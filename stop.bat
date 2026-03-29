@echo off
title WaveTrader V3 - Stop All Services
color 0C

echo.
echo  ============================================
echo   WaveTrader V3 - Stopping All Services
echo  ============================================
echo.

:: Kill by window title
echo  Stopping Backend API...
taskkill /FI "WINDOWTITLE eq WaveTrader - Backend API*" /F >nul 2>&1

echo  Stopping Reverb WebSocket...
taskkill /FI "WINDOWTITLE eq WaveTrader - Reverb WebSocket*" /F >nul 2>&1

echo  Stopping Queue Worker...
taskkill /FI "WINDOWTITLE eq WaveTrader - Queue Worker*" /F >nul 2>&1

echo  Stopping Frontend...
taskkill /FI "WINDOWTITLE eq WaveTrader - Frontend*" /F >nul 2>&1

:: Kill orphaned processes on specific ports
echo  Cleaning up port 8000 (Backend)...
for /f "tokens=5" %%a in ('netstat -ano 2^>nul ^| findstr ":8000 " ^| findstr "LISTENING"') do (
    taskkill /PID %%a /F >nul 2>&1
)

echo  Cleaning up port 8085 (Reverb)...
for /f "tokens=5" %%a in ('netstat -ano 2^>nul ^| findstr ":8085 " ^| findstr "LISTENING"') do (
    taskkill /PID %%a /F >nul 2>&1
)

echo  Cleaning up port 5173 (Frontend)...
for /f "tokens=5" %%a in ('netstat -ano 2^>nul ^| findstr ":5173 " ^| findstr "LISTENING"') do (
    taskkill /PID %%a /F >nul 2>&1
)

timeout /t 2 /nobreak >nul

:: Verify ports are released
echo.
set "CLEAN=1"
netstat -an | findstr ":8000" | findstr "LISTENING" >nul 2>&1
if %errorlevel% equ 0 (
    echo  [!!] Port 8000 still in use
    set "CLEAN=0"
) else (
    echo  [OK] Port 8000 released
)
netstat -an | findstr ":8085" | findstr "LISTENING" >nul 2>&1
if %errorlevel% equ 0 (
    echo  [!!] Port 8085 still in use
    set "CLEAN=0"
) else (
    echo  [OK] Port 8085 released
)
netstat -an | findstr ":5173" | findstr "LISTENING" >nul 2>&1
if %errorlevel% equ 0 (
    echo  [!!] Port 5173 still in use
    set "CLEAN=0"
) else (
    echo  [OK] Port 5173 released
)

echo.
if "%CLEAN%"=="1" (
    echo  ============================================
    echo   All WaveTrader services stopped cleanly.
    echo  ============================================
) else (
    echo  ============================================
    echo   Some ports still in use. May need manual cleanup.
    echo  ============================================
)
echo.
pause
