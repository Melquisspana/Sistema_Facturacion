@echo off
REM ==========================================================================
REM  Limpieza de backups antiguos segun la politica de retencion de
REM  config\backup.php (seccion "cleanup" / "default_strategy").
REM  Ejecuta: php artisan backup:clean
REM
REM  Uso manual: doble clic, o desde cmd:  scripts\backup-clean.bat
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

echo [%date% %time%] Iniciando backup:clean ...
"%PHP_BIN%" artisan backup:clean
set "RC=%ERRORLEVEL%"
echo [%date% %time%] backup:clean termino con codigo %RC%

if "%~1"=="" pause
endlocal & exit /b %RC%
