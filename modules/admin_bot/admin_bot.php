<?php
/**
 * admin_bot.php - 관리 Bot 페이지
 * 기능: 전체 BTC 진입수량 재세팅, 프로세스 싱크, 전체청산, 부분청산, 수동물타기
 */
require_once __DIR__ . '/../../includes/init.php';
require_login();
require_super_admin();

global $NODE_SERVERS, $EXCHANGE_LIST;

// ============================================================
// AJAX 처리
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    check_csrf_on_post();
    $act = $_POST['act'];

    // Node.js 전체 서버 호출 헬퍼
    function call_all_nodes(string $endpoint, array $payload): array {
        global $NODE_SERVERS;
        $results = [];
        foreach ($NODE_SERVERS as $key => $url) {
            $ch = curl_init($url . $endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 10,
            ]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $results[$key] = ['code' => $code, 'body' => $res];
        }
        return $results;
    }

    // 단일 노드 호출
    function call_node_by_exchange(int $rx_ce, string $endpoint, array $payload): array {
        global $NODE_SERVERS, $EXCHANGE_LIST;
        $ex_key  = $EXCHANGE_LIST[$rx_ce]['key'] ?? 'toobit';
        $node_url = $NODE_SERVERS[$ex_key] ?? '';
        if (!$node_url) return ['code' => 0, 'body' => ''];
        $ch = curl_init($node_url . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $res];
    }

    // ── 전체 BTC 진입수량 재세팅
    if ($act === 'reset_all_qty') {
        // BTC 현재가 조회
        $ch = curl_init('https://api.binance.com/api/v3/ticker/price?symbol=BTCUSDT');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        $res_price = curl_exec($ch); curl_close($ch);
        $btc_price = (float)(json_decode($res_price, true)['price'] ?? 0);

        if ($btc_price <= 0) json_response(false, 'BTC 현재가를 가져오지 못했습니다.');

        // 전체 활성 회원 순회하며 수량 계산
        $members = db_rows(
            "SELECT mb_no, rx_uid, rx_acount, rx_rate FROM g5_member
             WHERE rx_send_ok IN ('Y','R') AND rx_acount > 0"
        );

        $updated = 0;
        foreach ($members as $m) {
            $per_trade = (float)$m['rx_acount'] * ((float)$m['rx_rate'] / 100) / 100;
            $notional  = $per_trade * 50; // BTC 레버리지 50
            $qty_btc   = floor(($notional / $btc_price) * 1000) / 1000;

            db_execute(
                "UPDATE g5_member SET mb_memo=? WHERE mb_no=?",
                [json_encode(['btc_qty' => $qty_btc, 'calc_at' => date('Y-m-d H:i:s'), 'price' => $btc_price]), $m['mb_no']]
            );
            $updated++;
        }

        write_audit_log('reset_all_qty', 'ALL', [], ['count' => $updated, 'price' => $btc_price]);
        json_response(true, "전체 {$updated}명의 BTC 진입수량 재세팅 완료. (BTC: \${$btc_price})");
    }

    // ── 프로세스 싱크 (실제 포지션과 DB 동기화)
    if ($act === 'process_sync') {
        $results = call_all_nodes('/sync/position', []);
        $success_count = count(array_filter($results, fn($r) => $r['code'] === 200));
        write_audit_log('process_sync', 'ALL', [], ['results' => $results]);
        json_response(true, "포지션 싱크 완료. ({$success_count}/" . count($results) . "개 서버 성공)");
    }

    // ── BTC 전체 청산 (LONG 또는 SHORT)
    if ($act === 'close_all') {
        $side = $_POST['side'] ?? ''; // 'LONG' 또는 'SHORT' 또는 'ALL'
        $results = call_all_nodes('/close/all', ['side' => $side, 'symbol' => 'BTCUSDT']);
        write_audit_log('close_all', 'ALL', [], ['side' => $side]);
        json_response(true, "BTC {$side} 전체 청산 신호 전송 완료.");
    }

    // ── 부분 청산 (10%, 20%, 30%, 50%)
    if ($act === 'partial_close') {
        $percent  = (int)($_POST['percent'] ?? 0);
        $side     = $_POST['side'] ?? 'ALL';

        if (!in_array($percent, [10, 20, 30, 50])) {
            json_response(false, '허용된 청산 비율이 아닙니다. (10/20/30/50%)');
        }

        $results = call_all_nodes('/close/partial', [
            'percent' => $percent,
            'side'    => $side,
            'symbol'  => 'BTCUSDT',
        ]);
        write_audit_log('partial_close', 'ALL', [], ['percent' => $percent, 'side' => $side]);
        json_response(true, "BTC {$percent}% 부분청산 신호 전송 완료.");
    }

    // ── 수동 물타기 (특정 회원)
    if ($act === 'manual_dca') {
        $search_val  = trim($_POST['search_val']  ?? '');
        $side        = $_POST['dca_side']         ?? 'LONG';
        $dca_count   = (int)($_POST['dca_count']  ?? 1);

        if (empty($search_val)) json_response(false, 'UID 또는 이름을 입력하세요.');
        if ($dca_count < 1 || $dca_count > 100) json_response(false, '물타기 횟수는 1~100 사이여야 합니다.');

        // UID 또는 이름으로 회원 조회
        $member = db_row(
            "SELECT mb_no, rx_uid, rx_ce FROM g5_member
             WHERE rx_uid=? OR mb_name=? LIMIT 1",
            [$search_val, $search_val]
        );
        if (!$member) json_response(false, "회원을 찾을 수 없습니다: {$search_val}");

        $result = call_node_by_exchange($member['rx_ce'], '/dca/manual', [
            'uid'       => $member['rx_uid'],
            'side'      => $side,
            'dca_count' => $dca_count,
        ]);

        write_audit_log('manual_dca', $member['rx_uid'], [], [
            'side' => $side, 'count' => $dca_count
        ]);
        json_response(true, "{$member['rx_uid']} 회원에게 수동 물타기 {$dca_count}회 ({$side}) 신호 전송 완료.");
    }

    json_response(false, '알 수 없는 요청');
}

// ============================================================
// 통계 (현재 포지션 보유 현황)
// ============================================================
$active_count = (int)db_scalar("SELECT COUNT(*) FROM g5_member WHERE rx_send_ok='Y'");
$total_futures = (float)db_scalar("SELECT COALESCE(SUM(rx_acount),0) FROM g5_member WHERE rx_send_ok='Y'");

$page_title = '관리 Bot';
require_once __DIR__ . '/../../includes/header.php';
?>
<meta name="csrf-token" content="<?= h(generate_csrf_token()) ?>">

<div class="page-header">
  <h2>🛠️ 관리 Bot</h2>
  <p>현재 가동 봇: <strong style="color:var(--success)"><?= $active_count ?>개</strong> | 총 투자금: <strong style="color:var(--warning)"><?= number_format($total_futures, 0) ?> USDT</strong></p>
</div>

<!-- ── 상단: 전체 관리 버튼 ────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin-bottom:24px">

  <!-- 투자수량 재세팅 -->
  <div class="card-glass">
    <div class="card-header-bar">
      <h5>⚡ 투자수량 재세팅</h5>
    </div>
    <p style="color:var(--text-muted);font-size:0.83rem;margin-bottom:16px">
      전체 활성 회원의 BTC 진입수량을 현재가 기준으로 재계산합니다.
    </p>
    <button class="btn-warning-full" onclick="resetAllQty()">
      🔄 전체 BTC 진입수량 재세팅
    </button>
  </div>

  <!-- 프로세스 싱크 -->
  <div class="card-glass">
    <div class="card-header-bar">
      <h5>🔃 프로세스 싱크</h5>
    </div>
    <p style="color:var(--text-muted);font-size:0.83rem;margin-bottom:16px">
      거래소 실제 포지션과 DB 데이터를 동기화합니다.
    </p>
    <button class="btn-glass" style="width:100%;padding:10px;font-weight:700" onclick="processSync()">
      🔃 포지션 싱크 실행
    </button>
  </div>
</div>

<!-- ── 청산관리 ─────────────────────────────────────── -->
<div class="card-glass" style="margin-bottom:24px">
  <div class="card-header-bar">
    <h5>💥 청산관리</h5>
    <span class="badge-danger">위험 구역</span>
  </div>

  <!-- 전체 청산 -->
  <div style="margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid var(--border)">
    <p style="color:var(--text-muted);font-size:0.83rem;margin-bottom:12px">
      ⚠️ BTC 전체 청산 (선택한 방향의 전체 회원 포지션 즉시 청산)
    </p>
    <div class="d-flex gap-2 flex-wrap">
      <button class="btn-danger-glow" onclick="closeAll('LONG')">
        📉 BTC LONG 전체 청산
      </button>
      <button class="btn-danger-glow" onclick="closeAll('SHORT')">
        📈 BTC SHORT 전체 청산
      </button>
      <button class="btn-danger-glow" style="background:linear-gradient(135deg,#ff6b35,#cc3300)"
              onclick="closeAll('ALL')">
        ☠️ BTC 전체 강제청산 (LONG+SHORT)
      </button>
    </div>
  </div>

  <!-- 부분 청산 -->
  <div>
    <p style="color:var(--text-muted);font-size:0.83rem;margin-bottom:12px">
      📊 부분 청산 - 전체 회원 포지션의 일부를 청산합니다
    </p>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <select id="partial-side" class="form-control-dark" style="width:120px">
        <option value="ALL">전체</option>
        <option value="LONG">LONG만</option>
        <option value="SHORT">SHORT만</option>
      </select>
      <button class="btn-glass" style="color:var(--warning)" onclick="partialClose(10)">10% 청산</button>
      <button class="btn-glass" style="color:var(--warning)" onclick="partialClose(20)">20% 청산</button>
      <button class="btn-glass" style="color:var(--warning)" onclick="partialClose(30)">30% 청산</button>
      <button class="btn-glass" style="color:var(--danger)"  onclick="partialClose(50)">50% 청산</button>
    </div>
  </div>
</div>

<!-- ── 수동 물타기 ─────────────────────────────────── -->
<div class="card-glass">
  <div class="card-header-bar">
    <h5>💧 수동 물타기</h5>
  </div>
  <p style="color:var(--text-muted);font-size:0.83rem;margin-bottom:16px">
    특정 회원에게 물타기 신호를 수동으로 전송합니다. UID 또는 이름으로 검색합니다.
  </p>

  <div class="d-flex flex-wrap gap-3 align-items-end">
    <div>
      <label class="form-label-dark">UID 또는 이름</label>
      <input type="text" id="dca-search" class="form-control-dark" style="width:200px"
             placeholder="UID 또는 이름 입력">
    </div>
    <div>
      <label class="form-label-dark">방향</label>
      <select id="dca-side" class="form-control-dark" style="width:120px">
        <option value="LONG">LONG</option>
        <option value="SHORT">SHORT</option>
      </select>
    </div>
    <div>
      <label class="form-label-dark">물타기 횟수</label>
      <input type="number" id="dca-count" class="form-control-dark" style="width:100px"
             value="1" min="1" max="100">
    </div>
    <button class="btn-primary-glow" onclick="manualDca()">💧 수동 물타기 실행</button>
  </div>
</div>

<style>
.btn-warning-full {
  width: 100%;
  background: linear-gradient(135deg, var(--warning), #cc9200);
  color: #000;
  border: none;
  border-radius: 8px;
  padding: 10px;
  font-weight: 800;
  font-size: 0.9rem;
  cursor: pointer;
  transition: all 0.25s;
}
.btn-warning-full:hover { box-shadow: 0 0 20px rgba(255,184,0,0.4); transform: translateY(-1px); }
</style>

<script>
const ADMIN_BOT_URL = 'admin_bot.php';

// 전체 BTC 진입수량 재세팅
function resetAllQty() {
  if (!confirm('전체 활성 회원의 BTC 진입수량을 현재가 기준으로 재계산하시겠습니까?')) return;
  showAlert('재세팅 중... 잠시 기다려주세요.', 'info', 0);
  adminAjax(ADMIN_BOT_URL, { act: 'reset_all_qty' }, (res) => showAlert(res.message, 'success'));
}

// 포지션 싱크
function processSync() {
  if (!confirm('포지션 싱크를 실행하시겠습니까?')) return;
  adminAjax(ADMIN_BOT_URL, { act: 'process_sync' }, (res) => showAlert(res.message, 'success'));
}

// 전체 청산
function closeAll(side) {
  const labels = { LONG: 'LONG 전체 청산', SHORT: 'SHORT 전체 청산', ALL: '전체 강제 청산' };
  if (!confirm(`⚠️ 정말로 ${labels[side]}을 실행하시겠습니까?\n\n이 작업은 되돌릴 수 없습니다!`)) return;
  adminAjax(ADMIN_BOT_URL, { act: 'close_all', side }, (res) => showAlert(res.message, 'success'));
}

// 부분 청산
function partialClose(percent) {
  const side = document.getElementById('partial-side').value;
  if (!confirm(`${side} 방향 포지션의 ${percent}%를 청산하시겠습니까?`)) return;
  adminAjax(ADMIN_BOT_URL, { act: 'partial_close', percent, side }, (res) => showAlert(res.message, 'success'));
}

// 수동 물타기
function manualDca() {
  const search_val = document.getElementById('dca-search').value.trim();
  const dca_side   = document.getElementById('dca-side').value;
  const dca_count  = parseInt(document.getElementById('dca-count').value);

  if (!search_val) { showAlert('UID 또는 이름을 입력하세요.', 'warning'); return; }
  if (!dca_count || dca_count < 1) { showAlert('물타기 횟수를 1 이상으로 입력하세요.', 'warning'); return; }

  if (!confirm(`${search_val} 회원에게 ${dca_side} 물타기 ${dca_count}회를 실행하시겠습니까?`)) return;
  adminAjax(ADMIN_BOT_URL, { act: 'manual_dca', search_val, dca_side, dca_count },
    (res) => showAlert(res.message, 'success')
  );
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
