<?php
/**
 * sidebar.php - 공통 사이드바 (햄버거 Collapse 지원)
 * 총어드민/센터어드민 권한에 따라 메뉴 자동 분기
 */
$is_super = is_super_admin();
$admin_role_label = $is_super ? '총 어드민' : '센터 어드민';
$admin_center_label = $is_super ? '전체 CENTER' : h(get_admin_center() ?? '');
?>
<nav class="sidebar" id="sidebar">
  <!-- 로고 -->
  <div class="sidebar-logo">
    <span class="logo-icon">⚡</span>
    <span class="logo-text">Global Admin</span>
  </div>

  <!-- 메뉴 -->
  <ul class="sidebar-nav" style="list-style:none;padding:0;">

    <!-- 1. 대시보드 (공통) -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/modules/dashboard/dashboard.php"
         class="nav-link <?= $current_page === 'dashboard' ? 'active' : '' ?>">
        <span class="nav-icon">📊</span>
        <span class="nav-label">대시보드</span>
      </a>
    </li>

    <!-- 2. 회원관리 (공통) -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/modules/members/members.php"
         class="nav-link <?= $current_page === 'members' ? 'active' : '' ?>">
        <span class="nav-icon">👥</span>
        <span class="nav-label">회원관리</span>
      </a>
    </li>

    <!-- 2-1. 회원 대시보드 (총어드민 + 센터어드민 공통) -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/modules/member_view/member_view.php"
         class="nav-link <?= $current_page === 'member_view' ? 'active' : '' ?>">
        <span class="nav-icon">📈</span>
        <span class="nav-label">회원 대시보드</span>
      </a>
    </li>

    <?php if ($is_super): ?>
    <!-- ── 총어드민 전용 메뉴 ── -->

    <!-- 3. Bot 세팅 -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/modules/bot_setting/bot_setting.php"
         class="nav-link <?= $current_page === 'bot_setting' ? 'active' : '' ?>">
        <span class="nav-icon">⚙️</span>
        <span class="nav-label">Bot 세팅</span>
      </a>
    </li>

    <!-- 4. Bot 프로세스 -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/modules/bot_process/bot_process.php"
         class="nav-link <?= $current_page === 'bot_process' ? 'active' : '' ?>">
        <span class="nav-icon">🤖</span>
        <span class="nav-label">Bot 프로세스</span>
      </a>
    </li>

    <!-- 5. 관리 Bot -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/modules/admin_bot/admin_bot.php"
         class="nav-link <?= $current_page === 'admin_bot' ? 'active' : '' ?>">
        <span class="nav-icon">🛠️</span>
        <span class="nav-label">관리 Bot</span>
      </a>
    </li>

    <!-- 6. Bot 제어 -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/modules/bot_control/bot_control.php"
         class="nav-link <?= $current_page === 'bot_control' ? 'active' : '' ?>">
        <span class="nav-icon">🎮</span>
        <span class="nav-label">Bot 제어</span>
      </a>
    </li>

    <li><div class="nav-divider"></div></li>

    <!-- 7. 멀티도메인 관리 -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/modules/multi_domain/multi_domain.php"
         class="nav-link <?= $current_page === 'multi_domain' ? 'active' : '' ?>">
        <span class="nav-icon">🌐</span>
        <span class="nav-label">멀티도메인 관리</span>
      </a>
    </li>

    <!-- 8. 전송기록 -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/modules/telegram/trans_list.php"
         class="nav-link <?= $current_page === 'trans_list' ? 'active' : '' ?>">
        <span class="nav-icon">📨</span>
        <span class="nav-label">전송기록</span>
      </a>
    </li>

    <!-- 9. 센터텔레그램 -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/modules/telegram/manual_transfer.php"
         class="nav-link <?= $current_page === 'manual_transfer' ? 'active' : '' ?>">
        <span class="nav-icon">📨</span>
        <span class="nav-label">센터텔레그램</span>
      </a>
    </li>

    <!-- 10. 구독료통계 -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/modules/statistics/subscription_stat.php"
         class="nav-link <?= $current_page === 'subscription_stat' ? 'active' : '' ?>">
        <span class="nav-icon">💰</span>
        <span class="nav-label">구독료통계</span>
      </a>
    </li>

    <!-- 11. 로그 시스템 -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/modules/logs/audit_log.php"
         class="nav-link <?= $current_page === 'audit_log' ? 'active' : '' ?>">
        <span class="nav-icon">📋</span>
        <span class="nav-label">로그 시스템</span>
      </a>
    </li>

    <!-- 12. 관리자 계정관리 -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/modules/admin_manager/admin_manager.php"
         class="nav-link <?= $current_page === 'admin_manager' ? 'active' : '' ?>">
        <span class="nav-icon">🔑</span>
        <span class="nav-label">관리자 계정관리</span>
      </a>
    </li>

    <!-- 13. DB 백업 -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/modules/db_backup/db_backup.php"
         class="nav-link <?= $current_page === 'db_backup' ? 'active' : '' ?>">
        <span class="nav-icon">💾</span>
        <span class="nav-label">DB 백업</span>
      </a>
    </li>

    <!-- 14. 시스템 검증 -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/modules/system/system_check.php"
         class="nav-link <?= $current_page === 'system_check' ? 'active' : '' ?>">
        <span class="nav-icon">🔍</span>
        <span class="nav-label">시스템 검증</span>
      </a>
    </li>

    <!-- 15. IP 화이트리스트 -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/modules/system/white_ip.php"
         class="nav-link <?= $current_page === 'white_ip' ? 'active' : '' ?>">
        <span class="nav-icon">🔒</span>
        <span class="nav-label">IP 화이트리스트</span>
      </a>
    </li>

    <?php else: ?>
    <!-- ── 센터어드민 전용 메뉴 ── -->

    <!-- 회원대시보드 보기 (센터어드민도 공통 메뉴 사용) -->
    <?php endif; ?>

    <li><div class="nav-divider"></div></li>

    <!-- 로그아웃 -->
    <li class="nav-item">
      <a href="<?= BASE_URL ?>/logout.php" class="nav-link"
         onclick="return confirm('로그아웃 하시겠습니까?')">
        <span class="nav-icon">🚪</span>
        <span class="nav-label">로그아웃</span>
      </a>
    </li>
  </ul>

  <!-- 하단 관리자 정보 -->
  <div class="sidebar-footer">
    <div class="admin-badge">
      <div class="badge-avatar">👤</div>
      <div class="badge-info">
        <div class="badge-name"><?= h($_SESSION['admin_id'] ?? '') ?></div>
        <div class="badge-role"><?= $admin_role_label ?> | <?= $admin_center_label ?></div>
      </div>
    </div>
  </div>
</nav>

<!-- 모바일 오버레이 -->
<div id="sidebar-overlay" onclick="closeMobileSidebar()"
  style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;backdrop-filter:blur(2px)"></div>
