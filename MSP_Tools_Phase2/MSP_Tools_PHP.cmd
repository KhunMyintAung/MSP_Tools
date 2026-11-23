@echo off
title MSP Tools Phase 2 - PHP Engine
cd /d "%~dp0"

where php >nul 2>nul
if %errorlevel% neq 0 (
    echo [ERROR] PHP is not found in your Windows PATH!
    pause
    exit /b 1
)

echo Starting MSP Tools PHP Server on http://127.0.0.1:8910 ...
start /b php -S 127.0.0.1:8910 -t public php-engine\router.php

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

echo MSP Tools Phase 2 (PHP) is running! Close this window to stop.
pause >nul
