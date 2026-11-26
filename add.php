<?php
// 청취 기록 추가 화면: 기록을 넣는 3가지 방법을 한곳에 모았습니다.
//   ① 좋아하는 곡 직접 고르기   → 파일이 없어도 바로 시작 (pick.php 에 저장)
//   ② 기록 파일 올리기          → 유튜브·스포티파이·애플 뮤직·CSV 파일 (upload.php 가 종류를 자동으로 알아냄)
//   ③ 스포티파이 계정 연결      → 운영자가 등록한 계정만 가능
require 'db.php';
require 'lib.php';
require 'spotify.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$name  = $_SESSION['name'];
$back  = safe_back($_GET['back'] ?? 'result.php');
$first = isset($_GET['first']);

// 고를 수 있는 곡 목록: 사람들이 많이 들은 곡부터 최대 1500곡 (목록에 없으면 '직접 넣기'로 추가)
$tracks = $conn->query(
    "SELECT t.track_id, t.title, t.artist, t.genre
     FROM tracks t LEFT JOIN plays p ON p.track_id = t.track_id
     GROUP BY t.track_id, t.title, t.artist, t.genre
     ORDER BY t.genre = '기타', SUM(COALESCE(p.play_count, 0)) DESC, t.genre, t.artist, t.title
     LIMIT 1500")->fetch_all(MYSQLI_ASSOC);
$genres = array_column($conn->query("SELECT genre FROM tracks GROUP BY genre ORDER BY genre = '기타', COUNT(*) DESC")->fetch_all(), 0);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>청취 기록 추가 - 음악 추천 대시보드</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app">
  <div class="topbar">
    <span class="who">청취 기록 추가</span>
    <a class="btn" href="<?= h($back) ?>"><?= $first ? '나중에 하기' : '돌아가기' ?></a>
  </div>
  <?php flash(); ?>

  <?php if ($first): ?>
    <p class="flash"><?= h($name) ?>님, 반가워요! 좋아하는 곡을 5곡 이상 고르면 바로 취향을 분석해 드려요.</p>
  <?php endif; ?>

  <nav class="add-tabs">
    <a href="#pick">① 좋아하는 곡 고르기</a>
    <a href="#file">② 기록 파일 올리기</a>
    <?php if (spotify_ready()): ?><a href="#spotify">③ 스포티파이 연결</a><?php endif; ?>
  </nav>

  <!-- ① 좋아하는 곡 직접 고르기 -->
  <section class="panel" id="pick">
    <h2 class="sec-title">① 좋아하는 곡 고르기 <small class="muted">파일 없이 바로 시작</small></h2>
    <p class="muted small">어느 앱(멜론·지니·플로 등)에서 듣든 상관없어요. 자주 듣는 곡을 골라 주세요. 고른 곡은 3번 들은 것으로 저장돼요.</p>
    <form method="post" action="pick.php" id="pick-form" data-wait="고른 곡으로 취향을 분석하고 있어요">
      <input type="hidden" name="token" value="<?= h(form_token()) ?>">
      <input type="hidden" name="back" value="<?= h($back) ?>">
      <div class="pick-tools">
        <input type="search" id="pick-q" placeholder="곡 제목이나 가수 검색" autocomplete="off">
        <div class="chips" id="pick-genres">
          <button type="button" class="chip on" data-g="">전체</button>
          <?php foreach ($genres as $g): ?><button type="button" class="chip" data-g="<?= h($g) ?>"><?= h($g) ?></button><?php endforeach; ?>
        </div>
      </div>
      <div class="pick-list" id="pick-list">
        <?php foreach ($tracks as $t): ?>
          <label class="pick-item" data-g="<?= h($t['genre']) ?>" data-s="<?= h(mb_strtolower($t['title'] . ' ' . $t['artist'], 'UTF-8')) ?>">
            <input type="checkbox" name="picks[]" value="<?= (int) $t['track_id'] ?>">
            <span class="t"><?= h($t['title']) ?></span>
            <span class="a"><?= h($t['artist']) ?></span>
            <span class="g"><?= h($t['genre']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="muted small" id="pick-empty" hidden>검색한 곡이 목록에 없어요. 아래 '목록에 없는 곡 직접 넣기'에 적어 주세요.</p>

      <details class="custom">
        <summary>목록에 없는 곡 직접 넣기</summary>
        <p class="muted small">제목과 가수를 적으면 곡 목록에 새로 추가돼요. 장르를 모르면 '자동'으로 두세요. (같은 가수의 곡이 있으면 그 장르로 정해요)</p>
        <div id="custom-rows">
          <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="custom-row">
              <input name="c_title[]" placeholder="곡 제목" maxlength="100">
              <input name="c_artist[]" placeholder="가수" maxlength="100">
              <select name="c_genre[]">
                <option value="">장르 자동</option>
                <?php foreach ($genres as $g): ?><option><?= h($g) ?></option><?php endforeach; ?>
              </select>
            </div>
          <?php endfor; ?>
        </div>
        <button type="button" class="btn" id="custom-more">칸 더 만들기</button>
      </details>

      <div class="pick-bar">
        <span id="pick-count">0곡 골랐어요</span>
        <button class="btn primary" type="submit">고른 곡으로 분석하기</button>
      </div>
    </form>
  </section>

  <!-- ② 기록 파일 올리기 -->
  <section class="panel" id="file">
    <h2 class="sec-title">② 기록 파일 올리기 <small class="muted">실제로 들은 기록으로 더 정확하게</small></h2>
    <form method="post" action="upload.php" enctype="multipart/form-data" class="file-form" data-upload>
      <input type="hidden" name="back" value="<?= h($back) ?>">
      <input type="file" name="history" accept=".json,.zip,.html,.htm,.csv,.txt" required>
      <button class="btn primary" type="submit">올리기</button>
    </form>
    <p class="muted small">파일 종류는 자동으로 알아내요. 받은 zip 파일을 <b>압축 풀지 않고 그대로</b> 올려도 돼요. 처음 보는 곡은 곡 목록에 자동으로 추가돼요.</p>

    <div class="guides">
      <details open>
        <summary>유튜브 · 유튜브 뮤직 (구글 테이크아웃)</summary>
        <ol>
          <li><a href="https://takeout.google.com/" target="_blank" rel="noopener">takeout.google.com</a> 에 접속해 <b>선택 해제</b>를 누릅니다.</li>
          <li>목록에서 <b>YouTube 및 YouTube Music</b>만 체크합니다.</li>
          <li><b>모든 YouTube 데이터 포함됨</b> 버튼을 눌러 <b>기록</b>만 남기고 확인을 누릅니다. (영상까지 받으면 파일이 너무 커져요)</li>
          <li><b>다음 단계 → 내보내기 생성</b>을 누르고, 메일로 온 링크에서 zip 파일을 받습니다. (보통 몇 분 걸려요)</li>
          <li>받은 zip 파일을 위에서 그대로 올립니다.</li>
        </ol>
        <p class="muted small">유튜브 뮤직에서 들은 곡과 뮤직비디오만 골라서 저장하고, 일반 영상은 건너뛰어요.</p>
      </details>
      <details>
        <summary>스포티파이</summary>
        <ol>
          <li><a href="https://www.spotify.com/account/privacy/" target="_blank" rel="noopener">spotify.com 계정 → 개인정보 보호 설정</a>으로 갑니다.</li>
          <li><b>계정 데이터</b>를 체크하고 <b>데이터 요청</b>을 누른 뒤, 메일에서 확인을 누릅니다.</li>
          <li>며칠 뒤 메일로 온 zip 파일을 받아서 그대로 올립니다. (안의 StreamingHistory 파일을 읽어요)</li>
        </ol>
      </details>
      <details>
        <summary>애플 뮤직</summary>
        <ol>
          <li><a href="https://privacy.apple.com/" target="_blank" rel="noopener">privacy.apple.com</a> 에서 <b>데이터 사본 요청</b>을 누릅니다.</li>
          <li><b>Apple Media Services 정보</b>를 골라 요청합니다. (준비되면 메일이 와요)</li>
          <li>받은 파일 안의 <b>Apple Music - Play History Daily Tracks.csv</b> 를 올립니다.</li>
        </ol>
      </details>
      <details>
        <summary>멜론 · 지니 · 플로 등 (엑셀/CSV로 직접 만들기)</summary>
        <p class="small">기록 파일을 내려받는 기능이 없는 앱은 엑셀에 <b>title, artist, count</b> (제목, 가수, 들은 횟수) 세 칸으로 적어서 CSV로 저장해 올려 주세요.
           <a href="history_template.csv" download>예시 파일 받기</a></p>
      </details>
    </div>
  </section>

  <?php if (spotify_ready()): ?>
  <!-- ③ 스포티파이 연결 -->
  <section class="panel" id="spotify">
    <h2 class="sec-title">③ 스포티파이 계정 연결</h2>
    <p class="muted small">계정을 연결하면 최근에 들은 곡을 바로 가져와요. 스포티파이 정책 때문에 지금은 운영자가 등록한 계정만 연결할 수 있어요.</p>
    <a class="btn sp" href="spotify_login.php">스포티파이 계정 연결하기</a>
  </section>
  <?php endif; ?>
</div>

<script>
(function () {
  var q = document.getElementById('pick-q'), list = document.getElementById('pick-list'), empty = document.getElementById('pick-empty');
  var items = Array.prototype.slice.call(list.querySelectorAll('.pick-item'));
  var genre = '';
  function filter() {
    var word = q.value.trim().toLowerCase(), shown = 0;
    items.forEach(function (el) {
      var ok = (!genre || el.dataset.g === genre) && (!word || el.dataset.s.indexOf(word) !== -1);
      el.hidden = !ok && !el.querySelector('input').checked;      // 고른 곡은 계속 보이게
      if (!el.hidden) shown++;
    });
    empty.hidden = shown > 0;
  }
  q.addEventListener('input', filter);
  document.getElementById('pick-genres').addEventListener('click', function (e) {
    var b = e.target.closest('.chip'); if (!b) return;
    genre = b.dataset.g;
    this.querySelectorAll('.chip').forEach(function (c) { c.classList.toggle('on', c === b); });
    filter();
  });
  function count() {
    var n = list.querySelectorAll('input:checked').length;
    document.querySelectorAll('#custom-rows input[name="c_title[]"]').forEach(function (i) { if (i.value.trim()) n++; });
    document.getElementById('pick-count').textContent = n + '곡 골랐어요';
    return n;
  }
  document.getElementById('pick-form').addEventListener('input', count);
  document.getElementById('pick-form').addEventListener('change', count);
  document.getElementById('pick-form').addEventListener('submit', function (e) {
    if (count() === 0) { e.preventDefault(); alert('곡을 1곡 이상 골라 주세요.'); }
  });
  document.getElementById('custom-more').addEventListener('click', function () {
    var rows = document.getElementById('custom-rows'), row = rows.lastElementChild.cloneNode(true);
    row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    row.querySelector('select').selectedIndex = 0;
    rows.appendChild(row);
  });
})();
</script>
<?php wait_overlay(); ?>
</body>
</html>
