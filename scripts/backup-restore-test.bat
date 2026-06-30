@echo off
REM ==========================================================================
REM  PRUEBA SEGURA DE RESTAURACION DE BACKUP.
REM
REM  Restaura el ultimo backup SOLO en una base TEMPORAL llamada
REM  "dulces_negrita_restore_test". NUNCA toca la base real "dulces_negrita".
REM
REM  - Pide confirmacion antes de continuar.
REM  - Usa el ZIP mas reciente de storage\app\private\Dulces La Negrita.
REM  - Extrae en una carpeta temporal y busca el .sql.
REM  - Importa el dump en la base temporal y muestra conteos basicos.
REM  - Al final ofrece borrar la base temporal.
REM  - No muestra contrasenas ni secretos.
REM ==========================================================================
setlocal EnableDelayedExpansion

set "PROJECT_DIR=C:\laragon\www\Facturacion"
set "BACKUP_DIR=%PROJECT_DIR%\storage\app\private\Dulces La Negrita"
REM Ajustar si cambia la version de MySQL en Laragon:
set "MYSQL_BIN=C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe"
set "REAL_DB=dulces_negrita"
set "TEMP_DB=dulces_negrita_restore_test"
set "WORK_DIR=%TEMP%\restore_test_%RANDOM%"

echo ==========================================================================
echo  Prueba de restauracion en base TEMPORAL: %TEMP_DB%
echo  La base real (%REAL_DB%) NO se toca.
echo ==========================================================================
echo.

REM --- Guarda de seguridad: la temporal jamas puede ser la real ---
if /I "%TEMP_DB%"=="%REAL_DB%" (
    echo ERROR de seguridad: la base temporal coincide con la real. Abortando.
    goto :fin
)

set /p "CONFIRM=Escriba SI (mayusculas) para continuar: "
if /I not "%CONFIRM%"=="SI" (
    echo Cancelado por el usuario.
    goto :fin
)

if not exist "%MYSQL_BIN%" (
    echo ERROR: no se encontro mysql.exe en "%MYSQL_BIN%". Ajuste MYSQL_BIN en este .bat.
    goto :fin
)

REM --- 1. Ubicar el ZIP mas reciente (orden por fecha desc) ---
set "ZIP="
for /f "delims=" %%F in ('dir /b /a-d /o-d "%BACKUP_DIR%\*.zip" 2^>nul') do (
    if not defined ZIP set "ZIP=%BACKUP_DIR%\%%F"
)
if not defined ZIP (
    echo ERROR: no hay archivos .zip en "%BACKUP_DIR%".
    goto :fin
)
echo ZIP a probar: %ZIP%

REM --- 2-3. Extraer SOLO el dump .sql (el zip puede traer entradas con rutas
REM         absolutas que Expand-Archive no soporta; usamos .NET para sacar
REM         unicamente el .sql del backup). ---
mkdir "%WORK_DIR%" 2>nul
set "SQL=%WORK_DIR%\dump.sql"
powershell -NoProfile -Command "Add-Type -AssemblyName System.IO.Compression.FileSystem; $z=[System.IO.Compression.ZipFile]::OpenRead('%ZIP%'); $e=$z.Entries | Where-Object { $_.FullName -match '\.sql$' } | Select-Object -First 1; if($e){ [System.IO.Compression.ZipFileExtensions]::ExtractToFile($e,'%SQL%',$true) }; $z.Dispose()"
if not exist "%SQL%" (
    echo ERROR: no se encontro/extrajo un archivo .sql dentro del backup.
    goto :limpiar
)
echo Dump extraido en: %SQL%

REM --- 4. (Re)crear la base temporal (DROP/CREATE solo de la TEMPORAL) ---
echo Creando base temporal %TEMP_DB% ...
"%MYSQL_BIN%" -u root -e "DROP DATABASE IF EXISTS `%TEMP_DB%`; CREATE DATABASE `%TEMP_DB%` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 ( echo ERROR creando la base temporal. & goto :limpiar )

REM --- 5. Importar el dump en la base temporal ---
echo Importando dump en %TEMP_DB% ... (puede tardar)
"%MYSQL_BIN%" -u root %TEMP_DB% < "%SQL%"
if errorlevel 1 ( echo ERROR importando el dump. & goto :limpiar )

REM --- 6. Verificacion: conteos basicos ---
echo.
echo --- Tablas restauradas en %TEMP_DB% ---
"%MYSQL_BIN%" -u root -e "SELECT COUNT(*) AS total_tablas FROM information_schema.tables WHERE table_schema='%TEMP_DB%';"
echo --- Filas en tablas clave ---
"%MYSQL_BIN%" -u root %TEMP_DB% -e "SELECT 'clientes' AS tabla, COUNT(*) AS filas FROM clientes UNION ALL SELECT 'productos', COUNT(*) FROM productos UNION ALL SELECT 'dtes', COUNT(*) FROM dtes UNION ALL SELECT 'users', COUNT(*) FROM users;" 2>nul

echo.
echo ====================================================================
echo  PRUEBA EXITOSA: el backup se restauro en la base temporal %TEMP_DB%.
echo  La base real %REAL_DB% NO fue modificada.
echo ====================================================================

:limpiar
echo.
set /p "DROP=Borrar la base temporal %TEMP_DB% ahora? (SI/NO): "
if /I "%DROP%"=="SI" (
    "%MYSQL_BIN%" -u root -e "DROP DATABASE IF EXISTS `%TEMP_DB%`;"
    echo Base temporal eliminada.
) else (
    echo Se deja %TEMP_DB% para que la inspecciones. Borrela luego con:
    echo   "%MYSQL_BIN%" -u root -e "DROP DATABASE IF EXISTS `%TEMP_DB%`;"
)
if exist "%WORK_DIR%" rmdir /s /q "%WORK_DIR%"

:fin
echo.
if "%~1"=="" pause
endlocal
