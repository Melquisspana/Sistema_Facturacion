@echo off
setlocal enableextensions

rem ==========================================================================
rem  Backup DIARIO verificado de la base de datos (mysqldump + SHA-256 +
rem  registro en respaldo_ejecuciones). Ejecuta: php artisan backup:mysql-diario
rem
rem  Distinto de scripts\backup-run.bat (spatie/laravel-backup, zip completo
rem  app+BD): este es el mecanismo que alimenta el check "Backup del día
rem  listo" del readiness de producción. Los dumps quedan en backups\ con
rem  prefijo "auto-" (la retención NUNCA borra archivos sin ese prefijo).
rem
rem  Uso manual: doble clic, o desde cmd: scripts\windows\backup-diario.bat
rem  Programado: ver docs\WORKER_WINDOWS.md / docs\BACKUPS_WINDOWS.md
rem ==========================================================================

cd /d "%~dp0..\.."
set "PROJECT_DIR=%CD%"

if defined PHP_BIN_DTE (
    set "PHP=%PHP_BIN_DTE%"
) else (
    set "PHP="
    for /d %%D in ("C:\laragon\bin\php\php-*") do set "PHP=%%D\php.exe"
    if not defined PHP set "PHP=php"
    if not exist "%PHP%" if /i not "%PHP%"=="php" set "PHP=php"
)

echo [%date% %time%] Iniciando backup:mysql-diario ...
"%PHP%" "%PROJECT_DIR%\artisan" backup:mysql-diario --origen=automatico
set "RC=%ERRORLEVEL%"
echo [%date% %time%] backup:mysql-diario termino con codigo %RC%

if "%~1"=="" pause
endlocal & exit /b %RC%
