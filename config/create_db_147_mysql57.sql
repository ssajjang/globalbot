-- ============================================================
-- [147번 서버] 글로벌어드민 DB 완전 생성 스크립트 (독립형)
-- 대상 DB   : killer_pro_admin
-- MySQL 버전 : 5.7 호환 (그누보드 설치 불필요 - 독립 실행)
-- 실행 방법  : mysql -u root -p < create_db_147_mysql57.sql
-- 작성 기준  : 2026-05-04
-- ============================================================

SET NAMES utf8mb4;
SET SQL_MODE = '';
SET foreign_key_checks = 0;
SET time_zone = '+09:00';

-- ============================================================
-- 0. DB 생성
-- ============================================================
CREATE DATABASE IF NOT EXISTS `killer_pro_admin`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `killer_pro_admin`;

-- ============================================================
-- 1. 회원 테이블 (g5_member)
-- 그누보드 설치 없이 독립 생성 - 필수 컬럼 + rx_* 확장 컬럼 포함
-- ============================================================
CREATE TABLE IF NOT EXISTS `g5_member` (
  -- ── 그누보드 호환 기본 컬럼 ──────────────────────────────
  `mb_no`             INT           NOT NULL AUTO_INCREMENT    COMMENT '회원 고유번호',
  `mb_id`             VARCHAR(20)   NOT NULL DEFAULT ''        COMMENT '회원 로그인 ID',
  `mb_password`       VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '비밀번호 (bcrypt 해시)',
  `mb_name`           VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '회원 이름',
  `mb_nick`           VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '닉네임',
  `mb_nick_date`      DATE          NOT NULL DEFAULT '0000-00-00' COMMENT '닉네임 변경일',
  `mb_email`          VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '이메일',
  `mb_homepage`       VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '홈페이지',
  `mb_level`          TINYINT(2)    UNSIGNED NOT NULL DEFAULT 1 COMMENT '회원 레벨',
  `mb_sex`            CHAR(1)       NOT NULL DEFAULT ''        COMMENT '성별 (m/f)',
  `mb_birth`          VARCHAR(8)    NOT NULL DEFAULT ''        COMMENT '생년월일 (YYYYMMDD)',
  `mb_tel`            VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '전화번호',
  `mb_hp`             VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '휴대폰 번호',
  `mb_certify`        VARCHAR(20)   NOT NULL DEFAULT ''        COMMENT '본인인증 수단',
  `mb_adult`          TINYINT(1)    NOT NULL DEFAULT 0         COMMENT '성인인증 여부',
  `mb_dupinfo`        VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '중복가입 방지 정보',
  `mb_zip1`           VARCHAR(3)    NOT NULL DEFAULT ''        COMMENT '우편번호 앞자리',
  `mb_zip2`           VARCHAR(3)    NOT NULL DEFAULT ''        COMMENT '우편번호 뒷자리',
  `mb_addr1`          VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '주소',
  `mb_addr2`          VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '상세주소',
  `mb_addr3`          VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '참고항목',
  `mb_addr_jibeon`    VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '지번주소',
  `mb_signature`      TEXT          NOT NULL                   COMMENT '서명',
  `mb_recommend`      VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '추천인 ID',
  `mb_point`          BIGINT        NOT NULL DEFAULT 0         COMMENT '보유 포인트',
  `mb_today_login`    DATETIME      NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '오늘 로그인 일시',
  `mb_login_ip`       VARCHAR(100)  NOT NULL DEFAULT ''        COMMENT '로그인 IP',
  `mb_datetime`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '가입일시',
  `mb_ip`             VARCHAR(100)  NOT NULL DEFAULT ''        COMMENT '가입 IP',
  `mb_leave_date`     VARCHAR(8)    NOT NULL DEFAULT ''        COMMENT '탈퇴일 (YYYYMMDD)',
  `mb_intercept_date` VARCHAR(8)    NOT NULL DEFAULT ''        COMMENT '차단일 (YYYYMMDD, 0000:정상)',
  `mb_email_certify`  DATETIME      NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '이메일 인증일',
  `mb_email_certify2` VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '이메일 인증 키',
  `mb_memo`           TEXT          NOT NULL                   COMMENT '관리자 메모',
  `mb_lost_certify`   VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '비밀번호 찾기 인증값',
  `mb_mailling`       TINYINT(1)    NOT NULL DEFAULT 1         COMMENT '메일 수신 여부',
  `mb_sms`            TINYINT(1)    NOT NULL DEFAULT 0         COMMENT 'SMS 수신 여부',
  `mb_open`           TINYINT(1)    NOT NULL DEFAULT 0         COMMENT '정보 공개 여부',
  `mb_open_date`      DATE          NOT NULL DEFAULT '0000-00-00' COMMENT '정보 공개 변경일',
  `mb_profile`        TEXT          NOT NULL                   COMMENT '프로필',
  `mb_memo_cnt`       INT           NOT NULL DEFAULT 0         COMMENT '쪽지 수',
  `mb_scrap_cnt`      INT           NOT NULL DEFAULT 0         COMMENT '스크랩 수',
  `mb_extra1`         VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '여분 필드 1',
  `mb_extra2`         VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '여분 필드 2',
  `mb_extra3`         VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '여분 필드 3',
  `mb_extra4`         VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '여분 필드 4',
  `mb_extra5`         VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '여분 필드 5',
  `mb_extra6`         VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '여분 필드 6',
  `mb_extra7`         VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '여분 필드 7',
  `mb_extra8`         VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '여분 필드 8',
  `mb_extra9`         VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '여분 필드 9',
  `mb_extra10`        VARCHAR(255)  NOT NULL DEFAULT ''        COMMENT '여분 필드 10',
  -- ── 봇 트레이딩 확장 컬럼 (rx_*) ─────────────────────────
  `mb_class`          INT           NOT NULL DEFAULT 1         COMMENT '투자 클래스 (1:기본 2:중급 3:고급 9:VIP)',
  `mb_center`         VARCHAR(100)  DEFAULT NULL               COMMENT '소속 CENTER 이름',
  `rx_uid`            VARCHAR(50)   DEFAULT NULL               COMMENT '거래소 회원 UID',
  `rx_ce`             TINYINT(1)    NOT NULL DEFAULT 1         COMMENT '거래소 코드 1:Toobit 2:Gateio 3:Websea 4:Deepcoin',
  `rx_acount`         DECIMAL(20,4) NOT NULL DEFAULT 0         COMMENT 'Futures 투자금 (USDT)',
  `rx_spot_acount`    DECIMAL(20,4) NOT NULL DEFAULT 0         COMMENT 'Spot 투자금 (USDT)',
  `rx_spot_amount`    INT           NOT NULL DEFAULT 0         COMMENT 'Spot 투자 금액 (정수형)',
  `rx_send_ok`        ENUM('Y','N','S','R') NOT NULL DEFAULT 'N'
                                                               COMMENT '봇 상태 Y:가동 N:중지 S:시그널중지 R:대기',
  `rx_apikey`         TEXT          DEFAULT NULL               COMMENT 'API Key (평문 저장 - 거래소 연동용)',
  `rx_sskey`          TEXT          DEFAULT NULL               COMMENT 'Secret Key (평문 저장 - 거래소 연동용)',
  `rx_passphrase`     TEXT          DEFAULT NULL               COMMENT 'Passphrase (평문 저장 - Deepcoin 전용)',
  `rx_reserve_percent` TINYINT(3)   NOT NULL DEFAULT 0         COMMENT '유보율 (0/30/100)',
  `rx_accumulated_profit` DECIMAL(20,4) DEFAULT 0             COMMENT '누적 순손익 (USDT)',
  `rx_rate`           DECIMAL(5,2)  NOT NULL DEFAULT 0         COMMENT '수익률 (%)',
  `rx_leverage`       INT           NOT NULL DEFAULT 50        COMMENT '레버리지 배율',
  `rx_dca_count`      INT           NOT NULL DEFAULT 0         COMMENT '현재 물타기 횟수',
  `rx_dca_stop`       TINYINT(1)    NOT NULL DEFAULT 0         COMMENT '물타기 정지 여부 (1:정지)',
  `rx_updated_at`     DATETIME      DEFAULT NULL               COMMENT '58번 서버 동기화 마지막 시각',
  `bot_start_date`    DATETIME      DEFAULT NULL               COMMENT '봇 가동 시작 일시',
  -- ── PRIMARY KEY & INDEX ───────────────────────────────────
  PRIMARY KEY (`mb_no`),
  UNIQUE KEY `uq_mb_id`           (`mb_id`),
  KEY `idx_mb_email`              (`mb_email`),
  KEY `idx_mb_datetime`           (`mb_datetime`),
  KEY `idx_mb_intercept_date`     (`mb_intercept_date`),
  KEY `idx_mb_class`              (`mb_class`),
  KEY `idx_mb_center`             (`mb_center`),
  KEY `idx_rx_uid`                (`rx_uid`),
  KEY `idx_rx_ce`                 (`rx_ce`),
  KEY `idx_rx_send_ok`            (`rx_send_ok`),
  KEY `idx_send_ok_ce`            (`rx_send_ok`, `rx_ce`),
  KEY `idx_ce_center`             (`rx_ce`, `mb_center`),
  KEY `idx_center_class`          (`mb_center`, `mb_class`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='회원 테이블 (그누보드 호환 + 봇트레이딩 확장)';

-- ============================================================
-- 2. 관리자 계정 테이블
-- super(총관리자) → center(센터관리자) → sub(서브관리자)
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_accounts` (
  `admin_no`         INT          NOT NULL AUTO_INCREMENT   COMMENT '관리자 고유번호',
  `admin_id`         VARCHAR(50)  NOT NULL                  COMMENT '로그인 ID',
  `admin_pw`         VARCHAR(255) NOT NULL                  COMMENT '비밀번호 (bcrypt 해시)',
  `admin_role`       ENUM('super','center','sub') NOT NULL DEFAULT 'sub'
                                                            COMMENT 'super:총관리자 center:센터 sub:서브',
  `admin_center`     VARCHAR(100) DEFAULT NULL              COMMENT '소속 CENTER명 (center/sub만 사용)',
  `admin_name`       VARCHAR(100) NOT NULL                  COMMENT '관리자 실명',
  `admin_email`      VARCHAR(255) DEFAULT NULL              COMMENT '이메일',
  `permissions`      TEXT         DEFAULT NULL              COMMENT '허용 메뉴 목록 (JSON배열, sub전용)',
  `parent_admin_id`  VARCHAR(50)  DEFAULT NULL              COMMENT '상위 관리자 ID',
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1        COMMENT '1:활성 0:비활성',
  `login_fail_count` TINYINT      NOT NULL DEFAULT 0        COMMENT '연속 로그인 실패 횟수',
  `lock_until`       DATETIME     DEFAULT NULL              COMMENT '계정 잠금 해제 시각',
  `last_login`       DATETIME     DEFAULT NULL              COMMENT '마지막 로그인 일시',
  `login_ip`         VARCHAR(45)  DEFAULT NULL              COMMENT '마지막 로그인 IP',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
  PRIMARY KEY (`admin_no`),
  UNIQUE KEY `uq_admin_id`              (`admin_id`),
  KEY `idx_admin_role`                  (`admin_role`),
  KEY `idx_admin_center`                (`admin_center`),
  KEY `idx_admin_center_active`         (`admin_center`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='관리자 계정 테이블';

-- 초기 총관리자 계정 생성
-- [필수] 아래 해시값을 교체하세요:
-- php -r "echo password_hash('실제비밀번호', PASSWORD_BCRYPT, ['cost'=>12]);"
INSERT IGNORE INTO `admin_accounts`
  (`admin_id`, `admin_pw`, `admin_role`, `admin_name`, `is_active`)
VALUES
  ('admin', '$2y$12$PLACEHOLDER_HASH_CHANGE_THIS_NOW', 'super', '총관리자', 1);

-- ============================================================
-- 3. 관리자 감사 로그 테이블
-- 모든 관리자 작업(수정/삭제/승인) 자동 기록
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_audit_log` (
  `log_no`      BIGINT       NOT NULL AUTO_INCREMENT COMMENT '로그 고유번호',
  `admin_id`    VARCHAR(50)  NOT NULL                COMMENT '작업한 관리자 ID',
  `admin_role`  VARCHAR(20)  NOT NULL                COMMENT '관리자 역할',
  `action`      VARCHAR(100) NOT NULL                COMMENT '작업 유형 (member_update 등)',
  `target_id`   VARCHAR(100) DEFAULT NULL            COMMENT '대상 ID (회원 UID 등)',
  `before_data` LONGTEXT     DEFAULT NULL            COMMENT '변경 전 데이터 (JSON)',
  `after_data`  LONGTEXT     DEFAULT NULL            COMMENT '변경 후 데이터 (JSON)',
  `ip_address`  VARCHAR(45)  DEFAULT NULL            COMMENT '작업자 IP',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '작업 일시',
  PRIMARY KEY (`log_no`),
  KEY `idx_admin_id`  (`admin_id`),
  KEY `idx_action`    (`action`),
  KEY `idx_target`    (`target_id`),
  KEY `idx_created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='관리자 작업 감사 로그';

-- ============================================================
-- 4. 멀티도메인 관리 테이블
-- 147번 관리자가 등록 → 58번 서버로 Push 동기화
-- ============================================================
CREATE TABLE IF NOT EXISTS `domain_management` (
  `domain_no`     INT          NOT NULL AUTO_INCREMENT COMMENT '도메인 고유번호',
  `domain_url`    VARCHAR(255) NOT NULL                COMMENT '도메인 주소 (예: toobit.killerbot.vip)',
  `center_name`   VARCHAR(100) NOT NULL                COMMENT '소속 CENTER 이름',
  `site_name`     VARCHAR(255) NOT NULL                COMMENT '사이트 표시 이름',
  `logo_path`     VARCHAR(500) DEFAULT NULL            COMMENT '로고 이미지 경로',
  `primary_color` VARCHAR(7)   NOT NULL DEFAULT '#00D4FF' COMMENT '테마 색상 (HEX)',
  `rx_ce`         TINYINT      NOT NULL DEFAULT 1      COMMENT '거래소 코드 1:Toobit 2:Gateio 3:Websea 4:Deepcoin',
  `exchange_name` VARCHAR(50)  NOT NULL DEFAULT 'Toobit' COMMENT '거래소 이름',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1      COMMENT '1:활성 0:비활성',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '등록일',
  `updated_at`    DATETIME     DEFAULT NULL            COMMENT '수정일',
  PRIMARY KEY (`domain_no`),
  UNIQUE KEY `uq_domain_url`      (`domain_url`),
  KEY `idx_center`                (`center_name`),
  KEY `idx_active`                (`is_active`),
  KEY `idx_center_active`         (`center_name`, `is_active`),
  KEY `idx_rx_ce_active`          (`rx_ce`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='멀티도메인 관리 테이블';

-- ============================================================
-- 5. Telegram 전송 기록 테이블
-- ============================================================
CREATE TABLE IF NOT EXISTS `telegram_log` (
  `log_no`     BIGINT       NOT NULL AUTO_INCREMENT COMMENT '로그 번호',
  `chat_id`    VARCHAR(100) NOT NULL                COMMENT 'Telegram 채팅방 ID',
  `message`    TEXT         NOT NULL                COMMENT '전송 메시지 내용',
  `event_type` VARCHAR(50)  DEFAULT NULL            COMMENT '이벤트 타입 (entry/error/close)',
  `target_uid` VARCHAR(50)  DEFAULT NULL            COMMENT '대상 회원 UID',
  `status`     ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending' COMMENT '전송 상태',
  `error_msg`  TEXT         DEFAULT NULL            COMMENT '실패 에러 메시지',
  `sent_at`    DATETIME     DEFAULT NULL            COMMENT '실제 전송 일시',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성 일시',
  PRIMARY KEY (`log_no`),
  KEY `idx_chat_id`    (`chat_id`),
  KEY `idx_status`     (`status`),
  KEY `idx_target_uid` (`target_uid`),
  KEY `idx_created`    (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Telegram 메시지 전송 기록';

-- ============================================================
-- 6. DB 백업 로그 테이블
-- ============================================================
CREATE TABLE IF NOT EXISTS `db_backup_log` (
  `backup_no`  INT          NOT NULL AUTO_INCREMENT COMMENT '백업 고유번호',
  `file_name`  VARCHAR(255) NOT NULL                COMMENT '백업 파일명',
  `file_size`  BIGINT       NOT NULL DEFAULT 0      COMMENT '파일 크기 (bytes)',
  `file_path`  VARCHAR(500) NOT NULL                COMMENT '저장 경로',
  `status`     ENUM('success','failed') NOT NULL DEFAULT 'success' COMMENT '백업 상태',
  `error_msg`  TEXT         DEFAULT NULL            COMMENT '실패 에러 메시지',
  `created_by` VARCHAR(50)  NOT NULL                COMMENT '실행한 관리자 ID',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '백업 일시',
  PRIMARY KEY (`backup_no`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_status`     (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='DB 백업 이력';

-- ============================================================
-- 7. 58번 서버 동기화 로그 테이블
-- 147→58 Push / 58→147 Pull 전체 통신 기록
-- ============================================================
CREATE TABLE IF NOT EXISTS `server58_sync_log` (
  `sync_no`    INT          NOT NULL AUTO_INCREMENT COMMENT '동기화 고유번호',
  `action`     VARCHAR(100) NOT NULL                COMMENT '동기화 액션 (member_push/domain_push)',
  `direction`  ENUM('push','pull') NOT NULL DEFAULT 'push'
                                                   COMMENT 'push:147→58  pull:58→147',
  `target_uid` VARCHAR(50)  DEFAULT NULL            COMMENT '대상 회원 UID',
  `request`    TEXT         DEFAULT NULL            COMMENT '요청 데이터 (JSON)',
  `response`   TEXT         DEFAULT NULL            COMMENT '응답 데이터 (JSON)',
  `http_code`  INT          DEFAULT NULL            COMMENT 'HTTP 응답 코드',
  `elapsed_ms` INT          DEFAULT NULL            COMMENT 'API 응답 시간 (밀리초)',
  `status`     ENUM('success','failed') NOT NULL DEFAULT 'success' COMMENT '동기화 결과',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '동기화 일시',
  PRIMARY KEY (`sync_no`),
  KEY `idx_action`            (`action`),
  KEY `idx_target_uid`        (`target_uid`),
  KEY `idx_created_at`        (`created_at`),
  KEY `idx_direction_created` (`direction`, `created_at`),
  KEY `idx_status_created`    (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='58번 서버 양방향 동기화 로그';

-- ============================================================
-- 8. 투자금 변경 이력 테이블
-- ============================================================
CREATE TABLE IF NOT EXISTS `rx_account_history` (
  `idx`           INT           NOT NULL AUTO_INCREMENT COMMENT '레코드 번호',
  `rx_uid`        VARCHAR(100)  NOT NULL               COMMENT '회원 UID',
  `rx_ce`         TINYINT(1)    NOT NULL DEFAULT 1      COMMENT '거래소 코드',
  `amount_type`   ENUM('futures','spot') DEFAULT 'futures' COMMENT '투자 유형',
  `before_amount` DECIMAL(20,4) NOT NULL DEFAULT 0      COMMENT '변경 전 금액 (USDT)',
  `after_amount`  DECIMAL(20,4) NOT NULL DEFAULT 0      COMMENT '변경 후 금액 (USDT)',
  `changed_by`    VARCHAR(50)   DEFAULT NULL            COMMENT '변경한 관리자 ID',
  `regdate`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '변경 일시',
  PRIMARY KEY (`idx`),
  KEY `idx_uid_ce`        (`rx_uid`, `rx_ce`),
  KEY `idx_uid_type_date` (`rx_uid`, `amount_type`, `regdate`),
  KEY `idx_ce_date`       (`rx_ce`, `regdate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='투자금 변경 이력';

-- ============================================================
-- 9. 집계 뷰 (대시보드 통계 최적화)
-- MySQL 5.7: CASE WHEN 방식 사용 (SUM(조건식) 미지원)
-- ============================================================

-- 봇 현황 집계 뷰 (거래소별 + CENTER별 요약)
DROP VIEW IF EXISTS `v_bot_summary`;
CREATE VIEW `v_bot_summary` AS
  SELECT
    rx_ce,
    mb_center,
    COUNT(*)                                                          AS total,
    SUM(CASE WHEN rx_send_ok = 'Y'          THEN 1 ELSE 0 END)       AS active,
    SUM(CASE WHEN rx_send_ok IN('N','S')    THEN 1 ELSE 0 END)       AS stopped,
    IFNULL(SUM(rx_acount), 0)                                        AS total_futures,
    IFNULL(SUM(rx_spot_acount), 0)                                   AS total_spot,
    MAX(rx_updated_at)                                               AS last_sync
  FROM `g5_member`
  WHERE mb_intercept_date IN ('', '00000000') OR mb_intercept_date IS NULL
  GROUP BY rx_ce, mb_center;

-- 동기화 상태 추적 뷰 (가동 중 봇 동기화 지연 감지)
DROP VIEW IF EXISTS `v_sync_status`;
CREATE VIEW `v_sync_status` AS
  SELECT
    rx_uid,
    mb_center,
    rx_ce,
    rx_send_ok,
    rx_updated_at,
    TIMESTAMPDIFF(MINUTE, rx_updated_at, NOW())  AS minutes_since_sync,
    CASE
      WHEN rx_updated_at IS NULL                                      THEN 'never'
      WHEN TIMESTAMPDIFF(MINUTE, rx_updated_at, NOW()) < 5           THEN 'fresh'
      WHEN TIMESTAMPDIFF(MINUTE, rx_updated_at, NOW()) < 60          THEN 'ok'
      ELSE 'stale'
    END AS sync_health
  FROM `g5_member`
  WHERE rx_send_ok = 'Y';

-- Telegram 미전송 큐 뷰
DROP VIEW IF EXISTS `v_telegram_pending`;
CREATE VIEW `v_telegram_pending` AS
  SELECT * FROM `telegram_log`
  WHERE status = 'pending'
    AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
  ORDER BY created_at ASC;

SET foreign_key_checks = 1;

-- ============================================================
-- 적용 확인 쿼리 (실행 후 주석 해제해서 확인)
-- ============================================================
-- SHOW TABLES;
-- SHOW COLUMNS FROM g5_member;
-- SHOW INDEX FROM g5_member;
-- SELECT admin_id, admin_role, is_active FROM admin_accounts;
-- SELECT * FROM v_bot_summary;
-- SELECT * FROM v_sync_status LIMIT 5;
