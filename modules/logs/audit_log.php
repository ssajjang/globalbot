<?php
/**
 * audit_log.php - 관리자 감사 로그 조회 + 로그 시스템
 * 모든 관리자 작업 이력 조회, 필터, 검색
 */
require_once __DIR__ . '/../../includes/init.php';
require_login();
require_super_admin();

// 검색 조건
$q_admin  = trim($_GET['q_admin']  ?? '');
$q_action = trim($_GET['q_action'] ?? '');
$q_target = trim($_GET['q_target'] ?? '');
$q_date   = trim($_GET['q_date']   ?? date('Y-m-d')); // 기본: 오늘
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 30;

$where  = "1=1";
$params = [];
if ($q_admin)  { $where .= " AND admin_id LIKE ?"; $params[] = '%'.$q_admin.'%'; }
if ($q_action) { $where .= " AND action=?"; $params[] = $q_action; }
if ($q_target) { $where .= " AND target_id LIKE ?"; $params[] = '%'.$q_target.'%'; }
if ($q_date)   { $where .= " AND DATE(created_at)=?"; $params[] = $q_date; }

$total  = (int)db_scalar("SELECT COUNT(*) FROM admin_audit_log WHERE $where", $params);
$pages  = max(1, ceil($total / $per_page));
$offset = ($page - 1) * $per_page;

$logs = db_rows(
    "SELECT * FROM admin_audit_log WHERE $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset",
    $params
);

// 오늘 작업 수
$today_count = (int)db_scalar("SELECT COUNT(*) FROM admin_audit_log WHERE DATE(created_at)=?", [date('Y-m-d')]);

// 액션 목록 (필터용)
$action_list = db_rows("SELECT DISTINCT action FROM admin_audit_log ORDER BY action");

// 액션별 아이콘
$action_icons = [
    'login_success'        => '🔐',
    'logout'               => '🚪',
    'login_locked'         => '🔒',
    'member_update'        => '✏️',
    'member_rx_acount_update' => '💰',
    'member_rx_spot_amount_update' => '💵',
    'member_memo_update'   => '📝',
    'signal_toggle'        => '🎛️',
    'manual_signal'        => '⚡',
    'manual_close'         => '💥',
    'set_qty'              => '⚙️',
    'process_restart'      => '🔄',
    'process_kill'         => '☠️',
    'dca_toggle'           => '💧',
    'dca_all_stop'         => '🛑',
    'dca_all_start'        => '▶️',
    'reset_all_qty'        => '🔄',
    'process_sync'         => '🔃',
    'close_all'            => '💥',
    'partial_close'        => '📊',
    'manual_dca'           => '💧',
    'class_two_way'        => '📊',
    'manual_two_way_entry' => '⚡',
    'manual_hedge_close'   => '🔒',
    'domain_add'           => '🌐',
    'domain_update'        => '✏️',
    'domain_delete'        => '🗑️',
    'manual_send'          => '📨',
];

$page_title = '로그 시스템';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h2>📋 로그 시스템</h2>
    <p>오늘 작업: <strong style="color:var(--primary)"><?= number_format($today_count) ?>건</strong></p>
  </div>
</div>

<!-- 검색 -->
<div class="search-bar">
  <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100">
    <div>
      <label class="form-label-dark">날짜</label>
      <input type="date" name="q_date" class="form-control-dark" value="<?= h($q_date) ?>" style="width:150px">
    </div>
    <div>
      <label class="form-label-dark">관리자 ID</label>
      <input type="text" name="q_admin" class="form-control-dark" style="width:130px"
             placeholder="관리자 ID" value="<?= h($q_admin) ?>">
    </div>
    <div>
      <label class="form-label-dark">작업 유형</label>
      <select name="q_action" class="form-control-dark" style="width:160px">
        <option value="">전체</option>
        <?php foreach ($action_list as $a): ?>
        <option value="<?= h($a['action']) ?>" <?= $q_action===$a['action']?'selected':'' ?>>
          <?= ($action_icons[$a['action']] ?? '📌') . ' ' . h($a['action']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label-dark">대상 (UID 등)</label>
      <input type="text" name="q_target" class="form-control-dark" style="width:140px"
             placeholder="대상 ID" value="<?= h($q_target) ?>">
    </div>
    <div class="d-flex gap-2">
      <button type="submit" class="btn-primary-glow">🔍 검색</button>
      <a href="audit_log.php" class="btn-glass">초기화</a>
    </div>
  </form>
</div>

<div class="card-glass">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
    <span style="color:var(--text-muted);font-size:0.83rem">
      총 <?= number_format($total) ?>건 | 페이지 <?= $page ?>/<?= $pages ?>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table-dark-custom">
      <thead>
        <tr>
          <th>#</th>
          <th>시각</th>
          <th>관리자</th>
          <th>역할</th>
          <th>작업</th>
          <th>대상</th>
          <th>IP</th>
          <th>변경 내용</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($logs)): ?>
        <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text-muted)">기록이 없습니다.</td></tr>
        <?php else: ?>
        <?php foreach ($logs as $log): ?>
        <?php
          $icon = $action_icons[$log['action']] ?? '📌';
          $before = $log['before_data'] ? json_decode($log['before_data'], true) : null;
          $after  = $log['after_data']  ? json_decode($log['after_data'],  true) : null;
        ?>
        <tr>
          <td style="color:var(--text-muted);font-size:0.75rem"><?= $log['log_no'] ?></td>
          <td style="font-size:0.78rem;white-space:nowrap;color:var(--text-muted)"><?= h(substr($log['created_at'], 5)) ?></td>
          <td class="fw-bold" style="color:var(--primary)"><?= h($log['admin_id']) ?></td>
          <td>
            <span class="badge-<?= $log['admin_role']==='super' ? 'primary' : 'warning' ?>">
              <?= $log['admin_role'] === 'super' ? '총어드민' : '센터' ?>
            </span>
          </td>
          <td>
            <span title="<?= h($log['action']) ?>"><?= $icon ?></span>
            <span style="font-size:0.78rem;color:var(--text-muted);margin-left:4px"><?= h($log['action']) ?></span>
          </td>
          <td class="mono" style="font-size:0.8rem"><?= h($log['target_id'] ?: '-') ?></td>
          <td style="font-size:0.75rem;color:var(--text-muted)"><?= h($log['ip_address'] ?: '-') ?></td>
          <td>
            <?php if ($after): ?>
            <button class="btn-glass" style="padding:3px 8px;font-size:0.72rem"
                    onclick="showDiff(<?= htmlspecialchars(json_encode(['before'=>$before,'after'=>$after], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
              변경내용
            </button>
            <?php else: ?>
            <span style="color:var(--text-muted);font-size:0.75rem">-</span>
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
    <a href="?page=<?= $page-1 ?>&q_date=<?= h($q_date) ?>&q_admin=<?= h($q_admin) ?>&q_action=<?= h($q_action) ?>&q_target=<?= h($q_target) ?>"
       class="btn-glass" style="padding:6px 12px;text-decoration:none">이전</a>
    <?php endif; ?>
    <?php for ($i = max(1,$page-2); $i <= min($pages,$page+2); $i++): ?>
    <a href="?page=<?= $i ?>&q_date=<?= h($q_date) ?>&q_admin=<?= h($q_admin) ?>&q_action=<?= h($q_action) ?>&q_target=<?= h($q_target) ?>"
       class="btn-glass" style="padding:6px 12px;text-decoration:none;<?= $i==$page ? 'background:rgba(0,212,255,0.15);color:var(--primary)' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $pages): ?>
    <a href="?page=<?= $page+1 ?>&q_date=<?= h($q_date) ?>&q_admin=<?= h($q_admin) ?>&q_action=<?= h($q_action) ?>&q_target=<?= h($q_target) ?>"
       class="btn-glass" style="padding:6px 12px;text-decoration:none">다음</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- 변경내용 상세 모달 -->
<div class="modal fade modal-dark" id="diffModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">🔍 변경 내용 상세</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div>
            <div style="color:var(--danger);font-weight:700;margin-bottom:8px;font-size:0.85rem">변경 전</div>
            <pre id="before-data" style="background:rgba(255,59,92,0.06);border:1px solid rgba(255,59,92,0.2);border-radius:8px;padding:12px;font-size:0.78rem;color:var(--text);overflow:auto;max-height:300px"></pre>
          </div>
          <div>
            <div style="color:var(--success);font-weight:700;margin-bottom:8px;font-size:0.85rem">변경 후</div>
            <pre id="after-data" style="background:rgba(0,255,157,0.06);border:1px solid rgba(0,255,157,0.2);border-radius:8px;padding:12px;font-size:0.78rem;color:var(--text);overflow:auto;max-height:300px"></pre>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function showDiff(data) {
  document.getElementById('before-data').textContent = JSON.stringify(data.before, null, 2) || '없음';
  document.getElementById('after-data').textContent  = JSON.stringify(data.after,  null, 2) || '없음';
  new bootstrap.Modal(document.getElementById('diffModal')).show();
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
