<?php
/**
 * db_backup.php - DB 백업 관리 페이지 (통합 서버)
 * 기능: mysqldump 실행, 백업 파일 목록, 다운로드, 삭제
 */
require_once __DIR__ . '/../../includes/init.php';
require_login();
require_super_admin();

// 백업 저장 경로
define('BACKUP_DIR', dirname(__DIR__, 2) . '/backups/');
if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0750, true);

// ============================================================
// AJAX 처리
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf_on_post();
    $act = post_input('act');

    // ── DB 백업 실행
    if ($act === 'run_backup') {
        $tables = post_input('tables', 'all'); // 'all' 또는 특정 테이블명

        // 파일명: backup_YYYYMMDD_HHMMSS.sql.gz
        $file_base = 'backup_' . date('Ymd_His');
        $file_sql  = BACKUP_DIR . $file_base . '.sql';
        $file_gz   = BACKUP_DIR . $file_base . '.sql.gz';

        // mysqldump 명령 조립
        $db_host = DB_HOST;
        $db_name = DB_NAME;
        $db_user = DB_USER;
        $db_pass = DB_PASS;

        // 비밀번호가 있으면 my.cnf 임시 파일로 보안 처리 (커맨드 히스토리 노출 방지)
        $mycnf_path = sys_get_temp_dir() . '/mysql_' . uniqid() . '.cnf';
        file_put_contents($mycnf_path, "[client]\npassword=" . escapeshellarg($db_pass) . "\n");
        chmod($mycnf_path, 0600);

        $table_arg = $tables === 'all' ? '' : escapeshellarg($tables);
        $cmd = sprintf(
            'mysqldump --defaults-extra-file=%s -h %s -u %s %s %s > %s 2>&1',
            escapeshellarg($mycnf_path),
            escapeshellarg($db_host),
            escapeshellarg($db_user),
            escapeshellarg($db_name),
            $table_arg,
            escapeshellarg($file_sql)
        );

        exec($cmd, $output, $return_code);
        unlink($mycnf_path); // 임시 파일 즉시 삭제

        if ($return_code !== 0) {
            $err_msg = implode("\n", $output);
            db_execute(
                "INSERT INTO db_backup_log (file_name, file_size, file_path, status, error_msg, created_by, created_at)
                 VALUES (?, 0, ?, 'failed', ?, ?, NOW())",
                [$file_base . '.sql.gz', $file_gz, $err_msg, $_SESSION['admin_id']]
            );
            write_audit_log('db_backup_failed', DB_NAME, [], ['error' => $err_msg]);
            json_response(false, 'mysqldump 실패: ' . substr($err_msg, 0, 200));
        }

        // gzip 압축
        exec('gzip -f ' . escapeshellarg($file_sql), $gz_output, $gz_code);
        $final_file = ($gz_code === 0 && file_exists($file_gz)) ? $file_gz : $file_sql;
        $file_size  = filesize($final_file);

        // 백업 로그 기록
        db_execute(
            "INSERT INTO db_backup_log (file_name, file_size, file_path, status, created_by, created_at)
             VALUES (?, ?, ?, 'success', ?, NOW())",
            [basename($final_file), $file_size, $final_file, $_SESSION['admin_id']]
        );
        write_audit_log('db_backup_success', DB_NAME, [], ['file' => basename($final_file), 'size' => $file_size]);

        json_response(true, '백업이 완료되었습니다.', [
            'file_name' => basename($final_file),
            'file_size' => number_format($file_size / 1024, 1) . ' KB',
        ]);
    }

    // [통합] 58번 서버 업로드 기능 제거됨 — 단일 서버이므로 로컬 백업만 사용

    // ── 백업 파일 삭제
    if ($act === 'delete_backup') {
        $file_name = basename(post_input('file_name'));
        $file_path = BACKUP_DIR . $file_name;

        if (!file_exists($file_path)) json_response(false, '파일이 존재하지 않습니다.');
        unlink($file_path);

        db_execute("UPDATE db_backup_log SET file_path='DELETED' WHERE file_name=? LIMIT 1", [$file_name]);
        write_audit_log('db_backup_delete', $file_name, [], []);
        json_response(true, '백업 파일이 삭제되었습니다.');
    }

    json_response(false, '알 수 없는 요청');
}

// ── 다운로드 처리
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    $file_name = basename($_GET['download']);
    $file_path = BACKUP_DIR . $file_name;
    if (file_exists($file_path) && preg_match('/^backup_[\d_]+\.sql(\.gz)?$/', $file_name)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    }
    die('파일을 찾을 수 없습니다.');
}

// ============================================================
// 백업 파일 목록 (디렉토리 + DB 로그)
// ============================================================
$backup_logs = db_rows(
    "SELECT * FROM db_backup_log ORDER BY created_at DESC LIMIT 30"
);

// 실제 파일 목록
$files = glob(BACKUP_DIR . 'backup_*.sql*') ?: [];
rsort($files); // 최신 순

$page_title = 'DB 백업';
require_once __DIR__ . '/../../includes/header.php';
?>
<meta name="csrf-token" content="<?= h(generate_csrf_token()) ?>">

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h2>💾 DB 백업 관리</h2>
    <p>백업 경로: <code style="font-size:0.78rem;color:var(--text-muted)"><?= h(BACKUP_DIR) ?></code></p>
  </div>
  <button class="btn-primary-glow" onclick="runBackup()">🚀 지금 백업 실행</button>
</div>

<!-- 진행 표시 -->
<div id="backup-progress" style="display:none" class="alert-dark info" style="margin-bottom:16px">
  <div class="spinner"></div>&nbsp; 백업 진행 중입니다. 잠시 기다려주세요... (DB 크기에 따라 수분 소요)
</div>

<!-- 백업 이력 테이블 -->
<div class="card-glass">
  <div class="card-header-bar">
    <h5>📋 백업 이력 (최근 30건)</h5>
    <span style="color:var(--text-muted);font-size:0.8rem">파일 수: <?= count($files) ?>개</span>
  </div>
  <div class="table-responsive">
    <table class="table-dark-custom">
      <thead>
        <tr>
          <th>파일명</th><th>크기</th><th>상태</th><th>실행자</th><th>백업 일시</th><th>관리</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($backup_logs)): ?>
        <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted)">백업 이력이 없습니다. 지금 바로 백업을 실행하세요.</td></tr>
        <?php else: ?>
        <?php foreach ($backup_logs as $log): ?>
        <?php $exists = $log['file_path'] !== 'DELETED' && file_exists($log['file_path']); ?>
        <tr>
          <td class="mono" style="font-size:0.8rem"><?= h($log['file_name']) ?></td>
          <td><?= $log['file_size'] > 0 ? number_format($log['file_size'] / 1024, 1) . ' KB' : '-' ?></td>
          <td>
            <?= $log['status'] === 'success'
              ? '<span class="badge-success">✅ 성공</span>'
              : '<span class="badge-danger" title="' . h($log['error_msg']) . '">❌ 실패</span>' ?>
          </td>
          <td><?= h($log['created_by']) ?></td>
          <td style="font-size:0.78rem;color:var(--text-muted)"><?= h($log['created_at']) ?></td>
          <td>
            <?php if ($exists): ?>
            <div class="d-flex gap-1 flex-wrap">
              <a href="?download=<?= h($log['file_name']) ?>"
                 class="btn-glass" style="padding:4px 8px;font-size:0.72rem;text-decoration:none">⬇ 다운로드</a>
              <button class="btn-glass" style="padding:4px 8px;font-size:0.72rem;color:var(--danger)"
                      onclick="deleteBackup('<?= h($log['file_name']) ?>')">🗑 삭제</button>
            </div>
            <?php else: ?>
            <span style="color:var(--text-muted);font-size:0.75rem">파일 없음</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
const DB_BACKUP_URL = 'db_backup.php';

// 백업 실행
function runBackup() {
  if (!confirm('DB 전체 백업을 실행하시겠습니까?\n(DB 크기에 따라 수분 소요)')) return;
  document.getElementById('backup-progress').style.display = 'flex';
  adminAjax(DB_BACKUP_URL, { act: 'run_backup', tables: 'all' }, (res) => {
    document.getElementById('backup-progress').style.display = 'none';
    showAlert(`✅ ${res.message} (파일: ${res.data.file_name}, 크기: ${res.data.file_size})`, 'success', 6000);
    setTimeout(() => location.reload(), 3000);
  }, () => {
    document.getElementById('backup-progress').style.display = 'none';
  });
}

// [통합] 58번 서버 업로드 기능 제거됨

// 백업 파일 삭제
function deleteBackup(file_name) {
  if (!confirm(`"${file_name}" 파일을 삭제하시겠습니까?`)) return;
  adminAjax(DB_BACKUP_URL, { act: 'delete_backup', file_name },
    (res) => { showAlert(res.message, 'success'); setTimeout(() => location.reload(), 1200); }
  );
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
