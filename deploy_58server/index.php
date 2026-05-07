<?php
/**
 * index.php - 58번 대시보드 멀티도메인 자동 라우터
 *
 * [연동 구조]
 * 147번 글로벌어드민 → multi_domain.php에서 도메인 등록
 * → 58번 서버 이 파일에서 도메인별 거래소(rx_ce) 자동 분기
 * → dashboard.php (Toobit/1), dashboard_deepcoin.php (4), dashboard_websea.php (3)
 *
 * [멀티도메인 분기 원리]
 * rx_domain_config 테이블에서 현재 도메인의 거래소 코드(rx_ce)를 조회
 * → 해당 거래소에 맞는 대시보드 파일로 include
 *
 * 58번 서버에 배포하는 파일입니다.
 */

require_once __DIR__ . '/common.php';

// ============================================================
// 1. 현재 접속 도메인 감지
// ============================================================
$current_domain = strtolower($_SERVER['HTTP_HOST'] ?? 'localhost');
// www. 제거
$current_domain = preg_replace('/^www\./', '', $current_domain);

// ============================================================
// 2. 도메인별 거래소 설정 조회 (147번 관리자가 등록한 도메인)
// ============================================================
$conn = get_db_connection();

$domain_safe = $conn->real_escape_string($current_domain);
$sql_domain = "SELECT * FROM rx_domain_config WHERE domain_host = '{$domain_safe}' AND is_active = 1 LIMIT 1";
$res_domain = $conn->query($sql_domain);

// 기본값: Toobit (rx_ce=1)
$domain_config = [
    'domain_host'    => $current_domain,
    'rx_ce'          => 1,                // 1:Toobit, 2:Gateio, 3:Websea, 4:Deepcoin
    'exchange_name'  => 'Toobit',
    'site_name'      => 'Trading Dashboard',
    'logo_url'       => '/assets/img/logo_default.png',
    'theme_color'    => '#0d6efd',
    'dashboard_file' => 'dashboard.php',
];

if ($res_domain && $res_domain->num_rows > 0) {
    $row = $res_domain->fetch_assoc();
    $domain_config = array_merge($domain_config, [
        'rx_ce'          => intval($row['rx_ce']),
        'exchange_name'  => $row['exchange_name'] ?? 'Toobit',
        'site_name'      => $row['site_name']      ?? 'Trading Dashboard',
        'logo_url'       => $row['logo_url']       ?? '/assets/img/logo_default.png',
        'theme_color'    => $row['theme_color']    ?? '#0d6efd',
        'telegram_bot'   => $row['telegram_bot']   ?? '',
    ]);
}

// ============================================================
// 3. rx_ce → 대시보드 파일 라우팅
// ============================================================
$dashboard_map = [
    1 => 'dashboard.php',           // Toobit
    2 => 'dashboard_gateio.php',    // Gate.io
    3 => 'dashboard_websea.php',    // WebSea
    4 => 'dashboard_deepcoin.php',  // Deepcoin
];

$dashboard_file = $dashboard_map[$domain_config['rx_ce']] ?? 'dashboard.php';

// ============================================================
// 4. 도메인 설정을 전역 변수로 공유 (각 대시보드에서 사용)
// ============================================================
$GLOBALS['domain_config'] = $domain_config;
define('CURRENT_RX_CE',   $domain_config['rx_ce']);
define('EXCHANGE_NAME',   $domain_config['exchange_name']);
define('SITE_NAME',       $domain_config['site_name']);
define('SITE_LOGO_URL',   $domain_config['logo_url']);
define('SITE_COLOR',      $domain_config['theme_color']);

// EXCHANGE_MAP 정의 (대시보드 파일에서 참조)
if (!defined('EXCHANGE_MAP')) {
    define('EXCHANGE_MAP', [
        1 => 'Toobit',
        2 => 'Gate.io',
        3 => 'WebSea',
        4 => 'Deepcoin',
    ]);
}

// ============================================================
// 5. 대시보드 파일 존재 여부 확인 후 include
// ============================================================
$dashboard_path = __DIR__ . '/' . $dashboard_file;

if (!file_exists($dashboard_path)) {
    // 해당 거래소 대시보드가 없으면 기본 Toobit 대시보드로 폴백
    error_log("[index.php] 대시보드 파일 없음: {$dashboard_path} → dashboard.php로 폴백");
    $dashboard_path = __DIR__ . '/dashboard.php';
}

include $dashboard_path;
