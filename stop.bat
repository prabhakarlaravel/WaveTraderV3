@echo off
title WaveTrader V3 - Stop All Services
color 0C

echo ============================================
echo   WaveTrader V3 - Stopping All Services
echo ============================================
echo.

:: Kill by window title (most reliable on Windows)
echo Stopping Backend API...
taskkill /FI "WINDOWTITLE eq WaveTrader - Backend API*" /F >nul 2>&1

echo Stopping Reverb WebSocket...
taskkill /FI "WINDOWTITLE eq WaveTrader - Reverb WebSocket*" /F >nul 2>&1

echo Stopping Queue Worker...
taskkill /FI "WINDOWTITLE eq WaveTrader - Queue Worker*" /F >nul 2>&1

echo Stopping Frontend...
taskkill /FI "WINDOWTITLE eq WaveTrader - Frontend*" /F >nul 2>&1

:: Also kill any orphaned php artisan processes on our ports
echo Cleaning up orphaned processes...
for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":8000" ^| findstr "LISTENING"') do (
    taskkill /PID %%a /F >nul 2>&1
)
for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":8085" ^| findstr "LISTENING"') do (
    taskkill /PID %%a /F >nul 2>&1
)

echo.
echo ============================================
echo   All WaveTrader services stopped.
echo ============================================
echo.
pause
