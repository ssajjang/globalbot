<?php
//ajax_member_account
require_once './_common.php';
$return_data = array();
$return_data['error']=0;
$return_data['masger']='금액 수정 성공';


$mb_id = htmlspecialchars($_POST['mb_id']);
$mb_class = htmlspecialchars($_POST['mb_class']);

if($_POST['mb_no'] == "" || $_POST['rx_account'] == ""  ){
	$return_data['error']=1;
	$return_data['masger']='필수 파라미터 없음';
}

$sql = "UPDATE g5_member SET mb_class = '".$mb_class."' , mb_memo = CONCAT(mb_memo , '".date('Y-m-d H:i:s')." :: ".$mb_class." 클래스 수정 \\n') WHERE mb_id = '".$mb_id."';";
//echo $sql;
$result = sql_query($sql);

if(!$result){
	$return_data['error']=1;
	$return_data['masger']='수정 실패';
}else{
	$return_data['masger']='클래스 수정 성공';
}


echo json_encode($return_data);