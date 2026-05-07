<?php
/**
 * bot_api.php - Node.js → PHP 이벤트 수신 API
 * Node.js 서버에서 발생한 이벤트를 받아 DB 큐에 저장
 * 화이트 IP + API Key 이중 인증
 */

// ============================================================
// 공통 초기화 (세션 불필요)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

// ============================================================
// 보안 1: 화이트 IP 체크
// ============================================================
$allowed_ips = [
    '211.115.65.88',  // Node.js 봇 서버 IP
    '127.0.0.1',      // 로컬 (개발/테스트용)
    '::1',
];

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($client_ip, $allowed_ips)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => '접근 불가 IP입니다.']));
}

// ============================================================
// 보안 2: API Key 헤더 검증
// ============================================================
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
define('BOT_API_KEY', 'CHANGE_THIS_BOT_API_SECRET_KEY'); // Node.js와 공유하는 시크릿

if (!hash_equals(BOT_API_KEY, $api_key)) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'API Key가 유효하지 않습니다.']));
}

// ============================================================
// POST JSON 파싱
// ============================================================
$raw   = file_get_contents('php://input');
$event = json_decode($raw, true);

if (!$event || !isset($event['type'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => '유효하지 않은 요청 형식입니다.']));
}

// ============================================================
// DB 큐에 이벤트 저장 (telegram_consumer.php가 처리)
// ============================================================
try {
    // 이벤트 타임스탬프 추가 (없는 경우)
    $event['received_at'] = microtime(true);

    $event_json = json_encode($event, JSON_UNESCAPED_UNICODE);

    db_execute(
        "INSERT INTO telegram_log (chat_id, message, event_type, target_uid, status, created_at)
         VALUES ('', ?, ?, ?, 'pending', NOW())",
        [$event_json, $event['type'], $event['uid'] ?? '']
    );

    // 응답
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => '이벤트가 큐에 등록되었습니다.']);

} catch (Exception $e) {
    error_log('[Bot API DB 오류] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB 오류가 발생했습니다.']);
}
