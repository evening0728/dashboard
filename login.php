<?php
// 로그인 / 회원가입 화면
require 'db.php';
require 'lib.php';

$error = '';
$mode  = ($_GET['mode'] ?? '') === 'signup' ? 'signup' : 'login';
$next  = safe_back($_POST['next'] ?? $_GET['next'] ?? 'result.php');   // 로그인 뒤 돌아갈 화면

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = strtolower(trim($_POST['user_id'] ?? ''));
    $pw   = $_POST['password'] ?? '';
    $mode = ($_POST['mode'] ?? '') === 'signup' ? 'signup' : 'login';

    // 아이디로 사용자 찾기 (Prepared Statement → SQL 인젝션 방지)
    $stmt = $conn->prepare("SELECT user_id, password, name FROM users WHERE user_id = ?");
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($mode === 'signup') {
        $name = trim($_POST['name'] ?? '');
        if (!preg_match('/^[a-z0-9]{3,12}$/', $id)) {
            $error = '아이디는 영문 소문자와 숫자 3~12자로 입력해 주세요.';
        } elseif ($user) {
            $error = '이미 사용 중인 아이디입니다.';
        } elseif (strlen($pw) < 4) {
            $error = '비밀번호는 4자 이상으로 입력해 주세요.';
        } else {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $name = $name !== '' ? $name : $id;
            $add  = $conn->prepare("INSERT INTO users (user_id, password, name) VALUES (?, ?, ?)");
            $add->bind_param('sss', $id, $hash, $name);
            $add->execute();
            $user = ['user_id' => $id, 'name' => $name];
        }
    } elseif (!$user || !password_verify($pw, $user['password'])) {
        $error = '아이디 또는 비밀번호가 올바르지 않습니다.';
    }

    if ($error === '') {                       // 성공: 세션에 저장하고 결과 화면으로
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['name']    = $user['name'];
        // 처음 가입한 사람은 '좋아하는 곡 고르기' 화면부터 보여 줍니다.
        if ($mode === 'signup' && $next === 'result.php') $next = 'add.php?first=1';
        header('Location: ' . $next);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $mode === 'signup' ? '회원가입' : '로그인' ?> - 음악 추천 대시보드</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app">
  <div class="topbar">
    <a class="btn" href="index.php">홈</a>
    <span class="brand">♪ 음악 추천</span>
  </div>

  <form class="auth panel" method="post" action="login.php">
    <h2><?= $mode === 'signup' ? '회원가입' : '로그인' ?></h2>
    <?php if ($next !== 'result.php'): ?><p class="small muted">공유받은 분석을 보려면 먼저 로그인해 주세요.</p><?php endif; ?>
    <input type="hidden" name="mode" value="<?= $mode ?>">
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <label class="field">아이디
      <input name="user_id" value="<?= h($_POST['user_id'] ?? '') ?>" required>
    </label>
    <label class="field">비밀번호
      <input name="password" type="password" required>
    </label>
    <?php if ($mode === 'signup'): ?>
      <label class="field">이름
        <input name="name" value="<?= h($_POST['name'] ?? '') ?>" placeholder="화면에 보일 이름">
      </label>
    <?php endif; ?>
    <p class="err"><?= h($error) ?></p>
    <div class="row">
      <button class="btn primary" type="submit"><?= $mode === 'signup' ? '가입하기' : '로그인' ?></button>
      <?php if ($mode === 'signup'): ?>
        <a class="btn" href="login.php?next=<?= urlencode($next) ?>">로그인으로</a>
      <?php else: ?>
        <a class="btn" href="login.php?mode=signup&amp;next=<?= urlencode($next) ?>">회원가입</a>
      <?php endif; ?>
    </div>
  </form>
</div>
</body>
</html>
