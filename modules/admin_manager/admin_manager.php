<?php
/**
 * admin_manager.php - 마스터/서브 관리자 계정 관리
 * 총어드민 전용: 관리자 생성, 메뉴 권한 부여, 비밀번호 변경
 */
require_once __DIR__ . '/../../includes/init.php';
require_login();
require_super_admin();

// ============================================================
// 허용 가능한 메뉴 목록 (권한 체크 키 기준)
// ============================================================
const MENU_LIST = [
    'dashboard'         => '📊 대시보드',
    'members'           => '👥 회원관리',
    'bot_setting'       => '⚙️ Bot 세팅',
    'bot_process'       => '🤖 Bot 프로세스',
    'admin_bot'         => '🛠️ 관리 Bot',
    'bot_control'       => '🎮 Bot 제어',
    'multi_domain'      => '🌐 멀티도메인 관리',
    'trans_list'        => '📨 전송기록',
    'manual_transfer'   => '📨 센터텔레그램',
    'subscription_stat' => '💰 구독료통계',
    'audit_log'         => '📋 로그 시스템',
    'db_backup'         => '💾 DB 백업',
    'admin_manager'     => '🔑 관리자 계정관리',
];

// ============================================================
// AJAX 처리
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf_on_post();
    $act = post_input('act');

    // ── 관리자 계정 생성
    if ($act === 'create_admin') {
        $admin_id    = trim(post_input('admin_id'));
        $admin_pw    = post_input('admin_pw');
        $admin_role  = post_input('admin_role', 'sub');     // 'center' 또는 'sub'
        $admin_name  = trim(post_input('admin_name'));
        $admin_center = trim(post_input('admin_center', ''));
        $admin_email  = trim(post_input('admin_email', ''));
        $permissions  = post_input('permissions', []);      // 배열: 허용 메뉴 키 목록

        // 입력값 검증
        if (empty($admin_id) || empty($admin_pw) || empty($admin_name)) {
            json_response(false, 'ID, 비밀번호, 이름은 필수 입력값입니다.');
        }
        if (!preg_match('/^[a-z0-9_]{4,20}$/', $admin_id)) {
            json_response(false, 'ID는 영문 소문자, 숫자, _ 만 사용 가능하며 4~20자입니다.');
        }
        if (strlen($admin_pw) < 8) {
            json_response(false, '비밀번호는 8자 이상이어야 합니다.');
        }
        if (!in_array($admin_role, ['center', 'sub'])) {
            json_response(false, '유효하지 않은 역할입니다.');
        }
        // 서브어드민은 CENTER 불필요 (강제 비움)
        if ($admin_role === 'sub') {
            $admin_center = '';
        }
        if ($admin_role === 'center' && empty($admin_center)) {
            json_response(false, '센터어드민은 소속 CENTER를 선택해야 합니다.');
        }

        // 중복 ID 체크
        $exists = db_scalar("SELECT COUNT(*) FROM admin_accounts WHERE admin_id=?", [$admin_id]);
        if ($exists) json_response(false, "이미 사용 중인 ID입니다: {$admin_id}");

        // 허용 메뉴 유효성 검증
        $valid_menus = array_keys(MENU_LIST);
        $permissions = is_array($permissions)
            ? array_values(array_filter($permissions, fn($m) => in_array($m, $valid_menus)))
            : [];

        // 총어드민이 서브관리자 만들 때는 자신이 가진 권한 범위 내에서만 부여 가능
        // (총어드민 자신은 모든 권한이므로 그대로 통과)

        $new_id = db_insert(
            "INSERT INTO admin_accounts
             (admin_id, admin_pw, admin_role, admin_name, admin_email, admin_center, permissions, parent_admin_id, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())",
            [
                $admin_id,
                password_hash($admin_pw, PASSWORD_DEFAULT),
                $admin_role,
                $admin_name,
                $admin_email,
                $admin_center ?: null,
                json_encode($permissions, JSON_UNESCAPED_UNICODE),
                $_SESSION['admin_id'],
            ]
        );

        write_audit_log('admin_create', $admin_id, [], [
            'role'        => $admin_role,
            'center'      => $admin_center,
            'permissions' => $permissions,
        ]);
        json_response(true, "관리자 계정 [{$admin_id}]이 생성되었습니다.", ['admin_no' => $new_id]);
    }

    // ── 관리자 권한(메뉴) 수정
    if ($act === 'update_permissions') {
        $admin_no    = (int)post_input('admin_no', 0);
        $permissions = post_input('permissions', []);

        if ($admin_no <= 0) json_response(false, '잘못된 요청입니다.');

        // 대상 관리자 확인 (총어드민은 수정 불가)
        $target = db_row("SELECT * FROM admin_accounts WHERE admin_no=?", [$admin_no]);
        if (!$target) json_response(false, '해당 관리자를 찾을 수 없습니다.');
        if ($target['admin_role'] === 'super') json_response(false, '총어드민의 권한은 수정할 수 없습니다.');

        $valid_menus = array_keys(MENU_LIST);
        $permissions = is_array($permissions)
            ? array_values(array_filter($permissions, fn($m) => in_array($m, $valid_menus)))
            : [];

        $before = ['permissions' => $target['permissions']];
        db_execute(
            "UPDATE admin_accounts SET permissions=? WHERE admin_no=?",
            [json_encode($permissions, JSON_UNESCAPED_UNICODE), $admin_no]
        );

        write_audit_log('admin_permissions_update', $target['admin_id'], $before, ['permissions' => $permissions]);
        json_response(true, "권한이 업데이트되었습니다.");
    }

    // ── 비밀번호 변경
    if ($act === 'change_password') {
        $admin_no  = (int)post_input('admin_no', 0);
        $new_pw    = post_input('new_pw');

        if (strlen($new_pw) < 8) json_response(false, '비밀번호는 8자 이상이어야 합니다.');

        $target = db_row("SELECT admin_id, admin_role FROM admin_accounts WHERE admin_no=?", [$admin_no]);
        if (!$target) json_response(false, '해당 관리자를 찾을 수 없습니다.');
        if ($target['admin_role'] === 'super' && $target['admin_id'] !== $_SESSION['admin_id']) {
            json_response(false, '다른 총어드민의 비밀번호는 변경할 수 없습니다.');
        }

        db_execute("UPDATE admin_accounts SET admin_pw=? WHERE admin_no=?",
            [password_hash($new_pw, PASSWORD_DEFAULT), $admin_no]);

        write_audit_log('admin_password_change', $target['admin_id'], [], []);
        json_response(true, "비밀번호가 변경되었습니다.");
    }

    // ── 활성/비활성 토글
    if ($act === 'toggle_active') {
        $admin_no  = (int)post_input('admin_no', 0);
        $is_active = (int)post_input('is_active', 0);

        $target = db_row("SELECT admin_id, admin_role FROM admin_accounts WHERE admin_no=?", [$admin_no]);
        if (!$target) json_response(false, '해당 관리자를 찾을 수 없습니다.');
        if ($target['admin_role'] === 'super') json_response(false, '총어드민 계정은 비활성화할 수 없습니다.');

        db_execute("UPDATE admin_accounts SET is_active=? WHERE admin_no=?", [$is_active, $admin_no]);
        write_audit_log('admin_toggle_active', $target['admin_id'], [], ['is_active' => $is_active]);
        json_response(true, $is_active ? '계정이 활성화되었습니다.' : '계정이 비활성화되었습니다.');
    }

    // ── 삭제 (소프트)
    if ($act === 'delete_admin') {
        $admin_no = (int)post_input('admin_no', 0);
        $target   = db_row("SELECT admin_id, admin_role FROM admin_accounts WHERE admin_no=?", [$admin_no]);
        if (!$target) json_response(false, '해당 관리자를 찾을 수 없습니다.');
        if ($target['admin_role'] === 'super') json_response(false, '총어드민 계정은 삭제할 수 없습니다.');
        if ($target['admin_id'] === $_SESSION['admin_id']) json_response(false, '자기 자신은 삭제할 수 없습니다.');

        db_execute("UPDATE admin_accounts SET is_active=0 WHERE admin_no=?", [$admin_no]);
        write_audit_log('admin_delete', $target['admin_id'], [], []);
        json_response(true, "관리자 [{$target['admin_id']}]이 삭제되었습니다.");
    }

    json_response(false, '알 수 없는 요청');
}

// ============================================================
// 관리자 목록 조회
// ============================================================
$admins  = db_rows("SELECT * FROM admin_accounts ORDER BY admin_role ASC, created_at DESC");
$centers = db_rows("SELECT center_name FROM center_TB ORDER BY center_name");

$role_labels = ['super' => '총어드민', 'center' => '센터어드민', 'sub' => '서브어드민'];
$role_colors = ['super' => 'primary', 'center' => 'warning', 'sub' => 'muted'];

$page_title = '관리자 계정관리';
require_once __DIR__ . '/../../includes/header.php';
?>
<meta name="csrf-token" content="<?= h(generate_csrf_token()) ?>">

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h2>🔑 관리자 계정관리</h2>
    <p>전체 <strong style="color:var(--primary)"><?= count($admins) ?>명</strong> | 총어드민만 접근 가능</p>
  </div>
  <button class="btn-primary-glow" onclick="openCreateModal()">➕ 관리자 추가</button>
</div>

<!-- 관리자 목록 -->
<div class="card-glass">
  <div class="table-responsive">
    <table class="table-dark-custom">
      <thead>
        <tr>
          <th>역할</th><th>ID</th><th>이름</th><th>이메일</th>
          <th>소속 CENTER</th><th>허용 메뉴 수</th><th>상태</th>
          <th>생성일</th><th>관리</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($admins as $a):
          $perms = json_decode($a['permissions'] ?? '[]', true) ?: [];
          $role_color = $role_colors[$a['admin_role']] ?? 'muted';
        ?>
        <tr>
          <td><span class="badge-<?= $role_color ?>"><?= h($role_labels[$a['admin_role']] ?? $a['admin_role']) ?></span></td>
          <td class="fw-bold mono"><?= h($a['admin_id']) ?></td>
          <td><?= h($a['admin_name']) ?></td>
          <td style="font-size:0.78rem;color:var(--text-muted)"><?= h($a['admin_email'] ?: '-') ?></td>
          <td><?= h($a['admin_center'] ?: '-') ?></td>
          <td>
            <?php if ($a['admin_role'] === 'super'): ?>
            <span class="badge-primary">전체 허용</span>
            <?php else: ?>
            <span class="badge-muted"><?= count($perms) ?>/<?= count(MENU_LIST) ?>개</span>
            <?php endif; ?>
          </td>
          <td><?= $a['is_active'] ? '<span class="badge-success">활성</span>' : '<span class="badge-danger">비활성</span>' ?></td>
          <td style="font-size:0.78rem;color:var(--text-muted)"><?= h(substr($a['created_at'], 0, 10)) ?></td>
          <td>
            <div class="d-flex gap-1 flex-wrap">
              <?php if ($a['admin_role'] !== 'super'): ?>
              <button class="btn-glass" style="padding:4px 8px;font-size:0.72rem"
                      onclick='openPermModal(<?= htmlspecialchars(json_encode($a, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)'>
                권한
              </button>
              <button class="btn-glass" style="padding:4px 8px;font-size:0.72rem;color:var(--warning)"
                      onclick="changePw(<?= $a['admin_no'] ?>, '<?= h($a['admin_id']) ?>')">
                비번
              </button>
              <button class="btn-glass" style="padding:4px 8px;font-size:0.72rem;color:<?= $a['is_active'] ? 'var(--danger)' : 'var(--success)' ?>"
                      onclick="toggleActive(<?= $a['admin_no'] ?>, <?= $a['is_active'] ? 0 : 1 ?>)">
                <?= $a['is_active'] ? '비활성' : '활성화' ?>
              </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── 관리자 생성 모달 -->
<div class="modal fade modal-dark" id="createModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">➕ 관리자 계정 생성</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="create-form">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label-dark">역할 *</label>
              <select id="c_role" name="admin_role" class="form-control-dark" onchange="toggleCenterField(this.value)">
                <option value="sub" selected>서브어드민</option>
                <option value="center">센터어드민</option>
              </select>
            </div>
            <div class="col-md-4" id="center-field" style="display:none">
              <label class="form-label-dark">소속 CENTER</label>
              <select name="admin_center" class="form-control-dark">
                <option value="">-- CENTER 선택 --</option>
                <?php foreach ($centers as $c): ?>
                <option value="<?= h($c['center_name']) ?>"><?= h($c['center_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label-dark">로그인 ID * (영문소문자+숫자, 4~20자)</label>
              <input type="text" name="admin_id" class="form-control-dark" placeholder="예: manager01" required>
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">이름 *</label>
              <input type="text" name="admin_name" class="form-control-dark" placeholder="실명 입력" required>
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">이메일</label>
              <input type="email" name="admin_email" class="form-control-dark" placeholder="이메일 (선택)">
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">비밀번호 * (8자 이상)</label>
              <input type="password" name="admin_pw" class="form-control-dark" placeholder="8자 이상" required>
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">비밀번호 확인</label>
              <input type="password" id="admin_pw2" class="form-control-dark" placeholder="비밀번호 재입력">
            </div>

            <!-- 서브어드민 메뉴 권한 -->
            <div class="col-12" id="perm-field">
              <label class="form-label-dark">허용할 메뉴 선택 (서브어드민용)</label>
              <div style="background:rgba(0,0,0,0.2);border:1px solid var(--border);border-radius:8px;padding:16px">
                <div style="display:flex;gap:8px;margin-bottom:12px">
                  <button type="button" class="btn-glass" style="padding:4px 10px;font-size:0.78rem" onclick="checkAllMenus(true)">전체 선택</button>
                  <button type="button" class="btn-glass" style="padding:4px 10px;font-size:0.78rem" onclick="checkAllMenus(false)">전체 해제</button>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px">
                  <?php foreach (MENU_LIST as $key => $label): ?>
                  <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.83rem;padding:6px 8px;border:1px solid var(--border);border-radius:6px">
                    <input type="checkbox" name="permissions[]" value="<?= h($key) ?>" class="menu-check">
                    <span><?= h($label) ?></span>
                  </label>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-glass" data-bs-dismiss="modal">취소</button>
          <button type="submit" class="btn-primary-glow">💾 생성</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── 권한 수정 모달 -->
<div class="modal fade modal-dark" id="permModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="perm-modal-title">🔑 메뉴 권한 수정</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="perm-form">
        <div class="modal-body">
          <input type="hidden" id="p_admin_no">
          <div style="background:rgba(0,0,0,0.2);border:1px solid var(--border);border-radius:8px;padding:16px">
            <div style="display:flex;gap:8px;margin-bottom:12px">
              <button type="button" class="btn-glass" style="padding:4px 10px;font-size:0.78rem" onclick="checkPermMenus(true)">전체 선택</button>
              <button type="button" class="btn-glass" style="padding:4px 10px;font-size:0.78rem" onclick="checkPermMenus(false)">전체 해제</button>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px">
              <?php foreach (MENU_LIST as $key => $label): ?>
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.83rem;padding:6px 8px;border:1px solid var(--border);border-radius:6px">
                <input type="checkbox" name="permissions[]" value="<?= h($key) ?>" class="perm-check" id="pm_<?= h($key) ?>">
                <span><?= h($label) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-glass" data-bs-dismiss="modal">취소</button>
          <button type="submit" class="btn-success-glow">💾 권한 저장</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const ADMIN_MGR_URL = 'admin_manager.php';

function openCreateModal() {
  new bootstrap.Modal(document.getElementById('createModal')).show();
}

function toggleCenterField(role) {
  document.getElementById('center-field').style.display = role === 'center' ? 'block' : 'none';
  document.getElementById('perm-field').style.display   = role === 'sub'    ? 'block' : 'none';
}

function checkAllMenus(check) {
  document.querySelectorAll('.menu-check').forEach(cb => cb.checked = check);
}

// 관리자 생성 폼
document.getElementById('create-form').addEventListener('submit', function(e) {
  e.preventDefault();
  const pw  = this.querySelector('[name="admin_pw"]').value;
  const pw2 = document.getElementById('admin_pw2').value;
  if (pw !== pw2) { showAlert('비밀번호가 일치하지 않습니다.', 'danger'); return; }

  const data = { act: 'create_admin' };
  this.querySelectorAll('[name]').forEach(el => {
    if (el.type === 'checkbox' && el.name === 'permissions[]') {
      if (el.checked) {
        if (!data.permissions) data.permissions = [];
        data.permissions.push(el.value);
      }
    } else if (el.type !== 'checkbox') {
      data[el.name] = el.value;
    }
  });
  if (!data.permissions) data.permissions = [];

  adminAjax(ADMIN_MGR_URL, data, (res) => {
    showAlert(res.message, 'success');
    bootstrap.Modal.getInstance(document.getElementById('createModal')).hide();
    setTimeout(() => location.reload(), 1200);
  });
});

// 권한 수정 모달 열기
function openPermModal(a) {
  document.getElementById('p_admin_no').value = a.admin_no;
  document.getElementById('perm-modal-title').textContent = `🔑 권한 수정: ${a.admin_id}`;
  const perms = typeof a.permissions === 'string' ? JSON.parse(a.permissions || '[]') : (a.permissions || []);
  document.querySelectorAll('.perm-check').forEach(cb => cb.checked = perms.includes(cb.value));
  new bootstrap.Modal(document.getElementById('permModal')).show();
}

function checkPermMenus(check) {
  document.querySelectorAll('.perm-check').forEach(cb => cb.checked = check);
}

document.getElementById('perm-form').addEventListener('submit', function(e) {
  e.preventDefault();
  const admin_no  = document.getElementById('p_admin_no').value;
  const permissions = [...document.querySelectorAll('.perm-check:checked')].map(cb => cb.value);
  adminAjax(ADMIN_MGR_URL, { act: 'update_permissions', admin_no, permissions }, (res) => {
    showAlert(res.message, 'success');
    bootstrap.Modal.getInstance(document.getElementById('permModal')).hide();
    setTimeout(() => location.reload(), 1200);
  });
});

// 비밀번호 변경
function changePw(admin_no, admin_id) {
  const new_pw = prompt(`[${admin_id}] 새 비밀번호 (8자 이상):`);
  if (!new_pw) return;
  if (new_pw.length < 8) { showAlert('8자 이상 입력하세요.', 'warning'); return; }
  adminAjax(ADMIN_MGR_URL, { act: 'change_password', admin_no, new_pw },
    (res) => showAlert(res.message, 'success')
  );
}

// 활성/비활성 토글
function toggleActive(admin_no, is_active) {
  if (!confirm(`계정을 ${is_active ? '활성화' : '비활성화'}하시겠습니까?`)) return;
  adminAjax(ADMIN_MGR_URL, { act: 'toggle_active', admin_no, is_active },
    (res) => { showAlert(res.message, 'success'); setTimeout(() => location.reload(), 1200); }
  );
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
