<?php
/**
 * config.php - 58번 대시보드 서버 전용 설정
 *
 * [중요] 이 파일은 58번 서버(대시보드 서버)에만 배포합니다.
 * 147번 서버의 config.php와 완전히 별개의 파일입니다.
 *
 * 배포 경로: /home/killer_pro/config.php  (58번 서버 웹루트)
 */

// ============================================================
// 시간대 설정
// ============================================================
date_default_timezone_set('Asia/Seoul');

// ============================================================
// 58번 서버 DB 접속 정보 (반드시 실제 값으로 교체)
// ============================================================
define('DB58_HOST',    '127.0.0.1');       // 58번 서버 DB 주소
define('DB58_PORT',    3306);              // DB 포트
define('DB58_NAME',    'CHANGE_58_DBNAME'); // 58번 서버 DB명 (예: rx_dashboard)
define('DB58_USER',    'CHANGE_58_DBUSER'); // DB 계정 (예: rx_user)
define('DB58_PASS',    'CHANGE_58_DBPASS'); // DB 비밀번호
define('DB58_CHARSET', 'utf8mb4');

// ============================================================
// 147번 글로벌어드민 연동 설정
// ============================================================
// 147번 서버의 API 엔드포인트 (회원 정보 동기화 대상)
define('GLOBAL_ADMIN_API_URL',
    'https://CHANGE_147_DOMAIN/api/signup_api_dashboard.php'
);

// HMAC 서명 공유 키 (147번 config.php의 SERVER_58_KEY와 완전히 동일해야 함)
// 생성법: php -r "echo bin2hex(random_bytes(32));"
define('SHARED_HMAC_KEY', 'CHANGE_THIS_TO_SAME_KEY_AS_147_SERVER_58_KEY');

// ============================================================
// 보안 설정
// ============================================================
// 147번 서버 IP (이 IP에서 오는 요청만 수신 API 허용)
define('ALLOWED_ADMIN_IP', 'CHANGE_147_SERVER_IP'); // 예: '211.115.65.147'

// API 타임스탬프 허용 오차 (초) - 리플레이 공격 방지
define('HMAC_TIMESTAMP_TOLERANCE', 300); // 5분

// ============================================================
// 세션 설정
// ============================================================
define('SESSION_NAME',     'rx_session');
define('SESSION_LIFETIME', 3600); // 1시간

// ============================================================
// 오류 표시 (운영 시 0으로 변경)
// ============================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/killer_pro/logs/php_error.log'); // Apache 로그 경로
error_reporting(E_ALL);

// ============================================================
// 서버 경로 상수 (코드에서 경로 참조용)
// ============================================================
define('BASE_PATH',  '/home/killer_pro');       // 58번 서버 웹루트
define('LOG_PATH',   '/home/killer_pro/logs');  // 로그 저장 디렉토리

