-- ============================================================
-- [58번 서버] 대시보드 DB 완전 생성 스크립트 (독립형)
-- 대상 DB   : killer_pro_dash
-- MySQL 버전 : 5.7 호환
-- 실행 방법  : mysql -u root -p < create_db_58_mysql57.sql
-- 작성 기준  : 2026-05-04
-- ============================================================

SET NAMES utf8mb4;
SET SQL_MODE = '';
SET foreign_key_checks = 0;
SET time_zone = '+09:00';

-- ============================================================
-- 0. DB 생성
-- ============================================================
CREATE DATABASE IF NOT EXISTS `killer_pro_dash`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `killer_pro_dash`;

-- ============================================================
-- 1. 멀티도메인 라우팅 설정 테이블
-- 역할: 147번 관리자가 도메인 등록 → Push로 이 테이블에 저장
--       58번 index.php 가 이 테이블 조회 → 거래소 대시보드 자동 분기
-- ============================================================
CREATE TABLE IF NOT EXISTS `rx_domain_config` (
  `domain_no`     INT          NOT NULL AUTO_INCREMENT   COMMENT '도메인 고유번호',
  `domain_host`   VARCHAR(255) NOT NULL                  COMMENT '도메인 주소 (www 제외, 예: toobit.killerbot.vip)',
  `site_name`     VARCHAR(100) NOT NULL DEFAULT ''       COMMENT '사이트 이름 (대시보드 타이틀)',
  `rx_ce`         TINYINT      NOT NULL DEFAULT 1        COMMENT '거래소 코드 1:Toobit 2:Gateio 3:Websea 4:Deepcoin',
  `exchange_name` VARCHAR(50)  NOT NULL DEFAULT 'Toobit' COMMENT '거래소 이름 표시용',
  `logo_url`      VARCHAR(500) DEFAULT NULL              COMMENT '로고 이미지 URL',
  `theme_color`   VARCHAR(20)  NOT NULL DEFAULT '#0d6efd' COMMENT '테마 색상 (HEX)',
  `telegram_bot`  VARCHAR(255) DEFAULT NULL              COMMENT '텔레그램 봇 토큰',
  `telegram_chat` VARCHAR(100) DEFAULT NULL              COMMENT '텔레그램 채팅방 ID',
  `mb_center`     VARCHAR(50)  DEFAULT NULL              COMMENT '소속 CENTER (147번 관리자 기준)',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1        COMMENT '1:활성 0:비활성',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '등록일',
  `updated_at`    DATETIME     DEFAULT NULL              COMMENT '수정일',
  PRIMARY KEY (`domain_no`),
  UNIQUE KEY `uq_domain_host`  (`domain_host`),
  KEY `idx_rx_ce`              (`rx_ce`),
  KEY `idx_mb_center`          (`mb_center`),
  KEY `idx_is_active_ce`       (`is_active`, `rx_ce`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='멀티도메인 거래소 설정 (147번 관리자 Push 수신)';

-- 기본 도메인 예시 데이터
-- [필수] 실제 운영 도메인으로 변경 후 실행
INSERT IGNORE INTO `rx_domain_config`
  (`domain_host`, `site_name`, `rx_ce`, `exchange_name`, `theme_color`, `is_active`)
VALUES
  ('toobit.killerbot.vip',   'Toobit Dashboard',   1, 'Toobit',   '#0d6efd', 1),
  ('websea.killerbot.vip',   'WebSea Dashboard',   3, 'WebSea',   '#198754', 1),
  ('deepcoin.killerbot.vip', 'Deepcoin Dashboard', 4, 'Deepcoin', '#dc3545', 1);

-- ============================================================
-- 2. 회원 테이블
-- 147번 g5_member 와 양방향 동기화
-- API Key / Secret Key / Passphrase = 평문 저장 (암호화 안 함)
-- ============================================================
CREATE TABLE IF NOT EXISTS `rx_member` (
  `rx_no`              INT           NOT NULL AUTO_INCREMENT   COMMENT '레코드 번호',
  `rx_uid`             VARCHAR(100)  NOT NULL                  COMMENT '회원 UID (거래소 기준)',
  `mb_id`              VARCHAR(50)   DEFAULT NULL              COMMENT '대시보드 로그인 ID',
  `mb_pw`              VARCHAR(255)  DEFAULT NULL              COMMENT '대시보드 비밀번호 (bcrypt 해시)',
  `mb_name`            VARCHAR(50)   DEFAULT NULL              COMMENT '회원 이름',
  `mb_hp`              VARCHAR(20)   DEFAULT NULL              COMMENT '휴대폰 번호',
  `mb_email`           VARCHAR(100)  DEFAULT NULL              COMMENT '이메일',
  `mb_center`          VARCHAR(50)   DEFAULT NULL              COMMENT '소속 CENTER',
  `rx_ce`              TINYINT       NOT NULL DEFAULT 1        COMMENT '거래소 코드 1:Toobit 3:Websea 4:Deepcoin',
  `mb_class`           TINYINT       NOT NULL DEFAULT 1        COMMENT '회원 클래스 (1:기본 2:중급 3:고급)',
  `rx_acount`          DECIMAL(15,2) NOT NULL DEFAULT 0        COMMENT 'Futures 투자금 (USDT)',
  `rx_spot_acount`     DECIMAL(15,2) NOT NULL DEFAULT 0        COMMENT 'Spot 투자금 (USDT)',
  `rx_rate`            DECIMAL(5,2)  NOT NULL DEFAULT 0        COMMENT '수익률 (%)',
  `rx_apikey`          TEXT          DEFAULT NULL              COMMENT 'API Key (평문 저장 - 거래소 연동용)',
  `rx_sskey`           TEXT          DEFAULT NULL              COMMENT 'Secret Key (평문 저장 - 거래소 연동용)',
  `rx_passphrase`      TEXT          DEFAULT NULL              COMMENT 'Passphrase (평문 저장 - Deepcoin 전용)',
  `rx_password`        VARCHAR(255)  DEFAULT NULL              COMMENT '대시보드 로그인 비밀번호 (해시)',
  `rx_send_ok`         CHAR(1)       NOT NULL DEFAULT 'N'      COMMENT '봇 상태 N:중지 Y:가동 S:시그널중지',
  `rx_reserve_percent` INT           NOT NULL DEFAULT 0        COMMENT '리저브율 (0/30/100)',
  `rx_memo`            TEXT          DEFAULT NULL              COMMENT '관리자 메모',
  `rx_updated_at`      DATETIME      DEFAULT NULL              COMMENT '147번 서버 동기화 시각',
  `reg_date`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '등록일',
  PRIMARY KEY (`rx_no`),
  UNIQUE KEY `uq_uid_ce`      (`rx_uid`, `rx_ce`),
  KEY `idx_send_ok_ce`        (`rx_send_ok`, `rx_ce`),
  KEY `idx_mb_center`         (`mb_center`),
  KEY `idx_rx_ce`             (`rx_ce`),
  KEY `idx_mb_id`             (`mb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='대시보드 회원 정보 (147번 관리자와 양방향 동기화)';

-- ============================================================
-- 3. 일별 손익 기록 테이블
-- 봇이 매일 수익/손실 결과를 기록 → 대시보드 차트에 표시
-- ============================================================
CREATE TABLE IF NOT EXISTS `rx_daily_record` (
  `record_no`    INT           NOT NULL AUTO_INCREMENT COMMENT '레코드 번호',
  `rx_uid`       VARCHAR(100)  NOT NULL                COMMENT '회원 UID',
  `rx_ce`        TINYINT       NOT NULL DEFAULT 1      COMMENT '거래소 코드',
  `record_date`  DATE          NOT NULL                COMMENT '기록 날짜 (YYYY-MM-DD)',
  `daily_pnl`    DECIMAL(15,4) NOT NULL DEFAULT 0      COMMENT '당일 손익 (USDT)',
  `daily_rate`   DECIMAL(8,4)  NOT NULL DEFAULT 0      COMMENT '당일 수익률 (%)',
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0      COMMENT '당일 잔고 (USDT)',
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '기록 생성일',
  PRIMARY KEY (`record_no`),
  UNIQUE KEY `uq_uid_ce_date` (`rx_uid`, `rx_ce`, `record_date`),
  KEY `idx_uid_date`          (`rx_uid`, `record_date`),
  KEY `idx_ce_date`           (`rx_ce`, `record_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='일별 손익 기록 (대시보드 차트용)';

-- ============================================================
-- 4. 주간 TXID 관리 테이블
-- 같은 주문이 중복 실행되는 것을 방지하는 중복 체크 테이블
-- ============================================================
CREATE TABLE IF NOT EXISTS `rx_weekly_txid` (
  `txid_no`    INT          NOT NULL AUTO_INCREMENT COMMENT '레코드 번호',
  `rx_uid`     VARCHAR(100) NOT NULL                COMMENT '회원 UID',
  `rx_ce`      TINYINT      NOT NULL DEFAULT 1      COMMENT '거래소 코드',
  `txid`       VARCHAR(100) NOT NULL                COMMENT '거래 ID (거래소 발급)',
  `symbol`     VARCHAR(30)  DEFAULT NULL            COMMENT '거래 심볼 (예: BTCUSDT)',
  `week_key`   VARCHAR(10)  NOT NULL                COMMENT '주차 키 (예: 2026-W18)',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '기록 일시',
  PRIMARY KEY (`txid_no`),
  UNIQUE KEY `uq_txid`    (`txid`, `rx_ce`),
  KEY `idx_uid_week`      (`rx_uid`, `week_key`),
  KEY `idx_week_key`      (`week_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='주간 TXID 관리 (중복 주문 방지)';

-- ============================================================
-- 5. 전역 설정 테이블
-- 거래소별 공통 설정값 (레버리지 기본값, 기본 심볼 등)
-- ============================================================
CREATE TABLE IF NOT EXISTS `rx_global_settings` (
  `setting_key`   VARCHAR(100) NOT NULL              COMMENT '설정 키',
  `setting_value` TEXT         DEFAULT NULL          COMMENT '설정 값',
  `rx_ce`         TINYINT      NOT NULL DEFAULT 0    COMMENT '거래소 코드 (0=전체 공통)',
  `updated_at`    DATETIME     DEFAULT NULL          COMMENT '마지막 수정일',
  PRIMARY KEY (`setting_key`, `rx_ce`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='거래소별 전역 공용 설정';

-- 기본 설정값 삽입
INSERT IGNORE INTO `rx_global_settings` (`setting_key`, `setting_value`, `rx_ce`) VALUES
  ('max_leverage',   '50',  0),   -- 전체 공통 최대 레버리지
  ('default_symbol', 'BTC', 1),   -- Toobit 기본 심볼
  ('default_symbol', 'BTC', 3),   -- WebSea 기본 심볼
  ('default_symbol', 'BTC', 4);   -- Deepcoin 기본 심볼

-- ============================================================
-- 6. 집계 뷰 (대시보드 통계 최적화)
-- MySQL 5.7: CASE WHEN 방식 사용
-- ============================================================

-- 거래소별 회원 현황 요약
DROP VIEW IF EXISTS `v_member_summary`;
CREATE VIEW `v_member_summary` AS
  SELECT
    rx_ce,
    mb_center,
    COUNT(*)                                                    AS total_member,
    SUM(CASE WHEN rx_send_ok = 'Y' THEN 1 ELSE 0 END)          AS active_bot,
    SUM(CASE WHEN rx_send_ok = 'N' THEN 1 ELSE 0 END)          AS stopped_bot,
    IFNULL(SUM(rx_acount), 0)                                  AS total_futures,
    IFNULL(SUM(rx_spot_acount), 0)                             AS total_spot,
    MAX(rx_updated_at)                                         AS last_sync
  FROM `rx_member`
  GROUP BY rx_ce, mb_center;

-- 동기화 지연 회원 목록 (5분 이상 미동기화 가동 봇)
DROP VIEW IF EXISTS `v_sync_delayed`;
CREATE VIEW `v_sync_delayed` AS
  SELECT
    rx_uid,
    mb_name,
    rx_ce,
    rx_send_ok,
    rx_updated_at,
    TIMESTAMPDIFF(MINUTE, rx_updated_at, NOW()) AS delay_min
  FROM `rx_member`
  WHERE rx_send_ok = 'Y'
    AND (
      rx_updated_at IS NULL
      OR TIMESTAMPDIFF(MINUTE, rx_updated_at, NOW()) > 5
    );

SET foreign_key_checks = 1;

-- ============================================================
-- 적용 확인 쿼리 (실행 후 주석 해제해서 확인)
-- ============================================================
-- SHOW TABLES;
-- DESCRIBE rx_domain_config;
-- DESCRIBE rx_member;
-- SELECT domain_host, rx_ce, exchange_name, is_active FROM rx_domain_config;
-- SELECT setting_key, setting_value, rx_ce FROM rx_global_settings;
-- SELECT * FROM v_member_summary;
