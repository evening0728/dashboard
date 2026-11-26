@echo off
rem Deploy script: update this folder to the latest code on GitHub (main).
rem Run by GitHub Actions over SSH (double-click also works).
rem Files not in GitHub (config.php, spotify_config.php) are left untouched.
cd /d C:\xampp\htdocs\music-dashboard
set GIT=git -c safe.directory=C:/xampp/htdocs/music-dashboard
%GIT% fetch origin main || exit /b 1
%GIT% reset --hard origin/main || exit /b 1
echo %date% %time% deployed>> deploy.log
echo DEPLOY OK %date% %time%
