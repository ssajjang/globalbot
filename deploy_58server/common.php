<?php
/**
 * common.php - 58번 대시보드 서버 공통 초기화
 *
 * [역할]
 * - config.php 로드 (DB 설정, 키 설정)
 * - mysqli DB 연결 싱글톤 함수 제공: get_db_connection()
 * - 공통 보안 헬퍼 함수 제공
 *
 * 58번 서버 모든 PHP 파일에서 맨 처음 include 합니다.
 * 배포 경로: /home/killer_pro/common.php
 */

// ── config.php 로드 (같은 폴더에 위치)
require_once __DIR__ . '/config.php';

// ============================================================
// DB 연결 싱글톤 (mysqli 방식 - 기존 58번 레거시 호환)
// ============================================================
function get_db_connection(): mysqli {
    static $conn = null; // 한 번만 연결 후 재사용

    if ($conn === null || $conn->ping() === false) {
        $conn = new mysqli(
            DB58_HOST,
            DB58_USER,
            DB58_PASS,
            DB58_NAME,
            DB58_PORT
        );

        if ($conn->connect_error) {
            // DB 연결 실패 시 로그 기록 후 에러 응답
            error_log('[58번 DB 연결 실패] ' . $conn->connect_error,
                3, '/home/killer_pro/logs/php_error.log');
            http_response_code(500);
            die(json_encode(['success' => false, 'message' => 'DB 연결 실패']));
        }

        // UTF-8 인코딩 강제 설정 (이모지, 한글 완전 지원)
        $conn->set_charset(DB58_CHARSET);
    }

    return $conn;
}

// ============================================================
// HMAC-SHA256 서명 검증 공통 함수
// (147번 서버에서 오는 Push 요청 검증에 사용)
// ============================================================
function verify_hmac_from_147(string $endpoint, string $raw_body): bool {
    $timestamp = intval($_SERVER['HTTP_X_TIMESTAMP'] ?? 0);
    $signature = $_SERVER['HTTP_X_SIGNATURE']  ?? '';

    // 타임스탬프 만료 체크 (5분 이상 지난 요청 거부)
    if (abs(time() - $timestamp) > HMAC_TIMESTAMP_TOLERANCE) {
        error_log("[HMAC] 타임스탬프 만료: {$timestamp}");
        return false;
    }

    // 서명 검증 (147번 Server58Service::request()와 동일한 방식)
    $sign_string  = $timestamp . '|' . $endpoint . '|' . $raw_body;
    $expected_sig = hash_hmac('sha256', $sign_string, SHARED_HMAC_KEY);

    return hash_equals($expected_sig, $signature);
}

// ============================================================
// IP 화이트리스트 체크 (147번 서버만 수신 API 접근 허용)
// ============================================================
function check_allowed_ip(): bool {
    $client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

    // 개발/테스트 환경은 127.0.0.1 허용
    $allowed = [ALLOWED_ADMIN_IP, '127.0.0.1', '::1'];

    if (!in_array($client_ip, $allowed)) {
        error_log("[IP 차단] 허용되지 않은 IP: {$client_ip}");
        return false;
    }
    return true;
}

// ============================================================
// POST만 허용하는 API 공통 검증
// ============================================================
function require_post_method(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        die(json_encode(['success' => false, 'message' => 'POST 요청만 허용']));
    }
}

// ============================================================
// JSON 응답 후 종료
// ============================================================
function api_response(bool $success, string $message, array $data = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $data),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// ============================================================
// 세션 시작 (대시보드 회원 로그인용)
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'secure'   => false,   // HTTPS 환경에서는 true
        'httponly' => true,    // XSS 방지
        'samesite' => 'Lax',
    ]);
    session_start();
}
