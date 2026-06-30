# Backups en Windows / Laragon

Guía para hacer copias de seguridad automáticas de **Dulces La Negrita** usando
`spatie/laravel-backup` (ya instalado) en una PC con Windows + Laragon.

> No toca lógica del sistema. Solo backups (base de datos + archivos de `storage`).
> La tarea programada de Windows **no** se crea automáticamente: esta guía deja los
> scripts listos y explica cómo programarla a mano.

---

## 1. Dónde quedan los backups

Cada backup es un único `.zip` con el **dump de la base de datos** y los archivos
de `storage/app`, guardado en:

```
C:\laragon\www\Facturacion\storage\app\private\Dulces La Negrita\
```

El nombre incluye fecha y hora, por ejemplo `2026-06-16-01-30-00.zip`.

(La carpeta "Dulces La Negrita" sale de `APP_NAME`; el disco destino es `local`,
definido en `config/backup.php` → `destination.disks = ['local']`.)

Además existen copias manuales del **proyecto completo** (código) en:

```
C:\laragon\backups\Facturacion_YYYYMMDD_HHMMSS.zip
```

---

## 2. Scripts incluidos

En la carpeta `scripts\` del proyecto:

| Script | Qué hace |
|--------|----------|
| `scripts\backup-run.bat`   | Crea un backup nuevo (`php artisan backup:run`). |
| `scripts\backup-clean.bat` | Borra backups viejos según la retención de `config\backup.php` (`php artisan backup:clean`). |

Ambos:
- Se posicionan solos en la carpeta del proyecto.
- Usan el PHP de Laragon (`C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`);
  si esa ruta cambia con una actualización de Laragon, **editá la línea `PHP_BIN`**
  del `.bat` o asegurate de tener `php` en el PATH.
- Hacen `pause` solo cuando se ejecutan con doble clic (en tarea programada no pausan).

> Requisito ya cubierto: `backup:run` usa `mysqldump`. La ruta está en `.env`
> (`DB_DUMP_PATH=C:/laragon/bin/mysql/.../bin`). Si actualizás MySQL, ajustá esa ruta.

---

## 3. Ejecutar un backup manual

Opción A — doble clic:
- Abrí `C:\laragon\www\Facturacion\scripts\` y hacé doble clic en `backup-run.bat`.

Opción B — desde la terminal (cmd):
```cmd
cd C:\laragon\www\Facturacion
scripts\backup-run.bat
```

Para limpiar backups viejos a mano:
```cmd
scripts\backup-clean.bat
```

Verificá que apareció un `.zip` nuevo en
`storage\app\private\Dulces La Negrita\`.

---

## 4. Programar en el Programador de tareas de Windows

Recomendado: **un backup diario** y **una limpieza diaria**.

### 4.1 Con la interfaz gráfica
1. Abrí **Programador de tareas** (Task Scheduler).
2. **Crear tarea…** (no "tarea básica", para poder correr aunque no haya sesión).
3. Pestaña **General**: nombre `Backup Facturacion - Run`. Marcar
   *"Ejecutar tanto si el usuario inició sesión como si no"* y
   *"Ejecutar con los privilegios más altos"*.
4. Pestaña **Desencadenadores** → Nuevo → Diariamente, hora `01:30`.
5. Pestaña **Acciones** → Nueva → *Iniciar un programa*:
   - Programa o script: `C:\laragon\www\Facturacion\scripts\backup-run.bat`
   - Agregar argumentos: `auto`  (evita el `pause`)
6. Aceptar.
7. Repetir para la limpieza: tarea `Backup Facturacion - Clean`,
   desencadenador diario `01:00`, acción
   `C:\laragon\www\Facturacion\scripts\backup-clean.bat` con argumento `auto`.

### 4.2 Con una línea de comando (cmd como administrador)
```cmd
schtasks /Create /TN "Backup Facturacion - Run" /TR "C:\laragon\www\Facturacion\scripts\backup-run.bat auto" /SC DAILY /ST 01:30 /RL HIGHEST /F

schtasks /Create /TN "Backup Facturacion - Clean" /TR "C:\laragon\www\Facturacion\scripts\backup-clean.bat auto" /SC DAILY /ST 01:00 /RL HIGHEST /F
```

Probar una tarea sin esperar a la hora:
```cmd
schtasks /Run /TN "Backup Facturacion - Run"
```

### 4.3 Alternativa (todo el scheduler de Laravel)
El proyecto ya tiene programado `backup:run` (01:30) y `backup:clean` (01:00) en
`routes/console.php`. Si preferís que Windows ejecute **todo** el scheduler de
Laravel en vez de los `.bat`, creá **una** tarea que corra cada minuto:
```cmd
schtasks /Create /TN "Laravel Scheduler Facturacion" /TR "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe C:\laragon\www\Facturacion\artisan schedule:run" /SC MINUTE /MO 1 /RL HIGHEST /F
```
Usá **una** de las dos estrategias (los `.bat` directos **o** el scheduler), no ambas,
para no duplicar backups.

---

## 5. Frecuencia recomendada

- **Base de datos: diaria** (la que ya queda programada, 01:30).
- **Limpieza: diaria** (01:00) según retención de `config\backup.php`.
- **Proyecto completo (código): semanal** — zip manual a `C:\laragon\backups\`
  (o agregá una tarea semanal que comprima la carpeta del proyecto).
- Hacé un backup manual **antes** de cambios grandes (migraciones, importaciones masivas).

---

## 6. Copiar el backup a USB / disco externo / nube

Los backups contienen **datos de negocio** (dump de la BD). Guardá una copia
**fuera de la PC principal**.

A un USB / disco externo (ej. unidad `E:`), copiando solo lo nuevo:
```cmd
robocopy "C:\laragon\www\Facturacion\storage\app\private\Dulces La Negrita" "E:\BackupsFacturacion" /E /XO
```

A una carpeta sincronizada con la nube (OneDrive/Google Drive/Dropbox):
```cmd
robocopy "C:\laragon\www\Facturacion\storage\app\private\Dulces La Negrita" "%USERPROFILE%\OneDrive\BackupsFacturacion" /E /XO
```

Podés programar este `robocopy` como otra tarea diaria (después de la hora del backup).
Recomendado: mantener al menos **una copia off-site** y, si es posible, cifrar el USB.

---

## 7. Restaurar (procedimiento general)

> La restauración es manual y delicada: hacela en un entorno de prueba primero.

1. **Elegí el zip** a restaurar desde `storage\app\private\Dulces La Negrita\`.
2. **Descomprimilo** en una carpeta temporal. Adentro hay:
   - el dump de la base de datos (`.sql`), y
   - los archivos de `storage/app` respaldados.
3. **Restaurar la base de datos** (con la BD destino creada/vacía):
   ```cmd
   "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root dulces_negrita < "C:\ruta\al\dump.sql"
   ```
   (Ajustá la ruta de `mysql.exe`, el usuario y el nombre de la base.)
4. **Restaurar archivos**: copiá los archivos del zip de vuelta a
   `C:\laragon\www\Facturacion\storage\app\` respetando subcarpetas.
5. Limpiar caches de la app:
   ```cmd
   php artisan config:clear
   php artisan cache:clear
   php artisan view:clear
   ```
6. Verificá ingresando a la app y revisando algunos documentos.

---

## 8. Seguridad

- Los backups **no se versionan** en git ni se suben a repos públicos.
- El zip puede contener datos sensibles (clientes, documentos): guardalo en lugar
  seguro y, off-site, preferentemente cifrado.
- Estos scripts y esta guía **no contienen contraseñas ni secretos**. Las credenciales
  viven solo en `.env` (no se toca aquí).

---

## 9. Estado

- Scripts: `scripts\backup-run.bat`, `scripts\backup-clean.bat` — **listos**.
- Tarea de Windows: **no creada automáticamente** (se deja a criterio del operador,
  ver sección 4).
- Programación interna de Laravel (`routes/console.php`): ya existe; solo corre si
  alguna tarea de Windows ejecuta `schedule:run` (ver 4.3).
