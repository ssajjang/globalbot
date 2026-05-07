-- ============================================================
-- schema_patch_v4.sql - DB 성능 최적화 + 양방향 동기화 지원
-- 적용 서버: 147번 (global_admin_db)
-- 적용 순서: v1 → v2 → v3 → v4 (이 파일)
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ============================================================
-- 1. g5_member 쿼리 최적화 인덱스 (WHERE/JOIN 핵심 컬럼)
-- ============================================================
-- [성능] rx_send_ok + rx_ce 복합 인덱스 (봇 가동 목록 조회 최적화)
ALTER TABLE `g5_member`
  ADD INDEX IF NOT EXISTS `idx_send_ok_ce`  (`rx_send_ok`, `rx_ce`),
  ADD INDEX IF NOT EXISTS `idx_ce_center`   (`rx_ce`, `mb_center`),
  ADD INDEX IF NOT EXISTS `idx_center_class`(`mb_center`, `mb_class`),
  ADD INDEX IF NOT EXISTS `idx_mb_datetime` (`mb_datetime`),
  ADD COLUMN IF NOT EXISTS `rx_updated_at`  DATETIME DEFAULT NULL COMMENT '58번 서버 동기화 마지막 시각';

-- ============================================================
-- 2. domain_management 최적화
-- ============================================================
ALTER TABLE `domain_management`
  ADD INDEX IF NOT EXISTS `idx_center_active` (`center_name`, `is_active`),
  ADD INDEX IF NOT EXISTS `idx_rx_ce_active`  (`rx_ce`, `is_active`),
  MODIFY COLUMN `exchanges` TEXT DEFAULT NULL COMMENT '[구버전] exchanges JSON (rx_ce로 마이그레이션됨)';

-- ============================================================
-- 3. admin_audit_log 파티셔닝 (월별 분할 - 10만건 이상 시 필수)
-- 주의: 파티셔닝은 기존 테이블에 바로 적용 불가, 신규 생성 권장
-- ============================================================
-- admin_audit_log는 현재 단일 테이블로 유지하고 만료 삭제 정책으로 대체
-- 3개월 이상 된 로그는 자동 아카이빙 (Cron 설정 필요)

-- ============================================================
-- 4. 양방향 동기화 로그 테이블 개선
-- ============================================================
ALTER TABLE `server58_sync_log`
  ADD COLUMN IF NOT EXISTS `direction`  ENUM('push','pull') NOT NULL DEFAULT 'push'
    COMMENT 'push: 147→58, pull: 58→147'
    AFTER `action`,
  ADD COLUMN IF NOT EXISTS `elapsed_ms` INT DEFAULT NULL
    COMMENT 'API 응답 시간 (밀리초)'
    AFTER `http_code`,
  ADD INDEX IF NOT EXISTS `idx_direction_created` (`direction`, `created_at`),
  ADD INDEX IF NOT EXISTS `idx_status_created`    (`status`, `created_at`);

-- ============================================================
-- 5. rx_account_history 인덱스 추가 (투자금 이력 조회 최적화)
-- ============================================================
ALTER TABLE `rx_account_history`
  ADD INDEX IF NOT EXISTS `idx_uid_type_date` (`rx_uid`, `amount_type`, `regdate`),
  ADD INDEX IF NOT EXISTS `idx_ce_date`        (`rx_ce`, `regdate`);

-- ============================================================
-- 6. telegram_log 오래된 레코드 정리 뷰 생성
-- ============================================================
CREATE OR REPLACE VIEW `v_telegram_pending` AS
  SELECT * FROM telegram_log
  WHERE status = 'pending'
    AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
  ORDER BY created_at ASC;

-- ============================================================
-- 7. 실시간 봇 현황 집계 뷰 (147번 대시보드 최적화)
-- SELECT * 대신 뷰를 사용해 DB 부하 최소화
-- ============================================================
CREATE OR REPLACE VIEW `v_bot_summary` AS
  SELECT
    rx_ce,
    mb_center,
    COUNT(*)                                          AS total,
    SUM(rx_send_ok = 'Y')                             AS active,
    SUM(rx_send_ok IN ('N','S'))                      AS stopped,
    COALESCE(SUM(rx_acount), 0)                       AS total_futures,
    COALESCE(SUM(rx_spot_acount), 0)                  AS total_spot,
    MAX(rx_updated_at)                                AS last_sync
  FROM g5_member
  WHERE mb_intercept_date IN ('', '00000000') OR mb_intercept_date IS NULL
  GROUP BY rx_ce, mb_center;

-- ============================================================
-- 8. admin_accounts 로그인 실패 횟수 컬럼 추가 (Brute-force 차단)
-- ============================================================
ALTER TABLE `admin_accounts`
  ADD COLUMN IF NOT EXISTS `login_fail_count` TINYINT NOT NULL DEFAULT 0
    COMMENT '연속 로그인 실패 횟수',
  ADD COLUMN IF NOT EXISTS `lock_until`  DATETIME DEFAULT NULL
    COMMENT '잠금 해제 시각 (NULL이면 잠금 없음)',
  ADD INDEX IF NOT EXISTS `idx_admin_center_active` (`admin_center`, `is_active`);

-- ============================================================
-- 9. 58번 서버 동기화 상태 추적 (rx_updated_at 기준)
-- ============================================================
CREATE OR REPLACE VIEW `v_sync_status` AS
  SELECT
    rx_uid,
    mb_center,
    rx_ce,
    rx_send_ok,
    rx_updated_at,
    TIMESTAMPDIFF(MINUTE, rx_updated_at, NOW()) AS minutes_since_sync,
    CASE
      WHEN rx_updated_at IS NULL THEN 'never'
      WHEN TIMESTAMPDIFF(MINUTE, rx_updated_at, NOW()) < 5  THEN 'fresh'
      WHEN TIMESTAMPDIFF(MINUTE, rx_updated_at, NOW()) < 60 THEN 'ok'
      ELSE 'stale'
    END AS sync_health
  FROM g5_member
  WHERE rx_send_ok = 'Y'; -- 가동 중인 봇만

SET foreign_key_checks = 1;

-- ============================================================
-- 적용 확인 쿼리
-- ============================================================
-- SHOW INDEX FROM g5_member;
-- SHOW INDEX FROM domain_management;
-- SELECT * FROM v_bot_summary;
-- SELECT * FROM v_sync_status LIMIT 10;
