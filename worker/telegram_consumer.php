#!/usr/bin/env php
<?php
/**
 * telegram_consumer.php - DB Queue 소비 워커
 * Supervisor로 항상 실행 상태를 유지합니다.
 * 실행 명령: php telegram_consumer.php
 *
 * [동작 원리]
 * 1. telegram_log 테이블에서 status='pending' 건을 조회
 * 2. Rate Limit 체크 후 Telegram API로 전송
 * 3. 전송 완료 시 status='sent', 실패 시 status='failed'로 업데이트
 *
 * Supervisor 설정 예시 (/etc/supervisor/conf.d/telegram_consumer.conf):
 * [program:telegram_consumer]
 * command=php /home/killer_pro/global-admin-147/worker/telegram_consumer.php
 * autostart=true
 * autorestart=true
 * stderr_logfile=/var/log/telegram_consumer.err.log
 * stdout_logfile=/var/log/telegram_consumer.out.log
 */

// ============================================================
// 초기 설정
// ============================================================
define('_WORKER_', true); // 워커 실행 중 표시

// 필수 파일 로드
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../services/TelegramService.php';
require_once __DIR__ . '/../services/EventHandler.php';

// 무한 실행 설정 (Supervisor가 재시작해주므로 안전)
set_time_limit(0);
ini_set('memory_limit', '128M');

// 종료 신호 처리 (Supervisor가 SIGTERM을 보내면 우아하게 종료)
$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
    pcntl_signal(SIGINT,  function () use (&$running) { $running = false; });
}

// ============================================================
// DB 연결 확인
// ============================================================
try {
    $pdo = get_pdo();
    echo "[" . date('Y-m-d H:i:s') . "] Telegram Consumer 시작. DB 연결 완료.\n";
} catch (Exception $e) {
    echo "[ERROR] DB 연결 실패: " . $e->getMessage() . "\n";
    exit(1); // Supervisor가 재시작함
}

$telegram = new TelegramService();
$handler  = new EventHandler($telegram);

// ============================================================
// 메인 루프 - DB 큐에서 이벤트 소비
// ============================================================
while ($running) {
    // 신호 처리 (pcntl 사용 가능한 경우)
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    try {
        // DB에서 pending 건을 TelegramService가 처리
        $telegram->processQueue();

        // 2초 대기 후 다음 라운드 (DB 부하 방지)
        sleep(2);

    } catch (Exception $e) {
        echo "[ERROR] " . $e->getMessage() . "\n";
        error_log('[Consumer 오류] ' . $e->getMessage());
        sleep(3); // 에러 후 잠깐 대기
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Telegram Consumer 정상 종료.\n";
