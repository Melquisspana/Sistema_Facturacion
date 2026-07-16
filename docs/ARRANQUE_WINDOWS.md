# Arranque del sistema en Windows (PC de facturación)

Guía para dejar **Dulces La Negrita** listo al encender la PC de facturación,
**sin activar emisión real**. El sistema sigue en **modo paralelo seguro**: Conta
Portable continúa siendo el sistema oficial mientras no se decida lo contrario.

> Esta guía **no** activa emisión, **no** transmite, **no** mueve correlativos y
> **no** toca `.env`. Solo explica cómo arrancar servicios y verificar. Las tareas
> programadas y los servicios reales se crean **a mano** en la PC definitiva
> (no se crean automáticamente).

Relacionadas: `docs/BACKUPS_WINDOWS.md`, `docs/FIRMADOR_LOCAL.md`, `docs/COLA_CORREOS.md`.

---

## 0. Qué necesita estar corriendo

| Pieza | Para qué | Cómo arranca |
|-------|----------|--------------|
| Laragon (Apache/Nginx + MySQL) | Servir la app y la base de datos | Autostart de Laragon (§1, §2) |
| Worker de cola (`queue:work`) | Que salgan los correos encolados | Tarea programada con `queue-worker-auto.bat` (§3) |
| Scheduler / backups | Backup diario de la BD | `schedule:run` cada minuto **o** tareas `.bat` (§4) |
| Firmador Java (opcional) | Solo si se va a **firmar** | `firmador-auto.bat`, solo al emitir (§5) |

Nada de esto emite ni transmite por sí solo. El worker solo manda correos que el
usuario ya encoló desde "Enviar correo"; el firmador no firma nada mientras
`DTE_FIRMA_ENABLED=false`.

---

## 1. Laragon: arrancar con Windows

1. Abrí **Laragon → Menu (botón derecho) → Preferences → General**.
2. Marcá **"Run Laragon on Windows startup"**.
3. Marcá **"Auto start"** para que levante los servicios (Apache/Nginx **y** MySQL)
   al abrir Laragon.
4. Reiniciá la PC y confirmá que Laragon abre solo y los servicios quedan en verde.

Así el **servidor web** y la **base de datos** quedan arriba sin intervención.

---

## 2. Usar Apache/Nginx de Laragon (no `php artisan serve`)

Para una PC de facturación conviene el servidor de Laragon, **no** `artisan serve`:

- `artisan serve` es de **un solo hilo**, necesita una **consola abierta** y se
  **muere al cerrarla**. No sirve para uso continuo ni para varios equipos.
- Apache/Nginx de Laragon **arranca con Laragon**, sin ventana, sirve en el
  **puerto 80** y soporta acceso desde otras PC/celulares (§7).

En Laragon, el proyecto en `C:\laragon\www\Facturacion` normalmente ya queda
publicado. El *document root* debe apuntar a la carpeta **`public/`** del proyecto
(Laragon lo hace solo con su "Auto virtual hosts"). Verificá abriendo la app en el
navegador (o usá `iniciar-facturacion.bat`).

> Si preferís una URL de red estable (IP o host), eso implica ajustar `APP_URL` en
> `.env`. **Eso queda para cuando toque configurar la PC definitiva**, no ahora.

---

## 3. Worker de cola como tarea programada

La cola usa la base de datos (`QUEUE_CONNECTION=database`), así que **los correos
solo salen si el worker está corriendo**. Ya existe el script desatendido
**`queue-worker-auto.bat`** (se auto-reinicia, se recicla cada hora, no pide teclas).

Crear la tarea en el **Programador de tareas de Windows**:

1. **Crear tarea…** (no "tarea básica").
2. **General**: nombre `Worker Facturacion`. Marcar *"Ejecutar tanto si el usuario
   inició sesión como si no"* y *"Ejecutar con los privilegios más altos"*.
3. **Desencadenadores** → Nuevo → **Al iniciar el sistema**.
4. **Acciones** → Nueva → *Iniciar un programa*:
   - Programa/script: `C:\laragon\www\Facturacion\queue-worker-auto.bat`
5. **Condiciones**: desmarcar *"Iniciar la tarea solo si el equipo está con corriente
   alterna"* si es un portátil que se usa con batería.
6. Aceptar.

Con una línea (cmd como administrador):

```cmd
schtasks /Create /TN "Worker Facturacion" /TR "C:\laragon\www\Facturacion\queue-worker-auto.bat" /SC ONSTART /RL HIGHEST /F
```

Verificar sin reiniciar:

```cmd
schtasks /Run /TN "Worker Facturacion"
```

Luego confirmá el latido del worker en **Facturación → Preparar emisión real**
(sección "Servicios") o en **Salud del sistema**.

> Alternativa más robusta (opcional): instalar el worker como **servicio real** de
> Windows con **NSSM**. No es necesario; la tarea programada alcanza.

---

## 4. Scheduler y backups automáticos

El backup diario **ya está programado** en `routes/console.php`
(`backup:clean` 01:00 y `backup:run` 01:30). Solo falta que Windows dispare el
scheduler. Elegí **una** de estas dos estrategias (no ambas, para no duplicar):

**Opción A — un solo scheduler de Laravel (recomendada):**

```cmd
schtasks /Create /TN "Scheduler Facturacion" /TR "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe C:\laragon\www\Facturacion\artisan schedule:run" /SC MINUTE /MO 1 /RL HIGHEST /F
```

**Opción B — tareas `.bat` directas** (ver `docs/BACKUPS_WINDOWS.md` §4):
`scripts\backup-run.bat` a las 01:30 y `scripts\backup-clean.bat` a las 01:00.

Sumá una copia **off-site** (USB / OneDrive) con `robocopy` (ver
`docs/BACKUPS_WINDOWS.md` §6). Hacé siempre un backup manual **antes** de cambios
grandes (migraciones, importaciones): `scripts\backup-run.bat`.

---

## 5. Firmador Java (solo cuando se vaya a emitir)

**No hace falta en modo paralelo seguro.** El firmador solo se usa cuando decidas
**firmar** (`DTE_FIRMA_ENABLED=true`, que no se toca ahora). Cuando llegue ese día:

- Arrancalo con **`firmador-auto.bat`** (arranque recomendado; usa el JDK 21 que
  trae el propio firmador, no requiere Java instalado). El JAR trae 8113 por
  defecto, pero el `.bat` fuerza `--server.port=8080`: la app y el firmador
  deben usar **el mismo puerto** (`DTE_FIRMADOR_URL`), así que el servicio debe
  responder en `http://localhost:8080`.
- Verificá con el botón **"Probar firmador ahora"** en
  **Facturación → Preparar emisión real**.
- Detalle del certificado y perfiles: `docs/FIRMADOR_LOCAL.md`.

Para dejarlo automático al encender (solo en la etapa de emisión), creá otra tarea
"Al iniciar el sistema" que ejecute `C:\laragon\www\Facturacion\firmador-auto.bat`.
Arrancar el firmador **no** emite ni transmite por sí solo.

---

## 6. Evitar que Windows se suspenda

Si la PC se duerme, se cortan el servidor y el worker. Dejá la PC despierta
(cmd como administrador, plan de energía en corriente alterna):

```cmd
powercfg /change standby-timeout-ac 0
powercfg /change hibernate-timeout-ac 0
powercfg /change monitor-timeout-ac 15
```

`standby`/`hibernate` en `0` = nunca suspender/hibernar; el monitor puede apagarse
(15 min) sin afectar los servicios. En portátil, repetir con `-dc` si se usa con
batería, o mantenerla enchufada.

---

## 7. Acceso desde otra PC o celular (red local)

1. **Firewall de Windows**: permitir el puerto **80** entrante (perfil Privado/red
   local). Con cmd de administrador:
   ```cmd
   netsh advfirewall firewall add rule name="Facturacion LAN 80" dir=in action=allow protocol=TCP localport=80 profile=private
   ```
2. Averiguá la **IP local** de la PC (`ipconfig` → *Dirección IPv4*, ej. `192.168.1.50`).
3. Desde el celular/otra PC en la **misma red**, abrí `http://192.168.1.50/`.

> Para que enlaces y sesiones funcionen bien con la IP/host de red conviene fijar
> `APP_URL` (y revisar `SESSION_DOMAIN`) en `.env`. **Eso queda pendiente para la
> configuración de la PC definitiva**, no se toca ahora.

---

## 8. Tailscale (acceso seguro fuera de la red) — opción recomendada

Para entrar desde fuera de la red local **sin abrir puertos al internet** ni
port-forwarding:

1. Instalá **Tailscale** en la PC de facturación y en tu celular/otra PC.
2. Iniciá sesión con la **misma cuenta** en todos los dispositivos.
3. Accedé por la **IP de Tailscale** (ej. `100.x.y.z`) o por **MagicDNS**
   (`http://nombre-pc/`), desde cualquier lugar.

Es la forma más segura de acceso remoto (red privada cifrada, sin exponer la PC).
También implica ajustar `APP_URL`/hosts de confianza en `.env` (**pendiente**, no ahora).

---

## 9. Checklist final al encender

Después de arrancar, verificá todo desde **Facturación → Preparar emisión real**:

- Estado del sistema: modo **PARALELO SEGURO** (no emite producción).
- Servicios: **app** y **base de datos** responden; **worker** activo (latido).
- Firmador: solo si estás en etapa de emisión ("Probar firmador ahora").
- Backup: revisá el último backup y generá uno antes de cambios grandes.

Accesos rápidos incluidos en el proyecto:

- `iniciar-facturacion.bat` — abre la app en el navegador (opcional; no arranca servicios).
- `queue-worker-auto.bat` — worker de correos desatendido (§3).
- `firmador-auto.bat` — firmador local, solo al emitir (§5).
- `scripts\backup-run.bat` / `scripts\backup-clean.bat` — backups (§4).

---

## 10. Qué NO hace esta guía

- No activa emisión ni transmisión a Hacienda.
- No toca `.env`, `DTE_FIRMA_ENABLED`, correlativos, SMTP ni el CCF 1078.
- No crea tareas programadas ni servicios automáticamente (se hacen a mano en la PC
  definitiva, siguiendo estos pasos).
- No cambia el modo paralelo seguro.
