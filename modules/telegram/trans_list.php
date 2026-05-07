<?php
/**
 * trans_list.php - 전송(Telegram) 기록 페이지
 * 전송 기록 조회, 재전송, 상태 필터
 */
require_once __DIR__ . '/../../includes/init.php';
require_login();
require_super_admin();

// ============================================================
// AJAX 처리 - 재전송
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    check_csrf_on_post();
    if ($_POST['act'] === 'resend') {
        $log_no = (int)($_POST['log_no'] ?? 0);
        $row = db_row("SELECT * FROM telegram_log WHERE log_no=?", [$log_no]);
        if (!$row) json_response(false, '기록을 찾을 수 없습니다.');

        require_once __DIR__ . '/../../services/TelegramService.php';
        $tg = new TelegramService();
        $ok = $tg->sendDirect($row['chat_id'], $row['message']);

        if ($ok) {
            db_execute("UPDATE telegram_log SET status='sent', sent_at=NOW() WHERE log_no=?", [$log_no]);
            json_response(true, '재전송 완료.');
        } else {
            db_execute("UPDATE telegram_log SET status='failed', error_msg='수동 재전송 실패' WHERE log_no=?", [$log_no]);
            json_response(false, '재전송에 실패했습니다.');
        }
    }
    json_response(false, '알 수 없는 요청');
}

// ============================================================
// 목록 조회 (페이징)
// ============================================================
$q_status = $_GET['q_status'] ?? '';
$q_uid    = trim($_GET['q_uid'] ?? '');
$q_type   = trim($_GET['q_type'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 30;

$where  = "1=1";
$params = [];
if ($q_status) { $where .= " AND status=?"; $params[] = $q_status; }
if ($q_uid)    { $where .= " AND target_uid LIKE ?"; $params[] = '%'.$q_uid.'%'; }
if ($q_type)   { $where .= " AND event_type=?"; $params[] = $q_type; }

$total    = (int)db_scalar("SELECT COUNT(*) FROM telegram_log WHERE $where", $params);
$pages    = max(1, ceil($total / $per_page));
$offset   = ($page - 1) * $per_page;

$logs = db_rows(
    "SELECT * FROM telegram_log WHERE $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset",
    $params
);

// 통계
$stat_sent    = (int)db_scalar("SELECT COUNT(*) FROM telegram_log WHERE status='sent'");
$stat_failed  = (int)db_scalar("SELECT COUNT(*) FROM telegram_log WHERE status='failed'");
$stat_pending = (int)db_scalar("SELECT COUNT(*) FROM telegram_log WHERE status='pending'");

$page_title = '전송기록';
require_once __DIR__ . '/../../includes/header.php';
?>
<meta name="csrf-token" content="<?= h(generate_csrf_token()) ?>">

<div class="page-header"><h2>📨 전송기록</h2></div>

<!-- 통계 -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px">
  <div class="stat-card">
    <div class="stat-icon success">✅</div>
    <div><div class="stat-label">전송 성공</div><div class="stat-value" style="color:var(--success)"><?= number_format($stat_sent) ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon danger">❌</div>
    <div><div class="stat-label">전송 실패</div><div class="stat-value" style="color:var(--danger)"><?= number_format($stat_failed) ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon warning">⏳</div>
    <div><div class="stat-label">대기 중</div><div class="stat-value" style="color:var(--warning)"><?= number_format($stat_pending) ?></div></div>
  </div>
</div>

<!-- 검색 -->
<div class="search-bar">
  <form method="GET" class="d-flex flex-wrap gap-2 align-items-end">
    <div>
      <label class="form-label-dark">상태</label>
      <select name="q_status" class="form-control-dark" style="width:120px">
        <option value="">전체</option>
        <option value="sent"    <?= $q_status==='sent'    ?'selected':'' ?>>성공</option>
        <option value="failed"  <?= $q_status==='failed'  ?'selected':'' ?>>실패</option>
        <option value="pending" <?= $q_status==='pending' ?'selected':'' ?>>대기</option>
      </select>
    </div>
    <div>
      <label class="form-label-dark">회원 UID</label>
      <input type="text" name="q_uid" class="form-control-dark" style="width:150px"
             placeholder="UID 검색" value="<?= h($q_uid) ?>">
    </div>
    <div>
      <label class="form-label-dark">이벤트 타입</label>
      <select name="q_type" class="form-control-dark" style="width:130px">
        <option value="">전체</option>
        <?php foreach (['entry','close','dca','error','signal_control'] as $t): ?>
        <option value="<?= $t ?>" <?= $q_type===$t?'selected':'' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn-primary-glow">🔍 검색</button>
    <a href="trans_list.php" class="btn-glass">초기화</a>
  </form>
</div>

<div class="card-glass">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;color:var(--text-muted);font-size:0.83rem">
    <span>전체 <?= number_format($total) ?>건 / 페이지 <?= $page ?>/<?= $pages ?></span>
  </div>
  <div class="table-responsive">
    <table class="table-dark-custom">
      <thead>
        <tr>
          <th>#</th>
          <th>상태</th>
          <th>이벤트</th>
          <th>대상 UID</th>
          <th>Chat ID</th>
          <th>메시지</th>
          <th>전송 시각</th>
          <th>생성 시각</th>
          <th>재전송</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($logs)): ?>
        <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text-muted)">기록이 없습니다.</td></tr>
        <?php else: ?>
        <?php foreach ($logs as $log): ?>
        <tr>
          <td style="color:var(--text-muted);font-size:0.75rem"><?= $log['log_no'] ?></td>
          <td>
            <?php if ($log['status'] === 'sent'): ?>
            <span class="badge-success">✅ 성공</span>
            <?php elseif ($log['status'] === 'failed'): ?>
            <span class="badge-danger">❌ 실패</span>
            <?php else: ?>
            <span class="badge-warning">⏳ 대기</span>
            <?php endif; ?>
          </td>
          <td><span class="badge-muted"><?= h($log['event_type'] ?: '-') ?></span></td>
          <td class="mono"><?= h($log['target_uid'] ?: '-') ?></td>
          <td class="mono" style="font-size:0.75rem;color:var(--text-muted)"><?= h(substr($log['chat_id'], 0, 12)) ?>...</td>
          <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.78rem"
              title="<?= h($log['message']) ?>">
            <?= h(substr(strip_tags($log['message']), 0, 60)) ?>
          </td>
          <td style="font-size:0.75rem;color:var(--text-muted)"><?= h($log['sent_at'] ?: '-') ?></td>
          <td style="font-size:0.75rem;color:var(--text-muted)"><?= h(substr($log['created_at'], 5)) ?></td>
          <td>
            <?php if ($log['status'] !== 'sent'): ?>
            <button class="btn-glass" style="padding:3px 8px;font-size:0.72rem"
                    onclick="resend(<?= $log['log_no'] ?>)">재전송</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- 페이징 -->
  <?php if ($pages > 1): ?>
  <div style="display:flex;justify-content:center;gap:6px;margin-top:20px;flex-wrap:wrap">
    <?php if ($page > 1): ?>
    <a href="?page=<?= $page-1 ?>&q_status=<?= h($q_status) ?>&q_uid=<?= h($q_uid) ?>&q_type=<?= h($q_type) ?>"
       class="btn-glass" style="padding:6px 12px;text-decoration:none">이전</a>
    <?php endif; ?>
    <?php for ($i = max(1,$page-2); $i <= min($pages,$page+2); $i++): ?>
    <a href="?page=<?= $i ?>&q_status=<?= h($q_status) ?>&q_uid=<?= h($q_uid) ?>&q_type=<?= h($q_type) ?>"
       class="btn-glass" style="padding:6px 12px;text-decoration:none;<?= $i==$page ? 'background:rgba(0,212,255,0.15);color:var(--primary)' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $pages): ?>
    <a href="?page=<?= $page+1 ?>&q_status=<?= h($q_status) ?>&q_uid=<?= h($q_uid) ?>&q_type=<?= h($q_type) ?>"
       class="btn-glass" style="padding:6px 12px;text-decoration:none">다음</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<script>
function resend(log_no) {
  if (!confirm('이 메시지를 재전송하시겠습니까?')) return;
  adminAjax('trans_list.php', { act: 'resend', log_no },
    (res) => { showAlert(res.message, 'success'); setTimeout(() => location.reload(), 1200); }
  );
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
