<?php
require 'db.php';
require 'spotify.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pending = $_SESSION['sp_pending'] ?? null;
unset($_SESSION['sp_pending']);

$valid =
    $pending
    && is_string($_GET['state'] ?? null)
    && hash_equals($pending['state'], $_GET['state'])
    && $pending['uid'] === $_SESSION['user_id']
    && time() - $pending['time'] <= 600
    && $pending['version'] === SP_CONSENT_VERSION;

if (!$valid) {
    sp_finish(
        '연결 요청이 만료되었거나 올바르지 않아요. 동의 화면에서 다시 시작해 주세요.'
    );
}

if (isset($_GET['error'])) {
    sp_finish(
        'Spotify 연결을 취소했어요. 새 청취 기록은 가져오지 않았어요.'
    );
}

if (
    !spotify_ready()
    || empty($_GET['code'])
    || !is_string($_GET['code'])
) {
    sp_finish('연결에 필요한 정보가 없어요. 다시 시작해 주세요.');
}

$uid = $_SESSION['user_id'];

try {
    sp_schema($conn);

    $token = spotify_token([
        'grant_type' => 'authorization_code',
        'code' => $_GET['code'],
        'redirect_uri' => $SPOTIFY_REDIRECT_URI,
    ]);

    if (
        empty($token['refresh_token'])
        || empty($token['access_token'])
    ) {
        throw new RuntimeException(
            'Spotify 인증 정보를 받지 못했어요. 다시 연결해 주세요.'
        );
    }

    $version = SP_CONSENT_VERSION;

    $conn->begin_transaction();

    try {
        /*
         * 다른 Spotify 계정으로 재연결할 수 있으므로
         * 이전 Spotify 전용 기록을 정리합니다.
         */
        $delete = $conn->prepare(
            'DELETE FROM spotify_user_history WHERE user_id = ?'
        );

        $delete->bind_param('s', $uid);
        $delete->execute();

        $save = $conn->prepare("
            INSERT INTO spotify_user_links
                (user_id, consent_version, consent_at, refresh_token)
            VALUES (?, ?, UTC_TIMESTAMP(), ?)
            ON DUPLICATE KEY UPDATE
                consent_version = VALUES(consent_version),
                consent_at = VALUES(consent_at),
                refresh_token = VALUES(refresh_token),
                synced_at = NULL
        ");

        $save->bind_param(
            'sss',
            $uid,
            $version,
            $token['refresh_token']
        );

        $save->execute();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    try {
        $message = 'Spotify 연결을 완료했어요. '
            . spotify_sync($conn, $uid, $token['access_token']);
    } catch (Throwable $e) {
        $detail = $e instanceof mysqli_sql_exception
            ? 'DB 저장 상태를 확인한 뒤 다시 시도해 주세요.'
            : $e->getMessage();

        $message = '계정 연결은 완료했지만 기록을 가져오지 못했어요. '
            . $detail;
    }

    sp_finish($message);
} catch (Throwable $e) {
    $message = $e instanceof mysqli_sql_exception
        ? '연결 정보를 저장하지 못했어요. 운영자에게 DB 권한을 확인해 달라고 요청해 주세요.'
        : $e->getMessage();

    sp_finish($message);
}