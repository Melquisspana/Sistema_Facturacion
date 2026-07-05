@echo off
setlocal enableextensions
title Reiniciar worker de correos - Facturacion

rem Ubicarse en la carpeta del proyecto (donde vive este .bat).
cd /d "%~dp0"

rem Detectar el PHP de Laragon automaticamente (toma la ultima version instalada).
rem Si tu instalacion es distinta, edita la linea "set PHP=" de abajo con la ruta a php.exe.
set "PHP="
for /d %%D in ("C:\laragon\bin\php\php-*") do set "PHP=%%D\php.exe"
if not defined PHP set "PHP=php"
if not exist "%PHP%" if /i not "%PHP%"=="php" set "PHP=php"

echo ============================================================
echo   REINICIAR WORKER DE CORREOS - Facturacion
echo.
echo   Envia la senal de reinicio elegante: los workers en curso
echo   terminan su ciclo actual y arrancan de nuevo tomando el
echo   codigo mas reciente. Usalo tras actualizar el sistema.
echo.
echo   PHP: %PHP%
echo ============================================================
echo.

"%PHP%" artisan queue:restart

echo.
echo   Listo. Si usas 'queue-worker-auto.bat' o 'start-queue.bat',
echo   el worker se reinicia solo en pocos segundos.
echo.
pause >nul
