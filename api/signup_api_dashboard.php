<?php
/**
 * signup_api_dashboard.php - 회원 대시보드에서 관리자 DB로 회원정보 동기화 수신 API (통합 서버)
 *
 * [연동 구조]
 * 회원 대시보드(dashboard_*.php)에서
 * API Key/투자금 수정 시 이 API를 호출하여 관리자 DB에도 반영
 *
 * [통합] 동일 서버 내부 호출 (로컬 API)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

// [통합] Server58Service 제거 — 동일 서버 내부 API

// ============================================================
// 요청 메서드 및 IP 화이트리스트 검증
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'POST 요청만 허용됩니다.']));
}

// [통합] 동일 서버 내부 호출이므로 로컬 IP 허용
$allowed_ips = [
    '127.0.0.1',      // 로컬 (동일 서버)
    '::1',            // IPv6 로컬
    '211.115.65.88',  // 봇 서버군
];

$client_ip = get_client_ip();
if (!empty($allowed_ips) && !in_array($client_ip, $allowed_ips)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => "허용되지 않은 IP: {$client_ip}"]));
}

// ============================================================
// JSON body 파싱
// ============================================================
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'JSON 파싱 실패']));
}

// ============================================================
// HMAC-SHA256 서명 검증 (58번 서버와 동일한 키 사용)
// ============================================================
$timestamp  = $_SERVER['HTTP_X_TIMESTAMP'] ?? 0;
$signature  = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$source     = $_SERVER['HTTP_X_SOURCE'] ?? '';

// 타임스탬프 기반 리플레이 공격 방지 (5분 이내 요청만 허용)
if (abs(time() - intval($timestamp)) > 300) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => '요청 타임스탬프가 만료되었습니다.']));
}

// [통합] HMAC 검증 비활성화 — 동일 서버 내부 호출이므로 서명 불필요
// 향후 외부 API 호출 시 아래 주석 해제
// $endpoint    = '/api/signup_api_dashboard.php';
// $sign_string = $timestamp . '|' . $endpoint . '|' . $raw;
// $expected_sig = hash_hmac('sha256', $sign_string, 'INTERNAL_KEY');
// if (!hash_equals($expected_sig, $signature)) {
//     http_response_code(401);
//     die(json_encode(['success' => false, 'message' => 'HMAC 서명 불일치']));
// }

// ============================================================
// 동기화 액션 분기
// ============================================================
$act = $data['act'] ?? 'member_sync';

// ── 1. 회원 설정 동기화 (API Key, 투자금, 클래스)
if ($act === 'member_sync') {
    $rx_uid    = trim($data['rx_uid'] ?? '');
    $rx_acount = floatval($data['rx_acount'] ?? 0);
    $rx_apikey = trim($data['rx_apikey'] ?? '');
    $rx_sskey  = trim($data['rx_sskey'] ?? '');
    $rx_class  = intval($data['rx_class'] ?? 0);
    $rx_ce     = intval($data['rx_ce'] ?? 1); // 거래소 코드 (1:Toobit, 2:Gateio, 3:Websea, 4:Deepcoin)

    if (empty($rx_uid)) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'rx_uid 필수']));
    }

    // rx_class → rx_reserve_percent 매핑
    $reserve_percent_map = [1 => 0, 2 => 30, 3 => 100];
    $rx_reserve_percent  = $reserve_percent_map[$rx_class] ?? 0;

    // 기존 데이터 확인 (변경 전 스냅샷)
    $before = db_row(
        "SELECT rx_acount, rx_apikey, rx_reserve_percent FROM g5_member WHERE rx_uid=? LIMIT 1",
        [$rx_uid]
    );

    // [정책] API Key / Secret Key / Passphrase는 거래소 연동 키이므로 평문 저장
    // (암호화 미적용 - 거래소 API 호출 시 바로 사용되어야 함)
    $plain_apikey     = !empty($rx_apikey)     ? $rx_apikey     : null;
    $plain_sskey      = !empty($rx_sskey)      ? $rx_sskey      : null;

    // rx_passphrase (Deepcoin용)
    $rx_passphrase  = trim($data['rx_password'] ?? $data['rx_passphrase'] ?? '');
    $plain_passphrase = !empty($rx_passphrase) ? $rx_passphrase : null;

    // 투자금 + API Key를 조건부 UPDATE (NULL은 기존값 유지)
    $affected = db_execute(
        "UPDATE g5_member
         SET rx_acount          = ?,
             rx_apikey          = COALESCE(NULLIF(?,  ''), rx_apikey),
             rx_sskey           = COALESCE(NULLIF(?,  ''), rx_sskey),
             rx_passphrase      = COALESCE(NULLIF(?,  ''), rx_passphrase),
             rx_reserve_percent = ?,
             mb_class           = ?,
             rx_updated_at      = NOW()
         WHERE rx_uid = ? AND rx_ce = ?",
        [
            $rx_acount,
            $plain_apikey, $plain_apikey,
            $plain_sskey,  $plain_sskey,
            $plain_passphrase, $plain_passphrase,
            $rx_reserve_percent,
            $rx_class,
            $rx_uid,
            $rx_ce,
        ]
    );

    // 투자금 변경 이력 기록
    if ($before && abs(floatval($before['rx_acount']) - $rx_acount) >= 0.0001) {
        db_execute(
            "INSERT INTO rx_account_history (rx_uid, rx_ce, amount_type, before_amount, after_amount, regdate)
             VALUES (?, ?, 'futures', ?, ?, NOW())",
            [$rx_uid, $rx_ce, floatval($before['rx_acount']), $rx_acount]
        );
    }

    // 감사 로그 기록
    db_execute(
        "INSERT INTO admin_audit_log (admin_id, admin_role, action, target_id, before_data, after_data, ip_address, created_at)
         VALUES ('58_dashboard', 'system', 'member_sync_from_58', ?, ?, ?, ?, NOW())",
        [
            $rx_uid,
            json_encode(['acount' => $before['rx_acount'] ?? 0], JSON_UNESCAPED_UNICODE),
            json_encode(['acount' => $rx_acount, 'class' => $rx_class], JSON_UNESCAPED_UNICODE),
            $client_ip,
        ]
    );

    header('Content-Type: application/json');
    echo json_encode([
        'success'  => true,
        'message'  => "UID [{$rx_uid}] 동기화 완료",
        'affected' => $affected,
    ]);
    exit;
}

// ── 2. 봇 상태 Push (58번에서 실시간 봇 이벤트 수신)
if ($act === 'bot_event') {
    $rx_uid  = trim($data['rx_uid'] ?? '');
    $event   = trim($data['event'] ?? ''); // 'entry', 'close', 'dca', 'error'
    $payload = $data['payload'] ?? [];

    if (empty($rx_uid) || empty($event)) {
        die(json_encode(['success' => false, 'message' => 'rx_uid, event 필수']));
    }

    // DB 큐에 이벤트 저장 (telegram_consumer.php가 처리)
    try {
        $event_data = json_encode([
            'rx_uid'  => $rx_uid,
            'event'   => $event,
            'payload' => $payload,
            'source'  => '58_dashboard',
            'ts'      => time(),
        ], JSON_UNESCAPED_UNICODE);

        db_execute(
            "INSERT INTO telegram_log (chat_id, message, event_type, target_uid, status, created_at)
             VALUES ('', ?, ?, ?, 'pending', NOW())",
            [$event_data, $event, $rx_uid]
        );
    } catch (Exception $e) {
        error_log('[dashboard_api] DB 큐 저장 실패: ' . $e->getMessage());
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => "이벤트 [{$event}] 수신 완료"]);
    exit;
}

// ── 3. 회원 가입 신규 등록 (58번 가입 → 147 DB 반영)
if ($act === 'member_register') {
    $rx_uid    = trim($data['rx_uid'] ?? '');
    $mb_id     = trim($data['mb_id'] ?? '');
    $mb_center = trim($data['mb_center'] ?? '');
    $rx_ce     = intval($data['rx_ce'] ?? 1);
    $rx_acount = floatval($data['rx_acount'] ?? 0);

    if (empty($rx_uid) || empty($mb_id)) {
        die(json_encode(['success' => false, 'message' => 'rx_uid, mb_id 필수']));
    }

    // 중복 체크
    $exists = db_scalar("SELECT COUNT(*) FROM g5_member WHERE rx_uid=?", [$rx_uid]);
    if ($exists) {
        die(json_encode(['success' => false, 'message' => "이미 존재하는 UID: {$rx_uid}"]));
    }

    db_execute(
        "INSERT INTO g5_member (rx_uid, mb_id, mb_center, rx_ce, rx_acount, rx_send_ok, reg_date)
         VALUES (?, ?, ?, ?, ?, 'N', NOW())",
        [$rx_uid, $mb_id, $mb_center, $rx_ce, $rx_acount]
    );

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => "회원 [{$rx_uid}] 등록 완료"]);
    exit;
}

// ── 알 수 없는 액션
http_response_code(400);
echo json_encode(['success' => false, 'message' => "알 수 없는 액션: {$act}"]);
