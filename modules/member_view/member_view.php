<?php
/**
 * member_view.php - 관리자용 회원 대시보드 뷰어
 * 총어드민/센터어드민이 회원을 선택하면 해당 회원의 거래 대시보드를
 * iframe으로 표시합니다.
 *
 * [동작 방식]
 * 1. 왼쪽 패널: 회원 목록 (센터별 필터링)
 * 2. 오른쪽 패널: 선택한 회원의 dashboard*.php를 iframe으로 로드
 *    → admin_view=1 파라미터로 관리자 모드 진입
 *
 * [세션 브릿지]
 * 관리자 세션(PHPSESSID)과 회원 세션(rx_session)은 분리되어 있음
 * iframe에서는 rx_session 세션을 사용하므로 admin_view 파라미터로 인증
 */
require_once __DIR__ . '/../../includes/init.php';
require_login();

// 총어드민 + 센터어드민만 접근 가능
if (!is_super_admin() && !is_center_admin()) {
    http_response_code(403);
    die('접근 권한이 없습니다.');
}

global $EXCHANGE_LIST;

// 센터어드민은 자기 센터 회원만 조회
$center_filter = '';
$center_param  = [];
if (is_center_admin()) {
    $center_filter = ' AND mb_center = ?';
    $center_param  = [get_admin_center()];
}

// 검색 조건
$search_uid = trim($_GET['search_uid'] ?? '');
$search_ce  = (int)($_GET['search_ce'] ?? 0);

$where_sql = "1=1 AND rx_acount > 0" . $center_filter;
$params = $center_param;

if ($search_uid !== '') {
    $where_sql .= " AND rx_uid LIKE ?";
    $params[] = "%{$search_uid}%";
}
if ($search_ce > 0) {
    $where_sql .= " AND rx_ce = ?";
    $params[] = $search_ce;
}

// 회원 목록 조회 (g5_member에서)
$members = db_rows(
    "SELECT mb_no, rx_uid, rx_ce, mb_center, rx_acount, rx_send_ok, mb_datetime
     FROM g5_member
     WHERE {$where_sql}
     ORDER BY mb_datetime DESC
     LIMIT 200",
    $params
);

// 선택된 회원
$selected_uid = trim($_GET['view_uid'] ?? '');
$selected_ce  = (int)($_GET['view_ce'] ?? 0);
$selected_member = null;
if ($selected_uid !== '' && $selected_ce > 0) {
    $sel_params = [$selected_uid, $selected_ce];
    $sel_where  = "rx_uid = ? AND rx_ce = ?";
    if (is_center_admin()) {
        $sel_where .= " AND mb_center = ?";
        $sel_params[] = get_admin_center();
    }
    $selected_member = db_row(
        "SELECT * FROM g5_member WHERE {$sel_where} LIMIT 1",
        $sel_params
    );
}

$page_title = '회원 대시보드';
require_once __DIR__ . '/../../includes/header.php';
?>
<meta name="csrf-token" content="<?= h(generate_csrf_token()) ?>">

<div class="page-header">
  <h2>📈 회원 대시보드 뷰어</h2>
  <p>회원을 선택하면 거래소별 실시간 대시보드를 확인할 수 있습니다.</p>
</div>

<div style="display:grid;grid-template-columns:360px 1fr;gap:20px;min-height:80vh">
  <!-- 왼쪽: 회원 선택 패널 -->
  <div class="card-glass" style="overflow:hidden;display:flex;flex-direction:column">
    <div class="card-header-bar"><h5>👥 회원 선택</h5></div>

    <!-- 검색 -->
    <form method="GET" style="padding:12px 16px;border-bottom:1px solid var(--border)">
      <div style="display:flex;gap:6px">
        <input type="text" name="search_uid" class="form-control-dark" style="flex:1"
               placeholder="UID 검색" value="<?= h($search_uid) ?>">
        <select name="search_ce" class="form-control-dark" style="width:100px">
          <option value="0">전체</option>
          <?php foreach ($EXCHANGE_LIST as $ce_id => $ex): ?>
          <option value="<?= $ce_id ?>" <?= $search_ce == $ce_id ? 'selected' : '' ?>>
            <?= h($ex['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-primary-glow" style="padding:8px 14px">🔍</button>
      </div>
    </form>

    <!-- 회원 목록 -->
    <div style="flex:1;overflow-y:auto;padding:8px 12px">
      <?php if (empty($members)): ?>
      <div style="text-align:center;padding:40px 0;color:var(--text-muted)">
        회원이 없습니다.
      </div>
      <?php else: ?>
      <?php foreach ($members as $m):
        $ce = (int)$m['rx_ce'];
        $ex = $EXCHANGE_LIST[$ce] ?? ['name' => '?', 'color' => '#888'];
        $is_sel = ($selected_uid === $m['rx_uid'] && $selected_ce === $ce);
        $qs = http_build_query([
          'view_uid'   => $m['rx_uid'],
          'view_ce'    => $ce,
          'search_uid' => $search_uid,
          'search_ce'  => $search_ce,
        ]);
      ?>
      <a href="?<?= $qs ?>" style="text-decoration:none;display:block">
        <div style="display:flex;align-items:center;justify-content:space-between;
                    padding:10px 12px;border-radius:8px;margin-bottom:4px;
                    border:1px solid <?= $is_sel ? 'var(--primary)' : 'var(--border)' ?>;
                    background:<?= $is_sel ? 'rgba(0,212,255,0.08)' : 'transparent' ?>;
                    transition:all 0.2s;cursor:pointer"
             onmouseover="this.style.background='rgba(255,255,255,0.04)'"
             onmouseout="this.style.background='<?= $is_sel ? 'rgba(0,212,255,0.08)' : 'transparent' ?>'">
          <div>
            <div style="font-weight:700;color:#fff;font-size:0.88rem"><?= h($m['rx_uid']) ?></div>
            <div style="font-size:0.72rem;color:var(--text-muted)">
              <?= h($m['mb_center'] ?? '-') ?> · $<?= number_format($m['rx_acount']) ?>
            </div>
          </div>
          <div style="text-align:right">
            <span style="background:<?= $ex['color'] ?>22;color:<?= $ex['color'] ?>;
                         padding:2px 8px;border-radius:10px;font-size:0.7rem;font-weight:600">
              <?= h($ex['name']) ?>
            </span>
            <?php if ($m['rx_send_ok'] === 'Y'): ?>
            <div style="font-size:0.65rem;color:var(--success);margin-top:2px">●봇 가동중</div>
            <?php endif; ?>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div style="padding:10px 16px;border-top:1px solid var(--border);font-size:0.75rem;color:var(--text-muted)">
      총 <?= count($members) ?>명 표시 (최대 200명)
    </div>
  </div>

  <!-- 오른쪽: 대시보드 iframe -->
  <div class="card-glass" style="overflow:hidden;padding:0">
    <?php if ($selected_member): ?>
    <?php
      // 거래소별 대시보드 파일 결정
      $ce = (int)$selected_member['rx_ce'];
      $dash_type = 'toobit'; // 기본: Toobit
      if ($ce === 3) $dash_type = 'websea';
      if ($ce === 4) $dash_type = 'deepcoin';

      // admin_bridge.php를 경유하여 레거시 세션에 관리자 권한 설정
      $iframe_url = BASE_URL . '/member_dashboard/admin_bridge.php'
        . '?admin_uid=' . urlencode($selected_member['rx_uid'])
        . '&admin_ce=' . $ce
        . '&dash=' . $dash_type;
    ?>
    <!-- 선택 회원 정보 헤더 -->
    <div style="padding:14px 20px;border-bottom:1px solid var(--border);
                display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
      <div>
        <span style="font-weight:800;color:#fff;font-size:1rem"><?= h($selected_member['rx_uid']) ?></span>
        <span style="margin-left:8px;background:<?= $EXCHANGE_LIST[$ce]['color'] ?? '#888' ?>22;
                     color:<?= $EXCHANGE_LIST[$ce]['color'] ?? '#888' ?>;
                     padding:3px 10px;border-radius:10px;font-size:0.75rem;font-weight:600">
          <?= h($EXCHANGE_LIST[$ce]['name'] ?? '?') ?>
        </span>
      </div>
      <div style="display:flex;gap:12px;font-size:0.8rem;color:var(--text-muted)">
        <span>센터: <strong style="color:#fff"><?= h($selected_member['mb_center'] ?? '-') ?></strong></span>
        <span>투자금: <strong style="color:var(--success)">$<?= number_format($selected_member['rx_acount'] ?? 0) ?></strong></span>
        <a href="<?= $iframe_url ?>" target="_blank"
           style="color:var(--primary);text-decoration:none;font-size:0.75rem">🔗 새 창</a>
      </div>
    </div>

    <!-- iframe: 레거시 대시보드 로드 (코드 변경 없이!) -->
    <iframe src="<?= $iframe_url ?>"
            style="width:100%;height:calc(100vh - 200px);border:none;background:#fff"
            title="회원 대시보드"></iframe>

    <?php else: ?>
    <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;
                height:100%;padding:60px;text-align:center">
      <div style="font-size:4rem;margin-bottom:20px;opacity:0.3">📈</div>
      <h4 style="color:#fff;margin-bottom:8px">회원을 선택해주세요</h4>
      <p style="color:var(--text-muted);font-size:0.85rem">
        왼쪽 목록에서 회원을 클릭하면<br>해당 회원의 거래 대시보드가 표시됩니다.
      </p>
    </div>
    <?php endif; ?>
  </div>
</div>

<style>
  /* 반응형: 모바일에서는 세로 배치 */
  @media (max-width: 1024px) {
    div[style*="grid-template-columns:360px"] {
      grid-template-columns: 1fr !important;
    }
  }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
