<?php
/**
 * members.php - 회원관리 페이지
 * 컬럼: CENTER, 거래소, UID, 이름, 휴대폰, 클래스, Futures금액, Spot금액, 가입일, 메모, 상세
 * 기능: 인라인 수정(Futures/Spot금액), 검색, 상세 모달
 */
require_once __DIR__ . '/../../includes/init.php';
// [통합] 58번 서버 기능이 147번으로 통합됨 — 별도 Push 불필요
require_login();

global $EXCHANGE_LIST;

// ============================================================
// AJAX 처리 (POST 요청)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf_on_post(); // JSON body도 자동 파싱 (버그수정)
    $act = post_input('act');
    if (empty($act)) json_response(false, '잘못된 요청입니다.');

    // ── 회원 정보 업데이트 (상세 모달)
    if ($act === 'update_member') {
        $mb_no = (int)post_input('mb_no', 0);
        if ($mb_no <= 0) json_response(false, '잘못된 요청입니다.');

        // 센터어드민이면 자신의 CENTER만 수정 가능 (PDO 파라미터 방식으로 수정)
        if (is_center_admin()) {
            $chk = db_scalar(
                "SELECT COUNT(*) FROM g5_member WHERE mb_no=? AND mb_center=?",
                [$mb_no, (string)get_admin_center()]
            );
            if (!$chk) json_response(false, '수정 권한이 없습니다.');
        }

        // 변경 전 데이터 저장 (감사 로그용)
        $before = db_row("SELECT * FROM g5_member WHERE mb_no=?", [$mb_no]);

        // 업데이트할 필드 조립
        $fields = [];
        $params = [];

        // 공통 수정 가능 필드
        $editable = ['mb_name','mb_hp','mb_email','rx_acount','rx_spot_amount',
                     'rx_apikey','rx_sskey','rx_passphrase','mb_recommend',
                     'mb_memo','rx_rate','mb_class'];

        foreach ($editable as $field) {
            if (isset($_POST[$field])) {
                // [정책] API Key / Secret Key / Passphrase는 거래소 연동 키이므로 평문 저장
                // (암호화 미적용 - 거래소 API 호출 시 바로 사용되어야 함)
                $fields[] = "$field = ?";
                $params[] = trim($_POST[$field]);
            }
        }

        // 비밀번호 재설정 요청
        if (!empty($_POST['new_password'])) {
            $fields[] = "mb_password = ?";
            $params[] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        }

        // 활동/비활동 토글
        if (isset($_POST['is_active'])) {
            $is_active = (int)$_POST['is_active'];
            $fields[] = "mb_intercept_date = ?";
            $params[] = $is_active ? '' : date('Ymd'); // 0: 비활동 = intercept 날짜 설정
        }

        if (empty($fields)) json_response(false, '변경된 내용이 없습니다.');

        $params[] = $mb_no;
        db_execute("UPDATE g5_member SET " . implode(', ', $fields) . " WHERE mb_no=?", $params);

        // 감사 로그 기록
        $after = db_row("SELECT * FROM g5_member WHERE mb_no=?", [$mb_no]);
        write_audit_log('member_update', (string)$mb_no, $before ?? [], $after ?? []);

        // [통합] 동일 DB이므로 별도 Push 불필요

        json_response(true, '회원 정보가 수정되었습니다.');
    }

    // ── Futures/Spot 금액 인라인 수정
    if ($act === 'inline_update_amount') {
        $mb_no  = (int)post_input('mb_no', 0);
        $field  = post_input('field', '');  // 'rx_acount' 또는 'rx_spot_amount'
        $value  = (float)post_input('value', 0);

        if (!in_array($field, ['rx_acount', 'rx_spot_amount'])) {
            json_response(false, '허용되지 않는 필드입니다.');
        }

        db_execute("UPDATE g5_member SET $field=? WHERE mb_no=?", [$value, $mb_no]);
        write_audit_log("member_{$field}_update", (string)$mb_no, [], [$field => $value]);

        // [통합] 동일 DB이므로 별도 Push 불필요

        json_response(true, '금액이 수정되었습니다.', ['value' => $value]);
    }

    // ── 메모 저장
    if ($act === 'save_memo') {
        $mb_no = (int)post_input('mb_no', 0);
        $memo  = trim(post_input('memo', ''));
        db_execute("UPDATE g5_member SET mb_memo=? WHERE mb_no=?", [$memo, $mb_no]);
        write_audit_log('member_memo_update', (string)$mb_no, [], ['memo' => $memo]);
        json_response(true, '메모가 저장되었습니다.');
    }

    // ── 회원 신규 추가
    if ($act === 'add_member') {
        $rx_uid     = trim(post_input('rx_uid'));
        $mb_name    = trim(post_input('mb_name'));
        $mb_center  = trim(post_input('mb_center'));
        $rx_ce      = (int)post_input('rx_ce', 1);
        $rx_acount  = (float)post_input('rx_acount', 0);
        $rx_apikey  = trim(post_input('rx_apikey', ''));
        $rx_sskey   = trim(post_input('rx_sskey', ''));
        $rx_passphrase = trim(post_input('rx_passphrase', ''));
        $mb_hp      = trim(post_input('mb_hp', ''));
        $mb_email   = trim(post_input('mb_email', ''));
        $mb_class   = (int)post_input('mb_class', 1);
        $rx_rate    = (float)post_input('rx_rate', 5);

        if (empty($rx_uid) || empty($mb_name) || empty($mb_center)) {
            json_response(false, 'UID, 이름, CENTER는 필수 입력값입니다.');
        }

        // UID 중복 체크
        $exists = db_scalar("SELECT COUNT(*) FROM g5_member WHERE rx_uid=?", [$rx_uid]);
        if ($exists) json_response(false, "이미 등록된 UID입니다: {$rx_uid}");

        // DB 삽입
        $mb_no = db_insert(
            "INSERT INTO g5_member (mb_id, rx_uid, mb_name, mb_center, rx_ce, rx_acount,
                rx_apikey, rx_sskey, rx_passphrase, mb_hp, mb_email, mb_class, rx_rate,
                rx_send_ok, mb_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'N', NOW())",
            [$rx_uid, $rx_uid, $mb_name, $mb_center, $rx_ce, $rx_acount,
             $rx_apikey, $rx_sskey, $rx_passphrase, $mb_hp, $mb_email, $mb_class, $rx_rate]
        );

        write_audit_log('member_add', $rx_uid, [], ['mb_no' => $mb_no, 'center' => $mb_center]);

        // [통합] 동일 DB이므로 별도 Push 불필요

        json_response(true, "회원 [{$rx_uid}]이 추가되었습니다.", ['mb_no' => $mb_no]);
    }

    json_response(false, '알 수 없는 요청입니다.');
}

// ============================================================
// 목록 조회
// ============================================================
$search_uid    = trim($_GET['q_uid']    ?? '');
$search_name   = trim($_GET['q_name']  ?? '');
$search_center = trim($_GET['q_center'] ?? '');
$search_ce     = (int)($_GET['q_ce']   ?? 0);
$search_class  = (int)($_GET['q_class'] ?? 0);

// WHERE 조건 조립
$where  = "1=1";
$params = [];

// [버그수정] 센터어드민 격리 - PDO 바인딩 파라미터 방식 (기존 quote() 방식 제거)
if (is_center_admin()) {
    $center_val = (string)get_admin_center();
    $where      .= " AND m.mb_center = ?";
    $params[]    = $center_val;
} elseif ($search_center !== '') {
    $where   .= " AND m.mb_center = ?";
    $params[] = $search_center;
}

if ($search_uid !== '') {
    $where   .= " AND m.rx_uid LIKE ?";
    $params[] = '%' . $search_uid . '%';
}
if ($search_name !== '') {
    $where   .= " AND m.mb_name LIKE ?";
    $params[] = '%' . $search_name . '%';
}
if ($search_ce > 0) {
    $where   .= " AND m.rx_ce = ?";
    $params[] = $search_ce;
}
if ($search_class > 0) {
    $where   .= " AND m.mb_class = ?";
    $params[] = $search_class;
}

// 목록 조회
$members = db_rows(
    "SELECT m.mb_no, m.mb_id, m.rx_uid, m.mb_name, m.mb_hp, m.mb_class,
            m.rx_acount, m.rx_spot_amount, m.mb_datetime, m.mb_memo,
            m.mb_center, m.rx_ce, m.mb_intercept_date
     FROM g5_member m
     WHERE $where
     ORDER BY m.mb_datetime DESC",
    $params
);

$total = count($members);

// ── CENTER 목록 (총어드민용 필터)
$centers = is_super_admin() ? db_rows("SELECT center_name FROM center_TB ORDER BY center_name") : [];

$page_title = '회원관리';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- CSRF 토큰 메타 태그 -->
<meta name="csrf-token" content="<?= h(generate_csrf_token()) ?>">

<!-- ── 페이지 헤더 ─────────────────────────────────── -->
<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h2>👥 회원관리</h2>
    <p>전체 <strong style="color:var(--primary)"><?= number_format($total) ?></strong>명</p>
  </div>
  <button class="btn-primary-glow" onclick="openAddModal()">➕ 회원 추가</button>
</div>

<!-- ── 검색 ───────────────────────────────────────── -->
<div class="search-bar">
  <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100" id="search-form">
    <div>
      <label class="form-label-dark">UID</label>
      <input type="text" name="q_uid" class="form-control-dark" style="width:150px"
             placeholder="UID 검색" value="<?= h($search_uid) ?>">
    </div>
    <div>
      <label class="form-label-dark">이름</label>
      <input type="text" name="q_name" class="form-control-dark" style="width:130px"
             placeholder="이름 검색" value="<?= h($search_name) ?>">
    </div>
    <?php if (is_super_admin()): ?>
    <div>
      <label class="form-label-dark">CENTER</label>
      <select name="q_center" class="form-control-dark" style="width:140px">
        <option value="">전체 CENTER</option>
        <?php foreach ($centers as $c): ?>
        <option value="<?= h($c['center_name']) ?>" <?= $search_center === $c['center_name'] ? 'selected' : '' ?>>
          <?= h($c['center_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div>
      <label class="form-label-dark">거래소</label>
      <select name="q_ce" class="form-control-dark" style="width:130px">
        <option value="0">전체</option>
        <?php foreach ($EXCHANGE_LIST as $id => $ex): ?>
        <option value="<?= $id ?>" <?= $search_ce === $id ? 'selected' : '' ?>><?= h($ex['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label-dark">클래스</label>
      <select name="q_class" class="form-control-dark" style="width:110px">
        <option value="0">전체</option>
        <?php foreach ([1,2,3,9] as $cls): ?>
        <option value="<?= $cls ?>" <?= $search_class === $cls ? 'selected' : '' ?>>CLASS <?= $cls ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="d-flex gap-2">
      <button type="submit" class="btn-primary-glow">🔍 검색</button>
      <a href="members.php" class="btn-glass">초기화</a>
    </div>
  </form>
</div>

<!-- ── 회원 테이블 ─────────────────────────────────── -->
<div class="card-glass">
  <div class="table-responsive">
    <table class="table-dark-custom" id="members-table" data-dt>
      <thead>
        <tr>
          <th>CENTER</th>
          <th>거래소</th>
          <th>UID</th>
          <th>이름</th>
          <th>휴대폰</th>
          <th>클래스</th>
          <th>Futures금액</th>
          <th>Spot금액</th>
          <th>상태</th>
          <th>가입일</th>
          <th>메모</th>
          <th>상세</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($members)): ?>
        <tr><td colspan="12" style="text-align:center;padding:30px;color:var(--text-muted)">데이터가 없습니다.</td></tr>
        <?php else: ?>
        <?php foreach ($members as $m): ?>
        <?php
          $ex_info = $EXCHANGE_LIST[$m['rx_ce']] ?? ['name' => '알 수 없음', 'color' => '#888'];
          $is_active = empty($m['mb_intercept_date']) || $m['mb_intercept_date'] === '00000000';
        ?>
        <tr>
          <td><span class="badge-muted"><?= h($m['mb_center']) ?></span></td>
          <td>
            <span class="badge-primary" style="background:<?= $ex_info['color'] ?>22;color:<?= $ex_info['color'] ?>">
              <?= h($ex_info['name']) ?>
            </span>
          </td>
          <td class="mono"><?= h($m['rx_uid']) ?></td>
          <td class="fw-bold"><?= h($m['mb_name']) ?></td>
          <td><?= h($m['mb_hp']) ?></td>
          <td><span class="badge-muted">CLASS <?= (int)$m['mb_class'] ?></span></td>

          <!-- Futures금액 인라인 수정 -->
          <td>
            <input type="number" class="inline-edit-input"
                   id="futures_<?= $m['mb_no'] ?>"
                   value="<?= (int)$m['rx_acount'] ?>"
                   onblur="inlineUpdateAmount(<?= $m['mb_no'] ?>, 'rx_acount', this.value)"
                   onkeydown="if(event.key==='Enter')this.blur()">
          </td>

          <!-- Spot금액 인라인 수정 -->
          <td>
            <input type="number" class="inline-edit-input"
                   id="spot_<?= $m['mb_no'] ?>"
                   value="<?= (int)($m['rx_spot_amount'] ?? 0) ?>"
                   onblur="inlineUpdateAmount(<?= $m['mb_no'] ?>, 'rx_spot_amount', this.value)"
                   onkeydown="if(event.key==='Enter')this.blur()">
          </td>

          <td>
            <?php if ($is_active): ?>
            <span class="status-on"><span class="dot-on"></span>활동</span>
            <?php else: ?>
            <span class="status-off">비활동</span>
            <?php endif; ?>
          </td>
          <td style="font-size:0.78rem;color:var(--text-muted)"><?= h(substr($m['mb_datetime'], 0, 10)) ?></td>
          <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.78rem;color:var(--text-muted)"
              title="<?= h($m['mb_memo']) ?>">
            <?= h($m['mb_memo']) ?: '-' ?>
          </td>
          <td>
            <button class="btn-primary-glow" style="padding:5px 12px;font-size:0.78rem"
                    onclick="openMemberModal(<?= htmlspecialchars(json_encode($m, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
              상세
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── 회원 상세 모달 ──────────────────────────────── -->
<div class="modal fade modal-dark" id="memberModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">👤 회원 상세 정보</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="member-edit-form">
        <div class="modal-body">
          <input type="hidden" id="m_mb_no">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label-dark">UID (거래소)</label>
              <input type="text" id="m_rx_uid" class="form-control-dark" readonly style="opacity:0.6">
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">이름</label>
              <input type="text" id="m_mb_name" name="mb_name" class="form-control-dark">
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">비밀번호 재설정 (입력 시에만 변경)</label>
              <input type="password" id="m_new_password" name="new_password" class="form-control-dark" placeholder="새 비밀번호 입력">
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">이메일</label>
              <input type="email" id="m_mb_email" name="mb_email" class="form-control-dark">
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">CENTER</label>
              <input type="text" id="m_mb_center" class="form-control-dark" readonly style="opacity:0.6">
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">휴대폰</label>
              <input type="text" id="m_mb_hp" name="mb_hp" class="form-control-dark">
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">Futures 금액 (USDT)</label>
              <input type="number" id="m_rx_acount" name="rx_acount" class="form-control-dark">
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">Spot 금액 (USDT)</label>
              <input type="number" id="m_rx_spot" name="rx_spot_amount" class="form-control-dark">
            </div>
            <div class="col-md-12">
              <label class="form-label-dark">API Key</label>
              <input type="text" id="m_rx_apikey" name="rx_apikey" class="form-control-dark mono" placeholder="변경 시만 입력">
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">Secret Key</label>
              <input type="text" id="m_rx_sskey" name="rx_sskey" class="form-control-dark mono" placeholder="변경 시만 입력">
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">Passphrase</label>
              <input type="text" id="m_rx_passphrase" name="rx_passphrase" class="form-control-dark mono" placeholder="변경 시만 입력">
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">추천인 UID</label>
              <input type="text" id="m_mb_recommend" name="mb_recommend" class="form-control-dark">
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">활동 상태</label>
              <select id="m_is_active" name="is_active" class="form-control-dark">
                <option value="1">활동</option>
                <option value="0">비활동</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label-dark">메모</label>
              <textarea id="m_mb_memo" name="mb_memo" class="form-control-dark" rows="3" style="resize:vertical"></textarea>
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
// ── 회원 상세 모달 열기
function openMemberModal(m) {
  document.getElementById('m_mb_no').value       = m.mb_no;
  document.getElementById('m_rx_uid').value      = m.rx_uid || '';
  document.getElementById('m_mb_name').value     = m.mb_name || '';
  document.getElementById('m_mb_email').value    = m.mb_email || '';
  document.getElementById('m_mb_center').value   = m.mb_center || '';
  document.getElementById('m_mb_hp').value       = m.mb_hp || '';
  document.getElementById('m_rx_acount').value   = m.rx_acount || 0;
  document.getElementById('m_rx_spot').value     = m.rx_spot_amount || 0;
  document.getElementById('m_mb_recommend').value = m.mb_recommend || '';
  document.getElementById('m_mb_memo').value     = m.mb_memo || '';
  document.getElementById('m_new_password').value = '';
  document.getElementById('m_rx_apikey').value   = '';  // 보안상 빈 칸으로
  document.getElementById('m_rx_sskey').value    = '';
  document.getElementById('m_rx_passphrase').value = '';
  document.getElementById('m_is_active').value   = (m.mb_intercept_date === '' || m.mb_intercept_date === '00000000') ? '1' : '0';

  new bootstrap.Modal(document.getElementById('memberModal')).show();
}

// ── 모달 폼 저장
document.getElementById('member-edit-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  const mb_no = document.getElementById('m_mb_no').value;

  // 폼 데이터 수집
  const data = { act: 'update_member', mb_no };
  this.querySelectorAll('[name]').forEach(el => { if (el.value !== '') data[el.name] = el.value; });

  adminAjax('members.php', data, (res) => {
    showAlert(res.message, 'success');
    bootstrap.Modal.getInstance(document.getElementById('memberModal')).hide();
    setTimeout(() => location.reload(), 1200);
  });
});

// ── Futures/Spot 인라인 수정
function inlineUpdateAmount(mb_no, field, value) {
  adminAjax('members.php', { act: 'inline_update_amount', mb_no, field, value }, (res) => {
    showAlert(res.message, 'success');
  });
}

// ── 회원 추가 모달
function openAddModal() {
  new bootstrap.Modal(document.getElementById('addMemberModal')).show();
}

document.getElementById('add-member-form').addEventListener('submit', function(e) {
  e.preventDefault();
  const data = { act: 'add_member' };
  this.querySelectorAll('[name]').forEach(el => {
    if (el.value !== '') data[el.name] = el.value;
  });
  adminAjax('members.php', data, (res) => {
    showAlert(res.message, 'success');
    bootstrap.Modal.getInstance(document.getElementById('addMemberModal')).hide();
    setTimeout(() => location.reload(), 1200);
  });
});
</script>

<!-- ── 회원 추가 모달 ────────────────────────────── -->
<div class="modal fade modal-dark" id="addMemberModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">➕ 회원 추가</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="add-member-form">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label-dark">UID (거래소) *</label>
              <input type="text" name="rx_uid" class="form-control-dark" placeholder="거래소 UID" required>
            </div>
            <div class="col-md-4">
              <label class="form-label-dark">이름 *</label>
              <input type="text" name="mb_name" class="form-control-dark" placeholder="실명" required>
            </div>
            <div class="col-md-4">
              <label class="form-label-dark">CENTER *</label>
              <select name="mb_center" class="form-control-dark" required>
                <option value="">-- 선택 --</option>
                <?php foreach ($centers as $c): ?>
                <option value="<?= h($c['center_name']) ?>"><?= h($c['center_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label-dark">거래소</label>
              <select name="rx_ce" class="form-control-dark">
                <?php foreach ($EXCHANGE_LIST as $id => $ex): ?>
                <option value="<?= $id ?>"><?= h($ex['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label-dark">클래스</label>
              <select name="mb_class" class="form-control-dark">
                <option value="1">CLASS 1</option>
                <option value="2">CLASS 2</option>
                <option value="3">CLASS 3</option>
                <option value="9">CLASS 9</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label-dark">Futures 금액 (USDT)</label>
              <input type="number" name="rx_acount" class="form-control-dark" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label-dark">요율 (%)</label>
              <input type="number" name="rx_rate" class="form-control-dark" value="5" step="0.1">
            </div>
            <div class="col-md-4">
              <label class="form-label-dark">휴대폰</label>
              <input type="text" name="mb_hp" class="form-control-dark" placeholder="010-0000-0000">
            </div>
            <div class="col-md-4">
              <label class="form-label-dark">이메일</label>
              <input type="email" name="mb_email" class="form-control-dark" placeholder="이메일">
            </div>
            <div class="col-md-12">
              <label class="form-label-dark">API Key</label>
              <input type="text" name="rx_apikey" class="form-control-dark mono" placeholder="API Key">
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">Secret Key</label>
              <input type="text" name="rx_sskey" class="form-control-dark mono" placeholder="Secret Key">
            </div>
            <div class="col-md-6">
              <label class="form-label-dark">Passphrase</label>
              <input type="text" name="rx_passphrase" class="form-control-dark mono" placeholder="Passphrase">
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
