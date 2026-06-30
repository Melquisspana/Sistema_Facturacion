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

REM --- Carpeta del proyecto (donde esta "artisan") ---
set "PROJECT_DIR=C:\laragon\www\Facturacion"

REM --- PHP de Laragon. Ajustar si cambia la version de PHP. ---
set "PHP_BIN=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
if not exist "%PHP_BIN%" set "PHP_BIN=php"

cd /d "%PROJECT_DIR%"

echo [%date% %time%] Iniciando backup:run ...
"%PHP_BIN%" artisan backup:run
set "RC=%ERRORLEVEL%"
echo [%date% %time%] backup:run termino con codigo %RC%

REM Pausa solo si se ejecuta con doble clic (no en tarea programada).
if "%~1"=="" pause
endlocal & exit /b %RC%
