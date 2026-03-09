@echo off
chcp 65001 >nul
echo ========================================
echo   Push Code to GitHub (ScanWarehouse)
echo ========================================

:: Get today's date in YYYY-MM-DD format
for /f "tokens=2 delims==" %%I in ('wmic os get localdatetime /value') do set "dt=%%I"
set "TODAY=%dt:~0,4%-%dt:~4,2%-%dt:~6,2%"

:: Count how many commits already exist for today
set COUNT=0
for /f %%C in ('git log --oneline --after="%TODAY% 00:00" --before="%TODAY% 23:59" 2^>nul ^| find /c /v ""') do set COUNT=%%C

:: Build commit message
if %COUNT%==0 (
    set "COMMIT_MSG=Update: %TODAY%"
) else (
    set "COMMIT_MSG=Update: %TODAY% (%COUNT%)"
)

echo.
echo   Commit Message: %COMMIT_MSG%
echo.

:: Ask for repo URL (first time only)
set /p REPO_URL="Enter GitHub Repository URL (or press Enter to skip): "

git init 2>nul
git add .
git commit -m "%COMMIT_MSG%"
git branch -M main

if not "%REPO_URL%"=="" (
    git remote remove origin 2>nul
    git remote add origin %REPO_URL%
)

echo.
echo Pushing to GitHub...
git push -u origin main
echo ========================================
echo   Done! Commit: %COMMIT_MSG%
echo ========================================
pause
