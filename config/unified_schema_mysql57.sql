-- ============================================================
-- unified_schema_mysql57.sql
-- 147번 통합 서버 DB 스키마 (MySQL 5.7 호환)
-- 58번 서버 테이블도 포함 (단일 DB 운영)
-- ============================================================

SET NAMES utf8mb4;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================
-- 1. 관리자 계정 테이블
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_accounts` (
  `admin_no`     INT          NOT NULL AUTO_INCREMENT COMMENT '관리자 고유번호',
  `admin_id`     VARCHAR(50)  NOT NULL UNIQUE         COMMENT '관리자 로그인 ID',
  `admin_pw`     VARCHAR(255) NOT NULL                COMMENT '비밀번호 (bcrypt 해시)',
  `admin_role`   VARCHAR(20)  NOT NULL DEFAULT 'sub'  COMMENT '역할: super, center, sub',
  `admin_center` VARCHAR(100) DEFAULT NULL            COMMENT '센터어드민의 소속 CENTER',
  `admin_name`   VARCHAR(100) NOT NULL                COMMENT '관리자 실명',
  `admin_email`  VARCHAR(255) DEFAULT NULL            COMMENT '관리자 이메일',
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1      COMMENT '1:활성, 0:비활성',
  `permissions`  TEXT         DEFAULT NULL            COMMENT '서브관리자 메뉴 권한 (JSON)',
  `last_login`   DATETIME     DEFAULT NULL            COMMENT '마지막 로그인 일시',
  `login_ip`     VARCHAR(45)  DEFAULT NULL            COMMENT '마지막 로그인 IP',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
  PRIMARY KEY (`admin_no`),
  KEY `idx_admin_id`     (`admin_id`),
  KEY `idx_admin_role`   (`admin_role`),
  KEY `idx_admin_center` (`admin_center`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='관리자 계정 테이블';

-- ============================================================
-- 2. IP 화이트리스트 테이블 (총어드민 관리)
-- ============================================================
CREATE TABLE IF NOT EXISTS `white_ip_list` (
  `ip_no`       INT          NOT NULL AUTO_INCREMENT COMMENT 'IP 고유번호',
  `ip_address`  VARCHAR(45)  NOT NULL                COMMENT 'IPv4 또는 IPv6 주소',
  `memo`        VARCHAR(255) DEFAULT NULL            COMMENT 'IP 설명 메모',
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1      COMMENT '1:활성, 0:비활성',
  `created_by`  VARCHAR(50)  DEFAULT NULL            COMMENT '등록한 관리자 ID',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '등록일',
  PRIMARY KEY (`ip_no`),
  UNIQUE KEY `uq_ip_address` (`ip_address`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='관리자 페이지 IP 화이트리스트';

-- 기본 IP 등록
INSERT IGNORE INTO `white_ip_list` (`ip_address`, `memo`, `is_active`, `created_by`)
VALUES ('193.186.4.174', '기본 허용 IP', 1, 'system');

-- ============================================================
-- 3. 관리자 감사 로그 테이블
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_audit_log` (
  `log_no`      BIGINT       NOT NULL AUTO_INCREMENT COMMENT '로그 고유번호',
  `admin_id`    VARCHAR(50)  NOT NULL                COMMENT '작업한 관리자 ID',
  `admin_role`  VARCHAR(20)  NOT NULL                COMMENT '관리자 역할',
  `action`      VARCHAR(100) NOT NULL                COMMENT '작업 유형',
  `target_id`   VARCHAR(100) DEFAULT NULL            COMMENT '대상 ID',
  `before_data` LONGTEXT     DEFAULT NULL            COMMENT '변경 전 데이터 (JSON)',
  `after_data`  LONGTEXT     DEFAULT NULL            COMMENT '변경 후 데이터 (JSON)',
  `ip_address`  VARCHAR(45)  DEFAULT NULL            COMMENT '작업자 IP',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '작업 일시',
  PRIMARY KEY (`log_no`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_action`   (`action`),
  KEY `idx_target`   (`target_id`),
  KEY `idx_created`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='관리자 작업 감사 로그';

-- ============================================================
-- 4. 멀티도메인 관리 테이블
-- ============================================================
CREATE TABLE IF NOT EXISTS `domain_management` (
  `domain_no`     INT          NOT NULL AUTO_INCREMENT COMMENT '도메인 고유번호',
  `domain_url`    VARCHAR(255) NOT NULL UNIQUE         COMMENT '도메인 주소',
  `center_name`   VARCHAR(100) NOT NULL                COMMENT '소속 CENTER',
  `site_name`     VARCHAR(255) NOT NULL                COMMENT '사이트 표시 이름',
  `logo_path`     VARCHAR(500) DEFAULT NULL            COMMENT '로고 이미지 경로',
  `primary_color` VARCHAR(7)   NOT NULL DEFAULT '#00D4FF' COMMENT '기본 색상 (HEX)',
  `rx_ce`         INT          NOT NULL DEFAULT 1      COMMENT '대표 거래소 코드',
  `exchange_name` VARCHAR(50)  DEFAULT 'Toobit'        COMMENT '대표 거래소 이름',
  `exchanges`     TEXT         DEFAULT NULL            COMMENT '연결 거래소 목록 (JSON)',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1      COMMENT '1:활성, 0:비활성',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '등록일',
  `updated_at`    DATETIME     DEFAULT NULL            COMMENT '수정일',
  PRIMARY KEY (`domain_no`),
  KEY `idx_center` (`center_name`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='멀티도메인 관리 테이블';

-- ============================================================
-- 5. Telegram 전송 기록 테이블
-- ============================================================
CREATE TABLE IF NOT EXISTS `telegram_log` (
  `log_no`      BIGINT       NOT NULL AUTO_INCREMENT COMMENT '로그 고유번호',
  `chat_id`     VARCHAR(100) NOT NULL                COMMENT 'Telegram 채팅방 ID',
  `message`     TEXT         NOT NULL                COMMENT '전송 메시지',
  `event_type`  VARCHAR(50)  DEFAULT NULL            COMMENT '이벤트 타입',
  `target_uid`  VARCHAR(50)  DEFAULT NULL            COMMENT '대상 UID',
  `status`      VARCHAR(10)  NOT NULL DEFAULT 'pending' COMMENT 'pending/sent/failed',
  `error_msg`   TEXT         DEFAULT NULL            COMMENT '실패 시 에러 메시지',
  `sent_at`     DATETIME     DEFAULT NULL            COMMENT '실제 전송 일시',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성 일시',
  PRIMARY KEY (`log_no`),
  KEY `idx_chat_id`    (`chat_id`),
  KEY `idx_status`     (`status`),
  KEY `idx_target_uid` (`target_uid`),
  KEY `idx_created`    (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Telegram 메시지 전송 기록';

-- ============================================================
-- 6. 58번 서버 동기화 로그 (통합 후에도 이력 보존용)
-- ============================================================
CREATE TABLE IF NOT EXISTS `server58_sync_log` (
  `log_no`      BIGINT       NOT NULL AUTO_INCREMENT,
  `action`      VARCHAR(100) DEFAULT NULL,
  `target_uid`  VARCHAR(100) DEFAULT NULL,
  `request`     LONGTEXT     DEFAULT NULL,
  `response`    LONGTEXT     DEFAULT NULL,
  `http_code`   INT          DEFAULT 0,
  `status`      VARCHAR(20)  DEFAULT 'success',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_no`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='서버 동기화 이력';

-- ============================================================
-- 7. center_TB 확장 (기존 테이블에 컬럼 추가)
-- MySQL 5.7: ADD COLUMN IF NOT EXISTS 미지원 → 에러 무시 방식
-- ============================================================
-- 수동 실행 필요:
-- ALTER TABLE center_TB ADD COLUMN T_chat_id VARCHAR(100) DEFAULT NULL COMMENT '텔레그램 Chat ID';
-- ALTER TABLE center_TB ADD COLUMN telegram_token VARCHAR(255) DEFAULT NULL COMMENT '텔레그램 봇 토큰';
-- ALTER TABLE center_TB ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '활성 여부';
-- ALTER TABLE center_TB ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '생성일';

-- ============================================================
-- 8. g5_member 테이블 확장 (기존 테이블에 컬럼 추가)
-- MySQL 5.7: ADD COLUMN IF NOT EXISTS 미지원 → 에러 무시 방식
-- ============================================================
-- 수동 실행 필요:
-- ALTER TABLE g5_member ADD COLUMN rx_spot_amount INT NOT NULL DEFAULT 0 COMMENT 'Spot 투자금 (USDT)';
-- ALTER TABLE g5_member ADD COLUMN rx_passphrase TEXT DEFAULT NULL COMMENT '거래소 Passphrase';
-- ALTER TABLE g5_member ADD COLUMN mb_class INT NOT NULL DEFAULT 1 COMMENT '투자 클래스';
-- ALTER TABLE g5_member ADD COLUMN bot_start_date DATETIME DEFAULT NULL COMMENT '봇 가동 시작일';
-- ALTER TABLE g5_member ADD COLUMN rx_leverage INT NOT NULL DEFAULT 50 COMMENT '레버리지 배율';
-- ALTER TABLE g5_member ADD COLUMN rx_dca_count INT NOT NULL DEFAULT 0 COMMENT '현재 물타기 횟수';
-- ALTER TABLE g5_member ADD COLUMN rx_dca_stop TINYINT(1) NOT NULL DEFAULT 0 COMMENT '물타기 정지 여부';
-- ALTER TABLE g5_member ADD INDEX idx_mb_class (mb_class);
-- ALTER TABLE g5_member ADD INDEX idx_rx_send_ok (rx_send_ok);
