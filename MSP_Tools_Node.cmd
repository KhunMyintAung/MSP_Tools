@echo off
title MSP Tools - Node.js Engine (Auto-Detect)
cd /d "%~dp0"
set "PATH=%~dp0bin;C:\f7\platform-tools;C:\Users\Admin\android-tools\sdk\platform-tools;%PATH%"

where node >nul 2>nul
if %errorlevel% neq 0 (
    echo [ERROR] Node.js is not found in your Windows PATH!
    pause
    exit /b 1
)

echo Starting MSP Tools Node.js Server on http://127.0.0.1:8910 ...
start /b node node-engine\server.js

timeout /t 1 /nobreak >nul

if exist "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" (
    start "" "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" --app=http://127.0.0.1:8910 --window-size=1420,880
) else if exist "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" (
    start "" "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" --app=http://127.0.0.1:8910 --window-size=1420,880
) else if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" (
    start "" "%ProgramFiles%\Google\Chrome\Application\chrome.exe" --app=http://127.0.0.1:8910 --window-size=1420,880
) else (
    start http://127.0.0.1:8910
)

echo MSP Tools (Node.js) is running! Close this window to stop.
pause >nul
