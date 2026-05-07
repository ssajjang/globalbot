<?php
/**
 * logout.php - 로그아웃 처리
 */
require_once __DIR__ . '/includes/init.php';

// 감사 로그 기록
if (is_logged_in()) {
    write_audit_log('logout', $_SESSION['admin_id'] ?? '');
}

// 세션 완전 초기화
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header('Location: ' . BASE_URL . '/login.php');
exit;
