<?php
// 좋아하는 곡 고르기 저장: add.php 에서 고른 곡을 "3번 들은 기록"으로 plays 테이블에 넣습니다.
//   - picks[]  : 목록에서 고른 곡 번호
//   - c_title[], c_artist[], c_genre[] : 목록에 없어서 직접 적은 곡 → tracks 에 새로 추가한 뒤 기록
require 'db.php';
require 'lib.php';
require 'import_lib.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$uid  = $_SESSION['user_id'];
$back = safe_back($_POST['back'] ?? 'result.php');

const PICK_PLAYS = 3;     // 고른 곡 하나를 몇 번 들은 것으로 볼지
const PICK_MAX   = 50;    // 한 번에 고를 수 있는 곡 수

function done($msg, $to) { $_SESSION['flash'] = $msg; header('Location: ' . $to); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(form_token(), (string) ($_POST['token'] ?? ''))) {
    done('화면을 새로 고친 뒤 다시 골라 주세요.', 'add.php');
}

// 1) 목록에서 고른 곡: 실제로 있는 곡 번호만 남깁니다.
$ids = array_slice(array_unique(array_filter(array_map('intval', (array) ($_POST['picks'] ?? [])))), 0, PICK_MAX);
if ($ids) {
    $in  = implode(',', $ids);                                   // 숫자만 들어 있으므로 안전
    $ids = array_column($conn->query("SELECT track_id FROM tracks WHERE track_id IN ($in)")->fetch_all(), 0);
}

// 2) 직접 적은 곡: 이미 있는 곡이면 그 곡으로, 없으면 새로 추가합니다.
$tracks = load_tracks($conn);
$genreOf = artist_genres($tracks);
$known  = array_column($conn->query("SELECT DISTINCT genre FROM tracks")->fetch_all(), 0);
$titles = (array) ($_POST['c_title'] ?? []);
$added  = 0;
foreach ($titles as $i => $title) {
    $title  = trim((string) $title);
    $artist = trim((string) ($_POST['c_artist'][$i] ?? ''));
    $genre  = trim((string) ($_POST['c_genre'][$i] ?? ''));
    if ($title === '' || count($ids) >= PICK_MAX) continue;
    if ($artist === '') $artist = '알 수 없음';
    if (!in_array($genre, $known, true)) $genre = '';            // 목록에 있는 장르만 허용
    $t = find_track($tracks, $title, $artist === '알 수 없음' ? '' : $artist, '');
    if (!$t) { $t = add_track($conn, $tracks, $genreOf, $title, $artist, null, $genre); $added++; }
    $ids[] = (int) $t['track_id'];
}
$ids = array_values(array_unique($ids));
if (!$ids) done('곡을 1곡 이상 골라 주세요.', 'add.php?back=' . urlencode($back));

// 3) 오늘 날짜로 3번씩 들은 기록 저장
$today = date('Y-m-d');
$n     = PICK_PLAYS;
$add   = $conn->prepare("INSERT INTO plays (user_id, track_id, played_on, play_count) VALUES (?, ?, ?, ?)");
foreach ($ids as $tid) {
    $add->bind_param('sisi', $uid, $tid, $today, $n);
    $add->execute();
}
done(count($ids) . '곡을 청취 기록에 저장했어요.' . ($added ? " (새 곡 {$added}개는 곡 목록에 추가했어요)" : '')
     . (count($ids) < 5 ? ' 5곡 이상 고르면 추천이 더 정확해져요.' : ''), $back);
