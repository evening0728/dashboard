<?php
// 곡 관리: 엑셀에서 만든 CSV 파일로 곡을 한꺼번에 넣습니다.
//
// CSV 형식 (첫 줄은 칸 이름)
//   title,artist,genre,youtube_url
//   밤편지,아이유,K-POP,
//   Dynamite,BTS,K-POP,https://www.youtube.com/watch?v=gdZLi9oWNZg
//
// - 칸 이름은 한글(제목, 가수, 장르, 유튜브)로 써도 됩니다.
// - youtube_url 은 비워 둬도 됩니다. 비우면 재생 버튼이 유튜브 검색으로 연결됩니다.
// - 제목과 가수가 같은 곡이 이미 있으면 새로 넣지 않습니다. (유튜브 주소가 비어 있었다면 주소만 채움)
// - 엑셀의 "CSV (쉼표로 분리)"와 "CSV UTF-8" 둘 다 읽을 수 있습니다.
require 'db.php';
require 'lib.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!is_admin($conn)) {                          // 관리자가 아니면 들어올 수 없음
    $_SESSION['flash'] = '곡 관리는 관리자만 할 수 있어요.';
    header('Location: result.php'); exit;
}

// ---------- 가수 사진 올리기 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'photo') {
    $name = trim($_POST['artist'] ?? '');
    $file = $_FILES['photo'] ?? null;
    $stmt = $conn->prepare("SELECT 1 FROM tracks WHERE artist = ? LIMIT 1");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $info  = ($file && $file['error'] === UPLOAD_ERR_OK) ? @getimagesize($file['tmp_name']) : false;

    if (!$stmt->get_result()->num_rows)          $msg = '곡 목록에 없는 가수예요.';
    elseif (!$file || $file['error'] !== UPLOAD_ERR_OK) $msg = '사진 파일을 골라 주세요.';
    elseif ($file['size'] > 3 * 1024 * 1024)     $msg = '사진은 3MB 이하만 올릴 수 있어요.';
    elseif (!$info || !isset($types[$info['mime']])) $msg = 'JPG, PNG, WEBP 사진만 올릴 수 있어요.';
    else {
        $dir = __DIR__ . '/img/artists';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $old = $conn->prepare("SELECT image FROM artists WHERE name = ?");          // 예전 사진 지우기
        $old->bind_param('s', $name);
        $old->execute();
        if ($row = $old->get_result()->fetch_assoc()) @unlink(__DIR__ . '/' . $row['image']);
        $path = 'img/artists/' . md5($name) . '.' . $types[$info['mime']];          // 파일 이름은 가수 이름으로 만든 코드
        move_uploaded_file($file['tmp_name'], __DIR__ . '/' . $path);
        $save = $conn->prepare("REPLACE INTO artists (name, image) VALUES (?, ?)");
        $save->bind_param('ss', $name, $path);
        $save->execute();
        $msg = "{$name}의 사진을 저장했어요.";
    }
    $_SESSION['flash'] = $msg;
    header('Location: songs.php#photos'); exit;
}

// ---------- 곡 정보 고치기 (제목·가수·장르) ----------
// 표에서 바뀐 칸만 저장합니다. (원래 값은 o_title 등 숨은 칸으로 같이 보냄)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_songs') {
    $upd = $conn->prepare("UPDATE tracks SET title = ?, artist = ?, genre = ?, youtube_url = ? WHERE track_id = ?");
    $changed = 0; $badUrl = 0;
    foreach ((array) ($_POST['id'] ?? []) as $i => $id) {
        $title  = trim((string) ($_POST['title'][$i] ?? ''));
        $artist = trim((string) ($_POST['artist'][$i] ?? ''));
        $genre  = trim((string) ($_POST['genre'][$i] ?? ''));
        $url    = trim((string) ($_POST['url'][$i] ?? ''));
        if ($title === '' || $artist === '' || $genre === '' || mb_strlen($title) > 100 || mb_strlen($artist) > 100 || mb_strlen($genre) > 30) continue;
        if ($title === ($_POST['o_title'][$i] ?? null) && $artist === ($_POST['o_artist'][$i] ?? null)
            && $genre === ($_POST['o_genre'][$i] ?? null) && $url === ($_POST['o_url'][$i] ?? null)) continue;
        // 유튜브 주소: 영상 번호(11자리)만 적어도 되고, 유튜브 주소가 아니면 저장하지 않음
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) $url = 'https://www.youtube.com/watch?v=' . $url;
        if ($url !== '' && !preg_match('~^https://(www\.|m\.|music\.)?(youtube\.com|youtu\.be)/~', $url)) { $url = $_POST['o_url'][$i] ?? ''; $badUrl++; }
        $urlOrNull = $url === '' ? null : $url;
        $id = (int) $id;
        $upd->bind_param('ssssi', $title, $artist, $genre, $urlOrNull, $id);
        $upd->execute();
        $changed++;
    }
    $_SESSION['flash'] = ($changed ? "곡 {$changed}개의 정보를 고쳤어요." : '바뀐 곡이 없어요.')
                       . ($badUrl ? " 유튜브 주소가 아닌 칸 {$badUrl}개는 저장하지 않았어요." : '');
    header('Location: songs.php?' . http_build_query(['q' => $_POST['q'] ?? '', 'only' => $_POST['only'] ?? '']) . '#edit'); exit;
}

// ---------- 가수별로 장르 한꺼번에 정하기 ('기타' 곡만) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'artist_genre') {
    $upd = $conn->prepare("UPDATE tracks SET genre = ? WHERE artist = ? AND genre = '기타'");
    $songs = 0; $people = 0;
    foreach ((array) ($_POST['ag'] ?? []) as $artist => $genre) {
        $genre = trim((string) $genre);
        if ($genre === '' || $genre === '기타' || mb_strlen($genre) > 30) continue;
        $artist = (string) $artist;
        $upd->bind_param('ss', $genre, $artist);
        $upd->execute();
        $songs += $upd->affected_rows; $people++;
    }
    $_SESSION['flash'] = $people ? "가수 {$people}명의 곡 {$songs}개 장르를 정했어요." : '장르를 고른 가수가 없어요.';
    header('Location: songs.php#edit'); exit;
}

// ---------- 중복 곡 합치기 ----------
// 남길 곡(keep) 하나만 두고, 나머지 곡의 청취 기록을 남길 곡으로 옮긴 뒤 지웁니다.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'merge') {
    $merged = 0;
    foreach ((array) ($_POST['group'] ?? []) as $ids) {
        $ids  = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $ids)))));
        if (count($ids) < 2) continue;
        $keep = (int) ($_POST['keep'][implode(',', $ids)] ?? $ids[0]);
        if (!in_array($keep, $ids, true)) $keep = $ids[0];
        $drop = implode(',', array_diff($ids, [$keep]));          // 숫자만 들어 있음
        $conn->begin_transaction();
        $conn->query("UPDATE plays SET track_id = $keep WHERE track_id IN ($drop)");
        // 남길 곡에 유튜브 주소가 없으면 지울 곡의 주소를 가져옴
        $conn->query("UPDATE tracks k JOIN (SELECT youtube_url FROM tracks WHERE track_id IN ($drop) AND youtube_url <> '' LIMIT 1) d
                      SET k.youtube_url = d.youtube_url WHERE k.track_id = $keep AND (k.youtube_url IS NULL OR k.youtube_url = '')");
        $conn->query("DELETE FROM tracks WHERE track_id IN ($drop)");
        $conn->commit();
        $merged += count($ids) - 1;
    }
    $_SESSION['flash'] = $merged ? "중복 곡 {$merged}개를 합쳤어요. (청취 기록은 남긴 곡으로 옮겼어요)" : '합칠 곡이 없어요.';
    header('Location: songs.php#dupes'); exit;
}

// 장르 이름 통일 (kpop, 케이팝 → K-POP 등)
function genre_name($g) {
    $key = mb_strtolower(preg_replace('/[\s\-_]+/u', '', $g), 'UTF-8');
    $map = ['kpop' => 'K-POP', '케이팝' => 'K-POP', 'rnb' => 'R&B', 'r&b' => 'R&B', '알앤비' => 'R&B',
            'hiphop' => '힙합', '랩' => '힙합', 'rap' => '힙합', 'ballad' => '발라드', 'indie' => '인디'];
    return $map[$key] ?? trim($g);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file = $_FILES['csv'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash'] = 'CSV 파일을 골라 주세요.';
        header('Location: songs.php'); exit;
    }
    $text = file_get_contents($file['tmp_name']);
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);                         // BOM 제거
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'CP949');   // 엑셀 기본 CSV(한글 윈도우) 처리

    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $text); rewind($fp);

    // 첫 줄이 칸 이름이면 그 순서를 따르고, 아니면 제목,가수,장르,유튜브 순서로 봅니다.
    $col = ['title' => 0, 'artist' => 1, 'genre' => 2, 'youtube_url' => 3];
    $names = ['title' => ['title', '제목', '곡', '곡제목'], 'artist' => ['artist', '가수', '아티스트'],
              'genre' => ['genre', '장르'], 'youtube_url' => ['youtube_url', 'youtube', 'url', '유튜브', '유튜브주소', '링크']];
    $first = fgetcsv($fp, 0, ',', '"', '');
    $isHeader = false;
    if ($first) {
        foreach ($first as $i => $h) {
            $h = mb_strtolower(trim($h), 'UTF-8');
            foreach ($names as $key => $list) if (in_array($h, $list, true)) { $col[$key] = $i; $isHeader = true; }
        }
        if (!$isHeader) rewind($fp);
    }

    $find   = $conn->prepare("SELECT track_id, youtube_url FROM tracks WHERE title = ? AND artist = ?");
    $insert = $conn->prepare("INSERT INTO tracks (title, artist, genre, youtube_url) VALUES (?, ?, ?, ?)");
    $setUrl = $conn->prepare("UPDATE tracks SET youtube_url = ? WHERE track_id = ?");
    $added = 0; $same = 0; $urls = 0; $bad = [];
    $line = $isHeader ? 1 : 0;

    while (($row = fgetcsv($fp, 0, ',', '"', '')) !== false) {
        $line++;
        if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) continue;   // 빈 줄
        $title  = trim($row[$col['title']] ?? '');
        $artist = trim($row[$col['artist']] ?? '');
        $genre  = genre_name($row[$col['genre']] ?? '');
        $url    = trim($row[$col['youtube_url']] ?? '');
        if ($title === '' || $artist === '' || $genre === '' || mb_strlen($title) > 100 || mb_strlen($artist) > 100 || mb_strlen($genre) > 30) {
            $bad[] = $line; continue;
        }
        if ($url !== '' && !preg_match('~^https://(www\.|m\.|music\.)?(youtube\.com|youtu\.be)/~', $url)) $url = '';   // 유튜브 주소가 아니면 비움
        $urlOrNull = $url === '' ? null : $url;

        $find->bind_param('ss', $title, $artist);
        $find->execute();
        $old = $find->get_result()->fetch_assoc();
        if ($old) {
            $same++;
            if ($urlOrNull && empty($old['youtube_url'])) { $setUrl->bind_param('si', $urlOrNull, $old['track_id']); $setUrl->execute(); $urls++; }
            continue;
        }
        $insert->bind_param('ssss', $title, $artist, $genre, $urlOrNull);
        $insert->execute();
        $added++;
    }

    $msg = "새 곡 {$added}개를 넣었어요.";
    if ($same) $msg .= " 이미 있는 곡 {$same}개는 건너뛰었어요" . ($urls ? " (유튜브 주소 {$urls}개 추가)" : '') . '.';
    if ($bad)  $msg .= ' 제목·가수·장르가 비어 있거나 너무 긴 줄 ' . count($bad) . '개도 건너뛰었어요. (' . implode(', ', array_slice($bad, 0, 5)) . '번째 줄' . (count($bad) > 5 ? ' 등' : '') . ')';
    $_SESSION['flash'] = $msg;
    header('Location: songs.php'); exit;
}

// 장르별 곡 수
$counts = $conn->query("SELECT genre, COUNT(*) AS n, SUM(youtube_url IS NOT NULL AND youtube_url <> '') AS links
                        FROM tracks GROUP BY genre ORDER BY n DESC")->fetch_all(MYSQLI_ASSOC);
$total = array_sum(array_column($counts, 'n'));

// 가수 목록 (곡이 많은 가수부터) + 사진 여부
$artists = $conn->query("SELECT t.artist, COUNT(*) AS n, a.image IS NOT NULL AS has_photo
                         FROM tracks t LEFT JOIN artists a ON a.name = t.artist
                         GROUP BY t.artist, a.image ORDER BY has_photo, n DESC, t.artist")->fetch_all(MYSQLI_ASSOC);
$withPhoto = count(array_filter($artists, fn($r) => $r['has_photo']));

// ---------- 곡 정보 고치기 화면에 쓸 데이터 ----------
$genreList = array_values(array_unique(array_merge(['발라드', '인디', 'R&B', '힙합', 'K-POP'], array_column($counts, 'genre'), ['기타'])));
$q    = trim($_GET['q'] ?? '');
$etcN = (int) $conn->query("SELECT COUNT(*) FROM tracks WHERE genre = '기타'")->fetch_row()[0];
$only = $_GET['only'] ?? ($q === '' && $etcN ? 'etc' : '');            // 기본: '기타' 곡이 있으면 그것만
$sql  = "SELECT track_id, title, artist, genre, youtube_url FROM tracks WHERE 1";
$par  = [];
if ($only === 'etc')   $sql .= " AND genre = '기타'";
if ($only === 'nourl') $sql .= " AND (youtube_url IS NULL OR youtube_url = '')";
$noUrlN = (int) $conn->query("SELECT COUNT(*) FROM tracks WHERE youtube_url IS NULL OR youtube_url = ''")->fetch_row()[0];
if ($q !== '')       { $sql .= " AND (title LIKE ? OR artist LIKE ?)"; $par = ["%$q%", "%$q%"]; }
$stmt = $conn->prepare($sql . " ORDER BY artist, title LIMIT 200");
if ($par) $stmt->bind_param('ss', ...$par);
$stmt->execute();
$editRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
// '기타' 곡이 있는 가수 (곡 많은 순)
$etcArtists = $conn->query("SELECT artist, COUNT(*) AS n FROM tracks WHERE genre = '기타' GROUP BY artist ORDER BY n DESC, artist LIMIT 100")->fetch_all(MYSQLI_ASSOC);

// ---------- 중복 곡 찾기 ----------
// 1) 유튜브 영상이 같은 곡   2) 띄어쓰기·대소문자·기호를 빼고 제목과 가수가 같은 곡
$dupes = [];
$all = $conn->query("SELECT t.track_id, t.title, t.artist, t.genre, t.youtube_url, COALESCE(SUM(p.play_count), 0) AS plays
                     FROM tracks t LEFT JOIN plays p ON p.track_id = t.track_id
                     GROUP BY t.track_id, t.title, t.artist, t.genre, t.youtube_url ORDER BY t.track_id")->fetch_all(MYSQLI_ASSOC);
$key = fn($s) => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $s, 'UTF-8'));
$byVideo = []; $byName = [];
foreach ($all as $t) {
    if ($vid = youtube_id($t['youtube_url'] ?? '')) $byVideo[$vid][] = $t;
    $byName[$key($t['title']) . '|' . $key($t['artist'])][] = $t;
}
$seen = [];
foreach (array_merge(array_values($byVideo), array_values($byName)) as $g) {
    if (count($g) < 2) continue;
    $ids = implode(',', array_column($g, 'track_id'));
    if (isset($seen[$ids])) continue;
    $seen[$ids] = true;
    $dupes[] = $g;
}

?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>곡 관리 - 음악 추천 대시보드</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app">
  <div class="topbar">
    <span class="who">곡 관리</span>
    <a class="btn" href="result.php">내 분석</a>
    <a class="btn" href="logout.php">로그아웃</a>
  </div>
  <?php flash(); ?>

  <section>
    <h2 class="sec-title">장르별 곡 수 (전체 <?= $total ?>곡)</h2>
    <div class="panel">
      <table class="count-table">
        <tr><th>장르</th><th>곡 수</th><th>유튜브 주소 있음</th></tr>
        <?php foreach ($counts as $c): ?>
          <tr><td><?= h($c['genre']) ?></td><td><?= (int) $c['n'] ?>곡</td><td><?= (int) $c['links'] ?>곡</td></tr>
        <?php endforeach; ?>
      </table>
    </div>
  </section>

  <section>
    <h2 class="sec-title">CSV 파일로 곡 넣기</h2>
    <form class="panel upload-form" method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="csv">
      <p class="small muted">엑셀에 아래처럼 적고 <b>다른 이름으로 저장 → CSV</b>로 저장한 뒤 올리세요.</p>
      <table class="count-table">
        <tr><th>title</th><th>artist</th><th>genre</th><th>youtube_url</th></tr>
        <tr><td>밤편지</td><td>아이유</td><td>K-POP</td><td class="muted">(비워도 됨)</td></tr>
        <tr><td>Dynamite</td><td>BTS</td><td>K-POP</td><td>https://www.youtube.com/watch?v=…</td></tr>
      </table>
      <ul class="small muted">
        <li>장르는 발라드, 인디, R&amp;B, 힙합, K-POP 중 하나로 쓰면 추천 플레이리스트 이름이 맞게 나옵니다.</li>
        <li>제목과 가수가 같은 곡이 이미 있으면 건너뜁니다. 같은 파일을 두 번 올려도 곡이 두 번 들어가지 않습니다.</li>
        <li>유튜브 주소를 비우면 재생 버튼이 "가수 + 제목" 유튜브 검색으로 연결됩니다.</li>
      </ul>
      <input type="file" name="csv" accept=".csv,text/csv" required>
      <div class="row">
        <button class="btn primary" type="submit">CSV 올리기</button>
        <a class="btn" href="songs_template.csv" download>예시 CSV 받기</a>
      </div>
    </form>
  </section>

  <section id="edit">
    <h2 class="sec-title">곡 정보 고치기 <small class="muted">장르 '기타' <?= $etcN ?>곡 · 유튜브 주소 없음 <?= $noUrlN ?>곡</small></h2>

    <?php if ($etcArtists): ?>
    <form class="panel" method="post" style="margin-bottom:12px">
      <input type="hidden" name="action" value="artist_genre">
      <p class="small muted">기록을 올릴 때 새로 추가된 곡 중 장르를 모르는 곡은 '기타'로 들어가요. 가수별로 장르를 고르면 그 가수의 '기타' 곡이 한꺼번에 바뀌어요.</p>
      <table class="count-table edit-table">
        <tr><th>가수</th><th>'기타' 곡</th><th>장르 정하기</th></tr>
        <?php foreach ($etcArtists as $r): ?>
          <tr><td><?= h($r['artist']) ?></td><td><?= (int) $r['n'] ?>곡</td>
              <td><select name="ag[<?= h($r['artist']) ?>]"><option value="">그대로</option>
                  <?php foreach ($genreList as $g) if ($g !== '기타'): ?><option><?= h($g) ?></option><?php endif; ?></select></td></tr>
        <?php endforeach; ?>
      </table>
      <div class="row" style="margin-top:10px"><button class="btn primary" type="submit">가수별 장르 저장</button></div>
    </form>
    <?php endif; ?>

    <div class="panel">
      <form class="row edit-search" method="get" action="songs.php#edit">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="제목이나 가수로 찾기">
        <select name="only">
          <option value="all">모든 곡</option>
          <option value="etc" <?= $only === 'etc' ? 'selected' : '' ?>>장르가 '기타'인 곡 (<?= $etcN ?>)</option>
          <option value="nourl" <?= $only === 'nourl' ? 'selected' : '' ?>>유튜브 주소 없는 곡 (<?= $noUrlN ?>)</option>
        </select>
        <button class="btn" type="submit">찾기</button>
      </form>
      <?php if (!$editRows): ?>
        <p class="muted small" style="margin-top:10px">찾는 곡이 없어요. 위에서 제목이나 가수로 찾아보세요.</p>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="edit_songs">
        <input type="hidden" name="q" value="<?= h($q) ?>">
        <input type="hidden" name="only" value="<?= h($only) ?>">
        <p class="small muted" style="margin:10px 0 6px">칸을 고친 뒤 아래 저장 버튼을 누르세요. 바뀐 곡만 저장돼요. 유튜브 주소는 영상 번호(11자리)만 붙여 넣어도 돼요. (최대 200곡까지 보여요)</p>
        <div class="edit-scroll">
        <table class="count-table edit-table">
          <tr><th>제목</th><th>가수</th><th>장르</th><th>유튜브 주소</th></tr>
          <?php foreach ($editRows as $t): ?>
            <tr<?= $t['genre'] === '기타' ? ' class="etc"' : '' ?>>
              <td><input type="hidden" name="id[]" value="<?= (int) $t['track_id'] ?>">
                  <input type="hidden" name="o_title[]" value="<?= h($t['title']) ?>"><input type="hidden" name="o_artist[]" value="<?= h($t['artist']) ?>"><input type="hidden" name="o_genre[]" value="<?= h($t['genre']) ?>"><input type="hidden" name="o_url[]" value="<?= h($t['youtube_url'] ?? '') ?>">
                  <input name="title[]" value="<?= h($t['title']) ?>" maxlength="100"></td>
              <td><input name="artist[]" value="<?= h($t['artist']) ?>" maxlength="100"></td>
              <td><select name="genre[]"><?php foreach ($genreList as $g): ?><option<?= $g === $t['genre'] ? ' selected' : '' ?>><?= h($g) ?></option><?php endforeach; ?></select></td>
              <td><input name="url[]" value="<?= h($t['youtube_url'] ?? '') ?>" placeholder="https://www.youtube.com/watch?v=…" maxlength="255"></td>
            </tr>
          <?php endforeach; ?>
        </table>
        </div>
        <div class="row" style="margin-top:10px"><button class="btn primary" type="submit">고친 내용 저장</button></div>
      </form>
      <?php endif; ?>
    </div>
  </section>

  <section id="dupes">
    <h2 class="sec-title">중복 곡 합치기 <small class="muted"><?= count($dupes) ?>묶음</small></h2>
    <div class="panel">
      <?php if (!$dupes): ?>
        <p class="muted small">중복된 곡이 없어요.</p>
      <?php else: ?>
      <form method="post" onsubmit="return confirm('고른 곡만 남기고 나머지는 지울까요?\n청취 기록은 남기는 곡으로 옮겨져요.');">
        <input type="hidden" name="action" value="merge">
        <p class="small muted">유튜브 영상이 같거나, 제목과 가수가 같은 곡이에요. 남길 곡을 고르면 나머지 곡의 청취 기록을 그 곡으로 옮기고 지워요.</p>
        <?php foreach ($dupes as $g): $ids = implode(',', array_column($g, 'track_id')); ?>
          <fieldset class="dupe">
            <input type="hidden" name="group[]" value="<?= h($ids) ?>">
            <?php foreach ($g as $i => $t): ?>
              <label><input type="radio" name="keep[<?= h($ids) ?>]" value="<?= (int) $t['track_id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
                <b><?= h($t['title']) ?></b> · <?= h($t['artist']) ?> <span class="muted small">(<?= h($t['genre']) ?>, 청취 <?= (int) $t['plays'] ?>회)</span></label>
            <?php endforeach; ?>
          </fieldset>
        <?php endforeach; ?>
        <div class="row" style="margin-top:10px"><button class="btn primary" type="submit">모두 합치기</button></div>
      </form>
      <?php endif; ?>
    </div>
  </section>

  <section id="photos">
    <h2 class="sec-title">가수 사진 (<?= count($artists) ?>명 중 <?= $withPhoto ?>명 등록)</h2>
    <form class="panel upload-form" method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="photo">
      <p class="small muted">사진은 공유하기 팜플렛과 공유 화면에 동그랗게 잘려서 나옵니다. 정사각형에 가까운 사진이 잘 어울려요. (JPG, PNG, WEBP, 3MB 이하)</p>
      <label class="field">가수
        <select name="artist" required>
          <?php foreach ($artists as $r): ?>
            <option value="<?= h($r['artist']) ?>"><?= h($r['artist']) ?><?= $r['has_photo'] ? ' (사진 있음)' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
      <button class="btn primary" type="submit">사진 올리기</button>
    </form>
    <div class="panel artist-grid" style="margin-top:12px">
      <?php foreach ($artists as $r): ?>
        <figure><?= artist_avatar($conn, $r['artist'], 'sm') ?><figcaption><?= h($r['artist']) ?></figcaption></figure>
      <?php endforeach; ?>
    </div>
  </section>
</div>
</body>
</html>
