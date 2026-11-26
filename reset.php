<?php
// [청취 기록 초기화] 로그인한 사람 "본인"의 청취 기록(plays)만 모두 지웁니다.
//   - 다른 사람의 기록, 곡 목록(tracks), 계정은 건드리지 않습니다.
//   - 주소창에 쳐서 실수로 지워지지 않도록 POST 로만 받고, 결과 화면에서 받은 확인 번호(token)가 맞아야 지웁니다.
require 'db.php';
require 'lib.php';
if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: result.php'); exit; }
$uid = $_SESSION['user_id'];

if (!hash_equals(form_token(), (string) ($_POST['token'] ?? ''))) {
    $_SESSION['flash'] = '요청이 올바르지 않아요. 화면을 새로고침한 뒤 다시 눌러 주세요.';
    header('Location: result.php');
    exit;
}

$stmt = $conn->prepare("DELETE FROM plays WHERE user_id = ?");
$stmt->bind_param('s', $uid);
$stmt->execute();
$n = $stmt->affected_rows;

// 스포티파이 "마지막으로 가져온 시각"도 비워 둡니다. (연결 자체는 유지 → 다시 가져오기 가능)
$stmt = $conn->prepare("UPDATE users SET spotify_synced = NULL WHERE user_id = ?");
$stmt->bind_param('s', $uid);
$stmt->execute();

$_SESSION['flash'] = $n
    ? "청취 기록을 모두 지웠어요. (기록 {$n}줄 삭제) 새로 올리거나 곡을 재생하면 다시 분석해 드려요."
    : '지울 청취 기록이 없어요.';
header('Location: result.php');
exit;
