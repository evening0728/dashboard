# 🎵 음악 추천 대시보드 (팀 공사공오)

청취 기록을 넣으면 많이 들은 장르와 가수를 분석해서, 취향에 맞는 곡·플레이리스트·아티스트를 추천해 주는 웹사이트입니다.
PHP + MySQL(XAMPP)로 만들었고, 팀장 노트북이 공개 서버 역할을 합니다.

---

## 1. 팀원 작업 방법 (가장 중요)

> **모든 수정은 GitHub을 통해서만 합니다.**
> `main` 브랜치에 push 하면 약 30초 뒤 공개 서버(팀장 노트북)에 **자동으로 반영**됩니다.
> 서버 노트북에서 파일을 직접 고치면 다음 배포 때 GitHub 내용으로 **덮어써집니다.**

### 처음 한 번
1. 팀장에게 GitHub 아이디를 알려 주고, 초대 메일에서 **수락**합니다. (수락해야 push 가능)
2. Git 설치: `winget install --id Git.Git -e` (설치 후 터미널을 다시 열기)
3. 이름·이메일 등록
   ```
   git config --global user.name "내 GitHub 아이디"
   git config --global user.email "내 GitHub 이메일"
   ```
4. 코드 받기 (내 컴퓨터 XAMPP에서 시험하려면 `C:\xampp\htdocs` 안에서 받기)
   ```
   cd C:\xampp\htdocs
   git clone https://github.com/evening0728/dashboard.git
   ```
5. `config.example.php` 를 복사해서 이름을 `config.php` 로 바꿉니다. (스포티파이를 쓰려면 `spotify_config.example.php` → `spotify_config.php` 도)
6. XAMPP 에서 Apache, MySQL 을 켜고 `http://localhost/dashboard/setup.php` 를 한 번 엽니다. → 내 컴퓨터에 DB와 곡 데이터가 만들어집니다.
7. 곡 500개를 모두 넣으려면 `admin / admin1234` 로 로그인 → **곡 관리** → `songs_basic.csv` 를 올립니다. (이미 있는 곡은 건너뜀)

### 코드를 고칠 때마다
```
git pull                              ← 다른 팀원이 올린 최신 코드 먼저 받기
(코드 수정하고 내 컴퓨터에서 확인)
git add .
git commit -m "무엇을 고쳤는지 짧게"   ← 예: "결과 화면 글자 크기 수정"
git push                              ← 올리면 공개 서버에 자동 반영
```

### 지켜 주세요
- **`config.php`, `spotify_config.php` 는 절대 올리지 않습니다.** (비밀번호가 들어 있음. `.gitignore` 로 막아 두었음)
- 같은 파일을 여러 명이 동시에 고치면 충돌이 납니다. **파일별로 담당을 나누고**, 고치기 전에 꼭 `git pull` 하기.
- push 했는데 사이트에 반영이 안 되면 → 저장소의 **Actions** 탭에서 ✅/❌ 확인. ❌ 이면 팀장에게 알려 주세요.

### 충돌(CONFLICT)이 났을 때
`git pull` 했을 때 `CONFLICT` 가 나오면, 해당 파일을 열어 아래 표시를 찾아 남길 내용만 남기고 표시 줄은 지웁니다.
```
<<<<<<< HEAD
내가 고친 내용
=======
다른 팀원이 고친 내용
>>>>>>> 
```
그다음 `git add .` → `git commit -m "충돌 정리"` → `git push`

---

## 2. 자동 배포 구조

```
팀원 A / B / C ──push──▶ GitHub (main)
                            │
                            ▼
                     GitHub Actions  (.github/workflows/deploy.yml)
                            │  ① Tailscale 로 노트북에 연결
                            │  ② SSH 로 team 계정 접속
                            ▼
                     팀장 노트북 (Windows, OpenSSH Server)
                            │  ③ deploy.bat 실행 → git fetch + reset
                            ▼
              C:\xampp\htdocs\music-dashboard  →  공개 사이트에 바로 반영
```

| 구간 | 방식 |
|---|---|
| 팀원 → GitHub | HTTPS (브라우저 로그인) |
| GitHub Actions → 노트북 | SSH (키 인증) + Tailscale (노트북이 공유기 안에 있어서 필요) |
| 노트북 → htdocs | `deploy.bat` (git 으로 최신 코드 받기, 배포 기록은 `deploy.log`) |

GitHub Secrets (Settings → Secrets and variables → Actions)

| 이름 | 내용 |
|---|---|
| `SSH_KEY` | 노트북 team 계정 접속용 개인키 (`setup_deploy_key.ps1` 이 만듦) |
| `SERVER_HOST` | 노트북의 Tailscale 주소 (100.x.x.x) |
| `TS_AUTHKEY` | Tailscale auth key (Reusable + Ephemeral, **90일마다 새로 만들어야 함**) |

---

## 3. 서버 노트북 관리 (팀장용)

### 사이트 켜기
1. XAMPP 제어판에서 **MySQL Start**
2. `서버열기.bat` 더블클릭 → 창에 나온 `https://○○○.trycloudflare.com` 이 공개 주소 (켤 때마다 바뀜)
3. 검은 창을 닫거나 노트북이 꺼지거나 절전되면 사이트도 꺼집니다.

### 처음 설치할 때 (이미 완료)
| 순서 | 할 일 |
|---|---|
| 1 | 관리자 PowerShell: `powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\music-dashboard\setup_ssh.ps1` (OpenSSH 서버, 방화벽, team 계정) |
| 2 | Tailscale 설치·로그인 → 노트북 주소 100.x.x.x 확인 |
| 3 | 관리자 PowerShell: `...\setup_deploy_key.ps1` → 개인키를 GitHub Secret `SSH_KEY` 에 등록 |
| 4 | Git 설치(`winget install --id Git.Git -e --scope machine`), `Restart-Service sshd` |
| 5 | 이 폴더에서 `git init -b main` → 첫 push |

### 배포가 실패할 때
| 증상 | 해결 |
|---|---|
| Tailscale 단계 ❌ | `TS_AUTHKEY` 만료 → Tailscale 관리 화면에서 키 새로 만들어 Secret 교체 |
| SSH 단계 timed out | 노트북이 꺼짐/절전/Tailscale 꺼짐 → 켠 뒤 Actions 에서 **Run workflow** |
| SSH Permission denied | `SSH_KEY` 가 잘못됨 → `setup_deploy_key.ps1` 다시 실행 후 Secret 교체 |
| `git` 을 찾을 수 없음 | 노트북에 Git 설치 후 관리자 PowerShell 에서 `Restart-Service sshd` |
| deploy.bat 에서 fetch 실패 | 저장소가 Private 이면 노트북이 코드를 못 받음 → Public 으로 두기 |

---

## 4. 사이트 기능

| 기능 | 설명 |
|---|---|
| 좋아하는 곡 고르기 | 가입 직후 곡을 검색해서 고르면 바로 분석 (파일 없이 시작) |
| 기록 파일 올리기 | 유튜브(구글 테이크아웃 zip/JSON/HTML), 스포티파이 데이터, 애플 뮤직 CSV, 엑셀 CSV — 종류 자동 인식, 같은 기록은 두 번 안 셈 |
| 처음 보는 곡 자동 추가 | 장르는 같은 가수의 곡을 보고 정하고, 모르면 '기타' |
| 하이브리드 추천 | ① 취향(장르 70%·가수 30%, 최근 기록일수록 크게) ② 함께 들은 곡(협업 필터링) ③ 가수당 최대 2곡 ④ 곡마다 추천 이유 |
| 추천 플레이리스트 TOP 3 | 많이 들은 장르별 30곡, 유튜브 전체 재생 |
| 공유 | 결과 카드를 PNG·링크로 공유, 친구와 취향 비교 |
| 스포티파이 연결 | 개발 모드라 운영자가 등록한 5명까지만 가능 |
| 곡 관리 (관리자) | CSV 로 곡 추가, 장르·제목·가수·유튜브 주소 고치기, 중복 곡 합치기, 가수 사진 |

곡 데이터: 기본 500곡 (`songs_basic.csv`, K-POP·발라드·인디·R&B·힙합)
체험 계정: minseong / 1234, jiwoo / 1234, newbie / 1234 (기록 없음)

---

## 5. 파일 설명

| 파일 | 역할 |
|---|---|
| `config.example.php` | 설정 예시 → 복사해서 `config.php` 로 (DB 접속, 관리자 비밀번호) |
| `db.php` | DB 연결 |
| `lib.php` | 분석·추천 함수, 업로드 결과 카드, 대기 화면 |
| `import_lib.php` | 여러 플랫폼 기록 파일 읽기, 곡 찾기·자동 추가, 중복 방지 저장 |
| `setup.php` | 테이블 생성 + 기본 데이터 (여러 번 열어도 안전) |
| `index.php` / `login.php` / `logout.php` | 메인, 로그인·회원가입, 로그아웃 |
| `add.php` / `pick.php` | 청취 기록 추가 화면, 좋아하는 곡 고르기 저장 |
| `upload.php` | 기록 파일 올리기 |
| `result.php` | 분석 결과 화면 |
| `share.php` | 공유 링크 화면 (취향 비교) |
| `play.php` | 재생 버튼 → 기록 1회 추가 후 유튜브 이동 |
| `songs.php` / `add_music.php` | 곡 관리 (관리자) |
| `reset.php` | 내 청취 기록 지우기 |
| `spotify*.php` | 스포티파이 연결 |
| `style.css` | 화면 디자인 |
| `songs_basic.csv` | 기본 곡 500곡 (제목, 가수, 장르, 유튜브 주소) |
| `deploy.bat` | 서버 배포 스크립트 (GitHub Actions 가 실행) |
| `.github/workflows/deploy.yml` | 자동 배포 설정 |
| `setup_ssh.ps1` / `setup_deploy_key.ps1` | 서버 노트북 SSH·배포 키 설정 (처음 한 번) |
