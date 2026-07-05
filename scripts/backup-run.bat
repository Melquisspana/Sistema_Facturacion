@echo off
REM ==========================================================================
REM  Backup de la base de datos + storage (spatie/laravel-backup).
REM  Ejecuta: php artisan backup:run
REM  Los backups quedan en: storage\app\private\Dulces La Negrita\*.zip
REM
REM  Uso manual: doble clic, o desde cmd:  scripts\backup-run.bat
REM  Programado: ver docs\BACKUPS_WINDOWS.md
REM ==========================================================================
setlocal

REM --- Carpeta del proyecto = carpeta padre de este script (scripts\..). ---
cd /d "%~dp0.."
set "PROJECT_DIR=%CD%"

REM --- PHP de Laragon (ultima version instalada); si no, el del PATH. ---
set "PHP_BIN="
for /d %%D in ("C:\laragon\bin\php\php-*") do set "PHP_BIN=%%D\php.exe"
if not defined PHP_BIN set "PHP_BIN=php"
if not exist "%PHP_BIN%" if /i not "%PHP_BIN%"=="php" set "PHP_BIN=php"

echo [%date% %time%] Iniciando backup:run ...
"%PHP_BIN%" artisan backup:run
set "RC=%ERRORLEVEL%"
echo [%date% %time%] backup:run termino con codigo %RC%

REM Pausa solo si se ejecuta con doble clic (no en tarea programada).
if "%~1"=="" pause
endlocal & exit /b %RC%
