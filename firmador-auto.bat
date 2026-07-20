@echo off
setlocal enableextensions
title Firmador local MH (AUTOMATICO) - Facturacion

rem ------------------------------------------------------------------
rem  Levanta el FIRMADOR LOCAL del MH (servicio Java/Spring incluido en
rem  resources\firmador\...). Usa el JDK 21 que YA VIENE con el firmador
rem  (no requiere Java instalado en el sistema).
rem
rem  QUE HACE Y QUE NO HACE:
rem   - Solo ARRANCA el proceso Java del firmador cuando vos lo ejecutas.
rem   - NO activa la firma del sistema (eso lo decide DTE_FIRMA_ENABLED en
rem     .env, que este script NO toca).
rem   - NO transmite nada a Hacienda, NO emite, NO envia correos.
rem   - Firmar en local NO equivale a emitir: un DTE solo queda emitido
rem     tras firma + transmision + sello. Este .bat no hace nada de eso.
rem
rem  Solo es util cuando decidas firmar (fase de emision). En modo
rem  paralelo seguro el firmador puede estar apagado sin problema.
rem
rem  Health check (no firma): http://localhost:8080/firmardocumento/status
rem ------------------------------------------------------------------

rem Carpeta de trabajo del firmador (relativa a este .bat, en la raiz del proyecto).
set "FIRMADOR_DIR=%~dp0resources\firmador\dte-firmador\dte-firmador\servicioFirmadoWindows"
set "JAR=svfe-api-firmador-2.0.0-exec.jar"

rem Java propio del firmador (JDK 21 incluido). Si no existe, se intenta el java del sistema.
set "JAVA=%FIRMADOR_DIR%\jdk-21\bin\java.exe"
if not exist "%JAVA%" set "JAVA=java"

if not exist "%FIRMADOR_DIR%\%JAR%" (
    echo No se encontro el firmador: "%FIRMADOR_DIR%\%JAR%"
    echo Revisa docs\FIRMADOR_LOCAL.md. No se arranco nada.
    pause
    exit /b 1
)

cd /d "%FIRMADOR_DIR%"

echo ============================================================
echo   FIRMADOR LOCAL MH - Facturacion
echo.
echo   Arrancando el servicio Java del firmador (perfil nonssl).
echo   Status: http://localhost:8080/firmardocumento/status
echo.
echo   Este proceso NO emite, NO transmite y NO toca .env.
echo   Deja esta ventana abierta mientras firmas.
echo ============================================================
echo.

:loop
rem Perfil nonssl = HTTP en localhost (recomendado). El JAR trae 8113 por defecto;
rem se fuerza 8080 porque es el puerto que la app espera (DTE_FIRMADOR_URL).
"%JAVA%" -Dspring.profiles.active=nonssl -jar "%JAR%" --server.port=8080 --server.address=127.0.0.1

rem Si el firmador se detuvo (cierre, error o reinicio), esperar y relanzar.
rem Backoff corto para no entrar en un bucle apretado ante un error persistente.
echo.
echo El firmador se detuvo. Reintentando en 5 segundos (Ctrl+C para salir)...
timeout /t 5 /nobreak >nul
goto loop
