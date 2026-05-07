<?php
/**
 * bot_setting.php - Bot 세팅 페이지
 * 컬럼: CENTER, UID, 이름, Futures금액, 클래스, Futures요율, 레버리지,
 *        물타기, 진입수량, 수동시그널, 수동청산, 진입수량세팅, 시그널상태
 * 진입수량 계산: perTrade = 총자산 × 요율/100 ÷ 100
 *              notional = perTrade × 레버리지
 *              qtyRaw   = notional ÷ 현재가격  (BTC: 소수점3자리 버림)
 */
require_once __DIR__ . '/../../includes/init.php';
require_login();
require_super_admin(); // Bot 세팅은 총어드민만

global $EXCHANGE_LIST;

// ============================================================
// AJAX 처리
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    check_csrf_on_post();
    $act    = $_POST['act'];
    $mb_no  = (int)($_POST['mb_no'] ?? 0);
    $uid    = trim($_POST['rx_uid'] ?? '');

    // ── 시그널 ON/OFF (Node.js 프로세스 유지, signal_control 이벤트만 전송)
    if ($act === 'signal_toggle') {
        $signal_state = $_POST['state'] === 'on' ? 'Y' : 'N';

        // DB 업데이트
        db_execute("UPDATE g5_member SET rx_send_ok=? WHERE mb_no=?", [$signal_state, $mb_no]);

        // Node.js 봇 서버에 signal_control 이벤트 전송
        $event = json_encode([
            'type'   => 'signal_control',
            'uid'    => $uid,
            'action' => $_POST['state'],  // 'on' 또는 'off'
            'ts'     => time(),
        ]);
        // Node.js 봇 서버에 signal_control 이벤트 직접 HTTP 전송
        global $NODE_SERVERS, $EXCHANGE_LIST;
        $rx_ce_val = (int)($_POST['rx_ce'] ?? 1);
        $ex_key_sig = $EXCHANGE_LIST[$rx_ce_val]['key'] ?? 'toobit';
        $node_url_sig = $NODE_SERVERS[$ex_key_sig] ?? '';
        if ($node_url_sig) {
            $ch = curl_init($node_url_sig . '/signal/control');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $event,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 5,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }

        write_audit_log('signal_toggle', $uid, [], ['state' => $_POST['state']]);
        json_response(true, '시그널 상태가 변경되었습니다.', ['state' => $_POST['state']]);
    }

    // ── 수동 시그널 (LONG/SHORT 진입)
    if ($act === 'manual_signal') {
        $side     = $_POST['side'] ?? '';  // 'LONG' 또는 'SHORT'
        $exchange = (int)($_POST['rx_ce'] ?? 1);

        if (!in_array($side, ['LONG', 'SHORT'])) json_response(false, '잘못된 방향입니다.');

        // Node.js API 호출 (거래소별 서버)
        global $NODE_SERVERS, $EXCHANGE_LIST;
        $ex_key = $EXCHANGE_LIST[$exchange]['key'] ?? 'toobit';
        $node_url = $NODE_SERVERS[$ex_key] ?? '';

        if ($node_url) {
            $payload = json_encode(['uid' => $uid, 'action' => 'manual_signal', 'side' => $side]);
            $ch = curl_init($node_url . '/signal');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 5,
            ]);
            $result = curl_exec($ch);
            curl_close($ch);
        }

        write_audit_log('manual_signal', $uid, [], ['side' => $side]);
        json_response(true, "수동 {$side} 시그널이 전송되었습니다.");
    }

    // ── 수동 청산
    if ($act === 'manual_close') {
        $exchange = (int)($_POST['rx_ce'] ?? 1);
        global $NODE_SERVERS, $EXCHANGE_LIST;
        $ex_key = $EXCHANGE_LIST[$exchange]['key'] ?? 'toobit';
        $node_url = $NODE_SERVERS[$ex_key] ?? '';

        if ($node_url) {
            $payload = json_encode(['uid' => $uid, 'action' => 'close_all']);
            $ch = curl_init($node_url . '/close');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 5,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }

        write_audit_log('manual_close', $uid, [], []);
        json_response(true, '수동 청산 신호가 전송되었습니다.');
    }

    // ── 진입수량 세팅 (BTC 현재가 기반 계산 후 저장)
    if ($act === 'set_qty') {
        // BTC 현재 가격 조회 (Node.js API 또는 외부 API)
        $btc_price = get_btc_price();

        $row = db_row("SELECT rx_acount, rx_rate FROM g5_member WHERE mb_no=?", [$mb_no]);
        if (!$row) json_response(false, '회원 정보를 찾을 수 없습니다.');

        $total_asset = (float)$row['rx_acount'];
        $rate        = (float)$row['rx_rate'];
        $leverage    = 50; // BTC 기본 레버리지

        // 진입수량 계산식
        $per_trade = $total_asset * ($rate / 100) / 100;
        $notional  = $per_trade * $leverage;
        $qty_raw   = $btc_price > 0 ? $notional / $btc_price : 0;
        $qty_btc   = floor($qty_raw * 1000) / 1000; // 소수점 3자리 버림

        // 저장 (mb_memo에 수량 기록)
        db_execute("UPDATE g5_member SET mb_memo=? WHERE mb_no=?",
            [json_encode(['btc_qty' => $qty_btc, 'calc_at' => date('Y-m-d H:i:s'), 'price' => $btc_price]), $mb_no]);

        write_audit_log('set_qty', $uid, [], ['qty' => $qty_btc, 'price' => $btc_price]);
        json_response(true, "진입수량 세팅 완료 (BTC: {$qty_btc})", [
            'qty_btc' => $qty_btc, 'btc_price' => $btc_price
        ]);
    }

    json_response(false, '알 수 없는 요청입니다.');
}

// ============================================================
// BTC 현재가 조회 함수
// ============================================================
function get_btc_price(): float {
    // Binance Public API (서명 불필요)
    $url = 'https://api.binance.com/api/v3/ticker/price?symbol=BTCUSDT';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $res = curl_exec($ch);
    curl_close($ch);
    if ($res) {
        $data = json_decode($res, true);
        return (float)($data['price'] ?? 0);
    }
    return 0;
}

// ============================================================
// 목록 조회
// ============================================================
$search_center = trim($_GET['q_center'] ?? '');
$search_uid    = trim($_GET['q_uid'] ?? '');

$where  = "1=1";
$params = [];
if ($search_center !== '') { $where .= " AND mb_center=?"; $params[] = $search_center; }
if ($search_uid !== '')    { $where .= " AND rx_uid LIKE ?"; $params[] = '%'.$search_uid.'%'; }

$members = db_rows(
    "SELECT mb_no, rx_uid, mb_name, rx_acount, rx_spot_amount, mb_class,
            rx_rate, mb_center, rx_ce, rx_send_ok, mb_memo
     FROM g5_member
     WHERE $where
     ORDER BY mb_datetime DESC",
    $params
);

$centers = db_rows("SELECT center_name FROM center_TB ORDER BY center_name");
$btc_price = get_btc_price();

$page_title = 'Bot 세팅';
require_once __DIR__ . '/../../includes/header.php';
?>
<meta name="csrf-token" content="<?= h(generate_csrf_token()) ?>">

<div class="page-header">
  <h2>⚙️ Bot 세팅</h2>
  <p>BTC 현재가: <strong style="color:var(--warning)">$<?= number_format($btc_price, 0) ?></strong></p>
</div>

<!-- 검색 -->
<div class="search-bar">
  <form method="GET" class="d-flex flex-wrap gap-2 align-items-end">
    <div>
      <label class="form-label-dark">CENTER</label>
      <select name="q_center" class="form-control-dark" style="width:140px">
        <option value="">전체</option>
        <?php foreach ($centers as $c): ?>
        <option value="<?= h($c['center_name']) ?>" <?= $search_center===$c['center_name']?'selected':'' ?>>
          <?= h($c['center_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label-dark">UID</label>
      <input type="text" name="q_uid" class="form-control-dark" style="width:140px"
             placeholder="UID" value="<?= h($search_uid) ?>">
    </div>
    <button type="submit" class="btn-primary-glow">🔍 검색</button>
    <a href="bot_setting.php" class="btn-glass">초기화</a>
  </form>
</div>

<div class="card-glass">
  <div class="table-responsive">
    <table class="table-dark-custom" id="bot-setting-table" data-dt>
      <thead>
        <tr>
          <th>CENTER</th>
          <th>UID</th>
          <th>이름</th>
          <th>Futures금액</th>
          <th>클래스</th>
          <th>요율(%)</th>
          <th>레버리지</th>
          <th>진입수량(BTC)</th>
          <th>수동시그널</th>
          <th>수동청산</th>
          <th>진입수량세팅</th>
          <th>시그널상태</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($members as $m): ?>
        <?php
          $qty_data = $m['mb_memo'] ? json_decode($m['mb_memo'], true) : [];
          $btc_qty  = $qty_data['btc_qty'] ?? '-';
          $is_on    = $m['rx_send_ok'] === 'Y';
          // 진입수량 미리 계산 (표시용)
          $rate     = (float)$m['rx_rate'];
          $asset    = (float)$m['rx_acount'];
          $calc_qty = '-';
          if ($btc_price > 0 && $rate > 0 && $asset > 0) {
              $per_trade = $asset * ($rate/100) / 100;
              $notional  = $per_trade * 50;
              $calc_qty  = floor(($notional / $btc_price) * 1000) / 1000;
          }
        ?>
        <tr>
          <td><span class="badge-muted"><?= h($m['mb_center']) ?></span></td>
          <td class="mono"><?= h($m['rx_uid']) ?></td>
          <td class="fw-bold"><?= h($m['mb_name']) ?></td>
          <td style="text-align:right"><?= number_format((int)$m['rx_acount']) ?></td>
          <td>CLASS <?= (int)$m['mb_class'] ?></td>
          <td style="text-align:right"><?= number_format($m['rx_rate'], 1) ?>%</td>
          <td style="text-align:center">50×</td>
          <td class="mono" style="text-align:right"><?= $calc_qty ?></td>

          <!-- 수동시그널 -->
          <td>
            <div class="d-flex gap-1">
              <button class="btn-success-glow" style="padding:4px 8px;font-size:0.72rem"
                      onclick="sendSignal(<?= $m['mb_no'] ?>, '<?= h($m['rx_uid']) ?>', <?= $m['rx_ce'] ?>, 'LONG')">
                LONG
              </button>
              <button class="btn-danger-glow" style="padding:4px 8px;font-size:0.72rem"
                      onclick="sendSignal(<?= $m['mb_no'] ?>, '<?= h($m['rx_uid']) ?>', <?= $m['rx_ce'] ?>, 'SHORT')">
                SHORT
              </button>
            </div>
          </td>

          <!-- 수동청산 -->
          <td>
            <button class="btn-glass" style="padding:4px 10px;font-size:0.72rem;color:var(--warning)"
                    onclick="sendClose(<?= $m['mb_no'] ?>, '<?= h($m['rx_uid']) ?>', <?= $m['rx_ce'] ?>)">
              청산
            </button>
          </td>

          <!-- 진입수량세팅 -->
          <td>
            <button class="btn-glass" style="padding:4px 10px;font-size:0.72rem"
                    onclick="setQty(<?= $m['mb_no'] ?>, '<?= h($m['rx_uid']) ?>')">
              ⚡세팅
            </button>
          </td>

          <!-- 시그널 ON/OFF 토글 -->
          <td>
            <label class="toggle-switch" title="<?= $is_on ? '클릭하여 OFF' : '클릭하여 ON' ?>">
              <input type="checkbox" <?= $is_on ? 'checked' : '' ?>
                     onchange="toggleSignal(<?= $m['mb_no'] ?>, '<?= h($m['rx_uid']) ?>', this)">
              <div class="toggle-track"></div>
              <span style="font-size:0.75rem;color:var(--text-muted)"><?= $is_on ? 'ON' : 'OFF' ?></span>
            </label>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
const BOT_SETTING_URL = 'bot_setting.php';

// 시그널 ON/OFF 토글
function toggleSignal(mb_no, uid, checkbox) {
  const state = checkbox.checked ? 'on' : 'off';
  if (!confirm(`시그널을 ${state.toUpperCase()}으로 변경하시겠습니까?\n(Node.js 프로세스는 유지됩니다)`)) {
    checkbox.checked = !checkbox.checked;
    return;
  }
  adminAjax(BOT_SETTING_URL, { act: 'signal_toggle', mb_no, rx_uid: uid, state },
    (res) => showAlert(res.message, 'success'),
    ()    => { checkbox.checked = !checkbox.checked; }
  );
}

// 수동 시그널
function sendSignal(mb_no, uid, rx_ce, side) {
  if (!confirm(`${uid} 회원에게 수동 ${side} 시그널을 보내시겠습니까?`)) return;
  adminAjax(BOT_SETTING_URL, { act: 'manual_signal', mb_no, rx_uid: uid, rx_ce, side },
    (res) => showAlert(res.message, 'success')
  );
}

// 수동 청산
function sendClose(mb_no, uid, rx_ce) {
  if (!confirm(`${uid} 회원의 포지션을 전체 청산하시겠습니까?`)) return;
  adminAjax(BOT_SETTING_URL, { act: 'manual_close', mb_no, rx_uid: uid, rx_ce },
    (res) => showAlert(res.message, 'success')
  );
}

// 진입수량 세팅
function setQty(mb_no, uid) {
  if (!confirm(`${uid} 회원의 BTC 진입수량을 현재가 기준으로 재계산하시겠습니까?`)) return;
  adminAjax(BOT_SETTING_URL, { act: 'set_qty', mb_no, rx_uid: uid },
    (res) => {
      showAlert(res.message, 'success');
      setTimeout(() => location.reload(), 1500);
    }
  );
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
