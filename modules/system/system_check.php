<?php
/**
 * system_check.php - 전체 서비스 연동 검증 페이지 (통합 서버)
 * DB, Node.js 서버, Telegram, IP 화이트리스트 상태 확인
 */
require_once __DIR__ . '/../../includes/init.php';
require_login();
require_super_admin();

// [통합] 58번 서버 기능이 147번으로 통합됨

global $NODE_SERVERS, $EXCHANGE_LIST;

// ============================================================
// 검증 카드 렌더링 함수 (상단에 선언해야 다른 함수에서 호출 가능)
// ============================================================
function render_check_card(string $title, bool $ok, string $message, array $details = []): void {
    $color  = $ok ? 'var(--success)' : 'var(--danger)';
    $border = $ok ? 'rgba(0,255,157,0.25)' : 'rgba(255,59,92,0.25)';
    $icon   = $ok ? '✅' : '❌';
    echo "<div style=\"background:rgba(255,255,255,0.03);border:1px solid {$border};border-radius:10px;padding:16px\">";
    echo "<div style=\"display:flex;justify-content:space-between;align-items:center;margin-bottom:10px\">";
    echo "  <span style=\"font-weight:700;color:var(--text)\">{$title}</span>";
    echo "  <span style=\"color:{$color};font-size:1.1rem\">{$icon}</span>";
    echo "</div>";
    echo "<div style=\"font-size:0.8rem;color:{$color};margin-bottom:8px\">" . h($message) . "</div>";
    foreach ($details as $d) {
        echo "<div style=\"font-size:0.76rem;color:var(--text-muted)\">";
        echo "<span style=\"width:70px;display:inline-block\">" . h($d['label']) . ":</span>";
        echo "<code style=\"font-size:0.74rem\">" . h($d['val']) . "</code>";
        echo "</div>";
    }
    echo "</div>";
}

// ============================================================
// 1. DB 연결 테스트
// ============================================================
$db_ok = false;
$db_msg = '';
try {
    $ver = db_scalar("SELECT VERSION()");
    $db_ok  = true;
    $db_msg = "MariaDB/MySQL {$ver}";
} catch (Exception $e) {
    $db_msg = $e->getMessage();
}

// ============================================================
// 2. Telegram 큐 대기 건수 확인 (DB 기반)
// ============================================================
$queue_ok  = true;
$queue_msg = '';
$queue_len = 0;
try {
    $queue_len = (int)db_scalar("SELECT COUNT(*) FROM telegram_log WHERE status='pending'");
    $queue_msg = "큐 대기: {$queue_len}건";
} catch (Exception $e) {
    $queue_ok  = false;
    $queue_msg = $e->getMessage();
}

// ============================================================
// 3. Node.js 서버 연결 테스트 (거래소별)
// ============================================================
$node_results = [];
foreach ($NODE_SERVERS as $key => $url) {
    $ch = curl_init($url . '/health');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $ex_name = $EXCHANGE_LIST[array_search($key, array_column($EXCHANGE_LIST, 'key', 'key'))] ?? [];
    $node_results[$key] = [
        'ok'   => ($code === 200 && !$err),
        'code' => $code,
        'url'  => $url,
        'msg'  => $err ?: ($code === 200 ? '정상' : "HTTP {$code}"),
        'data' => json_decode($res, true),
    ];
}

// ============================================================
// 4. IP 화이트리스트 상태
// ============================================================
$ip_ok     = true;
$ip_msg    = '';
$ip_count  = 0;
try {
    $ip_count = (int)db_scalar("SELECT COUNT(*) FROM white_ip_list WHERE is_active=1");
    $ip_msg   = "활성 IP {$ip_count}개 등록됨";
} catch (Exception $e) {
    $ip_ok  = false;
    $ip_msg = 'white_ip_list 테이블 없음 - 스키마 실행 필요';
}
$current_ip = get_client_ip();

// ============================================================
// 5. Telegram 봇 테스트 (getMe API)
// ============================================================
$tg_ok  = false;
$tg_msg = '';
$tg_url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/getMe';
$ch = curl_init($tg_url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false]);
$tg_res = curl_exec($ch);
$tg_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$tg_data = json_decode($tg_res, true);
if ($tg_code === 200 && ($tg_data['ok'] ?? false)) {
    $tg_ok  = true;
    $tg_msg = '봇명: @' . ($tg_data['result']['username'] ?? '알 수 없음');
} else {
    $tg_msg = TELEGRAM_BOT_TOKEN === 'CHANGE_THIS_BOT_TOKEN'
        ? '⚠️ 봇 토큰이 설정되지 않았습니다.'
        : 'HTTP ' . $tg_code;
}

// ============================================================
// 6. 암호화 키 유효성 검사
// ============================================================
$encrypt_ok = false;
$encrypt_msg = '';
if (ENCRYPT_KEY === 'CHANGE_32_CHAR_SECRET_KEY_HERE!!') {
    $encrypt_msg = '⚠️ 기본값 사용 중 - 반드시 변경 필요!';
} elseif (strlen(ENCRYPT_KEY) !== 32) {
    $encrypt_msg = '❌ 키 길이 오류 - 반드시 32자여야 함 (현재: ' . strlen(ENCRYPT_KEY) . '자)';
} else {
    // 실제 암복호화 테스트
    $test     = 'test_encrypt_' . time();
    $enc      = encrypt_api_key($test);
    $dec      = decrypt_api_key($enc);
    $encrypt_ok  = ($dec === $test);
    $encrypt_msg = $encrypt_ok ? 'AES-256-CBC 정상 작동' : '❌ 암복호화 오류';
}

// ============================================================
// 7. 설정값 플레이스홀더 감지 (미설정 항목 경고)
// ============================================================
$config_warnings = [];
if (TELEGRAM_BOT_TOKEN === 'CHANGE_THIS_BOT_TOKEN') $config_warnings[] = 'TELEGRAM_BOT_TOKEN';
if (TELEGRAM_CHAT_ID === 'CHANGE_THIS_CHAT_ID')    $config_warnings[] = 'TELEGRAM_CHAT_ID';
if (ENCRYPT_KEY === 'CHANGE_32_CHAR_SECRET_KEY_HERE!!') $config_warnings[] = 'ENCRYPT_KEY';
if (DB_PASS === '')                                $config_warnings[] = 'DB_PASS (빈 비밀번호)';

// 전체 이상 없는지 판단
$all_ok = $db_ok && $encrypt_ok && $ip_ok
       && empty($config_warnings)
       && !in_array(false, array_column($node_results, 'ok'));

$page_title = '시스템 연동 검증';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <h2>🔍 시스템 연동 검증</h2>
  <p>모든 서비스의 실시간 연결 상태를 확인합니다. | 최종 확인: <?= date('Y-m-d H:i:s') ?></p>
</div>

<!-- 전체 상태 요약 배너 -->
<div style="padding:16px 20px;border-radius:12px;margin-bottom:24px;
     background:<?= $all_ok ? 'rgba(0,255,157,0.08)' : 'rgba(255,184,0,0.08)' ?>;
     border:1px solid <?= $all_ok ? 'rgba(0,255,157,0.3)' : 'rgba(255,184,0,0.3)' ?>;
     display:flex;align-items:center;gap:12px">
  <span style="font-size:2rem"><?= $all_ok ? '✅' : '⚠️' ?></span>
  <div>
    <div style="font-weight:800;font-size:1rem;color:<?= $all_ok ? 'var(--success)' : 'var(--warning)' ?>">
      <?= $all_ok ? '모든 서비스 정상 동작 중' : '일부 서비스 점검이 필요합니다' ?>
    </div>
    <div style="font-size:0.82rem;color:var(--text-muted)">아래 항목을 확인하세요</div>
  </div>
  <button class="btn-glass" style="margin-left:auto;padding:8px 16px" onclick="location.reload()">🔄 새로고침</button>
</div>

<!-- ── 미설정 항목 경고 -->
<?php if (!empty($config_warnings)): ?>
<div class="alert-dark warning" style="margin-bottom:20px">
  <strong>⚠️ config.php 미설정 항목:</strong>
  <?= implode(', ', array_map('h', $config_warnings)) ?>
  <br><small>기본값(CHANGE_THIS_~)으로 남아 있는 항목이 있습니다. 운영 전에 반드시 변경하세요.</small>
</div>
<?php endif; ?>

<!-- ── 검증 결과 카드 그리드 -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-bottom:24px">

  <!-- DB -->
  <?php render_check_card('💾 데이터베이스', $db_ok, $db_msg, [
    ['label' => 'Host', 'val' => DB_HOST . ':' . DB_PORT],
    ['label' => 'DB명', 'val' => DB_NAME],
  ]); ?>

  <?php render_check_card('📨 Telegram 큐', $queue_ok, $queue_msg, [
    ['label' => '대기 건수', 'val' => $queue_len . '건 대기 중'],
    ['label' => '방식', 'val' => 'DB 기반 큐 (telegram_log 테이블)'],
  ]); ?>

  <!-- Telegram -->
  <?php render_check_card('📨 Telegram Bot', $tg_ok, $tg_msg, [
    ['label' => 'Chat ID', 'val' => TELEGRAM_CHAT_ID === 'CHANGE_THIS_CHAT_ID' ? '미설정' : TELEGRAM_CHAT_ID],
  ]); ?>

  <!-- 암호화 -->
  <?php render_check_card('🔐 AES-256 암호화', $encrypt_ok, $encrypt_msg, [
    ['label' => '키 길이', 'val' => strlen(ENCRYPT_KEY) . '자'],
  ]); ?>

  <!-- IP 화이트리스트 -->
  <?php render_check_card('🔒 IP 화이트리스트', $ip_ok, $ip_msg, [
    ['label' => '현재 IP', 'val' => $current_ip],
    ['label' => '활성 IP', 'val' => $ip_count . '개'],
    ['label' => '정책', 'val' => '총어드민 페이지만 IP 체크'],
  ]); ?>

</div>

<!-- Node.js 서버별 상태 -->
<div class="card-glass">
  <div class="card-header-bar"><h5>🤖 Node.js 봇 서버 상태</h5></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">
    <?php foreach ($node_results as $key => $r):
      $ex_entry = array_values(array_filter($EXCHANGE_LIST, fn($e) => $e['key'] === $key))[0] ?? null;
      $ex_name  = $ex_entry['name'] ?? $key;
      $ex_color = $ex_entry['color'] ?? '#888';
    ?>
    <div style="background:rgba(255,255,255,0.03);border:1px solid <?= $r['ok'] ? 'rgba(0,255,157,0.25)' : 'rgba(255,59,92,0.25)' ?>;border-radius:10px;padding:14px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <span style="font-weight:700;color:<?= $ex_color ?>"><?= h($ex_name) ?></span>
        <?= $r['ok']
          ? '<span class="badge-success">✅ 정상</span>'
          : '<span class="badge-danger">❌ 오류</span>' ?>
      </div>
      <div style="font-size:0.78rem;color:var(--text-muted)">
        <div>URL: <code><?= h($r['url']) ?></code></div>
        <div>상태: <?= h($r['msg']) ?></div>
        <?php if ($r['data']): ?>
        <div style="margin-top:4px">가동봇: <strong style="color:var(--success)"><?= $r['data']['active_bots'] ?? '-' ?></strong></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php
// render_check_card 함수는 파일 상단에 선언되어 있습니다.
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
