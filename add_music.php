<?php
require 'db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
// Demo-only page: implement a real administrator role before public deployment.
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $artist = trim($_POST['artist'] ?? '');
    $genre = trim($_POST['genre'] ?? '');
    if ($title === '' || $artist === '' || $genre === '' || mb_strlen($title)>100 || mb_strlen($artist)>100 || mb_strlen($genre)>30) {
        $message = '제목, 가수, 장르를 올바르게 입력해 주세요.';
    } else {
        $stmt = $conn->prepare('SELECT track_id FROM tracks WHERE title = ? AND artist = ? LIMIT 1');
        $stmt->bind_param('ss', $title, $artist); $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) $message = '이미 등록된 음악입니다.';
        else {
            $stmt = $conn->prepare('INSERT INTO tracks (title, artist, genre) VALUES (?, ?, ?)');
            $stmt->bind_param('sss', $title, $artist, $genre); $stmt->execute();
            $message = '음악을 추가했습니다.';
        }
    }
}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>음악 추가</title><link rel="stylesheet" href="style.css"></head>
<body><div class="app"><div class="topbar"><a class="btn" href="result.php">결과로 돌아가기</a><b>DB 음악 추가 (개발용)</b></div>
<div class="panel"><h2>음악 추가</h2><p><?= h($message) ?></p>
<form method="post"><label class="field">제목<input name="title" required maxlength="100"></label>
<label class="field">가수<input name="artist" required maxlength="100"></label>
<label class="field">장르<input name="genre" required maxlength="30"></label>
<button class="btn primary" type="submit">DB에 저장</button></form></div></div></body></html>
