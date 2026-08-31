@echo off
REM ===== Apex Gym F22 Bridge - connection test (READ ONLY, deletes nothing) =====
cd /d "%~dp0"
echo Testing connection to the F22 at 192.168.18.16 ...
echo (This only READS from the device. Nothing is deleted or changed.)
echo.
python test_connection.py 192.168.18.16
echo.
echo Send a screenshot of everything above to Zeeshan.
echo.
pause
