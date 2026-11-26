<?php
// 재생 버튼: 청취 기록을 1회 남기고, 그 곡의 유튜브 페이지로 이동합니다. (결과 화면에서 새 탭으로 열림)
require 'db.php';
require 'lib.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$trackId = (int) ($_POST['track_id'] ?? 0);
$stmt = $conn->prepare("SELECT track_id, title, artist, youtube_url FROM tracks WHERE track_id = ?");
$stmt->bind_param('i', $trackId);
$stmt->execute();
$track = $stmt->get_result()->fetch_assoc();

if (!$track || $_SERVER['REQUEST_METHOD'] !== 'POST') {     // 없는 곡이면 결과 화면으로
    header('Location: result.php');
    exit;
}

// 오늘 날짜로 청취 기록 1회 추가
$add = $conn->prepare("INSERT INTO plays (user_id, track_id, played_on, play_count) VALUES (?, ?, CURDATE(), 1)");
$add->bind_param('si', $_SESSION['user_id'], $trackId);
$add->execute();

header('Location: ' . youtube_link($track));
exit;
