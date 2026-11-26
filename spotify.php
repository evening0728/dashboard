<?php
require_once __DIR__ . '/spotify_config.php';

const SP_CONSENT_VERSION = '2026-11-19-v1';

/* Spotify 앱 설정 여부 */
function spotify_ready() {
    global $SPOTIFY_CLIENT_ID;
    global $SPOTIFY_CLIENT_SECRET;
    global $SPOTIFY_REDIRECT_URI;

    return trim($SPOTIFY_CLIENT_ID ?? '') !== ''
        && trim($SPOTIFY_CLIENT_SECRET ?? '') !== ''
        && trim($SPOTIFY_REDIRECT_URI ?? '') !== '';
}

/* 기존 테이블과 별도로 연결 정보와 개인 기록을 저장 */
function sp_schema($conn) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS spotify_user_links (
            user_id VARCHAR(20) PRIMARY KEY,
            consent_version VARCHAR(30) NOT NULL,
            consent_at DATETIME NOT NULL,
            refresh_token TEXT NULL,
            synced_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS spotify_user_history (
            user_id VARCHAR(20) NOT NULL,
            event_key CHAR(64) NOT NULL,
            track_id VARCHAR(64) NOT NULL,
            title VARCHAR(255) NOT NULL,
            artist VARCHAR(500) NOT NULL,
            played_at DATETIME(3) NOT NULL,
            PRIMARY KEY (user_id, event_key),
            INDEX by_user_time (user_id, played_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/* 현재 사용자의 연결 정보 */
function sp_user($conn, $uid) {
    $stmt = $conn->prepare(
        'SELECT * FROM spotify_user_links WHERE user_id = ?'
    );

    $stmt->bind_param('s', $uid);
    $stmt->execute();

    return $stmt->get_result()->fetch_assoc();
}

/* 위조 요청 방지용 토큰 */
function sp_csrf() {
    if (empty($_SESSION['sp_csrf'])) {
        $_SESSION['sp_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['sp_csrf'];
}

function sp_check_post() {
    $valid =
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && is_string($_POST['csrf'] ?? null)
        && hash_equals(sp_csrf(), $_POST['csrf']);

    if (!$valid) {
        http_response_code(403);
        exit('요청이 만료되었어요. 이전 화면을 새로고침하고 다시 시도해 주세요.');
    }
}

/* 안내 메시지를 남기고 연결 화면으로 이동 */
function sp_finish($message) {
    $_SESSION['flash'] = $message;
    header('Location: spotify_connect.php');
    exit;
}

/* Spotify 서버 요청 */
function spotify_http($method, $url, $headers = [], $body = null) {
    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            '서버의 PHP cURL 확장을 켜 주세요.'
        );
    }

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
    ]);

    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($raw === false) {
        throw new RuntimeException(
            'Spotify와 연결하지 못했어요. 서버의 인터넷 및 인증서 설정을 확인해 주세요.'
        );
    }

    $data = json_decode($raw, true);

    if ($status === 401) {
        throw new RuntimeException(
            'Spotify 인증이 만료되었어요. 다시 연결해 주세요.',
            401
        );
    }

    if ($status === 403) {
        throw new RuntimeException(
            'Spotify가 접근을 허용하지 않았어요. 운영자에게 테스트 사용자 허용 목록과 앱 권한을 확인해 달라고 요청해 주세요.',
            403
        );
    }

    if ($status === 429) {
        throw new RuntimeException(
            'Spotify 요청 한도에 도달했어요. 잠시 후 다시 시도해 주세요.',
            429
        );
    }

    if ($status < 200 || $status >= 300 || !is_array($data)) {
        throw new RuntimeException(
            'Spotify 요청을 완료하지 못했어요. 다시 시도하거나 계정을 재연결해 주세요. (HTTP '
            . $status . ')',
            $status
        );
    }

    return $data;
}

/* 인증 코드 또는 갱신 토큰으로 접근 토큰 발급 */
function spotify_token($params) {
    global $SPOTIFY_CLIENT_ID;
    global $SPOTIFY_CLIENT_SECRET;
    global $SPOTIFY_ACCOUNTS_URL;

    return spotify_http(
        'POST',
        $SPOTIFY_ACCOUNTS_URL . '/api/token',
        [
            'Authorization: Basic ' . base64_encode(
                $SPOTIFY_CLIENT_ID . ':' . $SPOTIFY_CLIENT_SECRET
            ),
            'Content-Type: application/x-www-form-urlencoded',
        ],
        http_build_query($params)
    );
}

function spotify_get($path, $token) {
    global $SPOTIFY_API_URL;

    return spotify_http(
        'GET',
        $SPOTIFY_API_URL . $path,
        ['Authorization: Bearer ' . $token]
    );
}

/* API 응답에서 필요한 정보만 추출 */
function sp_rows($items) {
    $rows = [];

    foreach ($items as $item) {
        $track = $item['track'] ?? [];
        $id = $track['id'] ?? '';

        if (
            !is_string($id)
            || !preg_match('/^[A-Za-z0-9]{22}$/D', $id)
            || empty($track['name'])
            || empty($item['played_at'])
        ) {
            continue;
        }

        try {
            $time = new DateTimeImmutable($item['played_at']);
        } catch (Exception $e) {
            continue;
        }

        $utc = $time
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.v');

        $artists = array_column($track['artists'] ?? [], 'name');

        $rows[] = [
            'key' => hash('sha256', $id . '|' . $utc),
            'id' => $id,
            'title' => mb_substr($track['name'], 0, 255),
            'artist' => mb_substr(implode(', ', $artists), 0, 500),
            'time' => $utc,
        ];
    }

    return $rows;
}

/* 저장된 갱신 토큰으로 접근 토큰 갱신 */
function spotify_access_token($conn, $uid) {
    $user = sp_user($conn, $uid);

    if (
        !$user
        || $user['consent_version'] !== SP_CONSENT_VERSION
        || empty($user['refresh_token'])
    ) {
        throw new RuntimeException(
            '동의 화면에서 Spotify 계정을 먼저 연결해 주세요.'
        );
    }

    $token = spotify_token([
        'grant_type' => 'refresh_token',
        'refresh_token' => $user['refresh_token'],
    ]);

    if (empty($token['access_token'])) {
        throw new RuntimeException(
            '인증 정보를 받지 못했어요. 다시 연결해 주세요.'
        );
    }

    if (!empty($token['refresh_token'])) {
        $stmt = $conn->prepare(
            'UPDATE spotify_user_links
             SET refresh_token = ?
             WHERE user_id = ?'
        );

        $stmt->bind_param('ss', $token['refresh_token'], $uid);
        $stmt->execute();
    }

    return $token['access_token'];
}

/* 최근 기록을 본인 전용 테이블에 저장 */
function spotify_sync($conn, $uid, $token) {
    $data = spotify_get(
        '/me/player/recently-played?limit=50',
        $token
    );

    if (!isset($data['items']) || !is_array($data['items'])) {
        throw new RuntimeException(
            '청취 기록 응답을 읽지 못했어요.'
        );
    }

    $rows = sp_rows($data['items']);
    $newCount = 0;

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            INSERT INTO spotify_user_history
                (user_id, event_key, track_id, title, artist, played_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                artist = VALUES(artist)
        ");

        foreach ($rows as $row) {
            $stmt->bind_param(
                'ssssss',
                $uid,
                $row['key'],
                $row['id'],
                $row['title'],
                $row['artist'],
                $row['time']
            );

            $stmt->execute();

            if ($stmt->affected_rows === 1) {
                $newCount++;
            }
        }

        $stamp = $conn->prepare(
            'UPDATE spotify_user_links
             SET synced_at = UTC_TIMESTAMP()
             WHERE user_id = ?'
        );

        $stamp->bind_param('s', $uid);
        $stamp->execute();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    if (!$rows) {
        return '가져올 최근 청취 기록이 없어요. Spotify에서 음악을 들은 후 다시 시도해 주세요.';
    }

    return '최근 기록을 확인했어요. 새 기록 '
        . $newCount
        . '건을 저장했고 같은 기록은 중복 저장하지 않았어요.';
}

/* DB의 UTC 시간을 한국 시간으로 표시 */
function sp_kst($utc) {
    if (!$utc) {
        return '아직 가져오지 않음';
    }

    return (new DateTimeImmutable(
        $utc,
        new DateTimeZone('UTC')
    ))
        ->setTimezone(new DateTimeZone('Asia/Seoul'))
        ->format('Y-m-d H:i');
}

/* 기존 result.php에서 호출하는 함수 이름 유지 */
function spotify_box($conn, $uid) {
    ?>
    <section
        class="panel"
        style="margin:20px 0;padding:24px;border-left:5px solid #1db954"
    >
        <h2 style="margin:0 0 8px">Spotify 청취 기록 연결</h2>

        <p>
            내 Spotify 계정으로 로그인하고 최근 들은 음악을 확인해 보세요.
        </p>

        <a class="btn primary" href="spotify_connect.php">
            Spotify 연결 및 기록 관리
        </a>

        <p class="small muted">
            <?= spotify_ready()
                ? '데이터 수집 안내를 확인한 후 연결할 수 있어요.'
                : '현재 연결 준비 중이에요. 데이터 수집 안내 화면은 미리 확인할 수 있어요.'
            ?>
        </p>
    </section>
    <?php
}