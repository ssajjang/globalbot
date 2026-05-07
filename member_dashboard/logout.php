<?php
/**
 * logout.php - 로그아웃 처리
 */
require_once(__DIR__ . '/common.php');

// 세션 파괴
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header('Location: login.php');
exit;