<?php
/**
 * TelegramService.php - Telegram 중앙 집중 전송 서비스
 * DB 기반 큐 + Rate Limit (Redis 미사용)
 * 초당 최대 20건, 분당 최대 15건 안전 마진 적용
 */

class TelegramService {

    // 텔레그램 API 기본 URL
    private const API_BASE = 'https://api.telegram.org/bot';

    // Rate Limit 설정 (Telegram 공식: 초당 30건, 분당 20건 → 안전 마진 적용)
    private const MAX_PER_SEC = 20; // 초당 최대 전송 건수 (안전 마진)
    private const MAX_PER_MIN = 15; // 분당 최대 전송 건수 (안전 마진)

    // 최근 전송 시각 기록 (Rate Limit용, 메모리 기반)
    private static array $send_timestamps = [];

    private string $bot_token;

    // ============================================================
    // 생성자
    // ============================================================
    public function __construct(string $bot_token = TELEGRAM_BOT_TOKEN) {
        $this->bot_token = $bot_token;
    }

    // ============================================================
    // 메시지를 DB 큐에 넣기 (비동기 전송)
    // telegram_log 테이블에 status='pending'으로 저장
    // ============================================================
    public function queueMessage(string $chat_id, string $text, string $event_type = '', string $uid = ''): bool {
        // DB에 전송 대기 기록
        try {
            db_execute(
                "INSERT INTO telegram_log (chat_id, message, event_type, target_uid, status, created_at)
                 VALUES (?, ?, ?, ?, 'pending', NOW())",
                [$chat_id, $text, $event_type, $uid]
            );
        } catch (Exception $e) {
            error_log('[telegram_log 기록 실패] ' . $e->getMessage());
            // DB 큐 실패 시 직접 전송 시도
            return $this->sendDirect($chat_id, $text);
        }

        return true;
    }

    // ============================================================
    // DB 큐에서 소비해서 실제 전송 (Consumer Worker에서 호출)
    // telegram_log 테이블의 status='pending' 건을 순서대로 처리
    // ============================================================
    public function processQueue(): void {
        // pending 상태인 메시지를 오래된 순서대로 가져오기 (한 번에 50건)
        $pending = db_rows(
            "SELECT log_no, chat_id, message
             FROM telegram_log
             WHERE status = 'pending'
             ORDER BY log_no ASC
             LIMIT 50"
        );

        if (empty($pending)) return;

        foreach ($pending as $msg) {
            // Rate Limit 체크
            if (!$this->checkRateLimit()) {
                usleep(100000); // 100ms 대기 후 다시 시도
                if (!$this->checkRateLimit()) {
                    break; // 여전히 초과면 이번 라운드 종료
                }
            }

            // 실제 전송
            $success = $this->sendDirect($msg['chat_id'], $msg['message']);

            // DB 상태 업데이트
            try {
                db_execute(
                    "UPDATE telegram_log SET status=?, sent_at=NOW() WHERE log_no=?",
                    [$success ? 'sent' : 'failed', $msg['log_no']]
                );
            } catch (Exception $e) {
                error_log('[telegram_log 업데이트 실패] ' . $e->getMessage());
            }

            // 전송 간격: 안전하게 50ms 대기
            usleep(50000);
        }
    }

    // ============================================================
    // 메모리 기반 Rate Limit 체크 (Redis 없이 동작)
    // 단일 프로세스 기준으로 동작 (멀티 프로세스는 DB 기반 확장 필요)
    // ============================================================
    private function checkRateLimit(): bool {
        $now = microtime(true);

        // 1초 이전 기록 제거
        self::$send_timestamps = array_filter(
            self::$send_timestamps,
            fn($ts) => ($now - $ts) < 60.0 // 1분 이내만 보관
        );

        // 1초 안의 전송 건수 체크
        $sec_count = count(array_filter(
            self::$send_timestamps,
            fn($ts) => ($now - $ts) < 1.0
        ));
        if ($sec_count >= self::MAX_PER_SEC) return false;

        // 1분 안의 전송 건수 체크
        $min_count = count(self::$send_timestamps);
        if ($min_count >= self::MAX_PER_MIN) return false;

        // Rate Limit 통과 → 현재 타임스탬프 기록
        self::$send_timestamps[] = $now;

        return true;
    }

    // ============================================================
    // Telegram API 직접 호출 (sendMessage)
    // ============================================================
    public function sendDirect(string $chat_id, string $text, string $parse_mode = 'HTML'): bool {
        $url = self::API_BASE . $this->bot_token . '/sendMessage';

        $payload = [
            'chat_id'    => $chat_id,
            'text'       => $text,
            'parse_mode' => $parse_mode,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200) {
            $result = json_decode($response, true);
            return $result['ok'] ?? false;
        }

        // 실패 로그 기록
        error_log("[Telegram 전송 실패] HTTP {$http_code} | Chat: {$chat_id} | Msg: " . substr($text, 0, 50));
        return false;
    }

    // ============================================================
    // 봇 이벤트 메시지 포맷 생성 (진입, 청산, 에러 등)
    // ============================================================
    public static function formatBotEvent(array $event): string {
        $type = $event['type'] ?? 'unknown';
        $uid  = $event['uid']  ?? '';
        $ts   = date('Y-m-d H:i:s');

        return match($type) {
            'entry' => sprintf(
                "🚀 <b>진입 완료</b>\n👤 UID: %s\n📈 방향: %s\n💰 수량: %s\n⏰ %s",
                $uid, $event['side'] ?? '-', $event['qty'] ?? '-', $ts
            ),
            'close' => sprintf(
                "✅ <b>청산 완료</b>\n👤 UID: %s\n💵 PnL: %s\n⏰ %s",
                $uid, $event['pnl'] ?? '-', $ts
            ),
            'error' => sprintf(
                "❌ <b>에러 발생</b>\n👤 UID: %s\n⚠️ 오류: %s\n⏰ %s",
                $uid, htmlspecialchars($event['error'] ?? ''), $ts
            ),
            'dca' => sprintf(
                "💧 <b>물타기 실행</b>\n👤 UID: %s\n🔢 횟수: %s회\n⏰ %s",
                $uid, $event['dca_count'] ?? '-', $ts
            ),
            'signal_control' => sprintf(
                "🎛️ <b>시그널 변경</b>\n👤 UID: %s\n📡 상태: %s\n⏰ %s",
                $uid, strtoupper($event['action'] ?? '-'), $ts
            ),
            default => sprintf(
                "📌 <b>이벤트</b>\n👤 UID: %s\n📋 타입: %s\n⏰ %s",
                $uid, $type, $ts
            ),
        };
    }
}
