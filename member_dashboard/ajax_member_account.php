<?php
//ajax_member_account
require_once './_common.php';
$return_data = array();
$return_data['error']=0;
$return_data['masger']='금액 수정 성공';


$mb_no = htmlspecialchars($_POST['mb_no']);
$rx_account = htmlspecialchars($_POST['rx_account']);

if($_POST['mb_no'] == "" || $_POST['rx_account'] == ""  ){
	$return_data['error']=1;
	$return_data['masger']='필수 파라미터 없음';
}

$sql = "UPDATE g5_member SET rx_acount = '".$rx_account."' , mb_memo = CONCAT(mb_memo , '".date('Y-m-d H:i:s')." :: ".$rx_account." 투자금액수정 \\n') WHERE mb_no = '".$mb_no."';";
//echo $sql;
$result = sql_query($sql);

if(!$result){
	$return_data['error']=1;
	$return_data['masger']='수정 실패';
}else{
	$return_data['masger']='금액 수정 성공';

	// 저장 된 데이터 다시 불러 오기
	$sql = "select * from g5_member where mb_no = '".$mb_no."'";
	$result = sql_query($sql, false);
	$row1 = sql_fetch_array($result);

	// POST 데이터 구성
		$postData = array(
			'rx_uid'    => $row1['rx_uid'],
			'rx_ce'     => $row1['rx_ce'],
			'rx_apikey' => $row1['rx_apikey'],
			'rx_sskey'  => $row1['rx_sskey'],
			'rx_password'  => $row1['rx_password'],
			'rx_acount'  => $row1['rx_acount'],
		);

		// cURL 초기화
		$ch = curl_init();

		// cURL 옵션 설정
		curl_setopt($ch, CURLOPT_URL, 'https://dashboard.botbull.org/api_member.php');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // SSL 인증서 검증 비활성화 (필요시)
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/x-www-form-urlencoded',
		));

		// cURL 실행
		$response = curl_exec($ch);

		// cURL 에러 체크
		if (curl_errno($ch)) {
			$error_msg = curl_error($ch);
			curl_close($ch);
			//echo "cURL 통신 에러: " . $error_msg;
			// 필요시 로그 기록 또는 에러 처리
			$return_data['Message'] = "cURL 통신 에러: " . $error_msg;
			$return_data['error'] = '1';
			echo json_encode($return_data);
			exit;
		}

		// HTTP 상태 코드 확인
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($httpCode !== 200) {
			//echo "HTTP 에러 코드: " . $httpCode;
			// 필요시 로그 기록 또는 에러 처리
			$return_data['Message'] = "HTTP 에러 코드: " . $httpCode;
			$return_data['error'] = '1';
			echo json_encode($return_data);
			exit;
		}

		// JSON 디코딩
		$result = json_decode($response, true);

		// JSON 파싱 에러 체크
		if (json_last_error() !== JSON_ERROR_NONE) {
			//echo "JSON 파싱 에러: " . json_last_error_msg();
			//echo "\n응답 원문: " . $response;
			$return_data['Message'] = 'JSON 파싱 에러.';
			$return_data['error'] = '1';
			echo json_encode($return_data);
			exit;
		}

		// status 값 기준 분기 처리
		if (isset($result['status']) && $result['status'] === true) {
			// ✅ 성공 처리
			//echo "API 전송 성공!";

			// 성공 시 추가 처리 (예: DB 업데이트, 로그 기록 등)
			// $result 배열에서 필요한 데이터 활용 가능
			// 예: $result['data'], $result['message'] 등

		} else {
			// ❌ 에러 처리
			//$error_message = isset($result['message']) ? $result['message'] : '알 수 없는 에러';
			//echo "API 전송 실패: " . $error_message;
			$return_data['Message'] = '데시보드 API 전송 실패.';
			$return_data['error'] = '1';
			echo json_encode($return_data);
			exit;
			// 실패 시 추가 처리 (예: 에러 로그 기록, 알림 등)
		}
	########################################################################################
}


echo json_encode($return_data);