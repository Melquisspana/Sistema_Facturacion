# Probar la restauración de un backup en Windows / Laragon

Guía para **validar** que un backup se puede restaurar, **sin tocar la base real**.
La prueba se hace siempre en una base **temporal**: `dulces_negrita_restore_test`.

> ⚠️ **ADVERTENCIA**: nunca restaures encima de la base real (`dulces_negrita`) sin
> haber hecho antes un backup nuevo. Una restauración sobre la base real **sobre­escribe
> todo**. Para solo *probar* el backup, usá la base temporal de esta guía.

---

## 1. Ubicar el ZIP más reciente

Los backups están en:
```
C:\laragon\www\Facturacion\storage\app\private\Dulces La Negrita\
```
El más reciente es el de fecha/hora más alta, p. ej. `2026-06-16-19-48-53.zip`.

Desde cmd, ver el último:
```cmd
dir /b /o-d "C:\laragon\www\Facturacion\storage\app\private\Dulces La Negrita\*.zip"
```
(El primero de la lista es el más nuevo.)

---

## 2. Extraerlo

> Nota: el backup guarda algunos archivos de `storage/app` con **ruta absoluta**
> (`C:\laragon\...`), y `Expand-Archive` falla con esas entradas. Para la prueba de
> restauración solo necesitás el **dump `.sql`**, así que conviene extraer únicamente
> ese archivo (lo que hace el script de la sección 8). Manualmente podés usar
> **7-Zip** (clic derecho → 7-Zip → Abrir, y sacar `db-dumps\...sql`) o PowerShell
> extrayendo solo el `.sql`:
> ```powershell
> Add-Type -AssemblyName System.IO.Compression.FileSystem
> $z=[System.IO.Compression.ZipFile]::OpenRead("C:\ruta\al\backup.zip")
> $e=$z.Entries | ? { $_.FullName -match '\.sql$' } | Select -First 1
> [System.IO.Compression.ZipFileExtensions]::ExtractToFile($e,"C:\temp\dump.sql",$true); $z.Dispose()
> ```
> El dump queda en `C:\temp\dump.sql`.

---

## 3. Encontrar el archivo .sql

Dentro del backup, el dump está en la subcarpeta `db-dumps`:
```
db-dumps\mysql-dulces_negrita.sql
```
Ese `.sql` es el respaldo de la base. (El resto del zip son archivos de `storage/app`.)

---

## 4. Crear una base temporal

**Nunca uses la base real.** Creá una aparte:
```cmd
"C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root -e "CREATE DATABASE dulces_negrita_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```
(Ajustá la ruta de `mysql.exe` si tu versión de MySQL es distinta.)

---

## 5. Importar el dump en la base temporal

```cmd
"C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root dulces_negrita_restore_test < "C:\temp\restore_test\db-dumps\mysql-dulces_negrita.sql"
```
> Fijate que el destino sea **`dulces_negrita_restore_test`** y NO `dulces_negrita`.

---

## 6. Verificar que restauró tablas y datos

Cantidad de tablas:
```cmd
"C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root -e "SELECT COUNT(*) AS tablas FROM information_schema.tables WHERE table_schema='dulces_negrita_restore_test';"
```
Filas en tablas clave:
```cmd
"C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root dulces_negrita_restore_test -e "SELECT 'clientes' t, COUNT(*) n FROM clientes UNION ALL SELECT 'productos', COUNT(*) FROM productos UNION ALL SELECT 'dtes', COUNT(*) FROM dtes UNION ALL SELECT 'users', COUNT(*) FROM users;"
```
Si ves un número razonable de tablas y filas, el backup es restaurable. ✅

---

## 7. Borrar la base temporal al final

```cmd
"C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root -e "DROP DATABASE IF EXISTS dulces_negrita_restore_test;"
```
Y borrá la carpeta temporal donde extrajiste el zip (`C:\temp\restore_test`).

---

## 8. Script automático (opcional)

`scripts\backup-restore-test.bat` hace los pasos 1–7 de forma guiada y segura:

- Restaura **solo** en `dulces_negrita_restore_test` (jamás toca `dulces_negrita`;
  tiene una guarda que aborta si por error ambas coinciden).
- **Pide confirmación** (`SI`) antes de continuar.
- Usa el **último ZIP** de `storage\app\private\Dulces La Negrita`.
- Extrae en una carpeta temporal, ubica el `.sql`, lo importa en la base temporal.
- Muestra **conteo de tablas** y **filas** de tablas clave.
- Al final ofrece **borrar** la base temporal.
- No imprime contraseñas ni secretos.

Ejecutarlo (manual):
```cmd
cd C:\laragon\www\Facturacion
scripts\backup-restore-test.bat
```
Respondé `SI` para continuar y, al final, `SI`/`NO` para borrar la base temporal.

> El script **no** está pensado para tareas programadas (pide confirmación a propósito).
> Si `mysql.exe` está en otra ruta, editá la línea `MYSQL_BIN` del `.bat`.

---

## 9. Restaurar de verdad sobre la base real (solo en emergencia)

Esto **sobreescribe** la base real. Hacelo únicamente si la base real se dañó/perdió:

1. **Backup nuevo primero** (si la base aún responde): `scripts\backup-run.bat`.
2. Poné el sistema en mantenimiento si aplica.
3. Importá el dump sobre `dulces_negrita` (mismo comando del paso 5 pero con la base real).
4. Restaurá los archivos de `storage/app` del zip a su lugar.
5. Limpiá caches: `php artisan config:clear`, `cache:clear`, `view:clear`.
6. Verificá la app.

> Si hay cualquier duda, primero probá con la base temporal (secciones 4–7).

---

## 10. Seguridad

- La prueba ocurre en una base **separada**; la real no se modifica.
- El dump contiene datos de negocio: tratá la carpeta temporal como sensible y borrala al terminar.
- Ni esta guía ni el script contienen contraseñas. (En esta instalación local el usuario
  `root` de MySQL no tiene contraseña; en un servidor real, MySQL debería tener credenciales
  propias y se usarían con `-u usuario -p`, sin escribir la clave en el script.)
