<?php
// 청취 기록 가져오기 공통 함수 모음
//   - 여러 플랫폼의 파일을 읽어서 "제목·가수·횟수·날짜" 한 가지 모양(행)으로 바꿉니다.
//   - 바꾼 행을 DB의 곡과 연결하고, DB에 없는 곡은 새로 추가한 뒤 plays 테이블에 저장합니다.
//
// 행(row) 모양
//   ['title' => 곡 제목, 'artist' => 가수(모르면 ''), 'hint' => 채널 이름 등 참고용,
//    'count' => 들은 횟수, 'date' => 'Y-m-d', 'music' => 음악이 확실한지(true면 DB에 없을 때 새 곡으로 추가),
//    'url' => 유튜브 주소(있으면), 'src' => 어느 플랫폼 기록인지(yt·sp·csv·json)]

const NEW_SONG_LIMIT = 3000;    // 한 번에 새로 추가할 수 있는 곡 수 (너무 큰 파일로 DB가 넘치지 않게)
const UNKNOWN_GENRE  = '기타';   // 장르를 알 수 없는 새 곡의 장르

// 비교용 글자 정리: 소문자로 바꾸고 글자와 숫자만 남깁니다.  "Shape of You!" → "shapeofyou"
function norm($s) {
    return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $s, 'UTF-8'));
}

// 글자가 UTF-8 이 아니면(엑셀 CSV 등) 한글 윈도우 인코딩(CP949)으로 보고 바꿉니다.
function to_utf8($text) {
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);                 // BOM 제거
    if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) return mb_convert_encoding($text, 'UTF-8', 'UTF-16');
    return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'CP949');
}

// 앞뒤의 따옴표·기호를 지웁니다. (PHP 의 trim 은 한글을 깨뜨릴 수 있어서 정규식으로 처리)
function trim_marks($s) {
    return preg_replace('/^[\s\'"‘’“”\-–—_|·]+|[\s\'"‘’“”\-–—_|·]+$/u', '', (string) $s);
}

function ymd($time) {
    return $time ? date('Y-m-d', $time) : date('Y-m-d');
}

// =====================================================================
// 1. 파일 읽기: 어떤 파일인지 알아내서 행 목록으로 바꿉니다.
//    돌려주는 값: ['kind' => '파일 종류 이름', 'rows' => [...]]  또는  오류 문구(문자열)
// =====================================================================
function read_history_file($path, $name) {
    $bytes = file_get_contents($path);
    if ($bytes === false || $bytes === '') return '빈 파일이에요.';
    if (str_starts_with($bytes, "PK")) return read_zip($path);              // zip 파일
    return read_history_text(to_utf8($bytes), $name);
}

// 압축 파일 안에서 청취 기록 파일들을 찾아 모두 읽습니다. (구글 테이크아웃·스포티파이 압축 파일 그대로 올려도 됨)
function read_zip($path) {
    $files = zip_entries($path);
    if (!is_array($files)) return $files;
    $rows = []; $kinds = [];
    foreach ($files as $name => $read) {
        $base = basename(str_replace('\\', '/', $name));
        // 청취 기록이 들어 있는 파일 이름만 고릅니다.
        if (!preg_match('/^(watch-history|시청\s?기록)\.(json|html)$/iu', $base)
         && !preg_match('/^(StreamingHistory|Streaming_History_Audio).*\.json$/i', $base)
         && !preg_match('/play.?(history|activity).*\.csv$/i', $base)) continue;
        $got = read_history_text(to_utf8($read()), $base);
        if (is_array($got)) { $rows = array_merge($rows, $got['rows']); $kinds[$got['kind']] = true; }
    }
    if (!$rows) return 'zip 안에서 청취 기록 파일을 찾지 못했어요. (유튜브: 시청 기록.json/html, 스포티파이: StreamingHistory….json)';
    return ['kind' => implode(' + ', array_keys($kinds)) . ' (zip)', 'rows' => $rows];
}

// zip 안의 파일 목록: [파일 이름 => 내용을 꺼내는 함수]
//   PHP 에 zip 기능(ZipArchive)이 켜져 있으면 그것을 쓰고, 꺼져 있으면(XAMPP 기본) 직접 풉니다.
function zip_entries($path) {
    $max = 80 * 1024 * 1024;                     // 파일 하나가 80MB 넘으면 건너뜀
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return 'zip 파일을 열지 못했어요.';
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if ($zip->statIndex($i)['size'] > $max) continue;
            $out[$zip->getNameIndex($i)] = fn() => $zip->getFromIndex($i);
        }
        return $out;
    }
    if (!function_exists('gzinflate')) return '이 서버에서는 zip 파일을 풀 수 없어요. 압축을 풀고 안의 기록 파일만 올려 주세요.';
    // zip 파일 구조: 맨 끝의 "목차 끝" 기록 → 목차(central directory) → 파일마다 실제 데이터
    $data = file_get_contents($path);
    $end  = strrpos(substr($data, -66000), "PK\x05\x06");
    if ($end === false) return 'zip 파일을 열지 못했어요.';
    $end += max(0, strlen($data) - 66000);
    $e = unpack('vdisk/vcdisk/ventries/vtotal/Vsize/Voffset', substr($data, $end + 4, 16));
    $pos = $e['offset']; $out = [];
    for ($i = 0; $i < $e['total'] && substr($data, $pos, 4) === "PK\x01\x02"; $i++) {
        $h = unpack('vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen/vclen/vdisk/vin/Vex/Vlocal', substr($data, $pos + 8, 38));
        $name = substr($data, $pos + 46, $h['nlen']);
        if (!($h['flag'] & 0x800) && !mb_check_encoding($name, 'UTF-8')) $name = mb_convert_encoding($name, 'UTF-8', 'CP949');
        $pos += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
        if ($h['size'] > $max || !in_array($h['method'], [0, 8], true)) continue;   // 0 = 압축 안 함, 8 = deflate
        $out[$name] = function () use ($data, $h) {
            $l = unpack('vnlen/velen', substr($data, $h['local'] + 26, 4));
            $raw = substr($data, $h['local'] + 30 + $l['nlen'] + $l['elen'], $h['csize']);
            return $h['method'] === 8 ? (string) @gzinflate($raw) : $raw;
        };
    }
    return $out ?: 'zip 파일을 열지 못했어요.';
}

function read_history_text($text, $name) {
    $trim = ltrim($text);
    if ($trim === '') return '빈 파일이에요.';
    if ($trim[0] === '[' || $trim[0] === '{') {
        $data = json_decode($trim, true);
        if (!is_array($data)) return 'JSON 형식이 아니에요. 파일 내용을 확인해 주세요. (' . json_last_error_msg() . ')';
        return read_json($data);
    }
    if (stripos($trim, '<html') !== false || stripos($trim, 'outer-cell') !== false) return read_takeout_html($text);
    return read_csv($text);
}

// ---------- JSON: 유튜브 테이크아웃 / 스포티파이 / 간단 형식 ----------
function read_json($data) {
    if (isset($data['rows']) && is_array($data['rows'])) $data = $data['rows'];   // {"rows":[...]} 형태
    $first = null;
    foreach ($data as $r) if (is_array($r)) { $first = $r; break; }
    if (!$first) return '파일에 기록이 없어요.';
    $rows = [];

    // 스포티파이 "계정 데이터" (StreamingHistory_music_0.json)
    if (isset($first['trackName'], $first['artistName'])) {
        foreach ($data as $r) {
            if (!is_array($r) || ($r['msPlayed'] ?? 30000) < 30000) continue;     // 30초 미만은 들은 것으로 안 봄
            $rows[] = ['title' => $r['trackName'] ?? '', 'artist' => $r['artistName'] ?? '', 'hint' => '',
                       'count' => 1, 'date' => ymd(strtotime($r['endTime'] ?? '')), 'music' => true, 'url' => null, 'src' => 'sp'];
        }
        return ['kind' => '스포티파이 기록', 'rows' => $rows];
    }
    // 스포티파이 "전체 스트리밍 기록" (Streaming_History_Audio_….json)
    if (array_key_exists('master_metadata_track_name', $first) || isset($first['ms_played'])) {
        foreach ($data as $r) {
            if (!is_array($r) || empty($r['master_metadata_track_name']) || ($r['ms_played'] ?? 30000) < 30000) continue;
            $rows[] = ['title' => $r['master_metadata_track_name'], 'artist' => $r['master_metadata_album_artist_name'] ?? '',
                       'hint' => '', 'count' => 1, 'date' => ymd(strtotime($r['ts'] ?? '')), 'music' => true, 'url' => null, 'src' => 'sp'];
        }
        return ['kind' => '스포티파이 전체 기록', 'rows' => $rows];
    }
    // 유튜브 시청 기록 (구글 테이크아웃 JSON)
    if (isset($first['titleUrl']) || isset($first['header']) || isset($first['subtitles'])) {
        foreach ($data as $r) {
            if (!is_array($r) || !isset($r['title'])) continue;
            $row = youtube_row($r['title'], $r['subtitles'][0]['name'] ?? '', $r['header'] ?? '', $r['titleUrl'] ?? '', strtotime($r['time'] ?? ''));
            if ($row) $rows[] = $row;
        }
        return ['kind' => '유튜브 시청 기록', 'rows' => $rows];
    }
    // 간단 형식 [{"title":"밤편지","artist":"아이유","count":3,"date":"2026-11-01"}]
    foreach ($data as $r) {
        if (!is_array($r) || !isset($r['title'])) continue;
        $artist = trim((string) ($r['artist'] ?? ''));
        $rows[] = ['title' => (string) $r['title'], 'artist' => $artist, 'hint' => $artist,
                   'count' => (int) ($r['count'] ?? 1), 'date' => ymd(strtotime((string) ($r['date'] ?? ''))),
                   'music' => $artist !== '', 'url' => null, 'src' => 'json'];
    }
    return $rows ? ['kind' => '간단 형식', 'rows' => $rows] : '이 JSON 파일은 어떤 형식인지 알 수 없어요.';
}

// ---------- 구글 테이크아웃 HTML (기본 설정으로 받으면 이 형식) ----------
function read_takeout_html($html) {
    $rows = [];
    $cells = preg_split('/class="outer-cell/u', $html);
    array_shift($cells);
    foreach ($cells as $cell) {
        $header = preg_match('~mdl-typography--title">(.*?)<br~su', $cell, $m) ? trim(strip_tags($m[1])) : '';
        if (!preg_match('~<a href="(https://(?:www|music)\.youtube\.com/watch\?v=[^"]+)">(.*?)</a>~su', $cell, $v)) continue;
        $channel = preg_match('~<a href="https://www\.youtube\.com/channel/[^"]+">(.*?)</a>~su', $cell, $c) ? $c[1] : '';
        $time = 0;
        if (preg_match('/(\d{4})\.\s*(\d{1,2})\.\s*(\d{1,2})\./u', $cell, $d)) $time = mktime(12, 0, 0, $d[2], $d[3], $d[1]);   // 2026. 11. 6. 오후 …
        elseif (preg_match('/([A-Z][a-z]{2} \d{1,2}, \d{4})/', $cell, $d)) $time = strtotime($d[1]);                    // Nov 6, 2026, …
        $row = youtube_row(html_entity_decode(strip_tags($v[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                           html_entity_decode(strip_tags($channel), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                           $header, html_entity_decode($v[1]), $time);
        if ($row) $rows[] = $row;
    }
    return $rows ? ['kind' => '유튜브 시청 기록(HTML)', 'rows' => $rows] : 'HTML 파일에서 시청 기록을 찾지 못했어요.';
}

// 유튜브 기록 한 줄 → 행. 음악인지도 판단합니다.
//   - 유튜브 뮤직 앱에서 들은 기록, "가수 - Topic" 채널 → 음악 확실 (제목 = 곡 제목, 채널 = 가수)
//   - 일반 유튜브의 뮤직비디오: "가수 - 제목 (Official MV)" 같은 제목이면 가수와 제목을 나눠서 음악으로 봄
//   - 그 밖의 영상(브이로그·게임 등)은 DB의 곡과 맞을 때만 기록하고, 새 곡으로 추가하지 않음
function youtube_row($title, $channel, $header, $url, $time) {
    $title = trim(preg_replace('/^(Watched|Listened to)\s+/i', '', (string) $title));
    $title = preg_replace('/\s*을\(를\)\s*시청했습니다\.?$|\s*(을|를)\s*시청했습니다\.?$/u', '', $title);
    if ($title === '' || preg_match('~^https?://~', $title)) return null;           // 삭제된 영상은 제목이 주소로 남음
    $channel = trim((string) $channel);
    $row = ['title' => $title, 'artist' => '', 'hint' => $channel, 'count' => 1, 'date' => ymd($time),
            'music' => false, 'url' => $url ?: null, 'src' => 'yt'];

    $topic = preg_match('/^(.*?)\s*-\s*Topic$/u', $channel, $m) ? trim($m[1]) : '';
    if (stripos((string) $header, 'YouTube Music') !== false || $topic !== '') {
        $row['artist'] = $topic !== '' ? $topic : $channel;
        $row['music']  = $row['artist'] !== '';
        return $row;
    }
    // 뮤직비디오 제목 나누기
    $tag = '/(\bM\/?V\b|뮤직\s?비디오|Official\s+(Music\s+)?(Video|Audio|Visualizer)|Lyric|가사|Live\s?Clip|Special\s+(Clip|Video)|Performance\s+Video|Color\s+Coded)/iu';
    if (preg_match($tag, $title)) {
        $clean = preg_replace('/\[[^\]]*\]|【[^】]*】|［[^］]*］|\([^)]*(M\/?V|Official|Lyric|가사|Audio|Video|Clip|Visualizer|Eng|Sub)[^)]*\)/iu', '', $title);
        $clean = trim_marks(preg_replace('/\s+/u', ' ', $clean));
        $artist = $song = '';
        if (preg_match('/^(.+?)\s*[\'‘’"“”](.+?)[\'‘’"“”]/u', $clean, $p)) {          // NewJeans (뉴진스) 'Hype Boy' Official MV
            [$artist, $song] = [$p[1], $p[2]];
        } elseif (preg_match('/^(.+?)\s+[-–—_]\s+(.+)$/u', $clean, $p)) {            // 잔나비 - 주저하는 연인들을 위해
            [$artist, $song] = [$p[1], trim_marks($p[2])];
            $song = trim(preg_replace('/\s*(M\/?V|Official.*|Lyrics?.*)$/iu', '', $song));
        }
        $artist = trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', trim_marks($artist)));   // "DAY6 (데이식스)" → "DAY6"
        $song   = trim(preg_replace('/\s*\(feat\.[^)]*\)$/iu', '', $song));
        if ($artist !== '' && $song !== '' && mb_strlen($artist) <= 40) {
            $row['hint']   = $title . ' ' . $channel;          // 원래 영상 제목도 남겨서 "데이식스" 같은 다른 표기로도 찾게
            $row['artist'] = $artist;
            $row['title']  = $song;
            $row['music']  = true;
        }
    }
    return $row;
}

// ---------- CSV: 엑셀로 만든 목록 / 애플 뮤직 재생 기록 ----------
function read_csv($text) {
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $text); rewind($fp);
    $head = fgetcsv($fp, 0, ',', '"', '');
    if (!$head) return '파일을 읽지 못했어요.';
    $names = ['title'  => ['title', '제목', '곡', '곡제목', '곡명', 'track', 'trackname', 'track name', 'song', 'song name', 'name'],
              'artist' => ['artist', '가수', '아티스트', 'artistname', 'artist name'],
              'count'  => ['count', '횟수', '재생횟수', '재생 횟수', 'plays', 'play count'],
              'date'   => ['date', '날짜', 'date played', 'played'],
              'desc'   => ['track description']];
    $col = [];
    foreach ($head as $i => $h) {
        $h = mb_strtolower(trim($h), 'UTF-8');
        foreach ($names as $key => $list) if (!isset($col[$key]) && in_array($h, $list, true)) $col[$key] = $i;
    }
    if (!isset($col['title']) && !isset($col['desc'])) {          // 첫 줄이 칸 이름이 아니면 "제목,가수,횟수" 순서로 봄
        $col = ['title' => 0, 'artist' => 1, 'count' => 2];
        rewind($fp);
    }
    $rows = [];
    while (($r = fgetcsv($fp, 0, ',', '"', '')) !== false) {
        if (count(array_filter($r, fn($v) => trim((string) $v) !== '')) === 0) continue;
        $title  = trim($r[$col['title'] ?? -1] ?? '');
        $artist = trim($r[$col['artist'] ?? -1] ?? '');
        if (isset($col['desc']) && $title === '') {               // 애플 뮤직: "가수 - 제목"
            $parts = explode(' - ', trim($r[$col['desc']] ?? ''), 2);
            if (count($parts) === 2) [$artist, $title] = $parts;
        }
        if ($title === '') continue;
        $raw  = trim($r[$col['date'] ?? -1] ?? '');
        $time = preg_match('/^\d{8}$/', $raw) ? strtotime(substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2)) : strtotime($raw);
        $cnt  = trim($r[$col['count'] ?? -1] ?? '');
        $rows[] = ['title' => $title, 'artist' => $artist, 'hint' => $artist, 'count' => $cnt === '' ? 1 : (int) $cnt,
                   'date' => ymd($time), 'music' => $artist !== '', 'url' => null, 'src' => 'csv'];
    }
    return $rows ? ['kind' => 'CSV 목록', 'rows' => $rows] : 'CSV 파일에서 곡을 찾지 못했어요. 첫 줄에 title,artist 칸을 넣어 주세요.';
}

// =====================================================================
// 2. 곡 찾기와 새 곡 추가
// =====================================================================
function load_tracks($conn) {
    $tracks = [];
    foreach ($conn->query("SELECT track_id, title, artist, genre FROM tracks") as $t) {
        $t['nt'] = norm($t['title']);
        $t['na'] = norm($t['artist']);
        if ($t['nt'] !== '') $tracks[] = $t;
    }
    usort($tracks, fn($a, $b) => mb_strlen($b['nt']) <=> mb_strlen($a['nt']));   // 긴 제목부터 (짧은 제목이 잘못 걸리지 않게)
    return $tracks;
}

// 제목(과 가수)으로 DB의 곡 하나를 찾습니다. 못 찾으면 null
function find_track($tracks, $title, $artist, $hint) {
    $nt = norm($title);
    $na = norm($artist);
    $nh = norm($hint);
    if ($nt === '') return null;
    $exact = []; $inside = [];
    foreach ($tracks as $t) {
        if ($t['nt'] === $nt) $exact[] = $t;
        elseif (mb_strlen($t['nt']) >= 2 && mb_strpos($nt, $t['nt']) !== false) $inside[] = $t;
    }
    $artistOk = fn($t) => $t['na'] !== '' && (($na !== '' && ($na === $t['na'] || mb_strpos($na, $t['na']) !== false || mb_strpos($t['na'], $na) !== false))
                                              || mb_strpos($nt, $t['na']) !== false || ($nh !== '' && mb_strpos($nh, $t['na']) !== false));
    foreach ($exact as $t) if ($artistOk($t)) return $t;
    if ($exact && $na === '') return $exact[0];          // 가수를 모르면 제목만 같아도 인정
    if ($na !== '') {
        // 같은 제목의 곡이 DB에 딱 하나뿐이면 가수 표기가 달라도 같은 곡으로 봄 (예: "IU" ↔ "아이유", "DAY6" ↔ "데이식스")
        if (count($exact) === 1 && mb_strlen($nt) >= 3) return $exact[0];
        return null;                                     // 그 밖에는 새 곡
    }
    foreach ($inside as $t) if ($artistOk($t)) return $t;
    foreach ($inside as $t) if (mb_strlen($t['nt']) >= 5) return $t;
    return null;
}

// 가수별로 가장 많이 쓰인 장르 (새 곡의 장르를 정할 때 사용)
function artist_genres($tracks) {
    $cnt = [];
    foreach ($tracks as $t) if ($t['genre'] !== UNKNOWN_GENRE) $cnt[$t['na']][$t['genre']] = ($cnt[$t['na']][$t['genre']] ?? 0) + 1;
    $out = [];
    foreach ($cnt as $na => $g) { arsort($g); $out[$na] = array_key_first($g); }
    return $out;
}

// 새 곡을 tracks 테이블에 넣고, 찾기 목록에도 추가합니다.
function add_track($conn, &$tracks, $genres, $title, $artist, $url, $genre = '') {
    $title  = mb_substr(trim($title), 0, 100);
    $artist = mb_substr(trim($artist), 0, 100);
    if ($genre === '') $genre = $genres[norm($artist)] ?? UNKNOWN_GENRE;
    $url = ($url && preg_match('~^https://(www\.|music\.)?youtube\.com/watch\?v=[A-Za-z0-9_-]{11}~', $url)) ? $url : null;
    $stmt = $conn->prepare("INSERT INTO tracks (title, artist, genre, youtube_url) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('ssss', $title, $artist, $genre, $url);
    $stmt->execute();
    $t = ['track_id' => $conn->insert_id, 'title' => $title, 'artist' => $artist, 'genre' => $genre, 'nt' => norm($title), 'na' => norm($artist)];
    array_unshift($tracks, $t);
    return $t;
}

// =====================================================================
// 3. 행 목록을 DB에 저장합니다.  돌려주는 값: 결과 숫자들
// =====================================================================
function save_rows($conn, $uid, $rows) {
    $tracks = load_tracks($conn);
    $genres = artist_genres($tracks);
    $plays = []; $missing = []; $added = 0; $notMusic = 0; $overLimit = 0; $addedList = [];
    $byId = [];                                          // 결과 화면에 보여 줄 곡 이름
    $memo = [];                                          // 같은 곡이 여러 번 나오면 한 번만 찾기

    foreach ($rows as $row) {
        $count = max(1, min(200, (int) $row['count']));
        $key = $row['title'] . "\n" . $row['artist'] . "\n" . $row['hint'];
        $t = array_key_exists($key, $memo) ? $memo[$key] : ($memo[$key] = find_track($tracks, $row['title'], $row['artist'], $row['hint']));
        if (!$t && $row['music'] && $row['artist'] !== '' && $added < NEW_SONG_LIMIT
            && mb_strlen($row['title']) <= 100 && mb_strlen($row['artist']) <= 100) {
            $t = $memo[$key] = add_track($conn, $tracks, $genres, $row['title'], $row['artist'], $row['url']);
            $added++;
            $addedList[] = ['title' => $t['title'], 'artist' => $t['artist'], 'genre' => $t['genre']];
        }
        // 가수 표기가 다른 같은 곡을 찾았으면(IU ↔ 아이유), 그 가수의 다른 새 곡도 같은 장르로 정하도록 기억
        if ($t && $row['artist'] !== '' && $t['genre'] !== UNKNOWN_GENRE && !isset($genres[norm($row['artist'])])) {
            $genres[norm($row['artist'])] = $t['genre'];
        }
        if (!$t) {
            if ($row['music'] && $row['artist'] !== '' && $added >= NEW_SONG_LIMIT) { $overLimit++; continue; }
            if ($row['music']) $missing[$row['title']] = ($missing[$row['title']] ?? 0) + $count;
            else $notMusic++;
            continue;
        }
        $src = $row['src'] ?? 'etc';
        $plays[$t['track_id']][$src . '|' . $row['date']] = ($plays[$t['track_id']][$src . '|' . $row['date']] ?? 0) + $count;
        $byId[$t['track_id']] = ['title' => $t['title'], 'artist' => $t['artist']];
    }

    // 같은 기록을 두 번 올려도 두 번 세지 않도록, "플랫폼·곡·날짜" 마다 구분 번호(source_key)를 붙여서 저장합니다.
    // 이미 같은 번호가 있으면(INSERT IGNORE) 건너뜁니다.
    $conn->begin_transaction();
    $add = $conn->prepare("INSERT IGNORE INTO plays (user_id, track_id, played_on, play_count, source_key) VALUES (?, ?, ?, ?, ?)");
    $saved = 0; $dup = 0; $savedSongs = [];
    foreach ($plays as $tid => $byDay) {
        foreach ($byDay as $srcDate => $n) {
            [$src, $date] = explode('|', $srcDate, 2);
            $key = 'up:' . md5($src . '|' . $tid . '|' . $date);
            $add->bind_param('sisis', $uid, $tid, $date, $n, $key);
            $add->execute();
            if ($add->affected_rows > 0) { $saved += $n; $savedSongs[$tid] = ($savedSongs[$tid] ?? 0) + $n; }
            else $dup += $n;
        }
    }
    $conn->commit();

    // 이번 파일에서 많이 들은 곡 TOP 5
    $sum = $savedSongs;                                  // 이번에 새로 저장된 기록만
    arsort($sum);
    $top = [];
    foreach (array_slice($sum, 0, 5, true) as $tid => $n) $top[] = $byId[$tid] + ['plays' => $n];
    return ['read' => count($rows), 'songs' => count($savedSongs), 'saved' => $saved, 'added' => $added, 'dup' => $dup,
            'not_music' => $notMusic, 'missing' => $missing, 'over_limit' => $overLimit,
            'top' => $top, 'added_list' => $addedList];
}
