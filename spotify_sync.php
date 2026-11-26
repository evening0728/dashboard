<?php
require 'db.php';
require 'spotify.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

sp_check_post();

$uid = $_SESSION['user_id'];

try {
    sp_schema($conn);

    $action = $_POST['action'] ?? '';

    if ($action === 'withdraw') {
        if (($_POST['confirm'] ?? '') !== 'yes') {
            sp_finish(
                '연결 해제 및 데이터 삭제 확인란을 선택해 주세요.'
            );
        }

        $conn->begin_transaction();

        try {
            /* 이번 기능에서 저장한 본인의 데이터 삭제 */
            foreach (
                ['spotify_user_history', 'spotify_user_links']
                as $table
            ) {
                $stmt = $conn->prepare(
                    "DELETE FROM $table WHERE user_id = ?"
                );

                $stmt->bind_param('s', $uid);
                $stmt->execute();
            }

            /* 이전 버전의 본인 Spotify 연결 정보 정리 */
            $stmt = $conn->prepare(
                'UPDATE users
                 SET spotify_refresh = NULL,
                     spotify_synced = NULL
                 WHERE user_id = ?'
            );

            $stmt->bind_param('s', $uid);
            $stmt->execute();

            /* 이전 버전에서 가져온 본인 Spotify 기록만 삭제 */
            $stmt = $conn->prepare("
                DELETE FROM plays
                WHERE user_id = ?
                  AND (
                    source_key LIKE 'sp:%'
                    OR source_key LIKE 'sptop:%'
                  )
            ");

            $stmt->bind_param('s', $uid);
            $stmt->execute();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        unset($_SESSION['sp_pending']);

        sp_finish(
            '동의를 철회했어요. 이 사이트의 Spotify 연결 정보와 개인 청취 기록을 삭제했어요. Spotify 앱 권한은 아래 링크에서 별도로 해제할 수 있어요.'
        );
    }

    if ($action !== 'sync') {
        sp_finish('지원하지 않는 요청이에요.');
    }

    if (!spotify_ready()) {
        sp_finish('Spotify 연결 준비 중이에요.');
    }

    $user = sp_user($conn, $uid);

    /* 연속 클릭으로 API 요청이 반복되는 것을 줄임 */
    if ($user && $user['synced_at']) {
        $lastSync = new DateTimeImmutable(
            $user['synced_at'],
            new DateTimeZone('UTC')
        );

        if (time() - $lastSync->getTimestamp() < 30) {
            sp_finish(
                '방금 기록을 확인했어요. 30초 후 다시 시도해 주세요.'
            );
        }
    }

    $token = spotify_access_token($conn, $uid);

    sp_finish(
        spotify_sync($conn, $uid, $token)
    );
} catch (Throwable $e) {
    $message = $e instanceof mysqli_sql_exception
        ? 'DB 저장 작업을 완료하지 못했어요. 운영자에게 확인해 주세요.'
        : $e->getMessage();

    sp_finish($message);
}