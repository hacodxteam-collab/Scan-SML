@echo off
echo ================================================
echo   ScanWarehouse - Starting PHP Server
echo ================================================
echo.
echo Server URL: http://localhost:8080
echo Press Ctrl+C to stop the server
echo.
C:\xampp\php\php.exe -S localhost:8080 -t "%~dp0"
pause
