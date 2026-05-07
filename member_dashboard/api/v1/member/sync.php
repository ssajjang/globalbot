<?php
/**
 * sync.php - 147번 관리자에서 Push되는 회원 정보 동기화 수신 API
 *
 * [동작]
 * 147번 서버가 회원 정보를 등록/수정하면 이 파일로 POST 전송
 * → rx_member 테이블에 UPSERT
 * → 대시보드에서 즉시 반영
 *
 * 업로드 경로: /home/killer_pro/api/v1/member/sync.php
 */

require_once __DIR__ . '/../../../common.php';

// ── IP + HMAC 검증 ────────────────────────────────────────────
if (!check_allowed_ip()) {
    http_response_code(403);
    api_response(false, '허용되지 않은 IP입니다.');
}

$raw_body = file_get_contents('php://input');
if (!verify_hmac_from_147('/api/v1/member/sync', $raw_body)) {
    http_response_code(401);
    api_response(false, 'HMAC 서명 검증 실패');
}

// ── 요청 데이터 파싱 ───────────────────────────────────────────
$data = json_decode($raw_body, true);
if (!$data) {
    api_response(false, '잘못된 JSON 형식');
}

$conn = get_db_connection();

// 필수 필드 확인
$rx_uid = trim($data['rx_uid'] ?? '');
$rx_ce  = intval($data['rx_ce'] ?? 1);

if (empty($rx_uid)) {
    api_response(false, 'rx_uid 값이 없습니다.');
}

// 회원 데이터 정리
$mb_id       = trim($data['mb_id']       ?? '');
$mb_name     = trim($data['mb_name']     ?? '');
$mb_hp       = trim($data['mb_hp']       ?? '');
$mb_email    = trim($data['mb_email']    ?? '');
$mb_center   = trim($data['mb_center']   ?? '');
$mb_class    = intval($data['mb_class']  ?? 1);
$rx_acount   = floatval($data['rx_acount'] ?? 0);
$rx_spot_acount = floatval($data['rx_spot_acount'] ?? 0);
$rx_apikey   = trim($data['rx_apikey']   ?? ''); // 평문 저장
$rx_sskey    = trim($data['rx_sskey']    ?? ''); // 평문 저장
$rx_passphrase = trim($data['rx_passphrase'] ?? ''); // 평문 저장 (Deepcoin)
$rx_send_ok  = trim($data['rx_send_ok']  ?? 'N');
$rx_reserve_percent = intval($data['rx_reserve_percent'] ?? 0);

// 비밀번호 (초기값 = UID, bcrypt 해시)
$rx_uid_password = '';
if (!empty($data['rx_uid_password'])) {
    $rx_uid_password = $data['rx_uid_password']; // 이미 해시된 값 전달
} else {
    // 초기 패스워드 = UID (첫 로그인 시 변경 요구)
    $rx_uid_password = password_hash($rx_uid, PASSWORD_BCRYPT);
}

// DB UPSERT
$uid_e = $conn->real_escape_string($rx_uid);
$id_e  = $conn->real_escape_string($mb_id);
$nm_e  = $conn->real_escape_string($mb_name);
$hp_e  = $conn->real_escape_string($mb_hp);
$em_e  = $conn->real_escape_string($mb_email);
$cen_e = $conn->real_escape_string($mb_center);
$api_e = $conn->real_escape_string($rx_apikey);
$ss_e  = $conn->real_escape_string($rx_sskey);
$pp_e  = $conn->real_escape_string($rx_passphrase);
$ok_e  = $conn->real_escape_string($rx_send_ok);
$pw_e  = $conn->real_escape_string($rx_uid_password);

$sql = "INSERT INTO rx_member
          (rx_uid, mb_id, mb_name, mb_hp, mb_email, mb_center, rx_ce, mb_class,
           rx_acount, rx_spot_acount, rx_apikey, rx_sskey, rx_passphrase,
           rx_send_ok, rx_reserve_percent, rx_uid_password, rx_updated_at)
        VALUES
          ('{$uid_e}', '{$id_e}', '{$nm_e}', '{$hp_e}', '{$em_e}', '{$cen_e}',
           {$rx_ce}, {$mb_class}, {$rx_acount}, {$rx_spot_acount},
           '{$api_e}', '{$ss_e}', '{$pp_e}',
           '{$ok_e}', {$rx_reserve_percent}, '{$pw_e}', NOW())
        ON DUPLICATE KEY UPDATE
          mb_id       = '{$id_e}',
          mb_name     = '{$nm_e}',
          mb_hp       = '{$hp_e}',
          mb_email    = '{$em_e}',
          mb_center   = '{$cen_e}',
          mb_class    = {$mb_class},
          rx_acount   = {$rx_acount},
          rx_spot_acount = {$rx_spot_acount},
          rx_apikey   = '{$api_e}',
          rx_sskey    = '{$ss_e}',
          rx_passphrase = '{$pp_e}',
          rx_send_ok  = '{$ok_e}',
          rx_reserve_percent = {$rx_reserve_percent},
          rx_updated_at = NOW()";

if ($conn->query($sql)) {
    api_response(true, '회원 정보가 동기화되었습니다.', ['rx_uid' => $rx_uid]);
} else {
    error_log('[member/sync] DB 오류: ' . $conn->error, 3, LOG_PATH . '/php_error.log');
    api_response(false, 'DB 저장 실패: ' . $conn->error);
}
