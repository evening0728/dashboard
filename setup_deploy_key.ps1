# GitHub Actions 가 SSH 로 이 노트북의 team 계정에 비밀번호 없이(키로) 들어올 수 있게 준비합니다.
# setup_ssh.ps1 을 먼저 실행한 뒤, 관리자 권한 PowerShell 에서:
#   powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\music-dashboard\setup_deploy_key.ps1
#Requires -RunAsAdministrator
$ErrorActionPreference = 'Stop'

if (-not (Get-LocalUser -Name 'team' -ErrorAction SilentlyContinue)) { throw 'team 계정이 없어요. setup_ssh.ps1 을 먼저 실행하세요.' }
if (-not (Get-Command git -ErrorAction SilentlyContinue)) { Write-Host '※ Git 이 없어요. 배포하려면 설치가 필요해요: winget install --id Git.Git' -ForegroundColor Yellow }

Write-Host '[1/3] 배포용 키 만들기'
$keyDir = Join-Path $env:USERPROFILE '.ssh'
New-Item -ItemType Directory -Force -Path $keyDir | Out-Null
$key = Join-Path $keyDir 'github_deploy_key'
if (-not (Test-Path $key)) { ssh-keygen -t ed25519 -C 'github-actions-deploy' -f $key -N '""' | Out-Null }

Write-Host '[2/3] team 계정에 키 등록 (C:\ProgramData\ssh\team_authorized_keys)'
$auth = 'C:\ProgramData\ssh\team_authorized_keys'
Copy-Item "$key.pub" $auth -Force
icacls $auth /inheritance:r /grant 'SYSTEM:F' /grant '*S-1-5-32-544:F' | Out-Null      # 관리자·SYSTEM 만 접근
$conf = 'C:\ProgramData\ssh\sshd_config'
if (-not (Select-String -Path $conf -Pattern 'team_authorized_keys' -Quiet)) {
    Add-Content -Path $conf -Value "`r`nMatch User team`r`n       AuthorizedKeysFile __PROGRAMDATA__/ssh/team_authorized_keys`r`n"
}
Restart-Service sshd

Write-Host '[3/3] 완료' -ForegroundColor Green
Get-Content $key -Raw | Set-Clipboard
Write-Host ''
Write-Host '개인키를 클립보드에 복사했어요.' -ForegroundColor Green
Write-Host 'GitHub 저장소 → Settings → Secrets and variables → Actions → New repository secret'
Write-Host '  이름: SSH_KEY   값: (Ctrl+V 로 붙여넣기)'
Write-Host ''
Write-Host '연결 시험: ssh -i' $key 'team@localhost "echo ok"'
