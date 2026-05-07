<?php
/**
 * database.php - 데이터베이스 연결 설정
 * PDO 방식으로 보안 강화 (SQL 인젝션 완전 차단)
 */

// ============================================================
// DB 접속 정보 (실제 서버 정보로 반드시 변경할 것)
// ============================================================
define('DB_HOST', '127.0.0.1');          // 데이터베이스 서버 주소
define('DB_PORT', '3306');               // 포트 번호 (기본값: 3306)
define('DB_NAME', 'global_admin_db');    // 데이터베이스 이름
define('DB_USER', 'root');              // DB 접속 아이디
define('DB_PASS', '');                   // DB 접속 비밀번호
define('DB_CHARSET', 'utf8mb4');        // 문자 인코딩 (이모지 지원)

// ============================================================
// PDO 연결 싱글톤 함수 (한 번만 연결해서 재사용)
// ============================================================
function get_pdo(): PDO {
    // 정적 변수로 한 번 연결 후 계속 재사용 (성능 최적화)
    static $pdo = null;

    if ($pdo === null) {
        try {
            // DSN (Data Source Name) 조립
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
            );

            // PDO 옵션 설정
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // 에러 시 예외 던짐
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // 결과를 연관 배열로 반환
                PDO::ATTR_EMULATE_PREPARES   => false,                  // 실제 Prepared Statement 사용 (보안)
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",    // 문자셋 강제 설정
            ];

            // PDO 객체 생성
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        } catch (PDOException $e) {
            // DB 연결 실패 시 관리자에게만 에러 표시 (보안상 사용자에게는 숨김)
            error_log('[DB 연결 오류] ' . $e->getMessage());
            die('<div style="color:red;padding:20px;">데이터베이스 연결에 실패했습니다. 관리자에게 문의하세요.</div>');
        }
    }

    return $pdo;
}

// ============================================================
// 편의 함수: 단일 행 조회
// ============================================================
function db_row(string $sql, array $params = []): ?array {
    $stmt = get_pdo()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null; // 결과 없으면 null 반환
}

// ============================================================
// 편의 함수: 여러 행 조회
// ============================================================
function db_rows(string $sql, array $params = []): array {
    $stmt = get_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(); // 빈 배열 반환 가능
}

// ============================================================
// 편의 함수: 실행 (INSERT, UPDATE, DELETE)
// ============================================================
function db_execute(string $sql, array $params = []): int {
    $stmt = get_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount(); // 영향받은 행 수 반환
}

// ============================================================
// 편의 함수: INSERT 후 마지막 삽입 ID 반환
// ============================================================
function db_insert(string $sql, array $params = []): int {
    $stmt = get_pdo()->prepare($sql);
    $stmt->execute($params);
    return (int) get_pdo()->lastInsertId(); // 새로 삽입된 행의 ID 반환
}

// ============================================================
// 편의 함수: 단일 값 조회 (COUNT 등)
// ============================================================
function db_scalar(string $sql, array $params = []) {
    $stmt = get_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn(); // 첫 번째 컬럼의 첫 번째 값만 반환
}
