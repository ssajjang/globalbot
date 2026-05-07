<?php
/**
 * login.php - 관리자 로그인 페이지
 * 보안: IP+UA 체크, 10회 실패 시 30분 잠금, CSRF 토큰
 */
require_once __DIR__ . '/includes/init.php';

// 이미 로그인 상태면 대시보드로 이동
if (is_logged_in()) {
    header('Location: ' . BASE_URL . '/modules/dashboard/dashboard.php');
    exit;
}

$error = '';
$success = '';

// ============================================================
// POST 처리 - 로그인 시도
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 검증
    if (!verify_csrf_token($_POST[CSRF_TOKEN_KEY] ?? '')) {
        $error = '보안 토큰이 유효하지 않습니다. 페이지를 새로 고침해주세요.';
    } else {
        $input_id  = trim($_POST['admin_id']  ?? '');
        $input_pw  = trim($_POST['admin_pw']  ?? '');
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if (empty($input_id) || empty($input_pw)) {
            $error = '아이디와 비밀번호를 모두 입력해주세요.';
        } else {
            // 계정 잠금 체크 (세션 기반)
            $lock_key = 'login_fail_' . md5($input_id . $client_ip);
            $fail_count = (int)($_SESSION[$lock_key . '_count'] ?? 0);
            $lock_until = $_SESSION[$lock_key . '_until'] ?? 0;

            if ($lock_until > time()) {
                // 잠금 중
                $remain = ceil(($lock_until - time()) / 60);
                $error = "로그인 {$remain}분 후 다시 시도해주세요. (IP 잠금 중)";
            } else {
                // 관리자 계정 DB 조회
                $admin = db_row(
                    "SELECT * FROM admin_accounts WHERE admin_id = ? AND is_active = 1 LIMIT 1",
                    [$input_id]
                );

                if ($admin && password_verify($input_pw, $admin['admin_pw'])) {
                    // ✅ 로그인 성공
                    session_regenerate_id(true); // 세션 고정 공격 방지

                    $_SESSION['admin_id']     = $admin['admin_id'];
                    $_SESSION['admin_role']   = $admin['admin_role'];   // 'super' 또는 'center'
                    $_SESSION['admin_center'] = $admin['admin_center']; // 센터어드민의 CENTER ID
                    $_SESSION['last_activity'] = time();

                    // 실패 카운트 초기화
                    unset($_SESSION[$lock_key . '_count'], $_SESSION[$lock_key . '_until']);

                    // 감사 로그 (로그인 성공)
                    write_audit_log('login_success', $input_id);

                    // 로그인 전 방문 페이지로 이동
                    $redirect = $_SESSION['redirect_after_login']
                        ?? (BASE_URL . '/modules/dashboard/dashboard.php');
                    unset($_SESSION['redirect_after_login']);
                    header('Location: ' . $redirect);
                    exit;

                } else {
                    // ❌ 로그인 실패
                    $fail_count++;
                    $_SESSION[$lock_key . '_count'] = $fail_count;

                    if ($fail_count >= LOGIN_MAX_FAIL) {
                        // 잠금 처리
                        $_SESSION[$lock_key . '_until'] = time() + (LOGIN_LOCK_MINUTES * 60);
                        $error = "로그인 " . LOGIN_MAX_FAIL . "회 실패로 " . LOGIN_LOCK_MINUTES . "분간 잠금됩니다.";
                        write_audit_log('login_locked', $input_id, ['ip' => $client_ip]);
                    } else {
                        $remain_tries = LOGIN_MAX_FAIL - $fail_count;
                        $error = "아이디 또는 비밀번호가 올바르지 않습니다. ({$remain_tries}회 남음)";
                    }
                }
            }
        }
    }
}

// 타임아웃 메시지
if (isset($_GET['timeout'])) {
    $error = '세션이 만료되었습니다. 다시 로그인해주세요.';
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>로그인 | <?= ADMIN_TITLE ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #00D4FF;
      --success: #00FF9D;
      --danger:  #FF3B5C;
      --bg:      #0b0f1a;
      --border:  rgba(255,255,255,0.08);
    }
    * { margin:0;padding:0;box-sizing:border-box; }
    body {
      font-family: 'Noto Sans KR', sans-serif;
      background: var(--bg);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      /* 배경 그리드 패턴 */
      background-image:
        radial-gradient(circle at 20% 50%, rgba(0,212,255,0.06) 0%, transparent 50%),
        radial-gradient(circle at 80% 20%, rgba(0,255,157,0.04) 0%, transparent 50%),
        linear-gradient(var(--border) 1px, transparent 1px),
        linear-gradient(90deg, var(--border) 1px, transparent 1px);
      background-size: 100% 100%, 100% 100%, 40px 40px, 40px 40px;
    }
    .login-wrap {
      width: 100%;
      max-width: 420px;
      padding: 20px;
    }
    .login-logo {
      text-align: center;
      margin-bottom: 32px;
    }
    .login-logo .icon-wrap {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 64px; height: 64px;
      background: linear-gradient(135deg, var(--primary), var(--success));
      border-radius: 20px;
      font-size: 2rem;
      margin-bottom: 16px;
      box-shadow: 0 0 40px rgba(0,212,255,0.3);
    }
    .login-logo h1 {
      font-size: 1.5rem;
      font-weight: 900;
      background: linear-gradient(135deg, var(--primary), var(--success));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    .login-logo p { color: rgba(255,255,255,0.4); font-size: 0.82rem; margin-top: 6px; }

    .login-card {
      background: rgba(255,255,255,0.04);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 36px 32px;
      backdrop-filter: blur(20px);
      box-shadow: 0 20px 60px rgba(0,0,0,0.4);
    }

    .form-group { margin-bottom: 18px; }
    .form-label {
      display: block;
      font-size: 0.8rem;
      color: rgba(255,255,255,0.55);
      margin-bottom: 8px;
      font-weight: 600;
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }
    .form-input {
      width: 100%;
      background: rgba(255,255,255,0.06);
      border: 1px solid var(--border);
      color: #e2e8f0;
      border-radius: 10px;
      padding: 12px 16px;
      font-size: 0.9rem;
      font-family: inherit;
      transition: all 0.25s;
    }
    .form-input:focus {
      outline: none;
      border-color: var(--primary);
      background: rgba(0,212,255,0.06);
      box-shadow: 0 0 0 3px rgba(0,212,255,0.15);
    }
    .form-input::placeholder { color: rgba(255,255,255,0.2); }

    .btn-login {
      width: 100%;
      background: linear-gradient(135deg, var(--primary), #0099cc);
      color: #000;
      border: none;
      border-radius: 10px;
      padding: 13px;
      font-size: 0.95rem;
      font-weight: 800;
      cursor: pointer;
      margin-top: 8px;
      transition: all 0.25s;
      font-family: inherit;
    }
    .btn-login:hover {
      box-shadow: 0 0 30px rgba(0,212,255,0.5);
      transform: translateY(-1px);
    }
    .btn-login:active { transform: translateY(0); }

    .alert-box {
      padding: 12px 16px;
      border-radius: 10px;
      font-size: 0.85rem;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .alert-box.danger {
      background: rgba(255,59,92,0.1);
      border: 1px solid rgba(255,59,92,0.3);
      color: #FF3B5C;
    }
    .alert-box.success {
      background: rgba(0,255,157,0.1);
      border: 1px solid rgba(0,255,157,0.3);
      color: #00FF9D;
    }

    .version-badge {
      text-align: center;
      margin-top: 20px;
      color: rgba(255,255,255,0.2);
      font-size: 0.75rem;
    }
  </style>
</head>
<body>
<div class="login-wrap">
  <!-- 로고 -->
  <div class="login-logo">
    <div class="icon-wrap">⚡</div>
    <h1>Global Admin</h1>
    <p>통합 관리 시스템 v<?= SYSTEM_VERSION ?></p>
  </div>

  <!-- 로그인 카드 -->
  <div class="login-card">
    <?php if ($error): ?>
    <div class="alert-box danger">❌ <?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST" id="login-form" autocomplete="off">
      <!-- CSRF 보안 토큰 -->
      <?= csrf_field() ?>

      <div class="form-group">
        <label class="form-label" for="admin_id">관리자 ID</label>
        <input type="text" id="admin_id" name="admin_id"
               class="form-input"
               placeholder="아이디 입력"
               value="<?= h($_POST['admin_id'] ?? '') ?>"
               autocomplete="username"
               required>
      </div>

      <div class="form-group">
        <label class="form-label" for="admin_pw">비밀번호</label>
        <input type="password" id="admin_pw" name="admin_pw"
               class="form-input"
               placeholder="비밀번호 입력"
               autocomplete="current-password"
               required>
      </div>

      <button type="submit" class="btn-login" id="submit-btn">
        🔐 로그인
      </button>
    </form>
  </div>

  <div class="version-badge">
    <?= ADMIN_TITLE ?> <?= SYSTEM_VERSION ?> &nbsp;|&nbsp; 서버: 147번
  </div>
</div>

<script>
// 로그인 버튼 중복 클릭 방지
document.getElementById('login-form').addEventListener('submit', function() {
  const btn = document.getElementById('submit-btn');
  btn.disabled = true;
  btn.textContent = '⏳ 로그인 중...';
  setTimeout(() => { btn.disabled = false; btn.textContent = '🔐 로그인'; }, 5000);
});
</script>
</body>
</html>
