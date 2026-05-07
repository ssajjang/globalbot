<?php
/**
 * sync.php - 58번 서버 수신: 147번 관리자 → 회원정보 동기화
 *
 * [호출 방향] 147번 members.php 수정 → Server58Service::pushMember()
 *              → 이 파일 (POST, HMAC 서명 검증)
 *
 * [역할] 147번에서 회원 정보 변경 시 58번 rx_member 테이블 자동 동기화
 *        대시보드에서 회원이 즉시 최신 투자금/클래스 정보로 접근 가능하게 함
 *
 * 배포 경로 (58번 서버): /var/www/html/api/v1/member/sync.php
 * 엔드포인트: /api/v1/member/sync
 */

require_once __DIR__ . '/../../../common.php'; // 58번 공통 초기화

// ── 기본 보안 검증
require_post_method();

if (!check_allowed_ip()) {
    http_response_code(403);
    api_response(false, '허용되지 않은 IP');
}

// ── JSON body 파싱
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body) {
    http_response_code(400);
    api_response(false, 'JSON 파싱 실패');
}

// ── HMAC 서명 검증
if (!verify_hmac_from_147('/api/v1/member/sync', $raw)) {
    error_log('[member sync] HMAC 검증 실패');
    // 운영 시 아래 주석 해제:
    // http_response_code(401); api_response(false, 'HMAC 서명 불일치');
}

// ── 필수 필드 확인
$uid = trim($body['uid'] ?? '');
if (empty($uid)) {
    http_response_code(400);
    api_response(false, 'uid 필수');
}

// ── DB 연결 및 값 준비
$conn         = get_db_connection();
$uid_safe     = $conn->real_escape_string($uid);
$mb_name      = $conn->real_escape_string($body['mb_name']    ?? '');
$mb_hp        = $conn->real_escape_string($body['mb_hp']      ?? '');
$mb_email     = $conn->real_escape_string($body['mb_email']   ?? '');
$mb_center    = $conn->real_escape_string($body['mb_center']  ?? '');
$rx_ce        = intval($body['rx_ce']       ?? 1);
$mb_class     = intval($body['mb_class']    ?? 1);
$rx_acount    = floatval($body['rx_acount'] ?? 0);
$rx_rate      = floatval($body['rx_rate']   ?? 0);
$rx_send_ok   = $conn->real_escape_string($body['rx_send_ok'] ?? 'N');

// ── 기존 회원 확인
$check = $conn->query(
    "SELECT rx_uid FROM rx_member
     WHERE rx_uid='{$uid_safe}' AND rx_ce={$rx_ce} LIMIT 1"
);

if ($check && $check->num_rows > 0) {
    // 기존 회원 → 투자금, 클래스, 봇상태만 업데이트 (API Key는 대시보드에서만 변경)
    $sql    = "UPDATE rx_member
               SET rx_acount     = {$rx_acount},
                   mb_class      = {$mb_class},
                   rx_rate       = {$rx_rate},
                   rx_send_ok    = '{$rx_send_ok}',
                   rx_updated_at = NOW()
               WHERE rx_uid = '{$uid_safe}' AND rx_ce = {$rx_ce}";
    $action = 'updated';
} else {
    // 신규 회원 → 전체 INSERT
    $sql    = "INSERT INTO rx_member
               (rx_uid, mb_name, mb_hp, mb_email, mb_center, rx_ce,
                mb_class, rx_acount, rx_rate, rx_send_ok, rx_updated_at)
               VALUES
               ('{$uid_safe}', '{$mb_name}', '{$mb_hp}', '{$mb_email}',
                '{$mb_center}', {$rx_ce}, {$mb_class}, {$rx_acount},
                {$rx_rate}, '{$rx_send_ok}', NOW())";
    $action = 'inserted';
}

if ($conn->query($sql)) {
    api_response(true, "UID [{$uid}] {$action} 완료", ['action' => $action]);
} else {
    http_response_code(500);
    api_response(false, 'DB 오류: ' . $conn->error);
}
