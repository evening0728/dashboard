<?php
// ※ 예시 파일입니다. 복사해서 spotify_config.php 로 이름을 바꾼 뒤 값을 채우세요. (spotify_config.php 는 GitHub 에 올리지 않습니다)
// ===== 스포티파이 연결 설정 =====
// developer.spotify.com 대시보드에서 만든 앱의 값을 아래에 붙여 넣으세요.
// (Client Secret 은 비밀번호와 같습니다. 깃허브 등에 올리지 마세요.)
$SPOTIFY_CLIENT_ID     = '';
$SPOTIFY_CLIENT_SECRET = '';

// 스포티파이 대시보드의 "Redirect URIs" 에도 아래 주소를 글자 하나 다르지 않게 똑같이 등록해야 합니다.
// localhost 는 스포티파이가 허용하지 않으므로 127.0.0.1 을 씁니다. (XAMPP 포트를 바꿨다면 127.0.0.1:8080 처럼 포트도 넣기)
$SPOTIFY_REDIRECT_URI = 'https://served-managed-alberta-yet.trycloudflare.com/spotify_callback.php';

// 아래 두 줄은 바꾸지 마세요. (테스트할 때만 쓰는 값)
$SPOTIFY_ACCOUNTS_URL  = 'https://accounts.spotify.com';
$SPOTIFY_API_URL       = 'https://api.spotify.com/v1';
