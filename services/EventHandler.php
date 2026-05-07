<?php
/**
 * EventHandler.php - 이벤트 타입별 분기 처리기
 * Node.js에서 발생한 이벤트를 수신하여 DB 업데이트, 감사 로그, Telegram 전송
 */

class EventHandler {

    private TelegramService $telegram;

    public function __construct(TelegramService $telegram) {
        $this->telegram = $telegram;
    }

    // ============================================================
    // 이벤트 메인 핸들러 - 타입별 분기
    // ============================================================
    public function handle(array $event): void {
        $type = $event['type'] ?? '';
        $uid  = $event['uid']  ?? '';

        switch ($type) {
            case 'signal_control': // 시그널 ON/OFF
                $this->handleSignalControl($event);
                break;

            case 'entry':          // 진입 완료
                $this->handleEntry($event);
                break;

            case 'close':          // 청산 완료
                $this->handleClose($event);
                break;

            case 'dca':            // 물타기 실행
                $this->handleDca($event);
                break;

            case 'error':          // 에러 발생
                $this->handleError($event);
                break;

            case 'position_sync':  // 포지션 동기화
                $this->handlePositionSync($event);
                break;

            default:
                error_log("[EventHandler] 알 수 없는 이벤트 타입: {$type}");
        }
    }

    // ============================================================
    // 시그널 ON/OFF 처리 (Node.js 프로세스 유지)
    // ============================================================
    private function handleSignalControl(array $event): void {
        $uid    = $event['uid']    ?? '';
        $action = $event['action'] ?? 'off'; // 'on' 또는 'off'

        // DB 업데이트: rx_send_ok 변경
        $new_state = $action === 'on' ? 'Y' : 'S'; // Y:가동, S:정지(Signal OFF)
        db_execute(
            "UPDATE g5_member SET rx_send_ok=? WHERE rx_uid=?",
            [$new_state, $uid]
        );

        // 봇 가동 시작 시간 기록
        if ($action === 'on') {
            db_execute(
                "UPDATE g5_member SET bot_start_date=NOW() WHERE rx_uid=? AND bot_start_date IS NULL",
                [$uid]
            );
        }

        // Telegram 알림
        $member = db_row("SELECT mb_center, T_chat_id FROM g5_member m
                          JOIN center_TB c ON m.mb_center = c.center_name
                          WHERE m.rx_uid=? LIMIT 1", [$uid]);
        if ($member && $member['T_chat_id']) {
            $msg = TelegramService::formatBotEvent($event);
            $this->telegram->queueMessage($member['T_chat_id'], $msg, 'signal_control', $uid);
        }
    }

    // ============================================================
    // 진입 완료 처리
    // ============================================================
    private function handleEntry(array $event): void {
        $uid  = $event['uid']  ?? '';
        $side = $event['side'] ?? '';
        $qty  = $event['qty']  ?? 0;
        $price = $event['price'] ?? 0;

        // 포지션 정보를 DB에 기록 (감사 목적)
        error_log("[Entry] UID={$uid} SIDE={$side} QTY={$qty} PRICE={$price}");

        // Telegram 알림
        $member = db_row("SELECT m.mb_center, c.T_chat_id FROM g5_member m
                          JOIN center_TB c ON m.mb_center = c.center_name
                          WHERE m.rx_uid=? LIMIT 1", [$uid]);
        if ($member && $member['T_chat_id']) {
            $msg = TelegramService::formatBotEvent($event);
            $this->telegram->queueMessage($member['T_chat_id'], $msg, 'entry', $uid);
        }
    }

    // ============================================================
    // 청산 완료 처리
    // ============================================================
    private function handleClose(array $event): void {
        $uid = $event['uid'] ?? '';
        $pnl = $event['pnl'] ?? 0;

        // 청산 완료 로그 기록
        error_log("[Close] UID={$uid} PNL={$pnl}");

        // DB 물타기 횟수 초기화
        db_execute("UPDATE g5_member SET rx_dca_count=0 WHERE rx_uid=?", [$uid]);

        // Telegram 알림
        $member = db_row("SELECT m.mb_center, c.T_chat_id FROM g5_member m
                          JOIN center_TB c ON m.mb_center = c.center_name
                          WHERE m.rx_uid=? LIMIT 1", [$uid]);
        if ($member && $member['T_chat_id']) {
            $msg = TelegramService::formatBotEvent($event);
            $this->telegram->queueMessage($member['T_chat_id'], $msg, 'close', $uid);
        }
    }

    // ============================================================
    // 물타기 실행 처리
    // ============================================================
    private function handleDca(array $event): void {
        $uid       = $event['uid']       ?? '';
        $dca_count = $event['dca_count'] ?? 0;

        // DB 물타기 횟수 업데이트
        db_execute("UPDATE g5_member SET rx_dca_count=? WHERE rx_uid=?", [$dca_count, $uid]);

        // 물타기 10회 이상 시 Telegram 긴급 알림
        if ($dca_count >= 10) {
            $member = db_row("SELECT m.mb_center, c.T_chat_id FROM g5_member m
                              JOIN center_TB c ON m.mb_center = c.center_name
                              WHERE m.rx_uid=? LIMIT 1", [$uid]);
            if ($member && $member['T_chat_id']) {
                $msg = TelegramService::formatBotEvent($event);
                $this->telegram->queueMessage($member['T_chat_id'], $msg, 'dca', $uid);
            }
        }
    }

    // ============================================================
    // 에러 처리
    // ============================================================
    private function handleError(array $event): void {
        $uid   = $event['uid']   ?? '';
        $error = $event['error'] ?? '';

        // 에러는 반드시 Telegram 전송 (무조건)
        $member = db_row("SELECT m.mb_center, c.T_chat_id FROM g5_member m
                          JOIN center_TB c ON m.mb_center = c.center_name
                          WHERE m.rx_uid=? LIMIT 1", [$uid]);
        if ($member && $member['T_chat_id']) {
            $msg = TelegramService::formatBotEvent($event);
            $this->telegram->queueMessage($member['T_chat_id'], $msg, 'error', $uid);
        }

        // 시스템 로그에도 기록
        error_log("[Bot 에러] UID={$uid} | {$error}");
    }

    // ============================================================
    // 포지션 동기화 처리
    // ============================================================
    private function handlePositionSync(array $event): void {
        $uid      = $event['uid']      ?? '';
        $position = $event['position'] ?? null;

        if (!$position) return;

        // 포지션 동기화 로그 기록
        error_log("[PositionSync] UID={$uid} DATA=" . json_encode($position));
    }
}
