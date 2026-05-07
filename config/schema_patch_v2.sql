-- ============================================================
-- admin_accounts 테이블 확장 (서브관리자 권한 추가)
-- ============================================================

-- 서브관리자 역할 추가
ALTER TABLE `admin_accounts`
  MODIFY COLUMN `admin_role`
    ENUM('super','center','sub') NOT NULL DEFAULT 'sub'
    COMMENT '총어드민:super, 센터어드민:center, 서브어드민:sub';

-- 서브관리자 메뉴 권한 저장 컬럼 추가
ALTER TABLE `admin_accounts`
  ADD COLUMN IF NOT EXISTS `permissions`
    TEXT DEFAULT NULL
    COMMENT '허용된 메뉴 키 목록 (JSON 배열, sub 역할에서만 사용)',
  ADD COLUMN IF NOT EXISTS `parent_admin_id`
    VARCHAR(50) DEFAULT NULL
    COMMENT '이 계정을 생성한 상위 관리자 ID';

-- ============================================================
-- DB 백업 로그 테이블
-- ============================================================
CREATE TABLE IF NOT EXISTS `db_backup_log` (
  `backup_no`   INT          NOT NULL AUTO_INCREMENT COMMENT '백업 고유번호',
  `file_name`   VARCHAR(255) NOT NULL                COMMENT '백업 파일명',
  `file_size`   BIGINT       NOT NULL DEFAULT 0      COMMENT '파일 크기 (bytes)',
  `file_path`   VARCHAR(500) NOT NULL                COMMENT '저장 경로',
  `status`      ENUM('success','failed') NOT NULL DEFAULT 'success' COMMENT '백업 상태',
  `error_msg`   TEXT         DEFAULT NULL            COMMENT '실패 시 에러 메시지',
  `created_by`  VARCHAR(50)  NOT NULL                COMMENT '실행한 관리자 ID',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '백업 일시',
  PRIMARY KEY (`backup_no`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_status`     (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='DB 백업 이력';

-- ============================================================
-- 58번 서버 연동 로그 테이블
-- ============================================================
CREATE TABLE IF NOT EXISTS `server58_sync_log` (
  `sync_no`     INT          NOT NULL AUTO_INCREMENT COMMENT '동기화 고유번호',
  `action`      VARCHAR(100) NOT NULL                COMMENT '동기화 액션 (member_push, bot_status 등)',
  `target_uid`  VARCHAR(50)  DEFAULT NULL            COMMENT '대상 회원 UID',
  `request`     TEXT         DEFAULT NULL            COMMENT '요청 데이터 (JSON)',
  `response`    TEXT         DEFAULT NULL            COMMENT '응답 데이터 (JSON)',
  `http_code`   INT          DEFAULT NULL            COMMENT 'HTTP 응답 코드',
  `status`      ENUM('success','failed') NOT NULL DEFAULT 'success',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '동기화 일시',
  PRIMARY KEY (`sync_no`),
  KEY `idx_action`     (`action`),
  KEY `idx_target_uid` (`target_uid`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='58번 서버 동기화 로그';
