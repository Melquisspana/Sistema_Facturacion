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

REM --- Carpeta del proyecto (donde esta "artisan") ---
set "PROJECT_DIR=C:\laragon\www\Facturacion"

REM --- PHP de Laragon. Ajustar si cambia la version de PHP. ---
set "PHP_BIN=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
if not exist "%PHP_BIN%" set "PHP_BIN=php"

cd /d "%PROJECT_DIR%"

echo [%date% %time%] Iniciando backup:clean ...
"%PHP_BIN%" artisan backup:clean
set "RC=%ERRORLEVEL%"
echo [%date% %time%] backup:clean termino con codigo %RC%

if "%~1"=="" pause
endlocal & exit /b %RC%
