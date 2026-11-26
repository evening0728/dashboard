<?php
// 결과 화면 (로그인 후): 요약 → 추천 플레이리스트 → 추천 곡 → 차트, 그리고 공유하기
require 'db.php';
require 'lib.php';
require 'spotify.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }   // 로그인 안 했으면 메인으로
$uid  = $_SESSION['user_id'];
$name = $_SESSION['name'];

$a      = analyze($conn, $uid);                 // 장르·가수별 청취 횟수
$total  = $a['total'];
$recs   = recommend_songs($a, 5, $conn);        // 추천 곡 5개 (취향 + 함께 들은 곡 + 다양성)
$artist = recommend_artist($a);                 // 공유 팜플렛에 들어갈 추천 아티스트
// 추천 플레이리스트에 쓸 장르: '기타'(장르를 아직 모르는 곡)는 빼고 많이 들은 순서
$realGenres = array_diff_key($a['genres'], ['기타' => 0]);
$top    = array_key_first($realGenres ?: $a['genres']);   // 최다 장르 ('기타'만 있으면 기타)

// 월별 청취 수 (최근 6개월)
$stmt = $conn->prepare(
    "SELECT DATE_FORMAT(played_on, '%Y-%m') AS ym, SUM(play_count) AS plays
     FROM plays
     WHERE user_id = ? AND played_on >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
     GROUP BY ym ORDER BY ym");
$stmt->bind_param('s', $uid);
$stmt->execute();
$found = [];
foreach ($stmt->get_result() as $r) $found[$r['ym']] = (int) $r['plays'];

// 최근 6개월을 먼저 0으로 만들어 두고, 기록이 있는 달만 채웁니다. (기록 없는 달도 0으로 표시)
$monthly = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
    $monthly[] = ['ym' => $ym, 'plays' => $found[$ym] ?? 0];
}

// 장르별 곡 수 (플레이리스트 카드에 표시)
$genreSongs = [];
foreach ($conn->query("SELECT genre, COUNT(*) AS n FROM tracks GROUP BY genre") as $r) $genreSongs[$r['genre']] = $r['n'];
$playlistName = ['발라드' => '새벽 감성 발라드', '인디' => '잔잔한 인디 모음', 'R&B' => '밤에 듣는 R&B',
                 '힙합' => '리듬 타는 힙합', 'K-POP' => '신나는 K-POP'];
$playlistDesc = ['발라드' => '마음을 천천히 울리는 감성 발라드',
                 '인디'   => '가볍게 흘려 듣기 좋은 밴드·인디 음악',
                 'R&B'    => '밤에 어울리는 부드러운 R&B',
                 '힙합'   => '리듬감 넘치는 국내 힙합',
                 'K-POP'  => '기분 전환에 좋은 아이돌 히트곡'];

// 공유 링크 주소
$shareUrl = '';
if ($total) {
    // 터널(Cloudflare)로 들어오면 바깥 주소는 https 이므로, 터널이 알려 주는 X-Forwarded-Proto 도 봅니다.
    $https    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $scheme   = $https ? 'https' : 'http';
    $shareUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/share.php?code=' . share_code($conn, $uid);
}
$reason = $artist ? $artist['genre'] . ' 장르를 ' . share($a, $artist['genre']) . '% 들어서 추천했어요' : '';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($name) ?>님의 음악 분석</title>
<link rel="stylesheet" href="style.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
</head>
<body>
<div class="app">
  <div class="topbar">
    <span class="who"><?= h($name) ?>님의 음악 분석</span>
    <?php if ($total): ?><a class="btn" href="add.php">기록 추가</a><?php endif; ?>
    <?php if ($total): ?><button class="btn primary" id="btn-share" type="button">공유하기</button><?php endif; ?>
    <?php if (is_admin($conn)): ?><a class="btn" href="songs.php">곡 관리</a><?php endif; ?>
    <a class="btn" href="logout.php">로그아웃</a>
  </div>
  <?php flash(); ?>
  <?php upload_result_card(); ?>
  <?php spotify_box($conn, $uid); ?>

<?php if ($total == 0): ?>
  <?php upload_card($name, '기록을 올리거나 아래 곡을 재생하면, 취향을 분석해서 음악을 추천해 드려요.', 'result.php'); ?>
<?php else: ?>

  <!-- 1. 한눈에 요약 -->
  <section>
    <h2 class="sec-title">한눈에 요약</h2>
    <div class="summary">
      <div class="sum-card">
        <div class="lab">총 청취 곡</div>
        <div class="val"><?= $a['songs'] ?>곡 <small>재생 <?= $total ?>회</small></div>
      </div>
      <div class="sum-card">
        <div class="lab">최다 장르</div>
        <div class="val"><?= h($top) ?> <small><?= share($a, $top) ?>%</small></div>
      </div>
    </div>
  </section>

  <!-- 2. 추천 플레이리스트 TOP 3 (많이 들은 장르 순서, 순위가 높을수록 진한 배경) -->
  <?php if ($realGenres): ?>
  <section>
    <h2 class="sec-title">추천 플레이리스트 TOP <?= min(3, count($realGenres)) ?></h2>
    <div class="pl-grid">
      <?php $i = 0; foreach (array_slice($realGenres, 0, 3, true) as $genre => $plays): $i++; ?>
        <div class="pl r<?= $i ?>">
          <span class="badge"><?= $i ?></span>
          <span class="name"><?= h($playlistName[$genre] ?? $genre . ' 모음') ?></span>
          <span class="desc"><?= h($playlistDesc[$genre] ?? $genre . ' 장르 곡 모음') ?></span>
          <?php $songs = playlist_songs($a, $genre, 10);                     // 화면에 보여 줄 곡 목록 (10곡)
                $all   = play_all_link(playlist_songs($a, $genre, PHP_INT_MAX), 30);   // 장르 곡 전체에서 영상 있는 곡으로 30곡 채움    // [전체 재생]으로 이어서 들을 곡 (30곡)
                $singers = array_slice(array_unique(array_column($songs, 'artist')), 0, 3); ?>
          <span class="why">내가 들은 곡의 <?= share($a, $genre) ?>%가 <?= h($genre) ?>예요</span>
          <span class="cnt"><?= h(implode(' · ', $singers)) ?> 등 <?= (int) ($genreSongs[$genre] ?? 0) ?>곡</span>
          <span class="tag">기준 · <?= h($genre) ?></span>
          <details class="pl-songs">
            <summary>곡 목록 보기 (<?= count($songs) ?>곡)</summary>
            <?php foreach ($songs as $k => $t) song_row($t, $k + 1); ?>
          </details>
          <?php if ($all['count']): ?>
            <a class="playall" href="<?= h($all['url']) ?>" target="_blank" rel="noopener">▶ 전체 재생 <span class="n"><?= $all['count'] ?>곡</span></a>
            <span class="sub">유튜브에서 이어서 재생돼요</span>
          <?php else: ?>
            <span class="playall off" aria-disabled="true">▶ 전체 재생 준비 중</span>
            <span class="sub">곡의 유튜브 영상 주소가 등록되면 이어서 들을 수 있어요</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
<?php endif; ?>

  <!-- 3. 추천 곡 -->
  <?php if ($recs): ?>
  <section>
    <h2 class="sec-title"><?= $total ? '추천 곡' : '먼저 들어 볼 만한 곡' ?></h2>
    <div class="mini-grid">
      <?php foreach ($recs as $i => $s):
          song_card($s, 'result.php', $i + 1, $s['why']);      // 곡마다 추천 이유 표시
      endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

<?php if ($total > 0): ?>
  <!-- 4. 청취 분석 차트 -->
  <section>
    <h2 class="sec-title">청취 분석 차트</h2>
    <div class="charts">
      <div class="panel"><div class="chart-title">장르별 비율</div><div class="chart-box"><canvas id="genreChart"></canvas></div></div>
      <div class="panel"><div class="chart-title">월별 청취 수 (최근 6개월)</div><div class="chart-box"><canvas id="monthChart"></canvas></div></div>
    </div>
  </section>

  <!-- 5. 공유하기 팝업: 추천 아티스트 팜플렛 -->
  <div class="scrim" id="share-modal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-label="추천 아티스트 팜플렛">
      <div class="pamphlet">
        <header>
          <h3><?= h($name) ?>님 취향 저격 아티스트</h3>
          <p><?= date('Y년 n월') ?> · 나의 음악 분석</p>
          <button class="close" id="btn-close" type="button" aria-label="닫기">×</button>
        </header>
        <div class="in">
          <?= artist_avatar($conn, $artist['artist']) ?>
          <div class="artist"><?= h($artist['artist']) ?></div>
          <span class="tag center"><?= h($artist['genre']) ?></span>
          <div class="meter">
            <div class="top"><span class="muted">취향 일치도</span><b><?= $artist['score'] ?>%</b></div>
            <div class="bar"><i style="width:<?= $artist['score'] ?>%"></i></div>
          </div>
          <div class="stretch"><b>대표곡</b><ol><?php foreach ($artist['titles'] as $t): ?><li><?= h($t) ?></li><?php endforeach; ?></ol></div>
          <div class="reason"><?= h($reason) ?></div>
        </div>
      </div>
      <div class="row">
        <button class="btn primary" id="btn-png" type="button">PNG로 저장</button>
        <button class="btn" id="btn-copy" type="button">링크 복사</button>
      </div>
      <input class="linkbox" id="share-link" value="<?= h($shareUrl) ?>" readonly hidden>
      <p class="toast" id="toast" hidden></p>
    </div>
  </div>

  <script>
  // PHP가 SQL로 구한 결과를 JavaScript로 넘깁니다.
  const J = <?= json_encode([
      'genres'  => array_map(fn($g, $n) => ['genre' => $g, 'plays' => $n], array_keys($a['genres']), $a['genres']),
      'monthly' => $monthly, 'name' => $name, 'uid' => $uid, 'artist' => $artist, 'reason' => $reason,
      'month'   => date('Y년 n월'),
      'artistImg' => $artist ? artist_image($conn, $artist['artist']) : null,
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

  // ----- 차트 ----- (인터넷이 안 되어 Chart.js를 못 불러오면 차트만 건너뜁니다)
  if (window.Chart) {
  new Chart(document.getElementById('genreChart'), {
    type: 'pie',
    data: { labels: J.genres.map(g => g.genre),
            datasets: [{ data: J.genres.map(g => Number(g.plays)),
                         backgroundColor: ['#b6a9ee', '#f0dfae', '#f2b9c4', '#a9d8ea', '#c9c9c9', '#b8e0b0', '#f5c99b', '#d9b8e8'] }] },
    options: { maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
  });
  new Chart(document.getElementById('monthChart'), {
    type: 'bar',
    data: { labels: J.monthly.map(m => Number(m.ym.slice(5)) + '월'),
            datasets: [{ data: J.monthly.map(m => Number(m.plays)),
                         backgroundColor: J.monthly.map((m, i) => i === J.monthly.length - 1 ? '#4a3a9c' : '#d5cdf6') }] },   // 이번 달만 진하게
    options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
  });
  } else {
    document.querySelectorAll('.chart-box').forEach(box => box.textContent = '차트를 불러오지 못했어요. 인터넷 연결을 확인해 주세요.');
  }

  // ----- 공유하기 팝업 -----
  const modal = document.getElementById('share-modal'), toast = document.getElementById('toast');
  const say = msg => { toast.textContent = msg; toast.hidden = false; };
  document.getElementById('btn-share').onclick = () => { modal.hidden = false; toast.hidden = true; };
  document.getElementById('btn-close').onclick = () => { modal.hidden = true; };
  modal.onclick = e => { if (e.target === modal) modal.hidden = true; };
  document.addEventListener('keydown', e => { if (e.key === 'Escape') modal.hidden = true; });

  // 링크 복사
  document.getElementById('btn-copy').onclick = async () => {
    const box = document.getElementById('share-link');
    box.hidden = false; box.select();
    try { await navigator.clipboard.writeText(box.value); say('링크가 복사되었어요'); }
    catch (e) { say('아래 주소를 직접 복사해 주세요 (Ctrl+C)'); }
  };

  // PNG로 저장: 팜플렛을 캔버스에 그린 뒤 이미지 파일로 내려받습니다.
  document.getElementById('btn-png').onclick = () => {
    if (!J.artistImg) return drawPamphlet(null);
    const photo = new Image();
    photo.onload = () => drawPamphlet(photo);
    photo.onerror = () => drawPamphlet(null);
    photo.src = J.artistImg;
  };
  function drawPamphlet(photo) {
    const W = 720, H = 1000, c = document.createElement('canvas'); c.width = W; c.height = H;
    const x = c.getContext('2d'), F = (w, s) => `${w} ${s}px 'Malgun Gothic', sans-serif`, ar = J.artist;
    const text = (t, px, py, font, color, align = 'center') => { x.font = font; x.fillStyle = color; x.textAlign = align; x.fillText(t, px, py); };
    x.fillStyle = '#ffffff'; x.fillRect(0, 0, W, H);
    x.fillStyle = '#b6a9ee'; x.fillRect(0, 0, W, 150);
    text(J.name + '님 취향 저격 아티스트', W / 2, 78, F(700, 40), '#1d1a2b');
    text(J.month + ' · 나의 음악 분석', W / 2, 120, F(400, 24), '#3a2f7a');
    x.fillStyle = '#d5cdf6'; x.beginPath(); x.arc(W / 2, 290, 100, 0, Math.PI * 2); x.fill();
    if (photo) {                       // 가수 사진을 동그랗게 잘라서 그림 (가운데 기준으로 꽉 채움)
      const s = Math.min(photo.naturalWidth, photo.naturalHeight);
      x.save(); x.beginPath(); x.arc(W / 2, 290, 100, 0, Math.PI * 2); x.clip();
      x.drawImage(photo, (photo.naturalWidth - s) / 2, (photo.naturalHeight - s) / 2, s, s, W / 2 - 100, 190, 200, 200);
      x.restore();
    } else {
      text([...ar.artist][0], W / 2, 322, F(700, 88), '#4a3a9c');
    }
    text(ar.artist, W / 2, 480, F(700, 64), '#1d1a2b');
    x.font = F(400, 26); const tw = x.measureText(ar.genre).width + 36;
    x.strokeStyle = '#4a3a9c'; x.lineWidth = 2; x.strokeRect(W / 2 - tw / 2, 506, tw, 44); text(ar.genre, W / 2, 538, F(400, 26), '#4a3a9c');
    text('취향 일치도', 90, 622, F(400, 26), '#6a6680', 'left'); text(ar.score + '%', W - 90, 622, F(700, 30), '#4a3a9c', 'right');
    x.fillStyle = '#ebe7fb'; x.fillRect(90, 640, W - 180, 24); x.fillStyle = '#b6a9ee'; x.fillRect(90, 640, (W - 180) * ar.score / 100, 24);
    text('대표곡', 90, 728, F(700, 28), '#1d1a2b', 'left');
    ar.titles.forEach((t, i) => text((i + 1) + '. ' + t, 110, 772 + i * 40, F(400, 26), '#1d1a2b', 'left'));
    x.fillStyle = '#ebe7fb'; x.fillRect(70, 890, W - 140, 60); text(J.reason, W / 2, 930, F(400, 26), '#4a3a9c');
    x.strokeStyle = '#1d1a2b'; x.lineWidth = 6; x.strokeRect(3, 3, W - 6, H - 6);
    c.toBlob(blob => {
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob); link.download = J.uid + '_추천아티스트.png';
      document.body.append(link); link.click(); link.remove();
      say('팜플렛 이미지를 저장했어요');
    }, 'image/png');
  }
  </script>

  <!-- 6. 청취 기록 초기화 (본인 기록만 삭제) -->
  <section class="panel reset-box">
    <div>
      <h2 class="sec-title">청취 기록 초기화</h2>
      <p class="muted small">지금까지 올리거나 재생한 기록 <?= $total ?>회를 모두 지우고 처음부터 다시 분석해요. 지운 기록은 되돌릴 수 없어요.</p>
    </div>
    <form method="post" action="reset.php"
          onsubmit="return confirm('청취 기록을 모두 지울까요?\n지운 기록은 되돌릴 수 없어요.');">
      <input type="hidden" name="token" value="<?= h(form_token()) ?>">
      <button class="btn danger" type="submit">기록 모두 지우기</button>
    </form>
  </section>
<?php endif; ?>
</div>
</body>
</html>
