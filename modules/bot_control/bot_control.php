<?php
/**
 * bot_control.php - Bot 제어 페이지
 * 기능: 클래스별 양방모드 ON/OFF, 수동양방진입, 수동헷지청산
 *       포지션 보유 시 별도 ON/OFF 제어
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

    function call_all_nodes_ctrl(string $endpoint, array $payload): int {
        global $NODE_SERVERS;
        $success = 0;
        foreach ($NODE_SERVERS as $url) {
            $ch = curl_init($url . $endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 8,
            ]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200) $success++;
        }
        return $success;
    }

    // ── 클래스별 양방모드 ON/OFF
    if ($act === 'class_two_way') {
        $class  = (int)($_POST['class']  ?? 0);
        $state  = $_POST['state']        ?? 'off'; // 'on' 또는 'off'

        if (!in_array($class, [1, 2, 3, 9])) json_response(false, '유효하지 않은 클래스입니다.');

        $success = call_all_nodes_ctrl('/twoWay/class', [
            'class' => $class,
            'state' => $state,
        ]);

        // DB에도 클래스별 설정 반영 (g5_config rx_roi 필드 활용)
        write_audit_log('class_two_way', "CLASS{$class}", [], ['state' => $state]);
        json_response(true, "CLASS{$class} 양방모드 {$state}upper() 전송 완료. ({$success}서버)");
    }

    // ── 수동 양방 진입
    if ($act === 'manual_two_way_entry') {
        $target_type = $_POST['target_type'] ?? 'all'; // 'all' 또는 'specific'
        $target_uid  = trim($_POST['target_uid'] ?? '');
        $except_uid  = trim($_POST['except_uid'] ?? '');

        $payload = [
            'type'       => $target_type,
            'target_uid' => $target_uid,
            'except_uid' => $except_uid,
            'symbol'     => 'BTCUSDT',
        ];

        $success = call_all_nodes_ctrl('/twoWay/entry', $payload);
        write_audit_log('manual_two_way_entry', $target_type === 'specific' ? $target_uid : 'ALL', [], $payload);
        json_response(true, "수동 양방 진입 신호 전송 완료. ({$success}서버)");
    }

    // ── 수동 헷지 청산
    if ($act === 'manual_hedge_close') {
        $target_type = $_POST['target_type'] ?? 'all';
        $target_uid  = trim($_POST['target_uid'] ?? '');
        $except_uid  = trim($_POST['except_uid'] ?? '');

        $payload = [
            'type'       => $target_type,
            'target_uid' => $target_uid,
            'except_uid' => $except_uid,
            'symbol'     => 'BTCUSDT',
        ];

        $success = call_all_nodes_ctrl('/twoWay/close', $payload);
        write_audit_log('manual_hedge_close', $target_type === 'specific' ? $target_uid : 'ALL', [], $payload);
        json_response(true, "수동 헷지 청산 신호 전송 완료. ({$success}서버)");
    }

    // ── 포지션 보유 시 ON/OFF 제어
    if ($act === 'position_hold_control') {
        $state = $_POST['state'] ?? 'off';
        $success = call_all_nodes_ctrl('/control/positionHold', ['state' => $state]);
        write_audit_log('position_hold_control', 'ALL', [], ['state' => $state]);
        json_response(true, "포지션 보유 제어 {$state} 전송 완료.");
    }

    json_response(false, '알 수 없는 요청');
}

// 클래스별 현재 상태 조회 (DB 기반 - 기본값 OFF)
$class_states = [];
foreach ([1,2,3,9] as $cls) {
    $class_states[$cls] = false; // Node.js 서버에서 실시간 상태 관리
}

$page_title = 'Bot 제어';
require_once __DIR__ . '/../../includes/header.php';
?>
<meta name="csrf-token" content="<?= h(generate_csrf_token()) ?>">

<div class="page-header">
  <h2>🎮 Bot 제어</h2>
  <p>클래스별 양방모드 및 수동 포지션 제어</p>
</div>

<!-- ── 클래스별 양방모드 ON/OFF ────────────────────── -->
<div class="card-glass" style="margin-bottom:24px">
  <div class="card-header-bar">
    <h5>📊 클래스별 BTC 양방모드 제어</h5>
    <span class="badge-warning" style="font-size:0.72rem">CLASS 1, 2, 3, 9</span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px">
    <?php foreach ([1,2,3,9] as $cls): ?>
    <?php $is_on = $class_states[$cls] ?? false; ?>
    <div id="class-card-<?= $cls ?>" style="background:<?= $is_on ? 'rgba(0,255,157,0.08)' : 'rgba(255,59,92,0.06)' ?>;border:2px solid <?= $is_on ? 'rgba(0,255,157,0.4)' : 'rgba(255,59,92,0.3)' ?>;border-radius:12px;padding:20px;text-align:center;transition:all 0.3s">
      <div style="font-size:1.4rem;margin-bottom:8px">
        <?= ['1'=>'🥇','2'=>'🥈','3'=>'🥉','9'=>'⭐'][$cls] ?>
      </div>
      <div style="font-weight:800;font-size:1rem;margin-bottom:8px">CLASS <?= $cls ?></div>
      <!-- ON/OFF 상태 표시 (크고 직관적) -->
      <div id="class-status-<?= $cls ?>" style="padding:8px 20px;border-radius:8px;font-weight:900;font-size:1.1rem;margin-bottom:16px;
        background:<?= $is_on ? 'rgba(0,255,157,0.2)' : 'rgba(255,59,92,0.15)' ?>;
        color:<?= $is_on ? 'var(--success)' : 'var(--danger)' ?>">
        <?= $is_on ? '🟢 양방 ON' : '🔴 양방 OFF' ?>
      </div>
      <div class="d-flex gap-2 justify-content-center">
        <button class="btn-success-glow" style="padding:8px 20px;font-size:0.85rem;font-weight:700;<?= $is_on ? 'opacity:0.4;pointer-events:none' : '' ?>"
                id="btn-on-<?= $cls ?>" onclick="classControl(<?= $cls ?>, 'on')">▶ ON</button>
        <button class="btn-danger-glow"  style="padding:8px 20px;font-size:0.85rem;font-weight:700;<?= !$is_on ? 'opacity:0.4;pointer-events:none' : '' ?>"
                id="btn-off-<?= $cls ?>" onclick="classControl(<?= $cls ?>, 'off')">⏸ OFF</button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── 수동 양방 진입 ──────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">

  <div class="card-glass">
    <div class="card-header-bar">
      <h5>⚡ 수동 양방 진입</h5>
    </div>

    <div style="margin-bottom:16px">
      <label class="form-label-dark">대상 선택</label>
      <div class="d-flex gap-2">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:0.85rem">
          <input type="radio" name="entry-type" value="all" checked
                 onchange="toggleTargetField('entry', this.value)"> 전체 회원
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:0.85rem">
          <input type="radio" name="entry-type" value="specific"
                 onchange="toggleTargetField('entry', this.value)"> 특정 회원
        </label>
      </div>
    </div>

    <div id="entry-specific-field" style="display:none;margin-bottom:12px">
      <label class="form-label-dark">특정 회원 UID</label>
      <input type="text" id="entry-target-uid" class="form-control-dark" placeholder="UID 입력">
    </div>

    <div style="margin-bottom:16px">
      <label class="form-label-dark">제외할 회원 UID (선택사항)</label>
      <input type="text" id="entry-except-uid" class="form-control-dark" placeholder="제외할 UID (여러 명: 콤마 구분)">
    </div>

    <button class="btn-success-glow" style="width:100%;padding:10px" onclick="manualEntry()">
      ⚡ 수동 양방 진입 실행
    </button>
  </div>

  <!-- 수동 헷지 청산 -->
  <div class="card-glass">
    <div class="card-header-bar">
      <h5>🔒 수동 헷지 청산</h5>
    </div>

    <div style="margin-bottom:16px">
      <label class="form-label-dark">대상 선택</label>
      <div class="d-flex gap-2">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:0.85rem">
          <input type="radio" name="close-type" value="all" checked
                 onchange="toggleTargetField('close', this.value)"> 전체 회원
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:0.85rem">
          <input type="radio" name="close-type" value="specific"
                 onchange="toggleTargetField('close', this.value)"> 특정 회원
        </label>
      </div>
    </div>

    <div id="close-specific-field" style="display:none;margin-bottom:12px">
      <label class="form-label-dark">특정 회원 UID</label>
      <input type="text" id="close-target-uid" class="form-control-dark" placeholder="UID 입력">
    </div>

    <div style="margin-bottom:16px">
      <label class="form-label-dark">제외할 회원 UID (선택사항)</label>
      <input type="text" id="close-except-uid" class="form-control-dark" placeholder="제외할 UID (여러 명: 콤마 구분)">
    </div>

    <button class="btn-danger-glow" style="width:100%;padding:10px" onclick="manualHedgeClose()">
      🔒 수동 헷지 청산 실행
    </button>
  </div>
</div>

<!-- ── 포지션 보유 시 ON/OFF 제어 ──────────────────── -->
<div class="card-glass">
  <div class="card-header-bar">
    <h5>📍 포지션 보유 시 제어</h5>
    <span class="badge-muted">포지션 보유 중인 회원만 적용</span>
  </div>
  <p style="color:var(--text-muted);font-size:0.83rem;margin-bottom:16px">
    현재 포지션을 보유 중인 회원에 대해서만 별도로 ON/OFF 제어를 합니다.
  </p>
  <div class="d-flex gap-2">
    <button class="btn-success-glow" onclick="positionHoldControl('on')">
      ▶️ 포지션 보유자 ON
    </button>
    <button class="btn-danger-glow" onclick="positionHoldControl('off')">
      ⏸️ 포지션 보유자 OFF
    </button>
  </div>
</div>

<script>
const BOT_CTRL_URL = 'bot_control.php';

// 대상 필드 토글
function toggleTargetField(prefix, type) {
  document.getElementById(`${prefix}-specific-field`).style.display = type === 'specific' ? 'block' : 'none';
}

// 클래스별 양방 ON/OFF
function classControl(cls, state) {
  const label = state === 'on' ? 'ON' : 'OFF';
  if (!confirm(`CLASS${cls} 양방모드를 ${label}으로 변경하시겠습니까?`)) return;
  adminAjax(BOT_CTRL_URL, { act: 'class_two_way', class: cls, state },
    (res) => { showAlert(res.message, 'success'); setTimeout(() => location.reload(), 1500); }
  );
}

// 수동 양방 진입
function manualEntry() {
  const type = document.querySelector('input[name="entry-type"]:checked').value;
  const target_uid = document.getElementById('entry-target-uid').value.trim();
  const except_uid = document.getElementById('entry-except-uid').value.trim();

  if (type === 'specific' && !target_uid) {
    showAlert('특정 회원 UID를 입력하세요.', 'warning'); return;
  }
  if (!confirm('수동 양방 진입을 실행하시겠습니까?')) return;

  adminAjax(BOT_CTRL_URL, { act: 'manual_two_way_entry', target_type: type, target_uid, except_uid },
    (res) => showAlert(res.message, 'success')
  );
}

// 수동 헷지 청산
function manualHedgeClose() {
  const type = document.querySelector('input[name="close-type"]:checked').value;
  const target_uid = document.getElementById('close-target-uid').value.trim();
  const except_uid = document.getElementById('close-except-uid').value.trim();

  if (type === 'specific' && !target_uid) {
    showAlert('특정 회원 UID를 입력하세요.', 'warning'); return;
  }
  if (!confirm('수동 헷지 청산을 실행하시겠습니까?')) return;

  adminAjax(BOT_CTRL_URL, { act: 'manual_hedge_close', target_type: type, target_uid, except_uid },
    (res) => showAlert(res.message, 'success')
  );
}

// 포지션 보유 제어
function positionHoldControl(state) {
  const label = state === 'on' ? 'ON' : 'OFF';
  if (!confirm(`포지션 보유 회원을 ${label}으로 변경하시겠습니까?`)) return;
  adminAjax(BOT_CTRL_URL, { act: 'position_hold_control', state },
    (res) => showAlert(res.message, 'success')
  );
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
