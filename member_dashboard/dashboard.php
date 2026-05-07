<?php
// [디버깅] 에러를 화면에 표시합니다. 정상 작동 확인 후 아래 두 줄은 삭제하셔도 됩니다.
//error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set("display_errors", 1);

require_once(__DIR__ . '/common.php');
require_once(__DIR__ . '/lang.php'); // 언어팩 로드 추가

/**
 * dashboard.php - 거래 내역 및 상태 대시보드 (Toobit 용)
 * - 월별 캘린더 형태의 일별 실현 손익 (DB 기반 과거 수익률 고정)
 * - 실시간 포지션 갱신 (AJAX 5초 카운터)
 * - 투자금/API 설정, 주간 수익, 투자금 변동 내역 탭 지원
 * - 통신 에러 시 에러 메시지 표시 후 정지
 */

date_default_timezone_set('Asia/Seoul');

$conn = get_db_connection();

// [핵심 교정] 현재 실행 중인 파일명을 동적으로 가져옵니다. (하드코딩 된 dashboard.php 의존성 제거)
$current_page = basename($_SERVER['PHP_SELF']);

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

    // 1000단위 콤마 추가
    $parts = explode('.', $res);
    $parts[0] = number_format((float)$parts[0]);
    $finalRes = implode('.', $parts);

    return ($isNegative && floatval($res) != 0 ? '-' : '') . $finalRes;
}

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
if (isset($_GET['admin_view']) && $_GET['admin_view'] == '1' && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
    $admin_view = true;
    $admin_uid = trim($_GET['admin_uid'] ?? '');
    $admin_ce = intval($_GET['admin_ce'] ?? 1);

    if (!empty($admin_uid)) {
        $uid_safe = $conn->real_escape_string($admin_uid);
        $sql_tmp = "SELECT * FROM rx_member WHERE rx_uid = '{$uid_safe}' AND rx_ce = {$admin_ce} LIMIT 1";
        $res_tmp = $conn->query($sql_tmp);
        if ($res_tmp && $res_tmp->num_rows > 0) {
            $member = $res_tmp->fetch_assoc();
        } else {
            $member = null;
        }
    } else {
        $member = null;
    }
} else {
    $member = get_current_member();
}

if (!$member) {
    if (!$admin_view) {
        session_destroy();
        header('Location: login.php');
    }
    exit;
}

// 초기 패스워드 체크 (관리자 모드에서는 건너뜀)
if (!$admin_view && is_initial_password($member)) {
    header('Location: change_password.php?initial=1');
    exit;
}

$s_uid = $member['rx_uid'];
$current_ce = $admin_view ? ($admin_ce ?? 1) : intval($member['rx_ce'] ?? 1);
if (isset($_GET['ajax_action'])) {
    $current_ce = isset($_GET['admin_ce']) ? intval($_GET['admin_ce']) : 1;
}

$current_investment_amount = floatval($member['rx_acount'] ?? 0); // 선물 투자금
$current_spot_amount = floatval($member['rx_spot_acount'] ?? 0); // 현물 유보금
$s_symbol = isset($_REQUEST['s_symbol']) ? trim($_REQUEST['s_symbol']) : "BTC-SWAP-USDT";

$api_key = preg_replace('/\s+/', '', $member['rx_apikey'] ?? '');
$ss_key  = preg_replace('/\s+/', '', $member['rx_sskey'] ?? '');
$exchange_name = defined('EXCHANGE_MAP') ? (EXCHANGE_MAP[$current_ce] ?? 'Unknown') : 'Toobit';

// 오류 방지를 위해 별도로 유보율 데이터 안전 로드
$current_reserve_percent = 0;
if ($s_uid) {
    $uid_safe = $conn->real_escape_string($s_uid);
    $res_reserve = @$conn->query("SELECT rx_reserve_percent FROM rx_member WHERE rx_uid = '{$uid_safe}' AND rx_ce = {$current_ce} LIMIT 1");
    if ($res_reserve && $res_reserve->num_rows > 0) {
        $row_reserve = $res_reserve->fetch_assoc();
        $current_reserve_percent = intval($row_reserve['rx_reserve_percent']);
    }
}

// ==================================================================
// [POST 액션] 설정 탭 업데이트 및 자산 이동
// ==================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    // 1. API 및 투자금/유보금 설정 업데이트
    if ($act == 'update_settings') {
        $up_api = $conn->real_escape_string(preg_replace('/\s+/', '', $_POST['up_apikey'] ?? ''));
        $up_sec = $conn->real_escape_string(preg_replace('/\s+/', '', $_POST['up_sskey'] ?? ''));

        // 콤마 제거 후 숫자형 변환
        $up_acount = floatval(str_replace(',', '', $_POST['up_acount']));
        $up_spot_acount = floatval(str_replace(',', '', $_POST['up_spot_acount']));

        $up_reserve_percent = isset($_POST['up_reserve_percent']) ? intval($_POST['up_reserve_percent']) : 0;
        $uid_safe = $conn->real_escape_string($s_uid);

        // 변경 전 금액 확인
        $chk_sql = "SELECT rx_acount, rx_spot_acount FROM rx_member WHERE rx_uid = '{$uid_safe}' AND rx_ce = {$current_ce} LIMIT 1";
        $chk_res = $conn->query($chk_sql);
        $old_data = ($chk_res && $chk_res->num_rows > 0) ? $chk_res->fetch_assoc() : ['rx_acount'=>0, 'rx_spot_acount'=>0];

        $old_acount = floatval($old_data['rx_acount']);
        $old_spot = floatval($old_data['rx_spot_acount']);

		$add_params = $admin_view ? "&admin_view=1&admin_uid=".urlencode($s_uid)."&admin_ce={$current_ce}" : "";

		// 거래소 통신 해서 하부 uid 인지 체크 하는 로직 추가
		if($up_reserve_percent == 100){
		$result = rx_uid_chek($uid_safe);//$uid_safe
			if($result != 'E000'){
				$redirect_url = "{$current_page}?s_symbol=".urlencode($s_symbol)."{$add_params}&msg=uid_error_해당 상부 밑으로 가입된 UID가 아닙니다.#tab-settings";
				redirect_to($redirect_url);
				exit;
			}
		}

        // [핵심 교정] 투자금/유보금 분리: 변경된 내역만 독립적으로 기록합니다.
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

        // rx_member 테이블 업데이트 (유보율 포함)
        $up_sql = "UPDATE rx_member
                   SET rx_apikey = '{$up_api}',
                       rx_sskey = '{$up_sec}',
                       rx_acount = {$up_acount},
                       rx_spot_acount = {$up_spot_acount},
                       rx_reserve_percent = {$up_reserve_percent}
                   WHERE rx_uid = '{$uid_safe}' AND rx_ce = {$current_ce}";



        if ($conn->query($up_sql)) {
            if (!$admin_view && isset($_SESSION['member'])) {
                $refresh_sql = "SELECT * FROM rx_member WHERE rx_uid = '{$uid_safe}' AND rx_ce = {$current_ce} LIMIT 1";
                $refresh_res = $conn->query($refresh_sql);
                if ($refresh_res && $refresh_res->num_rows > 0) {
                    $_SESSION['member'] = $refresh_res->fetch_assoc();
                }
            }
            $redirect_url = "{$current_page}?s_symbol=".urlencode($s_symbol)."{$add_params}&msg=update_ok#tab-settings";
        } else {
            $db_error = $conn->error;
            $redirect_url = "{$current_page}?s_symbol=".urlencode($s_symbol)."{$add_params}&msg=uid_error_" . urlencode($db_error) . "#tab-settings";
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
                'act'       => 'member_sync',     // 액션 구분자
                'rx_uid'    => $member1['rx_uid'],
                'rx_acount' => $member1['rx_acount'],
                'rx_apikey' => $member1['rx_apikey'],
                'rx_sskey'  => $member1['rx_sskey'],
                'rx_class'  => $rx_class,
                'rx_ce'     => $current_ce,        // 거래소 코드 추가
            ], JSON_UNESCAPED_UNICODE);

            // HMAC-SHA256 서명 생성 (147번 SERVER_58_KEY와 동일한 키 사용)
            $sync_secret    = 'CHANGE_THIS_SECRET_KEY'; // config의 SERVER_58_KEY와 동일하게 변경
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5초 타임아웃 (대시보드 느려짐 방지)
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Timestamp: ' . $sync_timestamp,
                'X-Signature: ' . $sync_signature,
                'X-Source: 58_dashboard',
            ]);
            $sync_response = curl_exec($ch);
            $sync_status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // 동기화 실패해도 대시보드는 정상 작동 (비차단)
            if ($sync_status !== 200) {
                error_log("[dashboard sync] 147번 서버 동기화 실패 HTTP:{$sync_status} UID:{$member1['rx_uid']}");
            }
        }
        redirect_to($redirect_url);
    }

    // 2. 주간 TXID 저장
    if ($act == 'save_txid') {
        $week_tag = $conn->real_escape_string(trim($_POST['week_tag']));
        $txid = $conn->real_escape_string(trim($_POST['txid']));
        $uid_safe = $conn->real_escape_string($s_uid);

        if (!empty($week_tag) && !empty($txid)) {
            $conn->query("INSERT INTO rx_weekly_txid (uid, week_tag, txid, regdate) VALUES ('{$uid_safe}', '{$week_tag}', '{$txid}', NOW()) ON DUPLICATE KEY UPDATE txid = '{$txid}', regdate = NOW()");
        }

        $add_params = $admin_view ? "&admin_view=1&admin_uid=".urlencode($s_uid)."&admin_ce={$current_ce}" : "";
        $redirect_url = "{$current_page}?s_symbol=".urlencode($s_symbol)."{$add_params}&msg=txid_ok#tab-week";
        redirect_to($redirect_url);
    }

    // 3. 자산 이동
    if ($act == 'transfer') {
        $t_type   = trim($_POST['t_type']); // 1: Spot->Fut, 2: Fut->Spot
        $t_amount = trim($_POST['t_amount']);
        $res_trans = rx_transfer_asset($s_uid, $t_type, $t_amount, $api_key, $ss_key);

        if ($res_trans['status']) {
            $msg_code = "trans_ok_" . urlencode($t_amount);
        } else {
            $msg_code = "trans_fail_" . urlencode($res_trans['msg']);
        }

        $add_params = $admin_view ? "&admin_view=1&admin_uid=".urlencode($s_uid)."&admin_ce={$current_ce}" : "";
        $redirect_url = "{$current_page}?s_symbol=".urlencode($s_symbol)."{$add_params}&msg={$msg_code}#tab-settings";
        redirect_to($redirect_url);
    }
}

// ==================================================================
// [Toobit API 함수들] - 거래소 직접 통신
// ==================================================================

// uid 로 하위 체크
function rx_uid_chek($uid){
	//03-14 금요일 키 값을 받아야 함 디비에서 가져오는 방법은 사용 불가능 처음 일 경우도 있어서
	global $rx_api_url,$g5,$conn;

	if($uid==''){
		return 'E103';// 금액이 없음
		exit;
	}

	$sql = "SELECT * FROM rx_member WHERE rx_uid = '990137859' AND rx_apikey IS NOT NULL LIMIT 1;";
	$res_tmp = $conn->query($sql);
	if ($res_tmp && $res_tmp->num_rows > 0) {
		$row = $res_tmp->fetch_assoc();
	}


	$base_url = "https://api.toobit.com/api/v1/agent/inviteUserList";
	$api_key = $row['rx_apikey'];
	$ss_key = $row['rx_sskey'];

	$timestamp = round(microtime(true) * 1000);

		$payload = [
			"uid" => $uid,
			//"startTime" => $startTime,
			//"endTime" => $endTime,
			//"limit" => $limit,
			"timestamp" => $timestamp
		];

		$signature = hash_hmac('sha256', http_build_query($payload), $ss_key);

		// ✅ 요청 데이터 구성
		$payload["signature"] = $signature;
		//$payload_json = json_encode($payload);
		// ✅ **헤더 설정 (accessKey 포함)**
		$headers = [
			"X-BB-APIKEY: " . $api_key  // Toobit은 `X-MBX-APIKEY` 사용 가능
		];
		//print_r($payload);
		//echo "<br>";
		$url = $base_url.'?'.http_build_query($payload);
		// ✅ cURL 초기화
		$ch = curl_init();

		// ✅ cURL 옵션 설정
		curl_setopt($ch, CURLOPT_URL, $url);
		//curl_setopt($ch, CURLOPT_POST, true);
		//curl_setopt($ch, CURLOPT_POSTFIELDS, $payload_json);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		// ✅ API 요청 실행
		$response = curl_exec($ch);

		// ✅ 오류 확인
		if (curl_errno($ch)) {
			echo "cURL Error: " . curl_error($ch);
			exit();
		}

		// ✅ cURL 종료
		curl_close($ch);
		//echo $url."<br>";
		// ✅ JSON 응답을 배열로 변환
		//$data = json_decode($response, true);

		//print('<pre>');
		//print_r($data);
		//exle_array($data);
		//print('</pre>');

		$data = json_decode($response, true);
		//print_r($data);
		// 3. 결과 매칭 검증
		// 리스트 내에 데이터가 존재하고 UID가 정확히 일치하면 성공
		if (isset($data['code']) && $data['code'] == 200 && !empty($data['data']['list'])) {
			foreach ($data['data']['list'] as $user) {
				//print_r($user);
				if ((string)$user['uid'] === (string)$uid) {
					curl_close($ch);
					return 'E000';
					//return ['status' => true, 'msg' => '하부 소속 확인됨', 'data' => $user];
				}
			}
		}

		curl_close($ch);
		return 'E101';
		//return ['status' => false, 'msg' => '해당 상부 밑으로 가입된 UID가 아닙니다.'];
		/*
		if(count($data['data']['list']) == 0){
			// 리턴된 데이터가 없음
			return 'E101';// 리워드 가 아님
			//return 100000;
		}elseif($data['data']['list']['0']['balanceVolume'] > 0){

			return $data['data']['list']['0']['balanceVolume'];
		}else{
			return 'E102';// 금액이 없음
		}*/
}

function toobit_api_call($endpoint, $api_key, $ss_key, $extra_params = [], $method = 'GET') {
    $timestamp = number_format(microtime(true) * 1000, 0, '', '');
    $base_url = "https://api.toobit.com" . $endpoint;

    $params = array_merge($extra_params, [
        "timestamp"  => $timestamp,
        "recvWindow" => "10000"
    ]);

    ksort($params);
    $query_string = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $signature = hash_hmac('sha256', $query_string, $ss_key);
    $url = $base_url . '?' . $query_string . '&signature=' . $signature;

    $headers = ["X-BB-APIKEY: " . $api_key, "Content-Type: application/json"];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['status' => false, 'msg' => t('err_comm') . ": {$curl_error}", 'http_code' => 0];
    }

    $data = json_decode($response, true);
    return ['status' => true, 'data' => $data, 'http_code' => $http_code, 'raw' => $response];
}

function rx_transfer_asset($uid, $direction, $amount, $api_key, $ss_key, $asset = 'USDT') {
    if ($amount <= 0) return ['status' => false, 'msg' => t('err_amt_gt_zero')];
    $fromType = ($direction == 1) ? "MAIN" : "FUTURES";
    $toType   = ($direction == 1) ? "FUTURES" : "MAIN";
    $params = [
        "fromUid" => $uid, "toUid" => $uid,
        "fromAccountType" => $fromType, "toAccountType" => $toType,
        "asset" => $asset, "quantity" => $amount,
    ];
    $result = toobit_api_call('/api/v1/subAccount/transfer', $api_key, $ss_key, $params, 'POST');
    if (!$result['status']) return $result;
    $data = $result['data'];
    if ($result['http_code'] == 200 && isset($data['code']) && $data['code'] == 200) {
        return ['status' => true, 'msg' => t('msg_trans_succ'), 'data' => $data];
    }
    $error_msg = isset($data['msg']) ? $data['msg'] : 'Unknown Error';
    return ['status' => false, 'msg' => t('msg_trans_fail') . " ({$result['http_code']}): {$error_msg}"];
}

function rx_get_spot_balance($api_key, $ss_key) {
    $result = toobit_api_call('/api/v1/account', $api_key, $ss_key);
    if (!$result['status']) return $result;
    $data = $result['data'];
    if ($result['http_code'] == 200 && isset($data['balances'])) {
        $usdt_spot = ['free' => 0, 'locked' => 0, 'asset' => 'USDT'];
        foreach ($data['balances'] as $coin) {
            if (strtoupper($coin['asset']) == 'USDT') { $usdt_spot = $coin; break; }
        }
        return ['status' => true, 'data' => $usdt_spot];
    }
    $error_msg = isset($data['msg']) ? $data['msg'] : 'Spot API Error';
    return ['status' => false, 'msg' => t('err_spot_bal') . " ({$result['http_code']}): {$error_msg}"];
}

function rx_get_futures_balance($api_key, $ss_key) {
    $result = toobit_api_call('/api/v1/futures/balance', $api_key, $ss_key);
    if (!$result['status']) return $result;
    $data = $result['data'];
    if ($result['http_code'] == 200) {
        $list = [];
        if (isset($data['data']['list'])) $list = $data['data']['list'];
        elseif (isset($data['data']) && is_array($data['data'])) $list = $data['data'];
        elseif (is_array($data) && isset($data[0])) $list = $data;
        $usdt_wallet = null;
        foreach ($list as $wallet) {
            if ((isset($wallet['asset']) ? strtoupper($wallet['asset']) : '') == 'USDT') { $usdt_wallet = $wallet; break; }
        }
        return $usdt_wallet ? ['status' => true, 'data' => $usdt_wallet] : ['status' => false, 'msg' => t('err_no_usdt_fut')];
    }
    $error_msg = isset($data['msg']) ? $data['msg'] : 'Futures API Error';
    return ['status' => false, 'msg' => t('err_fut_bal') . " ({$result['http_code']}): {$error_msg}"];
}

function rx_get_current_positions($api_key, $ss_key, $symbol = '') {
    $params = [];
    if ($symbol) $params['symbol'] = $symbol;
    $result = toobit_api_call('/api/v1/futures/positions', $api_key, $ss_key, $params);
    if (!$result['status']) return $result;
    $data = $result['data'];
    if ($result['http_code'] == 200) {
        $positions = [];
        $raw_list = [];
        if (is_array($data) && isset($data[0])) $raw_list = $data;
        elseif (isset($data['data'])) $raw_list = $data['data'];
        foreach ($raw_list as $pos) {
            if (isset($pos['position']) && floatval($pos['position']) > 0) $positions[] = $pos;
        }
        return ['status' => true, 'data' => $positions];
    }
    $error_msg = isset($data['msg']) ? $data['msg'] : 'Position API Error';
    return ['status' => false, 'msg' => t('err_pos_fetch') . " ({$result['http_code']}): {$error_msg}"];
}

function rx_get_history_positions_300($api_key, $ss_key, $symbol = 'BTC-SWAP-USDT') {
    $all_history = [];
    $last_id = null;
    $max_iterations = 10;
    $one_month_ago = strtotime('-31 days');

    for ($i = 0; $i < $max_iterations; $i++) {
        $params = ["symbol" => $symbol, "limit" => 300];
        if ($last_id !== null) $params['toId'] = $last_id;
        $result = toobit_api_call('/api/v1/futures/historyPositions', $api_key, $ss_key, $params);
        if (!$result['status']) return $result;

        if ($result['http_code'] == 200) {
            $data = $result['data'];
            $batch = [];
            if (isset($data['data']['list'])) $batch = $data['data']['list'];
            elseif (is_array($data) && isset($data[0])) $batch = $data;
            elseif (isset($data['data']) && is_array($data['data'])) $batch = $data['data'];

            if (empty($batch)) break;

            foreach ($batch as $v) {
                $side = strtoupper($v['side'] ?? '-');
                $lever = floatval($v['leverage'] ?? 50);
                $openAvgPrice = floatval($v['openAvgPrice'] ?? 0);
                $closeAvgPrice = floatval($v['closeAvgPrice'] ?? 0);

                // 실현 손익 및 비율 연동
                $realizedPnL = floatval($v['realizedPnL'] ?? 0);
                $realizedPnlRate = floatval($v['realizedPnlRate'] ?? 0);

                // 진입 수량(계약 수) 파악: API 필드 중 0보다 큰 유효한 값을 찾습니다.
                $raw_volume = 0;
                $qty_fields = ['closeTotalQty', 'position', 'maxPosition', 'closedVolume', 'closeVolume', 'qty', 'amount', 'volume'];
                foreach ($qty_fields as $field) {
                    if (isset($v[$field]) && is_numeric($v[$field])) {
                        $val = floatval($v[$field]);
                        if ($val > 0) {
                            $raw_volume = $val;
                            break; // 유효한 값을 찾으면 즉시 루프를 종료합니다.
                        }
                    }
                }

                $symbol_upper = strtoupper($v['symbol'] ?? 'BTC');
                $multiplier = 1;
                if (strpos($symbol_upper, 'BTC') !== false) $multiplier = 0.001;
                elseif (strpos($symbol_upper, 'ETH') !== false) $multiplier = 0.01;
                elseif (strpos($symbol_upper, 'XRP') !== false) $multiplier = 10;

                // [핵심 스케일링 방어]
                // Toobit API가 정수(계약 수)가 아닌 소수점(실제 코인 수량, 예: 0.003)으로 응답하는 경우
                // multiplier를 또 곱하면 0.000003이 되어 0으로 표시되는 문제를 완벽 방어합니다.
                $final_volume = 0;
                if ($raw_volume > 0) {
                    if ($raw_volume < 1 && ($multiplier == 0.001 || $multiplier == 0.01)) {
                        $final_volume = $raw_volume; // 이미 환산된 코인 수량으로 간주
                    } else {
                        $final_volume = $raw_volume * $multiplier;
                    }
                } else {
                    // 최후의 수단: 필드를 아예 찾지 못한 경우 가치(openValue)와 진입가로 역산 폴백
                    $openVal = floatval($v['openValue'] ?? 0);
                    $openAvg = floatval($v['openAvgPrice'] ?? 0);
                    if ($openVal > 0 && $openAvg > 0) {
                        $fallback_vol = $openVal / $openAvg;
                        if ($fallback_vol < 1 && ($multiplier == 0.001 || $multiplier == 0.01)) {
                            $final_volume = $fallback_vol;
                        } else {
                            $final_volume = $fallback_vol * $multiplier;
                        }
                    }
                }

                $v['closedVolume'] = $final_volume;

                $v['side'] = $side;
                $v['leverage'] = $lever;
                $v['openAvgPrice'] = $openAvgPrice;
                $v['closeAvgPrice'] = $closeAvgPrice;
                $v['realizedPnL'] = $realizedPnL;
                $v['realizedPnlRate'] = $realizedPnlRate;

                $ctime = floatval($v['closeTime'] ?? 0);
                $v['closeTime'] = ($ctime > 1e11) ? $ctime : $ctime * 1000;

                $all_history[] = $v;
            }

            $last_item = end($batch);
            if (isset($last_item['id'])) $last_id = $last_item['id'];

            if (isset($last_item['closeTime'])) {
                $last_close_sec = $last_item['closeTime'] / 1000;
                if ($last_close_sec < $one_month_ago) {
                    break;
                }
            }
        } else {
            $error_msg = isset($result['data']['msg']) ? $result['data']['msg'] : 'History API Error';
            return ['status' => false, 'msg' => t('err_hist_fetch') . " ({$result['http_code']}): {$error_msg}"];
        }
        usleep(100000);
    }

    $unique_history = [];
    foreach ($all_history as $item) {
        $key = isset($item['id']) ? $item['id'] : serialize($item);
        $unique_history[$key] = $item;
    }
    return ['status' => true, 'data' => array_values($unique_history)];
}

function rx_get_bot_initial_qty_toobit($api_key, $ss_key, $symbol) {
    if (empty($api_key)) return 0;
    $params = [ 'symbol' => $symbol, 'limit' => 10 ];
    $res = toobit_api_call('/api/v1/futures/historyOrders', $api_key, $ss_key, $params);
    $min_qty = null;

    if ($res['status'] === true && !empty($res['data'])) {
        $list = [];
        if (isset($res['data']['data']['list'])) $list = $res['data']['data']['list'];
        elseif (is_array($res['data']) && isset($res['data'][0])) $list = $res['data'];
        elseif (isset($res['data']['data']) && is_array($res['data']['data'])) $list = $res['data']['data'];

        $symbol_upper = strtoupper($symbol);
        $multiplier = 1;
        if (strpos($symbol_upper, 'BTC') !== false) $multiplier = 0.001;
        elseif (strpos($symbol_upper, 'ETH') !== false) $multiplier = 0.01;
        elseif (strpos($symbol_upper, 'XRP') !== false) $multiplier = 10;

        foreach ($list as $v) {
            $raw_qty = 0;
            $qty_fields = ['origQty', 'volume', 'amount', 'qty', 'executedQty'];
            foreach ($qty_fields as $field) {
                if (isset($v[$field]) && floatval($v[$field]) > 0) {
                    $raw_qty = floatval($v[$field]);
                    break;
                }
            }

            $real_qty_val = $raw_qty * $multiplier;

            // 소수점 3자리까지만 남기고 버림 처리 (0.001 미만 체크 목적)
            $real_qty_val = floor($real_qty_val * 1000) / 1000;

            if ($real_qty_val >= 0.001) {
                if ($min_qty === null || $real_qty_val < $min_qty) {
                    $min_qty = $real_qty_val;
                }
            }
        }
    }

    if ($min_qty !== null) {
        return $min_qty;
    }
    return 0;
}

// ==================================================================
// [AJAX] 실시간 포지션 갱신 엔드포인트
// ==================================================================
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_positions') {
    header('Content-Type: application/json');
    $ajax_symbol = isset($_GET['symbol']) ? trim($_GET['symbol']) : '';

    if (!empty($api_key) && !empty($ss_key)) {
        $result = rx_get_current_positions($api_key, $ss_key, $ajax_symbol);

        if ($result['status'] === true && !empty($result['data'])) {
            foreach ($result['data'] as $key => $row) {
                $initial_qty = rx_get_bot_initial_qty_toobit($api_key, $ss_key, $row['symbol']);
                $result['data'][$key]['initial_qty'] = $initial_qty;
            }
        }
        echo json_encode($result);
    } else {
        echo json_encode(['status' => false, 'msg' => t('err_no_api_key')]);
    }
    exit;
}

// ==================================================================
// [AJAX] 실제 자산 조회 (모달 검증용) 엔드포인트
// ==================================================================
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_balances') {
    header('Content-Type: application/json');
    if (!empty($api_key) && !empty($ss_key)) {
        $actual_fut_bal = 0;
        $actual_spot_bal = 0;

        // 선물 자산 조회
        $res_fut = rx_get_futures_balance($api_key, $ss_key);
        if ($res_fut['status'] === true && isset($res_fut['data']['balance'])) {
            $actual_fut_bal = floatval($res_fut['data']['balance']);
        }

        // 현물 자산 조회
        $res_spot = rx_get_spot_balance($api_key, $ss_key);
        if ($res_spot['status'] === true) {
            $free = floatval($res_spot['data']['free'] ?? 0);
            $locked = floatval($res_spot['data']['locked'] ?? 0);
            $actual_spot_bal = $free + $locked;
        }

        echo json_encode([
            'status' => true,
            'fut_bal' => $actual_fut_bal,
            'spot_bal' => $actual_spot_bal
        ]);
    } else {
        echo json_encode(['status' => false, 'msg' => t('err_no_api_key')]);
    }
    exit;
}

// ==================================================================
// [로직 처리 & 데이터 로드]
// ==================================================================
$current_list = []; $history_list_page = [];
$wallet_fut = []; $wallet_spot = [];
$error_msg = ""; $fatal_error = false;

$pnl_today = 0; $pnl_yesterday = 0; $roi_today_pct = 0; $roi_yesterday_pct = 0;
$pnl_30days = 0; $roi_30days_pct = 0; $pnl_total = 0; $roi_total_pct = 0;
$total_trading_days = 0;
$date_30_ago = date("Y-m-d", strtotime("-30 days"));

$page = isset($_GET['page']) && $_GET['page'] > 0 ? intval($_GET['page']) : 1;
$rows_per_page = 20; $total_rows = 0; $total_pages = 0;

$cal_year  = isset($_GET['cal_year'])  ? intval($_GET['cal_year'])  : intval(date('Y'));
$cal_month = isset($_GET['cal_month']) ? intval($_GET['cal_month']) : intval(date('n'));

if (empty($api_key) || empty($ss_key)) {
    $error_msg = t('err_api_keys_missing');
    $fatal_error = true;
}

if (!$fatal_error) {
    $res_fut = rx_get_futures_balance($api_key, $ss_key);
    if ($res_fut['status']) { $wallet_fut = $res_fut['data']; }
    else { $error_msg = t('err_fut_bal_fail') . ": " . $res_fut['msg']; $fatal_error = true; }

    $res_spot = rx_get_spot_balance($api_key, $ss_key);
    if ($res_spot['status']) { $wallet_spot = $res_spot['data']; }
    else { $error_msg = t('err_spot_bal_fail') . ": " . $res_spot['msg']; $fatal_error = true; }

    $res_curr = rx_get_current_positions($api_key, $ss_key, $s_symbol);
    if ($res_curr['status']) { $current_list = $res_curr['data']; }
    else { $error_msg = t('err_pos_fetch') . ": " . $res_curr['msg']; $fatal_error = true; }

    if (!$fatal_error) {
        $res_hist = rx_get_history_positions_300($api_key, $ss_key, $s_symbol);
        if ($res_hist['status']) {
            $api_daily_pnl = [];
            $filtered_history = $res_hist['data'];

            foreach ($filtered_history as $trade) {
                $closeTimeSec = (isset($trade['closeTime']) ? floatval($trade['closeTime']) : 0) / 1000;
                $trade_date_str = date("Y-m-d", $closeTimeSec);
                $pnl = isset($trade['realizedPnL']) ? floatval($trade['realizedPnL']) : 0;

                if (!isset($api_daily_pnl[$trade_date_str])) $api_daily_pnl[$trade_date_str] = 0;
                $api_daily_pnl[$trade_date_str] += $pnl;
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
            $error_msg = t('err_hist_fetch') . ": " . $res_hist['msg'];
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
    $res_tx = $conn->query("SELECT week_tag, txid FROM rx_weekly_txid WHERE uid = '{$uid_safe}'");
    if ($res_tx && $res_tx->num_rows > 0) {
        while($tr = $res_tx->fetch_assoc()) {
            $txid_map[$tr['week_tag']] = $tr['txid'];
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

function renderWeeklyTable($db_pnl, $db_equity, $txid_map) {
    if(empty($db_pnl)) return "<p class='text-center text-muted p-4'>" . t('msg_no_data') . "</p>";

    $weekly_data = [];
    foreach ($db_pnl as $date => $pnl) {
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
        $weekly_data[$yearWeek]['equity_avg'] += ($db_equity[$date] ?? 0);
        $weekly_data[$yearWeek]['days']++;
    }

    $html = '<div class="table-responsive"><table class="table table-hover text-center align-middle">';
    // [수정] 평균투자금 삭제, 입금액 헤더 주석 처리
    $html .= '<thead class="table-light"><tr><th>' . t('th_week_range') . '</th><th>' . t('th_week_pnl') . '</th><!-- <th>입금액</th> --><th>' . t('th_week_roi') . '</th><th>' . t('th_txid') . '</th></tr></thead><tbody>';

    krsort($weekly_data);
    foreach ($weekly_data as $yw => $data) {
        $avg_eq = $data['days'] > 0 ? ($data['equity_avg'] / $data['days']) : 0;
        $roi = ($avg_eq > 0) ? ($data['pnl'] / $avg_eq) * 100 : 0;

        $pnlClass = $data['pnl'] >= 0 ? 'text-success fw-bold' : 'text-danger fw-bold';
        $txid_display = isset($txid_map[$yw]) ? '<code>'.htmlspecialchars($txid_map[$yw]).'</code>' : '<span class="text-muted">-</span>';

        // [수정] 입금액 계산 로직 주석 처리
        // $deposit_amt = $data['pnl'] * 0.2;

        $html .= '<tr>';
        $html .= '<td>' . $data['range'] . '</td>';

        // [수정] 주간손익 정수로 표기
        $html .= '<td class="'.$pnlClass.'">' . ($data['pnl'] > 0 ? '+' : '') . format_truncate($data['pnl'], 0) . '</td>';

        // [수정] 입금액 데이터 열 주석 처리
        /* $html .= '<td class="fw-bold text-primary">' . ($deposit_amt > 0 ? '+' : '') . format_truncate($deposit_amt, 0) . '</td>';
        */

        // 수익률은 기존 소수점 유지
        $html .= '<td class="'.$pnlClass.'">' . ($roi > 0 ? '+' : '') . format_truncate($roi, 2) . '%</td>';

        $html .= '<td class="text-primary fw-bold">' . $txid_display . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

$weekly_data_info = [];
if (!$fatal_error && !empty($db_daily_pnl)) {
    foreach ($db_daily_pnl as $date => $pnl) {
        $ts = strtotime($date);
        $yearWeek = date('o-W', $ts);
        if(!isset($weekly_data_info[$yearWeek])) {
            $dto = new DateTime();
            $dto->setISODate((int)date('o', $ts), (int)date('W', $ts));
            $mon = $dto->format('Y-m-d'); $dto->modify('+6 days'); $sun = $dto->format('Y-m-d');
            $weekly_data_info[$yearWeek] = $mon . ' ~ ' . $sun;
        }
    }
    krsort($weekly_data_info);
}

?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang ?? 'ko'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($exchange_name); ?> <?= t('dash_title') ?> - <?php echo h($s_uid); ?></title>
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

        .section-title { font-weight: 700; margin-bottom: 15px; color: #343a40; border-left: 5px solid #6f42c1; padding-left: 10px; }
        .card-fut { background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%); color: white; }
        .card-spot { background: linear-gradient(135deg, #fd7e14 0%, #d96203 100%); color: white; }
        .balance-value { font-size: 1.5rem; font-weight: bold; }
        .pagination { justify-content: center; margin-top: 20px; }
        .page-link { color: #333; }
        .page-item.active .page-link { background-color: #6f42c1; border-color: #6f42c1; color: #fff; }

        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .top-bar .user-info { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        .top-bar .user-badge { background: #6f42c1; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
        .top-bar .exchange-badge { background: #198754; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
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
        .calendar-day.today { border: 2px solid #6f42c1; background: #f3e8ff; }
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
        }
    </style>
</head>
<body>

<div class="container">
    <div class="top-bar">
        <h2 class="fw-bold mb-0">📊 <?= t('dash_title') ?></h2>
        <div class="user-info d-flex align-items-center gap-3">
            <?= render_language_selector() ?>
            <span class="exchange-badge"><?php echo h($exchange_name); ?></span>
            <span class="user-badge">UID: <?php echo h($s_uid); ?></span>
            <?php if (!$admin_view): ?>
            <a href="change_password.php" class="btn btn-outline-secondary btn-sm-custom">🔐 <?= t('dash_chg_pwd') ?></a>
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
            $alert_msg = t('msg_txid_ok');
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
        } elseif (strpos($msg_val, 'uid_error_') === 0) {
            $fail_reason = htmlspecialchars(urldecode(substr($msg_val, 10)));
            $alert_msg = "UID error {$fail_reason}";
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
            <input type="hidden" name="cal_year" value="<?php echo $cal_year; ?>">
            <input type="hidden" name="cal_month" value="<?php echo $cal_month; ?>">
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
                            <a href="<?php echo htmlspecialchars($current_page); ?>?s_symbol=<?php echo urlencode($s_symbol); ?>" class="btn btn-warning">🔄 <?= t('btn_retry') ?></a>
                        </div>
                    </div>
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
                    <?php
                        $fut_total = isset($wallet_fut['balance']) ? $wallet_fut['balance'] : 0;
                        $fut_avail = isset($wallet_fut['availableBalance']) ? $wallet_fut['availableBalance'] : 0;
                    ?>
                    <div class="col-md-6">
                        <div class="card p-3 card-fut">
                            <div class="d-flex justify-content-between"><span>⚡ <?= t('lbl_fut_wallet') ?></span> <small><?= t('lbl_base_amt') ?>: <?php echo format_truncate($current_investment_amount, 0); ?> USDT</small></div>
                            <div class="balance-value mt-2"><?php echo format_truncate($fut_total, 4); ?></div>
                            <div class="mt-1 opacity-75"><?= t('lbl_use_avail') ?>: <?php echo format_truncate($fut_avail, 4); ?></div>
                        </div>
                    </div>
                    <?php
                        $spot_free = isset($wallet_spot['free']) ? $wallet_spot['free'] : 0;
                        $spot_locked = isset($wallet_spot['locked']) ? $wallet_spot['locked'] : 0;
                        $spot_total = $spot_free + $spot_locked;
                    ?>
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
                                    $symbol_full = strtoupper($row['symbol']);
                                    $symbol_short = explode('-', $symbol_full)[0];

                                    $multiplier = 1;
                                    if (strpos($symbol_full, 'BTC') !== false) $multiplier = 0.001;
                                    elseif (strpos($symbol_full, 'ETH') !== false) $multiplier = 0.01;
                                    elseif (strpos($symbol_full, 'XRP') !== false) $multiplier = 10;

                                    $real_qty = floatval($row['position'] ?? 0) * $multiplier;
                                    $initial_qty = rx_get_bot_initial_qty_toobit($api_key, $ss_key, $row['symbol']);

                                    $martingale_count = 0;
                                    if ($initial_qty > 0 && $real_qty > 0) {
                                        $martingale_count = round($real_qty / $initial_qty);
                                    }
                                    $martingale_text = $martingale_count > 0 ? "<span class=\"badge bg-success\">{$martingale_count}" . t('lbl_times') . "</span>" : "-";

                                    $avgPrice = floatval($row['avgPrice'] ?? 0);
                                    $markPrice = floatval($row['markPrice'] ?? 0);
                                    $openValue = $avgPrice * $real_qty;
                                    $closeValue = $markPrice * $real_qty;

                                    $totalFee = ($openValue + $closeValue) * 0.0006;
                                    $netUnrealizedPnL = floatval($row['unrealizedPnL'] ?? 0) - $totalFee;

                                    $lever = floatval($row['leverage'] ?? 50);
                                    if ($lever <= 0) $lever = 50;
                                    $margin = ($openValue > 0) ? ($openValue / $lever) : 0;
                                    $netRoi = ($margin > 0) ? ($netUnrealizedPnL / $margin) : 0;

                                    $sideClass = ($row['side'] == 'LONG') ? 'text-long' : 'text-short';
                                    $pnlClass = ($netUnrealizedPnL >= 0) ? 'text-profit' : 'text-loss';
                                    $barClass = ($row['side'] == 'LONG') ? 'long' : 'short';
                                ?>
                                <tr>
                                    <td class="fw-bold text-start ps-4"><span class="vertical-bar <?php echo $barClass; ?>"></span><?php echo h($symbol_short); ?></td>
                                    <td class="<?php echo $sideClass; ?>"><?php echo h($row['side'] ?? '-'); ?></td>
                                    <td>x<?php echo h($row['leverage'] ?? '-'); ?></td>
                                    <td><?php echo format_truncate($initial_qty, 3); ?></td>
                                    <td><?php echo format_truncate($real_qty, 3); ?></td>
                                    <td><?php echo $martingale_text; ?></td>
                                    <td><?php echo format_truncate($avgPrice, 1); ?></td>
                                    <td><?php echo format_truncate($markPrice, 1); ?></td>
                                    <td class="text-danger"><?php echo format_truncate($row['flp'] ?? 0, 1); ?></td>
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
                                <tr>
                                    <th><?= t('th_close_time') ?></th>
                                    <th><?= t('th_position') ?></th>
                                    <th><?= t('th_leverage') ?></th>
                                    <th><?= t('th_entry_qty') ?></th>
                                    <th><?= t('th_entry_px') ?></th>
                                    <th><?= t('th_close_px') ?></th>
                                    <th><?= t('th_real_pnl') ?></th>
                                    <th><?= t('th_roi') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history_list_page as $row):
                                    $sideClass = (($row['side'] ?? '-') == 'LONG') ? 'text-long' : 'text-short';

                                    $pnl = isset($row['realizedPnL']) ? floatval($row['realizedPnL']) : 0;
                                    $pnlClass = ($pnl >= 0) ? 'text-profit' : 'text-loss';
                                ?>
                                <tr>
                                    <td><?php echo isset($row['closeTime']) ? date('Y-m-d H:i:s', $row['closeTime'] / 1000) : '-'; ?></td>
                                    <td class="<?php echo $sideClass; ?> fw-bold"><?php echo h($row['side'] ?? '-'); ?></td>
                                    <td>x<?php echo h($row['leverage'] ?? '-'); ?></td>
                                    <td><?php echo format_truncate($row['closedVolume'] ?? 0, 3); ?></td>
                                    <td><?php echo format_truncate($row['openAvgPrice'] ?? 0, 1); ?></td>
                                    <td><?php echo format_truncate($row['closeAvgPrice'] ?? 0, 1); ?></td>
                                    <td class="<?php echo $pnlClass; ?> fw-bold"><?php echo ($pnl > 0 ? '+' : '') . format_truncate($pnl, 4); ?></td>
                                    <td class="<?php echo $pnlClass; ?>"><?php echo (($row['realizedPnlRate']??0) > 0 ? '+' : '') . format_truncate(($row['realizedPnlRate'] ?? 0) * 100, 2); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php
                    $get_pagging_add = "";
                    if($admin_view == 1){
                        $get_pagging_add = "admin_view=".$_GET['admin_view']."&admin_uid=".$_GET['admin_uid']."&admin_ce=".$_GET['admin_ce']."&";
                    }
                    if ($total_pages > 1):
                    ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination mt-3">
                            <?php if ($page > 1): ?>
                            <li class="page-item"><a class="page-link" href="?<?=$get_pagging_add?>page=<?php echo $page - 1; ?>&s_symbol=<?php echo h($s_symbol); ?>&cal_year=<?php echo $cal_year; ?>&cal_month=<?php echo $cal_month; ?>"><?= t('btn_prev') ?></a></li>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);

                            if ($start_page > 1): ?>
                            <li class="page-item"><a class="page-link" href="?<?=$get_pagging_add?>page=1&s_symbol=<?php echo h($s_symbol); ?>&cal_year=<?php echo $cal_year; ?>&cal_month=<?php echo $cal_month; ?>">1</a></li>
                            <?php if ($start_page > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>"><a class="page-link" href="?<?=$get_pagging_add?>page=<?php echo $i; ?>&s_symbol=<?php echo h($s_symbol); ?>&cal_year=<?php echo $cal_year; ?>&cal_month=<?php echo $cal_month; ?>"><?php echo $i; ?></a></li>
                            <?php endfor; ?>

                            <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                            <li class="page-item"><a class="page-link" href="?<?=$get_pagging_add?>page=<?php echo $total_pages; ?>&s_symbol=<?php echo h($s_symbol); ?>&cal_year=<?php echo $cal_year; ?>&cal_month=<?php echo $cal_month; ?>"><?php echo $total_pages; ?></a></li>
                            <?php endif; ?>

                            <?php if ($page < $total_pages): ?>
                            <li class="page-item"><a class="page-link" href="?<?=$get_pagging_add?>page=<?php echo $page + 1; ?>&s_symbol=<?php echo h($s_symbol); ?>&cal_year=<?php echo $cal_year; ?>&cal_month=<?php echo $cal_month; ?>"><?= t('btn_next') ?></a></li>
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
                $bParam = $admin_view ? "&admin_view=1&admin_uid=" . urlencode($admin_uid) . "&admin_ce=" . $current_ce : "";
                $prevUrl = "?s_symbol=".urlencode($s_symbol)."{$bParam}&cal_year=".($cal_month==1?$cal_year-1:$cal_year)."&cal_month=".($cal_month==1?12:$cal_month-1)."#tab-cal";
                $nextUrl = "?s_symbol=".urlencode($s_symbol)."{$bParam}&cal_year=".($cal_month==12?$cal_year+1:$cal_year)."&cal_month=".($cal_month==12?1:$cal_month+1)."#tab-cal";
                $todayUrl = "?s_symbol=".urlencode($s_symbol)."{$bParam}&cal_year=".date('Y')."&cal_month=".date('n')."#tab-cal";
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
                <div class="row">
                    <div class="col-lg-8 mb-3">
                        <h4 class="section-title">📊 <?= t('title_week_roi') ?></h4>
                        <div class="card p-3 h-100">
                            <?php echo renderWeeklyTable($db_daily_pnl, $db_daily_equity, $txid_map); ?>
                        </div>
                    </div>

                    <div class="col-lg-4 mb-3">
                        <h4 class="section-title">🔗 <?= t('title_txid_input') ?></h4>
                        <div class="card p-4 h-100">
                            <form method="POST" action="?s_symbol=<?php echo urlencode($s_symbol); ?>#tab-week">
                                <input type="hidden" name="act" value="save_txid">
                                <?php if ($admin_view): ?>
                                    <input type="hidden" name="admin_view" value="1">
                                    <input type="hidden" name="admin_uid" value="<?php echo htmlspecialchars($admin_uid); ?>">
                                    <input type="hidden" name="admin_ce" value="<?php echo $current_ce; ?>">
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label class="form-label fw-bold"><?= t('lbl_select_week') ?></label>
                                    <select name="week_tag" class="form-select" required>
                                        <option value=""><?= t('opt_select_week') ?></option>
                                        <?php foreach($weekly_data_info as $yw => $range): ?>
                                            <option value="<?php echo h($yw); ?>"><?php echo h($range); ?> (<?php echo h($yw); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label fw-bold"><?= t('lbl_input_txid') ?></label>
                                    <input type="text" name="txid" class="form-control" placeholder="<?= t('ph_txid') ?>" required>
                                </div>
                                <button type="submit" class="btn btn-primary w-100 fw-bold"><?= t('btn_save_txid') ?></button>
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
                $get_pagging_add_hist = "s_symbol=" . urlencode($s_symbol) . "&";
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
                            <!-- [수정됨] id 추가 및 확인 메시지 제거 -->
                            <form method="POST" action="?s_symbol=<?php echo urlencode($s_symbol); ?>#tab-settings" id="settingsForm">
                                <input type="hidden" name="act" value="update_settings">
                                <?php if ($admin_view): ?>
                                    <input type="hidden" name="admin_view" value="1">
                                    <input type="hidden" name="admin_uid" value="<?php echo htmlspecialchars($admin_uid); ?>">
                                    <input type="hidden" name="admin_ce" value="<?php echo $current_ce; ?>">
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label class="form-label fw-bold"><?= t('lbl_api_key') ?></label>
                                    <input type="text" name="up_apikey" class="form-control" value="<?php echo htmlspecialchars($member['rx_apikey'] ?? ''); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold"><?= t('lbl_sec_key') ?></label>
                                    <input type="text" name="up_sskey" class="form-control" value="<?php echo htmlspecialchars($member['rx_sskey'] ?? ''); ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold"><?= t('lbl_reserve_set') ?></label>
                                    <div class="d-flex gap-2">
                                        <input type="radio" class="btn-check" name="up_reserve_percent" id="res0" value="0" <?php echo $current_reserve_percent == 0 ? 'checked' : ''; ?>>
                                        <label class="btn btn-outline-primary flex-fill" for="res0"><?= t('lbl_res_0') ?></label>

                                        <input type="radio" class="btn-check" name="up_reserve_percent" id="res30" value="30" <?php echo $current_reserve_percent == 30 ? 'checked' : ''; ?>>
                                        <label class="btn btn-outline-primary flex-fill" for="res30"><?= t('lbl_res_30') ?></label>

                                        <input type="radio" class="btn-check" name="up_reserve_percent" id="res100" value="100" <?php echo $current_reserve_percent == 100 ? 'checked' : ''; ?>>
                                        <label class="btn btn-outline-primary flex-fill" for="res100"><?= t('lbl_res_100') ?></label> <!-- -->
                                    </div>
                                    <small class="text-muted mt-1 d-block"><?= t('lbl_res_desc') ?></small>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold"><?= t('lbl_fut_amt') ?></label>
                                    <div class="input-group">
                                        <input type="text" inputmode="numeric" name="up_acount" class="form-control" value="<?php echo number_format(floatval($member['rx_acount'] ?? 0)); ?>" required oninput="this.value = this.value.replace(/[^0-9]/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');">
                                        <span class="input-group-text">USDT</span>
                                    </div>
                                    <small class="text-muted d-block mt-1"><?= t('lbl_fut_amt_desc') ?></small>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-bold"><?= t('lbl_spot_amt') ?></label>
                                    <div class="input-group">
                                        <input type="text" inputmode="numeric" name="up_spot_acount" class="form-control" value="<?php echo number_format(floatval($member['rx_spot_acount'] ?? 0)); ?>" required oninput="this.value = this.value.replace(/[^0-9]/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');">
                                        <span class="input-group-text">USDT</span>
                                    </div>
                                </div>

                                <!-- [수정됨] type을 button으로 변경, id 할당 -->
                                <button type="button" class="btn btn-primary w-100 fw-bold" id="btnCheckAndSave"><?= t('btn_save') ?></button>
                            </form>
                        </div>
                    </div>

                    <div class="col-lg-6 col-md-12 mb-3">
                        <h4 class="section-title">💸 <?= t('title_transfer') ?></h4>
                        <div class="card p-4 h-100">
                            <form method="POST" action="?s_symbol=<?php echo urlencode($s_symbol); ?>#tab-settings" onsubmit="return confirm('<?= t('confirm_transfer') ?>');">
                                <input type="hidden" name="act" value="transfer">
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

<!-- [수정됨] 자산 설정 확인 모달 추가 (언어팩 적용) -->
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

<script>
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

    const symbol = "<?php echo h($s_symbol); ?>";
    const tableBody = document.querySelector("#current-position-table tbody");
    const counterElement = document.getElementById("refresh-counter");

    if (tableBody) {
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
            let fetchUrl = `?ajax_action=get_positions&symbol=${encodeURIComponent(symbol)}`;

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
                let symbolFull = (row.symbol || "").toUpperCase();
                let symbolShort = symbolFull.split('-')[0];

                let multiplier = 1;
                if (symbolFull.includes('BTC')) multiplier = 0.001;
                else if (symbolFull.includes('ETH')) multiplier = 0.01;
                else if (symbolFull.includes('XRP')) multiplier = 10;

                let realQty = parseFloat(row.position || 0) * multiplier;
                let initialQty = parseFloat(row.initial_qty || 0);

                let martingaleCount = 0;
                if (initialQty > 0 && realQty > 0) {
                    martingaleCount = Math.round(realQty / initialQty);
                }
                let martingaleText = martingaleCount > 0 ? `<span class="badge bg-success">${martingaleCount}<?= t('lbl_times') ?></span>` : "-";

                let avgPrice = parseFloat(row.avgPrice || 0);
                let markPrice = parseFloat(row.markPrice || 0);
                let openValue = avgPrice * realQty;
                let closeValue = markPrice * realQty;

                let totalFee = (openValue + closeValue) * 0.0006;
                let netUnrealizedPnL = parseFloat(row.unrealizedPnL || 0) - totalFee;

                let lever = parseFloat(row.leverage || 50);
                if (lever <= 0) lever = 50;
                let margin = (openValue > 0) ? (openValue / lever) : 0;
                let netRoi = (margin > 0) ? (netUnrealizedPnL / margin) : 0;

                let sideClass = (row.side === 'LONG') ? 'text-long' : 'text-short';
                let pnlClass = (netUnrealizedPnL >= 0) ? 'text-profit' : 'text-loss';
                let barClass = (row.side === 'LONG') ? 'long' : 'short';

                let pnlSign = netUnrealizedPnL > 0 ? '+' : '';
                let roiSign = netRoi > 0 ? '+' : '';

                newHtml += `
                <tr>
                    <td class="fw-bold text-start ps-4">
                        <span class="vertical-bar ${barClass}"></span>${symbolShort || '-'}
                    </td>
                    <td class="${sideClass}">${row.side || '-'}</td>
                    <td>x${lever}</td>
                    <td>${fmtTruncate(initialQty, 3)}</td>
                    <td>${fmtTruncate(realQty, 3)}</td>
                    <td>${martingaleText}</td>
                    <td>${fmtTruncate(avgPrice, 1)}</td>
                    <td>${fmtTruncate(markPrice, 1)}</td>
                    <td class="text-danger">${fmtTruncate(row.flp, 1)}</td>
                    <td class="${pnlClass} fw-bold">${pnlSign}${fmtTruncate(netUnrealizedPnL, 4)}</td>
                    <td class="${pnlClass}">${roiSign}${fmtTruncate(netRoi * 100, 2)}%</td>
                </tr>
                `;
            });

            if (newHtml !== "") tableBody.innerHTML = newHtml;
        }
    }

    // === [수정됨] 자산 설정 검증 및 모달 제어 로직 (언어팩 적용) ===
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
            let fetchUrl = `?ajax_action=get_balances`;
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