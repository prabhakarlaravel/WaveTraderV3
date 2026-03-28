@echo off
title WaveTrader V3 - Full Stack Startup
color 0A

echo ============================================
echo   WaveTrader V3 - Starting All Services
echo ============================================
echo.

SET "PROJECT_DIR=%~dp0"
SET "BACKEND_DIR=%PROJECT_DIR%backend"
SET "FRONTEND_DIR=%PROJECT_DIR%frontend"

:: -----------------------------------------------
:: 1. Check prerequisites
:: -----------------------------------------------
echo [1/7] Checking prerequisites...

where php >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: PHP not found in PATH. Install PHP 8.3+ or start Laragon first.
    pause
    exit /b 1
)

where node >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: Node.js not found in PATH. Install Node 20+ via nvm.
    pause
    exit /b 1
)

echo   PHP: OK  ^|  Node: OK

:: -----------------------------------------------
:: 2. Check PostgreSQL is running
:: -----------------------------------------------
echo [2/7] Checking PostgreSQL on port 5432...
netstat -an | findstr ":5432" | findstr "LISTENING" >nul 2>&1
if %errorlevel% neq 0 (
    echo WARNING: PostgreSQL not running. Trying to start...
    net start postgresql-x64-16 >nul 2>&1
    timeout /t 3 /nobreak >nul
    netstat -an | findstr ":5432" | findstr "LISTENING" >nul 2>&1
    if %errorlevel% neq 0 (
        echo ERROR: Could not start PostgreSQL. Start it manually.
        pause
        exit /b 1
    )
)
echo   PostgreSQL: OK

:: -----------------------------------------------
:: 3. Check Redis is running
:: -----------------------------------------------
echo [3/7] Checking Redis on port 6379...
netstat -an | findstr ":6379" | findstr "LISTENING" >nul 2>&1
if %errorlevel% neq 0 (
    echo WARNING: Redis not running. Trying to start...
    start "" /MIN redis-server >nul 2>&1
    timeout /t 2 /nobreak >nul
    netstat -an | findstr ":6379" | findstr "LISTENING" >nul 2>&1
    if %errorlevel% neq 0 (
        echo ERROR: Could not start Redis. Start it manually.
        pause
        exit /b 1
    )
)
echo   Redis: OK

:: -----------------------------------------------
:: 4. Install dependencies if needed
:: -----------------------------------------------
echo [4/7] Checking dependencies...

if not exist "%BACKEND_DIR%\vendor" (
    echo   Installing backend dependencies...
    pushd "%BACKEND_DIR%"
    composer install --no-interaction --quiet
    popd
) else (
    echo   Backend dependencies: OK
)

if not exist "%FRONTEND_DIR%\node_modules" (
    echo   Installing frontend dependencies...
    pushd "%FRONTEND_DIR%"
    call npm install --silent
    popd
) else (
    echo   Frontend dependencies: OK
)

:: -----------------------------------------------
:: 5. Run migrations
:: -----------------------------------------------
echo [5/7] Running database migrations...
pushd "%BACKEND_DIR%"
php artisan migrate --force --quiet 2>nul
if %errorlevel% neq 0 (
    echo   WARNING: Migrations may have failed. Check database.
) else (
    echo   Migrations: OK
)
popd

:: -----------------------------------------------
:: 6. Kill any existing WaveTrader processes
:: -----------------------------------------------
echo [6/7] Cleaning up old processes...
taskkill /FI "WINDOWTITLE eq WaveTrader*" /F >nul 2>&1
timeout /t 1 /nobreak >nul

:: -----------------------------------------------
:: 7. Start all services in separate windows
:: -----------------------------------------------
echo [7/7] Starting services...
echo.

:: Backend API (Laravel)
echo   Starting Laravel API on http://localhost:8000 ...
start "WaveTrader - Backend API" /D "%BACKEND_DIR%" cmd /k "php artisan serve --host=0.0.0.0 --port=8000"
timeout /t 3 /nobreak >nul

:: Laravel Reverb (WebSocket server)
echo   Starting Reverb WebSocket on ws://localhost:8085 ...
start "WaveTrader - Reverb WebSocket" /D "%BACKEND_DIR%" cmd /k "php artisan reverb:start --port=8085"
timeout /t 2 /nobreak >nul

:: Laravel Queue Worker (processes candle fetch + engine jobs)
echo   Starting Queue Worker...
start "WaveTrader - Queue Worker" /D "%BACKEND_DIR%" cmd /k "php artisan queue:work redis --queue=default,engines --sleep=3 --tries=3"
timeout /t 1 /nobreak >nul

:: Frontend (Vite dev server)
echo   Starting Vue frontend on http://localhost:5173 ...
start "WaveTrader - Frontend" /D "%FRONTEND_DIR%" cmd /k "npm run dev"
timeout /t 3 /nobreak >nul

:: -----------------------------------------------
:: Done
:: -----------------------------------------------
echo.
echo ============================================
echo   All Services Started Successfully!
echo ============================================
echo.
echo   Backend API:    http://localhost:8000
echo   Frontend SPA:   http://localhost:5173
echo   WebSocket:      ws://localhost:8085
echo   Horizon:        http://localhost:8000/horizon
echo   Telescope:      http://localhost:8000/telescope
echo   PostgreSQL:     localhost:5432
echo   Redis:          localhost:6379
echo ============================================
echo.
echo   Opening browser...
timeout /t 2 /nobreak >nul
start http://localhost:5173
echo.
echo   To stop all services run: stop.bat
echo   Press any key to exit this window...
pause >nul
