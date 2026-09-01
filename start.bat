@echo off
REM Starts the marksheet generator with PHP's upload limits raised.
REM The built-in server ignores .htaccess and .user.ini, so the limits have to
REM be passed on the command line instead.

cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
    echo.
    echo PHP was not found on your PATH.
    echo If you use XAMPP, run this instead:
    echo     C:\xampp\php\php.exe -S localhost:8000
    echo.
    pause
    exit /b 1
)

echo Starting the marksheet generator at http://localhost:8000
echo Press Ctrl+C in this window to stop it.
echo.

start "" http://localhost:8000

php -d upload_max_filesize=512M ^
    -d post_max_size=512M ^
    -d memory_limit=512M ^
    -d max_execution_time=300 ^
    -d max_input_time=300 ^
    -S localhost:8000

pause
