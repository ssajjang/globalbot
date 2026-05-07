<?php
/**
 * auth_check.php - 인증 및 권한 체크 공통 모듈 (v2 - 버그 수정판)
 *
 * [수정된 오류]
 * 1. check_csrf_on_post(): AJAX JSON body에서도 CSRF 토큰 읽을 수 있도록 수정
 * 2. require_super_admin(): AJAX 요청 시 JSON 반환, 일반 요청 시 403 페이지 분기
 * 3. has_menu_permission(): 서브관리자 메뉴 권한 체크 함수 추가
 */

// ============================================================
// admin_accounts.admin_role에 'sub' 역할 추가 지원
// ENUM('super','center','sub') — DB에서 ALTER 필요
// ============================================================

// ============================================================
// 세션 시작 (아직 시작 안 된 경우만)
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,    // JS 쿠키 접근 차단 (XSS 방지)
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ============================================================
// 로그인 여부 확인
// ============================================================
function is_logged_in(): bool {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

// ============================================================
// 역할 확인 함수들
// ============================================================
function is_super_admin(): bool {
    return is_logged_in() && ($_SESSION['admin_role'] ?? '') === 'super';
}

function is_center_admin(): bool {
    return is_logged_in() && ($_SESSION['admin_role'] ?? '') === 'center';
}

function is_sub_admin(): bool {
    // 서브관리자: super 또는 center가 아닌 역할
    return is_logged_in() && !in_array($_SESSION['admin_role'] ?? '', ['super', 'center']);
}

// ============================================================
// 메뉴 권한 체크 (서브관리자용)
// 총어드민/센터어드민은 모든 메뉴 접근 가능
// ============================================================
function has_menu_permission(string $menu_key): bool {
    if (!is_logged_in()) return false;
    // 총어드민은 모든 메뉴 허용
    if (is_super_admin()) return true;
    // 센터어드민도 할당된 범위 내 모든 메뉴 허용
    if (is_center_admin()) return true;
    // 서브관리자: 세션에 저장된 허용 메뉴 배열에서 체크
    $allowed = $_SESSION['admin_permissions'] ?? [];
    return in_array($menu_key, $allowed);
}

// ============================================================
// 로그인 강제 체크
// ============================================================
function require_login(string $redirect = '/login.php'): void {
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . BASE_URL . $redirect);
        exit;
    }

    // 세션 타임아웃 체크
    if (isset($_SESSION['last_activity'])) {
        if ((time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
            session_destroy();
            // AJAX 요청이면 JSON 반환
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                die(json_encode(['success' => false, 'message' => '세션이 만료되었습니다.', 'redirect' => BASE_URL . '/login.php?timeout=1']));
            }
            header('Location: ' . BASE_URL . $redirect . '?timeout=1');
            exit;
        }
    }
    // 마지막 활동 시간 갱신
    $_SESSION['last_activity'] = time();
}

// ============================================================
// 총어드민/서브어드민 권한 강제 체크
// [버그수정] AJAX 요청 시 JSON 반환으로 변경
// ============================================================
function require_super_admin(): void {
    require_login();
    if (!is_super_admin()) {
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
                   (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);
        if ($is_ajax) {
            http_response_code(403);
            header('Content-Type: application/json');
            die(json_encode(['success' => false, 'message' => '총어드민 권한이 필요합니다.']));
        }
        http_response_code(403);
        include __DIR__ . '/error_403.php';
        exit;
    }
}

// ============================================================
// IP 화이트리스트 체크 (총어드민 관리 페이지 접근 제어)
// [정책] 센터대시보드, 회원대시보드는 IP 체크 안 함
// ============================================================
function check_admin_ip(): bool {
    global $WHITE_IP_LIST;
    // 접속자 실제 IP 추출 (Cloudflare/프록시 대응)
    $client_ip = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '';
    // 쉼표로 여러 IP가 올 수 있음 (첫번째가 실제 IP)
    if (strpos($client_ip, ',') !== false) {
        $client_ip = trim(explode(',', $client_ip)[0]);
    }

    // 1단계: config.php 하드코딩 IP 체크
    if (in_array($client_ip, $WHITE_IP_LIST)) return true;

    // 2단계: DB의 white_ip_list 테이블에서 추가 IP 체크
    try {
        $db_count = db_scalar(
            "SELECT COUNT(*) FROM white_ip_list WHERE ip_address=? AND is_active=1",
            [$client_ip]
        );
        if ($db_count > 0) return true;
    } catch (Exception $e) {
        // 테이블이 없거나 DB 오류 시 config IP만 체크
        error_log('[IP체크] DB 조회 실패: ' . $e->getMessage());
    }

    return false;
}

// 현재 접속자 IP 반환
function get_client_ip(): string {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '';
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    return $ip;
}

// 총어드민 페이지 접근 시 IP 체크 강제 (로그인 후 호출)
function require_admin_ip(): void {
    if (!check_admin_ip()) {
        $ip = get_client_ip();
        error_log('[IP 차단] 관리자 페이지 접근 거부 IP: ' . $ip);
        http_response_code(403);
        die('<div style="color:#FF3B5C;padding:60px;font-family:sans-serif;text-align:center">' .
            '<h2>⛔ 접근이 차단되었습니다</h2>' .
            '<p style="color:#999;margin-top:16px">허용되지 않은 IP (' . htmlspecialchars($ip) . ')에서의 접근입니다.</p>' .
            '<p style="color:#666;margin-top:8px;font-size:0.85rem">총어드민에게 IP 화이트리스트 등록을 요청하세요.</p>' .
            '</div>');
    }
}

// ============================================================
// 메뉴 권한 강제 체크 (서브관리자 접근 제어)
// ============================================================
function require_menu_permission(string $menu_key): void {
    require_login();
    if (!has_menu_permission($menu_key)) {
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
        if ($is_ajax) {
            http_response_code(403);
            die(json_encode(['success' => false, 'message' => '해당 메뉴에 대한 권한이 없습니다.']));
        }
        http_response_code(403);
        die('<div style="color:red;padding:40px;font-family:sans-serif"><h2>접근 권한 없음</h2><p>이 메뉴에 접근할 권한이 없습니다.</p><a href="javascript:history.back()">뒤로가기</a></div>');
    }
}

// ============================================================
// CSRF 토큰 생성
// ============================================================
function generate_csrf_token(): string {
    if (empty($_SESSION[CSRF_TOKEN_KEY])) {
        $_SESSION[CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_KEY];
}

// ============================================================
// CSRF 토큰 검증
// ============================================================
function verify_csrf_token(string $token): bool {
    return isset($_SESSION[CSRF_TOKEN_KEY]) && hash_equals($_SESSION[CSRF_TOKEN_KEY], $token);
}

// ============================================================
// CSRF 토큰 HTML 필드 출력
// ============================================================
function csrf_field(): string {
    $token = generate_csrf_token();
    return '<input type="hidden" name="' . CSRF_TOKEN_KEY . '" value="' . htmlspecialchars($token) . '">';
}

// ============================================================
// [버그수정] CSRF 토큰 검증 - AJAX JSON body + 일반 POST 양쪽 지원
// 기존 문제: AJAX는 JSON으로 전송하므로 $_POST에 토큰이 없어 항상 실패
// ============================================================
function check_csrf_on_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

    $token = '';

    // 1순위: 일반 form POST의 $_POST에서 읽기
    if (!empty($_POST[CSRF_TOKEN_KEY])) {
        $token = $_POST[CSRF_TOKEN_KEY];
    }
    // 2순위: AJAX JSON body에서 읽기
    else {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $body = json_decode($raw, true);
            $token = $body[CSRF_TOKEN_KEY] ?? '';
            // JSON body를 파싱한 데이터를 $GLOBALS에 저장 (이후 코드에서 사용)
            if (is_array($body)) {
                $GLOBALS['_JSON_BODY'] = $body;
            }
        }
    }
    // 3순위: X-CSRF-Token 헤더
    if (!$token) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }

    if (!verify_csrf_token($token)) {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'CSRF 토큰이 유효하지 않습니다. 페이지를 새로고침하세요.']));
    }
}

// ============================================================
// POST 입력값 안전 읽기 (JSON body 또는 $_POST 자동 분기)
// ============================================================
function post_input(string $key, $default = '') {
    // JSON body 파싱 결과가 있으면 우선 사용
    if (isset($GLOBALS['_JSON_BODY'][$key])) {
        return $GLOBALS['_JSON_BODY'][$key];
    }
    return $_POST[$key] ?? $default;
}

// ============================================================
// XSS 방지 출력 함수
// ============================================================
function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// ============================================================
// 현재 관리자 CENTER ID 반환
// ============================================================
function get_admin_center(): ?string {
    return $_SESSION['admin_center'] ?? null;
}

// ============================================================
// AES-256-CBC 암호화 (선택적 사용 - API Key에는 적용 안 함)
// [주의] API Key / Secret Key / Passphrase는 평문 저장 정책으로 평문 저장됨.
//          필요 시 비밀번호 등 다른 민감정보 암호화에만 사용할 것.
// ============================================================
function encrypt_api_key(string $plainText): string {
    if (empty($plainText)) return '';
    $iv        = random_bytes(16); // 랜덤 IV 생성
    $encrypted = openssl_encrypt($plainText, 'AES-256-CBC', ENCRYPT_KEY, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted); // IV + 암호문을 합쳐 저장
}

// ============================================================
// AES-256-CBC 복호화 (선택적 사용 - API Key에는 적용 안 함)
// ============================================================
function decrypt_api_key(string $encryptedText): string {
    if (empty($encryptedText)) return '';
    try {
        $decoded   = base64_decode($encryptedText);
        if (strlen($decoded) < 17) return ''; // 최소 IV(16)+1바이트
        $iv        = substr($decoded, 0, 16);
        $encrypted = substr($decoded, 16);
        $result    = openssl_decrypt($encrypted, 'AES-256-CBC', ENCRYPT_KEY, OPENSSL_RAW_DATA, $iv);
        return $result !== false ? $result : '';
    } catch (Exception $e) {
        error_log('[복호화 실패] ' . $e->getMessage());
        return '';
    }
}

// ============================================================
// 감사 로그 기록 함수
// ============================================================
function write_audit_log(string $action, string $target_id = '', array $before = [], array $after = []): void {
    try {
        db_execute(
            "INSERT INTO admin_audit_log
             (admin_id, admin_role, action, target_id, before_data, after_data, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $_SESSION['admin_id']   ?? 'unknown',
                $_SESSION['admin_role'] ?? 'unknown',
                $action,
                $target_id,
                json_encode($before, JSON_UNESCAPED_UNICODE),
                json_encode($after,  JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? '',
            ]
        );
    } catch (Exception $e) {
        error_log('[감사 로그 기록 실패] ' . $e->getMessage());
    }
}

// ============================================================
// JSON 응답 출력 후 종료
// ============================================================
function json_response(bool $success, string $message = '', array $data = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// Node.js 서버 공통 호출 함수 (중복 정의 방지 전역화)
// bot_setting, bot_process, admin_bot, bot_control 모두 이 함수 사용
// ============================================================
function call_node_api(string $node_url, string $endpoint, array $payload, int $timeout = 8): array {
    if (empty($node_url)) return ['code' => 0, 'body' => '', 'success' => false];
    $ch = curl_init(rtrim($node_url, '/') . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'code'    => $code,
        'body'    => $body,
        'success' => ($code === 200 && !$err),
        'error'   => $err,
    ];
}

// ============================================================
// 모든 Node.js 서버에 브로드캐스트
// ============================================================
function call_all_node_servers(string $endpoint, array $payload, int $timeout = 8): array {
    global $NODE_SERVERS;
    $results = [];
    foreach ($NODE_SERVERS as $key => $url) {
        $results[$key] = call_node_api($url, $endpoint, $payload, $timeout);
    }
    return $results;
}

// ============================================================
// 거래소 키로 Node.js 서버 1개 호출
// ============================================================
function call_node_by_exchange_key(int $rx_ce, string $endpoint, array $payload): array {
    global $NODE_SERVERS, $EXCHANGE_LIST;
    $ex_key  = $EXCHANGE_LIST[$rx_ce]['key'] ?? 'toobit';
    $node_url = $NODE_SERVERS[$ex_key] ?? '';
    return call_node_api($node_url, $endpoint, $payload);
}

// ============================================================
// 숫자 포맷 (서버 사이드)
// ============================================================
function num_fmt($value, int $decimals = 0): string {
    if ($value === null || $value === '') return '-';
    return number_format((float)$value, $decimals);
}
