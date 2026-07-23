# Worker de colas en Windows / Laragon

Guía para dejar el worker de colas (`php artisan queue:work`, `QUEUE_CONNECTION=database`)
corriendo de forma automática y desatendida en Windows, con reinicio propio y sin
duplicar instancias.

> No toca lógica de negocio. Solo arranca/mantiene vivo el proceso que procesa jobs ya
> encolados (envío de correos, etc.). La tarea programada de Windows **no** se crea
> automáticamente: esta guía deja los scripts listos y explica cómo registrarla.

---

## 1. Scripts incluidos

En `scripts\windows\`:

| Script | Qué hace |
|--------|----------|
| `queue-worker-auto.bat` | Corre `queue:work --tries=3 --sleep=3 --timeout=60 --max-time=3600` en un bucle que se reinicia solo si el proceso se cae. Antes de arrancar, verifica que no haya ya otro worker corriendo para este mismo proyecto (evita duplicados). Loguea en `storage\logs\queue-worker-YYYY-MM-DD.log` (uno por día). |
| `registrar-tareas-windows.ps1` | Registra la tarea programada **"DTE Queue Worker"** en el Programador de tareas de Windows (oculta, privilegios altos, reinicio automático, `MultipleInstances = IgnoreNew`). **No se ejecuta solo**: hay que correrlo a mano, una vez, como Administrador. |

El PHP usado es el de Laragon (última versión instalada automáticamente), igual que
`scripts\backup-run.bat`. Si tu instalación es distinta, definí la variable de entorno
`PHP_BIN_DTE` con la ruta completa a `php.exe` antes de ejecutar el `.bat`.

> Nota: en la raíz del proyecto ya existe un `queue-worker-auto.bat` más simple
> (`--tries=1`, sin log a archivo, sin verificación de duplicados). El de
> `scripts\windows\` es la versión recomendada para producción; el de la raíz se deja
> sin tocar por si alguna tarea programada ya lo usa.

---

## 2. Ejecutar manualmente

Opción A — doble clic: `scripts\windows\queue-worker-auto.bat`.

Opción B — desde cmd:
```cmd
cd C:\laragon\www\Facturacion
scripts\windows\queue-worker-auto.bat
```

Se queda corriendo (bucle infinito); para detenerlo, cerrá la ventana o `Ctrl+C`.
Revisá `storage\logs\queue-worker-<fecha>.log` para ver la actividad.

---

## 3. Registrar la tarea programada

### 3.1 Con el script de PowerShell (recomendado)
Como Administrador:
```powershell
powershell -ExecutionPolicy Bypass -File scripts\windows\registrar-tareas-windows.ps1
```
El script pide confirmación (Enter) antes de registrar nada. Registra la tarea
**"DTE Queue Worker"**: se dispara al iniciar sesión y al iniciar el sistema, sin
ventana visible, con privilegios altos, reinicio automático si falla, y sin permitir
una segunda instancia mientras la primera siga corriendo.

### 3.2 Con `schtasks` (alternativa manual, cmd como administrador)
```cmd
schtasks /Create /TN "DTE Queue Worker" /TR "C:\laragon\www\Facturacion\scripts\windows\queue-worker-auto.bat" /SC ONSTART /RL HIGHEST /F
schtasks /Create /TN "DTE Queue Worker (logon)" /TR "C:\laragon\www\Facturacion\scripts\windows\queue-worker-auto.bat" /SC ONLOGON /RL HIGHEST /F
```
`schtasks` no expone `MultipleInstances=IgnoreNew` directamente; para eso conviene usar
el script de PowerShell (3.1), o configurarlo a mano en la pestaña **Configuración** de
la tarea en el Programador de tareas (taskschd.msc): *"No iniciar una instancia nueva"*.

Probar sin esperar el disparador:
```powershell
Start-ScheduledTask -TaskName "DTE Queue Worker"
```

---

## 4. Cómo saber si el worker está activo

El sistema tiene un heartbeat propio (`App\Support\WorkerHeartbeat`, tabla de cache):
cada vuelta del `queue:work` marca "estoy vivo". Se puede consultar en:

- Panel **Salud del sistema** (`/admin/salud-sistema`, solo administrador).
- Pantalla **Preparar emisión real** (`/facturacion/preparar-produccion`).
- El Dashboard principal (bloque de diagnóstico).

El diagnóstico distingue 4 casos (no solo "cola vacía sí/no"):
- **Worker activo**: todo bien, con o sin trabajos pendientes.
- **Worker inactivo + trabajos pendientes**: crítico (se cayó con cola esperando).
- **Worker inactivo + cola vacía**: advertencia, no urgente.
- **Sin datos** (nunca reportó, p. ej. recién reiniciado el servidor): advertencia — nunca
  se muestra como "apagado" ni como verde falso, porque no hay forma confiable de
  confirmarlo todavía.
- **`failed_jobs` > 0**: siempre crítico, sin importar el estado del heartbeat.

---

## 5. Desinstalar / revertir las tareas

Si hay que revertir el registro de tareas (rollback de un despliegue, cambio de
servidor, etc.), con PowerShell como Administrador:

```powershell
Unregister-ScheduledTask -TaskName "DTE Queue Worker" -Confirm:$false
Unregister-ScheduledTask -TaskName "DTE Queue Worker (logon)" -Confirm:$false  # si se creó con schtasks (3.2)
Unregister-ScheduledTask -TaskName "DTE Backup Diario" -Confirm:$false
```

O con `schtasks` (cmd como administrador):
```cmd
schtasks /Delete /TN "DTE Queue Worker" /F
schtasks /Delete /TN "DTE Queue Worker (logon)" /F
schtasks /Delete /TN "DTE Backup Diario" /F
```

Esto solo quita las tareas programadas (el Programador de Windows deja de arrancar los
`.bat`); no borra `storage\logs\queue-worker-*.log` ni ningún backup de `backups\`, y
no toca `.env` ni la base de datos. Si un worker ya está corriendo cuando se
desinstala la tarea, sigue vivo hasta que se cierre manualmente (Task Manager o
cerrando la ventana/proceso `php.exe` correspondiente) — desregistrar la tarea no lo
mata.

## 6. Seguridad

- El script no imprime contraseñas ni tokens: `queue:work` no recibe ni muestra
  credenciales por línea de comandos.
- Los logs (`storage\logs\queue-worker-*.log`) pueden contener trazas de errores de
  jobs (por ejemplo, fallos de envío de correo); no contienen contraseñas de BD/SMTP.
- Registrar la tarea programada requiere privilegios de Administrador; hacerlo es una
  decisión explícita del operador (este script no se ejecuta solo).
