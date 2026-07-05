@echo off
setlocal enableextensions
title Worker de correos (AUTOMATICO) - Facturacion

rem ------------------------------------------------------------------
rem  Worker DESATENDIDO para el Programador de tareas de Windows.
rem
rem  A diferencia de start-queue.bat (uso manual, espera una tecla si
rem  se cae), este se REINICIA SOLO: no pide teclas y no se queda
rem  esperando. Pensado para ejecutarse en segundo plano al iniciar la
rem  PC ("Ejecutar tanto si el usuario inicio sesion como si no" =
rem  sin ventana visible). No instala nada extra.
rem ------------------------------------------------------------------

rem Ubicarse en la carpeta del proyecto (donde vive este .bat).
cd /d "%~dp0"

rem Detectar el PHP de Laragon automaticamente (toma la ultima version instalada).
rem Si tu instalacion es distinta, edita la linea "set PHP=" de abajo con la ruta a php.exe.
set "PHP="
for /d %%D in ("C:\laragon\bin\php\php-*") do set "PHP=%%D\php.exe"
if not defined PHP set "PHP=php"
if not exist "%PHP%" if /i not "%PHP%"=="php" set "PHP=php"

:loop
rem --max-time=3600: el worker se recicla cada hora (libera memoria y toma
rem   'queue:restart'); este bucle lo vuelve a levantar enseguida.
"%PHP%" artisan queue:work --tries=1 --sleep=3 --max-time=3600

rem Si el worker termino (reciclaje, caida o queue:restart), esperar unos
rem segundos y volver a levantarlo. El backoff evita un bucle apretado si
rem hay un error persistente (p. ej. base de datos caida).
timeout /t 5 /nobreak >nul
goto loop
