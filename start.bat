@echo off
setlocal enabledelayedexpansion
title WaveTrader V3 - Startup
color 0A

echo.
echo  ============================================
echo   WaveTrader V3 - Starting All Services
echo  ============================================
echo.

:: Get project directory (remove trailing backslash)
set "PROJECT_DIR=%~dp0"
if "%PROJECT_DIR:~-1%"=="\" set "PROJECT_DIR=%PROJECT_DIR:~0,-1%"
set "BACKEND_DIR=%PROJECT_DIR%\backend"
set "FRONTEND_DIR=%PROJECT_DIR%\frontend"

:: -----------------------------------------------
:: 1. Check prerequisites
:: -----------------------------------------------
echo  [1/6] Checking prerequisites...

where php >nul 2>&1
if %errorlevel% neq 0 (
    echo    ERROR: PHP not found. Start Laragon or add PHP to PATH.
    pause & exit /b 1
)
for /f "tokens=2" %%v in ('php -v 2^>^&1 ^| findstr /n "^" ^| findstr "^1:"') do set "PHP_VER=%%v"
echo    PHP: OK

where node >nul 2>&1
if %errorlevel% neq 0 (
    echo    ERROR: Node.js not found. Install via nvm.
    pause & exit /b 1
)
echo    Node: OK

:: -----------------------------------------------
:: 2. Check PostgreSQL
:: -----------------------------------------------
echo  [2/6] Checking PostgreSQL...
netstat -an | findstr ":5432" | findstr "LISTENING" >nul 2>&1
if %errorlevel% neq 0 (
    echo    WARNING: PostgreSQL not running. Attempting start...
    net start postgresql-x64-16 >nul 2>&1
    timeout /t 3 /nobreak >nul
    netstat -an | findstr ":5432" | findstr "LISTENING" >nul 2>&1
    if !errorlevel! neq 0 (
        echo    ERROR: PostgreSQL failed to start. Start manually.
        pause & exit /b 1
    )
)
echo    PostgreSQL: OK (port 5432)

:: -----------------------------------------------
:: 3. Check Redis
:: -----------------------------------------------
echo  [3/6] Checking Redis...
netstat -an | findstr ":6379" | findstr "LISTENING" >nul 2>&1
if %errorlevel% neq 0 (
    echo    WARNING: Redis not running. Attempting start...
    where redis-server >nul 2>&1
    if !errorlevel! equ 0 (
        start "" /MIN redis-server
    ) else if exist "C:\laragon\bin\redis\redis-server.exe" (
        start "" /MIN "C:\laragon\bin\redis\redis-server.exe"
    ) else (
        echo    ERROR: Redis not found. Install Redis or start Laragon.
        pause & exit /b 1
    )
    timeout /t 2 /nobreak >nul
    netstat -an | findstr ":6379" | findstr "LISTENING" >nul 2>&1
    if !errorlevel! neq 0 (
        echo    ERROR: Redis failed to start.
        pause & exit /b 1
    )
)
echo    Redis: OK (port 6379)

:: -----------------------------------------------
:: 4. Kill any existing WaveTrader processes
:: -----------------------------------------------
echo  [4/6] Cleaning up old processes...

:: Kill by window title
taskkill /FI "WINDOWTITLE eq WaveTrader -*" /F >nul 2>&1

:: Kill processes on our ports
for /f "tokens=5" %%a in ('netstat -ano 2^>nul ^| findstr ":8000 " ^| findstr "LISTENING"') do (
    taskkill /PID %%a /F >nul 2>&1
)
for /f "tokens=5" %%a in ('netstat -ano 2^>nul ^| findstr ":8085 " ^| findstr "LISTENING"') do (
    taskkill /PID %%a /F >nul 2>&1
)
timeout /t 2 /nobreak >nul
echo    Cleanup: OK

:: -----------------------------------------------
:: 5. Run migrations
:: -----------------------------------------------
echo  [5/6] Running migrations...
pushd "%BACKEND_DIR%"
php artisan migrate --force --quiet >nul 2>&1
if %errorlevel% equ 0 (
    echo    Migrations: OK
) else (
    echo    WARNING: Migrations may have issues. Check database config.
)
popd

:: -----------------------------------------------
:: 6. Start all services
:: -----------------------------------------------
echo  [6/6] Starting services...
echo.

:: Backend API (Laravel)
echo    Starting Laravel API on http://localhost:8000 ...
start "WaveTrader - Backend API" /D "%BACKEND_DIR%" cmd /c "php artisan serve --host=0.0.0.0 --port=8000"
timeout /t 3 /nobreak >nul

:: Verify backend started
netstat -an | findstr ":8000" | findstr "LISTENING" >nul 2>&1
if %errorlevel% neq 0 (
    echo    WARNING: Backend may not have started. Retrying...
    start "WaveTrader - Backend API" /D "%BACKEND_DIR%" cmd /c "php artisan serve --host=0.0.0.0 --port=8000"
    timeout /t 3 /nobreak >nul
)

:: Laravel Reverb (WebSocket)
echo    Starting Reverb WebSocket on ws://localhost:8085 ...
start "WaveTrader - Reverb WebSocket" /D "%BACKEND_DIR%" cmd /c "php artisan reverb:start --port=8085"
timeout /t 2 /nobreak >nul

:: Queue Worker
echo    Starting Queue Worker...
start "WaveTrader - Queue Worker" /D "%BACKEND_DIR%" cmd /c "php artisan queue:work redis --queue=default,engines --sleep=3 --tries=3"
timeout /t 1 /nobreak >nul

:: Frontend (Vite)
echo    Starting Vue frontend on http://localhost:5173 ...
start "WaveTrader - Frontend" /D "%FRONTEND_DIR%" cmd /c "npm run dev"
timeout /t 4 /nobreak >nul

:: -----------------------------------------------
:: Verify all services
:: -----------------------------------------------
echo.
echo  ============================================
echo   Service Status:
echo  ============================================

set "ALL_OK=1"

netstat -an | findstr ":8000" | findstr "LISTENING" >nul 2>&1
if %errorlevel% equ 0 (
    echo    [OK] Backend API:    http://localhost:8000
) else (
    echo    [!!] Backend API:    FAILED
    set "ALL_OK=0"
)

netstat -an | findstr ":8085" | findstr "LISTENING" >nul 2>&1
if %errorlevel% equ 0 (
    echo    [OK] Reverb WS:     ws://localhost:8085
) else (
    echo    [!!] Reverb WS:     FAILED
    set "ALL_OK=0"
)

netstat -an | findstr ":5173" | findstr "LISTENING" >nul 2>&1
if %errorlevel% equ 0 (
    echo    [OK] Frontend SPA:  http://localhost:5173
) else (
    echo    [!!] Frontend SPA:  FAILED (may still be starting)
    set "ALL_OK=0"
)

echo    [OK] PostgreSQL:    localhost:5432
echo    [OK] Redis:         localhost:6379
echo.

if "%ALL_OK%"=="1" (
    echo  ============================================
    echo   All Services Started Successfully!
    echo  ============================================
    echo.
    echo   Opening browser in 3 seconds...
    timeout /t 3 /nobreak >nul
    start http://localhost:5173
) else (
    echo  ============================================
    echo   Some services failed. Check output above.
    echo  ============================================
)

echo.
echo   Horizon:    http://localhost:8000/horizon
echo   Telescope:  http://localhost:8000/telescope
echo.
echo   To stop all services: stop.bat
echo   Press any key to close this window...
pause >nul
