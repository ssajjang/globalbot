<?php
/**
 * index.php - 진입점 (로그인 체크 후 대시보드로 이동)
 */
require_once __DIR__ . '/includes/init.php';

if (is_logged_in()) {
    header('Location: ' . BASE_URL . '/modules/dashboard/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/login.php');
}
exit;
