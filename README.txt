음악 추천 대시보드 (PHP + MySQL)

[실행 방법 - XAMPP 기준]
1. XAMPP 제어판에서 Apache와 MySQL을 Start 합니다.
2. 이 폴더(music-dashboard)를 C:\xampp\htdocs 안에 넣습니다.
3. 브라우저에서 http://localhost/music-dashboard/setup.php 를 엽니다.
4. http://localhost/music-dashboard/ 로 들어가서 로그인합니다.
   체험 계정: minseong / 1234, jiwoo / 1234, newbie / 1234 (청취 기록 없음)

[setup.php 는 여러 번 열어도 안전합니다]
- 테이블이 없으면 만들고, 있으면 그대로 둡니다.
- tracks 에 곡이 하나도 없을 때만 기본 곡 219곡을 넣습니다.
- users 에 사용자가 한 명도 없을 때만 체험 계정을 넣습니다.
- 전부 지우고 처음부터 하려면 관리자(admin)로 로그인한 뒤 setup.php?reset=1 을 엽니다.
  (로그인하지 않았거나 일반 사용자면 막힙니다)

[조원이 넣은 곡을 반영하는 방법]
같은 컴퓨터의 같은 DB(music_dashboard)의 tracks 테이블에 넣었다면 따로 할 일이 없습니다.
화면을 새로고침하면 바로 반영됩니다. (화면이 열릴 때마다 DB를 다시 조회하기 때문)

tracks 테이블의 칸 이름은 반드시 아래와 같아야 합니다.
  title (곡 제목), artist (가수), genre (장르)     ※ track_id 는 자동으로 붙습니다.
  예) INSERT INTO tracks (title, artist, genre) VALUES ('밤편지', '아이유', '발라드');

조원의 컴퓨터에서 내 컴퓨터로 옮기려면 (cmd):
  보내는 쪽:  cd C:\xampp\mysql\bin
              mysqldump -u root --default-character-set=utf8mb4 music_dashboard tracks > C:\tracks.sql
  받는 쪽:    (먼저 setup.php?reset=1 을 열지 말고) 아래 순서로
              mysql -u root --default-character-set=utf8mb4 -e "DROP DATABASE IF EXISTS music_dashboard; CREATE DATABASE music_dashboard CHARACTER SET utf8mb4"
              mysql -u root --default-character-set=utf8mb4 music_dashboard < C:\tracks.sql
              그다음 setup.php 를 엽니다. → 곡은 그대로 두고, 나머지 테이블과 체험 계정만 만들어집니다.

[곡 추가하기 - 곡 관리 화면] ※ 관리자만 사용 가능
관리자 계정: admin / admin1234  (setup.php 를 열면 자동으로 만들어짐. 발표 전에 비밀번호를 바꾸세요)
admin 으로 로그인 → 결과 화면 오른쪽 위 [곡 관리] → CSV 파일 올리기 / 가수 사진 올리기
일반 사용자에게는 [곡 관리] 버튼이 보이지 않고, 주소(songs.php)로 들어와도 결과 화면으로 돌아갑니다.
가수 사진은 img/artists 폴더에 저장됩니다.
- 엑셀에 title, artist, genre, youtube_url 4칸으로 적고 CSV로 저장해서 올립니다.
- songs_template.csv 가 예시, songs_basic.csv 가 기본 곡 219개입니다.
- 제목과 가수가 같은 곡은 다시 넣지 않으므로 여러 번 올려도 안전합니다.
- youtube_url 을 비우면 재생 버튼이 "가수 + 제목" 유튜브 검색으로 연결됩니다.

[스포티파이 계정 연결]
※ 조건: 개발자 앱을 만드는 사람이 스포티파이 프리미엄 구독자여야 하고, 연결할 수 있는 사용자는 최대 5명입니다. (2026년 스포티파이 정책)

1) 스포티파이 개발자 앱 만들기 (프리미엄 계정으로)
   - https://developer.spotify.com/dashboard 로그인 → Create app
   - App name, App description: 아무거나 (예: 음악 추천 대시보드)
   - Redirect URIs: http://127.0.0.1/music-dashboard/spotify_callback.php  (입력 후 Add 클릭)
       · localhost 는 스포티파이가 허용하지 않습니다. 반드시 127.0.0.1
       · XAMPP 포트를 바꿨다면 http://127.0.0.1:8080/music-dashboard/spotify_callback.php 처럼 포트 포함
   - Which API/SDKs: Web API 체크 → 약관 동의 → Save
2) 앱의 Settings 에서 Client ID 와 Client Secret(View client secret) 를 복사합니다.
3) spotify_config.php 를 메모장으로 열어 두 값을 붙여 넣고 저장합니다.
   Redirect URI 를 다르게 등록했다면 $SPOTIFY_REDIRECT_URI 도 똑같이 고칩니다.
4) 앱의 User Management 에 연결할 팀원의 스포티파이 이름과 이메일을 등록합니다. (등록 안 된 계정은 연결이 거절됨)
5) http://localhost/music-dashboard/setup.php 를 한 번 엽니다. (스포티파이용 칸 추가)
6) 사이트는 http://127.0.0.1/music-dashboard/ 로 엽니다. (localhost 로 열어도 자동으로 127.0.0.1 로 바뀝니다)
   로그인 → 결과 화면의 [스포티파이 연결] → 스포티파이에서 동의 → 기록이 자동으로 들어옵니다.

가져오는 기록
- 최근 들은 곡 최대 50곡 (들은 날짜 그대로)
- 많이 들은 곡 TOP 50 (최근 약 6개월): 1~10위 5회, 11~20위 4회 … 41~50위 1회로 계산해 오늘 날짜로 넣음
- 우리 DB에 없는 곡은 tracks 에 새로 추가 (장르는 스포티파이 가수 장르로 판단, 모르면 '기타')
- 같은 기록은 두 번 들어가지 않으므로 [기록 새로 가져오기]를 여러 번 눌러도 됩니다.

[SSL 오류가 날 때] "보안 연결(SSL)을 하지 못했어요" 라고 나오면
1) https://curl.se/docs/caextract.html 에서 cacert.pem 을 받아 C:\xampp\php\extras\ssl\cacert.pem 에 저장
2) C:\xampp\php\php.ini 를 메모장으로 열어 아래 두 줄을 찾아 앞의 ; 를 지우고 경로를 넣음
   curl.cainfo = "C:\xampp\php\extras\ssl\cacert.pem"
   openssl.cafile = "C:\xampp\php\extras\ssl\cacert.pem"
3) XAMPP 제어판에서 Apache 를 Stop → Start

[내 노트북을 공개 서버로 열기 - Cloudflare 터널]  ※ 공유기 설정 없이, 무료, 가입 없이 됩니다
처음 한 번만
1. cmd 를 열고 아래 명령으로 cloudflared 를 설치합니다. 설치 후 cmd 를 닫았다가 다시 엽니다.
     winget install --id Cloudflare.cloudflared
   (winget 이 안 되면 github.com/cloudflare/cloudflared/releases 에서 cloudflared-windows-amd64.exe 를 받아
    이름을 cloudflared.exe 로 바꾸고 이 폴더(music-dashboard)에 넣습니다.)
2. config.php 의 $ADMIN_PASS 를 새 비밀번호로 바꾸고, http://localhost/music-dashboard/setup.php 를 한 번 엽니다.
   → admin 계정 비밀번호가 새 값으로 바뀝니다. (인터넷에 공개되므로 admin1234 그대로 두면 안 됩니다)
공개할 때마다
3. XAMPP 제어판에서 MySQL 을 Start 합니다. (Apache 는 없어도 됩니다)
4. 이 폴더의 public_server.bat 을 더블클릭합니다. 검은 창이 2개 뜹니다.
5. 창에 나오는 https://(아무 단어들).trycloudflare.com 주소가 공개 주소입니다. 이 주소를 친구에게 보내면 됩니다.
   (주소 뒤에 /music-dashboard 를 붙이지 않습니다. 이 폴더만 공개되기 때문입니다)
주의
- 창을 닫거나 노트북이 꺼지거나 절전 모드로 들어가면 사이트도 꺼집니다. 발표 때는 절전 모드를 꺼 두세요.
- 실행할 때마다 주소가 바뀝니다. 발표 직전에 켜고 그 주소를 쓰면 됩니다.
- phpMyAdmin 같은 XAMPP 관리 화면은 밖에서 보이지 않습니다. (music-dashboard 폴더만 공개)
- 같은 노트북에서는 원래대로 http://localhost/music-dashboard/ 로도 들어갈 수 있습니다.

[공개 서버(웹호스팅)에 올리기]
1. 웹호스팅을 신청합니다. (예: 닷홈 무료 호스팅, PHP 8 + MySQL 선택)
   신청이 끝나면 관리 화면에 FTP 아이디/비밀번호, DB 이름/아이디/비밀번호, 사이트 주소가 나옵니다.
2. config.php 를 고칩니다.
   - $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME → 호스팅 관리 화면에 나온 값
   - $ADMIN_PASS → admin1234 가 아닌, 남이 추측하기 어려운 비밀번호
     (공개 서버에서 admin1234 그대로면 관리자 계정을 만들지 않습니다)
3. FileZilla 같은 FTP 프로그램으로 이 폴더의 파일을 호스팅의 웹 폴더(닷홈은 html 폴더)에 올립니다.
   img 폴더(가수 사진)도 함께 올립니다.
4. 브라우저에서 http://내사이트주소/setup.php 를 한 번 엽니다. → 테이블과 기본 곡이 만들어집니다.
5. http://내사이트주소/ 로 들어가면 끝입니다.
※ 가수 사진 올리기가 안 되면 FTP 프로그램에서 img 폴더 권한을 707 로 바꿉니다.
※ 체험 계정(minseong, jiwoo, newbie / 1234)은 누구나 로그인할 수 있으니 발표가 끝나면 지워도 됩니다.
※ 조원이 모은 곡은 그 CSV 파일을 공개 서버의 곡 관리 화면에서 다시 올리면 됩니다. (이미 있는 곡은 건너뜀)

[파일 설명]
config.php  설정 (DB 접속 정보, 관리자 비밀번호) ← 서버를 옮길 때 이 파일만 고칩니다
public_server.bat  내 노트북을 공개 서버로 열기 (더블클릭)
db.php      DB 연결
lib.php     분석 함수 모음 (장르 집계, 추천 곡, 추천 아티스트, 취향 궁합)
setup.php   테이블 생성 + 예시 데이터
index.php   메인 화면
login.php   로그인 / 회원가입
result.php  결과 화면 + 공유하기 팝업 (PNG 저장, 링크 복사)
share.php   공유 링크로 들어온 화면 (취향 비교, 기록 없을 때 안내 카드)
play.php    재생 버튼 → 청취 기록 1회 추가 후 유튜브로 이동
songs.php   곡 관리 (CSV로 곡 한꺼번에 넣기)
spotify_config.php   스포티파이 Client ID / Secret 설정
spotify.php / spotify_login.php / spotify_callback.php / spotify_sync.php   스포티파이 연결
upload.php  청취 기록 JSON 파일 올리기
reset.php   청취 기록 초기화 (결과 화면 맨 아래 [기록 모두 지우기] → 본인 기록만 삭제)
logout.php  로그아웃
style.css   화면 디자인
