-- ============================================================
-- schema_patch_v3.sql
-- domain_management 테이블 구조 패치 (rx_ce, exchange_name 컬럼 추가)
-- global_admin_schema.sql의 exchanges(JSON) 방식을 단일 rx_ce 방식으로 업그레이드
-- ============================================================

-- 1. domain_management에 rx_ce 컬럼 추가
ALTER TABLE `domain_management`
  ADD COLUMN IF NOT EXISTS `rx_ce`
    TINYINT NOT NULL DEFAULT 1
    COMMENT '거래소 코드 (1:Toobit 2:Gateio 3:Websea 4:Deepcoin)'
    AFTER `primary_color`,
  ADD COLUMN IF NOT EXISTS `exchange_name`
    VARCHAR(50) NOT NULL DEFAULT 'Toobit'
    COMMENT '거래소 이름'
    AFTER `rx_ce`,
  ADD INDEX IF NOT EXISTS `idx_rx_ce` (`rx_ce`);

-- 2. 기존 exchanges JSON 컬럼에서 rx_ce로 마이그레이션 (첫 번째 값 사용)
UPDATE `domain_management`
SET `rx_ce` = CAST(JSON_UNQUOTE(JSON_EXTRACT(`exchanges`, '$[0]')) AS UNSIGNED)
WHERE `exchanges` IS NOT NULL AND `exchanges` != '[]' AND `rx_ce` = 1;

-- 3. admin_accounts에 sub 역할 및 permissions 컬럼 (schema_patch_v2 포함)
ALTER TABLE `admin_accounts`
  MODIFY COLUMN `admin_role`
    ENUM('super','center','sub') NOT NULL DEFAULT 'sub'
    COMMENT '총어드민:super, 센터어드민:center, 서브어드민:sub',
  ADD COLUMN IF NOT EXISTS `permissions`
    TEXT DEFAULT NULL
    COMMENT '허용된 메뉴 키 목록 (JSON 배열)',
  ADD COLUMN IF NOT EXISTS `parent_admin_id`
    VARCHAR(50) DEFAULT NULL
    COMMENT '이 계정을 생성한 상위 관리자 ID';

-- 4. DB 백업 로그 테이블
CREATE TABLE IF NOT EXISTS `db_backup_log` (
  `backup_no`   INT          NOT NULL AUTO_INCREMENT,
  `file_name`   VARCHAR(255) NOT NULL,
  `file_size`   BIGINT       NOT NULL DEFAULT 0,
  `file_path`   VARCHAR(500) NOT NULL,
  `status`      ENUM('success','failed') NOT NULL DEFAULT 'success',
  `error_msg`   TEXT DEFAULT NULL,
  `created_by`  VARCHAR(50)  NOT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`backup_no`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_status`     (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='DB 백업 이력';

-- 5. 58번 서버 동기화 로그 테이블
CREATE TABLE IF NOT EXISTS `server58_sync_log` (
  `sync_no`     INT          NOT NULL AUTO_INCREMENT,
  `action`      VARCHAR(100) NOT NULL,
  `target_uid`  VARCHAR(50)  DEFAULT NULL,
  `request`     TEXT         DEFAULT NULL,
  `response`    TEXT         DEFAULT NULL,
  `http_code`   INT          DEFAULT NULL,
  `status`      ENUM('success','failed') NOT NULL DEFAULT 'success',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`sync_no`),
  KEY `idx_action`     (`action`),
  KEY `idx_target_uid` (`target_uid`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='58번 서버 동기화 로그';

-- 6. rx_account_history 테이블 (58번 서버 대시보드와 공유)
CREATE TABLE IF NOT EXISTS `rx_account_history` (
  `idx`          INT          NOT NULL AUTO_INCREMENT,
  `rx_uid`       VARCHAR(100) NOT NULL COMMENT '회원 UID',
  `rx_ce`        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '거래소 코드',
  `amount_type`  ENUM('futures','spot') DEFAULT 'futures',
  `before_amount` DECIMAL(20,4) NOT NULL DEFAULT 0,
  `after_amount`  DECIMAL(20,4) NOT NULL DEFAULT 0,
  `regdate`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`idx`),
  KEY `idx_uid_ce` (`rx_uid`, `rx_ce`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='투자금 변경 이력';

-- 7. g5_member 필수 컬럼 (없는 경우 추가)
ALTER TABLE `g5_member`
  ADD COLUMN IF NOT EXISTS `rx_uid`              VARCHAR(50)  DEFAULT NULL COMMENT '거래소 UID',
  ADD COLUMN IF NOT EXISTS `rx_ce`               TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '거래소 코드',
  ADD COLUMN IF NOT EXISTS `rx_acount`           DECIMAL(20,4) NOT NULL DEFAULT 0 COMMENT 'Futures 투자금',
  ADD COLUMN IF NOT EXISTS `rx_spot_acount`      DECIMAL(20,4) NOT NULL DEFAULT 0 COMMENT 'Spot 유보금',
  ADD COLUMN IF NOT EXISTS `rx_send_ok`          ENUM('Y','N','S','R') NOT NULL DEFAULT 'N' COMMENT '봇 상태',
  ADD COLUMN IF NOT EXISTS `rx_apikey`           TEXT DEFAULT NULL COMMENT 'API Key (평문 저장)',
  ADD COLUMN IF NOT EXISTS `rx_sskey`            TEXT DEFAULT NULL COMMENT 'Secret Key (평문 저장)',
  ADD COLUMN IF NOT EXISTS `rx_reserve_percent`  TINYINT(3) NOT NULL DEFAULT 0 COMMENT '유보율(0,30,100)',
  ADD COLUMN IF NOT EXISTS `rx_accumulated_profit` DECIMAL(20,4) DEFAULT 0 COMMENT '누적 순손익',
  ADD COLUMN IF NOT EXISTS `rx_updated_at`       DATETIME DEFAULT NULL COMMENT '최종 동기화 일시',
  ADD COLUMN IF NOT EXISTS `mb_center`           VARCHAR(100) DEFAULT NULL COMMENT '소속 CENTER',
  ADD COLUMN IF NOT EXISTS `mb_class`            INT NOT NULL DEFAULT 1 COMMENT '투자 클래스(1,2,3,9)',
  ADD INDEX IF NOT EXISTS `idx_rx_uid`           (`rx_uid`),
  ADD INDEX IF NOT EXISTS `idx_rx_ce`            (`rx_ce`),
  ADD INDEX IF NOT EXISTS `idx_rx_send_ok`       (`rx_send_ok`),
  ADD INDEX IF NOT EXISTS `idx_mb_center`        (`mb_center`);
