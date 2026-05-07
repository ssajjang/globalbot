<?php
/**
 * error_403.php - 권한 없음 에러 페이지
 */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <title>접근 권한 없음</title>
  <style>
    body { font-family: 'Noto Sans KR', sans-serif; background:#0b0f1a; color:#e2e8f0; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
    .box { text-align:center; padding:40px; }
    .icon { font-size:4rem; margin-bottom:16px; }
    h1 { font-size:1.5rem; color:#FF3B5C; margin-bottom:8px; }
    p  { color:rgba(255,255,255,0.5); margin-bottom:24px; }
    a  { background:rgba(0,212,255,0.1); border:1px solid rgba(0,212,255,0.3); color:#00D4FF; padding:10px 20px; border-radius:8px; text-decoration:none; }
  </style>
</head>
<body>
<div class="box">
  <div class="icon">🔒</div>
  <h1>접근 권한이 없습니다</h1>
  <p>이 페이지에 접근할 권한이 없습니다. 관리자에게 문의하세요.</p>
  <a href="javascript:history.back()">← 뒤로가기</a>
</div>
</body>
</html>
