@echo off
REM ===== Apex Gym F22 Bridge - one-time install =====
echo Installing the two libraries the bridge needs...
echo.
pip install pyzk requests
echo.
if %errorlevel% neq 0 (
  echo.
  echo [X] pip failed. Is Python installed with "Add to PATH" checked?
  echo     Install Python from python.org, then run this again.
) else (
  echo.
  echo [OK] Done. Next: double-click  2-test-connection.bat
)
echo.
pause
