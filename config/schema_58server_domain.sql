-- ============================================================
-- 58번 서버용 도메인 설정 테이블
-- (147번 글로벌어드민에서 multi_domain.php로 등록 → 58번 서버에 Push)
-- ============================================================
CREATE TABLE IF NOT EXISTS `rx_domain_config` (
  `domain_no`     INT          NOT NULL AUTO_INCREMENT COMMENT '도메인 고유번호',
  `domain_host`   VARCHAR(255) NOT NULL                COMMENT '도메인 주소 (www 제외, 예: abc.com)',
  `site_name`     VARCHAR(100) NOT NULL DEFAULT ''     COMMENT '사이트 이름',
  `rx_ce`         TINYINT      NOT NULL DEFAULT 1      COMMENT '거래소 코드 (1:Toobit 2:Gateio 3:Websea 4:Deepcoin)',
  `exchange_name` VARCHAR(50)  NOT NULL DEFAULT 'Toobit' COMMENT '거래소 이름',
  `logo_url`      VARCHAR(500) DEFAULT NULL            COMMENT '로고 이미지 URL',
  `theme_color`   VARCHAR(20)  NOT NULL DEFAULT '#0d6efd' COMMENT '테마 색상 (hex)',
  `telegram_bot`  VARCHAR(255) DEFAULT NULL            COMMENT '텔레그램 봇 토큰',
  `telegram_chat` VARCHAR(100) DEFAULT NULL            COMMENT '텔레그램 채팅방 ID',
  `mb_center`     VARCHAR(50)  DEFAULT NULL            COMMENT '소속 CENTER (147번 관리자 기준)',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1      COMMENT '1:활성, 0:비활성',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '등록일',
  `updated_at`    DATETIME     ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',
  PRIMARY KEY (`domain_no`),
  UNIQUE KEY `uq_domain_host` (`domain_host`),
  KEY `idx_rx_ce` (`rx_ce`),
  KEY `idx_mb_center` (`mb_center`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='멀티도메인 거래소 설정 (58번 대시보드)';

-- 테스트 데이터 예시
-- INSERT INTO rx_domain_config (domain_host, site_name, rx_ce, exchange_name, theme_color)
-- VALUES ('toobit.example.com', 'Toobit 트레이딩', 1, 'Toobit', '#0d6efd');
-- VALUES ('deepcoin.example.com', 'Deepcoin 트레이딩', 4, 'Deepcoin', '#dc3545');
-- VALUES ('websea.example.com', 'WebSea 트레이딩', 3, 'WebSea', '#ffc107');
