<?php
// 메인 화면 (로그인 전)
require 'db.php';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>음악 추천 대시보드</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app">
  <div class="topbar">
    <?php if (isset($_SESSION['user_id'])): ?>
      <a class="btn primary" href="result.php">내 분석 보기</a>
    <?php else: ?>
      <a class="btn primary" href="login.php">로그인</a>
    <?php endif; ?>
    <span class="brand">♪ 음악 추천</span>
  </div>

  <div class="hero">
    <h1>원하는 음악을 추천해드립니다.</h1>
    <p class="muted">내가 들어 온 음악을 분석해서, 취향에 맞는 곡과 플레이리스트를 찾아 드려요.</p>

    <!-- 소개 그림: 음악 파형 모양 막대 -->
    <div class="wave" aria-hidden="true">
      <?php foreach ([22, 40, 30, 58, 36, 64, 44, 28, 50, 34, 60, 26, 42] as $height): ?>
        <i style="height:<?= $height ?>px"></i>
      <?php endforeach; ?>
    </div>

    <!-- 기능 설명 3가지 -->
    <div class="steps">
      <div class="panel"><b>청취 기록 분석</b><span class="muted small">많이 들은 장르와 월별 청취 수를 차트로 보여 줘요.</span></div>
      <div class="panel"><b>취향에 맞는 추천</b><span class="muted small">추천 플레이리스트 TOP 3와 아직 안 들은 곡을 골라 드려요.</span></div>
      <div class="panel"><b>친구와 공유</b><span class="muted small">추천 아티스트 팜플렛을 이미지나 링크로 보낼 수 있어요.</span></div>
    </div>
  </div>
</div>
</body>
</html>
