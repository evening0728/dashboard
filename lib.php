<?php
// 여러 화면이 함께 쓰는 분석 함수 모음

// 한 사용자의 청취 기록을 분석해서 돌려줍니다.
//   plays : 들은 횟수 (화면에 보여 주는 숫자)
//   w     : 최근일수록 크게 센 횟수 (추천 점수에 사용)
//           30일 전 기록은 절반, 60일 전은 1/4 … 단, 아무리 오래돼도 0.2 아래로는 내려가지 않음
function analyze($conn, $uid) {
    // 모든 곡 + 이 사용자가 들은 횟수 (안 들은 곡은 0)
    $stmt = $conn->prepare(
        "SELECT t.track_id, t.title, t.artist, t.genre, t.youtube_url,
                COALESCE(SUM(p.play_count), 0) AS plays,
                COALESCE(SUM(p.play_count * GREATEST(0.2, POW(0.5, GREATEST(DATEDIFF(CURDATE(), p.played_on), 0) / 30))), 0) AS w
         FROM tracks t LEFT JOIN plays p ON p.track_id = t.track_id AND p.user_id = ?
         GROUP BY t.track_id, t.title, t.artist, t.genre, t.youtube_url
         ORDER BY t.track_id");
    $stmt->bind_param('s', $uid);
    $stmt->execute();
    $tracks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $a = ['uid' => $uid, 'tracks' => $tracks, 'total' => 0, 'songs' => 0, 'genres' => [], 'artists' => [],
          'gw' => [], 'aw' => []];
    foreach ($tracks as $t) {
        $n = (int) $t['plays'];
        if ($n == 0) continue;
        $w = (float) $t['w'];
        $a['total'] += $n;
        $a['songs']++;
        $a['genres'][$t['genre']]   = ($a['genres'][$t['genre']] ?? 0) + $n;
        $a['artists'][$t['artist']] = ($a['artists'][$t['artist']] ?? 0) + $n;
        $a['gw'][$t['genre']]       = ($a['gw'][$t['genre']] ?? 0) + $w;
        $a['aw'][$t['artist']]      = ($a['aw'][$t['artist']] ?? 0) + $w;
    }
    arsort($a['genres']);    // 많이 들은 장르 순서
    arsort($a['artists']);
    return $a;
}

// 장르 비율(%)
function share($a, $genre) {
    return $a['total'] ? round(($a['genres'][$genre] ?? 0) / $a['total'] * 100) : 0;
}

// =====================================================================
// 추천 곡 (하이브리드 추천)
//   ① 취향 점수   : 내가 많이 들은 장르(70%)·가수(30%)와 얼마나 맞는지 (최근 기록일수록 크게 셈)
//   ② 함께 들은 곡 : 내가 들은 곡을 들은 다른 사람들이 함께 들은 곡 (협업 필터링)
//   ③ 다양성       : 한 가수의 곡은 최대 2곡까지만
//   ④ 추천 이유    : 곡마다 왜 추천했는지 문장으로 붙임 ($t['why'])
// =====================================================================

// ② 함께 들은 곡 점수
//   돌려주는 값: ['peers' => 나와 겹치는 곡이 있는 사람 수,
//                'scores' => [track_id => ['score' => 0~1, 'from' => 기준 곡 제목, 'pct' => %, 'n' => 기준 곡을 들은 사람 수]]]
function collab_scores($conn, $a) {
    $mine = [];                                       // 내가 들은 곡 → 최근 가중치
    foreach ($a['tracks'] as $t) if ($t['plays'] > 0) $mine[$t['track_id']] = (float) $t['w'];
    if (!$mine) return ['peers' => 0, 'scores' => []];
    $titles = array_column($a['tracks'], 'title', 'track_id');

    // 다른 사람들이 들은 곡 목록 (사람별)
    $stmt = $conn->prepare("SELECT user_id, track_id FROM plays WHERE user_id <> ? GROUP BY user_id, track_id");
    $stmt->bind_param('s', $a['uid']);
    $stmt->execute();
    $others = [];
    foreach ($stmt->get_result() as $r) $others[$r['user_id']][] = (int) $r['track_id'];

    $heardBy = [];    // 내가 들은 곡 s 를 들은 다른 사람 수
    $co      = [];    // [t][s] = s 와 t 를 둘 다 들은 사람 수
    $peers   = 0;
    foreach ($others as $list) {
        $shared = array_values(array_filter($list, fn($id) => isset($mine[$id])));
        if (!$shared) continue;                       // 나와 겹치는 곡이 없는 사람은 건너뜀
        $peers++;
        foreach ($shared as $s) $heardBy[$s] = ($heardBy[$s] ?? 0) + 1;
        foreach ($list as $t) {
            if (isset($mine[$t])) continue;           // 이미 들은 곡은 추천하지 않음
            foreach ($shared as $s) $co[$t][$s] = ($co[$t][$s] ?? 0) + 1;
        }
    }
    $total = array_sum($mine);
    $out = [];
    foreach ($co as $t => $bySong) {
        $score = 0; $best = null;
        foreach ($bySong as $s => $both) {
            $p = $both / $heardBy[$s];                // s 를 들은 사람 중 t 도 들은 비율
            $score += ($mine[$s] / $total) * $p;
            if ($heardBy[$s] >= 2 && (!$best || $p > $best['p'] || ($p == $best['p'] && $heardBy[$s] > $best['n']))) {
                $best = ['p' => $p, 's' => $s, 'n' => $heardBy[$s]];
            }
        }
        $out[$t] = ['score' => $score,
                    'from'  => $best ? $titles[$best['s']] : '',
                    'pct'   => $best ? (int) round($best['p'] * 100) : 0,
                    'n'     => $best['n'] ?? 0];
    }
    return ['peers' => $peers, 'scores' => $out];
}

function recommend_songs($a, $count, $conn = null) {
    $res   = $conn ? collab_scores($conn, $a) : ['peers' => 0, 'scores' => []];
    $cf    = $res['scores'];
    $maxCf = $cf ? max(array_column($cf, 'score')) : 0;
    $maxG  = $a['gw'] ? max($a['gw']) : 0;
    $maxA  = $a['aw'] ? max($a['aw']) : 0;
    $topArtists = array_slice(array_keys($a['artists']), 0, 5);
    // 함께 들은 곡 점수를 얼마나 믿을지: 나와 겹치는 곡이 있는 사람이 많을수록 크게
    //   (1명 25%, 3명 35%, 5명 이상 45%)
    $mix   = ($cf && $maxCf > 0) ? min(0.45, 0.2 + 0.05 * $res['peers']) : 0;

    $list = [];
    foreach ($a['tracks'] as $t) {
        if ($t['plays'] > 0) continue;                             // 아직 안 들은 곡만
        $g = $maxG ? ($a['gw'][$t['genre']] ?? 0) / $maxG : 0;
        if ($t['genre'] === '기타') $g *= 0.3;                     // '기타'는 진짜 장르가 아니므로 약하게
        $ar = $maxA ? ($a['aw'][$t['artist']] ?? 0) / $maxA : 0;
        $taste = 0.7 * $g + 0.3 * $ar;                             // ① 취향 점수
        $c = isset($cf[$t['track_id']]) && $maxCf > 0 ? $cf[$t['track_id']]['score'] / $maxCf : 0;   // ② 함께 들은 곡
        $t['score'] = (1 - $mix) * $taste + $mix * $c;
        $t['tie']   = crc32($a['uid'] . ':' . $t['track_id']);    // 점수가 같으면 사람마다 다른 순서
        $t['why']   = recommend_reason($a, $t, $cf[$t['track_id']] ?? null, $topArtists);
        $list[] = $t;
    }
    usort($list, fn($x, $y) => $y['score'] <=> $x['score'] ?: $x['tie'] <=> $y['tie']);

    // ③ 다양성: 한 가수당 최대 2곡
    $out = []; $per = [];
    foreach ($list as $t) {
        if (($per[$t['artist']] ?? 0) >= 2) continue;
        $per[$t['artist']] = ($per[$t['artist']] ?? 0) + 1;
        $out[] = $t;
        if (count($out) >= $count) break;
    }
    return $out;
}

// ④ 추천 이유 문장 (가장 큰 이유 하나)
function recommend_reason($a, $t, $cf, $topArtists) {
    if (!$a['total']) return '';
    $together = function () use ($cf) {
        $m = (int) round($cf['pct'] / 100 * $cf['n']);
        return "'{$cf['from']}'을(를) 들은 {$cf['n']}명 중 {$m}명이 이 곡도 들었어요";
    };
    $strong = $cf && $cf['from'] !== '' && $cf['n'] >= 2;
    if ($strong && $cf['pct'] >= 60) return $together();                         // 함께 들은 비율이 높으면
    if (in_array($t['artist'], $topArtists, true)) return "자주 듣는 {$t['artist']}의 다른 곡이에요";
    if ($strong && $cf['pct'] >= 30) return $together();
    if ($cf && $cf['score'] > 0) return '나와 취향이 비슷한 사람들이 들은 곡이에요';
    if ($t['genre'] !== '기타' && isset($a['genres'][$t['genre']])) return $t['genre'] . ' 장르를 ' . share($a, $t['genre']) . '% 들어서 추천했어요';
    return '새로운 곡을 들어 보세요';
}

// 추천 아티스트: 취향 점수(장르 70% + 가수 30%)가 가장 높은 가수
function recommend_artist($a) {
    if (!$a['total']) return null;
    $maxGenre  = max($a['gw']);              // 최근 기록일수록 크게 센 횟수로 계산
    $maxArtist = max($a['aw']);
    $best = null;
    $seen = [];
    foreach ($a['tracks'] as $t) {
        if (isset($seen[$t['artist']])) continue;
        $seen[$t['artist']] = true;
        $score = round(100 * (0.7 * ($a['gw'][$t['genre']] ?? 0) / $maxGenre
                            + 0.3 * ($a['aw'][$t['artist']] ?? 0) / $maxArtist));
        if (!$best || $score > $best['score']) {
            $best = ['artist' => $t['artist'], 'genre' => $t['genre'], 'score' => $score];
        }
    }
    $best['titles'] = [];
    foreach ($a['tracks'] as $t) {
        if ($t['artist'] === $best['artist'] && count($best['titles']) < 3) $best['titles'][] = $t['title'];
    }
    return $best;
}

// 취향 궁합(%): 두 사람의 장르별 청취 횟수가 얼마나 비슷한지 (코사인 유사도)
function compatibility($a, $b) {
    $dot = 0; $na = 0; $nb = 0;
    foreach (array_unique(array_merge(array_keys($a['genres']), array_keys($b['genres']))) as $g) {
        $x = $a['genres'][$g] ?? 0; $y = $b['genres'][$g] ?? 0;
        $dot += $x * $y; $na += $x * $x; $nb += $y * $y;
    }
    return ($na && $nb) ? (int) round(100 * $dot / sqrt($na * $nb)) : 0;
}

// 둘이 함께 들으면 좋은 곡: 두 사람이 모두 많이 듣는 장르의 곡
function together_songs($owner, $viewer, $count) {
    $list = $viewer['tracks'];
    $ownerPlays = array_column($owner['tracks'], 'plays', 'track_id');
    $score = function ($t) use ($owner, $viewer, $ownerPlays) {
        $common = min(share($owner, $t['genre']), share($viewer, $t['genre']));
        return $common * 10 + ($t['plays'] == 0 ? 5 : 0) + (($ownerPlays[$t['track_id']] ?? 0) > 0 ? 3 : 0);
    };
    usort($list, fn($x, $y) => $score($y) <=> $score($x) ?: $x['track_id'] <=> $y['track_id']);
    return array_slice($list, 0, $count);
}

// 이 사용자의 공유 코드 (없으면 새로 만듭니다)
function share_code($conn, $uid) {
    $stmt = $conn->prepare("SELECT code FROM shares WHERE user_id = ?");
    $stmt->bind_param('s', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) return $row['code'];
    $code = bin2hex(random_bytes(4));     // 예: a7k2x9b1 같은 8글자
    $stmt = $conn->prepare("INSERT INTO shares (code, user_id, created_at) VALUES (?, ?, NOW())");
    $stmt->bind_param('ss', $code, $uid);
    $stmt->execute();
    return $code;
}

// 곡의 유튜브 주소: DB에 넣어 둔 주소가 있으면 그 주소, 없으면 "가수 + 제목" 유튜브 검색 결과
function youtube_link($t) {
    $url = trim((string) ($t['youtube_url'] ?? ''));
    if (preg_match('~^https://(www\.|m\.|music\.)?(youtube\.com|youtu\.be)/~', $url)) return $url;
    return 'https://www.youtube.com/results?search_query=' . urlencode($t['artist'] . ' ' . $t['title']);
}

// 곡 카드 한 개 (재생 버튼 포함)
// 재생 버튼을 누르면 play.php 가 청취 기록을 1회 남기고 새 탭에서 유튜브를 엽니다.
// 원래 화면은 잠시 뒤 새로고침되어 늘어난 청취 기록이 반영됩니다.
function song_card($t, $back, $rank = null, $why = '') { ?>
    <form class="mini <?= $why && $rank == 1 ? 'first' : '' ?>" method="post" action="play.php" target="_blank"
          onsubmit="setTimeout(function () { location.reload(); }, 800)">
      <div class="cover">♪</div>
      <div class="meta">
        <div class="t"><?= $rank ? $rank . '. ' : '' ?><?= h($t['title']) ?></div>
        <div class="small muted"><?= h($t['artist']) ?> · <?= h($t['genre']) ?></div>
        <?php if ($why): ?><div class="small why"><?= h($why) ?></div><?php endif; ?>
      </div>
      <input type="hidden" name="track_id" value="<?= (int) $t['track_id'] ?>">
      <input type="hidden" name="back" value="<?= h($back) ?>">
      <button class="play" type="submit" aria-label="<?= h($t['title']) ?> 유튜브에서 재생" title="유튜브에서 재생">▶</button>
    </form>
<?php }

// 로그인 뒤 돌아갈 주소가 안전한지 확인 (우리 사이트의 공유 화면만 허용)
function safe_back($url) {
    return preg_match('/^(result\.php|share\.php\?code=[0-9a-f]{8})$/', (string) $url) ? $url : 'result.php';
}

// 청취 기록이 없을 때 보여 주는 안내 카드 (곡 고르기 / 파일 올리기 / 스포티파이)
function upload_card($name, $text, $back) {
    $add = 'add.php?back=' . urlencode($back); ?>
    <div class="empty">
      <div class="q" aria-hidden="true">?</div>
      <h3>아직 <?= h($name) ?>님의 청취 기록이 없어요</h3>
      <p class="muted small"><?= h($text) ?></p>
      <div class="empty-actions">
        <a class="btn primary" href="<?= h($add) ?>#pick">좋아하는 곡 골라서 바로 시작</a>
        <a class="btn" href="<?= h($add) ?>#file">기록 파일 올리기</a>
        <?php if (function_exists('spotify_ready') && spotify_ready()): ?>
          <a class="btn sp" href="spotify_login.php">스포티파이 연결</a>
        <?php endif; ?>
      </div>
      <p class="muted small">유튜브·유튜브 뮤직(구글 테이크아웃), 스포티파이, 애플 뮤직 기록 파일과 엑셀(CSV) 목록을 올릴 수 있어요.</p>
    </div>
<?php }

// 폼 확인 번호: 다른 사이트가 몰래 "초기화" 요청을 보내지 못하도록, 우리 화면에서 받은 번호만 인정합니다.
function form_token() {
    if (empty($_SESSION['token'])) $_SESSION['token'] = bin2hex(random_bytes(16));
    return $_SESSION['token'];
}

// 한 번만 보여 주는 알림 문구
function flash() {
    if (empty($_SESSION['flash'])) return;
    echo '<p class="flash">' . h($_SESSION['flash']) . '</p>';
    unset($_SESSION['flash']);
}


// 지금 로그인한 사람이 관리자인지 (세션이 아니라 DB를 보고 판단)
function is_admin($conn) {
    if (!isset($_SESSION['user_id'])) return false;
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE user_id = ?");
    $stmt->bind_param('s', $_SESSION['user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row && (int) $row['is_admin'] === 1;
}

// 가수 사진 경로 (없으면 null). 사진을 바꿔도 바로 보이도록 뒤에 수정 시각을 붙입니다.
function artist_image($conn, $name) {
    $stmt = $conn->prepare("SELECT image FROM artists WHERE name = ?");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || !is_file(__DIR__ . '/' . $row['image'])) return null;
    return $row['image'] . '?v=' . filemtime(__DIR__ . '/' . $row['image']);
}

// 가수 사진 또는 첫 글자 동그라미
function artist_avatar($conn, $name, $size = 'xl') {
    $img = artist_image($conn, $name);
    if ($img) return '<img class="avatar ' . $size . ' photo" src="' . h($img) . '" alt="' . h($name) . ' 사진">';
    return '<div class="avatar ' . $size . '" aria-hidden="true">' . h(mb_substr($name, 0, 1)) . '</div>';
}

// 플레이리스트 곡 목록: 그 장르에서 내가 많이 들은 곡 4개 + 아직 안 들은 곡(자주 듣는 가수의 곡부터)
function playlist_songs($a, $genre, $count) {
    $list  = array_values(array_filter($a['tracks'], fn($t) => $t['genre'] === $genre));
    $heard = array_values(array_filter($list, fn($t) => $t['plays'] > 0));
    $new   = array_values(array_filter($list, fn($t) => $t['plays'] == 0));
    usort($heard, fn($x, $y) => $y['plays'] <=> $x['plays']);
    usort($new, fn($x, $y) => ($a['artists'][$y['artist']] ?? 0) <=> ($a['artists'][$x['artist']] ?? 0) ?: $x['track_id'] <=> $y['track_id']);
    $pick = array_slice($heard, 0, 4);
    return array_slice(array_merge($pick, $new, array_slice($heard, 4)), 0, $count);
}

// 유튜브 주소에서 영상 ID(11글자)만 꺼냅니다. 영상 주소가 아니면(검색 주소 등) 빈 글자를 돌려줍니다.
//   https://www.youtube.com/watch?v=ABCDEFGHIJK, https://youtu.be/ABCDEFGHIJK 모두 → ABCDEFGHIJK
function youtube_id($url) {
    return preg_match('~(?:youtu\.be/|[?&]v=|/embed/|/shorts/)([A-Za-z0-9_-]{11})~', (string) $url, $m) ? $m[1] : '';
}

// 플레이리스트 [전체 재생] 주소
//   영상 주소가 있는 곡만 골라 유튜브 임시 재생목록(watch_videos)으로 묶습니다. → 유튜브에서 차례대로 이어서 재생
//   주소가 없는 곡이나 같은 영상이 겹치는 곡은 건너뛰고, 다음 곡으로 채워서 $max곡(기본 30곡)을 맞춥니다.
function play_all_link($songs, $max = 30) {
    $ids = array_values(array_unique(array_filter(array_map(fn($t) => youtube_id($t['youtube_url'] ?? ''), $songs))));
    $ids = array_slice($ids, 0, $max);
    return [
        'url'   => $ids ? 'https://www.youtube.com/watch_videos?video_ids=' . implode(',', $ids) : '',
        'count' => count($ids),
    ];
}

// 플레이리스트 안의 곡 한 줄 (재생 버튼 포함)
function song_row($t, $no) { ?>
    <form class="song-row" method="post" action="play.php" target="_blank"
          onsubmit="setTimeout(function () { location.reload(); }, 800)">
      <span class="no"><?= $no ?></span>
      <span class="t"><?= h($t['title']) ?> <span class="muted">· <?= h($t['artist']) ?></span></span>
      <?php if ((int) $t['plays'] === 0): ?><span class="new">새 곡</span><?php endif; ?>
      <input type="hidden" name="track_id" value="<?= (int) $t['track_id'] ?>">
      <button class="play sm" type="submit" aria-label="<?= h($t['title']) ?> 유튜브에서 재생" title="유튜브에서 재생">▶</button>
    </form>
<?php }

// =====================================================================
// 업로드 결과 카드: 기록 파일을 올린 직후 한 번 보여 줍니다. (upload.php 가 세션에 남긴 결과)
// =====================================================================
function upload_result_card() {
    $r = $_SESSION['upload_result'] ?? null;
    if (!$r) return;
    unset($_SESSION['upload_result']);
    $more = count($r['added_list']) > 12 ? $r['added'] - 12 : max(0, $r['added'] - count($r['added_list'])); ?>
    <section class="panel upload-result" id="upload-result">
      <div class="ur-head">
        <span class="ur-ok" aria-hidden="true">✓</span>
        <div><b><?= h($r['kind']) ?> 가져오기 완료</b><div class="small muted"><?= h($r['file']) ?></div></div>
        <button type="button" class="ur-close" aria-label="닫기" onclick="this.closest('.upload-result').remove()">×</button>
      </div>
      <div class="ur-tiles">
        <div><b><?= number_format($r['songs']) ?></b><span>저장한 곡</span></div>
        <div><b><?= number_format($r['saved']) ?></b><span>재생 횟수</span></div>
        <div><b><?= number_format($r['added']) ?></b><span>새로 추가한 곡</span></div>
        <div><b><?= number_format($r['skipped']) ?></b><span>건너뛴 항목</span></div>
      </div>
      <?php if ($r['top']): ?>
        <div class="ur-sub">이번 파일에서 많이 들은 곡</div>
        <ol class="ur-top">
          <?php foreach ($r['top'] as $t): ?><li><?= h($t['title']) ?> · <span class="muted"><?= h($t['artist']) ?></span> <span class="ur-n"><?= (int) $t['plays'] ?>회</span></li><?php endforeach; ?>
        </ol>
      <?php endif; ?>
      <?php if ($r['added_list']): ?>
        <div class="ur-sub">곡 목록에 새로 추가된 곡</div>
        <div class="ur-chips">
          <?php foreach (array_slice($r['added_list'], 0, 12) as $t): ?><span title="<?= h($t['artist'] . ' · ' . $t['genre']) ?>"><?= h($t['title']) ?></span><?php endforeach; ?>
          <?php if ($more > 0): ?><span class="more">+<?= $more ?></span><?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($r['skipped']): ?>
        <details class="ur-skip">
          <summary>건너뛴 항목 <?= number_format($r['skipped']) ?>개 보기</summary>
          <ul class="small">
            <?php if (!empty($r['dup'])): ?><li>이미 올렸던 기록 <?= number_format($r['dup']) ?>회 (두 번 세지 않음)</li><?php endif; ?>
            <?php if ($r['not_music']): ?><li>음악이 아닌 영상(브이로그·게임 등) <?= number_format($r['not_music']) ?>개</li><?php endif; ?>
            <?php if ($r['over_limit']): ?><li>한 번에 넣을 수 있는 새 곡 수를 넘어서 빠진 기록 <?= number_format($r['over_limit']) ?>개</li><?php endif; ?>
            <?php if ($r['missing_n']): ?>
              <li>가수를 알 수 없어 연결하지 못한 곡 <?= number_format($r['missing_n']) ?>개
                <div class="muted"><?= h(implode(', ', array_map(fn($s) => mb_strimwidth($s, 0, 40, '…', 'UTF-8'), $r['missing']))) ?><?= $r['missing_n'] > count($r['missing']) ? ' …' : '' ?></div></li>
            <?php endif; ?>
          </ul>
        </details>
      <?php endif; ?>
    </section>
<?php }

// =====================================================================
// 대기 화면: 파일을 올리거나 곡을 저장하는 동안 화면을 덮고 진행 상황을 보여 줍니다.
//   <form data-upload>      → 파일을 자바스크립트로 보내고, 올라가는 비율(%)과 단계를 보여 줌
//   <form data-wait="문구"> → 보내는 동안 문구만 보여 줌 (곡 고르기 저장 등)
//   처리 중에는 버튼을 다시 눌러도 한 번만 보내집니다.
// =====================================================================
function wait_overlay() { ?>
    <div class="wait" id="wait" hidden>
      <div class="wait-box" role="status" aria-live="polite">
        <div class="wait-title"><span class="spin" aria-hidden="true"></span><b id="wait-msg">기록을 분석하고 있어요</b></div>
        <div class="small muted" id="wait-file"></div>
        <div id="wait-steps">
          <div class="wait-bar"><i id="wait-fill"></i></div>
          <div class="small muted wait-pct" id="wait-pct">0%</div>
          <ol class="wait-list">
            <li id="ws1">파일 올리기</li>
            <li id="ws2">곡 찾고 저장하기</li>
            <li id="ws3">결과 보여 주기</li>
          </ol>
        </div>
        <p class="small muted" id="wait-note">창을 닫지 말고 기다려 주세요</p>
        <p class="small wait-err" id="wait-err" hidden></p>
        <button type="button" class="btn" id="wait-close" hidden>닫기</button>
      </div>
    </div>
    <script>
    (function () {
      var box = document.getElementById('wait'), busy = false;
      function $(id) { return document.getElementById(id); }
      function step(n) { [1, 2, 3].forEach(function (i) { $('ws' + i).className = i < n ? 'done' : (i === n ? 'now' : ''); }); }
      function size(b) { return b > 1048576 ? (b / 1048576).toFixed(1) + 'MB' : Math.max(1, Math.round(b / 1024)) + 'KB'; }
      function open(msg, file, steps) {
        busy = true; box.hidden = false;
        $('wait-msg').textContent = msg; $('wait-file').textContent = file || '';
        $('wait-steps').hidden = !steps; $('wait-err').hidden = true; $('wait-close').hidden = true; $('wait-note').hidden = false;
        box.classList.remove('failed');
      }
      function fail(msg) {
        busy = false; box.classList.add('failed');
        $('wait-msg').textContent = '가져오지 못했어요'; $('wait-err').textContent = msg; $('wait-err').hidden = false;
        $('wait-steps').hidden = true;
        $('wait-close').hidden = false; $('wait-note').hidden = true;
      }
      $('wait-close').onclick = function () { box.hidden = true; };
      window.addEventListener('beforeunload', function (e) { if (busy && !box.dataset.leaving) { e.preventDefault(); e.returnValue = ''; } });

      document.querySelectorAll('form[data-upload]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          if (busy) return;
          var input = form.querySelector('input[type=file]'), file = input && input.files[0];
          if (!file) { alert('올릴 파일을 골라 주세요.'); return; }
          open('기록을 분석하고 있어요', file.name + ' · ' + size(file.size), true);
          step(1); $('wait-fill').style.width = '0%'; $('wait-fill').classList.remove('flow'); $('wait-pct').textContent = '0%';
          var xhr = new XMLHttpRequest();
          xhr.open('POST', form.action);
          xhr.setRequestHeader('X-Requested-With', 'fetch');
          xhr.upload.onprogress = function (ev) {
            if (!ev.lengthComputable) return;
            var p = Math.round(ev.loaded / ev.total * 100);
            $('wait-fill').style.width = p + '%'; $('wait-pct').textContent = p + '%';
          };
          xhr.upload.onload = function () {
            step(2); $('wait-fill').style.width = '100%'; $('wait-fill').classList.add('flow');
            $('wait-pct').textContent = '곡을 찾는 중…';
          };
          xhr.onload = function () {
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (err) {}
            if (res && res.ok) {
              step(3); $('wait-pct').textContent = '완료';
              box.dataset.leaving = '1'; location.href = res.go + '#upload-result';
            } else {
              fail(res ? res.message : '서버에서 오류가 났어요. (' + xhr.status + ') 잠시 뒤 다시 올려 주세요.');
            }
          };
          xhr.onerror = function () { fail('인터넷 연결이 끊겼어요. 다시 올려 주세요.'); };
          xhr.send(new FormData(form));
        });
      });
      document.querySelectorAll('form[data-wait]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
          if (e.defaultPrevented) return;
          if (busy) { e.preventDefault(); return; }
          open(form.dataset.wait, '', false);
          box.dataset.leaving = '1';
        });
      });
    })();
    </script>
<?php }
