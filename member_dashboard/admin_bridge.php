<?php
/**
 * admin_bridge.php - 관리자 세션 브릿지
 *
 * [역할] 관리자가 회원 대시보드를 iframe으로 볼 때
 *        레거시 대시보드의 세션(rx_session)에 is_admin=true를 설정
 *
 * [호출] member_view.php iframe → 이 파일 → dashboard*.php로 리다이렉트
 *
 * [보안] 관리자 세션(PHPSESSID)의 admin_id 존재 여부로 인증
 */

// 1단계: 관리자 세션(PHPSESSID)에서 관리자 여부 확인
session_start(); // 기본 PHPSESSID 세션
$is_valid_admin = isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
$admin_role     = $_SESSION['admin_role'] ?? '';
$admin_center   = $_SESSION['admin_center'] ?? '';
session_write_close(); // 기본 세션 닫기

if (!$is_valid_admin) {
    http_response_code(403);
    die('관리자 인증 실패');
}

// 파라미터 확인
$admin_uid = trim($_GET['admin_uid'] ?? '');
$admin_ce  = intval($_GET['admin_ce'] ?? 1);
$dash_type = trim($_GET['dash'] ?? 'toobit'); // toobit, deepcoin, websea

if (empty($admin_uid)) {
    die('admin_uid 필수');
}

// 센터어드민은 자기 센터 회원만 접근 가능
if ($admin_role === 'center' && !empty($admin_center)) {
    // DB에서 회원의 센터 확인 (간단한 직접 쿼리)
    require_once __DIR__ . '/config.php';
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    $uid_safe = $conn->real_escape_string($admin_uid);
    $center_safe = $conn->real_escape_string($admin_center);
    $check = $conn->query("SELECT idx FROM rx_member WHERE rx_uid='{$uid_safe}' AND mb_center='{$center_safe}' LIMIT 1");
    if (!$check || $check->num_rows === 0) {
        $conn->close();
        http_response_code(403);
        die('해당 센터의 회원이 아닙니다.');
    }
    $conn->close();
}

// 2단계: 레거시 세션(rx_session)을 열어 admin 플래그 설정
session_name('rx_session');
session_start();
$_SESSION['is_admin'] = true; // 대시보드의 admin_view 권한 부여
$_SESSION['admin_bridge_from'] = 'global_admin_147'; // 브릿지 출처 표시
session_write_close();

// 3단계: 대시보드 파일로 리다이렉트
$dash_file = 'dashboard.php';
if ($dash_type === 'deepcoin' || $admin_ce == 4) $dash_file = 'dashboard_deepcoin.php';
if ($dash_type === 'websea'   || $admin_ce == 3) $dash_file = 'dashboard_websea.php';

$redirect_url = dirname($_SERVER['SCRIPT_NAME']) . '/' . $dash_file
    . '?admin_view=1'
    . '&admin_uid=' . urlencode($admin_uid)
    . '&admin_ce=' . $admin_ce;

header('Location: ' . $redirect_url);
exit;
