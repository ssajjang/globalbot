<?php
/**
 * white_ip.php - 화이트 IP 관리 페이지
 * 총어드민만 접근 가능. 관리자 페이지에 접속 허용할 IP를 관리합니다.
 */
require_once __DIR__ . '/../../includes/init.php';
require_login();
require_super_admin();

// ============================================================
// AJAX 처리
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf_on_post();
    $act = post_input('act');

    // ── IP 추가
    if ($act === 'add_ip') {
        $ip   = trim(post_input('ip_address'));
        $memo = trim(post_input('memo', ''));

        if (empty($ip)) json_response(false, 'IP 주소를 입력하세요.');

        // IPv4 또는 IPv6 형식 검증
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            json_response(false, '올바른 IP 주소 형식이 아닙니다.');
        }

        // 중복 체크
        $exists = db_scalar("SELECT COUNT(*) FROM white_ip_list WHERE ip_address=?", [$ip]);
        if ($exists) json_response(false, '이미 등록된 IP입니다: ' . $ip);

        db_insert(
            "INSERT INTO white_ip_list (ip_address, memo, is_active, created_by, created_at) VALUES (?, ?, 1, ?, NOW())",
            [$ip, $memo, $_SESSION['admin_id']]
        );

        write_audit_log('white_ip_add', $ip, [], ['memo' => $memo]);
        json_response(true, "IP [{$ip}] 등록 완료");
    }

    // ── IP 삭제 (비활성화)
    if ($act === 'delete_ip') {
        $ip_no = (int)post_input('ip_no', 0);
        if ($ip_no <= 0) json_response(false, '잘못된 요청');

        $row = db_row("SELECT ip_address FROM white_ip_list WHERE ip_no=?", [$ip_no]);
        db_execute("UPDATE white_ip_list SET is_active=0 WHERE ip_no=?", [$ip_no]);

        write_audit_log('white_ip_delete', $row['ip_address'] ?? '', [], []);
        json_response(true, 'IP 비활성화 완료');
    }

    // ── IP 활성화 토글
    if ($act === 'toggle_ip') {
        $ip_no = (int)post_input('ip_no', 0);
        $active = (int)post_input('is_active', 1);
        db_execute("UPDATE white_ip_list SET is_active=? WHERE ip_no=?", [$active, $ip_no]);
        json_response(true, $active ? 'IP 활성화 완료' : 'IP 비활성화 완료');
    }

    json_response(false, '알 수 없는 요청');
}

// ============================================================
// 목록 조회
// ============================================================
$ip_list = db_rows("SELECT * FROM white_ip_list ORDER BY is_active DESC, created_at DESC");
$my_ip   = get_client_ip();

$page_title = 'IP 화이트리스트';
require_once __DIR__ . '/../../includes/header.php';
?>
<meta name="csrf-token" content="<?= h(generate_csrf_token()) ?>">

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h2>🔒 IP 화이트리스트 관리</h2>
    <p>현재 접속 IP: <strong style="color:var(--primary)"><?= h($my_ip) ?></strong></p>
  </div>
</div>

<!-- 하드코딩 IP 안내 -->
<div class="card-glass" style="margin-bottom:20px">
  <div class="card-header-bar"><h5>📋 기본 허용 IP (config.php)</h5></div>
  <div style="display:flex;flex-wrap:wrap;gap:10px;padding:4px 0">
    <?php global $WHITE_IP_LIST; foreach ($WHITE_IP_LIST as $wip): ?>
    <span class="badge-success" style="font-size:0.82rem;padding:6px 12px">
      ✅ <?= h($wip) ?>
    </span>
    <?php endforeach; ?>
  </div>
  <small style="color:var(--text-muted);font-size:0.75rem">이 IP는 코드에 하드코딩되어 항상 접속 가능합니다</small>
</div>

<!-- IP 추가 폼 -->
<div class="card-glass" style="margin-bottom:20px">
  <div class="card-header-bar"><h5>➕ IP 추가 등록</h5></div>
  <div class="d-flex flex-wrap gap-3 align-items-end">
    <div>
      <label class="form-label-dark">IP 주소 *</label>
      <input type="text" id="new-ip" class="form-control-dark" style="width:220px"
             placeholder="예: 211.115.65.58">
    </div>
    <div>
      <label class="form-label-dark">메모</label>
      <input type="text" id="new-memo" class="form-control-dark" style="width:220px"
             placeholder="예: 김대표 사무실">
    </div>
    <button class="btn-primary-glow" onclick="addIp()">➕ 등록</button>
    <button class="btn-glass" onclick="addCurrentIp()">📍 현재 IP 등록</button>
  </div>
</div>

<!-- IP 목록 -->
<div class="card-glass">
  <div class="card-header-bar"><h5>📋 등록된 IP 목록</h5></div>
  <div class="table-responsive">
    <table class="table-dark-custom" id="ip-table" data-dt>
      <thead>
        <tr>
          <th>IP 주소</th>
          <th>메모</th>
          <th>상태</th>
          <th>등록자</th>
          <th>등록일</th>
          <th>관리</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ip_list as $ip): ?>
        <tr>
          <td class="mono fw-bold"><?= h($ip['ip_address']) ?></td>
          <td style="color:var(--text-muted);font-size:0.82rem"><?= h($ip['memo'] ?? '') ?></td>
          <td>
            <?php if ($ip['is_active']): ?>
            <span class="badge-success">✅ 활성</span>
            <?php else: ?>
            <span class="badge-danger">❌ 비활성</span>
            <?php endif; ?>
          </td>
          <td style="font-size:0.78rem"><?= h($ip['created_by'] ?? '-') ?></td>
          <td style="font-size:0.78rem;color:var(--text-muted)"><?= h(substr($ip['created_at'] ?? '', 0, 16)) ?></td>
          <td>
            <div class="d-flex gap-1">
              <?php if ($ip['is_active']): ?>
              <button class="btn-glass" style="padding:4px 10px;font-size:0.75rem;color:var(--danger)"
                      onclick="deleteIp(<?= $ip['ip_no'] ?>, '<?= h($ip['ip_address']) ?>')">비활성화</button>
              <?php else: ?>
              <button class="btn-glass" style="padding:4px 10px;font-size:0.75rem;color:var(--success)"
                      onclick="toggleIp(<?= $ip['ip_no'] ?>, 1)">활성화</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function addIp() {
  const ip = document.getElementById('new-ip').value.trim();
  const memo = document.getElementById('new-memo').value.trim();
  if (!ip) { showAlert('IP 주소를 입력하세요.', 'warning'); return; }
  adminAjax('white_ip.php', { act: 'add_ip', ip_address: ip, memo },
    (res) => { showAlert(res.message, 'success'); setTimeout(() => location.reload(), 1000); }
  );
}
function addCurrentIp() {
  document.getElementById('new-ip').value = '<?= h($my_ip) ?>';
  document.getElementById('new-memo').value = '현재 접속 IP (자동입력)';
}
function deleteIp(no, ip) {
  if (!confirm(`IP [${ip}]를 비활성화하시겠습니까?`)) return;
  adminAjax('white_ip.php', { act: 'delete_ip', ip_no: no },
    (res) => { showAlert(res.message, 'success'); setTimeout(() => location.reload(), 1000); }
  );
}
function toggleIp(no, active) {
  adminAjax('white_ip.php', { act: 'toggle_ip', ip_no: no, is_active: active },
    (res) => { showAlert(res.message, 'success'); setTimeout(() => location.reload(), 1000); }
  );
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
