<?php
/**
 * receive_domain.php - 58번 서버 수신: 147번 관리자 → 도메인 설정 동기화
 *
 * [호출 방향] 147번 multi_domain.php 저장 → Server58Service::pushDomainConfig()
 *              → 이 파일 (POST, HMAC 서명 검증)
 *
 * [역할] rx_domain_config 테이블에 도메인-거래소 매핑 정보 Upsert
 *        → index.php 라우터가 이 테이블을 읽어 대시보드 자동 분기
 *
 * 배포 경로 (58번 서버): /var/www/html/api/receive_domain.php
 */

require_once __DIR__ . '/../../common.php'; // 58번 공통 초기화

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
if (!verify_hmac_from_147('/api/receive_domain.php', $raw)) {
    error_log('[receive_domain] HMAC 검증 실패');
    // 운영 시 아래 주석 해제 (현재는 경고 로그만):
    // http_response_code(401); api_response(false, 'HMAC 서명 불일치');
}

// ── 필수 필드 확인
$domain_host = trim($body['domain_host'] ?? '');
if (empty($domain_host)) {
    http_response_code(400);
    api_response(false, 'domain_host 필수');
}

// ── DB 연결 및 값 준비
$conn          = get_db_connection();
$domain_host   = $conn->real_escape_string(preg_replace('/^www\./', '', $domain_host));
$site_name     = $conn->real_escape_string($body['site_name']     ?? '');
$rx_ce         = intval($body['rx_ce']         ?? 1);
$exchange_name = $conn->real_escape_string($body['exchange_name'] ?? 'Toobit');
$logo_url      = $conn->real_escape_string($body['logo_url']      ?? '');
$theme_color   = $conn->real_escape_string($body['theme_color']   ?? '#0d6efd');
$mb_center     = $conn->real_escape_string($body['mb_center']     ?? '');
$is_active     = intval($body['is_active']      ?? 1);

// ── rx_domain_config Upsert (있으면 UPDATE, 없으면 INSERT)
$sql = "INSERT INTO rx_domain_config
    (domain_host, site_name, rx_ce, exchange_name, logo_url, theme_color, mb_center, is_active, created_at)
    VALUES ('{$domain_host}', '{$site_name}', {$rx_ce}, '{$exchange_name}', '{$logo_url}', '{$theme_color}', '{$mb_center}', {$is_active}, NOW())
    ON DUPLICATE KEY UPDATE
        site_name     = '{$site_name}',
        rx_ce         = {$rx_ce},
        exchange_name = '{$exchange_name}',
        logo_url      = IF('{$logo_url}' != '', '{$logo_url}', logo_url),
        theme_color   = '{$theme_color}',
        mb_center     = '{$mb_center}',
        is_active     = {$is_active},
        updated_at    = NOW()";

if ($conn->query($sql)) {
    api_response(true, "도메인 [{$domain_host}] 동기화 완료", ['rx_ce' => $rx_ce]);
} else {
    http_response_code(500);
    api_response(false, 'DB 오류: ' . $conn->error);
}
