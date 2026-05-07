<?php
/**
 * init_public.php - 공개 페이지용 초기화 파일
 * [정책] 이 파일을 include하는 페이지 = 공개 페이지 → IP 화이트리스트 체크 안 함
 * 센터대시보드, 회원대시보드 등 외부 접근이 필요한 페이지에서 사용
 */

// ============================================================
// 오류 표시 설정
// ============================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ============================================================
// 필수 파일 로드 (IP 체크 없음)
// ============================================================
$base_path = dirname(__DIR__);

require_once $base_path . '/config/config.php';
require_once $base_path . '/config/database.php';
require_once $base_path . '/includes/auth_check.php';

// ============================================================
// 현재 페이지 URL 추출
// ============================================================
$current_page = basename($_SERVER['PHP_SELF'], '.php');
