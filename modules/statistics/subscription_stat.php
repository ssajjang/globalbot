<?php
/**
 * subscription_stat.php - 구독료 통계 페이지
 * 월별/CENTER별/거래소별 구독료 현황, 클래스별 인원 분포
 */
require_once __DIR__ . '/../../includes/init.php';
require_login();
require_super_admin();

global $EXCHANGE_LIST;

// 기간 선택
$q_year  = (int)($_GET['q_year']  ?? date('Y'));
$q_month = (int)($_GET['q_month'] ?? date('n'));

// ============================================================
// 통계 데이터 조회
// ============================================================

// 1. 클래스별 인원 + 투자금
$class_stats = db_rows(
    "SELECT mb_class, COUNT(*) as cnt, SUM(rx_acount) as total_futures, SUM(rx_spot_amount) as total_spot
     FROM g5_member
     WHERE rx_send_ok IN ('Y','R')
     GROUP BY mb_class ORDER BY mb_class"
);

// 2. CENTER별 통계
$center_stats = db_rows(
    "SELECT mb_center, COUNT(*) as cnt,
            SUM(rx_acount) as total_futures,
            SUM(rx_spot_amount) as total_spot
     FROM g5_member
     WHERE rx_send_ok IN ('Y','R')
     GROUP BY mb_center ORDER BY total_futures DESC"
);

// 3. 거래소별 통계
$exchange_stats = db_rows(
    "SELECT rx_ce, COUNT(*) as cnt, SUM(rx_acount) as total_futures
     FROM g5_member
     WHERE rx_send_ok IN ('Y','R')
     GROUP BY rx_ce ORDER BY total_futures DESC"
);

// 4. 전체 요약
$total_active  = (int)db_scalar("SELECT COUNT(*) FROM g5_member WHERE rx_send_ok IN ('Y','R')");
$total_futures = (float)db_scalar("SELECT COALESCE(SUM(rx_acount),0) FROM g5_member WHERE rx_send_ok IN ('Y','R')");
$total_spot    = (float)db_scalar("SELECT COALESCE(SUM(rx_spot_amount),0) FROM g5_member WHERE rx_send_ok IN ('Y','R')");
$avg_futures   = $total_active > 0 ? $total_futures / $total_active : 0;

// 5. 클래스별 구독료 계산 (추정: Futures금액의 일정 %)
$class_sub_rates = [
    1 => 0.10, // CLASS 1: 10%
    2 => 0.08, // CLASS 2: 8%
    3 => 0.06, // CLASS 3: 6%
    9 => 0.15, // CLASS 9: 15%
];

$page_title = '구독료통계';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <h2>💰 구독료 통계</h2>
  <p>가동 중인 봇 기준 | 총 <?= number_format($total_active) ?>명</p>
</div>

<!-- ── 핵심 통계 카드 ──────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-icon success">💰</div>
    <div>
      <div class="stat-label">총 Futures 투자금</div>
      <div class="stat-value"><?= number_format($total_futures, 0) ?></div>
      <div class="stat-badge"><span class="badge-success">USDT</span></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon primary">💵</div>
    <div>
      <div class="stat-label">총 Spot 투자금</div>
      <div class="stat-value"><?= number_format($total_spot, 0) ?></div>
      <div class="stat-badge"><span class="badge-primary">USDT</span></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon warning">📊</div>
    <div>
      <div class="stat-label">1인 평균 투자금</div>
      <div class="stat-value"><?= number_format($avg_futures, 0) ?></div>
      <div class="stat-badge"><span class="badge-warning">USDT</span></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon primary">👥</div>
    <div>
      <div class="stat-label">가동 회원</div>
      <div class="stat-value"><?= number_format($total_active) ?></div>
      <div class="stat-badge"><span class="badge-primary">명</span></div>
    </div>
  </div>
</div>

<!-- ── 클래스별 현황 + 거래소별 현황 ─────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">

  <!-- 클래스별 -->
  <div class="card-glass">
    <div class="card-header-bar"><h5>📊 클래스별 현황</h5></div>
    <table class="table-dark-custom">
      <thead>
        <tr>
          <th>클래스</th>
          <th style="text-align:right">인원</th>
          <th style="text-align:right">Futures금액</th>
          <th style="text-align:right">Spot금액</th>
          <th style="text-align:right">추정 구독료</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($class_stats as $cs): ?>
        <?php
          $rate = $class_sub_rates[$cs['mb_class']] ?? 0.1;
          $sub_amount = (float)$cs['total_futures'] * $rate;
          $icons = [1=>'🥇', 2=>'🥈', 3=>'🥉', 9=>'⭐'];
        ?>
        <tr>
          <td><?= $icons[$cs['mb_class']] ?? '' ?> CLASS <?= $cs['mb_class'] ?></td>
          <td style="text-align:right;font-weight:700"><?= number_format($cs['cnt']) ?>명</td>
          <td style="text-align:right;color:var(--success)"><?= number_format($cs['total_futures'], 0) ?></td>
          <td style="text-align:right;color:var(--primary)"><?= number_format($cs['total_spot'], 0) ?></td>
          <td style="text-align:right;color:var(--warning);font-weight:700">
            <?= number_format($sub_amount, 0) ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($class_stats)): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:20px">데이터 없음</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- 거래소별 -->
  <div class="card-glass">
    <div class="card-header-bar"><h5>🏦 거래소별 현황</h5></div>
    <table class="table-dark-custom">
      <thead>
        <tr>
          <th>거래소</th>
          <th style="text-align:right">인원</th>
          <th style="text-align:right">Futures금액</th>
          <th style="text-align:right">비중</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($exchange_stats as $es): ?>
        <?php
          $ex   = $EXCHANGE_LIST[$es['rx_ce']] ?? ['name' => '알 수 없음', 'color' => '#888'];
          $pct  = $total_futures > 0 ? round((float)$es['total_futures'] / $total_futures * 100, 1) : 0;
        ?>
        <tr>
          <td>
            <span class="badge-primary" style="background:<?= $ex['color'] ?>22;color:<?= $ex['color'] ?>">
              <?= h($ex['name']) ?>
            </span>
          </td>
          <td style="text-align:right;font-weight:700"><?= number_format($es['cnt']) ?>명</td>
          <td style="text-align:right;color:var(--success)"><?= number_format($es['total_futures'], 0) ?></td>
          <td style="text-align:right">
            <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px">
              <div style="width:60px;height:5px;background:rgba(255,255,255,0.08);border-radius:3px;overflow:hidden">
                <div style="width:<?= $pct ?>%;height:100%;background:<?= $ex['color'] ?>;border-radius:3px"></div>
              </div>
              <span style="font-size:0.78rem;color:var(--text-muted)"><?= $pct ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── CENTER별 통계 ───────────────────────────────── -->
<div class="card-glass">
  <div class="card-header-bar"><h5>🏢 CENTER별 상세 현황</h5></div>
  <div class="table-responsive">
    <table class="table-dark-custom" id="center-stat-table" data-dt>
      <thead>
        <tr>
          <th>CENTER</th>
          <th style="text-align:right">인원</th>
          <th style="text-align:right">Futures 총액</th>
          <th style="text-align:right">Spot 총액</th>
          <th style="text-align:right">1인 평균</th>
          <th style="text-align:right">추정 구독료 (10%)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($center_stats as $cs): ?>
        <?php
          $avg = $cs['cnt'] > 0 ? (float)$cs['total_futures'] / $cs['cnt'] : 0;
          $sub = (float)$cs['total_futures'] * 0.1;
        ?>
        <tr>
          <td class="fw-bold"><?= h($cs['mb_center'] ?: '미지정') ?></td>
          <td style="text-align:right"><?= number_format($cs['cnt']) ?>명</td>
          <td style="text-align:right;color:var(--success)"><?= number_format($cs['total_futures'], 0) ?></td>
          <td style="text-align:right;color:var(--primary)"><?= number_format($cs['total_spot'], 0) ?></td>
          <td style="text-align:right;color:var(--text-muted)"><?= number_format($avg, 0) ?></td>
          <td style="text-align:right;color:var(--warning);font-weight:700"><?= number_format($sub, 0) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($center_stats)): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:20px">데이터 없음</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
