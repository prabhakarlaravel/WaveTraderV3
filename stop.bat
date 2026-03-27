@echo off
title WaveTrader V3 - Stop All Services
color 0C

echo ============================================
echo   WaveTrader V3 - Stopping All Services
echo ============================================
echo.

:: Kill PHP processes (artisan serve, reverb, horizon)
echo Stopping PHP processes...
taskkill /FI "WINDOWTITLE eq WaveTrader - Backend API*" /F >nul 2>&1
taskkill /FI "WINDOWTITLE eq WaveTrader - Reverb WebSocket*" /F >nul 2>&1
taskkill /FI "WINDOWTITLE eq WaveTrader - Horizon Queue*" /F >nul 2>&1

:: Kill Node processes (Vite dev server)
echo Stopping Node processes...
taskkill /FI "WINDOWTITLE eq WaveTrader - Frontend*" /F >nul 2>&1

echo.
echo All WaveTrader services stopped.
echo.
pause
