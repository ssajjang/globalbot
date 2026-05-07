<?php
// [디버깅] 에러를 화면에 표시합니다. 정상 작동 확인 후 아래 두 줄은 삭제하셔도 됩니다.
//error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set("display_errors", 1);

require_once(__DIR__ . '/common.php');
require_once(__DIR__ . '/lang.php'); // 언어팩 로드 추가

/**
 * Deepcoin 통합 대시보드 - UI/UX 탭 구성 및 DB 스냅샷 적용
 */

date_default_timezone_set('Asia/Seoul');
define('DEEPCOIN_API_URL', 'https://api.deepcoin.com');

$conn = get_db_connection();
$GLOBALS['api_debug_info'] = null; // 디버그 로그 저장용 글로벌 변수

// [DB 스키마 자동 업데이트] 주간수익률 및 트론스캔 실제입금액 컬럼 추가
$check_col_pnl = $conn->query("SHOW COLUMNS FROM rx_weekly_txid LIKE 'weekly_pnl'");
if ($check_col_pnl && $check_col_pnl->num_rows == 0) {
    $conn->query("ALTER TABLE rx_weekly_txid ADD COLUMN weekly_pnl DECIMAL(15,4) NOT NULL DEFAULT '0.0000' COMMENT '주간손익'");
    $conn->query("ALTER TABLE rx_weekly_txid ADD COLUMN avg_equity DECIMAL(15,4) NOT NULL DEFAULT '0.0000' COMMENT '평균투자금'");
    $conn->query("ALTER TABLE rx_weekly_txid ADD COLUMN weekly_roi DECIMAL(15,4) NOT NULL DEFAULT '0.0000' COMMENT '주간수익률'");
}

$check_col_actual = $conn->query("SHOW COLUMNS FROM rx_weekly_txid LIKE 'actual_amount'");
if ($check_col_actual && $check_col_actual->num_rows == 0) {
    $conn->query("ALTER TABLE rx_weekly_txid ADD COLUMN actual_amount DECIMAL(15,4) NOT NULL DEFAULT '0.0000' COMMENT '트론스캔 실제입금액'");
}

// [DB 스키마 자동 업데이트] 대시보드 UI 출력을 위한 관리자 상태 컬럼 추가
$check_col_status = $conn->query("SHOW COLUMNS FROM rx_weekly_txid LIKE 'status'");
if ($check_col_status && $check_col_status->num_rows == 0) {
    $conn->query("ALTER TABLE rx_weekly_txid ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT '대기'");
    $conn->query("ALTER TABLE rx_weekly_txid ADD COLUMN check_count INT NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE rx_weekly_txid ADD COLUMN last_check DATETIME NULL");
}

// 전역(글로벌) 설정 저장을 위한 테이블이 없다면 자동 생성 (입금 주소 전체 공용)
$conn->query("CREATE TABLE IF NOT EXISTS rx_global_settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='시스템 전역 공용 설정'");

// [핵심 교정] 현재 실행 중인 파일명을 동적으로 가져옵니다.
$current_page = basename($_SERVER['PHP_SELF']);

// [핵심 교정] 화면 리로드 멈춤 현상 100% 방지 서버단 리다이렉트
function redirect_to($url) {
    if (!headers_sent()) {
        header("Location: " . $url);
        exit;
    } else {
        echo "<script>window.location.replace('{$url}');</script>";
        exit;
    }
}

// ==================================================================
// [권한 및 세션 체크]
// ==================================================================
$admin_view = false;
if (isset($_REQUEST['admin_view']) && $_REQUEST['admin_view'] == '1' && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
    $admin_view = true;
    $_GET['s_uid'] = trim($_REQUEST['admin_uid'] ?? '');
    $admin_uid = trim($_REQUEST['admin_uid'] ?? '');
    $admin_ce = intval($_REQUEST['admin_ce'] ?? 4);

    if (!empty($admin_uid)) {
        $uid_safe = $conn->real_escape_string($admin_uid);
        $sql_tmp = "SELECT * FROM rx_member WHERE rx_uid = '{$uid_safe}' AND rx_ce = {$admin_ce} LIMIT 1";
        $res_tmp = $conn->query($sql_tmp);
        $member = ($res_tmp && $res_tmp->num_rows > 0) ? $res_tmp->fetch_assoc() : null;
		$add_params = $admin_view ? "&admin_view=1&admin_uid=".urlencode($admin_uid)."&admin_ce={$admin_ce}" : "";
    } else {
        $member = null;
    }
} else {
    $member = get_current_member();
}

if (!$member) {
    if (!$admin_view) { session_destroy(); header('Location: login.php'); }
    exit;
}

if (!$admin_view && intval($member['rx_ce']) !== 4) {
    // [수정] rx_ce=4(Deepcoin)만 접근 허용. 기존에 rx_ce=3(WebSea)도 통과되는 버그 수정
    header('Location: index.php');
    exit;
}

// 초기 패스워드 체크
if (!$admin_view && is_initial_password($member)) {
    header('Location: change_password.php?initial=1');
    exit;
}

$s_uid = isset($_GET['s_uid']) ? trim($_GET['s_uid']) : ($member['rx_uid'] ?? "");
$s_symbol = isset($_GET['s_symbol']) ? trim($_GET['s_symbol']) : "BTC-USDT-SWAP";

$current_ce = $admin_view ? ($admin_ce ?? 4) : intval($member['rx_ce'] ?? 4);
if (isset($_GET['ajax_action'])) {
    $current_ce = isset($_GET['admin_ce']) ? intval($_GET['admin_ce']) : 4;
}

// ==================================================================
// DB에 저장된 글로벌 공용 입금 주소 로드
// ==================================================================
$global_deposit_address = "관리자에게 입금 주소 등록을 요청하세요.";
$res_global = $conn->query("SELECT setting_value FROM rx_global_settings WHERE setting_key = 'admin_deposit_address' LIMIT 1");
if ($res_global && $res_global->num_rows > 0) {
    $val = trim($res_global->fetch_assoc()['setting_value']);
    if (!empty($val)) {
        $global_deposit_address = $val;
    }
}

// ==================================================================
// [POST 액션] 설정 탭 업데이트 및 자산 이동
// ==================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    // 1. API 및 투자금 설정 업데이트
    if ($act == 'update_settings') {
        $up_api = $conn->real_escape_string(preg_replace('/\s+/', '', $_POST['up_apikey'] ?? ''));
        $up_sec = $conn->real_escape_string(preg_replace('/\s+/', '', $_POST['up_sskey'] ?? ''));
        $up_pwd = $conn->real_escape_string(preg_replace('/\s+/', '', $_POST['up_password'] ?? ''));

        $up_acount = floatval(str_replace(',', '', $_POST['up_acount']));
        $up_spot_acount = floatval(str_replace(',', '', $_POST['up_spot_acount']));
        $up_reserve_percent = isset($_POST['up_reserve_percent']) ? intval($_POST['up_reserve_percent']) : 0;
        $uid_safe = $conn->real_escape_string($s_uid);

        $chk_sql = "SELECT rx_acount, rx_spot_acount FROM rx_member WHERE rx_uid = '{$uid_safe}' AND rx_ce = {$current_ce} LIMIT 1";
        $chk_res = $conn->query($chk_sql);
        $old_data = ($chk_res && $chk_res->num_rows > 0) ? $chk_res->fetch_assoc() : ['rx_acount'=>0, 'rx_spot_acount'=>0];

        $old_acount = floatval($old_data['rx_acount']);
        $old_spot = floatval($old_data['rx_spot_acount']);

        if (abs($old_acount - $up_acount) >= 0.0001) {
            $hist_sql = "INSERT INTO rx_account_history (rx_uid, rx_ce, amount_type, before_amount, after_amount, regdate)
                         VALUES ('{$uid_safe}', {$current_ce}, 'futures', {$old_acount}, {$up_acount}, NOW())";
            $conn->query($hist_sql);
        }

        if (abs($old_spot - $up_spot_acount) >= 0.0001) {
            $hist_sql = "INSERT INTO rx_account_history (rx_uid, rx_ce, amount_type, before_amount, after_amount, regdate)
                         VALUES ('{$uid_safe}', {$current_ce}, 'spot', {$old_spot}, {$up_spot_acount}, NOW())";
            $conn->query($hist_sql);
        }

        $up_sql = "UPDATE rx_member
                   SET rx_apikey = '{$up_api}',
                       rx_sskey = '{$up_sec}',
                       rx_password = '{$up_pwd}',
                       rx_acount = {$up_acount},
                       rx_spot_acount = {$up_spot_acount},
                       rx_reserve_percent = {$up_reserve_percent}
                   WHERE rx_uid = '{$uid_safe}' AND rx_ce = {$current_ce}";

        $add_params = $admin_view ? "&admin_view=1&admin_uid=".urlencode($s_uid)."&admin_ce={$current_ce}" : "";

        if ($conn->query($up_sql)) {
            if (!$admin_view && isset($_SESSION['member'])) {
                $refresh_sql = "SELECT * FROM rx_member WHERE rx_uid = '{$uid_safe}' AND rx_ce = {$current_ce} LIMIT 1";
                $refresh_res = $conn->query($refresh_sql);
                if ($refresh_res && $refresh_res->num_rows > 0) {
                    $_SESSION['member'] = $refresh_res->fetch_assoc();
                }
            }

            if (session_status() == PHP_SESSION_NONE) session_start();
            foreach ($_SESSION as $k => $v) {
                if (strpos($k, 'rx_init_qty_') === 0) {
                    unset($_SESSION[$k]);
                }
            }

            $redirect_url = "{$current_page}?s_uid=".urlencode($s_uid)."{$add_params}&msg=update_ok#tab-settings";
        } else {
            $db_error = $conn->error;
            $redirect_url = "{$current_page}?s_uid=".urlencode($s_uid)."{$add_params}&msg=db_error_" . urlencode($db_error) . "#tab-settings";
        }

		// ======================================================
		// [수정] 147번 글로벌어드민 서버로 회원 정보 동기화
		// 기존 mypnp.xyz → 실제 147번 서버 주소로 교체
		// ======================================================
		$global_admin_api_url = "https://CHANGE_147_SERVER_DOMAIN/api/signup_api_dashboard.php";
		$uid_safe = $conn->real_escape_string($s_uid);
        $sql_tmp = "SELECT * FROM rx_member WHERE rx_uid = '{$uid_safe}' AND rx_ce = {$current_ce} LIMIT 1";
        $res_tmp = $conn->query($sql_tmp);
        if ($res_tmp && $res_tmp->num_rows > 0) {
            $member1 = $res_tmp->fetch_assoc();
        } else {
            $member1 = null;
        }

		if ($member1) {
			switch (intval($member1['rx_reserve_percent'] ?? 0)) {
				case 0:   $rx_class = 1; break;
				case 30:  $rx_class = 2; break;
				case 100: $rx_class = 3; break;
				default:  $rx_class = 0; break;
			}

			$sync_payload = json_encode([
				'act'        => 'member_sync',
				'rx_uid'     => $member1['rx_uid'],
				'rx_acount'  => $member1['rx_acount'],
				'rx_apikey'  => $member1['rx_apikey'],
				'rx_sskey'   => $member1['rx_sskey'],
				'rx_password'=> $member1['rx_password'] ?? '',
				'rx_class'   => $rx_class,
				'rx_ce'      => $current_ce, // Deepcoin = 4
			], JSON_UNESCAPED_UNICODE);

			$sync_secret    = 'CHANGE_THIS_SECRET_KEY';
			$sync_timestamp = time();
			$sync_endpoint  = '/api/signup_api_dashboard.php';
			$sign_str       = $sync_timestamp . '|' . $sync_endpoint . '|' . $sync_payload;
			$sync_signature = hash_hmac('sha256', $sign_str, $sync_secret);

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $global_admin_api_url);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $sync_payload);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				'Content-Type: application/json',
				'X-Timestamp: ' . $sync_timestamp,
				'X-Signature: ' . $sync_signature,
				'X-Source: 58_dashboard',
			]);
			$sync_response = curl_exec($ch);
			$sync_status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if ($sync_status !== 200) {
				error_log("[deepcoin sync] 147번 동기화 실패 HTTP:{$sync_status} UID:{$member1['rx_uid']}");
			}
		}

        redirect_to($redirect_url);
    }

    // 2. 자산 이동
    if ($act == 'transfer') {
        $t_uid = trim($_POST['t_uid']);
        $t_type = trim($_POST['t_type']);
        $t_amount = trim($_POST['t_amount']);
        $res_trans = rx_transfer_asset($t_uid, $t_type, $t_amount);

        if (isset($res_trans['code']) && $res_trans['code'] === '0') {
            $msg_code = "trans_ok_" . urlencode($t_amount);
        } else {
            $fail_msg = isset($res_trans['msg']) ? $res_trans['msg'] : 'Unknown Error';
            $msg_code = "trans_fail_" . urlencode($fail_msg);
        }

        $add_params = $admin_view ? "&admin_view=1&admin_uid=".urlencode($t_uid)."&admin_ce={$current_ce}" : "";
        $redirect_url = "{$current_page}?s_uid=".urlencode($t_uid)."{$add_params}&msg={$msg_code}#tab-settings";

        redirect_to($redirect_url);
    }

    // 3. 주간 TXID 및 데이터 저장 (수정: 소수점 2자리 DB 고정 저장 적용)
    if ($act == 'save_txid') {
        $week_tag = $conn->real_escape_string(trim($_POST['week_tag']));
        $txid = $conn->real_escape_string(trim($_POST['txid']));

        // [핵심 교정] 주간손익 등 금액 데이터를 DB에 넘기기 직전, 소수점 2자리로 완벽하게 고정합니다.
        $weekly_pnl = round(floatval($_POST['weekly_pnl'] ?? 0), 2);
        $avg_equity = round(floatval($_POST['avg_equity'] ?? 0), 2);
        $weekly_roi = round(floatval($_POST['weekly_roi'] ?? 0), 2);

        // [핵심 교정] 2자리로 고정된 주간수익을 바탕으로 20%를 계산하고, 이 구독료 값도 2자리로 고정합니다.
        $sub_fee = ($weekly_pnl > 0) ? round($weekly_pnl * 0.20, 2) : 0;

        $uid_safe = $conn->real_escape_string($s_uid);
        $add_params = $admin_view ? "&admin_view=1&admin_uid=".urlencode($s_uid)."&admin_ce={$current_ce}" : "";

        if (!empty($week_tag) && !empty($txid)) {
            // 유저 화면에서는 검증을 생략하고 actual_amount를 0으로 둔 채 '입금대기' 상태로 저장합니다.
            $conn->query("INSERT INTO rx_weekly_txid (rx_uid, rx_ce, week_tag, txid, amount, actual_amount, weekly_pnl, avg_equity, weekly_roi, status, regdate)
                          VALUES ('{$uid_safe}', {$current_ce}, '{$week_tag}', '{$txid}', {$sub_fee}, 0, {$weekly_pnl}, {$avg_equity}, {$weekly_roi}, '입금대기', NOW())
                          ON DUPLICATE KEY UPDATE txid = '{$txid}', amount = {$sub_fee}, actual_amount = 0, weekly_pnl = {$weekly_pnl}, avg_equity = {$avg_equity}, weekly_roi = {$weekly_roi}, status = '입금대기', regdate = NOW()");
        }

        redirect_to("{$current_page}?s_uid=".urlencode($s_uid)."{$add_params}&msg=txid_ok#tab-week");
    }
}

// 손익 데이터 소수점 절사 및 콤마(,) 1000단위 표기 처리
function format_truncate($num, $decimals) {
    $isNegative = $num < 0;
    $numStr = sprintf('%.10f', abs((float)$num));
    if (($pos = strpos($numStr, '.')) !== false) {
        if ($decimals == 0) {
            $res = substr($numStr, 0, $pos);
        } else {
            $res = substr($numStr, 0, $pos + 1 + $decimals);
            if (substr($res, -1) === '.') $res = substr($res, 0, -1);
        }
    } else {
        $res = $numStr;
    }

    $parts = explode('.', $res);
    $parts[0] = number_format((float)$parts[0]);
    $finalRes = implode('.', $parts);

    return ($isNegative && floatval($res) != 0 ? '-' : '') . $finalRes;
}

// 주간 태그를 YYYY-MM-DD 포맷으로 파싱
function format_week_tag($week_tag) {
    if (preg_match('/^(\d{4})\D*(\d{1,2})$/', trim($week_tag), $matches)) {
        $year = intval($matches[1]);
        $week = intval($matches[2]);

        $dto = new DateTime();
        $dto->setISODate($year, $week);
        $mon = $dto->format('Y-m-d');
        $dto->modify('+6 days');
        $sun = $dto->format('Y-m-d');
        return $mon . ' ~ ' . $sun;
    }
    return $week_tag;
}

// ==================================================================
// [공통 함수 및 API 함수 선언]
// ==================================================================
function getMemberApiKeys($uid) {
    global $conn, $current_ce;
    $ce = isset($current_ce) ? $current_ce : 4;
    if (!$uid || !$conn) return ['rx_apikey'=>'', 'rx_sskey'=>'', 'rx_password'=>'', 'rx_acount'=>0];

    $uid_safe = $conn->real_escape_string($uid);
    $sql = "SELECT rx_apikey, rx_sskey, rx_password, rx_acount FROM rx_member WHERE rx_uid = '{$uid_safe}' AND rx_ce = {$ce} LIMIT 1";
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $row['rx_apikey'] = trim($row['rx_apikey'] ?? '');
        $row['rx_sskey'] = trim($row['rx_sskey'] ?? '');
        $row['rx_password'] = trim($row['rx_password'] ?? '');
        return $row;
    }
    return ['rx_apikey'=>'', 'rx_sskey'=>'', 'rx_password'=>'', 'rx_acount'=>0];
}

function getMemberSpotAmount($uid) {
    global $conn, $current_ce;
    $ce = isset($current_ce) ? $current_ce : 4;
    if (!$uid || !$conn) return 0;

    $uid_safe = $conn->real_escape_string($uid);
    $sql = "SELECT rx_spot_acount FROM rx_member WHERE rx_uid = '{$uid_safe}' AND rx_ce = {$ce} LIMIT 1";
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        return floatval($row['rx_spot_acount'] ?? 0);
    }
    return 0;
}

function callDeepcoinAPI($method, $endpoint, $data = array(), $keys) {
    // [호출 딜레이] Rate Limit 방지를 위해 연속 호출 시 0.3초 간격을 둡니다.
    static $last_call_time = 0;
    $min_interval_us = 300000; // 0.3초 (마이크로초)
    $now = microtime(true) * 1000000;
    $elapsed = $now - $last_call_time;
    if ($elapsed < $min_interval_us && $last_call_time > 0) {
        usleep($min_interval_us - $elapsed);
    }
    $last_call_time = microtime(true) * 1000000;

    if (empty($keys['rx_apikey']) || empty($keys['rx_sskey']) || empty($keys['rx_password'])) {
        return array('code' => '999', 'msg' => t('err_api_keys_missing'));
    }

    $url = DEEPCOIN_API_URL . $endpoint;
    $t = microtime(true);
    $micro = sprintf("%03d", ($t - floor($t)) * 1000);
    $timestamp = gmdate('Y-m-d\TH:i:s.', $t) . $micro . 'Z';
    $bodyStr = '';
    $requestPath = $endpoint;

    if ($method === 'GET' && !empty($data)) {
        $query = http_build_query($data);
        $requestPath .= '?' . $query;
        $url .= '?' . $query;
    } elseif ($method === 'POST') {
        $bodyStr = json_encode($data);
    }

    $sign_str = $timestamp . $method . $requestPath . $bodyStr;
    $sign = base64_encode(hash_hmac('sha256', $sign_str, $keys['rx_sskey'], true));

    $headers = array(
        'Content-Type: application/json',
        'DC-ACCESS-KEY: ' . $keys['rx_apikey'],
        'DC-ACCESS-SIGN: ' . $sign,
        'DC-ACCESS-TIMESTAMP: ' . $timestamp,
        'DC-ACCESS-PASSPHRASE: ' . $keys['rx_password']
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyStr); }
    else { curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method); }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return array('code' => '999', 'msg' => 'cURL Error: ' . $curl_error);
    }

    $result = json_decode($response, true);

    if ($result === null || (isset($result['code']) && $result['code'] !== '0')) {
        $GLOBALS['api_debug_info'] = [
            'request_url' => $url,
            'request_method' => $method,
            'request_headers' => $headers,
            'request_body' => $bodyStr,
            'signature_str' => $sign_str,
            'response_raw' => $response
        ];
    }

    return ($result === null) ? array('code' => '999', 'msg' => 'JSON Parse Error') : $result;
}

function rx_transfer_asset($uid, $direction, $amount) {
    $keys = getMemberApiKeys($uid);
    $params = array('currency_id' => 'USDT', 'amount' => (string)$amount, 'from_id' => ($direction == 1 ? 1 : 7), 'to_id' => ($direction == 1 ? 7 : 1), 'uid' => (int)$uid);
    return callDeepcoinAPI('POST', '/deepcoin/asset/transfer', $params, $keys);
}

function rx_get_balance($uid, $instType = 'SPOT') {
    $keys = getMemberApiKeys($uid);
    return callDeepcoinAPI('GET', '/deepcoin/account/balances', array('instType' => $instType), $keys);
}

function rx_get_current_positions($uid, $symbol = '') {
    $keys = getMemberApiKeys($uid);
    $params = array('instType' => 'SWAP');
    if ($symbol) $params['instId'] = $symbol;
    return callDeepcoinAPI('GET', '/deepcoin/account/positions', $params, $keys);
}

function rx_get_history_positions($uid, $symbol = 'BTC-USDT-SWAP', $limit = 100) {
    $keys = getMemberApiKeys($uid);
    return callDeepcoinAPI('GET', '/deepcoin/trade/orders-history', array('instType' => 'SWAP', 'instId' => $symbol, 'state' => 'filled', 'limit' => $limit), $keys);
}

function rx_get_api_initial_qty($uid, $symbol) {
    if (session_status() == PHP_SESSION_NONE) session_start();
    $cache_key = "rx_init_qty_{$uid}_{$symbol}";
    if (isset($_SESSION[$cache_key]) && isset($_SESSION[$cache_key.'_time']) && (time() - $_SESSION[$cache_key.'_time'] < 60)) {
        return $_SESSION[$cache_key];
    }

    $keys = getMemberApiKeys($uid);
    $min_qty = 0;

    $res_pending = callDeepcoinAPI('GET', '/deepcoin/trade/trigger-orders-pending', array('instType' => 'SWAP', 'instId' => $symbol, 'limit' => 10), $keys);
    $res_history = callDeepcoinAPI('GET', '/deepcoin/trade/orders-history', array('instType' => 'SWAP', 'instId' => $symbol, 'state' => 'filled', 'limit' => 10), $keys);

    $all_trades = array();
    if (isset($res_pending['code']) && $res_pending['code'] === '0' && !empty($res_pending['data'])) $all_trades = array_merge($all_trades, $res_pending['data']);
    if (isset($res_history['code']) && $res_history['code'] === '0' && !empty($res_history['data'])) $all_trades = array_merge($all_trades, $res_history['data']);

    foreach ($all_trades as $trade) {
        $sz_contracts = isset($trade['sz']) ? floatval($trade['sz']) : 0;
        $multiplier = (strpos(strtoupper($symbol), 'BTC') !== false) ? 0.001 : ((strpos(strtoupper($symbol), 'ETH') !== false) ? 0.01 : ((strpos(strtoupper($symbol), 'XRP') !== false) ? 10 : 1));

        $real_qty = $sz_contracts * $multiplier;
        $real_qty = floor($real_qty * 1000) / 1000;

        if ($real_qty >= 0.001 && ($min_qty == 0 || $real_qty < $min_qty)) {
            $min_qty = $real_qty;
        }
    }

    $_SESSION[$cache_key] = $min_qty;
    $_SESSION[$cache_key.'_time'] = time();
    return $min_qty;
}

// ==================================================================
// [AJAX] 실시간 포지션 갱신 엔드포인트
// ==================================================================
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_positions') {
    header('Content-Type: application/json');
    $uid = trim($_GET['uid'] ?? '');
    $symbol = trim($_GET['symbol'] ?? '');
    if ($uid) {
        $result = rx_get_current_positions($uid, $symbol);
        if (isset($result['code']) && $result['code'] === '0' && !empty($result['data'])) {
            $filtered_data = [];
            foreach ($result['data'] as $key => $row) {
                $pos_size = floatval($row['pos'] ?? $row['sz'] ?? 0);
                if($pos_size > 0) {
                    $row['initial_qty'] = rx_get_api_initial_qty($uid, $row['instId'] ?? '');
                    $filtered_data[] = $row;
                }
            }
            echo json_encode(array('status' => true, 'data' => $filtered_data));
        } else {
            echo json_encode(array('status' => false, 'data' => array()));
        }
    } else {
        echo json_encode(array('status' => false, 'msg' => 'UID 필요'));
    }
    exit;
}

// ==================================================================
// [AJAX] 실제 자산 조회 (모달 검증용) 엔드포인트 추가 (Deepcoin)
// ==================================================================
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_balances') {
    header('Content-Type: application/json');
    $uid = trim($_GET['uid'] ?? '');
    if (!$uid && isset($s_uid)) $uid = $s_uid;

    if (!empty($uid)) {
        $actual_fut_bal = 0;
        $actual_spot_bal = 0;

        // 선물 자산 조회
        $res_fut = rx_get_balance($uid, 'SWAP');
        if (isset($res_fut['code']) && $res_fut['code'] === '0' && !empty($res_fut['data'])) {
            foreach ($res_fut['data'] as $asset) {
                $ccy = strtoupper($asset['ccy'] ?? $asset['currency'] ?? $asset['coin'] ?? '');
                if ($ccy === 'USDT') {
                    $actual_fut_bal = floatval($asset['equity'] ?? 0);
                    break;
                }
            }
        }

        // 현물 자산 조회
        $res_spot = rx_get_balance($uid, 'SPOT');
        if (isset($res_spot['code']) && $res_spot['code'] === '0' && !empty($res_spot['data'])) {
            foreach ($res_spot['data'] as $asset) {
                $ccy = strtoupper($asset['ccy'] ?? $asset['currency'] ?? $asset['coin'] ?? '');
                if ($ccy === 'USDT') {
                    $actual_spot_bal = floatval($asset['bal'] ?? $asset['eq'] ?? $asset['total'] ?? 0);
                    break;
                }
            }
        }

        echo json_encode([
            'status' => true,
            'fut_bal' => $actual_fut_bal,
            'spot_bal' => $actual_spot_bal
        ]);
    } else {
        echo json_encode(['status' => false, 'msg' => 'UID 필요']);
    }
    exit;
}

// ==================================================================
// [로직 처리 & 데이터 로드]
// ==================================================================
$current_list = []; $history_list_page = [];
$fut_total = 0; $fut_avail = 0; $spot_total = 0; $spot_free = 0;
$error_msg = ""; $fatal_error = false;

$keys_and_acount = getMemberApiKeys($s_uid);
$current_investment_amount = floatval($keys_and_acount['rx_acount'] ?? 0);

$current_spot_amount = getMemberSpotAmount($s_uid);

$current_reserve_percent = 0;
if ($s_uid) {
    $uid_safe = $conn->real_escape_string($s_uid);
    $res_reserve = @$conn->query("SELECT rx_reserve_percent FROM rx_member WHERE rx_uid = '{$uid_safe}' AND rx_ce = {$current_ce} LIMIT 1");
    if ($res_reserve && $res_reserve->num_rows > 0) {
        $row_reserve = $res_reserve->fetch_assoc();
        $current_reserve_percent = intval($row_reserve['rx_reserve_percent']);
    }
}

$pnl_today = 0; $pnl_yesterday = 0; $roi_today_pct = 0; $roi_yesterday_pct = 0;
$pnl_30days = 0; $roi_30days_pct = 0; $pnl_total = 0; $roi_total_pct = 0;
$total_trading_days = 0;
$date_30_ago = date("Y-m-d", strtotime("-30 days"));

$page = isset($_GET['page']) && $_GET['page'] > 0 ? intval($_GET['page']) : 1;
$rows_per_page = 20; $total_rows = 0; $total_pages = 0;

$cal_year  = isset($_GET['cal_year'])  ? intval($_GET['cal_year'])  : intval(date('Y'));
$cal_month = isset($_GET['cal_month']) ? intval($_GET['cal_month']) : intval(date('n'));

if (empty($keys_and_acount['rx_apikey']) || empty($keys_and_acount['rx_sskey']) || empty($keys_and_acount['rx_password'])) {
    $error_msg = t('err_api_keys_missing');
    $fatal_error = true;
}

if (!$fatal_error) {
    $res_fut = rx_get_balance($s_uid, 'SWAP');
    if (isset($res_fut['code']) && $res_fut['code'] === '0') {
        foreach ($res_fut['data'] as $asset) {
            $ccy = strtoupper($asset['ccy'] ?? $asset['currency'] ?? $asset['coin'] ?? '');
            if ($ccy === 'USDT') {
                $fut_total = floatval($asset['equity'] ?? 0);
                $fut_avail = floatval($asset['availEq'] ?? 0);
                break;
            }
        }
    } else {
        $error_msg = t('err_fut_bal_fail') . ": " . ($res_fut['msg'] ?? 'Unknown');
        $fatal_error = true;
    }

    $res_spot = rx_get_balance($s_uid, 'SPOT');
    if (isset($res_spot['code']) && $res_spot['code'] === '0') {
        foreach ($res_spot['data'] as $asset) {
            $ccy = strtoupper($asset['ccy'] ?? $asset['currency'] ?? $asset['coin'] ?? '');
            if ($ccy === 'USDT') {
                $spot_free = floatval($asset['availBal'] ?? $asset['available'] ?? 0);
                $spot_total = floatval($asset['bal'] ?? $asset['eq'] ?? $asset['total'] ?? 0);
                break;
            }
        }
    } else {
        $error_msg = t('err_spot_bal_fail') . ": " . ($res_spot['msg'] ?? 'Unknown');
        $fatal_error = true;
    }

    $res_curr = rx_get_current_positions($s_uid, $s_symbol);
    if (isset($res_curr['code']) && $res_curr['code'] === '0') {
        $current_list = [];
        if(!empty($res_curr['data'])) {
            foreach ($res_curr['data'] as $pos) {
                $pos_size = floatval($pos['pos'] ?? $pos['sz'] ?? 0);
                if($pos_size > 0) {
                    $current_list[] = $pos;
                }
            }
        }
    } else {
        $error_msg = t('err_pos_fetch') . ": " . ($res_curr['msg'] ?? 'Unknown');
        $fatal_error = true;
    }

    if (!$fatal_error) {
        $res_hist = rx_get_history_positions($s_uid, $s_symbol, 100);
        if (isset($res_hist['code']) && $res_hist['code'] === '0') {
            $api_daily_pnl = [];
            $raw_history = $res_hist['data'] ?? [];
            $filtered_history = [];

            foreach ($raw_history as $trade) {
                $side = strtolower($trade['side'] ?? '');
                $posSide = strtolower($trade['posSide'] ?? '');

                if (($posSide === 'long' && $side === 'sell') || ($posSide === 'short' && $side === 'buy')) {
                    $filtered_history[] = $trade;
                }
            }

            foreach ($filtered_history as $trade) {
                $cTime = floatval($trade['fillTime'] ?? $trade['uTime'] ?? $trade['cTime'] ?? 0);
                $trade_date_str = date("Y-m-d", $cTime / 1000);

                $raw_pnl = floatval($trade['pnl'] ?? 0);
                $close_fee = floatval($trade['fee'] ?? 0);
                $rebate = floatval($trade['rebate'] ?? 0);

                $posSide = strtolower($trade['posSide'] ?? '');
                $closePx = floatval($trade['avgPx'] ?? $trade['fillPx'] ?? 0);

                $sz = floatval($trade['sz'] ?? 0);
                $multiplier = (strpos(strtoupper($trade['instId'] ?? ''), 'BTC') !== false) ? 0.001 : ((strpos(strtoupper($trade['instId'] ?? ''), 'ETH') !== false) ? 0.01 : ((strpos(strtoupper($trade['instId'] ?? ''), 'XRP') !== false) ? 10 : 1));
                $real_qty = $sz * $multiplier;

                $openPx = $closePx;
                if ($closePx > 0 && $real_qty > 0) {
                    if ($posSide === 'long') {
                        $openPx = $closePx - ($raw_pnl / $real_qty);
                    } else {
                        $openPx = $closePx + ($raw_pnl / $real_qty);
                    }
                }

                $closeValue = $closePx * $real_qty;
                $openValue = $openPx * $real_qty;
                $feeRate = ($closeValue > 0 && $close_fee > 0) ? ($close_fee / $closeValue) : 0.0006;
                $open_fee = $openValue * $feeRate;
                $total_fee = $close_fee + $open_fee;

                $net_pnl = $raw_pnl - $total_fee + $rebate;

                if (!isset($api_daily_pnl[$trade_date_str])) $api_daily_pnl[$trade_date_str] = 0;
                $api_daily_pnl[$trade_date_str] += $net_pnl;
            }

            $uid_safe = $conn->real_escape_string($s_uid);
            $today_str_db = date("Y-m-d");

            foreach ($api_daily_pnl as $d_date => $d_pnl) {
                $week_tag = date("o-\WW", strtotime($d_date));

                $chk_sql = "SELECT investment_amount FROM rx_daily_record WHERE uid = '{$uid_safe}' AND record_date = '{$d_date}'";
                $chk_res = $conn->query($chk_sql);

                if ($chk_res && $chk_res->num_rows > 0) {
                    $row_record = $chk_res->fetch_assoc();
                    $existing_equity = floatval($row_record['investment_amount']);

                    $target_equity = ($d_date === $today_str_db) ? $current_investment_amount : $existing_equity;
                    $target_roi = ($target_equity > 0) ? ($d_pnl / $target_equity) * 100 : 0;

                    $up_sql = "UPDATE rx_daily_record SET
                               daily_pnl = {$d_pnl},
                               investment_amount = {$target_equity},
                               roi = {$target_roi},
                               updated_at = NOW()
                               WHERE uid = '{$uid_safe}' AND record_date = '{$d_date}'";
                    $conn->query($up_sql);
                } else {
                    $target_roi = ($current_investment_amount > 0) ? ($d_pnl / $current_investment_amount) * 100 : 0;
                    $ins_sql = "INSERT INTO rx_daily_record (uid, record_date, week_tag, daily_pnl, investment_amount, roi, updated_at)
                                VALUES ('{$uid_safe}', '{$d_date}', '{$week_tag}', {$d_pnl}, {$current_investment_amount}, {$target_roi}, NOW())";
                    $conn->query($ins_sql);
                }
            }

            $total_rows = count($filtered_history);
            $total_pages = ceil($total_rows / $rows_per_page);
            if ($page > $total_pages && $total_pages > 0) $page = $total_pages;
            if ($page < 1) $page = 1;
            $history_list_page = array_slice($filtered_history, ($page - 1) * $rows_per_page, $rows_per_page);

        } else {
            $error_msg = t('err_hist_fetch') . ": " . ($res_hist['msg'] ?? 'Unknown');
            $fatal_error = true;
        }
    }

    $db_daily_pnl = [];
    $db_daily_equity = [];
    $db_daily_roi = [];

    if (!$fatal_error) {
        $uid_safe = $conn->real_escape_string($s_uid);
        $sql_load = "SELECT record_date, investment_amount, daily_pnl, roi FROM rx_daily_record WHERE uid = '{$uid_safe}' ORDER BY record_date ASC";
        $res_load = $conn->query($sql_load);

        $today_str = date("Y-m-d");
        $yesterday_str = date("Y-m-d", strtotime("-1 day"));

        if ($res_load && $res_load->num_rows > 0) {
            $first_trade_date = null;
            while ($row = $res_load->fetch_assoc()) {
                $d = $row['record_date'];
                $db_daily_pnl[$d] = floatval($row['daily_pnl']);
                $db_daily_equity[$d] = floatval($row['investment_amount']);
                $db_daily_roi[$d] = floatval($row['roi']);

                if (!$first_trade_date) $first_trade_date = $d;

                if ($d == $today_str) {
                    $pnl_today = $row['daily_pnl'];
                    $roi_today_pct = $row['roi'];
                }
                if ($d == $yesterday_str) {
                    $pnl_yesterday = $row['daily_pnl'];
                    $roi_yesterday_pct = $row['roi'];
                }

                if ($d >= $date_30_ago) {
                    $pnl_30days += $row['daily_pnl'];
                }
                $pnl_total += $row['daily_pnl'];
            }

            if ($current_investment_amount > 0) {
                $roi_30days_pct = ($pnl_30days / $current_investment_amount) * 100;
                $roi_total_pct = ($pnl_total / $current_investment_amount) * 100;
            }

            $member_regdate = isset($member['mm_regdate']) ? $member['mm_regdate'] : null;
            if ($member_regdate) {
                $reg_date_str = date('Y-m-d', strtotime($member_regdate));
                $today_ts = strtotime($today_str);
                $reg_ts = strtotime($reg_date_str);
                $total_trading_days = floor(($today_ts - $reg_ts) / 86400) + 1;
                if ($total_trading_days < 1) $total_trading_days = 1;
                if ($first_trade_date === null || $reg_date_str < $first_trade_date) {
                    $first_trade_date = $reg_date_str;
                }
            } elseif ($first_trade_date) {
                $today_ts = strtotime($today_str);
                $first_ts = strtotime($first_trade_date);
                $total_trading_days = floor(($today_ts - $first_ts) / 86400) + 1;
            }

            $conn->query("UPDATE rx_member SET rx_accumulated_profit = {$pnl_total} WHERE rx_uid = '{$uid_safe}' AND rx_ce = {$current_ce}");
        }
    }
}

$txid_map = [];
if (!$fatal_error && !empty($s_uid)) {
    $uid_safe = $conn->real_escape_string($s_uid);
    $res_tx = $conn->query("SELECT week_tag, txid, amount, actual_amount, weekly_pnl, avg_equity, weekly_roi, status, regdate, last_check FROM rx_weekly_txid WHERE rx_uid = '{$uid_safe}' AND rx_ce = {$current_ce} ORDER BY week_tag DESC");
    if ($res_tx && $res_tx->num_rows > 0) {
        while($tr = $res_tx->fetch_assoc()) {
            $txid_map[$tr['week_tag']] = $tr;
        }
    }
}

function renderSnapshotCalendar($year, $month, $db_pnl, $db_roi) {
    $firstDay = mktime(0, 0, 0, $month, 1, $year);
    $daysInMonth = date('t', $firstDay);
    $startDayOfWeek = date('w', $firstDay);
    $today = date('Y-m-d');

    $html = '<div class="calendar-grid">';
    $weekdays = [t('cal_sun'), t('cal_mon'), t('cal_tue'), t('cal_wed'), t('cal_thu'), t('cal_fri'), t('cal_sat')];
    foreach ($weekdays as $idx => $day) {
        $wc = ($idx == 0) ? 'sunday' : (($idx == 6) ? 'saturday' : '');
        $html .= '<div class="calendar-weekday ' . $wc . '">' . $day . '</div>';
    }

    for ($i = 0; $i < $startDayOfWeek; $i++) { $html .= '<div class="calendar-day empty"></div>'; }

    $monthTotal = 0; $profitDays = 0; $lossDays = 0; $tradingDays = 0;

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $dayOfWeek = date('w', mktime(0, 0, 0, $month, $day, $year));

        $pnl = $db_pnl[$dateStr] ?? 0;
        $roi = $db_roi[$dateStr] ?? 0;

        if ($pnl != 0) {
            $monthTotal += $pnl;
            $tradingDays++;
            if ($pnl > 0) $profitDays++; else $lossDays++;
        }

        $dayClass = 'calendar-day';
        if ($dateStr == $today) $dayClass .= ' today';
        if ($dayOfWeek == 0) $dayClass .= ' sunday';
        if ($dayOfWeek == 6) $dayClass .= ' saturday';

        $pnlClass = ''; $pnlDisplay = '-'; $roiDisplay = '';
        if ($pnl != 0) {
            $pnlClass = $pnl > 0 ? 'profit' : 'loss';
            $pnlDisplay = ($pnl > 0 ? '+' : '') . format_truncate($pnl, 4);
            $roiDisplay = '(' . format_truncate($roi, 2) . '%)';
        }

        $html .= '<div class="' . $dayClass . '">';
        $html .= '<div class="day-number">' . $day . '</div>';
        $html .= '<div class="day-pnl ' . $pnlClass . '">' . $pnlDisplay . '</div>';
        if ($roiDisplay) $html .= '<div class="day-roi ' . $pnlClass . '">' . $roiDisplay . '</div>';
        $html .= '</div>';
    }

    $totalCells = $startDayOfWeek + $daysInMonth;
    for ($i = 0; $i < ((7 - ($totalCells % 7)) % 7); $i++) { $html .= '<div class="calendar-day empty"></div>'; }
    $html .= '</div>';

    $html .= '<div class="calendar-summary">';
    $html .= '<div class="summary-item"><span class="summary-label">' . t('cal_mon_tot') . '</span><span class="summary-value ' . ($monthTotal >= 0 ? 'profit' : 'loss') . '">' . ($monthTotal > 0 ? '+' : '') . format_truncate($monthTotal, 4) . '</span></div>';
    $html .= '<div class="summary-item"><span class="summary-label">' . t('cal_trade_days') . '</span><span class="summary-value">' . $tradingDays . t('cal_days') . '</span></div>';
    $html .= '<div class="summary-item"><span class="summary-label">' . t('cal_prof_loss') . '</span><span class="summary-value"><span class="profit">' . $profitDays . t('cal_days') . '</span> / <span class="loss">' . $lossDays . t('cal_days') . '</span></span></div>';
    $html .= '</div>';

    return $html;
}

$weekly_data = [];
if (!$fatal_error && !empty($db_daily_pnl)) {
    foreach ($db_daily_pnl as $date => $pnl) {
        $ts = strtotime($date);
        $yearWeek = date('o-W', $ts);

        if(!isset($weekly_data[$yearWeek])) {
            $dto = new DateTime();
            $dto->setISODate((int)date('o', $ts), (int)date('W', $ts));
            $mon = $dto->format('Y-m-d');
            $dto->modify('+6 days');
            $sun = $dto->format('Y-m-d');

            $weekly_data[$yearWeek] = [
                'range' => $mon . ' ~ ' . $sun,
                'pnl' => 0,
                'equity_avg' => 0,
                'days' => 0
            ];
        }
        $weekly_data[$yearWeek]['pnl'] += $pnl;
        $weekly_data[$yearWeek]['equity_avg'] += ($db_daily_equity[$date] ?? 0);
        $weekly_data[$yearWeek]['days']++;
    }
    krsort($weekly_data);
}

// 24시간 후 완료 내역 숨김 로직
foreach ($weekly_data as $yw => $data) {
    if (isset($txid_map[$yw])) {
        $tx = $txid_map[$yw];
        $status = trim($tx['status'] ?? '');
        $status_lower = strtolower($status);

        if ($status === '완료' || strpos($status_lower, '완료') !== false ||
            $status_lower === 'completed' || strpos($status_lower, 'completed') !== false ||
            $status === '승인' || strpos($status_lower, '승인') !== false ||
            $status === '입금완료' || strpos($status_lower, '입금완료') !== false) {

            $check_time = 0;
            if (!empty($tx['last_check']) && strpos($tx['last_check'], '0000-00-00') === false) {
                $check_time = strtotime($tx['last_check']);
            } elseif (!empty($tx['regdate']) && strpos($tx['regdate'], '0000-00-00') === false) {
                $check_time = strtotime($tx['regdate']);
            }

            if ($check_time > 0 && (time() - $check_time) >= 86400) {
                unset($weekly_data[$yw]);
            }
        }
    }
}

$js_weekly_data = [];
if (!empty($weekly_data)) {
    foreach ($weekly_data as $yw => $data) {
        $avg_eq = $data['days'] > 0 ? ($data['equity_avg'] / $data['days']) : 0;
        $roi = ($avg_eq > 0) ? ($data['pnl'] / $avg_eq) * 100 : 0;
        $js_weekly_data[$yw] = [
            'pnl' => $data['pnl'],
            'avg_eq' => $avg_eq,
            'roi' => $roi
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang ?? 'ko'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deepcoin <?= t('dash_title') ?> - <?php echo h($s_uid); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; padding: 20px 15px; font-family: 'Noto Sans KR', sans-serif; }
        .container { max-width: 1200px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; }

        .text-long { color: #28a745 !important; font-weight: bold; }
        .text-short { color: #dc3545 !important; font-weight: bold; }
        .text-profit { color: #28a745 !important; font-weight: bold; }
        .text-loss { color: #dc3545 !important; font-weight: bold; }

        .section-title { font-weight: 700; margin-bottom: 15px; color: #343a40; border-left: 5px solid #0dcaf0; padding-left: 10px; }
        .card-fut { background: linear-gradient(135deg, #0dcaf0 0%, #087990 100%); color: white; }
        .card-spot { background: linear-gradient(135deg, #fd7e14 0%, #d96203 100%); color: white; }
        .balance-value { font-size: 1.5rem; font-weight: bold; }
        .pagination { justify-content: center; margin-top: 20px; }
        .page-link { color: #333; }
        .page-item.active .page-link { background-color: #0dcaf0; border-color: #0dcaf0; color: #fff; }

        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .top-bar .user-info { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        .top-bar .user-badge { background: #0dcaf0; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
        .top-bar .exchange-badge { background: #ffc107; color: #000; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
        .btn-sm-custom { padding: 4px 12px; font-size: 0.8rem; border-radius: 6px; }

        .error-box { background: #fff3cd; border: 2px solid #ffc107; border-radius: 12px; padding: 30px; text-align: center; margin-bottom: 20px; }
        .error-box .error-icon { font-size: 3rem; margin-bottom: 15px; }
        .error-box .error-title { font-size: 1.3rem; font-weight: 700; color: #856404; margin-bottom: 10px; }
        .error-box .error-detail { color: #664d03; font-size: 0.95rem; }

        .vertical-bar { display: inline-block; width: 8px; height: 30px; margin-right: 8px; vertical-align: middle; border-radius: 1px; }
        .vertical-bar.long { background-color: #28a745; }
        .vertical-bar.short { background-color: #dc3545; }

        .calendar-container { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); padding: 20px; margin-bottom: 20px; }
        .calendar-header { text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e9ecef; }
        .calendar-header h4 { color: #343a40; font-weight: 700; }
        .calendar-nav { display: flex; justify-content: center; align-items: center; flex-wrap: wrap; gap: 10px; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .calendar-weekday { text-align: center; font-weight: 700; padding: 10px 5px; background: #f8f9fa; border-radius: 6px; font-size: 0.85rem; }
        .calendar-weekday.sunday { color: #dc3545; }
        .calendar-weekday.saturday { color: #0d6efd; }

        .calendar-day { min-height: 80px; padding: 8px; border: 1px solid #e9ecef; border-radius: 8px; background: #fff; position: relative; z-index: 1; }
        .calendar-day.empty { background: #f8f9fa; border: 1px dashed #dee2e6; cursor: default; }
        .calendar-day.today { border: 2px solid #0dcaf0; background: #e0f8fb; }
        .calendar-day.sunday .day-number { color: #dc3545; }
        .calendar-day.saturday .day-number { color: #0d6efd; }
        .calendar-day:not(.empty) { cursor: pointer; }
        .calendar-day:hover:not(.empty):not(.zoomed) { box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 10; background-color: #f8f9fa; }
        .calendar-day.zoomed { transform: scale(3.2); transform-origin: center center; z-index: 1050 !important; box-shadow: 0 10px 25px rgba(0,0,0,0.2) !important; background: #fff !important; }

        .day-number { font-weight: 700; font-size: 0.9rem; color: #495057; margin-bottom: 4px; }
        .day-pnl { font-size: 0.75rem; font-weight: 600; text-align: center; }
        .day-pnl.profit { color: #28a745; }
        .day-pnl.loss { color: #dc3545; }
        .day-roi { font-size: 0.65rem; text-align: center; margin-top: 2px; }
        .day-roi.profit { color: #28a745; }
        .day-roi.loss { color: #dc3545; }

        .calendar-summary { display: flex; justify-content: space-around; flex-wrap: wrap; margin-top: 20px; padding-top: 20px; border-top: 2px solid #e9ecef; }
        .summary-item { text-align: center; padding: 10px 20px; }
        .summary-label { display: block; font-size: 0.8rem; color: #6c757d; margin-bottom: 5px; }
        .summary-value { font-size: 1.1rem; font-weight: 700; color: #343a40; }
        .summary-value.profit { color: #28a745; }
        .summary-value.loss { color: #dc3545; }

        .summary-card { background: #fff; border-radius: 12px; text-align: center; border: 1px solid #e9ecef; transition: all 0.2s; min-height: 140px; display: flex; flex-direction: column; justify-content: center; }
        .summary-card:hover { box-shadow: 0 4px 15px rgba(0,0,0,0.08); transform: translateY(-2px); }
        .sc-header { display: flex; align-items: center; justify-content: center; gap: 6px; margin-bottom: 4px; }
        .sc-icon { font-size: 1.1rem; }
        .sc-title { font-size: 0.8rem; font-weight: 700; color: #495057; }
        .sc-date { font-size: 0.7rem; color: #adb5bd; margin-bottom: 6px; }
        .sc-value { font-size: 1.25rem; font-weight: 800; margin-bottom: 4px; }
        .sc-value.profit { color: #28a745; }
        .sc-value.loss { color: #dc3545; }
        .sc-sub { font-size: 0.7rem; font-weight: 600; }
        .sc-sub.profit { color: #28a745; }
        .sc-sub.loss { color: #dc3545; }

        /* 복사 영역 스타일 추가 */
        .deposit-address-box {
            background-color: #e9ecef;
            border: 1px solid #ced4da;
            border-radius: 8px;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .deposit-address-text {
            font-family: monospace;
            font-size: 1.1rem;
            font-weight: bold;
            color: #087990;
            word-break: break-all;
        }

        @media (max-width: 768px) {
            .balance-value { font-size: 1.2rem; }
            .calendar-day { min-height: 60px; padding: 4px; }
            .day-number { font-size: 0.75rem; }
            .day-pnl { font-size: 0.65rem; }
            .day-roi { font-size: 0.55rem; }
            .calendar-day.zoomed { transform: scale(2.5); }
            .calendar-summary { flex-direction: column; }
            .summary-item { padding: 8px 10px; border-bottom: 1px solid #e9ecef; }
            .calendar-nav { flex-direction: column; }
            .calendar-nav h4 { order: -1; margin-bottom: 10px !important; font-size: 1rem; }
            .summary-card { min-height: 120px; padding: 10px !important; }
            .sc-value { font-size: 1.05rem; }
            .sc-title { font-size: 0.75rem; }
            .sc-date { font-size: 0.65rem; }
            .sc-sub { font-size: 0.65rem; }
            .top-bar h2 { font-size: 1.2rem; }
            .deposit-address-box { flex-direction: column; align-items: stretch; gap: 10px; text-align: center; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="top-bar">
        <h2 class="fw-bold mb-0">📊 <?= t('dash_title') ?></h2>
        <div class="user-info d-flex align-items-center gap-3">
            <?= render_language_selector() ?>
            <span class="exchange-badge">Deepcoin</span>
            <span class="user-badge">UID: <?php echo h($s_uid); ?></span>

            <?php if (!$admin_view): ?>
            <a href="change_password.php" class="btn btn-outline-secondary btn-sm-custom">🔐 <?= t('dash_chg_pwd') ?></a>
            <!-- 일반 회원 대시보드 상단에서 입금 통계 링크 삭제 -->
            <a href="logout.php" class="btn btn-outline-danger btn-sm-custom"><?= t('dash_logout') ?></a>
            <?php else: ?>
            <span class="badge bg-warning text-dark">👁️ <?= t('dash_admin_mode') ?></span>
            <a href="admin/member_list.php" class="btn btn-outline-secondary btn-sm-custom">← <?= t('dash_member_list') ?></a>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $alert_msg = "";
    $alert_type = "success";
    if (isset($_GET['msg'])) {
        $msg_val = $_GET['msg'];
        if ($msg_val === 'update_ok') {
            $alert_msg = t('msg_update_ok');
        } elseif ($msg_val === 'txid_ok') {
            $alert_msg = "주간 입금 기록이 성공적으로 전송되었습니다. (관리자 검증 대기중)";
        } elseif (strpos($msg_val, 'trans_ok_') === 0) {
            $amt = htmlspecialchars(urldecode(substr($msg_val, 9)));
            $alert_msg = t('msg_trans_ok_amt') . " {$amt} USDT";
        } elseif (strpos($msg_val, 'trans_fail_') === 0) {
            $fail_reason = htmlspecialchars(urldecode(substr($msg_val, 11)));
            $alert_msg = t('msg_trans_fail_rsn') . " {$fail_reason}";
            $alert_type = "danger";
        } elseif (strpos($msg_val, 'db_error_') === 0) {
            $fail_reason = htmlspecialchars(urldecode(substr($msg_val, 9)));
            $alert_msg = t('msg_db_error') . " {$fail_reason}";
            $alert_type = "danger";
        }
    }
    if ($alert_msg):
    ?>
    <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show shadow-sm" role="alert" style="border-radius: 10px; font-weight: 500;">
        <?php echo $alert_type === 'success' ? '✅' : '❌'; ?> <?php echo $alert_msg; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <script>
        if(history.replaceState) {
            let url = new URL(window.location.href);
            url.searchParams.delete('msg');
            history.replaceState(null, '', url.toString());
        }
    </script>
    <?php endif; ?>

    <div class="card p-3 mb-3" style="display:none;">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="s_uid" value="<?php echo h($s_uid); ?>">
            <input type="hidden" name="cal_year" value="<?php echo $cal_year; ?>">
            <input type="hidden" name="cal_month" value="<?php echo $cal_month; ?>">
            <?php if ($admin_view): ?>
                <input type="hidden" name="admin_view" value="1">
                <input type="hidden" name="admin_uid" value="<?php echo htmlspecialchars($admin_uid); ?>">
                <input type="hidden" name="admin_ce" value="<?php echo $current_ce; ?>">
            <?php endif; ?>
            <div class="col-md-4">
                <label class="form-label fw-bold"><?= t('lbl_sym') ?></label>
                <input type="text" name="s_symbol" class="form-control" placeholder="<?= t('ph_sym') ?>" value="<?php echo h($s_symbol); ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100 fw-bold"><?= t('btn_search') ?></button>
            </div>
        </form>
    </div>

    <?php if ($s_uid): ?>
        <ul class="nav nav-tabs fw-bold mb-4" id="dashboardTabs" role="tablist">
            <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-dash" type="button" role="tab"><?= t('tab_dash') ?></button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-cal" type="button" role="tab"><?= t('tab_cal') ?></button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-week" type="button" role="tab"><?= t('tab_week') ?></button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-history" type="button" role="tab"><?= t('tab_history') ?></button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-settings" type="button" role="tab"><?= t('tab_settings_api') ?></button></li>
        </ul>

        <div class="tab-content" id="dashboardTabsContent">
            <div class="tab-pane fade show active" id="tab-dash" role="tabpanel">

                <?php if ($fatal_error): ?>
                    <div class="error-box">
                        <div class="error-icon">⚠️</div>
                        <div class="error-title"><?= t('err_data_load') ?></div>
                        <div class="error-detail"><?php echo h($error_msg); ?></div>
                        <p class="mt-3 mb-0 text-muted" style="font-size:0.9rem;"><?= t('err_data_load_desc') ?></p>
                        <div class="mt-3">
                            <a href="<?php echo htmlspecialchars($current_page); ?>?s_uid=<?php echo urlencode($s_uid); ?>&s_symbol=<?php echo urlencode($s_symbol); ?><?php echo $admin_view ? '&admin_view=1&admin_uid='.urlencode($admin_uid).'&admin_ce='.$current_ce : ''; ?>" class="btn btn-warning">🔄 <?= t('btn_retry') ?></a>
                        </div>
                    </div>
                    <?php if ($GLOBALS['api_debug_info'] !== null): ?>
                    <div class="card p-3 mt-3 bg-dark text-light text-start" style="font-size: 0.8rem; overflow-x: auto;">
                        <h6 class="text-warning fw-bold">API Debug Info</h6>
                        <pre><code><?php echo htmlspecialchars(print_r($GLOBALS['api_debug_info'], true)); ?></code></pre>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="row mb-3 g-2">
                    <div class="col-6 col-md-3">
                        <div class="card p-3 summary-card">
                            <div class="sc-header"><span class="sc-icon">📈</span><span class="sc-title"><?= t('lbl_today_pnl') ?></span></div>
                            <div class="sc-date"><?php echo date('m/d'); ?></div>
                            <div class="sc-value <?php echo $pnl_today >= 0 ? 'profit' : 'loss'; ?>">
                                <?php echo ($pnl_today > 0 ? '+' : '') . format_truncate($pnl_today, 4); ?>
                            </div>
                            <div class="sc-sub <?php echo $roi_today_pct >= 0 ? 'profit' : 'loss'; ?>">ROI <?php echo format_truncate($roi_today_pct, 2); ?>%</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card p-3 summary-card">
                            <div class="sc-header"><span class="sc-icon">📉</span><span class="sc-title"><?= t('lbl_yest_pnl') ?></span></div>
                            <div class="sc-date"><?php echo date('m/d', strtotime('-1 day')); ?></div>
                            <div class="sc-value <?php echo $pnl_yesterday >= 0 ? 'profit' : 'loss'; ?>">
                                <?php echo ($pnl_yesterday > 0 ? '+' : '') . format_truncate($pnl_yesterday, 4); ?>
                            </div>
                            <div class="sc-sub <?php echo $roi_yesterday_pct >= 0 ? 'profit' : 'loss'; ?>">ROI <?php echo format_truncate($roi_yesterday_pct, 2); ?>%</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card p-3 summary-card">
                            <div class="sc-header"><span class="sc-icon">📆</span><span class="sc-title"><?= t('lbl_30d_pnl') ?></span></div>
                            <div class="sc-date"><?php echo h($date_30_ago); ?> ~</div>
                            <div class="sc-value <?php echo $pnl_30days >= 0 ? 'profit' : 'loss'; ?>">
                                <?php echo ($pnl_30days > 0 ? '+' : '') . format_truncate($pnl_30days, 4); ?>
                            </div>
                            <div class="sc-sub <?php echo $roi_30days_pct >= 0 ? 'profit' : 'loss'; ?>"><?= t('lbl_roi_vs') ?> <?php echo format_truncate($roi_30days_pct, 2); ?>%</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card p-3 summary-card">
                            <div class="sc-header"><span class="sc-icon">⏱️</span><span class="sc-title"><?= t('lbl_total_pnl') ?></span></div>
                            <div class="sc-date"><?php echo $first_trade_date ? h($first_trade_date) : t('lbl_no_record'); ?> ~ (<?= t('lbl_total_days_pre') ?> <?php echo number_format($total_trading_days); ?><?= t('lbl_total_days_post') ?>)</div>
                            <div class="sc-value" style="color:#0d6efd;">
                                <?php echo ($pnl_total > 0 ? '+' : '') . format_truncate($pnl_total, 4); ?>
                            </div>
                            <div class="sc-sub <?php echo $roi_total_pct >= 0 ? 'profit' : 'loss'; ?>"><?= t('lbl_roi_vs') ?> <?php echo format_truncate($roi_total_pct, 2); ?>%</div>
                        </div>
                    </div>
                </div>

                <h4 class="section-title mt-4">💰 <?= t('title_wallet') ?></h4>
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card p-3 card-fut">
                            <div class="d-flex justify-content-between"><span>⚡ <?= t('lbl_fut_wallet') ?></span> <small><?= t('lbl_base_amt') ?>: <?php echo format_truncate($current_investment_amount, 0); ?> USDT</small></div>
                            <div class="balance-value mt-2"><?php echo format_truncate($fut_total, 4); ?></div>
                            <div class="mt-1 opacity-75"><?= t('lbl_use_avail') ?>: <?php echo format_truncate($fut_avail, 4); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card p-3 card-spot">
                            <div class="d-flex justify-content-between"><span>🏦 <?= t('lbl_spot_wallet') ?></span> <small><?= t('lbl_reserve_rate') ?>: <?php echo $current_reserve_percent; ?>%</small></div>
                            <div class="balance-value mt-2"><?php echo format_truncate($spot_total, 4); ?></div>
                            <div class="mt-1 opacity-75"><?= t('lbl_use_avail') ?>: <?php echo format_truncate($spot_free, 4); ?></div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($current_list)): ?>
                    <h4 class="section-title text-primary" id="current-position-title">⚡ <?= t('title_curr_pos') ?></h4>
                    <div class="card table-responsive p-0">
                        <table class="table table-hover mb-0 text-center align-middle" id="current-position-table">
                            <thead class="table-light">
                                <tr>
                                    <th><?= t('th_symbol') ?></th><th><?= t('th_position') ?></th><th><?= t('th_leverage') ?></th><th><?= t('th_init_qty') ?></th><th><?= t('th_entry_qty') ?></th><th><?= t('th_martin') ?></th>
                                    <th><?= t('th_entry_px') ?></th><th><?= t('th_curr_px') ?></th><th><?= t('th_liq_px') ?></th><th><?= t('th_unreal_pnl') ?></th>
                                    <th><span id="refresh-counter" class="badge bg-secondary mb-1" style="font-size: 0.75rem; font-weight: normal;"><?= t('lbl_refresh_5s') ?></span><br><?= t('th_roi') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($current_list as $row):
                                    $symbol_full = strtoupper($row['instId'] ?? '');
                                    $symbol_short = explode('-', $symbol_full)[0];
                                    $multiplier = (strpos($symbol_full, 'BTC') !== false) ? 0.001 : ((strpos($symbol_full, 'ETH') !== false) ? 0.01 : ((strpos($symbol_full, 'XRP') !== false) ? 10 : 1));

                                    $real_qty = floatval($row['pos'] ?? $row['sz'] ?? 0) * $multiplier;
                                    // 진입수량 소수점 3자리 내림 표기
                                    $real_qty = floor($real_qty * 1000) / 1000;

                                    $initial_qty = rx_get_api_initial_qty($s_uid, $symbol_full);

                                    $martingale_count = 0;
                                    if ($initial_qty > 0 && $real_qty > 0) {
                                        $martingale_count = round($real_qty / $initial_qty);
                                    }
                                    $martingale_text = $martingale_count > 0 ? "<span class=\"badge bg-success\">{$martingale_count}" . t('lbl_times') . "</span>" : "-";

                                    $avgPrice = floatval($row['avgPx'] ?? 0);
                                    $markPrice = floatval($row['lastPx'] ?? $row['markPx'] ?? 0);
                                    $netUnrealizedPnL = floatval($row['unrealizedProfit'] ?? $row['upl'] ?? 0);

                                    $lever = floatval($row['lever'] ?? 50);
                                    if ($lever <= 0) $lever = 50;

                                    $openValue = $avgPrice * $real_qty;
                                    $margin = ($openValue > 0) ? ($openValue / $lever) : 0;
                                    $netRoi = ($margin > 0) ? ($netUnrealizedPnL / $margin) : 0;

                                    $sideDisplay = ($row['posSide'] === 'long') ? 'LONG' : 'SHORT';
                                    $sideClass = ($sideDisplay === 'LONG') ? 'text-long' : 'text-short';
                                    $pnlClass = ($netUnrealizedPnL >= 0) ? 'text-profit' : 'text-loss';
                                    $barClass = ($sideDisplay === 'LONG') ? 'long' : 'short';
                                ?>
                                <tr>
                                    <td class="fw-bold text-start ps-4"><span class="vertical-bar <?php echo $barClass; ?>"></span><?php echo h($symbol_short); ?></td>
                                    <td class="<?php echo $sideClass; ?>"><?php echo h($sideDisplay); ?></td>
                                    <td>x<?php echo h($lever); ?></td>
                                    <td><?php echo format_truncate($initial_qty, 3); ?></td>
                                    <td><?php echo format_truncate($real_qty, 3); ?></td>
                                    <td><?php echo $martingale_text; ?></td>
                                    <td><?php echo format_truncate($avgPrice, 1); ?></td>
                                    <td><?php echo format_truncate($markPrice, 1); ?></td>
                                    <td class="text-danger"><?php echo format_truncate($row['liqPx'] ?? 0, 1); ?></td>
                                    <td class="<?php echo $pnlClass; ?> fw-bold"><?php echo ($netUnrealizedPnL > 0 ? '+' : '') . format_truncate($netUnrealizedPnL, 4); ?></td>
                                    <td class="<?php echo $pnlClass; ?>"><?php echo ($netRoi > 0 ? '+' : '') . format_truncate($netRoi * 100, 2); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="card p-3 text-center text-muted"><p class="mb-0"><?= t('msg_no_pos') ?></p></div>
                <?php endif; ?>

                <?php if (!empty($history_list_page)): ?>
                    <h4 class="section-title text-secondary mt-4">📜 <?= t('title_hist_pos') ?></h4>
                    <div class="card table-responsive p-0">
                        <table class="table table-hover mb-0 text-center align-middle">
                            <thead class="table-dark">
                                <!-- [수정완료] 심볼 항목 삭제, 진입수량 헤더 추가 -->
                                <tr>
                                    <th><?= t('th_close_time') ?></th><th><?= t('th_entry_qty') ?? '진입수량' ?></th><th><?= t('th_position') ?></th><th><?= t('th_leverage') ?></th>
                                    <th><?= t('th_entry_px') ?></th><th><?= t('th_close_px') ?></th><th><?= t('th_real_pnl') ?></th><th><?= t('th_roi') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history_list_page as $row):
                                    $posSide = strtolower($row['posSide'] ?? '');
                                    $sideDisplay = ($posSide === 'long') ? 'LONG' : 'SHORT';
                                    $sideClass = ($sideDisplay === 'LONG') ? 'text-long' : 'text-short';

                                    $closeTimeTs = isset($row['fillTime']) && floatval($row['fillTime']) > 0 ? floatval($row['fillTime']) : floatval($row['uTime'] ?? $row['cTime'] ?? 0);
                                    $closeTimeDisplay = $closeTimeTs > 0 ? date('Y-m-d H:i:s', $closeTimeTs / 1000) : '-';

                                    $raw_pnl = floatval($row['pnl'] ?? 0);
                                    $close_fee = floatval($row['fee'] ?? 0);
                                    $rebate = floatval($row['rebate'] ?? 0);

                                    $closePx = floatval($row['avgPx'] ?? $row['fillPx'] ?? 0);

                                    $sz = floatval($row['sz'] ?? 0);
                                    $multiplier = (strpos(strtoupper($row['instId'] ?? ''), 'BTC') !== false) ? 0.001 : ((strpos(strtoupper($row['instId'] ?? ''), 'ETH') !== false) ? 0.01 : ((strpos(strtoupper($row['instId'] ?? ''), 'XRP') !== false) ? 10 : 1));
                                    $real_qty = $sz * $multiplier;

                                    $real_qty = floor($real_qty * 1000) / 1000;

                                    $openPx = $closePx;
                                    if ($closePx > 0 && $real_qty > 0) {
                                        if ($posSide === 'long') {
                                            $openPx = $closePx - ($raw_pnl / $real_qty);
                                        } else {
                                            $openPx = $closePx + ($raw_pnl / $real_qty);
                                        }
                                    }

                                    $closeValue = $closePx * $real_qty;
                                    $openValue = $openPx * $real_qty;
                                    $feeRate = ($closeValue > 0 && $close_fee > 0) ? ($close_fee / $closeValue) : 0.0006;
                                    $open_fee = $openValue * $feeRate;
                                    $total_fee = $close_fee + $open_fee;

                                    $net_pnl = $raw_pnl - $total_fee + $rebate;
                                    $pnlClass = ($net_pnl >= 0) ? 'text-profit' : 'text-loss';

                                    $lever = floatval($row['lever'] ?? 50);
                                    if ($lever <= 0) $lever = 50;

                                    $openValueForMargin = $openPx * $real_qty;
                                    $margin = ($openValueForMargin > 0) ? ($openValueForMargin / $lever) : 0;
                                    $roiRate = ($margin > 0) ? ($net_pnl / $margin) * 100 : 0;
                                ?>
                                <tr>
                                    <td><?php echo $closeTimeDisplay; ?></td>
                                    <td><?php echo format_truncate($real_qty, 3); ?></td>
                                    <td class="<?php echo $sideClass; ?>"><?php echo h($sideDisplay); ?></td>
                                    <td>x<?php echo h($lever); ?></td>
                                    <td><?php echo format_truncate($openPx, 1); ?></td>
                                    <td><?php echo format_truncate($closePx, 1); ?></td>
                                    <td class="<?php echo $pnlClass; ?> fw-bold"><?php echo ($net_pnl > 0 ? '+' : '') . format_truncate($net_pnl, 4); ?></td>
                                    <td class="<?php echo $pnlClass; ?>"><?php echo ($roiRate > 0 ? '+' : '') . format_truncate($roiRate, 2); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php
                    $get_pagging_add = "s_uid=" . urlencode($s_uid) . "&s_symbol=" . urlencode($s_symbol) . "&";
                    if($admin_view == 1){
                        $get_pagging_add .= "admin_view=1&admin_uid=" . urlencode($admin_uid) . "&admin_ce=" . $current_ce . "&";
                    }
                    if ($total_pages > 1):
                    ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination mt-3">
                            <?php if ($page > 1): ?>
                            <li class="page-item"><a class="page-link" href="?<?=$get_pagging_add?>page=<?php echo $page - 1; ?>&cal_year=<?php echo $cal_year; ?>&cal_month=<?php echo $cal_month; ?>"><?= t('btn_prev') ?></a></li>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);

                            if ($start_page > 1): ?>
                            <li class="page-item"><a class="page-link" href="?<?=$get_pagging_add?>page=1&cal_year=<?php echo $cal_year; ?>&cal_month=<?php echo $cal_month; ?>">1</a></li>
                            <?php if ($start_page > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>"><a class="page-link" href="?<?=$get_pagging_add?>page=<?php echo $i; ?>&cal_year=<?php echo $cal_year; ?>&cal_month=<?php echo $cal_month; ?>"><?php echo $i; ?></a></li>
                            <?php endfor; ?>

                            <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                            <li class="page-item"><a class="page-link" href="?<?=$get_pagging_add?>page=<?php echo $total_pages; ?>&cal_year=<?php echo $cal_year; ?>&cal_month=<?php echo $cal_month; ?>"><?php echo $total_pages; ?></a></li>
                            <?php endif; ?>

                            <?php if ($page < $total_pages): ?>
                            <li class="page-item"><a class="page-link" href="?<?=$get_pagging_add?>page=<?php echo $page + 1; ?>&cal_year=<?php echo $cal_year; ?>&cal_month=<?php echo $cal_month; ?>"><?= t('btn_next') ?></a></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="card p-3 text-center text-muted mt-4"><p class="mb-0"><?= t('msg_no_hist') ?></p></div>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade" id="tab-cal" role="tabpanel">
                <h4 class="section-title">📅 <?= t('title_cal') ?></h4>
                <?php
                $bParam = "s_uid=" . urlencode($s_uid) . "&s_symbol=" . urlencode($s_symbol);
                if ($admin_view) $bParam .= "&admin_view=1&admin_uid=" . urlencode($admin_uid) . "&admin_ce=" . $current_ce;

                $prevUrl = "?{$bParam}&cal_year=".($cal_month==1?$cal_year-1:$cal_year)."&cal_month=".($cal_month==1?12:$cal_month-1)."#tab-cal";
                $nextUrl = "?{$bParam}&cal_year=".($cal_month==12?$cal_year+1:$cal_year)."&cal_month=".($cal_month==12?1:$cal_month+1)."#tab-cal";
                $todayUrl = "?{$bParam}&cal_year=".date('Y')."&cal_month=".date('n')."#tab-cal";
                ?>
                <div class="calendar-container" id="rxCalendar">
                    <div class="calendar-header">
                        <div class="calendar-nav">
                            <a href="<?php echo $prevUrl;?>" class="btn btn-outline-primary btn-sm" onclick="showTab('tab-cal')"><?= t('btn_prev_mo') ?></a>
                            <h4 class="mb-0 mx-3"><?php echo $cal_year; ?><?= t('cal_year') ?> <?php echo sprintf('%02d', $cal_month); ?><?= t('cal_month') ?></h4>
                            <a href="<?php echo $nextUrl;?>" class="btn btn-outline-primary btn-sm" onclick="showTab('tab-cal')"><?= t('btn_next_mo') ?></a>
                        </div>
                        <div class="mt-2"><a href="<?php echo $todayUrl;?>" class="btn btn-sm btn-secondary"><?= t('btn_go_today') ?></a></div>
                    </div>
                    <?php echo renderSnapshotCalendar($cal_year, $cal_month, $db_daily_pnl, $db_daily_roi); ?>
                </div>
            </div>

            <div class="tab-pane fade" id="tab-week" role="tabpanel">

                <div class="deposit-address-box">
                    <div>
                        <span class="text-secondary fw-bold">DeepCoin Deposit Address :</span>
                        <span id="depositAddress" class="deposit-address-text ms-2"><?php echo htmlspecialchars($global_deposit_address); ?></span>
                    </div>
                    <button type="button" class="btn btn-sm btn-primary fw-bold" onclick="copyDepositAddress()">
                        📋 주소 복사
                    </button>
                </div>

                <div class="row">
                    <div class="col-lg-8 mb-3">
                        <h4 class="section-title">📊 <?= t('title_week_roi') ?></h4>
                        <div class="card p-3 h-100">
                            <?php if(empty($weekly_data)): ?>
                                <p class='text-center text-muted p-4'><?= t('msg_no_data') ?></p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover text-center align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th><?= t('th_week_range') ?></th>
                                                <th><?= t('th_week_pnl') ?></th>
                                                <th>Subscription Fee (20%)</th>
                                                <th><?= t('th_week_roi') ?></th>
                                                <th><?= t('th_txid') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($weekly_data as $yw => $data):
                                            $avg_eq = $data['days'] > 0 ? ($data['equity_avg'] / $data['days']) : 0;
                                            $roi = ($avg_eq > 0) ? ($data['pnl'] / $avg_eq) * 100 : 0;

                                            // [수정 포인트] 주간수익과 구독료 20%를 모두 화면에서도 소수점 2자리로 통일합니다.
                                            $pnl_val = round($data['pnl'], 2);
                                            $sub_fee_display = ($pnl_val > 0) ? round($pnl_val * 0.20, 2) : 0;

                                            $pnlClass = $pnl_val >= 0 ? 'text-success fw-bold' : 'text-danger fw-bold';
                                            $has_tx = isset($txid_map[$yw]);
                                            $tx = $has_tx ? $txid_map[$yw] : null;
                                            $txid_display = $has_tx ? '<span class="badge bg-success">저장됨</span>' : '<span class="text-muted">-</span>';
                                        ?>
                                        <tr>
                                            <td><?php echo $data['range']; ?></td>
                                            <td class="<?php echo $pnlClass; ?>"><?php echo ($pnl_val > 0 ? '+' : '') . number_format($pnl_val, 2); ?></td>
                                            <td><?php echo number_format($sub_fee_display, 2); ?> USDT</td>
                                            <td class="<?php echo $pnlClass; ?>"><?php echo ($roi > 0 ? '+' : '') . format_truncate($roi, 2); ?>%</td>
                                            <td class="fw-bold"><?php echo $txid_display; ?></td>
                                        </tr>

                                        <!-- 해당 주간 바로 아래에 한 줄로 상세 내역 추가 -->
                                        <?php if ($has_tx): ?>
                                        <tr style="background-color: #f8f9fa;">
                                            <td colspan="5" class="text-start py-3 border-top-0 text-muted" style="padding-left: 20px;">
                                                <div class="d-flex align-items-start flex-wrap gap-4 ms-2">
                                                    <div>
                                                        <span class="fw-bold text-dark"><i class="fs-6">📥</i> Deposit Details</span>
                                                    </div>
                                                    <div>
                                                        <strong>Status:</strong>
                                                        <?php
                                                            // DB에 저장된 상태 (대기, 완료 등)
                                                            $status_text = $tx['status'] ?? '입금대기';
                                                            if ($status_text === '대기') $status_text = '입금대기';

                                                            $status_lower = strtolower(trim($status_text));
                                                            $is_completed = ($status_lower === 'completed' || $status_lower === '완료' || $status_lower === '입금완료');

                                                            $display_status = $is_completed ? 'Completed' : 'Pending';
                                                            $status_class = $is_completed ? 'bg-primary' : 'bg-warning text-dark';

                                                            // [금액 검증 로직] 완료 상태일 때, 요구 구독료와 실제 트론입금액 비교
                                                            $is_mismatch = false;
                                                            $req_fee = floatval($tx['amount']);
                                                            $act_fee = floatval($tx['actual_amount']);

                                                            if ($is_completed && abs($req_fee - $act_fee) >= 0.01) {
                                                                $is_mismatch = true;
                                                            }
                                                        ?>
                                                        <span class="badge <?php echo $status_class; ?> ms-1"><?php echo $display_status; ?></span>
                                                    </div>
                                                    <div>
                                                        <strong>TXID:</strong>
                                                        <a href="https://tronscan.org/#/transaction/<?php echo urlencode($tx['txid']); ?>" target="_blank" class="text-primary text-decoration-none fw-bold" title="트론스캔에서 트랜잭션 상세 보기"><?php echo h($tx['txid']); ?> 🔗</a>
                                                        <div class="text-muted mt-1" style="font-size: 0.75rem;">
                                                            Saved at: <?php echo date('Y-m-d H:i:s', strtotime($tx['regdate'])); ?>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <strong>Deposit Amount:</strong>
                                                        <span class="text-success fw-bold ms-1" style="font-size: 1.05rem;">
                                                            <?php echo $is_completed ? number_format($act_fee, 2) . ' USDT' : '검증 대기중'; ?>
                                                        </span>
                                                        <?php if($is_mismatch): ?>
                                                            <br><span class="badge bg-danger mt-1">금액 불일치 (예상: <?php echo number_format($req_fee, 2); ?> USDT)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-lg-4 mb-3">
                        <h4 class="section-title">🔗 <?= t('title_txid_input') ?></h4>
                        <div class="card p-4 h-100">
                            <form method="POST" action="?s_uid=<?php echo urlencode($s_uid); ?>&s_symbol=<?php echo urlencode($s_symbol); ?>#tab-week">
                                <input type="hidden" name="act" value="save_txid">
                                <?php if ($admin_view): ?>
                                    <input type="hidden" name="admin_view" value="1">
                                    <input type="hidden" name="admin_uid" value="<?php echo htmlspecialchars($admin_uid); ?>">
                                    <input type="hidden" name="admin_ce" value="<?php echo $current_ce; ?>">
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label class="form-label fw-bold"><?= t('lbl_select_week') ?></label>
                                    <select name="week_tag" id="week_tag_select" class="form-select" onchange="updateWeeklyData()" required>
                                        <option value=""><?= t('opt_select_week') ?></option>
                                        <?php foreach($weekly_data as $yw => $data): ?>
                                            <option value="<?php echo h($yw); ?>"><?php echo h($data['range']); ?> (<?php echo h($yw); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label fw-bold"><?= t('lbl_input_txid') ?></label>
                                    <input type="text" name="txid" class="form-control" placeholder="<?= t('ph_txid') ?>" required>
                                </div>

                                <input type="hidden" name="weekly_pnl" id="hidden_weekly_pnl" value="0">
                                <input type="hidden" name="avg_equity" id="hidden_avg_equity" value="0">
                                <input type="hidden" name="weekly_roi" id="hidden_weekly_roi" value="0">

                                <button type="submit" class="btn btn-primary w-100 fw-bold"><?= t('btn_save_txid') ?? '저장하기' ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="tab-history" role="tabpanel">
                <h4 class="section-title">🕒 <?= t('title_hist_amt') ?></h4>
                <div class="card table-responsive p-0">
                    <table class="table table-hover text-center align-middle mb-0">
                        <thead class="table-light"><tr><th><?= t('th_chg_time') ?></th><th><?= t('th_type') ?></th><th><?= t('th_amt_before') ?></th><th><?= t('th_amt_after') ?></th></tr></thead>
                        <tbody>
                        <?php
                        $hist_page = isset($_GET['hist_page']) && $_GET['hist_page'] > 0 ? intval($_GET['hist_page']) : 1;
                        $hist_per_page = 10;
                        $uid_escaped = $conn->real_escape_string($s_uid);

                        $hist_count_sql = "SELECT COUNT(*) as cnt FROM rx_account_history WHERE rx_uid = '{$uid_escaped}' AND rx_ce = {$current_ce}";
                        $hist_count_res = $conn->query($hist_count_sql);
                        $hist_total_rows = ($hist_count_res && $hist_count_res->num_rows > 0) ? $hist_count_res->fetch_assoc()['cnt'] : 0;
                        $hist_total_pages = ceil($hist_total_rows / $hist_per_page);

                        if ($hist_page > $hist_total_pages && $hist_total_pages > 0) $hist_page = $hist_total_pages;
                        $hist_offset = ($hist_page - 1) * $hist_per_page;
                        if ($hist_offset < 0) $hist_offset = 0;

                        $hist_sql = "SELECT * FROM rx_account_history WHERE rx_uid = '{$uid_escaped}' AND rx_ce = {$current_ce} ORDER BY idx DESC LIMIT {$hist_offset}, {$hist_per_page}";
                        $hist_res = $conn->query($hist_sql);
                        if ($hist_res && $hist_res->num_rows > 0) {
                            while($hr = $hist_res->fetch_assoc()) {
                                $type_label = ($hr['amount_type'] == 'spot') ? '<span class="badge bg-warning text-dark">' . t('badge_spot') . '</span>' : '<span class="badge bg-primary">' . t('badge_fut') . '</span>';
                                echo "<tr><td>{$hr['regdate']}</td><td>{$type_label}</td><td>".format_truncate($hr['before_amount'],2)."</td><td class='fw-bold text-primary'>".format_truncate($hr['after_amount'],2)."</td></tr>";
                            }
                        } else {
                            echo "<tr><td colspan='4' class='text-muted p-4'>" . t('msg_no_amt_hist') . "</td></tr>";
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
                <?php
                $get_pagging_add_hist = "s_uid=" . urlencode($s_uid) . "&s_symbol=" . urlencode($s_symbol) . "&";
                if($admin_view == 1){
                    $get_pagging_add_hist .= "admin_view=1&admin_uid=" . urlencode($admin_uid) . "&admin_ce=" . $current_ce . "&";
                }
                if ($hist_total_pages > 1): ?>
                <nav><ul class="pagination mt-3 justify-content-center">
                    <?php if ($hist_page > 1): ?><li class="page-item"><a class="page-link" href="?<?=$get_pagging_add_hist?>hist_page=<?php echo $hist_page-1; ?>#tab-history"><?= t('btn_prev') ?></a></li><?php endif; ?>
                    <?php for ($i = max(1, $hist_page - 2); $i <= min($hist_total_pages, $hist_page + 2); $i++): ?>
                    <li class="page-item <?php echo ($i == $hist_page) ? 'active' : ''; ?>"><a class="page-link" href="?<?=$get_pagging_add_hist?>hist_page=<?php echo $i; ?>#tab-history"><?php echo $i; ?></a></li>
                    <?php endfor; ?>
                    <?php if ($hist_page < $hist_total_pages): ?><li class="page-item"><a class="page-link" href="?<?=$get_pagging_add_hist?>hist_page=<?php echo $hist_page+1; ?>#tab-history"><?= t('btn_next') ?></a></li><?php endif; ?>
                </ul></nav>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade" id="tab-settings" role="tabpanel">
                <div class="row">
                    <div class="col-lg-6 col-md-12 mb-3">
                        <h4 class="section-title">⚙️ <?= t('title_settings') ?></h4>
                        <div class="card p-4 h-100">
                            <!-- [수정됨] id 할당 및 확인 창 제거 -->
                            <form method="POST" action="?s_uid=<?php echo urlencode($s_uid); ?>&s_symbol=<?php echo urlencode($s_symbol); ?>#tab-settings" id="settingsForm">
                                <input type="hidden" name="act" value="update_settings">
                                <?php if ($admin_view): ?>
                                    <input type="hidden" name="admin_view" value="1">
                                    <input type="hidden" name="admin_uid" value="<?php echo htmlspecialchars($admin_uid); ?>">
                                    <input type="hidden" name="admin_ce" value="<?php echo $current_ce; ?>">
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label class="form-label fw-bold"><?= t('lbl_api_key') ?></label>
                                    <input type="text" name="up_apikey" class="form-control" value="<?php echo htmlspecialchars($keys_and_acount['rx_apikey'] ?? ''); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold"><?= t('lbl_sec_key') ?></label>
                                    <input type="text" name="up_sskey" class="form-control" value="<?php echo htmlspecialchars($keys_and_acount['rx_sskey'] ?? ''); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold"><?= t('lbl_pwd') ?> (Passphrase)</label>
                                    <input type="text" name="up_password" class="form-control" value="<?php echo htmlspecialchars($keys_and_acount['rx_password'] ?? ''); ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold"><?= t('lbl_reserve_set') ?></label>
                                    <div class="d-flex gap-2">
                                        <input type="radio" class="btn-check" name="up_reserve_percent" id="res0" value="0" <?php echo $current_reserve_percent == 0 ? 'checked' : ''; ?>>
                                        <label class="btn btn-outline-primary flex-fill" for="res0"><?= t('lbl_res_0') ?></label>

                                        <input type="radio" class="btn-check" name="up_reserve_percent" id="res30" value="30" <?php echo $current_reserve_percent == 30 ? 'checked' : ''; ?>>
                                        <label class="btn btn-outline-primary flex-fill" for="res30"><?= t('lbl_res_30') ?></label>

                                        <input type="radio" class="btn-check" name="up_reserve_percent" id="res100" value="100" <?php echo $current_reserve_percent == 100 ? 'checked' : ''; ?>>
                                        <label class="btn btn-outline-primary flex-fill" for="res100"><?= t('lbl_res_100') ?></label>
                                    </div>
                                    <small class="text-muted mt-1 d-block"><?= t('lbl_res_desc') ?></small>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold"><?= t('lbl_fut_amt') ?></label>
                                    <div class="input-group">
                                        <input type="text" inputmode="numeric" name="up_acount" class="form-control" value="<?php echo number_format(floatval($keys_and_acount['rx_acount'] ?? 0)); ?>" required oninput="this.value = this.value.replace(/[^0-9]/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');">
                                        <span class="input-group-text">USDT</span>
                                    </div>
                                    <small class="text-muted d-block mt-1"><?= t('lbl_fut_amt_desc') ?></small>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-bold"><?= t('lbl_spot_amt') ?></label>
                                    <div class="input-group">
                                        <input type="text" inputmode="numeric" name="up_spot_acount" class="form-control" value="<?php echo number_format($current_spot_amount); ?>" required oninput="this.value = this.value.replace(/[^0-9]/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');">
                                        <span class="input-group-text">USDT</span>
                                    </div>
                                </div>

                                <!-- [수정됨] type="button" 으로 변경하고 ID 부여 -->
                                <button type="button" class="btn btn-primary w-100 fw-bold" id="btnCheckAndSave"><?= t('btn_save') ?></button>
                            </form>
                        </div>
                    </div>

                    <div class="col-lg-6 col-md-12 mb-3">
                        <h4 class="section-title">💸 <?= t('title_transfer') ?></h4>
                        <div class="card p-4 h-100">
                            <form method="POST" action="?s_uid=<?php echo urlencode($s_uid); ?>&s_symbol=<?php echo urlencode($s_symbol); ?>#tab-settings" onsubmit="return confirm('<?= t('confirm_transfer') ?>');">
                                <input type="hidden" name="act" value="transfer">
                                <input type="hidden" name="t_uid" value="<?php echo htmlspecialchars($s_uid); ?>">
                                <?php if ($admin_view): ?>
                                    <input type="hidden" name="admin_view" value="1">
                                    <input type="hidden" name="admin_uid" value="<?php echo htmlspecialchars($admin_uid); ?>">
                                    <input type="hidden" name="admin_ce" value="<?php echo $current_ce; ?>">
                                <?php endif; ?>
                                <div class="mb-3">
                                    <label class="form-label fw-bold"><?= t('lbl_direction') ?></label>
                                    <select name="t_type" class="form-select">
                                        <option value="1"><?= t('opt_spot_to_fut') ?></option>
                                        <option value="2"><?= t('opt_fut_to_spot') ?></option>
                                    </select>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label fw-bold"><?= t('lbl_transfer_amt') ?></label>
                                    <input type="number" step="0.0001" name="t_amount" class="form-control" placeholder="<?= t('ph_amt') ?>" required>
                                </div>
                                <button type="submit" class="btn btn-success w-100 fw-bold mt-auto"><?= t('btn_transfer') ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        </div> <?php endif; ?>
</div>

<!-- [수정됨] 자산 설정 확인 모달 추가 (Deepcoin) -->
<div class="modal fade" id="balanceCheckModal" tabindex="-1" aria-labelledby="balanceCheckModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="balanceCheckModalLabel">💰 <?= t('mdl_bal_title') ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="balanceCheckModalBody">
        <!-- JS로 동적 내용이 들어갑니다. -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('mdl_bal_cancel') ?></button>
        <button type="button" class="btn btn-primary fw-bold" id="btnConfirmSettings"><?= t('mdl_bal_confirm') ?></button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php
$js_weekly_data = [];
if (!empty($weekly_data)) {
    foreach ($weekly_data as $yw => $data) {
        $avg_eq = $data['days'] > 0 ? ($data['equity_avg'] / $data['days']) : 0;
        $roi = ($avg_eq > 0) ? ($data['pnl'] / $avg_eq) * 100 : 0;
        $js_weekly_data[$yw] = [
            'pnl' => $data['pnl'],
            'avg_eq' => $avg_eq,
            'roi' => $roi
        ];
    }
}
?>
<script>
// [핵심 교정] 주간 태그 선택 시 hidden 필드에 주간데이터 자동 세팅
const weeklyDataMap = <?php echo json_encode($js_weekly_data); ?>;
function updateWeeklyData() {
    const sel = document.getElementById('week_tag_select');
    const yw = sel.value;
    if(yw && weeklyDataMap[yw]) {
        document.getElementById('hidden_weekly_pnl').value = weeklyDataMap[yw].pnl;
        document.getElementById('hidden_avg_equity').value = weeklyDataMap[yw].avg_eq;
        document.getElementById('hidden_weekly_roi').value = weeklyDataMap[yw].roi;
    } else {
        document.getElementById('hidden_weekly_pnl').value = '0';
        document.getElementById('hidden_avg_equity').value = '0';
        document.getElementById('hidden_weekly_roi').value = '0';
    }
}

// 복사 기능 추가 스크립트
function copyDepositAddress() {
    var copyText = document.getElementById("depositAddress").innerText;
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(copyText).then(function() {
            alert("입금 주소가 복사되었습니다.\n" + copyText);
        }, function(err) {
            console.error('복사 실패: ', err);
            fallbackCopyTextToClipboard(copyText);
        });
    } else {
        fallbackCopyTextToClipboard(copyText);
    }
}

function fallbackCopyTextToClipboard(text) {
    var textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed"; // 스크롤 방지
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
        var successful = document.execCommand('copy');
        if(successful) {
            alert("입금 주소가 복사되었습니다.\n" + text);
        } else {
            alert("복사에 실패했습니다. 직접 드래그하여 복사해주세요.");
        }
    } catch (err) {
        alert("복사에 실패했습니다. 직접 드래그하여 복사해주세요.");
    }
    document.body.removeChild(textArea);
}

function fmtTruncate(num, digits) {
    let isNegative = num < 0;
    let numS = Math.abs(num).toFixed(10);
    let decPos = numS.indexOf('.');
    let res;
    if (decPos === -1) {
        res = numS;
    } else if (digits === 0) {
        res = numS.substring(0, decPos);
    } else {
        res = numS.substring(0, decPos + 1 + digits);
        if (res.endsWith('.')) res = res.substring(0, res.length - 1);
    }

    let parts = res.split('.');
    parts[0] = parseInt(parts[0] || '0', 10).toLocaleString('en-US');
    res = parts.join('.');

    if (isNegative && parseFloat(res.replace(/,/g, '')) !== 0) return "-" + res;
    return res;
}

function showTab(tabId) {
    const triggerEl = document.querySelector(`button[data-bs-target="#${tabId}"]`);
    if (triggerEl) {
        const tab = new bootstrap.Tab(triggerEl);
        tab.show();
    }
}

document.addEventListener("DOMContentLoaded", function() {
    let hash = window.location.hash;
    if (hash) showTab(hash.replace('#', ''));

    document.querySelectorAll('button[data-bs-toggle="tab"]').forEach(btn => {
        btn.addEventListener('shown.bs.tab', function (e) {
            history.pushState(null, null, e.target.getAttribute('data-bs-target'));
        });
    });

    const calendarContainer = document.getElementById("rxCalendar");
    if (calendarContainer) {
        calendarContainer.addEventListener("click", function(e) {
            const dayBox = e.target.closest('.calendar-day:not(.empty)');
            if (dayBox) {
                const isAlreadyZoomed = dayBox.classList.contains('zoomed');
                document.querySelectorAll('.calendar-day.zoomed').forEach(el => el.classList.remove('zoomed'));
                if (!isAlreadyZoomed) dayBox.classList.add('zoomed');
            } else {
                document.querySelectorAll('.calendar-day.zoomed').forEach(el => el.classList.remove('zoomed'));
            }
        });
    }

    const uid = "<?php echo h($s_uid); ?>";
    const symbol = "<?php echo h($s_symbol); ?>";
    const tableBody = document.querySelector("#current-position-table tbody");
    const counterElement = document.getElementById("refresh-counter");

    if (tableBody && uid) {
        const maxCountdown = 5;
        let countdown = maxCountdown;

        setInterval(function() {
            countdown--;
            if (countdown <= 0) {
                if (counterElement) {
                    counterElement.innerText = "<?= t('lbl_refreshing') ?>";
                    counterElement.classList.replace('bg-secondary', 'bg-primary');
                }
                fetchPositions();
                countdown = maxCountdown;
            } else {
                if (counterElement) {
                    counterElement.innerText = countdown + "<?= t('lbl_sec_refresh') ?>";
                    counterElement.classList.replace('bg-primary', 'bg-secondary');
                }
            }
        }, 1000);

        function fetchPositions() {
            const urlParams = new URLSearchParams(window.location.search);
            let fetchUrl = `?ajax_action=get_positions&uid=${encodeURIComponent(uid)}&symbol=${encodeURIComponent(symbol)}`;

            if (urlParams.has('admin_view')) fetchUrl += `&admin_view=${urlParams.get('admin_view')}`;
            if (urlParams.has('admin_uid')) fetchUrl += `&admin_uid=${urlParams.get('admin_uid')}`;
            if (urlParams.has('admin_ce')) fetchUrl += `&admin_ce=${urlParams.get('admin_ce')}`;

            fetch(fetchUrl)
                .then(response => response.json())
                .then(res => {
                    if (res && res.status === true && Array.isArray(res.data)) {
                        updatePositionTable(res.data);
                    }
                    if (counterElement) counterElement.innerText = countdown + "<?= t('lbl_sec_refresh') ?>";
                })
                .catch(error => {
                    console.warn("포지션 갱신 에러:", error);
                    if (counterElement) {
                        counterElement.innerText = "<?= t('lbl_err_occurred') ?>";
                        counterElement.classList.replace('bg-primary', 'bg-danger');
                    }
                });
        }

        function updatePositionTable(positions) {
            if (!tableBody) return;
            let newHtml = "";

            positions.forEach(row => {
                let szContracts = parseFloat(row.pos || row.sz || 0);
                if (szContracts <= 0) return; // 찌꺼기 포지션 렌더링 제외

                let symbolFull = (row.instId || "").toUpperCase();
                let symbolShort = symbolFull.split('-')[0];

                let multiplier = 1;
                if (symbolFull.includes('BTC')) multiplier = 0.001;
                else if (symbolFull.includes('ETH')) multiplier = 0.01;
                else if (symbolFull.includes('XRP')) multiplier = 10;

                let realQty = szContracts * multiplier;

                // [수정완료] 현재 포지션 진입수량 소수점 3자리 내림 표기
                realQty = Math.floor(realQty * 1000) / 1000;

                let initialQty = parseFloat(row.initial_qty || 0);

                let martingaleCount = 0;
                if (initialQty > 0 && realQty > 0) {
                    martingaleCount = Math.round(realQty / initialQty);
                }
                let martingaleText = martingaleCount > 0 ? `<span class="badge bg-success">${martingaleCount}<?= t('lbl_times') ?></span>` : "-";

                let avgPrice = parseFloat(row.avgPx || 0);
                let markPrice = parseFloat(row.lastPx || row.markPx || 0);
                let netUnrealizedPnL = parseFloat(row.unrealizedProfit || row.upl || 0);

                let lever = parseFloat(row.lever || 50);
                if (lever <= 0) lever = 50;

                let openValue = avgPrice * realQty;
                let margin = (openValue > 0) ? (openValue / lever) : 0;
                let netRoi = (margin > 0) ? (netUnrealizedPnL / margin) : 0;

                let sideDisplay = (row.posSide === 'long') ? 'LONG' : 'SHORT';
                let sideClass = (sideDisplay === 'LONG') ? 'text-long' : 'text-short';
                let pnlClass = (netUnrealizedPnL >= 0) ? 'text-profit' : 'text-loss';
                let barClass = (sideDisplay === 'LONG') ? 'long' : 'short';

                let pnlSign = netUnrealizedPnL > 0 ? '+' : '';
                let roiSign = netRoi > 0 ? '+' : '';

                newHtml += `
                <tr>
                    <td class="fw-bold text-start ps-4">
                        <span class="vertical-bar ${barClass}"></span>${symbolShort || '-'}
                    </td>
                    <td class="${sideClass}">${sideDisplay}</td>
                    <td>x${lever}</td>
                    <td>${fmtTruncate(initialQty, 3)}</td>
                    <td>${fmtTruncate(realQty, 3)}</td>
                    <td>${martingaleText}</td>
                    <td>${fmtTruncate(avgPrice, 1)}</td>
                    <td>${fmtTruncate(markPrice, 1)}</td>
                    <td class="text-danger">${fmtTruncate(row.liqPx, 1)}</td>
                    <td class="${pnlClass} fw-bold">${pnlSign}${fmtTruncate(netUnrealizedPnL, 4)}</td>
                    <td class="${pnlClass}">${roiSign}${fmtTruncate(netRoi * 100, 2)}%</td>
                </tr>
                `;
            });

            if (newHtml !== "") {
                tableBody.innerHTML = newHtml;
            } else {
                tableBody.innerHTML = `<tr><td colspan="11" class="text-muted p-4"><?= t('msg_no_pos') ?></td></tr>`;
            }
        }
    }

    // === 자산 설정 검증 및 모달 제어 로직 (Deepcoin) ===
    const btnCheckAndSave = document.getElementById('btnCheckAndSave');
    const settingsForm = document.getElementById('settingsForm');
    const btnConfirmSettings = document.getElementById('btnConfirmSettings');
    let balanceCheckModal;

    if (btnCheckAndSave && settingsForm) {
        btnCheckAndSave.addEventListener('click', function() {
            let inputFutStr = document.querySelector('input[name="up_acount"]').value.replace(/,/g, '');
            let inputSpotStr = document.querySelector('input[name="up_spot_acount"]').value.replace(/,/g, '');
            let inputFut = parseFloat(inputFutStr) || 0;
            let inputSpot = parseFloat(inputSpotStr) || 0;

            let originalText = btnCheckAndSave.innerHTML;
            btnCheckAndSave.innerHTML = "⏳ <?= t('mdl_bal_checking') ?>";
            btnCheckAndSave.disabled = true;

            const urlParams = new URLSearchParams(window.location.search);
            let fetchUrl = `?ajax_action=get_balances&uid=${encodeURIComponent(uid)}`;
            if (urlParams.has('admin_view')) fetchUrl += `&admin_view=${urlParams.get('admin_view')}`;
            if (urlParams.has('admin_uid')) fetchUrl += `&admin_uid=${urlParams.get('admin_uid')}`;
            if (urlParams.has('admin_ce')) fetchUrl += `&admin_ce=${urlParams.get('admin_ce')}`;

            fetch(fetchUrl)
                .then(res => res.json())
                .then(data => {
                    btnCheckAndSave.innerHTML = originalText;
                    btnCheckAndSave.disabled = false;

                    if (data.status) {
                        let actFut = parseFloat(data.fut_bal) || 0;
                        let actSpot = parseFloat(data.spot_bal) || 0;

                        const fmtInt = (num) => Math.floor(num).toLocaleString('en-US');

                        let htmlContent = `
                            <div class="mb-2" style="font-size:1.05rem;">
                                <?= t('mdl_bal_fut') ?> <strong>$ ${fmtInt(actFut)}</strong> &nbsp;|&nbsp; <?= t('mdl_bal_set_amt') ?> <strong class="text-primary">$ ${fmtInt(inputFut)}</strong>
                            </div>
                            <div class="mb-3" style="font-size:1.05rem;">
                                <?= t('mdl_bal_spot') ?> <strong>$ ${fmtInt(actSpot)}</strong> &nbsp;|&nbsp; <?= t('mdl_bal_set_amt') ?> <strong class="text-primary">$ ${fmtInt(inputSpot)}</strong>
                            </div>
                        `;

                        let isFutOver = inputFut > actFut;
                        let isSpotOver = inputSpot > actSpot;
                        let warningMsg = "";

                        if (isFutOver && isSpotOver) {
                            warningMsg = "<?= t('mdl_bal_warn_all') ?>";
                        } else if (isFutOver && !isSpotOver) {
                            warningMsg = "<?= t('mdl_bal_warn_fut') ?>";
                        } else if (!isFutOver && isSpotOver) {
                            warningMsg = "<?= t('mdl_bal_warn_spot') ?>";
                        }

                        if (warningMsg !== "") {
                            htmlContent += `
                                <div class="alert alert-danger mb-0 py-3" style="border-radius: 8px; background-color: #f8d7da; color: #842029; border: 1px solid #f5c2c7;">
                                    ⚠️ <strong>${warningMsg}</strong><br>
                                    <?= t('mdl_bal_warn_ask') ?>
                                </div>`;
                        } else {
                            htmlContent += `
                                <div class="alert alert-info mb-0 py-3" style="border-radius: 8px;">
                                    ℹ️ <?= t('mdl_bal_info') ?>
                                </div>`;
                        }

                        document.getElementById('balanceCheckModalBody').innerHTML = htmlContent;

                        if (!balanceCheckModal) {
                            balanceCheckModal = new bootstrap.Modal(document.getElementById('balanceCheckModal'));
                        }
                        balanceCheckModal.show();
                    } else {
                        if (confirm("<?= t('mdl_bal_err_api') ?> " + (data.msg || "<?= t('lbl_err_occurred') ?>"))) {
                            settingsForm.submit();
                        }
                    }
                })
                .catch(err => {
                    btnCheckAndSave.innerHTML = originalText;
                    btnCheckAndSave.disabled = false;
                    alert("<?= t('mdl_bal_err_comm') ?>");
                });
        });
    }

    if (btnConfirmSettings && settingsForm) {
        btnConfirmSettings.addEventListener('click', function() {
            btnConfirmSettings.innerHTML = "<?= t('mdl_bal_saving') ?>";
            btnConfirmSettings.disabled = true;
            settingsForm.submit();
        });
    }
});
</script>

</body>
</html>
<?php
// End of file
?>