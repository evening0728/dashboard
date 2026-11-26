<?php
// DB 연결 파일. 다른 모든 파일이 이 파일을 불러서 $conn 을 사용합니다.
// 서버마다 달라지는 DB 정보와 관리자 비밀번호는 config.php 에 있습니다.
require __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// config.php 계정으로 먼저 접속하고, 그 계정이 없는 컴퓨터(XAMPP 기본)라면 root(비밀번호 없음)로 다시 시도합니다.
try {
    try {
        $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() != 1045 || $DB_USER === 'root') throw $e;   // 1045 = 아이디/비밀번호가 틀림
        $DB_USER = 'root'; $DB_PASS = '';
        $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    }
} catch (mysqli_sql_exception $e) {
    // 1049 = DB가 아직 없음. 내 컴퓨터(XAMPP)에서 처음 실행할 때만 DB를 직접 만듭니다.
    // (웹호스팅은 DB를 관리 화면에서 미리 만들어 주므로 여기로 오지 않습니다)
    if ($e->getCode() != 1049) throw $e;
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    $conn->query("CREATE DATABASE `$DB_NAME` CHARACTER SET utf8mb4");
    $conn->select_db($DB_NAME);
}
$conn->set_charset('utf8mb4');

// 지금 내 컴퓨터(localhost)에서 열었는지, 공개 서버 주소로 열었는지
function is_local_host() {
    return (bool) preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $_SERVER['HTTP_HOST'] ?? '');
}

// 스포티파이는 localhost 주소를 허용하지 않으므로, localhost 로 들어오면 127.0.0.1 로 바꿔 보냅니다.
// (로그인 정보가 주소마다 따로 저장되기 때문에, 처음부터 한 주소로 맞춰 두어야 연결이 끊기지 않습니다)
if (preg_match('/^localhost(:\d+)?$/i', $_SERVER['HTTP_HOST'] ?? '', $m) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Location: http://127.0.0.1' . ($m[1] ?? '') . $_SERVER['REQUEST_URI']);
    exit;
}

session_start();

// 아직 설치(setup.php)를 하지 않아 테이블이 없으면, 오류 대신 설치 화면으로 보냅니다.
if (basename($_SERVER['SCRIPT_NAME']) !== 'setup.php') {
    $need = ['users', 'tracks', 'plays', 'shares', 'artists'];
    $have = [];
    foreach ($conn->query("SHOW TABLES") as $row) $have[] = array_values($row)[0];
    if (array_diff($need, $have)) { header('Location: setup.php'); exit; }
}

// 화면에 글자를 안전하게 출력하는 함수 (XSS 방지)
function h($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
