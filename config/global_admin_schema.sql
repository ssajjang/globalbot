-- ============================================================
-- global_admin_schema.sql
-- Global Admin 147 시스템 신규 추가 테이블 정의
-- (기존 g5_member, center_TB, coin_exchange 테이블과 연동)
-- ============================================================

SET NAMES utf8mb4;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================
-- 1. 관리자 계정 테이블
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_accounts` (
  `admin_no`   INT          NOT NULL AUTO_INCREMENT COMMENT '관리자 고유번호',
  `admin_id`   VARCHAR(50)  NOT NULL UNIQUE         COMMENT '관리자 로그인 ID',
  `admin_pw`   VARCHAR(255) NOT NULL                COMMENT '비밀번호 (bcrypt 해시)',
  `admin_role` ENUM('super','center') NOT NULL DEFAULT 'center' COMMENT '총어드민:super, 센터어드민:center',
  `admin_center` VARCHAR(100) DEFAULT NULL           COMMENT '센터어드민의 소속 CENTER 이름',
  `admin_name` VARCHAR(100) NOT NULL                COMMENT '관리자 실명',
  `admin_email` VARCHAR(255) DEFAULT NULL           COMMENT '관리자 이메일',
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1      COMMENT '1:활성, 0:비활성',
  `last_login` DATETIME     DEFAULT NULL            COMMENT '마지막 로그인 일시',
  `login_ip`   VARCHAR(45)  DEFAULT NULL            COMMENT '마지막 로그인 IP',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
  PRIMARY KEY (`admin_no`),
  KEY `idx_admin_id`   (`admin_id`),
  KEY `idx_admin_role` (`admin_role`),
  KEY `idx_admin_center` (`admin_center`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='관리자 계정 테이블';

-- 초기 총어드민 계정 삽입 (비밀번호: admin1234 → 반드시 변경!)
INSERT IGNORE INTO `admin_accounts`
  (admin_id, admin_pw, admin_role, admin_name)
VALUES
  ('admin', '$2y$12$PLACEHOLDER_HASH_CHANGE_THIS', 'super', '총관리자');

-- ============================================================
-- 2. 관리자 감사 로그 테이블
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_audit_log` (
  `log_no`      BIGINT       NOT NULL AUTO_INCREMENT COMMENT '로그 고유번호',
  `admin_id`    VARCHAR(50)  NOT NULL                COMMENT '작업한 관리자 ID',
  `admin_role`  VARCHAR(20)  NOT NULL                COMMENT '관리자 역할',
  `action`      VARCHAR(100) NOT NULL                COMMENT '작업 유형 (예: member_update)',
  `target_id`   VARCHAR(100) DEFAULT NULL            COMMENT '대상 ID (회원 UID 등)',
  `before_data` LONGTEXT     DEFAULT NULL            COMMENT '변경 전 데이터 (JSON)',
  `after_data`  LONGTEXT     DEFAULT NULL            COMMENT '변경 후 데이터 (JSON)',
  `ip_address`  VARCHAR(45)  DEFAULT NULL            COMMENT '작업자 IP 주소',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '작업 일시',
  PRIMARY KEY (`log_no`),
  KEY `idx_admin_id`   (`admin_id`),
  KEY `idx_action`     (`action`),
  KEY `idx_target`     (`target_id`),
  KEY `idx_created`    (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='관리자 작업 감사 로그';

-- ============================================================
-- 3. 멀티도메인 관리 테이블
-- ============================================================
CREATE TABLE IF NOT EXISTS `domain_management` (
  `domain_no`    INT          NOT NULL AUTO_INCREMENT COMMENT '도메인 고유번호',
  `domain_url`   VARCHAR(255) NOT NULL UNIQUE         COMMENT '도메인 주소 (예: site.com)',
  `center_name`  VARCHAR(100) NOT NULL                COMMENT '소속 CENTER 이름',
  `site_name`    VARCHAR(255) NOT NULL                COMMENT '사이트 표시 이름',
  `logo_path`    VARCHAR(500) DEFAULT NULL            COMMENT '로고 이미지 경로 (PNG)',
  `primary_color` VARCHAR(7)  NOT NULL DEFAULT '#00D4FF' COMMENT '기본 색상 (HEX)',
  `exchanges`    TEXT         DEFAULT NULL            COMMENT '연결된 거래소 목록 (JSON 배열)',
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1      COMMENT '1:활성, 0:비활성',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '등록일',
  `updated_at`   DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',
  PRIMARY KEY (`domain_no`),
  KEY `idx_center`  (`center_name`),
  KEY `idx_active`  (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='멀티도메인 관리 테이블';

-- ============================================================
-- 4. Telegram 전송 기록 테이블
-- ============================================================
CREATE TABLE IF NOT EXISTS `telegram_log` (
  `log_no`      BIGINT       NOT NULL AUTO_INCREMENT COMMENT '로그 고유번호',
  `chat_id`     VARCHAR(100) NOT NULL                COMMENT 'Telegram 채팅방 ID',
  `message`     TEXT         NOT NULL                COMMENT '전송 메시지 내용',
  `event_type`  VARCHAR(50)  DEFAULT NULL            COMMENT '이벤트 타입 (entry, error, close 등)',
  `target_uid`  VARCHAR(50)  DEFAULT NULL            COMMENT '대상 회원 UID',
  `status`      ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending' COMMENT '전송 상태',
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
-- 5. g5_member 테이블 필드 추가 (기존 테이블 확장)
-- (이미 있는 경우 에러 무시)
-- ============================================================
ALTER TABLE `g5_member`
  ADD COLUMN IF NOT EXISTS `rx_spot_amount`   INT     NOT NULL DEFAULT 0   COMMENT 'Spot 투자 금액 (USDT)',
  ADD COLUMN IF NOT EXISTS `rx_passphrase`    TEXT    DEFAULT NULL          COMMENT '거래소 Passphrase (암호화)',
  ADD COLUMN IF NOT EXISTS `mb_class`         INT     NOT NULL DEFAULT 1   COMMENT '투자 클래스 (1,2,3,9)',
  ADD COLUMN IF NOT EXISTS `bot_start_date`   DATETIME DEFAULT NULL        COMMENT '봇 가동 시작 일시',
  ADD COLUMN IF NOT EXISTS `rx_leverage`      INT     NOT NULL DEFAULT 50  COMMENT '레버리지 배율',
  ADD COLUMN IF NOT EXISTS `rx_dca_count`     INT     NOT NULL DEFAULT 0   COMMENT '현재 물타기 횟수',
  ADD COLUMN IF NOT EXISTS `rx_dca_stop`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '물타기 정지 여부 (1:정지)',
  ADD INDEX IF NOT EXISTS `idx_mb_class`      (`mb_class`),
  ADD INDEX IF NOT EXISTS `idx_rx_send_ok`    (`rx_send_ok`);

-- ============================================================
-- 6. center_TB 테이블 확장
-- ============================================================
ALTER TABLE `center_TB`
  ADD COLUMN IF NOT EXISTS `telegram_token` VARCHAR(255) DEFAULT NULL COMMENT '센터 전용 텔레그램 봇 토큰',
  ADD COLUMN IF NOT EXISTS `is_active`      TINYINT(1)  NOT NULL DEFAULT 1 COMMENT '활성 여부';

-- ============================================================
-- 7. coin_exchange 테이블 확장
-- ============================================================
ALTER TABLE `coin_exchange`
  ADD COLUMN IF NOT EXISTS `node_api_key`   VARCHAR(255) DEFAULT NULL COMMENT 'Node.js API 인증 키',
  ADD COLUMN IF NOT EXISTS `is_active`      TINYINT(1)  NOT NULL DEFAULT 1 COMMENT '활성 여부';
