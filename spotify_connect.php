<?php
require 'db.php';
require 'lib.php';
require 'spotify.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$uid = $_SESSION['user_id'];
$user = null;
$rows = [];
$storageError = false;

try {
    sp_schema($conn);
    $user = sp_user($conn, $uid);

    $stmt = $conn->prepare(
        'SELECT track_id, title, artist, played_at
         FROM spotify_user_history
         WHERE user_id = ?
         ORDER BY played_at DESC
         LIMIT 200'
    );

    $stmt->bind_param('s', $uid);
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    $storageError = true;
}

$ready = spotify_ready() && !$storageError;

$linked =
    $user
    && !empty($user['refresh_token'])
    && $user['consent_version'] === SP_CONSENT_VERSION;

$csrf = sp_csrf();
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Spotify 연결 및 기록 관리</title>

    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="spotify_connect.css?v=3">
</head>
<body>
<main class="sp-page">

    <nav class="sp-nav">
        <a href="result.php">← 내 대시보드</a>
        <span><?= h($_SESSION['name'] ?? $uid) ?>님</span>
        <a href="logout.php">로그아웃</a>
    </nav>

    <?php flash(); ?>

    <section class="sp-hero">
        <span class="sp-kicker">MY MUSIC · SPOTIFY</span>

        <h1>
            내가 들은 음악을<br>
            한곳에서 확인하세요.
        </h1>

        <p>
            데이터 수집 안내를 확인하고 내 Spotify 계정을 연결하세요.<br>
            최근 청취 기록을 가져와 나만의 기록 목록에서 볼 수 있어요.
        </p>

        <ol class="sp-steps">
            <li>1. 데이터 수집 동의</li>
            <li>2. Spotify 로그인·허용</li>
            <li>3. 최근 기록 확인</li>
        </ol>
    </section>

    <?php if ($storageError): ?>
        <p class="sp-alert" role="alert">
            기록 저장 공간을 준비하지 못했어요.
            운영자가 DB의 테이블 생성 권한을 확인해야 해요.
        </p>
    <?php elseif (!$ready): ?>
        <p class="sp-alert" role="status">
            <b>Spotify 연결 준비 중</b><br>
            운영자가 연결 설정을 완료하면 이용할 수 있어요.
            아래 안내는 미리 확인할 수 있고,
            지금은 Spotify로 이동하거나 데이터를 가져오지 않아요.
        </p>
    <?php endif; ?>

    <div class="sp-columns">

        <section class="sp-card" aria-labelledby="consent-title">
            <span class="sp-label">데이터 수집·이용 안내</span>

            <h2 id="consent-title">연결 전에 확인해 주세요</h2>

            <dl class="sp-policy">
                <dt>수집·이용 주체</dt>
                <dd>
                    이 음악 대시보드를 운영하는 팀 프로젝트 운영팀
                </dd>

                <dt>목적</dt>
                <dd>
                    사용자가 요청한 Spotify 최근 청취 기록을 가져와
                    본인의 기록 목록에 표시하고,
                    계정 연결 상태를 관리합니다.
                </dd>

                <dt>수집 항목</dt>
                <dd>
                    곡 제목, 가수명, Spotify 곡 ID, 청취 시각,
                    연결용 인증 토큰, 이 사이트의 사용자 ID,
                    동의 시각·안내문 버전, 마지막 가져오기 시각
                </dd>

                <dt>수집하지 않는 정보</dt>
                <dd>
                    이 기능은 Spotify 비밀번호·결제정보를 받지 않으며,
                    이메일·위치·기기 정보 조회 권한을 요청하지 않습니다.
                </dd>

                <dt>보관 기간</dt>
                <dd>
                    이 사이트에서 ‘동의 철회 및 데이터 삭제’를
                    실행할 때까지 보관합니다.
                    해당 기능을 실행하면 개인 Spotify 기록,
                    연결용 토큰, 동의 내역을 DB에서 삭제합니다.
                </dd>

                <dt>이용 범위</dt>
                <dd>
                    로그인한 본인의 기록 화면에 표시합니다.
                    Spotify 기록은 기존 추천·장르 통계·공유 팜플렛·
                    AI 학습에 사용하지 않습니다.
                    일반 사용자는 다른 사용자의 기록을 조회할 수 없습니다.
                    서버·DB를 관리하는 운영자는 유지보수를 위해
                    접근할 수 있습니다.
                </dd>

                <dt>외부 연결</dt>
                <dd>
                    로그인·권한 허용과 기록 요청은 Spotify 서버를 통해
                    처리됩니다.
                    공개 사이트를 Cloudflare로 제공하는 경우
                    웹 요청이 해당 서비스를 경유합니다.
                </dd>

                <dt>거부 및 철회</dt>
                <dd>
                    동의를 거부할 수 있으며,
                    이 경우 Spotify 연결 기능만 이용할 수 없습니다.
                    기존 대시보드는 계속 이용할 수 있습니다.
                    아래 삭제 기능으로 동의를 철회할 수 있고,
                    Spotify 쪽 앱 접근 권한은
                    <a
                        href="https://www.spotify.com/account/apps/"
                        target="_blank"
                        rel="noopener noreferrer"
                    >Spotify 앱 관리</a>에서 별도로 해제할 수 있습니다.
                </dd>
            </dl>

            <p class="sp-muted">
                안내문 버전 <?= h(SP_CONSENT_VERSION) ?><br>
                문의: 이 사이트를 안내한 팀 프로젝트 운영자
            </p>

            <form
                method="post"
                action="spotify_login.php"
                class="sp-form"
            >
                <input
                    type="hidden"
                    name="csrf"
                    value="<?= h($csrf) ?>"
                >

                <?php if ($linked): ?>
                    <p class="sp-alert">
                        다시 연결을 완료하면 이전 Spotify 전용 기록을
                        지우고 새로 연결한 계정의 최근 기록을 가져옵니다.
                    </p>
                <?php endif; ?>

                <label class="sp-checkbox">
                    <input
                        type="checkbox"
                        name="consent"
                        value="yes"
                        required
                    >

                    <span>
                        [Spotify 연결 시 필수]
                        위 데이터 수집·이용 안내를 확인하고 동의합니다.
                    </span>
                </label>

                <button
                    class="sp-button"
                    type="submit"
                    <?= !$ready ? 'disabled' : '' ?>
                >
                    <?php
                    if (!$ready) {
                        echo '연결 준비 중';
                    } elseif ($linked) {
                        echo '동의하고 Spotify 다시 연결';
                    } else {
                        echo '동의하고 Spotify로 이동';
                    }
                    ?>
                </button>

                <a class="sp-cancel" href="result.php">
                    연결하지 않고 돌아가기
                </a>

                <p class="sp-muted">
                    다음 화면에서 Spotify가 요청 권한을 다시 안내합니다.
                    비밀번호는 Spotify 공식 로그인 화면에서만 입력하세요.
                </p>
            </form>
        </section>

        <aside>
            <section class="sp-card">
                <span class="sp-label">내 연결 상태</span>

                <h2>
                    <?= $linked
                        ? 'Spotify 연결됨'
                        : '아직 연결하지 않았어요'
                    ?>
                </h2>

                <p>
                    최근 가져오기<br>
                    <strong>
                        <?= h(sp_kst($user['synced_at'] ?? null)) ?>
                    </strong>
                </p>

                <?php if ($user): ?>
                    <p class="sp-muted">
                        수집 동의:
                        <?= h(sp_kst($user['consent_at'])) ?>
                        (한국 시간)
                    </p>
                <?php endif; ?>

                <?php if ($linked): ?>
                    <form method="post" action="spotify_sync.php">
                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= h($csrf) ?>"
                        >
                        <input
                            type="hidden"
                            name="action"
                            value="sync"
                        >

                        <button
                            class="sp-button"
                            <?= !$ready ? 'disabled' : '' ?>
                        >
                            최근 기록 가져오기
                        </button>
                    </form>
                <?php endif; ?>

                <p class="sp-muted">
                    한 번에 최근 최대 50건을 요청합니다.
                    전체 이용 이력이나 실제로 들은 총시간은
                    제공되지 않습니다.
                    같은 청취 기록은 중복 저장하지 않습니다.
                </p>
            </section>

            <section class="sp-card">
                <h2>연결 해제와 기록 삭제</h2>

                <p>
                    이 사이트에 저장한 내 Spotify 기록과 인증 정보를
                    삭제해요. JSON으로 올린 기록과
                    Spotify 원본 기록은 유지돼요.
                </p>

                <form method="post" action="spotify_sync.php">
                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= h($csrf) ?>"
                    >
                    <input
                        type="hidden"
                        name="action"
                        value="withdraw"
                    >

                    <label class="sp-checkbox">
                        <input
                            type="checkbox"
                            name="confirm"
                            value="yes"
                            required
                        >

                        <span>
                            내 Spotify 연결 정보와 기록 삭제에 동의합니다.
                        </span>
                    </label>

                    <button
                        class="sp-button sp-danger"
                        <?= $storageError ? 'disabled' : '' ?>
                    >
                        동의 철회 및 데이터 삭제
                    </button>
                </form>

                <a
                    class="sp-cancel"
                    href="https://www.spotify.com/account/apps/"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Spotify 앱 접근 권한 관리 ↗
                </a>
            </section>

            <section class="sp-card">
                <h2>연결이 안 되나요?</h2>

                <p>
                    현재는 팀 프로젝트 테스트 서비스예요.
                    운영자가 Spotify 개발자 앱의 허용 사용자로 등록한
                    계정만 데이터를 가져올 수 있어요.
                </p>

                <p class="sp-muted">
                    로그인은 됐지만 접근이 거절되면 운영자에게
                    Spotify 계정의 허용 목록 등록을 요청해 주세요.
                </p>
            </section>
        </aside>
    </div>

    <section class="sp-card">
        <span class="sp-label">MY LISTENING HISTORY</span>
        <h2>내 Spotify 청취 기록</h2>

        <p class="sp-muted">
            출처: Spotify · 최신 200건 표시 ·
            청취 시각은 한국 시간입니다.
        </p>

        <?php if (!$rows): ?>
            <div class="sp-empty">
                <b>아직 가져온 기록이 없어요.</b>
                <p>
                    계정을 연결하면 여기에 곡 제목,
                    가수, 청취 시각이 표시돼요.
                </p>
            </div>
        <?php else: ?>
            <div class="sp-table-wrap">
                <table class="sp-table">
                    <thead>
                    <tr>
                        <th>곡 / 가수</th>
                        <th>청취 시각</th>
                        <th>원본</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <strong><?= h($row['title']) ?></strong>
                                <br>
                                <span class="sp-muted">
                                    <?= h($row['artist']) ?>
                                </span>
                            </td>

                            <td>
                                <?= h(sp_kst($row['played_at'])) ?>
                            </td>

                            <td>
                                <a
                                    href="https://open.spotify.com/track/<?= h($row['track_id']) ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Spotify에서 열기 ↗
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

</main>
</body>
</html>