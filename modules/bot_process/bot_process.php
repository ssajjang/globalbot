<?php
/**
 * bot_process.php - Bot 프로세스 관리 페이지
 * 컬럼: Center, 이름, UID, 대시보드(링크), Futures금액, 포지션, 물타기정지, 프로세스
 * 규칙: 시그널 OFF = 시그널만 OFF, Node.js 프로세스 Kill 금지
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
    $act   = $_POST['act'];
    $mb_no = (int)($_POST['mb_no'] ?? 0);
    $uid   = trim($_POST['rx_uid'] ?? '');
    $rx_ce = (int)($_POST['rx_ce'] ?? 1);

    // Node.js 서버 URL 결정
    $ex_key  = $EXCHANGE_LIST[$rx_ce]['key'] ?? 'toobit';
    $node_url = $NODE_SERVERS[$ex_key] ?? '';

    // Node.js API 호출 헬퍼
    function call_node(string $url, array $payload): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 8,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $res];
    }

    // ── 물타기 정지/재개 (개별)
    if ($act === 'dca_toggle') {
        $state = $_POST['state'] === 'stop' ? 'stop' : 'resume';
        $result = $node_url ? call_node("$node_url/dca/$state", ['uid' => $uid]) : [];
        write_audit_log("dca_{$state}", $uid, [], []);
        json_response(true, "물타기 {$state} 처리 완료.");
    }

    // ── 전체 회원 물타기 정지/시작
    if ($act === 'dca_all') {
        $state = $_POST['state'] === 'stop' ? 'stop' : 'start';
        // 모든 거래소 서버에 브로드캐스트
        foreach ($NODE_SERVERS as $key => $url) {
            call_node("$url/dca/all/$state", []);
        }
        write_audit_log("dca_all_{$state}", 'ALL', [], []);
        json_response(true, "전체 물타기 {$state} 처리 완료.");
    }

    // ── 프로세스 재시작 (Kill & Restart)
    if ($act === 'process_restart') {
        if (!$node_url) json_response(false, 'Node.js 서버 URL이 없습니다.');
        $result = call_node("$node_url/restart", ['uid' => $uid]);
        write_audit_log('process_restart', $uid, [], ['result' => $result['code']]);
        json_response($result['code'] === 200, '프로세스 재시작 신호를 전송했습니다.');
    }

    // ── 프로세스 Kill
    if ($act === 'process_kill') {
        if (!$node_url) json_response(false, 'Node.js 서버 URL이 없습니다.');
        $result = call_node("$node_url/kill", ['uid' => $uid]);
        write_audit_log('process_kill', $uid, [], ['result' => $result['code']]);
        json_response($result['code'] === 200, '프로세스 Kill 신호를 전송했습니다.');
    }

    json_response(false, '알 수 없는 요청');
}

// ============================================================
// 목록 조회 (포지션 포함)
// ============================================================
$members = db_rows(
    "SELECT mb_no, rx_uid, mb_name, rx_acount, mb_center, rx_ce, rx_send_ok
     FROM g5_member
     WHERE rx_send_ok IN ('Y','S','R')
     ORDER BY mb_datetime DESC"
);

// 포지션 데이터 (Node.js API에서 직접 조회 또는 DB 캐시)
$positions = [];
// 포지션 데이터는 대시보드 AJAX 호출 시 Node.js에서 실시간 조회

$page_title = 'Bot 프로세스';
require_once __DIR__ . '/../../includes/header.php';
?>
<meta name="csrf-token" content="<?= h(generate_csrf_token()) ?>">

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h2>🤖 Bot 프로세스</h2>
    <p>가동 중인 봇: <strong style="color:var(--success)"><?= count($members) ?></strong>개</p>
  </div>
  <!-- 전체 물타기 제어 버튼 -->
  <div class="d-flex gap-2">
    <button class="btn-danger-glow" onclick="dcaAll('stop')">
      🛑 전체 물타기 정지
    </button>
    <button class="btn-success-glow" onclick="dcaAll('start')">
      ▶️ 전체 물타기 시작
    </button>
  </div>
</div>

<div class="card-glass">
  <div class="table-responsive">
    <table class="table-dark-custom" id="process-table" data-dt>
      <thead>
        <tr>
          <th>Center</th>
          <th>이름</th>
          <th>UID</th>
          <th>대시보드</th>
          <th>Futures금액</th>
          <th>포지션</th>
          <th>시그널</th>
          <th>물타기정지</th>
          <th>프로세스</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($members as $m): ?>
        <?php
          $pos = $positions[$m['rx_uid']] ?? null;
          $pos_side = $pos['side'] ?? '';
          $pos_pnl  = $pos['unrealizedPnl'] ?? null;
          $dashboard_url = '#'; // 도메인은 rx_domain_config 테이블에서 별도 관리
          $is_on = $m['rx_send_ok'] === 'Y';
        ?>
        <tr>
          <td><span class="badge-muted"><?= h($m['mb_center']) ?></span></td>
          <td class="fw-bold"><?= h($m['mb_name']) ?></td>
          <td class="mono"><?= h($m['rx_uid']) ?></td>
          <td>
            <a href="<?= h($dashboard_url) ?>" target="_blank"
               class="btn-glass" style="padding:4px 10px;font-size:0.75rem;text-decoration:none">
              📊 보기
            </a>
          </td>
          <td style="text-align:right"><?= number_format((int)$m['rx_acount']) ?></td>
          <td>
            <?php if ($pos_side === 'BUY' || $pos_side === 'LONG'): ?>
              <span class="pos-long">LONG</span>
              <?php if ($pos_pnl !== null): ?>
              <small style="color:<?= $pos_pnl >= 0 ? 'var(--success)' : 'var(--danger)' ?>;font-size:0.72rem">
                <?= ($pos_pnl >= 0 ? '+' : '') . number_format($pos_pnl, 2) ?>$
              </small>
              <?php endif; ?>
            <?php elseif ($pos_side === 'SELL' || $pos_side === 'SHORT'): ?>
              <span class="pos-short">SHORT</span>
              <?php if ($pos_pnl !== null): ?>
              <small style="color:<?= $pos_pnl >= 0 ? 'var(--success)' : 'var(--danger)' ?>;font-size:0.72rem">
                <?= ($pos_pnl >= 0 ? '+' : '') . number_format($pos_pnl, 2) ?>$
              </small>
              <?php endif; ?>
            <?php else: ?>
              <span class="pos-none">없음</span>
            <?php endif; ?>
          </td>

          <!-- 시그널 상태 -->
          <td>
            <?= $is_on
              ? '<span class="status-on"><span class="dot-on"></span>ON</span>'
              : '<span class="status-off">OFF</span>' ?>
          </td>

          <!-- 물타기 정지/재개 -->
          <td>
            <div class="d-flex gap-1">
              <button class="btn-glass" style="padding:4px 8px;font-size:0.72rem;color:var(--danger)"
                      onclick="dcaToggle(<?= $m['mb_no'] ?>, '<?= h($m['rx_uid']) ?>', <?= $m['rx_ce'] ?>, 'stop')">
                🛑정지
              </button>
              <button class="btn-glass" style="padding:4px 8px;font-size:0.72rem;color:var(--success)"
                      onclick="dcaToggle(<?= $m['mb_no'] ?>, '<?= h($m['rx_uid']) ?>', <?= $m['rx_ce'] ?>, 'resume')">
                ▶재개
              </button>
            </div>
          </td>

          <!-- 프로세스 Kill/Restart -->
          <td>
            <div class="d-flex gap-1">
              <button class="btn-glass" style="padding:4px 8px;font-size:0.72rem;color:var(--warning)"
                      onclick="processAction(<?= $m['mb_no'] ?>, '<?= h($m['rx_uid']) ?>', <?= $m['rx_ce'] ?>, 'restart')">
                🔄재시작
              </button>
              <button class="btn-glass" style="padding:4px 8px;font-size:0.72rem;color:var(--danger)"
                      onclick="processAction(<?= $m['mb_no'] ?>, '<?= h($m['rx_uid']) ?>', <?= $m['rx_ce'] ?>, 'kill')">
                ☠Kill
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
const BOT_PROCESS_URL = 'bot_process.php';

// 물타기 개별 정지/재개
function dcaToggle(mb_no, uid, rx_ce, state) {
  const label = state === 'stop' ? '정지' : '재개';
  if (!confirm(`${uid} 회원의 물타기를 ${label}하시겠습니까?`)) return;
  adminAjax(BOT_PROCESS_URL, { act: 'dca_toggle', mb_no, rx_uid: uid, rx_ce, state },
    (res) => showAlert(res.message, 'success')
  );
}

// 전체 물타기 정지/시작
function dcaAll(state) {
  const label = state === 'stop' ? '정지' : '시작';
  if (!confirm(`전체 회원의 물타기를 ${label}하시겠습니까?`)) return;
  adminAjax(BOT_PROCESS_URL, { act: 'dca_all', state },
    (res) => showAlert(res.message, 'success')
  );
}

// 프로세스 재시작/Kill
function processAction(mb_no, uid, rx_ce, action) {
  const label = action === 'restart' ? '재시작' : 'Kill';
  if (!confirm(`${uid} 회원의 프로세스를 ${label}하시겠습니까?`)) return;
  const act = action === 'restart' ? 'process_restart' : 'process_kill';
  adminAjax(BOT_PROCESS_URL, { act, mb_no, rx_uid: uid, rx_ce },
    (res) => showAlert(res.message, 'success')
  );
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
