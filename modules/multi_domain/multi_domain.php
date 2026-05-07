<?php
/**
 * multi_domain.php - 멀티도메인 관리 페이지
 * 컬럼: 도메인, 거래소(다중), CENTER, 사이트명, 로고(PNG), 색상, 상세
 */
require_once __DIR__ . '/../../includes/init.php';
// [통합] 58번 서버 기능이 147번으로 통합됨 — 별도 Push 불필요
require_login();
require_super_admin();

global $EXCHANGE_LIST;

// ============================================================
// AJAX 처리
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf_on_post();
    $act = post_input('act');

    // ── 도메인 추가/수정
    if ($act === 'save_domain') {
        $domain_no    = (int)post_input('domain_no', 0);
        $domain_url   = trim(post_input('domain_url'));
        $center_name  = trim(post_input('center_name'));
        $site_name    = trim(post_input('site_name'));
        $primary_color = trim(post_input('primary_color', '#00D4FF'));
        $rx_ce_list   = post_input('rx_ce_list', []);  // 멀티 거래소 ([] 배열)
        $is_active    = (int)post_input('is_active', 1);

        // 신규 센터 입력 처리
        $new_center = trim(post_input('new_center_name', ''));
        if ($new_center !== '') {
            $center_name = $new_center;
            // center_TB에 신규 등록 (중복 체크)
            $exists = db_scalar("SELECT COUNT(*) FROM center_TB WHERE center_name=?", [$center_name]);
            if (!$exists) {
                db_insert("INSERT INTO center_TB (center_name, is_active, created_at) VALUES (?, 1, NOW())", [$center_name]);
            }
        }

        // 거래소 배열 처리
        if (!is_array($rx_ce_list) || empty($rx_ce_list)) {
            json_response(false, '거래소를 1개 이상 선택해야 합니다.');
        }
        $rx_ce = (int)$rx_ce_list[0]; // 대표 거래소 (첫번째)
        $rx_ce_json = json_encode(array_map('intval', $rx_ce_list));

        if (empty($domain_url) || empty($center_name) || empty($site_name)) {
            json_response(false, '도메인, CENTER, 사이트명은 필수입니다.');
        }

        // www. 제거 정리
        $domain_url = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $domain_url);
        $domain_url = rtrim($domain_url, '/');

        // 로고 파일 업로드 처리
        $logo_path = post_input('current_logo_path', '');
        if (!empty($_FILES['logo']['name'])) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'webp'])) {
                json_response(false, '로고는 PNG, JPG, WEBP 파일만 허용합니다.');
            }
            $upload_dir = __DIR__ . '/../../assets/logos/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $filename = 'logo_' . preg_replace('/[^a-z0-9]/', '_', strtolower($domain_url)) . '.' . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $filename)) {
                $logo_path = '/assets/logos/' . $filename;
            }
        }

        // 거래소 이름 구하기
        $ex_name  = $EXCHANGE_LIST[$rx_ce]['name'] ?? 'Toobit';
        $ex_color = $EXCHANGE_LIST[$rx_ce]['color'] ?? '#0d6efd';

        if ($domain_no > 0) {
            // 수정
            $before = db_row("SELECT * FROM domain_management WHERE domain_no=?", [$domain_no]);
            $fields = ["domain_url=?","center_name=?","site_name=?","primary_color=?","rx_ce=?","exchange_name=?","exchanges=?","is_active=?","updated_at=NOW()"];
            $params = [$domain_url, $center_name, $site_name, $primary_color, $rx_ce, $ex_name, $rx_ce_json, $is_active];
            if ($logo_path) { $fields[] = "logo_path=?"; $params[] = $logo_path; }
            $params[] = $domain_no;
            db_execute("UPDATE domain_management SET " . implode(',', $fields) . " WHERE domain_no=?", $params);
            write_audit_log('domain_update', $domain_url, $before ?? [], ['rx_ce' => $rx_ce]);
        } else {
            // 추가
            $domain_no = db_insert(
                "INSERT INTO domain_management (domain_url, center_name, site_name, primary_color, rx_ce, exchange_name, exchanges, logo_path, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$domain_url, $center_name, $site_name, $primary_color, $rx_ce, $ex_name, $rx_ce_json, $logo_path ?: null, $is_active]
            );
            write_audit_log('domain_add', $domain_url, [], ['id' => $domain_no, 'rx_ce' => $rx_ce]);
        }

        // [통합] 동일 DB이므로 별도 Push 불필요
        json_response(true, '도메인 저장 완료.', ['domain_no' => $domain_no]);
    }

    // ── 도메인 삭제 (소프트)
    if ($act === 'delete_domain') {
        $domain_no = (int)post_input('domain_no', 0);
        $d = db_row("SELECT domain_url FROM domain_management WHERE domain_no=?", [$domain_no]);
        db_execute("UPDATE domain_management SET is_active=0 WHERE domain_no=?", [$domain_no]);

        // [통합] 동일 DB이므로 별도 Push 불필요

        write_audit_log('domain_delete', (string)$domain_no, [], []);
        json_response(true, '도메인이 비활성화되었습니다.');
    }

    json_response(false, '알 수 없는 요청');
}

// ============================================================
// 목록 조회
// ============================================================
$domains  = db_rows("SELECT * FROM domain_management ORDER BY created_at DESC");
$centers  = db_rows("SELECT center_name FROM center_TB ORDER BY center_name");

$page_title = '멀티도메인 관리';
require_once __DIR__ . '/../../includes/header.php';
?>
<meta name="csrf-token" content="<?= h(generate_csrf_token()) ?>">

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h2>🌐 멀티도메인 관리</h2>
    <p>등록된 도메인: <strong style="color:var(--primary)"><?= count($domains) ?>개</strong></p>
  </div>
  <button class="btn-primary-glow" onclick="openDomainModal(null)">
    ➕ 도메인 추가
  </button>
</div>

<div class="card-glass">
  <div class="table-responsive">
    <table class="table-dark-custom" id="domain-table" data-dt>
      <thead>
        <tr>
          <th>도메인</th>
          <th>CENTER</th>
          <th>사이트명</th>
          <th>거래소</th>
          <th>색상</th>
          <th>로고</th>
          <th>상태</th>
          <th>등록일</th>
          <th>관리</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($domains)): ?>
        <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text-muted)">등록된 도메인이 없습니다.</td></tr>
        <?php else: ?>
        <?php foreach ($domains as $d): ?>
        <?php
          $exs = json_decode($d['exchanges'] ?? '[]', true) ?: [];
        ?>
        <tr>
          <td>
            <a href="https://<?= h($d['domain_url']) ?>" target="_blank"
               style="color:var(--primary);text-decoration:none;font-weight:600">
              🔗 <?= h($d['domain_url']) ?>
            </a>
          </td>
          <td><span class="badge-muted"><?= h($d['center_name']) ?></span></td>
          <td class="fw-bold"><?= h($d['site_name']) ?></td>
          <td>
            <?php
              $rx_ce_val  = intval($d['rx_ce'] ?? 1);
              $ex_entry   = $EXCHANGE_LIST[$rx_ce_val] ?? null;
            ?>
            <?php if ($ex_entry): ?>
            <span class="badge-primary" style="background:<?= $ex_entry['color'] ?>22;color:<?= $ex_entry['color'] ?>">
              <?= h($ex_entry['name']) ?>
            </span>
            <?php else: ?>
            <span style="color:var(--text-muted)">rx_ce=<?= $rx_ce_val ?></span>
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="width:20px;height:20px;border-radius:50%;background:<?= h($d['primary_color']) ?>;border:2px solid rgba(255,255,255,0.2)"></div>
              <code style="font-size:0.75rem;color:var(--text-muted)"><?= h($d['primary_color']) ?></code>
            </div>
          </td>
          <td>
            <?php if ($d['logo_path']): ?>
            <img src="<?= h($d['logo_path']) ?>" alt="로고" style="height:28px;width:auto;border-radius:4px">
            <?php else: ?>
            <span style="color:var(--text-muted);font-size:0.78rem">없음</span>
            <?php endif; ?>
          </td>
          <td>
            <?= $d['is_active'] ? '<span class="badge-success">활성</span>' : '<span class="badge-danger">비활성</span>' ?>
          </td>
          <td style="font-size:0.78rem;color:var(--text-muted)"><?= h(substr($d['created_at'], 0, 10)) ?></td>
          <td>
            <div class="d-flex gap-1 flex-wrap">
              <button class="btn-primary-glow" style="padding:4px 10px;font-size:0.75rem"
                      onclick='openDomainModal(<?= htmlspecialchars(json_encode($d, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)'>
                수정
              </button>
              <button class="btn-glass" style="padding:4px 10px;font-size:0.75rem;color:var(--primary)"
                      onclick="push58Domain('<?= h($d['domain_url']) ?>')">
                📤 58Push
              </button>
              <button class="btn-glass" style="padding:4px 10px;font-size:0.75rem;color:var(--danger)"
                      onclick="deleteDomain(<?= $d['domain_no'] ?>, '<?= h($d['domain_url']) ?>')">
                삭제
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── 도메인 추가/수정 모달 ────────────────────────── -->
<div class="modal fade modal-dark" id="domainModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modal-title">🌐 도메인 등록</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="domain-form" enctype="multipart/form-data">
        <div class="modal-body">
          <input type="hidden" id="d_domain_no">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label-dark">도메인 URL *</label>
              <input type="text" id="d_domain_url" name="domain_url" class="form-control-dark"
                     placeholder="예: site.com (https:// 제외)">
            </div>
            <div class="col-md-4">
              <label class="form-label-dark">상태</label>
              <select id="d_is_active" name="is_active" class="form-control-dark">
                <option value="1">활성</option>
                <option value="0">비활성</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">CENTER * <small style="color:var(--text-muted)">(기존 선택 또는 신규 입력)</small></label>
              <select id="d_center" name="center_name" class="form-control-dark" onchange="toggleNewCenter(this.value)">
                <option value="">-- CENTER 선택 --</option>
                <?php foreach ($centers as $c): ?>
                <option value="<?= h($c['center_name']) ?>"><?= h($c['center_name']) ?></option>
                <?php endforeach; ?>
                <option value="__NEW__">➕ 신규 CENTER 등록</option>
              </select>
              <input type="text" id="d_new_center" name="new_center_name" class="form-control-dark"
                     placeholder="새 CENTER 이름 입력" style="margin-top:8px;display:none">
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">사이트명 *</label>
              <input type="text" id="d_site_name" name="site_name" class="form-control-dark" placeholder="사이트 표시 이름">
            </div>
            <div class="col-12">
              <label class="form-label-dark">연결 거래소 (복수 선택 가능) *</label>
              <div style="display:flex;flex-wrap:wrap;gap:10px;padding:12px;background:rgba(0,0,0,0.2);border:1px solid var(--border);border-radius:8px">
                <?php foreach ($EXCHANGE_LIST as $id => $ex): ?>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:8px 14px;border:1px solid var(--border);border-radius:8px;font-size:0.85rem;transition:all 0.2s">
                  <input type="checkbox" name="rx_ce_list[]" value="<?= $id ?>" class="rx-ce-check">
                  <span style="color:<?= $ex['color'] ?>;font-weight:700"><?= h($ex['name']) ?></span>
                </label>
                <?php endforeach; ?>
              </div>
              <small style="color:var(--text-muted);font-size:0.75rem">
                하나의 도메인에 여러 거래소를 연결할 수 있습니다
              </small>
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">기본 색상</label>
              <div class="d-flex gap-2 align-items-center">
                <input type="color" id="d_primary_color" name="primary_color"
                       value="#00D4FF" style="width:50px;height:36px;border:1px solid var(--border);border-radius:8px;background:transparent;cursor:pointer">
                <input type="text" id="d_color_text" class="form-control-dark" style="width:100px"
                       value="#00D4FF" oninput="syncColorPicker(this.value)">
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">로고 이미지 (PNG/JPG/WEBP)</label>
              <input type="file" id="d_logo" name="logo" accept="image/png,image/jpeg,image/webp"
                     class="form-control-dark" style="padding:8px">
              <div id="logo-preview" style="margin-top:8px"></div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-glass" data-bs-dismiss="modal">취소</button>
          <button type="submit" class="btn-primary-glow">💾 저장</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const DOMAIN_URL = 'multi_domain.php';

function openDomainModal(d) {
  const isEdit = d !== null;
  document.getElementById('modal-title').textContent = isEdit ? '🌐 도메인 수정' : '🌐 도메인 등록';
  document.getElementById('d_domain_no').value   = d?.domain_no   ?? '';
  document.getElementById('d_domain_url').value  = d?.domain_url  ?? '';
  document.getElementById('d_center').value      = d?.center_name ?? '';
  document.getElementById('d_site_name').value   = d?.site_name   ?? '';
  document.getElementById('d_primary_color').value = d?.primary_color ?? '#00D4FF';
  document.getElementById('d_color_text').value    = d?.primary_color ?? '#00D4FF';
  document.getElementById('d_is_active').value   = d?.is_active !== undefined ? d.is_active : 1;
  document.getElementById('d_new_center').style.display = 'none';
  document.getElementById('d_new_center').value = '';

  // 멀티 거래소 체크박스 설정
  document.querySelectorAll('.rx-ce-check').forEach(cb => cb.checked = false);
  if (d) {
    // exchanges 컬럼 (JSON 배열) 또는 rx_ce 단일값
    let ceList = [];
    try { ceList = JSON.parse(d.exchanges || '[]'); } catch(e) {}
    if (ceList.length === 0 && d.rx_ce) ceList = [parseInt(d.rx_ce)];
    ceList.forEach(ce => {
      const cb = document.querySelector(`.rx-ce-check[value="${ce}"]`);
      if (cb) cb.checked = true;
    });
  }

  new bootstrap.Modal(document.getElementById('domainModal')).show();
}

function toggleNewCenter(val) {
  document.getElementById('d_new_center').style.display = val === '__NEW__' ? 'block' : 'none';
}

// 58번 서버 수동 Push
function push58Domain(domain_url) {
  if (!confirm(`"${domain_url}" 도메인 설정을 58번 서버로 강제 Push하시겠습니까?`)) return;
  adminAjax(DOMAIN_URL, { act: 'save_domain', domain_url, force_push: 1 },
    (res) => showAlert(res.message, 'success')
  );
}

// 색상 피커 동기화
document.getElementById('d_primary_color').addEventListener('input', function() {
  document.getElementById('d_color_text').value = this.value;
});
function syncColorPicker(val) {
  if (/^#[0-9a-fA-F]{6}$/.test(val)) document.getElementById('d_primary_color').value = val;
}

// 로고 미리보기
document.getElementById('d_logo').addEventListener('change', function() {
  const file = this.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    document.getElementById('logo-preview').innerHTML =
      `<img src="${e.target.result}" style="height:32px;border-radius:4px" alt="미리보기">`;
  };
  reader.readAsDataURL(file);
});

// 폼 저장 (multipart)
document.getElementById('domain-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  const domain_no = document.getElementById('d_domain_no').value;
  const formData = new FormData(this);
  formData.set('act', 'save_domain');
  formData.set('domain_no', domain_no);
  formData.set('_csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

  const res = await fetch(DOMAIN_URL, { method: 'POST', body: formData });
  const json = await res.json();
  if (json.success) {
    showAlert(json.message, 'success');
    bootstrap.Modal.getInstance(document.getElementById('domainModal')).hide();
    setTimeout(() => location.reload(), 1200);
  } else {
    showAlert(json.message, 'danger');
  }
});

// 삭제
function deleteDomain(no, url) {
  if (!confirm(`"${url}" 도메인을 비활성화하시겠습니까?`)) return;
  adminAjax(DOMAIN_URL, { act: 'delete_domain', domain_no: no },
    (res) => { showAlert(res.message, 'success'); setTimeout(() => location.reload(), 1200); }
  );
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
