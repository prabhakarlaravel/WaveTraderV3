@echo off
title WaveTrader V3 - Full Stack Startup
color 0A

echo ============================================
echo   WaveTrader V3 - Starting All Services
echo ============================================
echo.

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

where composer >nul 2>&1
if %errorlevel% neq 0 (
    echo WARNING: Composer not found. Skipping dependency check.
) else (
    echo   PHP: OK  ^|  Node: OK  ^|  Composer: OK
)

:: -----------------------------------------------
:: 2. Check PostgreSQL is running
:: -----------------------------------------------
echo [2/7] Checking PostgreSQL on port 5432...
netstat -an | findstr ":5432" | findstr "LISTENING" >nul 2>&1
if %errorlevel% neq 0 (
    echo WARNING: PostgreSQL does not appear to be running on port 5432.
    echo   Start it via: net start postgresql-x64-16
    echo   Or start Laragon / pgAdmin.
    echo.
)

:: -----------------------------------------------
:: 3. Check Redis is running
:: -----------------------------------------------
echo [3/7] Checking Redis on port 6379...
netstat -an | findstr ":6379" | findstr "LISTENING" >nul 2>&1
if %errorlevel% neq 0 (
    echo WARNING: Redis does not appear to be running on port 6379.
    echo   Start it via: redis-server
    echo   Or start Laragon.
    echo.
)

:: -----------------------------------------------
:: 4. Install dependencies if needed
:: -----------------------------------------------
echo [4/7] Checking dependencies...

if not exist "backend\vendor" (
    echo   Installing backend dependencies...
    cd backend
    composer install --no-interaction --quiet
    cd ..
) else (
    echo   Backend dependencies: OK
)

if not exist "frontend\node_modules" (
    echo   Installing frontend dependencies...
    cd frontend
    npm install --silent
    cd ..
) else (
    echo   Frontend dependencies: OK
)

:: -----------------------------------------------
:: 5. Run migrations
:: -----------------------------------------------
echo [5/7] Running database migrations...
cd backend
php artisan migrate --force --quiet 2>nul
if %errorlevel% neq 0 (
    echo   WARNING: Migrations failed. Check database connection.
) else (
    echo   Migrations: OK
)
cd ..

:: -----------------------------------------------
:: 6. Start all services in separate windows
:: -----------------------------------------------
echo [6/7] Starting services...
echo.

:: Backend API (Laravel)
echo   Starting Laravel API on http://localhost:8000 ...
start "WaveTrader - Backend API" cmd /k "cd /d %~dp0backend && php artisan serve --host=0.0.0.0 --port=8000"

:: Wait a moment for backend to start
timeout /t 2 /nobreak >nul

:: Laravel Reverb (WebSocket server)
echo   Starting Reverb WebSocket on ws://localhost:8085 ...
start "WaveTrader - Reverb WebSocket" cmd /k "cd /d %~dp0backend && php artisan reverb:start --port=8085"

:: Laravel Horizon (Queue worker)
echo   Starting Horizon queue worker...
start "WaveTrader - Horizon Queue" cmd /k "cd /d %~dp0backend && php artisan horizon"

:: Frontend (Vite dev server)
echo   Starting Vue frontend on http://localhost:5173 ...
start "WaveTrader - Frontend" cmd /k "cd /d %~dp0frontend && npm run dev"

:: -----------------------------------------------
:: 7. Done
:: -----------------------------------------------
echo.
echo [7/7] All services started!
echo.
echo ============================================
echo   Services Running:
echo ============================================
echo   Backend API:    http://localhost:8000
echo   Frontend SPA:   http://localhost:5173
echo   WebSocket:      ws://localhost:8085
echo   Horizon:        http://localhost:8000/horizon
echo   Telescope:      http://localhost:8000/telescope
echo   PostgreSQL:     localhost:5432
echo   Redis:          localhost:6379
echo ============================================
echo.
echo   Press any key to open the app in browser...
pause >nul

start http://localhost:5173

echo.
echo   To stop all services, close the terminal windows
echo   or run: stop.bat
echo.
pause
