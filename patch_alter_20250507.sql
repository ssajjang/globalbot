-- ============================================================
-- 147번 서버 DB 스키마 패치 (이번 수정에 필요한 ALTER 문)
-- 실행 전 반드시 백업 하세요!
-- ============================================================

-- 1. domain_management 테이블에 멀티 거래소 저장 컬럼 추가
ALTER TABLE domain_management 
ADD COLUMN IF NOT EXISTS exchanges TEXT DEFAULT NULL 
COMMENT '멀티 거래소 목록 (JSON 배열, 예: [1,2,3])' 
AFTER exchange_name;

-- 2. center_TB 테이블에 created_at 컬럼이 없으면 추가
ALTER TABLE center_TB 
ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP 
COMMENT '센터 생성일';
