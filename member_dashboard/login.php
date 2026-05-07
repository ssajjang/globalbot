<?php
/**
 * login.php - 로그인 페이지
 * UID + 패스워드 입력
 * 패스워드가 UID와 동일하면 패스워드 변경 페이지로 이동
 */
require_once(__DIR__ . '/common.php');
require_once(__DIR__ . '/lang.php'); // 언어팩 로드

// 이미 로그인된 경우
if (is_logged_in()) {
    // 관리자인 경우 관리자 페이지로 이동
    if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
        header('Location: admin/index.php');
        exit;
    }
    // 일반 회원은 거래소별 대시보드로 이동
    $ce = intval($_SESSION['rx_ce'] ?? 1);
    if ($ce === 3) {
        header('Location: dashboard_websea.php');
    } elseif ($ce === 4) {
        header('Location: dashboard_deepcoin.php');
    } else {
        header('Location: dashboard.php');
    }
    exit;
}

$error_msg = '';
$s_uid = '';

// 로그인 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'login') {
    $s_uid = trim($_POST['rx_uid'] ?? '');
    $s_pwd = trim($_POST['rx_pwd'] ?? '');

    if (empty($s_uid) || empty($s_pwd)) {
        $error_msg = t('msg_enter_uid_pwd');
    } else {
        $conn = get_db_connection();
        $uid_safe = $conn->real_escape_string($s_uid);

        // 거래소(rx_ce) 조건 제거, UID만으로 회원 조회
        $sql = "SELECT * FROM rx_member WHERE rx_uid = '{$uid_safe}' LIMIT 1";
        $result = $conn->query($sql);

        if ($result && $result->num_rows > 0) {
            $member = $result->fetch_assoc();

            if (password_verify($s_pwd, $member['rx_uid_password'])) {
                // 로그인 성공 - 세션 설정 (DB에서 가져온 rx_ce 값을 세션에 저장)
                $_SESSION['rx_uid'] = $member['rx_uid'];
                $_SESSION['rx_ce'] = $member['rx_ce'];
                $_SESSION['rx_idx'] = $member['idx'];
                $_SESSION['logged_in'] = true;

                // 관리자 여부 확인 (TB_admin 테이블)
                $admin_uid_safe = $conn->real_escape_string($member['rx_uid']);
                $admin_sql = "SELECT * FROM TB_admin WHERE uid = '{$admin_uid_safe}' AND state_fail = '1' LIMIT 1";
                $admin_result = $conn->query($admin_sql);
                if ($admin_result && $admin_result->num_rows > 0) {
                    $_SESSION['is_admin'] = true;
                    // 관리자도 초기 패스워드(UID와 동일) 체크
                    if (password_verify($member['rx_uid'], $member['rx_uid_password'])) {
                        header('Location: change_password.php?initial=1');
                        exit;
                    }
                    header('Location: admin/index.php');
                    exit;
                }

                // 패스워드가 UID와 동일한지 확인
                if (password_verify($member['rx_uid'], $member['rx_uid_password'])) {
                    // 초기 패스워드 → 패스워드 변경 페이지로 이동
                    header('Location: change_password.php?initial=1');
                    exit;
                }

                // 거래소별 대시보드로 이동
                if (intval($member['rx_ce']) === 3) {
                    header('Location: dashboard_websea.php');
                } elseif (intval($member['rx_ce']) === 4) {
                    header('Location: dashboard_deepcoin.php');
                } else {
                    header('Location: dashboard.php');
                }
                exit;
            } else {
                $error_msg = t('msg_pwd_wrong');
            }
        } else {
            $error_msg = t('msg_not_reg');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('login_title') ?> - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #0f0c29 0%, #302b63 50%, #24243e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Noto Sans KR', sans-serif;
            padding: 20px;
        }
        .login-container {
            width: 100%;
            max-width: 440px;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 40px 35px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            position: relative;
        }
        .lang-switcher-wrap { position: absolute; top: 15px; right: 15px; }
        .login-logo {
            text-align: center;
            margin-bottom: 30px;
            margin-top: 15px;
        }
        .login-logo h1 {
            color: #fff;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .login-logo p {
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.9rem;
        }
        .form-label {
            color: rgba(255, 255, 255, 0.7);
            font-weight: 500;
            font-size: 0.85rem;
            margin-bottom: 6px;
        }
        .form-control {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            color: #fff;
            padding: 12px 16px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        .form-control:focus {
            background: rgba(255, 255, 255, 0.12);
            border-color: #6c63ff;
            box-shadow: 0 0 0 3px rgba(108, 99, 255, 0.2);
            color: #fff;
        }
        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.3);
        }
        .btn-login {
            width: 100%;
            padding: 13px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #6c63ff 0%, #4834d4 100%);
            color: #fff;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(108, 99, 255, 0.4);
            background: linear-gradient(135deg, #7b73ff 0%, #5a45e0 100%);
            color: #fff;
        }
        .alert-custom {
            background: rgba(220, 53, 69, 0.15);
            border: 1px solid rgba(220, 53, 69, 0.3);
            color: #ff6b7a;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 0.85rem;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

<div class="login-container">
    <div class="login-card">
        <div class="lang-switcher-wrap">
            <?= render_language_selector() ?>
        </div>
        <div class="login-logo">
            <h1>📊 <?= t('login_title') ?></h1>
            <p><?= t('login_sub') ?></p>
        </div>

        <?php if ($error_msg): ?>
            <div class="alert-custom">
                ⚠️ <?php echo h($error_msg); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <input type="hidden" name="act" value="login">

            <div class="mb-3">
                <label class="form-label" for="rx_uid"><?= t('uid_label') ?></label>
                <input type="text" class="form-control" id="rx_uid" name="rx_uid"
                       placeholder="<?= t('uid_ph') ?>" value="<?php echo h($s_uid); ?>" required autocomplete="off">
            </div>

            <div class="mb-3">
                <label class="form-label" for="rx_pwd"><?= t('pwd_label') ?></label>
                <input type="password" class="form-control" id="rx_pwd" name="rx_pwd"
                       placeholder="<?= t('pwd_ph') ?>" required autocomplete="off">
            </div>

            <button type="submit" class="btn btn-login"><?= t('btn_login') ?></button>
        </form>
    </div>
</div>

</body>
</html>