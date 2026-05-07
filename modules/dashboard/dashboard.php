<?php
/**
 * dashboard.php - 메인 대시보드
 * 거래소별 회원현황, Futures 총금액, 오늘 가입현황/투자금현황 차트
 */
require_once __DIR__ . '/../../includes/init.php';
require_login();

global $EXCHANGE_LIST;

// ============================================================
// 통계 데이터 조회
// ============================================================
$is_super   = is_super_admin();
$my_center  = get_admin_center();
$center_params = $is_super ? [] : [$my_center];
$center_where  = $is_super ? '' : 'AND mb_center = ?';

// 전체 회원 수
$total_members = (int)db_scalar(
    "SELECT COUNT(*) FROM g5_member WHERE 1=1 {$center_where}",
    $center_params
);

// 가동 중인 봇 수
$active_bots = (int)db_scalar(
    "SELECT COUNT(*) FROM g5_member WHERE rx_send_ok='Y' {$center_where}",
    $center_params
);

// 정지 중인 봇 수
$stopped_bots = (int)db_scalar(
    "SELECT COUNT(*) FROM g5_member WHERE rx_send_ok IN ('N','S') {$center_where}",
    $center_params
);

// 총 Futures 투자금
$total_futures = (float)db_scalar(
    "SELECT COALESCE(SUM(rx_acount),0) FROM g5_member WHERE 1=1 {$center_where}",
    $center_params
);

// CENTER 수 (총어드민만)
$total_centers = $is_super
    ? (int)db_scalar("SELECT COUNT(*) FROM center_TB WHERE is_active=1")
    : null;

// 거래소별 회원 수
$by_exchange = db_rows(
    "SELECT rx_ce, COUNT(*) as cnt, COALESCE(SUM(rx_acount),0) as total_futures
     FROM g5_member WHERE 1=1 {$center_where} GROUP BY rx_ce",
    $center_params
);
$exchange_stats = [];
$exchange_futures = [];
foreach ($by_exchange as $row) {
    $exchange_stats[$row['rx_ce']] = $row['cnt'];
    $exchange_futures[$row['rx_ce']] = $row['total_futures'];
}

// 오늘 가입자 수
$today_signups = (int)db_scalar(
    "SELECT COUNT(*) FROM g5_member WHERE DATE(mb_datetime)=CURDATE() {$center_where}",
    $center_params
);

// 최근 7일 일별 가입현황 (차트 데이터)
$daily_signups = db_rows(
    "SELECT DATE(mb_datetime) as dt, COUNT(*) as cnt
     FROM g5_member
     WHERE mb_datetime >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) {$center_where}
     GROUP BY DATE(mb_datetime)
     ORDER BY dt ASC",
    $center_params
);

// 최근 7일 일별 투자금 현황 (차트 데이터)
$daily_futures = db_rows(
    "SELECT DATE(mb_datetime) as dt, COALESCE(SUM(rx_acount),0) as total
     FROM g5_member
     WHERE mb_datetime >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) {$center_where}
     GROUP BY DATE(mb_datetime)
     ORDER BY dt ASC",
    $center_params
);

// 차트용 JSON 데이터 준비
$chart_labels = [];
$chart_signups = [];
$chart_futures = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chart_labels[] = date('m/d', strtotime($d));
    $found_s = false;
    foreach ($daily_signups as $r) {
        if ($r['dt'] === $d) { $chart_signups[] = (int)$r['cnt']; $found_s = true; break; }
    }
    if (!$found_s) $chart_signups[] = 0;

    $found_f = false;
    foreach ($daily_futures as $r) {
        if ($r['dt'] === $d) { $chart_futures[] = (float)$r['total']; $found_f = true; break; }
    }
    if (!$found_f) $chart_futures[] = 0;
}

$page_title = '대시보드';
require_once __DIR__ . '/../../includes/header.php';
?>
<meta name="csrf-token" content="<?= h(generate_csrf_token()) ?>">

<div class="page-header">
  <h2>📊 대시보드</h2>
  <p><?= date('Y년 m월 d일 H:i') ?> 기준</p>
</div>

<!-- ── 상태 표시줄 ─────────────────────────────────── -->
<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap">
  <span class="badge-success">🟢 DB 연결됨</span>
  <?php if ($is_super): ?>
  <span class="badge-primary">⚡ 총어드민 모드</span>
  <?php else: ?>
  <span class="badge-warning">🏢 CENTER: <?= h(get_admin_center()) ?></span>
  <?php endif; ?>
  <span class="badge-muted">오늘 가입: <strong style="color:var(--primary)"><?= $today_signups ?></strong>명</span>
</div>

<!-- ── 통계 카드 ───────────────────────────────────── -->
<div class="row g-3 mb-4" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">
  <div class="stat-card">
    <div class="stat-icon primary">👥</div>
    <div>
      <div class="stat-label">전체 회원</div>
      <div class="stat-value"><?= number_format($total_members) ?></div>
      <div class="stat-badge"><span class="badge-muted">명</span></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon success">🤖</div>
    <div>
      <div class="stat-label">가동 중인 봇</div>
      <div class="stat-value" style="color:var(--success)"><?= number_format($active_bots) ?></div>
      <div class="stat-badge"><span class="badge-success">ON</span></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon danger">💤</div>
    <div>
      <div class="stat-label">정지 봇</div>
      <div class="stat-value" style="color:var(--danger)"><?= number_format($stopped_bots) ?></div>
      <div class="stat-badge"><span class="badge-danger">OFF</span></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon warning">💰</div>
    <div>
      <div class="stat-label">총 Futures 투자금</div>
      <div class="stat-value"><?= number_format($total_futures, 0) ?></div>
      <div class="stat-badge"><span class="badge-warning">USDT</span></div>
    </div>
  </div>

  <?php if ($is_super && $total_centers !== null): ?>
  <div class="stat-card">
    <div class="stat-icon primary">🌐</div>
    <div>
      <div class="stat-label">활성 CENTER</div>
      <div class="stat-value"><?= number_format($total_centers) ?></div>
      <div class="stat-badge"><span class="badge-primary">개</span></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ── 차트 영역 ───────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px" class="mb-4">
  <!-- 7일 가입현황 차트 -->
  <div class="card-glass">
    <div class="card-header-bar">
      <h5>📈 최근 7일 가입현황</h5>
    </div>
    <div style="padding:10px">
      <canvas id="signupChart" height="200"></canvas>
    </div>
  </div>

  <!-- 7일 투자금현황 차트 -->
  <div class="card-glass">
    <div class="card-header-bar">
      <h5>💰 최근 7일 투자금 추이</h5>
    </div>
    <div style="padding:10px">
      <canvas id="futuresChart" height="200"></canvas>
    </div>
  </div>
</div>

<!-- ── 거래소별 현황 ──────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px" class="mb-4">
  <!-- 거래소별 회원수 -->
  <div class="card-glass">
    <div class="card-header-bar"><h5>🏦 거래소별 회원 현황</h5></div>
    <?php foreach ($EXCHANGE_LIST as $id => $ex): ?>
    <?php $cnt = $exchange_stats[$id] ?? 0; $fut = $exchange_futures[$id] ?? 0; ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--border)">
      <div>
        <span style="color:<?= $ex['color'] ?>;font-weight:700"><?= h($ex['name']) ?></span>
      </div>
      <div style="display:flex;align-items:center;gap:16px">
        <div style="width:120px;height:6px;background:rgba(255,255,255,0.08);border-radius:3px;overflow:hidden">
          <div style="width:<?= $total_members > 0 ? min(100, round($cnt/$total_members*100)) : 0 ?>%;height:100%;background:<?= $ex['color'] ?>;border-radius:3px;transition:width 1s ease"></div>
        </div>
        <span class="fw-bold" style="width:50px;text-align:right"><?= number_format($cnt) ?>명</span>
        <span style="color:var(--warning);font-size:0.78rem;width:90px;text-align:right"><?= number_format($fut,0) ?> USDT</span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- 거래소별 Futures 파이차트 -->
  <div class="card-glass">
    <div class="card-header-bar"><h5>🥧 거래소별 투자금 비중</h5></div>
    <div style="padding:10px;display:flex;justify-content:center">
      <canvas id="exchangePieChart" height="240" style="max-width:300px"></canvas>
    </div>
  </div>
</div>

<!-- ── 빠른 이동 버튼 ──────────────────────────────── -->
<?php if ($is_super): ?>
<div class="card-glass">
  <div class="card-header-bar"><h5>⚡ 빠른 이동</h5></div>
  <div style="display:flex;flex-wrap:wrap;gap:10px">
    <a href="<?= BASE_URL ?>/modules/members/members.php" class="btn-primary-glow" style="text-decoration:none">👥 회원관리</a>
    <a href="<?= BASE_URL ?>/modules/bot_setting/bot_setting.php" class="btn-glass" style="text-decoration:none">⚙️ Bot 세팅</a>
    <a href="<?= BASE_URL ?>/modules/bot_process/bot_process.php" class="btn-glass" style="text-decoration:none">🤖 Bot 프로세스</a>
    <a href="<?= BASE_URL ?>/modules/admin_bot/admin_bot.php" class="btn-glass" style="text-decoration:none">🛠️ 관리 Bot</a>
    <a href="<?= BASE_URL ?>/modules/bot_control/bot_control.php" class="btn-glass" style="text-decoration:none">🎮 Bot 제어</a>
    <a href="<?= BASE_URL ?>/modules/multi_domain/multi_domain.php" class="btn-glass" style="text-decoration:none">🌐 멀티도메인</a>
  </div>
</div>
<?php endif; ?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
// ── 공통 차트 스타일 설정
Chart.defaults.color = 'rgba(255,255,255,0.6)';
Chart.defaults.borderColor = 'rgba(255,255,255,0.06)';
Chart.defaults.font.family = "'Noto Sans KR', sans-serif";

// ── 7일 가입현황 (Bar + Line)
const signupCtx = document.getElementById('signupChart').getContext('2d');
new Chart(signupCtx, {
  type: 'bar',
  data: {
    labels: <?= json_encode($chart_labels) ?>,
    datasets: [{
      label: '가입자 수',
      data: <?= json_encode($chart_signups) ?>,
      backgroundColor: 'rgba(0,212,255,0.3)',
      borderColor: 'rgba(0,212,255,0.8)',
      borderWidth: 1,
      borderRadius: 6,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1 } }
    }
  }
});

// ── 7일 투자금 추이 (Line)
const futuresCtx = document.getElementById('futuresChart').getContext('2d');
new Chart(futuresCtx, {
  type: 'line',
  data: {
    labels: <?= json_encode($chart_labels) ?>,
    datasets: [{
      label: '투자금 (USDT)',
      data: <?= json_encode($chart_futures) ?>,
      borderColor: '#FFB800',
      backgroundColor: 'rgba(255,184,0,0.1)',
      fill: true,
      tension: 0.4,
      pointRadius: 4,
      pointBackgroundColor: '#FFB800',
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() + ' USDT' } }
    }
  }
});

// ── 거래소별 투자금 비중 (Pie)
const pieCtx = document.getElementById('exchangePieChart').getContext('2d');
const exList = <?= json_encode($EXCHANGE_LIST, JSON_UNESCAPED_UNICODE) ?>;
const exFutures = <?= json_encode($exchange_futures) ?>;
const pieLabels = [];
const pieData = [];
const pieColors = [];
for (const [id, ex] of Object.entries(exList)) {
  pieLabels.push(ex.name);
  pieData.push(exFutures[id] || 0);
  pieColors.push(ex.color);
}
new Chart(pieCtx, {
  type: 'doughnut',
  data: {
    labels: pieLabels,
    datasets: [{
      data: pieData,
      backgroundColor: pieColors.map(c => c + '66'),
      borderColor: pieColors,
      borderWidth: 2,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } }
    }
  }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
