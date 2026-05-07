<?php
/**
 * change_password.php - 패스워드 수정 페이지
 */
require_once(__DIR__ . '/common.php');
require_once(__DIR__ . '/lang.php'); // 언어팩 로드 추가
require_login();

$member = get_current_member();
if (!$member) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$is_initial = isset($_GET['initial']) && $_GET['initial'] == '1';
$success_msg = '';
$error_msg = '';

// 패스워드 변경 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'change_pwd') {
    $current_pwd = trim($_POST['current_pwd'] ?? '');
    $new_pwd     = trim($_POST['new_pwd'] ?? '');
    $confirm_pwd = trim($_POST['confirm_pwd'] ?? '');

    if (empty($current_pwd) || empty($new_pwd) || empty($confirm_pwd)) {
        $error_msg = t('cp_err_empty');
    } elseif (!password_verify($current_pwd, $member['rx_uid_password'])) {
        $error_msg = t('cp_err_curr');
    } elseif (strlen($new_pwd) < 4) {
        $error_msg = t('cp_err_len');
    } elseif ($new_pwd !== $confirm_pwd) {
        $error_msg = t('cp_err_match');
    } elseif ($current_pwd === $new_pwd) {
        $error_msg = t('cp_err_same');
    } else {
        $conn = get_db_connection();
        $hashed = password_hash($new_pwd, PASSWORD_DEFAULT);
        $hashed_safe = $conn->real_escape_string($hashed);

        $sql = "UPDATE rx_member SET rx_uid_password = '{$hashed_safe}', mm_update = NOW() WHERE idx = {$member['idx']}";

        if ($conn->query($sql)) {
            $success_msg = t('cp_succ');
            $dashboard_url = (intval($member['rx_ce']) === 3) ? 'dashboard_websea.php' : ((intval($member['rx_ce']) === 4) ? 'dashboard_deepcoin.php' : 'dashboard.php');
            header("Refresh: 2; URL={$dashboard_url}");
        } else {
            $error_msg = t('cp_err_fail') . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('cp_title') ?></title>
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
        .pwd-container {
            width: 100%;
            max-width: 440px;
        }
        .pwd-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 40px 35px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            position: relative;
        }
        .lang-switcher-wrap { position: absolute; top: 15px; right: 15px; }
        .pwd-header {
            text-align: center;
            margin-bottom: 30px;
            margin-top: 10px;
        }
        .pwd-header h2 {
            color: #fff;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .pwd-header p {
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.85rem;
        }
        .form-label {
            color: rgba(255, 255, 255, 0.7);
            font-weight: 500;
            font-size: 0.85rem;
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
        .form-control::placeholder { color: rgba(255, 255, 255, 0.3); }
        .btn-save {
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
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(108, 99, 255, 0.4);
            color: #fff;
        }
        .alert-warning-custom {
            background: rgba(255, 193, 7, 0.15);
            border: 1px solid rgba(255, 193, 7, 0.3);
            color: #ffc107;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 0.85rem;
            margin-bottom: 20px;
        }
        .alert-danger-custom {
            background: rgba(220, 53, 69, 0.15);
            border: 1px solid rgba(220, 53, 69, 0.3);
            color: #ff6b7a;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 0.85rem;
            margin-bottom: 20px;
        }
        .alert-success-custom {
            background: rgba(40, 167, 69, 0.15);
            border: 1px solid rgba(40, 167, 69, 0.3);
            color: #5bff7f;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 0.85rem;
            margin-bottom: 20px;
        }
        .member-info {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 20px;
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.85rem;
        }
        .member-info span { color: #6c63ff; font-weight: 700; }
    </style>
</head>
<body>

<div class="pwd-container">
    <div class="pwd-card">
        <div class="lang-switcher-wrap">
            <?= render_language_selector() ?>
        </div>
        <div class="pwd-header">
            <h2><?= t('cp_title') ?></h2>
            <p><?= t('cp_sub') ?></p>
        </div>

        <div class="member-info">
            <?= t('cp_uid') ?>: <span><?php echo h($member['rx_uid']); ?></span> &nbsp;|&nbsp;
            <?= t('cp_exchange') ?>: <span><?php echo h(EXCHANGE_MAP[$member['rx_ce']] ?? 'Unknown'); ?></span>
        </div>

        <?php if ($is_initial): ?>
            <div class="alert-warning-custom">
                <?= t('cp_warn_init') ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert-danger-custom">❌ <?php echo h($error_msg); ?></div>
        <?php endif; ?>

        <?php if ($success_msg): ?>
            <div class="alert-success-custom">✅ <?php echo h($success_msg); ?></div>
        <?php endif; ?>

        <form method="POST" action="change_password.php<?php echo $is_initial ? '?initial=1' : ''; ?>">
            <input type="hidden" name="act" value="change_pwd">

            <div class="mb-3">
                <label class="form-label"><?= t('cp_curr_pwd') ?></label>
                <input type="password" class="form-control" name="current_pwd"
                       placeholder="<?= t('cp_curr_ph') ?>" required autocomplete="off">
            </div>

            <div class="mb-3">
                <label class="form-label"><?= t('cp_new_pwd') ?></label>
                <input type="password" class="form-control" name="new_pwd"
                       placeholder="<?= t('cp_new_ph') ?>" required autocomplete="off">
            </div>

            <div class="mb-3">
                <label class="form-label"><?= t('cp_conf_pwd') ?></label>
                <input type="password" class="form-control" name="confirm_pwd"
                       placeholder="<?= t('cp_conf_ph') ?>" required autocomplete="off">
            </div>

            <button type="submit" class="btn btn-save"><?= t('cp_btn_change') ?></button>
        </form>

        <?php if (!$is_initial): ?>
            <div class="text-center mt-3">
                <?php
					$back_url = (intval($member['rx_ce']) === 3) ? '../dashboard_websea.php'
								   : (intval($member['rx_ce']) === 4 ? '../dashboard_deepcoin.php'
								   : '../dashboard.php');
                ?>
                <a href="<?php echo $back_url; ?>" style="color: rgba(255,255,255,0.5); text-decoration: none; font-size: 0.85rem;"><?= t('cp_back_dash') ?></a>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>