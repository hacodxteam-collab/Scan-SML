@echo off
chcp 65001 >nul
echo ----------------------------------------
echo Push Code to GitHub (ScanWarehouse)
echo ----------------------------------------
set /p REPO_URL="Enter GitHub Repository URL (e.g. https://github.com/user/repo.git) or press Enter if already set: "

git init
git add .
git commit -m "Update: Added User Management, Sale Admin role, and UI fixes"
git branch -M main

if not "%REPO_URL%"=="" (
    git remote remove origin 2>nul
    git remote add origin %REPO_URL%
)

echo Pushing to GitHub...
git push -u origin main
echo ----------------------------------------
echo Done! Please check your GitHub repository.
pause
