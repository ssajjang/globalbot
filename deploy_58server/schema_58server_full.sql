-- ============================================================
-- 58번 대시보드 서버 - 전체 DB 스키마
-- 적용 서버: 58번 서버 (대시보드 서버)
-- 적용 명령: mysql -u root -p 58번DB명 < schema_58server_full.sql
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ============================================================
-- 1. 멀티도메인 라우팅 설정 테이블
--    [147번 → 58번 Push로 데이터 수신]
--    index.php 라우터가 이 테이블을 조회하여 대시보드 자동 분기
-- ============================================================
CREATE TABLE IF NOT EXISTS `rx_domain_config` (
  `domain_no`     INT          NOT NULL AUTO_INCREMENT COMMENT '도메인 고유번호',
  `domain_host`   VARCHAR(255) NOT NULL                COMMENT '도메인 주소 (www 제외, 예: abc.com)',
  `site_name`     VARCHAR(100) NOT NULL DEFAULT ''     COMMENT '사이트 이름',
  `rx_ce`         TINYINT      NOT NULL DEFAULT 1      COMMENT '거래소코드 1:Toobit 2:Gateio 3:Websea 4:Deepcoin',
  `exchange_name` VARCHAR(50)  NOT NULL DEFAULT 'Toobit' COMMENT '거래소 이름',
  `logo_url`      VARCHAR(500) DEFAULT NULL            COMMENT '로고 이미지 URL',
  `theme_color`   VARCHAR(20)  NOT NULL DEFAULT '#0d6efd' COMMENT '테마 색상(hex)',
  `telegram_bot`  VARCHAR(255) DEFAULT NULL            COMMENT '텔레그램 봇 토큰',
  `telegram_chat` VARCHAR(100) DEFAULT NULL            COMMENT '텔레그램 채팅방 ID',
  `mb_center`     VARCHAR(50)  DEFAULT NULL            COMMENT '소속 CENTER (147번 관리자 기준)',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1      COMMENT '1:활성, 0:비활성',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '등록일',
  `updated_at`    DATETIME     ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',
  PRIMARY KEY (`domain_no`),
  UNIQUE KEY `uq_domain_host`  (`domain_host`),
  KEY `idx_rx_ce`              (`rx_ce`),
  KEY `idx_mb_center`          (`mb_center`),
  KEY `idx_is_active_ce`       (`is_active`, `rx_ce`) -- 복합 인덱스 (라우터 조회 최적화)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='멀티도메인 거래소 설정 (147번 관리자에서 Push 수신)';

-- ============================================================
-- 2. 회원 테이블 (기존 레거시 테이블에 컬럼 추가)
--    [147번 → 58번 Push 동기화 수신 대상]
-- ============================================================
CREATE TABLE IF NOT EXISTS `rx_member` (
  `rx_no`         INT          NOT NULL AUTO_INCREMENT COMMENT '레코드 번호',
  `rx_uid`        VARCHAR(100) NOT NULL                COMMENT '회원 UID (거래소 기준)',
  `mb_id`         VARCHAR(50)  DEFAULT NULL            COMMENT '로그인 ID (대시보드)',
  `mb_name`       VARCHAR(50)  DEFAULT NULL            COMMENT '회원 이름',
  `mb_hp`         VARCHAR(20)  DEFAULT NULL            COMMENT '휴대폰 번호',
  `mb_email`      VARCHAR(100) DEFAULT NULL            COMMENT '이메일',
  `mb_center`     VARCHAR(50)  DEFAULT NULL            COMMENT '소속 CENTER',
  `rx_ce`         TINYINT      NOT NULL DEFAULT 1      COMMENT '거래소 코드',
  `mb_class`      TINYINT      NOT NULL DEFAULT 1      COMMENT '회원 클래스 (1,2,3)',
  `rx_acount`     DECIMAL(15,2) NOT NULL DEFAULT 0     COMMENT 'Futures 투자금 (USDT)',
  `rx_spot_acount` DECIMAL(15,2) NOT NULL DEFAULT 0   COMMENT 'Spot 투자금 (USDT)',
  `rx_rate`       DECIMAL(5,2) NOT NULL DEFAULT 0      COMMENT '수익률(%)',
  `rx_apikey`     TEXT         DEFAULT NULL            COMMENT 'API Key (평문 저장 - 거래소 연동용)',
  `rx_sskey`      TEXT         DEFAULT NULL            COMMENT 'Secret Key (평문 저장 - 거래소 연동용)',
  `rx_passphrase` TEXT         DEFAULT NULL            COMMENT 'Passphrase (평문 저장 - Deepcoin 전용)',
  `rx_password`   VARCHAR(255) DEFAULT NULL            COMMENT '로그인 비밀번호 (해시)',
  `rx_send_ok`    CHAR(1)      NOT NULL DEFAULT 'N'   COMMENT '봇상태 N:중지 Y:가동 S:시그널중지',
  `rx_reserve_percent` INT     NOT NULL DEFAULT 0      COMMENT '리저브율 (0/30/100)',
  `rx_updated_at` DATETIME     DEFAULT NULL            COMMENT '147번 서버 동기화 시각',
  `reg_date`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '등록일',
  PRIMARY KEY (`rx_no`),
  UNIQUE KEY `uq_uid_ce`        (`rx_uid`, `rx_ce`),          -- 거래소별 중복 방지
  KEY `idx_send_ok_ce`          (`rx_send_ok`, `rx_ce`),       -- 봇 상태 조회 최적화
  KEY `idx_mb_center`           (`mb_center`),
  KEY `idx_rx_ce`               (`rx_ce`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='대시보드 회원 정보 (147번 관리자와 양방향 동기화)';

-- ============================================================
-- 3. 일별 손익 기록 (대시보드 차트용)
-- ============================================================
CREATE TABLE IF NOT EXISTS `rx_daily_record` (
  `record_no`     INT          NOT NULL AUTO_INCREMENT COMMENT '레코드 번호',
  `rx_uid`        VARCHAR(100) NOT NULL                COMMENT '회원 UID',
  `rx_ce`         TINYINT      NOT NULL DEFAULT 1      COMMENT '거래소 코드',
  `record_date`   DATE         NOT NULL                COMMENT '기록 날짜',
  `daily_pnl`     DECIMAL(15,4) NOT NULL DEFAULT 0    COMMENT '당일 손익 (USDT)',
  `daily_rate`    DECIMAL(8,4)  NOT NULL DEFAULT 0    COMMENT '당일 수익률(%)',
  `total_amount`  DECIMAL(15,2) NOT NULL DEFAULT 0    COMMENT '당일 잔고',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`record_no`),
  UNIQUE KEY `uq_uid_ce_date` (`rx_uid`, `rx_ce`, `record_date`),
  KEY `idx_uid_date`          (`rx_uid`, `record_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='일별 손익 기록';

-- ============================================================
-- 4. 주간 TXID 관리 (중복 주문 방지)
-- ============================================================
CREATE TABLE IF NOT EXISTS `rx_weekly_txid` (
  `txid_no`       INT          NOT NULL AUTO_INCREMENT,
  `rx_uid`        VARCHAR(100) NOT NULL                COMMENT '회원 UID',
  `rx_ce`         TINYINT      NOT NULL DEFAULT 1      COMMENT '거래소 코드',
  `txid`          VARCHAR(100) NOT NULL                COMMENT '거래 ID',
  `symbol`        VARCHAR(30)  DEFAULT NULL            COMMENT '거래 심볼',
  `week_key`      VARCHAR(10)  NOT NULL                COMMENT '주차 키 (예: 2024-W01)',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`txid_no`),
  UNIQUE KEY `uq_txid`        (`txid`, `rx_ce`),
  KEY `idx_uid_week`          (`rx_uid`, `week_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='주간 TXID 관리 (중복 주문 방지)';

-- ============================================================
-- 5. 전역 설정 테이블 (대시보드 공통 설정)
-- ============================================================
CREATE TABLE IF NOT EXISTS `rx_global_settings` (
  `setting_key`   VARCHAR(100) NOT NULL                COMMENT '설정 키',
  `setting_value` TEXT         DEFAULT NULL            COMMENT '설정 값',
  `rx_ce`         TINYINT      NOT NULL DEFAULT 0      COMMENT '거래소 코드 (0=전체)',
  `updated_at`    DATETIME     ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`, `rx_ce`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='전역 공용 설정';

-- ============================================================
-- 기본 전역 설정 초기 데이터
-- ============================================================
INSERT IGNORE INTO `rx_global_settings` (setting_key, setting_value, rx_ce) VALUES
('max_leverage',    '50',   0),
('default_symbol',  'BTC',  1), -- Toobit 기본 심볼
('default_symbol',  'BTC',  3), -- WebSea 기본 심볼
('default_symbol',  'BTC',  4); -- Deepcoin 기본 심볼

SET foreign_key_checks = 1;

-- ============================================================
-- 적용 확인 쿼리
-- ============================================================
-- SHOW TABLES;
-- DESCRIBE rx_member;
-- SHOW INDEX FROM rx_domain_config;
