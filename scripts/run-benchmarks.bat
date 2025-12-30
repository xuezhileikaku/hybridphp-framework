@echo off
REM HybridPHP Performance Benchmark Runner for Windows
REM Usage: run-benchmarks.bat [options]

setlocal enabledelayedexpansion

echo ╔══════════════════════════════════════════════════════════════╗
echo ║         HybridPHP Performance Benchmark Suite                ║
echo ╚══════════════════════════════════════════════════════════════╝
echo.

REM Check if PHP is available
where php >nul 2>nul
if %ERRORLEVEL% neq 0 (
    echo ❌ PHP not found in PATH
    echo Please install PHP and add it to your PATH
    exit /b 1
)

REM Check if vendor directory exists
if not exist "vendor" (
    echo ❌ Vendor directory not found
    echo Please run: composer install
    exit /b 1
)

REM Create output directory
if not exist "storage\benchmarks" mkdir storage\benchmarks

REM Run benchmarks
echo Running benchmarks...
echo.

php scripts/run-benchmarks.php --all %*

echo.
echo ✅ Benchmark completed!
echo Reports saved to: storage\benchmarks\
