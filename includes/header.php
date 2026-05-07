<?php
/**
 * header.php - 공통 HTML 헤더 + 탑바
 * $page_title 변수를 include 전에 설정해서 사용
 */
$page_title = $page_title ?? '관리자';
?>
<!DOCTYPE html>
<html lang="ko" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= h($page_title) ?> | <?= ADMIN_TITLE ?></title>

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- DataTables -->
  <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
  <!-- Custom CSS -->
  <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>

<?php include __DIR__ . '/sidebar.php'; ?>

<!-- 탑바 -->
<header class="topbar" id="topbar">
  <!-- 햄버거 버튼 -->
  <button id="sidebar-toggle" aria-label="사이드바 열기/닫기" title="메뉴 접기/펼치기">
    <span></span><span></span><span></span>
  </button>

  <!-- 현재 페이지 제목 -->
  <div class="topbar-title"><?= h($page_title) ?></div>

  <!-- 우측 액션 버튼들 -->
  <div class="topbar-actions">
    <!-- 실시간 시계 -->
    <span id="live-clock" style="color:var(--text-muted);font-size:0.82rem;font-family:monospace;"></span>

    <!-- 화이트/다크 모드 토글 -->
    <button class="theme-toggle" id="theme-toggle" title="테마 변경">
      <span id="theme-icon">☀️</span> 화이트
    </button>

    <!-- 관리자 정보 (PC 전용) -->
    <span style="color:var(--text-muted);font-size:0.8rem;display:none;" class="d-lg-inline">
      <?= h($_SESSION['admin_id'] ?? '') ?>
    </span>
  </div>
</header>

<!-- 메인 콘텐츠 시작 -->
<main class="main-content" id="main-content">
