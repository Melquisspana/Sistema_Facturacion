@echo off
setlocal enableextensions
title DTE Queue Worker (automatico)

rem ==========================================================================
rem  Worker DESATENDIDO del sistema DTE para el Programador de tareas de
rem  Windows (queue:work sobre QUEUE_CONNECTION=database).
rem
rem  - Se reinicia solo si el proceso se cae (bucle con backoff).
rem  - No imprime secretos (ninguna credencial pasa por argumentos ni salida).
rem  - Guarda logs en storage\logs\queue-worker-YYYY-MM-DD.log (uno por dia).
rem  - Antes de arrancar, verifica que NO haya ya otra instancia corriendo
rem    contra ESTE proyecto (evita workers duplicados si alguien lo arranca
rem    dos veces). El Programador de tareas ademas debe registrarse con
rem    "No iniciar una instancia nueva" (ver registrar-tareas-windows.ps1).
rem
rem  Uso manual: doble clic, o desde cmd: scripts\windows\queue-worker-auto.bat
rem  Programado: ver docs\WORKER_WINDOWS.md
rem ==========================================================================

rem --- Carpeta del proyecto = dos niveles arriba de este script (scripts\windows\..\..). ---
cd /d "%~dp0..\.."
set "PROJECT_DIR=%CD%"

rem --- PHP: variable opcional PHP_BIN_DTE, si no, ultima version de Laragon, si no, PATH. ---
if defined PHP_BIN_DTE (
    set "PHP=%PHP_BIN_DTE%"
) else (
    set "PHP="
    for /d %%D in ("C:\laragon\bin\php\php-*") do set "PHP=%%D\php.exe"
    if not defined PHP set "PHP=php"
    if not exist "%PHP%" if /i not "%PHP%"=="php" set "PHP=php"
)

set "ARTISAN=%PROJECT_DIR%\artisan"
if not exist "%PROJECT_DIR%\storage\logs" mkdir "%PROJECT_DIR%\storage\logs" >nul 2>nul

rem --- Evita duplicados: ¿ya hay un php.exe corriendo "queue:work" para ESTE artisan? ---
for /f "usebackq delims=" %%C in (`powershell -NoProfile -Command ^
    "(Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -and $_.CommandLine.Contains('queue:work') -and $_.CommandLine.Contains('%ARTISAN%') }).Count"`) do set YA_CORRE=%%C
if not "%YA_CORRE%"=="0" (
    echo [%date% %time%] Ya hay un worker corriendo para este proyecto ^(detectado %YA_CORRE%^). No se inicia otro.
    exit /b 0
)

echo [%date% %time%] Iniciando DTE Queue Worker. PHP="%PHP%" ARTISAN="%ARTISAN%"

:loop
set "LOGFILE=%PROJECT_DIR%\storage\logs\queue-worker-%date:~-4%-%date:~3,2%-%date:~0,2%.log"
echo [%date% %time%] queue:work arrancando >> "%LOGFILE%"

rem --tries=3: reintenta un job fallido hasta 3 veces antes de mandarlo a failed_jobs.
rem --sleep=3: espera 3s cuando la cola esta vacia (no satura la BD con SELECTs).
rem --timeout=60: un job que tarde mas de 60s (firma/transmision/correo) se corta y
rem   se marca como fallido en vez de colgar el worker indefinidamente.
rem --max-time=3600: se recicla cada hora (libera memoria); este bucle lo relevanta.
"%PHP%" "%ARTISAN%" queue:work --tries=3 --sleep=3 --timeout=60 --max-time=3600 >> "%LOGFILE%" 2>>&1

echo [%date% %time%] queue:work termino con codigo %ERRORLEVEL%, reiniciando en 5s >> "%LOGFILE%"
timeout /t 5 /nobreak >nul
goto loop
