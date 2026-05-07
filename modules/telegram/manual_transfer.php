<?php
/**
 * manual_transfer.php - 센터 텔레그램 관리
 * 센터별 텔레그램 봇 연동 설정 + 봇 로그 전송 관리
 */
require_once __DIR__ . '/../../includes/init.php';
require_login();
require_super_admin();

// ============================================================
// AJAX 처리
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    check_csrf_on_post();

    // ── 센터 텔레그램 설정 저장 (chat_id 등록/수정)
    if ($_POST['act'] === 'save_telegram') {
        $center_name = trim($_POST['center_name'] ?? '');
        $chat_id     = trim($_POST['chat_id'] ?? '');

        if (empty($center_name)) json_response(false, 'CENTER를 선택하세요.');
        if (empty($chat_id))     json_response(false, '텔레그램 Chat ID를 입력하세요.');

        db_execute(
            "UPDATE center_TB SET T_chat_id=? WHERE center_name=?",
            [$chat_id, $center_name]
        );

        write_audit_log('center_telegram_update', $center_name, [], ['chat_id' => $chat_id]);
        json_response(true, "[{$center_name}] 텔레그램 Chat ID가 저장되었습니다.");
    }

    // ── 센터별 수동 메시지 전송
    if ($_POST['act'] === 'manual_send') {
        $center_name = trim($_POST['center_name'] ?? '');
        $message     = trim($_POST['message'] ?? '');

        if (empty($center_name)) json_response(false, 'CENTER를 선택하세요.');
        if (empty($message))     json_response(false, '메시지를 입력하세요.');

        require_once __DIR__ . '/../../services/TelegramService.php';
        $tg = new TelegramService();

        // CENTER의 chat_id 조회
        $row = db_row("SELECT T_chat_id FROM center_TB WHERE center_name=? LIMIT 1", [$center_name]);
        if (!$row || !$row['T_chat_id']) json_response(false, '해당 CENTER의 텔레그램 Chat ID가 등록되지 않았습니다.');

        $formatted = "📢 <b>[관리자 공지]</b>\n\n{$message}\n\n⏰ " . date('Y-m-d H:i:s');
        $ok = $tg->queueMessage($row['T_chat_id'], $formatted, 'manual', '');

        write_audit_log('center_telegram_send', $center_name, [], ['message' => substr($message, 0, 50)]);
        json_response($ok, $ok ? '메시지가 전송 큐에 등록되었습니다.' : '전송 큐 등록 실패');
    }

    json_response(false, '알 수 없는 요청');
}

// CENTER 목록 + chat_id 정보
$centers = db_rows("SELECT center_name, T_chat_id FROM center_TB WHERE is_active=1 ORDER BY center_name");

$page_title = '센터 텔레그램';
require_once __DIR__ . '/../../includes/header.php';
?>
<meta name="csrf-token" content="<?= h(generate_csrf_token()) ?>">

<div class="page-header">
  <h2>📨 센터 텔레그램</h2>
  <p>센터별 텔레그램 봇방 연동 및 로그 전송 관리</p>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">

  <!-- ── 센터별 텔레그램 현황 ──────────────────────── -->
  <div class="card-glass">
    <div class="card-header-bar"><h5>🏢 센터별 텔레그램 현황</h5></div>

    <?php if (empty($centers)): ?>
    <p style="color:var(--text-muted);padding:20px;text-align:center">등록된 CENTER가 없습니다.</p>
    <?php else: ?>
    <?php foreach ($centers as $c): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--border)">
      <div>
        <span class="fw-bold"><?= h($c['center_name']) ?></span>
        <?php if ($c['T_chat_id']): ?>
        <span class="badge-success" style="margin-left:8px;font-size:0.7rem">✅ 연동됨</span>
        <br><code style="font-size:0.72rem;color:var(--text-muted)"><?= h($c['T_chat_id']) ?></code>
        <?php else: ?>
        <span class="badge-danger" style="margin-left:8px;font-size:0.7rem">❌ 미연동</span>
        <?php endif; ?>
      </div>
      <button class="btn-glass" style="padding:6px 12px;font-size:0.78rem"
              onclick="openTelegramSetup('<?= h($c['center_name']) ?>', '<?= h($c['T_chat_id'] ?? '') ?>')">
        ⚙️ 설정
      </button>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- ── 메시지 전송 + 가이드 ─────────────────────── -->
  <div>
    <!-- 수동 전송 폼 -->
    <div class="card-glass" style="margin-bottom:20px">
      <div class="card-header-bar"><h5>📤 센터별 메시지 전송</h5></div>

      <div style="margin-bottom:16px">
        <label class="form-label-dark">센터 선택</label>
        <select id="send-center" class="form-control-dark">
          <option value="">-- CENTER 선택 --</option>
          <?php foreach ($centers as $c): ?>
          <?php if ($c['T_chat_id']): ?>
          <option value="<?= h($c['center_name']) ?>"><?= h($c['center_name']) ?></option>
          <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="margin-bottom:16px">
        <label class="form-label-dark">메시지 내용</label>
        <textarea id="send-message" class="form-control-dark" rows="5"
                  placeholder="전송할 메시지를 입력하세요. HTML 태그 사용 가능합니다."></textarea>
      </div>

      <button class="btn-primary-glow" style="width:100%;padding:12px" onclick="sendCenterMessage()">
        📤 전송
      </button>
    </div>

    <!-- 봇 로그 형식 가이드 -->
    <div class="card-glass">
      <div class="card-header-bar"><h5>📖 봇 로그 전송 형식</h5></div>
      <div style="font-size:0.82rem;line-height:2;color:var(--text-muted)">
        <p style="margin-bottom:8px"><strong style="color:var(--primary)">봇이 자동 전송하는 로그 메시지 형식:</strong></p>
        <div style="background:rgba(0,0,0,0.3);padding:14px;border-radius:8px;font-family:monospace;font-size:0.78rem;line-height:1.9">
          <div style="color:var(--success)">🚀 [UID],[이름] LONG 포지션 진입을 합니다.</div>
          <div style="color:var(--danger)">🔻 [UID],[이름] SHORT 포지션 진입을 합니다.</div>
          <div style="color:#FFB800">🔄 [UID],[이름] LONG 리버스 진입을 합니다.</div>
          <div style="color:#FFB800">🔄 [UID],[이름] SHORT 리버스 진입을 합니다.</div>
          <div style="color:var(--primary)">💧 [UID],[이름] LONG 물타기 진행 (1/130)</div>
          <div style="color:var(--primary)">💧 [UID],[이름] SHORT 물타기 진행 (1/130)</div>
          <div style="color:var(--warning)">🔁 [UID],[이름] 포지션 보유중 재시작</div>
        </div>

        <p style="margin-top:16px;margin-bottom:8px"><strong style="color:var(--success)">텔레그램 봇방 개설 가이드:</strong></p>
        <ol style="padding-left:18px;line-height:2.2">
          <li>텔레그램에서 <code>@BotFather</code> 검색 → <code>/newbot</code> 명령 입력</li>
          <li>봇 이름과 username 설정 → <strong>API Token</strong> 발급</li>
          <li>새 그룹 또는 채널 생성 → 봇을 멤버로 초대</li>
          <li>그룹에 아무 메시지 전송 후 아래 URL로 <strong>Chat ID</strong> 확인:
            <br><code style="font-size:0.72rem">https://api.telegram.org/bot{API_TOKEN}/getUpdates</code>
          </li>
          <li>위에서 확인한 Chat ID를 센터 설정에 입력</li>
        </ol>
      </div>
    </div>
  </div>

</div>

<!-- ── 텔레그램 설정 모달 ────────────────────────────── -->
<div class="modal fade modal-dark" id="telegramModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="tg-modal-title">⚙️ 텔레그램 설정</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="tg_center_name">
        <div style="margin-bottom:16px">
          <label class="form-label-dark">텔레그램 Chat ID</label>
          <input type="text" id="tg_chat_id" class="form-control-dark mono"
                 placeholder="예: -1001234567890">
          <small style="color:var(--text-muted);font-size:0.75rem">
            그룹 Chat ID는 보통 <code>-100</code>으로 시작합니다
          </small>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-glass" data-bs-dismiss="modal">취소</button>
        <button class="btn-primary-glow" onclick="saveTelegramSetting()">💾 저장</button>
      </div>
    </div>
  </div>
</div>

<script>
// 텔레그램 설정 모달 열기
function openTelegramSetup(centerName, chatId) {
  document.getElementById('tg_center_name').value = centerName;
  document.getElementById('tg_chat_id').value = chatId;
  document.getElementById('tg-modal-title').textContent = `⚙️ ${centerName} 텔레그램 설정`;
  new bootstrap.Modal(document.getElementById('telegramModal')).show();
}

// 텔레그램 Chat ID 저장
function saveTelegramSetting() {
  const center_name = document.getElementById('tg_center_name').value;
  const chat_id = document.getElementById('tg_chat_id').value.trim();
  if (!chat_id) { showAlert('Chat ID를 입력하세요.', 'warning'); return; }

  adminAjax('manual_transfer.php', {
    act: 'save_telegram', center_name, chat_id
  }, (res) => {
    showAlert(res.message, 'success');
    bootstrap.Modal.getInstance(document.getElementById('telegramModal')).hide();
    setTimeout(() => location.reload(), 1200);
  });
}

// 센터별 메시지 전송
function sendCenterMessage() {
  const center_name = document.getElementById('send-center').value;
  const message = document.getElementById('send-message').value.trim();

  if (!center_name) { showAlert('센터를 선택하세요.', 'warning'); return; }
  if (!message) { showAlert('메시지를 입력하세요.', 'warning'); return; }
  if (!confirm(`[${center_name}] 센터에 메시지를 전송하시겠습니까?`)) return;

  adminAjax('manual_transfer.php', {
    act: 'manual_send', center_name, message
  }, (res) => {
    showAlert(res.message, 'success');
    document.getElementById('send-message').value = '';
  });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
