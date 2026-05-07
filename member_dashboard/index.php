<?php
/**
 * index.php - 58번 대시보드 서버 멀티도메인 라우터
 *
 * [동작 원리]
 * 1. 접속 도메인을 자동 감지
 * 2. rx_domain_config 테이블에서 해당 도메인의 거래소 코드(rx_ce) 조회
 * 3. rx_ce에 맞는 대시보드로 자동 이동
 *    - rx_ce=1 → dashboard.php (Toobit)
 *    - rx_ce=3 → dashboard_websea.php (WebSea)
 *    - rx_ce=4 → dashboard_deepcoin.php (Deepcoin)
 *
 * 업로드 경로: /home/killer_pro/index.php
 */

require_once __DIR__ . '/common.php';

// 로그인 여부 확인 → 미로그인 시 로그인 페이지로
if (!is_logged_in()) {
    redirect_to('/login.php');
}

// ── 접속 도메인 감지 ──────────────────────────────────────────
$current_domain = strtolower($_SERVER['HTTP_HOST'] ?? 'localhost');
$current_domain = preg_replace('/^www\./', '', $current_domain); // www. 제거

// ── DB에서 도메인 설정 조회 ───────────────────────────────────
$conn   = get_db_connection();
$d_safe = $conn->real_escape_string($current_domain);
$sql    = "SELECT * FROM rx_domain_config WHERE domain_host = '{$d_safe}' AND is_active = 1 LIMIT 1";
$res    = $conn->query($sql);

// 기본값 (도메인 등록이 안 된 경우 Toobit으로 분기)
$rx_ce = 1;

if ($res && $res->num_rows > 0) {
    $row   = $res->fetch_assoc();
    $rx_ce = intval($row['rx_ce']);
}

// ── 거래소 코드 → 대시보드 파일 라우팅 ─────────────────────────
$dashboard_map = [
    1 => 'dashboard.php',           // Toobit
    3 => 'dashboard_websea.php',    // WebSea
    4 => 'dashboard_deepcoin.php',  // Deepcoin
];

$dashboard = $dashboard_map[$rx_ce] ?? 'dashboard.php';

// 전역 상수 설정 (대시보드 파일에서 사용)
if (!defined('CURRENT_RX_CE')) {
    define('CURRENT_RX_CE',  $rx_ce);
    define('EXCHANGE_NAME',  get_exchange_name($rx_ce));
}

// ── 대시보드 파일 로드 ─────────────────────────────────────────
$dashboard_path = __DIR__ . '/' . $dashboard;

if (!file_exists($dashboard_path)) {
    error_log("[index.php] 대시보드 파일 없음: {$dashboard_path}", 3, LOG_PATH . '/php_error.log');
    $dashboard_path = __DIR__ . '/dashboard.php'; // 폴백
}

include $dashboard_path;
