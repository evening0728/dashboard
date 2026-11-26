<?php
// 청취 기록 올리기: 기록 파일을 읽어서 DB의 곡(tracks)과 연결한 뒤 plays 테이블에 넣습니다.
//
// 받을 수 있는 파일 (종류는 파일 내용을 보고 자동으로 알아냅니다. 읽는 방법은 import_lib.php)
//   - 유튜브 시청 기록: 구글 테이크아웃의 시청 기록.json / 시청 기록.html, 또는 테이크아웃 zip 그대로
//   - 스포티파이 기록: StreamingHistory_music_0.json, Streaming_History_Audio_….json, 또는 zip 그대로
//   - 애플 뮤직 재생 기록 CSV, 엑셀로 만든 CSV (제목, 가수, 횟수)
//   - 간단 형식 JSON  [{"title":"밤편지","artist":"아이유","count":3}, ...]
//
// DB에 없는 곡은 가수를 알 수 있으면 새 곡으로 추가합니다. (장르는 같은 가수의 곡을 보고 정하고, 모르면 '기타')
// 유튜브의 일반 영상(브이로그·게임 등)은 음악이 아니므로 건너뜁니다.
//
// 곡을 찾는 방법
//   - 띄어쓰기·대소문자·특수문자를 무시하고 비교합니다.  ("밤 편지" = "밤편지")
//   - 제목이 똑같은 곡을 먼저 찾고, 없으면 영상 제목 안에 곡 제목이 들어 있는 곡을 찾습니다.
//     예) "아이유 - 밤편지 (Official MV)" 안에 "밤편지" 가 있으므로 연결
//   - 같은 제목의 곡이 여러 개면 가수 이름이 같이 들어 있는 곡을 고릅니다.
//   - 가수를 알 수 없어 연결하지 못한 곡은 화면에 알려 줍니다.
require 'db.php';
require 'lib.php';
require 'import_lib.php';
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$back = safe_back($_POST['back'] ?? 'result.php');
$uid  = $_SESSION['user_id'];

// 결과를 알려 주고 원래 화면으로 돌아가는 함수
//   - 보통 요청: 알림 문구를 남기고 원래 화면으로 이동
//   - 대기 화면(자바스크립트)에서 보낸 요청: 결과를 JSON 으로 돌려주면, 화면이 알아서 이동하거나 오류를 보여 줌
function finish($msg, $back, $ok = false) {
    if (!$ok) $_SESSION['flash'] = $msg;
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $msg, 'go' => $back], JSON_UNESCAPED_UNICODE);
        if (!$ok) unset($_SESSION['flash']);      // 오류는 대기 화면에서 바로 보여 주므로 알림으로 남기지 않음
        exit;
    }
    header('Location: ' . $back);
    exit;
}

// ---------- 1. 파일 확인 ----------
$file  = $_FILES['history'] ?? null;
$limit = ini_get('upload_max_filesize');   // 서버(php.ini)가 받을 수 있는 최대 크기 (XAMPP 기본 40M)
if (!$file && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) finish("파일이 너무 커요. ({$limit}B 이하만 올릴 수 있어요. 테이크아웃에서 '기록'만 골라서 받아 주세요)", $back);
if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) finish('올릴 파일을 골라 주세요.', $back);
if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE)
    finish("파일이 너무 커요. ({$limit}B 이하만 올릴 수 있어요. 테이크아웃에서 '기록'만 골라서 받아 주세요)", $back);
if ($file['error'] !== UPLOAD_ERR_OK) finish('파일을 받지 못했어요. 다시 올려 주세요. (오류 번호 ' . $file['error'] . ')', $back);
@set_time_limit(300);                      // 기록이 많은 파일은 시간이 걸리므로 5분까지 기다림
@ini_set('memory_limit', '1024M');

// ---------- 2. 파일 읽기 (어떤 플랫폼 파일인지 자동으로 알아냄) ----------
$got = read_history_file($file['tmp_name'], $file['name']);
if (!is_array($got)) finish($got, $back);
if (!$got['rows']) finish($got['kind'] . ' 파일이지만 들은 곡이 하나도 없어요.', $back);

// ---------- 3. DB의 곡과 연결하고 저장 ----------
$r = save_rows($conn, $uid, $got['rows']);

// ---------- 4. 결과 알려 주기 ----------
// 결과 카드(lib.php 의 upload_result_card)가 보여 줄 내용을 세션에 남깁니다.
if ($r['songs'] === 0 && $r['dup'] > 0) {
    finish("이미 올린 기록이에요. 같은 기록 {$r['dup']}회는 두 번 세지 않고 건너뛰었어요.", $back);
}
if ($r['songs'] === 0) {
    $youtube = str_starts_with($got['kind'], '유튜브');
    finish($youtube
        ? "유튜브 시청 기록 {$r['read']}개에서 음악을 찾지 못했어요. (음악이 아닌 영상 {$r['not_music']}개는 건너뛰었어요)"
        : "파일에서 곡을 찾지 못했어요. 곡 제목과 가수가 들어 있는 파일인지 확인해 주세요. (엑셀이라면 title, artist 칸)", $back);
}
arsort($r['missing']);
$_SESSION['upload_result'] = [
    'kind'       => $got['kind'],
    'file'       => $file['name'],
    'songs'      => $r['songs'],
    'saved'      => $r['saved'],
    'added'      => $r['added'],
    'skipped'    => $r['not_music'] + $r['over_limit'] + array_sum($r['missing']) + $r['dup'],
    'dup'        => $r['dup'],
    'not_music'  => $r['not_music'],
    'over_limit' => $r['over_limit'],
    'top'        => $r['top'],
    'added_list' => array_slice($r['added_list'], 0, 30),
    'missing'    => array_slice(array_keys($r['missing']), 0, 30),
    'missing_n'  => count($r['missing']),
];
finish('ok', $back, true);
