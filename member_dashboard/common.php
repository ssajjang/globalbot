<?php
/**
 * common.php - 58번 서버 공통 초기화 파일
 *
 * [역할]
 * - config.php 로드 (DB 설정, Node.js 주소 등)
 * - DB 연결 (mysqli 싱글톤)
 * - 세션 시작
 * - 로그인 체크 함수
 * - Node.js 봇 서버 HTTP 통신 함수 (Redis 없이 직접 통신)
 * - HMAC 보안 검증 함수 (147번 Push 수신 시)
 *
 * 업로드 경로: /home/killer_pro/common.php
 * 모든 PHP 파일 첫 줄에 require_once(__DIR__.'/common.php'); 포함
 */

require_once __DIR__ . '/config.php';

// ============================================================
// DB 연결 싱글톤 (한 번만 연결 후 재사용)
// ============================================================
function get_db_connection(): mysqli {
    static $conn = null;

    if ($conn === null || !$conn->ping()) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

        if ($conn->connect_error) {
            // DB 연결 실패 로그 기록
            error_log('[58번 DB 연결 실패] ' . $conn->connect_error, 3, LOG_PATH . '/php_error.log');
            http_response_code(500);
            die('<div style="color:red;padding:20px;">데이터베이스 연결에 실패했습니다. 관리자에게 문의하세요.</div>');
        }

        $conn->set_charset(DB_CHARSET); // 한글/이모지 완전 지원
    }

    return $conn;
}

// ============================================================
// 세션 시작
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFE,
        'secure'   => false,    // HTTPS 사용 시 true로 변경
        'httponly' => true,     // XSS 방지
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ============================================================
// 로그인 관련 함수
// ============================================================

/**
 * 로그인 여부 확인
 */
function is_logged_in(): bool {
    return isset($_SESSION['rx_uid']) && !empty($_SESSION['rx_uid']);
}

/**
 * 로그인 강제 체크 (미로그인 시 login.php로 이동)
 */
function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * 현재 로그인된 회원 정보 조회
 */
function get_current_member(): ?array {
    if (!is_logged_in()) return null;

    $conn = get_db_connection();
    $uid  = $conn->real_escape_string($_SESSION['rx_uid']);
    $ce   = intval($_SESSION['rx_ce'] ?? 1);

    $sql    = "SELECT * FROM rx_member WHERE rx_uid = '{$uid}' AND rx_ce = {$ce} LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return null;
}

/**
 * 초기 패스워드 여부 확인 (UID와 패스워드가 동일한지)
 */
function is_initial_password(array $member): bool {
    if (empty($member['rx_uid_password'])) return false;
    return password_verify($member['rx_uid'], $member['rx_uid_password']);
}

// ============================================================
// Node.js 봇 서버 직접 통신 함수 (Redis 없이 HTTP 직접 전송)
// ============================================================

/**
 * 거래소 코드(rx_ce)로 Node.js 서버 URL 반환
 */
function get_node_url(int $rx_ce): string {
    $map = [
        1 => NODE_TOOBIT_URL,
        2 => NODE_GATEIO_URL,
        3 => NODE_WEBSEA_URL,
        4 => NODE_DEEPCOIN_URL,
    ];
    return $map[$rx_ce] ?? NODE_TOOBIT_URL;
}

/**
 * Node.js 봇 서버로 HTTP POST 전송
 *
 * @param  int    $rx_ce   거래소 코드
 * @param  string $path    API 경로 (예: /bot/start)
 * @param  array  $data    전송 데이터
 * @param  int    $timeout 타임아웃 초 (기본 10초)
 * @return array           ['success'=>bool, 'data'=>mixed, 'error'=>string]
 */
function call_node_api(int $rx_ce, string $path, array $data = [], int $timeout = 10): array {
    $node_url = get_node_url($rx_ce);
    $url      = rtrim($node_url, '/') . '/' . ltrim($path, '/');

    // 봇 서버 인증 키 포함
    $data['api_key'] = NODE_API_KEY;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'X-Api-Key: ' . NODE_API_KEY,
        ],
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        error_log("[Node.js 통신 오류] {$url} - {$curl_err}", 3, LOG_PATH . '/php_error.log');
        return ['success' => false, 'data' => null, 'error' => $curl_err];
    }

    $result = json_decode($response, true);
    return [
        'success'   => ($http_code >= 200 && $http_code < 300),
        'data'      => $result,
        'http_code' => $http_code,
        'error'     => '',
    ];
}

// ============================================================
// HMAC 보안 검증 (147번 서버 Push 수신 시 사용)
// ============================================================

/**
 * 147번 서버에서 오는 요청의 HMAC 서명 검증
 */
function verify_hmac_from_147(string $endpoint, string $raw_body): bool {
    $timestamp = intval($_SERVER['HTTP_X_TIMESTAMP'] ?? 0);
    $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

    // 5분 이상 지난 요청 거부 (리플레이 공격 방지)
    if (abs(time() - $timestamp) > HMAC_TOLERANCE) {
        error_log("[HMAC] 타임스탬프 만료: {$timestamp}", 3, LOG_PATH . '/php_error.log');
        return false;
    }

    $sign_string  = $timestamp . '|' . $endpoint . '|' . $raw_body;
    $expected_sig = hash_hmac('sha256', $sign_string, SHARED_HMAC_KEY);

    return hash_equals($expected_sig, $signature);
}

/**
 * 147번 서버 IP 화이트리스트 확인
 */
function check_allowed_ip(): bool {
    $client_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $allowed   = [ALLOWED_147_IP, '127.0.0.1', '::1'];
    return in_array($client_ip, $allowed, true);
}

// ============================================================
// 공통 유틸리티 함수
// ============================================================

/**
 * HTML 특수문자 이스케이프 (XSS 방지)
 */
function h(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * JSON 응답 출력 후 종료
 */
function json_response(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * API 응답 형식 출력 후 종료
 */
function api_response(bool $success, string $message, array $extra = []): void {
    json_response(array_merge(['success' => $success, 'message' => $message], $extra));
}

/**
 * 리다이렉트 함수 (헤더 전송 여부 자동 감지)
 */
function redirect_to(string $url): void {
    if (!headers_sent()) {
        header('Location: ' . $url);
    } else {
        echo "<script>window.location.replace('" . addslashes($url) . "');</script>";
    }
    exit;
}

/**
 * 숫자 절사 + 콤마 포맷 (손익 표시용)
 */
function format_truncate($num, int $decimals): string {
    $isNeg  = $num < 0;
    $numStr = sprintf('%.10f', abs((float)$num));

    if (($pos = strpos($numStr, '.')) !== false) {
        $res = ($decimals === 0)
            ? substr($numStr, 0, $pos)
            : rtrim(substr($numStr, 0, $pos + 1 + $decimals), '.');
    } else {
        $res = $numStr;
    }

    $parts    = explode('.', $res);
    $parts[0] = number_format((float)$parts[0]);
    $final    = implode('.', $parts);

    return ($isNeg && (float)$res != 0 ? '-' : '') . $final;
}

/**
 * 거래소 이름 반환
 */
function get_exchange_name(int $rx_ce): string {
    $map = unserialize(EXCHANGE_MAP);
    return $map[$rx_ce] ?? 'Unknown';
}
