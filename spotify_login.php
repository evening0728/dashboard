<?php
require 'db.php';
require 'spotify.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: spotify_connect.php');
    exit;
}

sp_check_post();

if (($_POST['consent'] ?? '') !== 'yes') {
    sp_finish(
        '데이터 수집·이용에 동의해야 Spotify를 연결할 수 있어요.'
    );
}

if (!spotify_ready()) {
    sp_finish(
        '아직 Spotify 연결 준비 중이에요. 운영자가 앱 설정을 완료한 뒤 이용해 주세요.'
    );
}

/* 현재 접속 주소와 복귀 주소가 같은 사이트인지 확인 */
$redirect = parse_url($SPOTIFY_REDIRECT_URI);

$expectedHost = strtolower(
    ($redirect['host'] ?? '')
    . (
        isset($redirect['port'])
        ? ':' . $redirect['port']
        : ''
    )
);

$expectedPath = rtrim(
    str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])),
    '/'
) . '/spotify_callback.php';

if (
    $expectedHost !== strtolower($_SERVER['HTTP_HOST'] ?? '')
    || rawurldecode($redirect['path'] ?? '')
        !== rawurldecode($expectedPath)
) {
    sp_finish(
        '현재 접속 주소와 Spotify 복귀 주소 설정이 달라요. 운영자가 현재 사이트의 /spotify_callback.php 주소로 설정해야 해요.'
    );
}

/* 동의한 사용자와 연결 요청을 묶어서 보관 */
$_SESSION['sp_pending'] = [
    'state' => bin2hex(random_bytes(32)),
    'uid' => $_SESSION['user_id'],
    'time' => time(),
    'version' => SP_CONSENT_VERSION,
];

$url = $SPOTIFY_ACCOUNTS_URL . '/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id' => $SPOTIFY_CLIENT_ID,
    'scope' => 'user-read-recently-played',
    'redirect_uri' => $SPOTIFY_REDIRECT_URI,
    'state' => $_SESSION['sp_pending']['state'],
    'show_dialog' => 'true',
]);

header('Location: ' . $url);
exit;