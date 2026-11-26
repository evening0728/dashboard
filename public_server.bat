@echo off
rem ==========================================================
rem  Open this laptop as a public server (Cloudflare Tunnel)
rem  1) Start MySQL in the XAMPP control panel.
rem  2) Double-click this file.
rem  3) Share the https://....trycloudflare.com address it prints.
rem  Closing the windows stops the public server.
rem ==========================================================

rem PHP server that shows ONLY the music-dashboard folder (port 8080).
rem (keeps phpMyAdmin and other XAMPP pages hidden from the internet)
start "PHP server - do not close" C:\xampp\php\php.exe -S 127.0.0.1:8080 -t "%~dp0."

rem Connect that server to a public internet address.
cloudflared tunnel --url http://127.0.0.1:8080
pause
