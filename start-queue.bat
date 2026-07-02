@echo off
setlocal enableextensions
title Worker de correos - Facturacion (DEJAR ESTA VENTANA ABIERTA)

rem Ubicarse en la carpeta del proyecto (donde vive este .bat).
cd /d "%~dp0"

rem Detectar el PHP de Laragon automaticamente (toma la ultima version instalada).
rem Si tu instalacion es distinta, edita la linea "set PHP=" de abajo con la ruta a php.exe.
set "PHP="
for /d %%D in ("C:\laragon\bin\php\php-*") do set "PHP=%%D\php.exe"
if not defined PHP set "PHP=php"
if not exist "%PHP%" if /i not "%PHP%"=="php" set "PHP=php"

:loop
cls
echo ============================================================
echo   WORKER DE CORREOS - Facturacion
echo.
echo   DEJA ESTA VENTANA ABIERTA mientras facturas.
echo   Los correos SOLO salen mientras este worker este corriendo.
echo.
echo   PHP: %PHP%
echo   Detener: cierra la ventana o presiona Ctrl+C.
echo ============================================================
echo.

"%PHP%" artisan queue:work --tries=1 --sleep=3

echo.
echo ------------------------------------------------------------
echo   El worker SE DETUVO. Los correos NO saldran hasta reiniciarlo.
echo   Presiona una tecla para REINICIAR, o cierra la ventana.
echo ------------------------------------------------------------
pause >nul
goto loop
