<?php
/**
 * admin/ajax_manual_transfer.php - 수동 전송 실행 AJAX 엔드포인트
 *
 * 관리자가 웹 UI에서 수동으로 수익금 분배를 실행할 때 사용
 * SSE(Server-Sent Events)로 실시간 진행 상황 전달
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

date_default_timezone_set('Asia/Seoul');

require_once(__DIR__ . '/common.php');
require_once(__DIR__ . '/../websea_api_functions.php');

// 관리자 인증 체크
if (!is_logged_in() || !is_admin()) {
    header('Content-Type: application/json');
    echo json_encode(['status' => false, 'msg' => '관리자 인증 필요']);
    exit;
}

// SSE 헤더 설정
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// 출력 버퍼링 비활성화
if (ob_get_level()) ob_end_clean();

// 상수 정의
define('COMPANY_WALLET', 'TSM8RxBG9Eyvn3oD9BxvTGk68n6w6oJ83G');
define('COMPANY_CHAIN', 'TRC20');
define('COMPANY_CURRENCY', 'USDT');
define('BASE_START_DATE', '2026-03-20');
define('NORMAL_RATE', 0.20);
define('DEFERRED_RATE', 0.20);
define('MIN_TRANSFER_AMOUNT', 5.0);

// 파라미터
$target_uid = isset($_GET['uid']) ? trim($_GET['uid']) : '';
$dry_run = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';
$mode = isset($_GET['mode']) ? trim($_GET['mode']) : 'single'; // single or all

/**
 * SSE 메시지 전송
 */
function send_sse($type, $data) {
    echo "data: " . json_encode(['type' => $type, 'data' => $data], JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

/**
 * rx_trans 테이블에 기록 저장
 */
function save_trans($conn, $uid, $total_amount, $send_amount, $fail_status) {
	global $in_end_date;
    $uid_esc = $conn->real_escape_string($uid);
    //$now = date('Y-m-d H:i:s');
    $now = $in_end_date." ".date('H:i:s');
    $sql = "INSERT INTO rx_trans (rx_uid, res_total_amount, res_send_amount, reg_datetime, res_fail)
            VALUES ('{$uid_esc}', {$total_amount}, {$send_amount}, '{$now}', '{$fail_status}')";
    return $conn->query($sql);
}

// ============================================================
// 시작
// ============================================================
send_sse('start', [
    'message' => '수동 전송 프로세스 시작',
    'mode' => $mode,
    'target_uid' => $target_uid,
    'dry_run' => $dry_run,
    'timestamp' => date('Y-m-d H:i:s')
]);

$conn = get_db_connection();

// 회원 조회
$sql = "SELECT * FROM rx_member WHERE rx_ce = 3 AND rx_apikey IS NOT NULL AND rx_apikey != ''";
if ($mode === 'single' && !empty($target_uid)) {
    $uid_safe = $conn->real_escape_string($target_uid);
    $sql .= " AND rx_uid = '{$uid_safe}'";
}

$result = $conn->query($sql);
if (!$result) {
    send_sse('error', ['message' => 'DB 조회 실패: ' . $conn->error]);
    send_sse('done', ['message' => '프로세스 종료 (에러)']);
    exit;
}

$members = [];
while ($row = $result->fetch_assoc()) {
    $members[] = $row;
}

$total_members = count($members);

if ($total_members === 0) {
    send_sse('error', ['message' => '조건에 맞는 WebSea 회원이 없습니다.']);
    send_sse('done', ['message' => '프로세스 종료']);
    exit;
}

send_sse('info', ['message' => "대상 회원: {$total_members}명", 'total' => $total_members]);

$success_count = 0;
$fail_count = 0;
$skip_count = 0;
$results = [];

foreach ($members as $idx => $member) {
    $member_num = $idx + 1;
    $uid = $member['rx_uid'];
    $api_key = preg_replace('/\s+/', '', $member['rx_apikey']);
    $ss_key  = preg_replace('/\s+/', '', $member['rx_sskey']);

    send_sse('progress', [
        'current' => $member_num,
        'total' => $total_members,
        'uid' => $uid,
        'message' => "[{$member_num}/{$total_members}] UID: {$uid} 처리 시작"
    ]);

    // API 키 확인
    if (empty($api_key) || empty($ss_key)) {
        send_sse('member_skip', ['uid' => $uid, 'reason' => 'API Key 또는 Secret Key 없음']);
        $skip_count++;
        $results[] = ['uid' => $uid, 'status' => 'skip', 'reason' => 'API Key 없음'];
        continue;
    }

    $keys = ['rx_apikey' => $api_key, 'rx_sskey' => $ss_key, 'mb_id' => $uid];

    // 마지막 성공 전송일 조회
    $uid_esc = $conn->real_escape_string($uid);
    $sql_last = "SELECT reg_datetime FROM rx_trans
                 WHERE rx_uid = '{$uid_esc}' AND res_fail = '1'
                 ORDER BY reg_datetime DESC LIMIT 1";
    $res_last = $conn->query($sql_last);

    $last_success_date = null;
    $last_success_datetime = null;

    if ($res_last && $res_last->num_rows > 0) {
        $row_last = $res_last->fetch_assoc();
        $last_success_datetime = $row_last['reg_datetime'];
        $last_success_date = date('Y-m-d', strtotime($last_success_datetime));
        send_sse('member_log', ['uid' => $uid, 'message' => "마지막 성공 전송일: {$last_success_datetime}"]);
    } else {
        send_sse('member_log', ['uid' => $uid, 'message' => "이전 성공 전송 기록 없음"]);
    }

    // 시작일 결정
    if ($last_success_date) {
        //$start_date = date('Y-m-d', strtotime($last_success_date . ' +1 day'));

        $start_date = date('Y-m-d', strtotime($last_success_date . ' +0 day'));
    } else {
        $start_date = BASE_START_DATE;
    }
    //$end_date = date('Y-m-d');
	$end_date = date('Y-m-d', strtotime('-1 day'));
	//$end_date = date('Y-m-d', "2026-04-05");
	//$end_date = "2026-04-05";
	//$in_end_date = "2026-04-06";
	$in_end_date = date('Y-m-d');

    send_sse('member_log', ['uid' => $uid, 'message' => "수익 산출 기간: {$start_date} ~ {$end_date}"]);

    // 거래 내역 조회
    $res_hist = ws_get_history_positions_since($keys, $start_date);

    if (!$res_hist['status']) {
        send_sse('member_error', ['uid' => $uid, 'message' => '거래 내역 조회 실패: ' . ($res_hist['msg'] ?? 'Unknown')]);
        $skip_count++;
        $results[] = ['uid' => $uid, 'status' => 'skip', 'reason' => '거래 내역 조회 실패'];
        continue;
    }

    // 수익 합산
    $accumulated_profit = 0;
    $trade_count = 0;

    if (!empty($res_hist['data'])) {
        foreach ($res_hist['data'] as $trade) {
            $ctime = isset($trade['ctime']) ? intval($trade['ctime']) : 0;
            $trade_date = date('Y-m-d', $ctime);
            if ($trade_date >= $start_date && $trade_date <= $end_date) {
                $accumulated_profit += floatval($trade['realizedPnL'] ?? 0);
                $trade_count++;
            }
        }
    }

    send_sse('member_log', ['uid' => $uid, 'message' => "거래 {$trade_count}건, 누적 수익: " . number_format($accumulated_profit, 4) . " USDT"]);

    if ($accumulated_profit <= 0) {
        send_sse('member_skip', ['uid' => $uid, 'reason' => '수익 0 이하 (' . number_format($accumulated_profit, 4) . ' USDT)']);
        $skip_count++;
        $results[] = ['uid' => $uid, 'status' => 'skip', 'reason' => '수익 0 이하', 'profit' => $accumulated_profit];
        continue;
    }

    // 전송 금액 계산
    $is_deferred = false;
    $applied_rate = NORMAL_RATE;
    $transfer_amount = $accumulated_profit * NORMAL_RATE;

    if ($last_success_datetime) {
        $last_success_ts = strtotime($last_success_datetime);
        $one_week_ago = strtotime('-7 days');
        if ($last_success_ts < $one_week_ago) {
            $is_deferred = true;
            $applied_rate = DEFERRED_RATE;
            $transfer_amount = $accumulated_profit * DEFERRED_RATE;
        }
    } else {
        $base_ts = strtotime(BASE_START_DATE);
        $one_week_ago = strtotime('-7 days');
        if ($base_ts < $one_week_ago) {
            $is_deferred = true;
            $applied_rate = DEFERRED_RATE;
            $transfer_amount = $accumulated_profit * DEFERRED_RATE;
        }
    }

    $transfer_amount = floor($transfer_amount * 10000) / 10000;
    $rate_label = $is_deferred ? '22% (보류 누적)' : '20% (일반)';

    send_sse('member_log', ['uid' => $uid, 'message' => "적용 수수료: {$rate_label}, 전송 예정: " . number_format($transfer_amount, 4) . " USDT"]);

    // 최소 금액 체크
    if ($transfer_amount < MIN_TRANSFER_AMOUNT) {
        send_sse('member_skip', [
            'uid' => $uid,
            'reason' => "전송 금액 미달 (" . number_format($transfer_amount, 4) . " < " . MIN_TRANSFER_AMOUNT . " USDT)"
        ]);
        $skip_count++;
        $results[] = [
            'uid' => $uid, 'status' => 'skip',
            'reason' => '$5 미달 보류',
            'profit' => $accumulated_profit,
            'transfer_amount' => $transfer_amount
        ];
        continue;
    }

    // 드라이런 모드
    if ($dry_run) {
        send_sse('member_dryrun', [
            'uid' => $uid,
            'profit' => number_format($accumulated_profit, 4),
            'transfer_amount' => number_format($transfer_amount, 4),
            'rate' => $rate_label,
            'message' => "[드라이런] 전송 조건 충족 - 실제 전송 안 함"
        ]);
        $skip_count++;
        $results[] = [
            'uid' => $uid, 'status' => 'dryrun',
            'profit' => $accumulated_profit,
            'transfer_amount' => $transfer_amount,
            'rate' => $rate_label
        ];
        continue;
    }

    // 실제 전송 실행
    send_sse('member_log', ['uid' => $uid, 'message' => "[Step 1] Futures → Spot 이동: {$transfer_amount} USDT"]);

    $res_transfer = ws_transfer_futures_to_spot($keys, $transfer_amount);

    if (!$res_transfer['status']) {
        $err_msg = $res_transfer['msg'] ?? 'Unknown';
        send_sse('member_fail', ['uid' => $uid, 'message' => "Futures→Spot 이동 실패: {$err_msg}"]);
        save_trans($conn, $uid, $accumulated_profit, $transfer_amount, '2');
        $fail_count++;
        $results[] = ['uid' => $uid, 'status' => 'fail', 'reason' => "Futures→Spot 실패: {$err_msg}", 'transfer_amount' => $transfer_amount];
        continue;
		// 이동 실패 해도 스팟에 있을수도 있으니 다음 처리 해 보고 안되면 실패
    }

    send_sse('member_log', ['uid' => $uid, 'message' => "Futures→Spot 이동 성공! 2초 대기..."]);
    sleep(2);

    send_sse('member_log', ['uid' => $uid, 'message' => "[Step 2] Spot → 회사 지갑 출금: {$transfer_amount} USDT"]);

    $res_withdraw = ws_withdraw_to_address($keys, COMPANY_CURRENCY, COMPANY_CHAIN, COMPANY_WALLET, $transfer_amount);

    if (!$res_withdraw['status']) {
        $err_msg = $res_withdraw['msg'] ?? 'Unknown';
        send_sse('member_fail', ['uid' => $uid, 'message' => "출금 실패: {$err_msg}"]);
        save_trans($conn, $uid, $accumulated_profit, $transfer_amount, '2');
        $fail_count++;
        $results[] = ['uid' => $uid, 'status' => 'fail', 'reason' => "출금 실패: {$err_msg}", 'transfer_amount' => $transfer_amount];
        continue;
    }

    $withdraw_id = $res_withdraw['data']['withdrawId'] ?? '완료';
    send_sse('member_success', [
        'uid' => $uid,
        'profit' => number_format($accumulated_profit, 4),
        'transfer_amount' => number_format($transfer_amount, 4),
        'rate' => $rate_label,
        'withdraw_id' => $withdraw_id,
        'message' => "전송 성공! (요청 ID: {$withdraw_id})"
    ]);

    // DB 기록
    save_trans($conn, $uid, $accumulated_profit, $transfer_amount, '1');

    $now = date('Y-m-d H:i:s');
    $conn->query("UPDATE rx_member SET rx_transfer_success_date = '{$now}' WHERE rx_uid = '{$uid_esc}' AND rx_ce = 3");

    $success_count++;
    $results[] = [
        'uid' => $uid, 'status' => 'success',
        'profit' => $accumulated_profit,
        'transfer_amount' => $transfer_amount,
        'rate' => $rate_label,
        'withdraw_id' => $withdraw_id
    ];

    sleep(1);
}

// 최종 결과
send_sse('done', [
    'message' => '프로세스 완료',
    'total' => $total_members,
    'success' => $success_count,
    'fail' => $fail_count,
    'skip' => $skip_count,
    'results' => $results
]);

$conn->close();