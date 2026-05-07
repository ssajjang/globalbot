<?php
/**
 * receive_backup.php - 58번 서버: 147번에서 보낸 DB 백업 수신 API
 * 이 파일은 58번 서버에 배포해야 합니다.
 * 경로: /api/receive_backup.php
 */

// ============================================================
// 보안 검증
// ============================================================
define('EXPECTED_API_KEY', 'CHANGE_THIS_SECRET_KEY'); // config의 SERVER_58_KEY와 동일하게
define('BACKUP_RECEIVE_DIR', '/var/www/backups/from_147/'); // 58번 서버 저장 경로

// API Key 검증
$received_key = $_POST['api_key'] ?? '';
if (!hash_equals(EXPECTED_API_KEY, $received_key)) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => '인증 실패']));
}

// 발신지 확인 (147번 서버 IP)
$allowed_source_ips = ['211.115.65.147', '127.0.0.1'];
if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_source_ips)) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => '허용되지 않은 IP']));
}

// 파일 수신 처리
if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => '파일 업로드 실패']));
}

// 파일명 검증 (backup_YYYYMMDD_HHMMSS.sql.gz 형식만 허용)
$file_name = basename($_FILES['backup_file']['name']);
if (!preg_match('/^backup_[\d_]+\.sql(\.gz)?$/', $file_name)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => '유효하지 않은 파일명']));
}

if (!is_dir(BACKUP_RECEIVE_DIR)) mkdir(BACKUP_RECEIVE_DIR, 0750, true);

$dest = BACKUP_RECEIVE_DIR . $file_name;
if (move_uploaded_file($_FILES['backup_file']['tmp_name'], $dest)) {
    header('Content-Type: application/json');
    echo json_encode([
        'success'  => true,
        'message'  => '백업 파일 수신 완료',
        'file'     => $file_name,
        'size'     => filesize($dest),
        'saved_at' => date('Y-m-d H:i:s'),
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '파일 저장 실패']);
}
