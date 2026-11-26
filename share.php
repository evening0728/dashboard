<?php
// 공유 링크로 들어온 화면: 공유한 사람의 결과 요약 + 나와의 취향 비교
require 'db.php';
require 'lib.php';
require 'spotify.php';
$code = preg_match('/^[0-9a-f]{8}$/', $_GET['code'] ?? '') ? $_GET['code'] : '';
$self = 'share.php?code=' . $code;

// 로그인하지 않았으면 로그인 화면으로 보내고, 로그인 뒤 이 화면으로 돌아옵니다.
if (!isset($_SESSION['user_id'])) { header('Location: login.php?next=' . urlencode($self)); exit; }
$uid  = $_SESSION['user_id'];
$name = $_SESSION['name'];

// 공유 코드로 공유한 사람 찾기
$stmt = $conn->prepare("SELECT u.user_id, u.name FROM shares s JOIN users u ON u.user_id = s.user_id WHERE s.code = ?");
$stmt->bind_param('s', $code);
$stmt->execute();
$owner = $stmt->get_result()->fetch_assoc();

if ($owner) {
    $ao     = analyze($conn, $owner['user_id']);    // 공유한 사람
    $av     = analyze($conn, $uid);                 // 나
    $artist = recommend_artist($ao);
    $mine   = $owner['user_id'] === $uid;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>공유받은 음악 분석</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app">
  <div class="topbar">
    <span class="who"><?= h($name) ?>님, 안녕하세요</span>
    <a class="btn" href="result.php">내 분석</a>
    <a class="btn" href="logout.php">로그아웃</a>
  </div>
  <?php flash(); ?>
  <?php upload_result_card(); ?>

<?php if (!$owner): ?>
  <div class="panel"><h3>찾을 수 없는 공유 링크예요</h3><p class="muted small">주소가 잘못되었거나 공유가 끝난 분석입니다.</p></div>
<?php else: ?>

  <div class="banner">
    <b><?= $mine ? '내가 공유한 음악 분석이에요' : h($owner['name']) . '님이 공유한 음악 분석이에요' ?></b>
    <span class="ro">읽기 전용</span>
  </div>

  <!-- 공유한 사람의 결과 요약 (상세 청취 기록은 보여 주지 않음) -->
  <section>
    <h2 class="sec-title"><?= h($owner['name']) ?>님의 추천 아티스트</h2>
    <?php if ($artist): ?>
      <div class="panel artist-card">
        <?= artist_avatar($conn, $artist['artist']) ?>
        <div class="info">
          <div><span class="artist-name"><?= h($artist['artist']) ?></span> <span class="tag"><?= h($artist['genre']) ?></span></div>
          <div class="meter">
            <div class="top"><span class="muted">취향 일치도</span><b><?= $artist['score'] ?>%</b></div>
            <div class="bar"><i style="width:<?= $artist['score'] ?>%"></i></div>
          </div>
          <div class="small muted">대표곡 : <?= h(implode(' · ', array_slice($artist['titles'], 0, 2))) ?></div>
          <div class="small">총 <?= $ao['songs'] ?>곡 · 최다 장르 <?= h(array_key_first($ao['genres'])) ?></div>
        </div>
      </div>
    <?php else: ?>
      <div class="panel muted">아직 분석할 청취 기록이 없는 사용자예요.</div>
    <?php endif; ?>
  </section>

  <?php if ($mine): ?>
    <p class="muted small">친구가 이 링크를 열면, 친구의 청취 기록과 비교한 취향 궁합이 함께 보여요.</p>

  <?php elseif ($ao['total'] && !$av['total']): ?>
    <!-- 내 청취 기록이 없을 때: 비교 대신 안내 카드 -->
    <section>
      <h2 class="sec-title">나(<?= h($name) ?>)와의 취향 비교</h2>
      <?php upload_card($name, '기록을 올리면 ' . $owner['name'] . '님과 취향을 비교해 드려요.', $self); ?>
    </section>
    <section>
      <h2 class="sec-title"><?= h($owner['name']) ?>님의 추천곡</h2>
      <div class="mini-grid">
        <?php $heard = array_filter($ao['tracks'], fn($t) => $t['plays'] > 0);
              usort($heard, fn($x, $y) => $y['plays'] <=> $x['plays']);
              foreach (array_slice($heard, 0, 3) as $t) { $t['plays'] = 0; song_card($t, $self); } ?>
      </div>
    </section>

  <?php elseif ($ao['total']): ?>
    <!-- 취향 비교 -->
    <section>
      <h2 class="sec-title">나(<?= h($name) ?>)와의 취향 비교</h2>
      <div class="panel">
        <div class="cmp-head">
          <div class="p"><div class="avatar md" aria-hidden="true"><?= h(mb_substr($owner['name'], 0, 1)) ?></div><?= h($owner['name']) ?></div>
          <div><div class="small muted">취향 궁합</div><div class="score"><?= compatibility($ao, $av) ?>%</div></div>
          <div class="p"><div class="avatar md b" aria-hidden="true"><?= h(mb_substr($name, 0, 1)) ?></div>나 (<?= h($name) ?>)</div>
        </div>
        <div class="cmp-rows">
          <?php $all = array_unique(array_merge(array_keys($ao['genres']), array_keys($av['genres'])));
                usort($all, fn($x, $y) => (share($ao, $y) + share($av, $y)) <=> (share($ao, $x) + share($av, $x)));
                foreach (array_slice($all, 0, 5) as $g): ?>
            <span><?= h($g) ?></span>
            <span class="bars"><i class="o" style="width:<?= share($ao, $g) ?>%"></i><i class="v" style="width:<?= share($av, $g) ?>%"></i></span>
            <span class="muted"><?= share($ao, $g) ?>% / <?= share($av, $g) ?>%</span>
          <?php endforeach; ?>
        </div>
        <div class="legend"><span><i class="sw o"></i><?= h($owner['name']) ?></span><span><i class="sw v"></i>나</span></div>
      </div>
    </section>
    <section>
      <h2 class="sec-title">둘이 함께 들으면 좋은 곡</h2>
      <div class="mini-grid"><?php foreach (together_songs($ao, $av, 3) as $t) song_card($t, $self); ?></div>
    </section>
  <?php endif; ?>

  <div class="row">
    <a class="btn primary" href="result.php">내 분석 결과 보기</a>
    <a class="btn" href="result.php">나도 공유하기</a>
  </div>
<?php endif; ?>
</div>
</body>
</html>
