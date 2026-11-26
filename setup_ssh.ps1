# 팀원이 SSH로 이 노트북의 music-dashboard 폴더를 직접 고칠 수 있게 준비하는 스크립트
# 실행 방법: PowerShell 을 "관리자 권한으로 실행" 한 뒤 아래 한 줄 입력
#   powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\music-dashboard\setup_ssh.ps1
#Requires -RunAsAdministrator
$ErrorActionPreference = 'Stop'
$folder = 'C:\xampp\htdocs\music-dashboard'

Write-Host '[1/5] OpenSSH 서버 설치 (처음이면 몇 분 걸려요)'
$cap = Get-WindowsCapability -Online | Where-Object Name -like 'OpenSSH.Server*'
if ($cap.State -ne 'Installed') { Add-WindowsCapability -Online -Name $cap.Name | Out-Null }

Write-Host '[2/5] SSH 서버 켜기 + 컴퓨터 켤 때 자동으로 켜지게'
Start-Service sshd
Set-Service -Name sshd -StartupType Automatic

Write-Host '[3/5] 방화벽에서 22번 포트 열기'
if (-not (Get-NetFirewallRule -Name 'OpenSSH-Server-In-TCP' -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -Name 'OpenSSH-Server-In-TCP' -DisplayName 'OpenSSH Server (sshd)' -Enabled True `
        -Direction Inbound -Protocol TCP -Action Allow -LocalPort 22 | Out-Null
}

Write-Host '[4/5] 팀원용 계정(team) 만들기'
if (-not (Get-LocalUser -Name 'team' -ErrorAction SilentlyContinue)) {
    $pw = Read-Host '팀원들이 쓸 비밀번호를 입력하세요 (화면에 안 보여요)' -AsSecureString
    New-LocalUser -Name 'team' -Password $pw -FullName 'Music Team' -PasswordNeverExpires | Out-Null
    Add-LocalGroupMember -Group 'Users' -Member 'team'
} else { Write-Host '      team 계정이 이미 있어서 그대로 둡니다.' }

Write-Host '[5/5] team 계정에 music-dashboard 폴더 수정 권한 주기'
icacls $folder /grant 'team:(OI)(CI)M' /T /Q | Out-Null

Write-Host ''
Write-Host '완료! 팀원 접속 정보:' -ForegroundColor Green
Write-Host "  아이디: team"
Write-Host "  폴더  : $folder"
$ts = Get-Command tailscale -ErrorAction SilentlyContinue
if ($ts) { Write-Host ("  주소  : " + (& tailscale ip -4)) + '  (Tailscale 주소)' }
else { Write-Host '  주소  : Tailscale 을 설치하면 100.x.x.x 주소가 생겨요. 같은 와이파이라면 아래 IPv4 주소를 쓰세요.'; ipconfig | Select-String 'IPv4' }
