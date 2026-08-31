@echo off
REM ===== Apex Gym F22 Bridge - run =====
cd /d "%~dp0"
if not exist config.ini (
  if exist config.kl.ini (
    copy config.kl.ini config.ini >nul
    echo Created config.ini from the KL template.
  ) else (
    echo [X] No config.ini found. Copy config.example.ini to config.ini first.
    pause
    exit /b
  )
)
echo Starting the bridge. Leave this window open while the gym is open.
echo Press Ctrl+C to stop.
echo.
python f22_bridge.py config.ini
echo.
echo Bridge stopped.
pause
