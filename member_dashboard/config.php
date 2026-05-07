<?php
/**
 * config.php - 회원 대시보드 전용 설정 (147번 통합 서버)
 *
 * [중요] 이 파일은 member_dashboard/ 폴더 전용입니다.
 * DB 연결 정보만 147번 통합 서버의 DB를 사용합니다.
 * 나머지 설정은 기존 58번 서버와 동일하게 유지합니다.
 *
 * [주의] 이 폴더의 dashboard*.php 파일은 절대 수정하지 마세요!
 */

// ============================================================
// 시간대 설정
// ============================================================
date_default_timezone_set('Asia/Seoul');

// 오류 표시 설정 (운영 중에는 0 유지)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ============================================================
// 📌 DB 접속 정보 — 147번 통합 서버 DB 사용
// ============================================================
define('DB_HOST',    '127.0.0.1');          // DB 서버 주소
define('DB_PORT',    3306);                  // DB 포트
define('DB_NAME',    'global_admin_db');     // 통합 DB 이름 (147번과 동일)
define('DB_USER',    'root');               // DB 계정 (운영 시 변경)
define('DB_PASS',    '');                    // DB 비밀번호 (운영 시 변경)
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// 📌 Node.js 봇 서버 주소
// ============================================================
define('NODE_TOOBIT_URL',   'http://211.115.65.88:3010'); // Toobit 봇 서버
define('NODE_DEEPCOIN_URL', 'http://211.115.65.88:3011'); // Deepcoin 봇 서버
define('NODE_WEBSEA_URL',   'http://211.115.65.88:3012'); // Websea 봇 서버
define('NODE_GATEIO_URL',   'http://211.115.65.88:3013'); // Gate.io 봇 서버
define('NODE_API_KEY',      '309A574C11146F107B039D42AF0FFA2733141049B71F8D59BD9499B5AAE78B71');

// ============================================================
// 보안/세션 설정
// ============================================================
define('HMAC_TOLERANCE', 300);
define('SESSION_NAME',   'rx_session');   // 관리자 세션과 다른 이름 (충돌 방지)
define('SESSION_LIFE',   3600);

// ============================================================
// 거래소 코드 매핑
// ============================================================
define('EXCHANGE_MAP', serialize([
    1 => 'Toobit',
    2 => 'Gate.io',
    3 => 'WebSea',
    4 => 'Deepcoin',
]));

// ============================================================
// 147번 연동 (통합 서버이므로 자기 자신)
// ============================================================
$http_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
define('GLOBAL_ADMIN_URL', $protocol . '://' . $http_host);
define('SHARED_HMAC_KEY',  'internal_same_server');
define('ALLOWED_147_IP',   '127.0.0.1');

// ============================================================
// 서버 경로 상수
// ============================================================
define('BASE_PATH', __DIR__);
define('LOG_PATH',  __DIR__ . '/logs');
if (!is_dir(LOG_PATH)) @mkdir(LOG_PATH, 0755, true);
