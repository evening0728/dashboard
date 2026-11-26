<?php
// 설치 파일: 테이블을 만들고, 비어 있을 때만 예시 데이터를 넣습니다.
//   http://localhost/music-dashboard/setup.php           → 이미 있는 데이터는 그대로 둠 (여러 번 열어도 안전)
//   http://localhost/music-dashboard/setup.php?reset=1   → 전부 지우고 처음 상태로
require 'db.php';
$log = [];

// 지금 로그인한 사람이 관리자인지 (테이블이 아직 없으면 false)
function setup_admin($conn) {
    if (!isset($_SESSION['user_id'])) return false;
    try {
        $stmt = $conn->prepare("SELECT is_admin FROM users WHERE user_id = ?");
        $stmt->bind_param('s', $_SESSION['user_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row && (int) $row['is_admin'] === 1;
    } catch (mysqli_sql_exception $e) { return false; }
}

// 전부 지우기(reset)는 관리자로 로그인한 사람만 할 수 있습니다. (공개 서버에서 아무나 지우지 못하게)
if (isset($_GET['reset']) && !setup_admin($conn)) {
    http_response_code(403);
    exit('<meta charset="utf-8"><p>전부 지우기는 관리자(admin)로 로그인한 뒤에만 할 수 있어요.</p><p><a href="login.php">관리자 로그인</a> 후 다시 열어 주세요 · <a href="index.php">메인으로</a></p>');
}
if (isset($_GET['reset'])) {
    foreach (['shares', 'plays', 'artists', 'tracks', 'users'] as $t) $conn->query("DROP TABLE IF EXISTS $t");
    $log[] = '기존 테이블을 모두 지웠습니다.';
}

// ---------- 1. 테이블 만들기 (이미 있으면 건너뜀) ----------
$conn->query("CREATE TABLE IF NOT EXISTS users (
    user_id   VARCHAR(20)  PRIMARY KEY,          -- 아이디
    password  VARCHAR(255) NOT NULL,             -- 암호화된 비밀번호
    name      VARCHAR(30)  NOT NULL,             -- 화면에 보일 이름
    is_admin  TINYINT(1)   NOT NULL DEFAULT 0    -- 1 이면 관리자 (곡 관리 화면 사용 가능)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if (!$conn->query("SHOW COLUMNS FROM users LIKE 'spotify_refresh'")->num_rows) {
    $conn->query("ALTER TABLE users ADD COLUMN spotify_refresh VARCHAR(255) NULL, ADD COLUMN spotify_synced DATETIME NULL");
    $log[] = 'users 테이블에 스포티파이 연결 칸을 추가했습니다.';
}
if (!$conn->query("SHOW COLUMNS FROM users LIKE 'is_admin'")->num_rows) {
    $conn->query("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0");
    $log[] = 'users 테이블에 is_admin(관리자 여부) 칸을 추가했습니다.';
}
$conn->query("CREATE TABLE IF NOT EXISTS artists (
    name   VARCHAR(100) PRIMARY KEY,             -- 가수 이름 (tracks.artist 와 같게)
    image  VARCHAR(255) NOT NULL                 -- 서버에 저장한 사진 파일 경로
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS tracks (
    track_id  INT AUTO_INCREMENT PRIMARY KEY,
    title     VARCHAR(100) NOT NULL,             -- 곡 제목
    artist    VARCHAR(100) NOT NULL,             -- 가수
    genre     VARCHAR(30)  NOT NULL,             -- 장르
    youtube_url VARCHAR(255) NULL                -- 유튜브 주소 (비워 두면 유튜브 검색으로 연결)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// 예전 버전으로 만든 tracks 테이블에는 youtube_url 칸이 없으므로 추가합니다.
if (!$conn->query("SHOW COLUMNS FROM tracks LIKE 'youtube_url'")->num_rows) {
    $conn->query("ALTER TABLE tracks ADD COLUMN youtube_url VARCHAR(255) NULL");
    $log[] = 'tracks 테이블에 youtube_url 칸을 추가했습니다.';
}
$conn->query("CREATE TABLE IF NOT EXISTS plays (
    play_id    INT AUTO_INCREMENT PRIMARY KEY,
    user_id    VARCHAR(20) NOT NULL,
    track_id   INT NOT NULL,
    played_on  DATE NOT NULL,                    -- 들은 날짜
    play_count INT NOT NULL DEFAULT 1,           -- 그날 들은 횟수
    source_key VARCHAR(120) NULL,                -- 스포티파이에서 가져온 기록 구분용 (중복 방지)
    UNIQUE KEY uniq_source (user_id, source_key),
    FOREIGN KEY (user_id)  REFERENCES users(user_id),
    FOREIGN KEY (track_id) REFERENCES tracks(track_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// 예전 버전 DB에 스포티파이용 칸 추가
if (!$conn->query("SHOW COLUMNS FROM plays LIKE 'source_key'")->num_rows) {
    $conn->query("ALTER TABLE plays ADD COLUMN source_key VARCHAR(120) NULL, ADD UNIQUE KEY uniq_source (user_id, source_key)");
    $log[] = 'plays 테이블에 source_key(스포티파이 기록 중복 방지) 칸을 추가했습니다.';
}
$conn->query("CREATE TABLE IF NOT EXISTS shares (
    code       CHAR(8) PRIMARY KEY,              -- 공유 링크에 붙는 코드
    user_id    VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$log[] = '테이블 5개(users, tracks, plays, shares, artists)를 확인했습니다.';

// ---------- 2. 곡이 하나도 없을 때만 예시 30곡 넣기 ----------
$trackCount = $conn->query("SELECT COUNT(*) FROM tracks")->fetch_row()[0];
if ($trackCount == 0) {
    // 실제 가수와 대표곡 (장르 5개, 장르당 40곡 안팎). 곡은 '곡 관리' 화면에서 CSV 파일로 더 넣을 수 있습니다.
    $catalog = [
        ['발라드', '성시경', ['거리에서', '두 사람', '너의 모든 순간', '내게 오는 길', '좋을텐데', '희재']],
        ['발라드', '폴킴', ['모든 날, 모든 순간', '너를 만나', '안녕']],
        ['발라드', '박효신', ['야생화', '눈의 꽃', '숨', '좋은 사람']],
        ['발라드', '김범수', ['보고 싶다', '끝사랑', '지나간다']],
        ['발라드', '이적', ['다행이다', '걱정말아요 그대', '하늘을 달리다', '빨래']],
        ['발라드', '다비치', ['8282', '안녕이라고 말하지마', '이 사랑']],
        ['발라드', '이진아', ['시간아 천천히']],
        ['발라드', '백지영', ['총 맞은 것처럼', '사랑 안해', '잊지 말아요']],
        ['발라드', '정승환', ['너였다면', '눈사람']],
        ['발라드', '케이윌', ['이러지마 제발', '그립고 그립고 그립다']],
        ['발라드', '윤종신', ['좋니', '오래전 그날']],
        ['발라드', '임영웅', ['사랑은 늘 도망가', '이제 나만 믿어요']],
        ['발라드', 'SG워너비', ['내 사람', 'Timeless', '라라라']],
        ['발라드', '엠씨더맥스', ['어디에도', '잠시만 안녕']],
        ['발라드', '버즈', ['가시', '겁쟁이']],
        ['발라드', '에일리', ['첫눈처럼 너에게 가겠다', '보여줄게']],
        ['발라드', '김연우', ['이별택시']],
        ['발라드', '이소라', ['바람이 분다']],
        ['인디', '잔나비', ['주저하는 연인들을 위해', '뜨거운 여름밤은 가고 남은 건 볼품없지만', '꿈과 책과 힘과 벽', '가을밤에 든 생각']],
        ['인디', '10cm', ['봄이 좋냐??', '폰서트', '스토커', '아메리카노', '사랑은 은하수 다방에서', '그라데이션', '서랍']],
        ['인디', '혁오', ['위잉위잉', 'TOMBOY', '와리가리', 'LOVE YA!']],
        ['인디', '검정치마', ['Everything', '나랑 아니면', '좋아해줘']],
        ['인디', '새소년', ['난춘', '긴꿈']],
        ['인디', '버스커 버스커', ['벚꽃 엔딩', '여수 밤바다', '처음엔 사랑이란게']],
        ['인디', '장범준', ['흔들리는 꽃들 속에서 네 샴푸향이 느껴진거야', '노래방에서']],
        ['인디', '볼빨간사춘기', ['우주를 줄게', '여행', '나만, 봄', '썸 탈꺼야', '워커홀릭']],
        ['인디', '데이브레이크', ['들었다 놨다', '꽃길만 걷게 해줄게']],
        ['인디', '브로콜리너마저', ['앵콜요청금지', '졸업']],
        ['인디', '카더가든', ['나무', '명동콜링']],
        ['인디', '옥상달빛', ['수고했어, 오늘도']],
        ['인디', '장기하와 얼굴들', ['싸구려 커피', '별일 없이 산다']],
        ['인디', '자우림', ['스물다섯, 스물하나', '하하하쏭']],
        ['인디', 'DAY6', ['예뻤어', '한 페이지가 될 수 있게', '좀비', 'Welcome to the Show']],
        ['인디', '적재', ['별 보러 가자']],
        ['R&B', '크러쉬', ['가끔', 'Beautiful', '잊어버리지마', '나빠', 'Oasis', 'Rush Hour']],
        ['R&B', '헤이즈', ['비도 오고 그래서', '저 별', '널 너무 모르고', '돌아오지마', '만추', '빙글빙글', 'Jenga']],
        ['R&B', '딘', ['instagram', 'D (half moon)', 'bonnie & clyde']],
        ['R&B', '자이언티', ['양화대교', '꺼내 먹어요', '노래', 'No Make Up', '눈']],
        ['R&B', '백예린', ['Square', '우주를 건너', '그건 아마 우리의 잘못은 아닐 거야', '0310']],
        ['R&B', '박재범', ['몸매', 'All I Wanna Do', '좋아']],
        ['R&B', '비비', ['밤양갱', '나쁜년']],
        ['R&B', '태양', ['눈, 코, 입', '나만 바라봐', '링가링가', 'Wedding Dress']],
        ['R&B', '브라운아이드소울', ['정말 사랑했을까', '비켜줄게']],
        ['R&B', '휘성', ['안되나요', 'With Me', '불치병']],
        ['R&B', '빅마마', ['체념', 'Break Away']],
        ['R&B', '나얼', ['바람기억']],
        ['R&B', '이하이', ['한숨', '누구 없소']],
        ['힙합', '지코', ['아무노래', '새삥', '너는 나 나는 너', '유레카', '사람']],
        ['힙합', '빈지노', ['Aqua Man', 'Dali, Van, Picasso', 'Time Travel', 'Boogie On & On']],
        ['힙합', '다이나믹 듀오', ['출첵', 'Ring My Bell', '고백', '죽일 놈', 'BAAAM']],
        ['힙합', '에픽하이', ['Fly', '우산', 'Love Love Love', '연애소설', '빈차', 'Born Hater']],
        ['힙합', '창모', ['METEOR', '마에스트로']],
        ['힙합', '우원재', ['시차']],
        ['힙합', '로꼬', ['감아', '시간이 들겠지']],
        ['힙합', '비와이', ['Day Day', 'Forever']],
        ['힙합', '이영지', ['Small girl', '나는 이영지']],
        ['힙합', '리쌍', ['헤어지지 못하는 여자, 떠나가지 못하는 남자', '발레리노', 'TV를 껐네']],
        ['힙합', 'MC몽', ['서커스', '아이스크림', '인디언 보이']],
        ['힙합', '드렁큰타이거', ['몬스터', '8:45 Heaven']],
        ['힙합', '버벌진트', ['좋아보여']],
        ['힙합', '산이', ['한여름밤의 꿀']],
        ['힙합', '한요한', ['범퍼카']],
        ['힙합', '애쉬 아일랜드', ['멜로디']],
        ['K-POP', 'BTS', ['Dynamite', 'Butter', '봄날', '피 땀 눈물', 'DNA', '작은 것들을 위한 시']],
        ['K-POP', 'NewJeans', ['Hype Boy', 'Ditto', 'Attention', 'OMG', 'Super Shy']],
        ['K-POP', '아이유', ['좋은 날', '밤편지', 'Blueming', '라일락', '팔레트', '너의 의미']],
        ['K-POP', 'BLACKPINK', ['뚜두뚜두', 'How You Like That', 'Pink Venom', '붐바야']],
        ['K-POP', 'IVE', ['LOVE DIVE', 'After LIKE', 'I AM', 'ELEVEN']],
        ['K-POP', 'aespa', ['Next Level', 'Savage', 'Supernova']],
        ['K-POP', 'LE SSERAFIM', ['ANTIFRAGILE', 'FEARLESS']],
        ['K-POP', '세븐틴', ['아주 NICE', '손오공', '울고 싶지 않아']],
        ['K-POP', 'TWICE', ['TT', 'CHEER UP', 'FANCY']],
        ['K-POP', '빅뱅', ['거짓말', '뱅뱅뱅', '봄여름가을겨울']],
        ['K-POP', '레드벨벳', ['빨간 맛', 'Psycho']],
    ];
    $stmt = $conn->prepare("INSERT INTO tracks (title, artist, genre) VALUES (?, ?, ?)");
    foreach ($catalog as [$genre, $artist, $titles]) {
        foreach ($titles as $title) { $stmt->bind_param('sss', $title, $artist, $genre); $stmt->execute(); }
    }
    $n = array_sum(array_map(fn($c) => count($c[2]), $catalog));
    $log[] = "곡이 없어서 기본 곡 {$n}개를 넣었습니다.";
} else {
    $log[] = "tracks 테이블에 이미 곡이 {$trackCount}개 있어서 그대로 두었습니다.";
}

// ---------- 3. 사용자가 한 명도 없을 때만 체험 계정 넣기 ----------
// 청취 기록은 지금 tracks 에 들어 있는 곡으로 자동으로 만듭니다. (곡을 바꿔도 그대로 동작)
$userCount = $conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
if ($userCount == 0) {
    $byGenre = [];
    foreach ($conn->query("SELECT track_id, genre FROM tracks ORDER BY track_id") as $t) $byGenre[$t['genre']][] = $t['track_id'];
    $genres = array_keys($byGenre);

    $addUser = $conn->prepare("INSERT INTO users (user_id, password, name) VALUES (?, ?, ?)");
    $addPlay = $conn->prepare("INSERT INTO plays (user_id, track_id, played_on, play_count) VALUES (?, ?, ?, ?)");
    $weights = [10, 13, 14, 17, 21, 25];            // 최근 달로 올수록 조금씩 많이
    $favorite = [14, 9, 5, 2, 1];                    // 1순위 장르 곡은 14회, 2순위 9회 …

    // [아이디, 이름, 좋아하는 장르가 시작되는 위치]  newbie 는 청취 기록 없음
    foreach ([['minseong', '민성', 0], ['jiwoo', '지우', 3], ['newbie', '새봄', null]] as [$id, $name, $start]) {
        $hash = password_hash('1234', PASSWORD_DEFAULT);
        $addUser->bind_param('sss', $id, $hash, $name);
        $addUser->execute();
        if ($start === null) continue;

        foreach ($favorite as $rank => $base) {
            if ($rank >= count($genres)) break;
            $songs = $byGenre[$genres[($start + $rank) % count($genres)]];
            array_pop($songs);                       // 장르마다 마지막 곡은 안 들은 곡으로 남김 → 추천 곡이 됨
            foreach ($songs as $j => $tid) {
                $total = $base - $j * 2;
                if ($total <= 0) break;
                $left = $total;
                for ($m = 0; $m < 6; $m++) {         // 최근 6개월에 나눠 넣기
                    $count = ($m == 5) ? $left : (int) floor($total * $weights[$m] / 100);
                    $left -= $count;
                    if ($count <= 0) continue;
                    $date = date('Y-m-15', strtotime('first day of -' . (5 - $m) . ' month'));
                    $addPlay->bind_param('sisi', $id, $tid, $date, $count);
                    $addPlay->execute();
                }
            }
        }
    }
    $log[] = '체험 계정 3개(minseong, jiwoo, newbie / 비밀번호 1234)를 만들었습니다.';
} else {
    $log[] = "users 테이블에 이미 사용자가 {$userCount}명 있어서 그대로 두었습니다.";
}
// ---------- 4. 관리자 계정 (없을 때만) ----------
//   비밀번호는 config.php 의 $ADMIN_PASS. 공개 서버에서 기본값(admin1234)이면 만들지 않습니다.
if (!$conn->query("SELECT 1 FROM users WHERE is_admin = 1")->num_rows) {
    if ($conn->query("SELECT 1 FROM users WHERE user_id = 'admin'")->num_rows) {
        $conn->query("UPDATE users SET is_admin = 1 WHERE user_id = 'admin'");
        $log[] = '이미 있던 admin 계정을 관리자로 지정했습니다.';
    } elseif ($ADMIN_PASS === 'admin1234' && !is_local_host()) {
        $log[] = '관리자 계정을 만들지 않았습니다. config.php 의 $ADMIN_PASS 를 admin1234 가 아닌 비밀번호로 바꾼 뒤 이 화면을 다시 열어 주세요.';
    } else {
        $hash = password_hash($ADMIN_PASS, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (user_id, password, name, is_admin) VALUES ('admin', ?, '관리자', 1)");
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $log[] = is_local_host()
            ? "관리자 계정(admin / {$ADMIN_PASS})을 만들었습니다. 곡 관리 화면은 이 계정으로만 들어갈 수 있습니다."
            : '관리자 계정(admin)을 만들었습니다. 비밀번호는 config.php 에 적은 값입니다.';
    }
}
// ---------- 5. 관리자 비밀번호를 config.php 와 맞추기 ----------
//   config.php 의 $ADMIN_PASS 를 바꾸고 이 화면을 열면, admin 계정 비밀번호도 그 값으로 바뀝니다.
$row = $conn->query("SELECT password FROM users WHERE user_id = 'admin'")->fetch_assoc();
if ($row && !password_verify($ADMIN_PASS, $row['password'])
    && !($ADMIN_PASS === 'admin1234' && !is_local_host())) {
    $hash = password_hash($ADMIN_PASS, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = 'admin'");
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $log[] = '관리자(admin) 비밀번호를 config.php 에 적은 값으로 바꿨습니다.';
}

?>
<meta charset="utf-8">
<h2>설치가 끝났습니다</h2>
<ul><?php foreach ($log as $line): ?><li><?= h($line) ?></li><?php endforeach; ?></ul>
<p><a href="index.php">메인 화면으로 가기</a></p>
<p style="color:gray">전부 지우고 처음부터 다시 하려면 관리자로 로그인한 뒤 <a href="setup.php?reset=1">setup.php?reset=1</a> 을 엽니다. (가입한 계정과 청취 기록, 넣은 곡이 모두 지워집니다)</p>
