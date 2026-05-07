<?php
/**
 * receive_domain.php - 147번 관리자에서 Push되는 도메인 설정 수신 API
 *
 * [동작]
 * 147번 서버가 도메인을 등록/수정하면 이 파일로 POST 전송
 * → rx_domain_config 테이블에 저장
 * → index.php 라우터가 이 테이블을 읽어 대시보드 분기
 *
 * 업로드 경로: /home/killer_pro/api/receive_domain.php
 */

require_once __DIR__ . '/../common.php';

// ── IP 화이트리스트 확인 ───────────────────────────────────────
if (!check_allowed_ip()) {
    http_response_code(403);
    api_response(false, '허용되지 않은 IP입니다.');
}

// ── HMAC 서명 검증 ─────────────────────────────────────────────
$raw_body = file_get_contents('php://input');
if (!verify_hmac_from_147('/api/receive_domain', $raw_body)) {
    http_response_code(401);
    api_response(false, 'HMAC 서명 검증 실패');
}

// ── 요청 데이터 파싱 ───────────────────────────────────────────
$data = json_decode($raw_body, true);
if (!$data) {
    api_response(false, '잘못된 JSON 형식');
}

$conn         = get_db_connection();
$domain_host  = trim($data['domain_host']  ?? '');
$site_name    = trim($data['site_name']    ?? '');
$rx_ce        = intval($data['rx_ce']      ?? 1);
$exchange_name = trim($data['exchange_name'] ?? 'Toobit');
$logo_url     = trim($data['logo_url']     ?? '');
$theme_color  = trim($data['theme_color']  ?? '#0d6efd');
$telegram_bot = trim($data['telegram_bot'] ?? '');
$telegram_chat = trim($data['telegram_chat'] ?? '');
$mb_center    = trim($data['mb_center']    ?? '');
$is_active    = intval($data['is_active']  ?? 1);

if (empty($domain_host)) {
    api_response(false, 'domain_host 값이 없습니다.');
}

// ── DB UPSERT (있으면 수정, 없으면 삽입) ────────────────────────
$dh = $conn->real_escape_string($domain_host);
$sn = $conn->real_escape_string($site_name);
$en = $conn->real_escape_string($exchange_name);
$lu = $conn->real_escape_string($logo_url);
$tc = $conn->real_escape_string($theme_color);
$tb = $conn->real_escape_string($telegram_bot);
$tch = $conn->real_escape_string($telegram_chat);
$mc = $conn->real_escape_string($mb_center);

$sql = "INSERT INTO rx_domain_config
          (domain_host, site_name, rx_ce, exchange_name, logo_url, theme_color,
           telegram_bot, telegram_chat, mb_center, is_active, updated_at)
        VALUES
          ('{$dh}', '{$sn}', {$rx_ce}, '{$en}', '{$lu}', '{$tc}',
           '{$tb}', '{$tch}', '{$mc}', {$is_active}, NOW())
        ON DUPLICATE KEY UPDATE
          site_name     = '{$sn}',
          rx_ce         = {$rx_ce},
          exchange_name = '{$en}',
          logo_url      = '{$lu}',
          theme_color   = '{$tc}',
          telegram_bot  = '{$tb}',
          telegram_chat = '{$tch}',
          mb_center     = '{$mc}',
          is_active     = {$is_active},
          updated_at    = NOW()";

if ($conn->query($sql)) {
    api_response(true, '도메인 설정이 저장되었습니다.', ['domain' => $domain_host]);
} else {
    error_log('[receive_domain] DB 오류: ' . $conn->error, 3, LOG_PATH . '/php_error.log');
    api_response(false, 'DB 저장 실패: ' . $conn->error);
}
