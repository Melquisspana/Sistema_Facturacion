# Migración del servidor a Linux con Docker

> Plan de trabajo, 08/11/2026. Todavía no se ejecutó nada y no cambió nada en producción.
> Este documento se armó con mapas del repositorio hechos en modo lectura. Cuando algo no se puede ver en el repo, aparece como **pregunta abierta** (sección 6) y no como algo seguro.
> Por ser un repositorio público, aquí no van datos de clientes ni de personas, IP reales, dominios reales ni secretos. Los secretos aparecen solo por su **nombre de variable**.

---

## 1. Objetivo y principio

**Objetivo.** El sistema pasa del servidor Windows con Laragon a Linux con `docker compose` y sigue haciendo exactamente lo mismo: facturación DTE con firma local, cola, tareas programadas, respaldos, lectores de asistencia ESP32, acceso por Cloudflare y por Tailscale. A cambio:

- el servidor se puede reconstruir desde git;
- se termina el despliegue archivo por archivo;
- se acaban las diferencias entre lo que hay en git y lo que hay en el servidor.

**Principios:**

1. **Producción no se toca hasta el ensayo.** Las fases A, B, C y D trabajan en el repo, en otra máquina o en un entorno aislado. El servidor de producción solo se **lee** (inventario y comparación) hasta el día del cambio.
2. **Windows queda como vuelta atrás.** Linux se instala en un **segundo disco**, con arranque doble. El disco de Windows no se formatea ni se cambia. Si el cambio falla, se arranca otra vez desde Windows.
3. **Nunca dos sistemas emitiendo a la vez.** En ningún momento pueden estar activos, al mismo tiempo, dos instancias con el mismo certificado, la transmisión a Hacienda encendida y el mismo túnel de Cloudflare. Eso duplica documentos y números correlativos.
4. **Ninguna migración de base nueva** forma parte de este trabajo. El contenedor no corre `migrate` solo al arrancar.
5. Siguen todas las reglas del proyecto: rama y PR, sin commit ni push directo a `master` y la guía de pantallas para cualquier vista que se toque.

---

## 2. Inventario

### 2.1 Servicios que tienen que estar siempre activos

| Servicio | Hoy en Windows | Fuente |
|---|---|---|
| Web (Apache + PHP 8.3) | Laragon, vhost `facturacion.test` y dominio público | `docs/apache/facturacion.conf.example`, `public/.htaccess` |
| Worker de la cola | `scripts/windows/queue-worker-auto.bat` con la tarea «DTE Queue Worker» (SYSTEM) | `scripts/windows/registrar-tareas-windows.ps1` |
| Programador (`schedule:run`) | **No se sabe si corre** (ver P1) | `docs/DESPLIEGUE_SERVIDOR.md §7.1` |
| MySQL | Laragon | `config/database.php` |
| Firmador del MH, puerto 8080 | `firmador-auto.bat` (Java con el JDK 21 que trae el paquete) | `docs/FIRMADOR_LOCAL.md` |
| Segundo firmador, puerto 8119 | No se ve en el repo (ver P2) | `tests/Feature/Alertas/SaludPublicaTest.php:23`, `SALUD_FIRMADORES` |
| cloudflared (túnel) | Instalado en el servidor | `docs/apache/facturacion.conf.example` |
| Tailscale (Serve y SSH) | Instalado en el servidor | `bootstrap/app.php`, `docs/DESPLIEGUE_SERVIDOR.md:9` |
| Respaldo diario de la base | Tarea «DTE Backup Diario» a las 02:00 | `scripts/windows/backup-diario.bat` |
| rclone hacia Drive | Fuera del repo, en `C:\rclone` (no se toca) | `ARQUITECTURA.md:622` |
| Endpoint de los lectores ESP32 | Rutas `/api/asistencia/*` por HTTP en la red local | `routes/api.php` |

### 2.2 PHP y extensiones

- **Versión: PHP 8.3**, la misma de Laragon (8.3.30). El `composer.lock` ya la obliga: `spatie/laravel-backup 10.3.0`, `spatie/db-dumper 4.1.1` y `spatie/laravel-permission 8.0.0` piden `^8.3`. No conviene 8.4 todavía, porque desde esa versión `ext-imap` ya no viene con PHP.
- **Imagen base: Debian bookworm**, sin Alpine:
  - el `iconv` de Alpine (musl) falla con `Windows-1252`, y eso lo usan `app/Services/Ppq/JsonAdjuntoDecoder.php`, `app/Services/Ppq/ConciliacionTxtParser.php` y `app/Console/Commands/PersonalCargarCommand.php`;
  - `ext-imap` necesita `libc-client-dev`, que trixie ya no trae (falta confirmarlo al armar la imagen).

| Extensión | Por qué hace falta |
|---|---|
| ctype, filter, hash, mbstring, openssl, session, tokenizer, json, pcre | laravel/framework |
| dom, xml, xmlreader, xmlwriter, simplexml, libxml, iconv, fileinfo | phpoffice/phpspreadsheet, dompdf |
| **gd** | phpspreadsheet, setasign/fpdf |
| **zip** | phpspreadsheet, spatie/laravel-backup |
| zlib | fpdf, fpdi, smalot/pdfparser, zipstream |
| **bcmath** | Dinero e inventario: `app/Support/Dinero.php`, `app/Support/Planta/SumaInventario.php`, `app/Services/Planta/*`, `app/Ajustes/ConversorValor.php`. **No está declarada en composer.json.** |
| **imap** | Compras por IMAP: `app/Services/DocumentosRecibidos/ImapMailboxClient.php`. Si falta, la app no se cae, pero esa lectura se apaga sola (`function_exists('imap_open')`). |
| pdo_mysql | Base MySQL |
| curl | Cliente HTTP (firmador, Hacienda) y google/apiclient |
| pcntl, posix | Para que la cola respete los límites de tiempo (ver 2.5) |
| opcache | Activarlo (hoy está apagado en Laragon local; en producción se informa activo) |
| intl | Opcional, pero conviene |

No se usan soap, sodium, imagick, exif, redis ni memcached.

### 2.3 php.ini mínimo

| Ajuste | Valor propuesto | Motivo |
|---|---|---|
| `upload_max_filesize` | ≥ 10M (se propone 12M) | 10 MB por archivo: `config/gastos.php:66`, `app/Models/SalidaRutaGasto.php:11` |
| `post_max_size` | ≥ 110M | Gastos acepta hasta 10 archivos de 10 MB |
| `memory_limit` | 512M | El mismo que hoy en Laragon |
| `max_execution_time` | Revisar (ver P10) | Hoy está en 0 (sin límite); en PHP-FPM o Apache el valor por defecto es 30 s |
| `date.timezone` | `America/El_Salvador` | La app ya la fija en `config/app.php:69`; esto alinea al CLI |
| opcache | Activo, `validate_timestamps=0` | Dentro de la imagen el código no cambia; hay que reiniciar los contenedores en cada despliegue |
| certificados TLS | Paquete `ca-certificates` | Reemplaza `openssl.cafile`/`curl.cainfo` de Windows (`docs/CORREO_DTE.md:29`) |

Los valores por defecto de Docker (2M de subida y 8M de envío) **romperían las subidas** de archivos.

### 2.4 Tareas programadas (`routes/console.php`)

Todas necesitan **un solo** programador que corra `schedule:run` cada minuto. Los bloqueos (`withoutOverlapping`, `Cache::lock`) usan la caché en la base (`CACHE_STORE=database`), así que funcionan entre contenedores. No se usa `onOneServer()`.

| Comando | Frecuencia | Interruptor |
|---|---|---|
| `dte:contingencia-reconexion` | cada N minutos (entre 1 y 15) | `dte.contingencia.enabled` |
| `dte:contingencia-lotes` | cada 5 min | `dte.contingencia.enabled` |
| `backup:clean` / `backup:run` (spatie) | 01:00 / 01:30 | ninguno |
| `ppq:sincronizar-albaranes --aplicar --solape=1` | cada 5 min | ajuste `ppq.albaranes.sincronizacion_automatica` |
| `ordenes:sincronizar` | cada 10 min | Gmail habilitado |
| `compras:sincronizar --aplicar --solape=2` | cada 15 min | `DOCUMENTOS_RECIBIDOS_AUTO_SYNC` |
| `gastos:generar-recurrentes --aplicar` | 05:30 | módulo Gastos y su ajuste |
| `gastos:avisos --aplicar` | 06:00 | módulo Gastos y su ajuste |
| `cobros:sincronizar --aplicar --vincular` | cada 10 min | `cobros.alta.automatica` |
| `cobros:leer-correos --aplicar` | cada 30 min | Gmail y ajustes de cobros |
| `alertas:revisar` | cada 5 min | `ALERTAS_ENABLED` |
| `rutas:finalizar-automaticas` | 04:00 | ninguno |
| **`backup:mysql-diario --origen=automatico`** | 02:00 | **no está en `routes/console.php`**: hoy lo dispara una tarea de Windows |

Las horas fijas dependen de la zona `America/El_Salvador`. Los registros van a `storage/logs/*.log`.

### 2.5 Cola

- Conexión `database` (tabla `jobs`), cola `default` y `retry_after` = `DB_QUEUE_RETRY_AFTER` (hoy 1320 s), según `config/queue.php:45`.
- Trabajos en `app/Jobs`:
  - `EnviarDteCorreo` y `EnviarDocumentoRecibidoContabilidad`: 1 intento, sin límite propio (toman el `--timeout` del worker);
  - `GenerarPaqueteContabilidad` y `EnviarPaqueteContabilidad`: 1 intento, 1200 s;
  - `RecuperarPeriodoCompras`: 1 intento, **3600 s**.
- **Cambia en Linux:** con `pcntl` los límites de tiempo se cumplen de verdad (en Windows nunca se cortan). Como `RecuperarPeriodoCompras` dura más que `retry_after`, con dos workers el mismo trabajo podría correr dos veces. Se resuelve con **un solo worker** y `DB_QUEUE_RETRY_AFTER` por encima de 3600 (por ejemplo 3700).
- Señal de vida: `WorkerHeartbeat::pulse()` escucha el evento `Looping` (`AppServiceProvider:173`). Ya funciona en Docker sin cambios.

### 2.6 Firmadores

- La app firma por **una sola dirección**: `DTE_FIRMADOR_URL`, que por defecto es `http://localhost:8080/firmardocumento` (`config/dte.php:117-119`). El cliente es `app/Services/Dte/DteFirmaService.php`: `POST {url}/` para firmar y `GET {url}/status` para el estado. Espera hasta `DTE_FIRMA_TIMEOUT` (10 s).
- **8080:** es el firmador oficial del MH, `svfe-api-firmador-2.0.0-exec.jar`, con el perfil `nonssl`. Busca el certificado `<NIT>.crt` en `uploads/` (carpeta de trabajo) o en `CERTIFICATE_HOME` (`docs/FIRMADOR_LOCAL.md §2`). Ni el JAR, ni el JDK, ni los certificados están en git (`.gitignore:26-34`): **existen solo en el servidor**.
- **8119:** solo lo vigila `/salud` (`SALUD_FIRMADORES`); ningún servicio lo usa para firmar. Las notas de operación de fuera del repo hablan de un segundo firmador con el certificado de pruebas para apitest (ver P2).
- En la documentación aparece el puerto 8113 (`ARQUITECTURA.md`, `docs/ESTADO_FASE_FACTURACION.md:315`). Está **desactualizado**: el JAR trae 8113 por defecto, pero `firmador-auto.bat` lo fuerza a 8080.

### 2.7 Almacenamiento y volúmenes

| Ruta | Contenido | Persistente |
|---|---|---|
| `storage/app/private` (disco `local`) | DTE (`dte/json`, `dte/firmados`, `dte/respuestas`, invalidación, contingencia), PPQ (órdenes, albaranes, gmail, conciliaciones, NC), cobros, documentos recibidos, gastos, planilla firmada, fotos de gastos de ruta, paquetes de contabilidad, ZIP de spatie (`<APP_NAME>/`) | **Sí** |
| `storage/app/templates` | `exportaciones/lista_empaque.xlsx` (no está en git) | Sí |
| `storage/logs` | `laravel.log` (no rota) y los registros del programador | Sí |
| `storage/framework` | Vistas compiladas y modo mantenimiento (`APP_MAINTENANCE_DRIVER=file`) | Sí (dentro del mismo volumen) |
| `storage/fonts` | Caché de fuentes de dompdf | Se puede regenerar |
| `backups/` (`BACKUP_DIARIO_ROOT`) | Volcados `.sql` del respaldo diario | **Sí, carpeta del host** |
| `/var/lib/mysql` | Datos de MySQL | **Sí** |
| Carpeta de certificados del firmador | `<NIT>.crt` de producción (y el de pruebas aparte) | Sí, carpeta del host, solo lectura |
| `bootstrap/cache`, `public/build` | Se generan | **No**: van dentro de la imagen o se crean al arrancar |

La base guarda rutas **relativas al disco**, así que no hay rutas `C:\` que reescribir en los datos. El enlace `public/storage` se crea con `storage:link`; hoy no sirve archivos, pero `PanelSistema` lo revisa.

### 2.8 Puertos y red

| Puerto | Uso | En Linux |
|---|---|---|
| 80/tcp, red local | Web para la oficina y los ESP32 (HTTP plano con `Host: facturacion.test`) | **Publicado** en la red local |
| 443/tcp, red local | Vhost HTTPS de contingencia (decisión 0006 §1.1); no se sabe si existe hoy (P8) | Solo si existe |
| 3306 | MySQL | Solo red interna de compose |
| 8080 / 8119 | Firmadores | Solo red interna, **nunca** publicados |
| 22 | SSH de despliegue | Solo por Tailscale |
| Salida 443 | Hacienda (`apitest`/`api.dtes.mh.gob.sv`), Gmail API, Drive, certificados de Cloudflare Access, sonda de contingencia | Desde la web, el worker y el programador |
| Salida 587 / 993 | SMTP de Gmail (STARTTLS) / IMAP SSL | Desde el worker y el programador |
| Salida del túnel | cloudflared | Desde su contenedor |

Los lectores ESP32 apuntan a una **IP fija de la red local** en el puerto 80 (`firmware/asistencia/secretos.h.example`). Por eso el servidor Linux tiene que quedarse con la misma IP y aceptar ese Host. El firmware no se toca.

### 2.9 Secretos y datos sensibles que hay que trasladar (solo nombres)

**Del `.env` de Laravel:**
- `APP_KEY`: **tiene que ser la misma**. Cifra `gmail_cuentas` (tokens de OAuth) y `ajustes_sistema.valor` (`app/Ajustes/Rotacion/RotacionAppKey.php`). Si cambia, hay que reconectar Gmail y volver a cargar los secretos.
- `DB_USERNAME`, `DB_PASSWORD`.
- `DTE_CERT_PASSWORD`, `DTE_FIRMA_NIT`, `DTE_PROD_USER`, `DTE_PROD_PASSWORD`, `DTE_TEST_USER`, `DTE_TEST_PASSWORD`.
- `GMAIL_CLIENT_ID`, `GMAIL_CLIENT_SECRET`, `GMAIL_REDIRECT_URI`.
- `MAIL_*` (SMTP, con la contraseña de aplicación).
- `DOCUMENTOS_RECIBIDOS_MAIL_*` y `MAIL2_*` (buzones IMAP).
- `BACKUP_ARCHIVE_PASSWORD`.
- `CLOUDFLARE_ACCESS_*` (activación, dominio del equipo, AUD, host permitido; los nombres exactos están en `config/cloudflare_access.php`).

**Fuera de Laravel:**
- El token del túnel de cloudflared (va en el `.env` de compose, **no** en el de Laravel).
- La autenticación de Tailscale en el host.
- La configuración de rclone, que tiene el token de Drive (hoy en `C:\rclone`).
- Los archivos `<NIT>.crt` de producción y de pruebas, y el `.p12` si se usa el perfil `ssl`.

**Lo que viaja con la base y no hay que copiar aparte:** los tokens de los lectores (guardados como hash en `asistencia_dispositivos`), los tokens de Gmail y los ajustes cifrados (sirven solo con la misma `APP_KEY`).

Los certificados y el `.env` los mueve **Melqui a mano**. No entran al repo, a la imagen ni a esta conversación.

---

## 3. Arquitectura propuesta

### 3.1 Contenedores

Todos los contenedores con código PHP usan **una sola imagen propia**. Cambia solo el comando con que arranca cada uno.

| Servicio | Imagen | Comando y notas |
|---|---|---|
| `web` | propia, `php:8.3-apache-bookworm` | Apache con el vhost actual y `.htaccess` sin cambios: es el camino con menos cambios. La alternativa nginx + php-fpm queda abierta (P11). Sirve la web y la API de los lectores. |
| `worker` | la misma | `php artisan queue:work --tries=3 --sleep=3 --timeout=60 --max-time=3600`, **una sola réplica**. |
| `scheduler` | la misma | `php artisan schedule:work`, **una sola instancia**. |
| `mysql` | `mysql:8.4` | Volumen de datos; red interna solamente. |
| `firmador` | `eclipse-temurin:21-jre` + el JAR del MH montado | `java -Dspring.profiles.active=nonssl -jar svfe-api-firmador-2.0.0-exec.jar --server.port=8080`; certificado montado `:ro`. |
| `firmador-pruebas` o `signer-mh` | igual | Solo si se confirma que hace falta (P2), con **su propio** volumen de certificado. |
| `cloudflared` | `cloudflare/cloudflared` | `tunnel run`; el destino del túnel cambia de `127.0.0.1:80` a `http://web:80`. |

**Imagen de la app, en varias etapas:**
1. `node:22` corre `npm ci && npm run build`, como en `.github/workflows/ci.yml`. Así `public/build` sale siempre del repo y se acaba el CSS propio de producción.
2. `composer:2` corre `composer install --no-dev --optimize-autoloader`.
3. La final, `php:8.3-apache-bookworm`, lleva:
   - las extensiones de 2.2;
   - el **cliente MySQL 8.4** oficial (el `default-mysql-client` de Debian es MariaDB);
   - `curl` para el healthcheck;
   - el php.ini de 2.3;
   - todo el código con dueño `www-data` (uid 33).

**Arranque (entrypoint):**
- se ejecuta en cada contenedor PHP;
- corre `config:cache`, porque el `.env` se monta al arrancar y no existe al construir la imagen. `route:cache` y `view:cache` pueden ir al construir la imagen;
- corre `storage:link` si falta;
- **no** corre `migrate`.

**Mismo usuario en todo:** web, worker, programador y comandos a mano corren como `www-data`. Por ejemplo: `docker compose exec -u www-data web php artisan ...`. Flysystem crea los archivos con 0600; si el worker corriera como root, la web no podría leer lo que él escribe.

**Zona horaria:** `TZ=America/El_Salvador` en todos los contenedores. El host sincroniza el reloj por NTP.

### 3.2 Redes

- **`backend`** (`internal: true`, sin salida a internet): `mysql` y los firmadores. Los contenedores `web`, `worker` y `scheduler` también están en esta red. Los firmadores no necesitan internet para firmar.
- **`frontend`**: `web`, `worker`, `scheduler` y `cloudflared`. Tiene salida a internet y una **subred fija** (`ipam`, por ejemplo `172.30.0.0/24`) para declarar los proxies de confianza (sección 4.1).
- Publicado en el host: **solo `80:80`**, y `443` si existe el vhost de contingencia. MySQL y los firmadores van **sin `ports:`**.

### 3.3 Volúmenes

| Volumen | Montado en | Tipo |
|---|---|---|
| `app-storage` → `/var/www/html/storage` | web, worker, scheduler (**el mismo** en los tres) | volumen de Docker o carpeta del host |
| `/srv/facturacion/backups` → `/var/www/html/backups` | scheduler (y web, que muestra el estado) | **carpeta del host**, para que rclone la lea directo |
| `mysql-data` → `/var/lib/mysql` | mysql | volumen de Docker |
| `/srv/facturacion/certificados/produccion` → carpeta `uploads/` del firmador | firmador | carpeta del host, 0700, `:ro` |
| `/srv/facturacion/certificados/pruebas` → igual | firmador de pruebas | carpeta del host, 0700, `:ro` |
| `.env` → `/var/www/html/.env` | web, worker, scheduler | archivo del host `:ro`, nunca dentro de la imagen |

Las rutas `/srv/facturacion/...` son una propuesta.

### 3.4 Healthchecks y reinicio

- Todos los servicios con `restart: unless-stopped`. Esto reemplaza los bucles de los `.bat` y las tareas de Windows.
- **Comprobaciones de salud:**
  - `mysql`: `mysqladmin ping`;
  - `firmador`: `GET http://localhost:8080/firmardocumento/status`. La imagen JRE puede no traer `curl`; hay que comprobarlo o agregarlo;
  - `web`: `curl -fsS http://localhost/up`;
  - `worker` y `scheduler`: no hay un comando de salud propio en el repo. Su señal de vida ya la vigilan `SaludPublica::cola()` y la alerta `TrabajosFallidos`; no se inventa nada nuevo.
- `web`, `worker` y `scheduler` arrancan con `depends_on: condition: service_healthy` de `mysql` y `firmador`.
- `stop_grace_period`: amplio en `mysql` (por ejemplo 60 s) para que se apague limpio. En `worker`, el trabajo que se esté ejecutando puede cortarse; como tiene un solo intento, queda como fallido y se relanza a mano.

### 3.5 Borrador de `compose.yaml` (orientativo, no definitivo)

```yaml
services:
  web:
    image: facturacion-app:${APP_IMAGE_TAG}
    restart: unless-stopped
    ports: ["80:80"]
    env_file: .env.compose        # solo variables de compose; el .env de Laravel va montado
    environment: { TZ: America/El_Salvador }
    volumes:
      - app-storage:/var/www/html/storage
      - /srv/facturacion/backups:/var/www/html/backups
      - /srv/facturacion/env/laravel.env:/var/www/html/.env:ro
    networks: [frontend, backend]
    depends_on:
      mysql: { condition: service_healthy }
      firmador: { condition: service_healthy }
    healthcheck: { test: ["CMD", "curl", "-fsS", "http://localhost/up"], interval: 30s }

  worker:
    image: facturacion-app:${APP_IMAGE_TAG}
    command: php artisan queue:work --tries=3 --sleep=3 --timeout=60 --max-time=3600
    user: www-data
    # mismos volumes, environment, networks y depends_on que web; sin ports

  scheduler:
    image: facturacion-app:${APP_IMAGE_TAG}
    command: php artisan schedule:work
    user: www-data
    # mismos volumes, environment, networks y depends_on que web; sin ports

  mysql:
    image: mysql:8.4
    restart: unless-stopped
    volumes: [mysql-data:/var/lib/mysql]
    networks: [backend]
    stop_grace_period: 60s
    healthcheck: { test: ["CMD", "mysqladmin", "ping", "-h", "localhost"], interval: 15s }

  firmador:
    image: eclipse-temurin:21-jre
    restart: unless-stopped
    working_dir: /firmador
    command: java -Dspring.profiles.active=nonssl -jar svfe-api-firmador-2.0.0-exec.jar --server.port=8080
    volumes:
      - /srv/facturacion/firmador/jar:/firmador:ro
      - /srv/facturacion/certificados/produccion:/firmador/uploads:ro
    networks: [backend]

  cloudflared:
    image: cloudflare/cloudflared:<versión fija>
    restart: unless-stopped
    command: tunnel run
    environment: { TUNNEL_TOKEN: ${CLOUDFLARED_TOKEN} }
    networks: [frontend]

networks:
  frontend: { ipam: { config: [{ subnet: 172.30.0.0/24 }] } }
  backend: { internal: true }

volumes:
  app-storage:
  mysql-data:
```

Antes de usarlo hay que confirmar tres cosas:
- si el JAR se puede leer desde una carpeta montada `:ro` mientras se escribe en `uploads/`;
- si el firmador necesita escribir logs en su carpeta de trabajo;
- la versión de cloudflared.

### 3.6 Lo que queda en el host Linux, fuera de Docker

- **Tailscale:** SSH de despliegue y Tailscale Serve hacia `127.0.0.1:80`. Lo que entra por ahí llega a Apache con la IP de la puerta de enlace de Docker, por eso esa subred va entre los proxies de confianza. La otra opción, el contenedor `tailscale/tailscale` con `TS_SERVE_CONFIG` hacia `web:80`, queda abierta.
- **rclone, por cron del host:**
  - sube `/srv/facturacion/backups`;
  - sube `storage/app/private`, sin `paquetes/`, `backup-temp/` ni `diagnostico-concurrencia/`;
  - sube `storage/app/templates`;
  - la configuración y el remoto se vuelven a crear en Linux; lo hace Melqui.
- **UPS con NUT:** ante un corte largo, `upsmon` corre `docker compose stop` (para que MySQL se apague limpio) y después apaga el equipo. Falta confirmar el modelo de UPS y si tiene conexión USB (P14).
- **Reloj:** NTP del sistema. Con arranque doble hay que alinear el reloj de hardware entre Windows y Linux (riesgo R9).
- **Rotación de logs:** si se cambia `LOG_CHANNEL` a `daily` no hace falta en el host. Si no, `logrotate` sobre `storage/logs`.
- **Cortafuegos:** solo el 80 en la red local (y el 443 si aplica). Docker escribe sus propias reglas de iptables y puede saltarse las de `ufw`, así que hay que revisar el resultado con un escaneo desde otra máquina.

---

## 4. Qué cambia en el repo y qué es solo configuración

### 4.1 Cambios de código necesarios

| Archivo | Cambio | Motivo |
|---|---|---|
| `bootstrap/app.php` (l. 53-60) | `trustProxies(at: '127.0.0.1')` pasa a leer una lista de una variable de entorno (por ejemplo la subred fija de compose más `127.0.0.1`), sin abrirla a cualquier origen | En Docker, cloudflared y Tailscale Serve llegan desde otra IP. Si no se cambia, `isSecure()` da falso y se apaga el login de Cloudflare Access; el límite de intentos de login y de los lectores trata a todos como una sola IP, y el lector queda anotado con una IP equivocada |
| `tests/Feature/Seguridad/SesionesYAccesoTest.php` (l. 173 en adelante) | Actualizar la prueba y agregar el caso de un proxy que no es de confianza | Lo exige la regla de pruebas para seguridad |
| `routes/console.php` | Agregar `backup:mysql-diario --origen=automatico` a las 02:00, o dejarlo en un cron del host (**decisión pendiente**, P5) | Hoy lo dispara una tarea de Windows, que en Linux no existe |
| `app/Http/Controllers/Facturacion/PreparacionProduccionController.php` (l. 124-125) | Cambiar el texto «Abrí start-queue.bat» por una indicación que sirva en los dos sistemas | Solo es un mensaje, pero en Linux confunde |
| `scripts/backup-restore-test.bat` | Agregar su equivalente `scripts/linux/backup-restore-test.sh` | El actual usa `mysql.exe` y PowerShell; la prueba de restauración tiene que existir en Linux |
| `app/Console/Commands/BackupMysqlDiarioCommand.php` (l. 124-141) | **Solo si falla:** pasar la contraseña con `--defaults-extra-file` temporal en lugar de `MYSQL_PWD` | `MYSQL_PWD` está obsoleta en MySQL 8; hay que comprobar en la fase C si el cliente 8.4 todavía la acepta |

### 4.2 Archivos nuevos del repo (Docker)

- `Dockerfile` (varias etapas, 3.1) y `.dockerignore` (excluir `.env`, `storage/`, `backups/`, `resources/firmador/`, `node_modules/`, `vendor/`, `*.crt`, `*.p12`, `*.key`, `*.pem`).
- `compose.yaml`, más `.env.compose.example` con nombres de variables y sin valores.
- `docker/apache/facturacion.conf`, a partir de `docs/apache/facturacion.conf.example`:
  - `ServerName facturacion.test`;
  - el dominio público como `ServerAlias`;
  - un vhost por defecto para la IP de la red local y Tailscale.
- `docker/php/php.ini` (valores de 2.3).
- `docker/entrypoint.sh` (`config:cache` y `storage:link`, sin `migrate`).
- `docs/MIGRACION_LINUX_DOCKER.md` (este archivo) y una guía de despliegue en Linux que reemplace a `docs/DESPLIEGUE_SERVIDOR.md` y `docs/ARRANQUE_WINDOWS.md` cuando termine el cambio.

### 4.3 Cambios convenientes, no obligatorios

Se dejan anotados para no hacer de más:

| Archivo | Cambio | Motivo |
|---|---|---|
| `composer.json` | `"php": "^8.3"` y agregar `ext-bcmath`; `ext-imap` en `suggest` | Que la imagen falle al construir si falta una extensión, en vez de fallar en uso |
| `config/backup.php` | Destino de spatie al disco `backups` y quitar la entrada de ejemplo de `monitor_backups` (disco `s3`) | Hoy el ZIP queda dentro del mismo volumen que respalda |
| `package.json` | Quitar `@tailwindcss/vite` | Nadie lo usa (el proyecto está en Tailwind 3) |
| `ARQUITECTURA.md`, `docs/ESTADO_FASE_FACTURACION.md` | Cambiar 8113 por 8080 | Puertos viejos que llevan a configurar mal |

Agregar `trustHosts` en Laravel queda descartado por ahora: el filtro del Host lo sigue haciendo Apache, igual que hoy.

### 4.4 Solo configuración (`.env`, compose y host)

| Variable o ajuste | Valor en Linux |
|---|---|
| `DB_HOST` | `mysql` (nombre del servicio) |
| `DB_DUMP_PATH` | vacío o `/usr/bin` (`BackupMysqlDiarioCommand` ya quita el `.exe` cuando no es Windows) |
| `BACKUP_DIARIO_ROOT` | `/var/www/html/backups` |
| `DTE_FIRMADOR_URL` | `http://firmador:8080/firmardocumento` |
| `SALUD_FIRMADORES` | las URL con los nombres de los servicios (`http://firmador:8080/...` y el segundo, si existe) |
| `DTE_PDF_LOGO_PATH` | vacío (usa `public_path`) o una ruta Linux |
| `DB_QUEUE_RETRY_AFTER` | mayor que 3600 (por ejemplo 3700) |
| `LOG_CHANNEL` / `LOG_STACK` | `daily`, porque hoy `laravel.log` no rota |
| `APP_MAINTENANCE_DRIVER` | `cache` (va a la base y afecta a todos los contenedores) o dejar `file`, que ya se comparte en el volumen |
| `TZ` | `America/El_Salvador` en todos los contenedores |
| `APP_URL`, `SESSION_SECURE_COOKIE`, `GMAIL_REDIRECT_URI`, `CLOUDFLARE_ACCESS_*` | **los mismos de producción** si el dominio no cambia (P9) |
| Destino del túnel en Cloudflare | `http://web:80` |
| Tailscale Serve | `127.0.0.1:80` (igual que hoy) |
| Reserva de DHCP | la misma IP para el equipo (los ESP32 la tienen fija) |

---

## 5. Fases

### Fase A — Preparar en el repo, sin tocar producción

1. Rama y PR propios, con issue en el tablero.
2. Escribir los archivos de 4.2 y el cambio de `trustProxies` con su prueba (4.1).
3. Decidir dónde va el respaldo diario (P5) y aplicarlo.
4. Construir la imagen y comprobar dentro de ella:
   - `php -m` muestra bcmath, gd, zip, imap, intl, pdo_mysql, pcntl, opcache y curl;
   - `iconv` convierte `Windows-1252` (prueba de una línea);
   - `mysqldump --version` es 8.4 y acepta `MYSQL_PWD`;
   - `php -i` muestra los límites de subida y la zona horaria esperados.
5. Correr la suite completa de PHPUnit **dentro del contenedor**, de una vez y sin banderas. Así se ven diferencias de mayúsculas o de comportamiento de Linux que no aparecen en Windows. Revisión previa: 928 clases contra PSR-4, los `use App\...` y 360 llamadas a vistas, sin problemas.
6. Se entrega sin desplegar.

### Fase B — Reconciliar el servidor con git (solo lectura en producción)

**Ya hecho el 08/11/2026** (inventario de solo lectura contra `4409fa6b`; el detalle con datos reales queda fuera del repo):

- **Código.** De 1635 archivos versionados, 1579 son iguales, 38 distintos y faltan 18.
  - `firmador-auto.bat` **va adelante de git**: tiene el arreglo AF_UNIX (`SOCK_DIR` y `-Djdk.net.unixdomain.tmpdir`). Hay que traerlo a git antes de igualar.
  - Las vistas `exportaciones/clientes/index` y `show` del servidor llaman a un método que ya no existe, pero son código muerto: esas rutas redirigen a la ficha del cliente y responden bien (comprobado con GET el 08/11). Se igualan con git sin apuro.
  - La función de importar y auditar correos enviados de Cobros (13 archivos y la migración `2026_09_25_120000`) **nunca se desplegó**. Decisión de Melqui: desplegarla o quitarla de git.
  - El resto son textos con datos reales que git ya limpió, copias `.backup` y scripts sueltos para descartar.
- **CSS.** A producción no le falta nada que git necesite. Tiene 84 clases de más que salen de vistas compiladas viejas, porque `tailwind.config.js` incluye `storage/framework/views`. **Un build limpio desde git sirve.** Conviene sacar esa ruta del `content` de Tailwind.
- **Variables.** El servidor usa `COBROS_INICIO_SEGUIMIENTO`, `DTE_NC_VERSION`, `LOG_DAILY_DAYS`, `PPQ_CLIENTE_DEFAULT_ID`, `PPQ_QUEDAN_SALAS_CONFIRMADAS` y `RUTAS_ENTREGAS_DESDE`, que faltan en `.env.example`. Varias rutas por defecto de config/ son de Windows (`BACKUP_DIARIO_ROOT`, `COBROS_TXT_CARPETA`, `*_STORAGE_DIR`).
- **Procesos.** Todos corren como tareas de Windows con SYSTEM, no como servicios:
  - **el programador sí corre** (`schedule:run` cada minuto; responde P1, así que R1 no aplica);
  - el worker es `scripts\windows\queue-worker-auto.bat` (responde P3);
  - el firmador del 8080 arranca con `firmador-auto.bat`;
  - el respaldo diario es a las 02:00 y rclone a las 02:30.
- **Firmadores.** El de 8119 (`signer-mh`, javaw) arranca desde la carpeta Inicio del usuario (responde en parte P2). `resources/firmador/` (unos 380 MB, con el certificado) no está en git y trae variantes `dockerConSSL`, `dockerSinSSL` y `svfe-api-firmador` (responde en parte P13).
- **Servicios y versiones.** Apache, MySQL **8.4** (responde P6: la vuelta atrás con un volcado 8.4 es viable), cloudflared, sshd y Tailscale, como servicios de Windows.
- **Equipo:** un solo disco NVMe de 512 GB. La revisión de seguridad del equipo (P14 y P17) se lleva aparte, **fuera de este repo público**.
- **Otros proyectos en la misma máquina:** `ControlTomas` (su propio `schedule:run`) y un respaldo de `RestaurantePOS`.
  - `RestaurantePOS` **no se migra** (decisión de Melqui, 08/11).
  - `ControlTomas`: falta decidir si se mueve también, se queda en Windows o se apaga.

Lo que sigue de esta fase:

1. **Inventario de lo que hay en producción.** Sacar un listado con hash SHA-256 de todos los archivos del código y compararlo con `origin/master`. Las notas de despliegue ya registran clases que **solo existen allá**: el Rutas viejo (`BandejaDocumentos`, `Custodia*`). Clasificar cada diferencia:
   - (a) ya está en git;
   - (b) hay que traerla a git;
   - (c) se descarta.
   Cada decisión va a su PR.
2. **CSS propio.** Bajar el `public/build` de producción y compararlo con un `npm run build` del repo. Buscar las clases que usan las vistas y no existen en uno u otro (antecedente: PR #218 y `CHANGELOG.md:213`). El build nuevo tiene que verse igual o mejor; se revisa en claro y oscuro, en celular y escritorio, según la guía de pantallas.
3. **Estado de la base.** Comparar `php artisan migrate:status` de producción con las migraciones del repo. Tienen que coincidir antes del cambio, porque el día del cambio no se corren migraciones.
4. **Datos de operación que faltan:** responder P1, P2, P3, P4, P6, P7, P8, P10 y P12 leyendo el servidor (tareas de Windows, servicios, `php -m`, `php.ini`, la configuración de Laragon y de MySQL, cloudflared, Tailscale Serve y el contenido de `resources/firmador/`). Sin cambiar nada.
5. Medir el tamaño de `storage/app/private`, de `backups/` y de la base, para estimar cuánto tarda la copia del día del cambio.

### Fase C — Ensayo completo en otra máquina con datos inventados

1. Instalar Linux y Docker en una máquina de prueba. Base aislada creada desde cero con las migraciones y datos inventados. **No** usar la base de desarrollo del usuario.
2. Firma:
   - `DTE_FIRMADOR_MOCK=true`, o el firmador con el **certificado de pruebas** contra `apitest`;
   - `DTE_TRANSMISION_DRY_RUN=true` / `DTE_MODO_OPERACION=paralelo` cuando no se transmita a pruebas;
   - **nunca** el certificado de producción.
3. Lista de comprobación:
   - Login normal por IP y por `facturacion.test`. Login por Cloudflare Access con un túnel de prueba, si se puede, para confirmar `isSecure()` y la IP real en el límite de intentos.
   - `/up` y `/salud` en verde, con los dos firmadores si aplica.
   - `php artisan dte:firma-check` y una firma de prueba de punta a punta.
   - PDF de un DTE (dompdf y fuentes) y un Excel (phpspreadsheet).
   - Importar un archivo en `Windows-1252` (conciliación PPQ y carga de personal).
   - Subir un archivo de 10 MB en Gastos y la foto de un gasto de ruta.
   - Cola: enviar un correo de prueba con `MAIL_MAILER=log` y generar un paquete de contabilidad. La web descarga el ZIP que escribió el worker (mismo volumen y mismo usuario).
   - Programador: comprobar que cada tarea corre a su hora en `America/El_Salvador` y que los interruptores se respetan.
   - `backup:mysql-diario` deja su `.sql` con SHA-256 en `respaldo_ejecuciones`. `backup:run` termina bien. `scripts/linux/backup-restore-test.sh` restaura en una base `_restore_test`.
   - Lector: simular con `curl` un `ping` y un `marcar` hacia `IP:80` con `Host: facturacion.test` y los encabezados `X-Dispositivo`/`X-Dispositivo-Token` de un lector de prueba. Si hay un ESP32 de banco, probarlo de verdad.
   - IMAP contra un buzón de prueba, si existe. Si no, al menos `imap_open` cargada.
   - Reiniciar el host: todo vuelve solo y en orden. Simular un corte con NUT si el UPS está disponible.
   - Escaneo de puertos desde otra máquina: solo responde el 80 (y el 443 si aplica).
4. Anotar cada falla y su arreglo en este documento antes de pasar a D.

### Fase D — Ensayo de restauración con una copia real, en aislado

1. Melqui lleva a mano a la máquina de ensayo una copia reciente: volcado de la base, `storage/app/private`, `storage/app/templates`, `.env` y certificados. Nadie más la toca y no sale de esa máquina.
2. **Aislamiento total:**
   - todas las redes de compose con `internal: true` (sin salida a internet);
   - sin `cloudflared`;
   - sin el puerto 80 publicado en la red de la oficina;
   - `DTE_TRANSMISION_DRY_RUN=true`, `MAIL_MAILER=log`, `ALERTAS_ENABLED=false`, `DOCUMENTOS_RECIBIDOS_AUTO_SYNC=false`;
   - los ajustes automáticos que viven en la base (PPQ, cobros, gastos) **no se cambian**: se deja apagado el `scheduler` y el `worker` arranca solo para pruebas puntuales.
   Así no se renueva ningún token de Gmail, no se manda ningún correo, no se transmite nada y no se pisa ningún correlativo.
3. Medir el tiempo de toda la restauración: copiar, importar, `chown -R 33:33`, levantar. Ese tiempo define la ventana del día del cambio.
4. Comprobar:
   - conteo de filas por tabla y suma de control de las tablas principales contra el origen;
   - que abren los DTE firmados, los PDF y los adjuntos (rutas relativas);
   - que la `APP_KEY` descifra los ajustes y `gmail_cuentas`, sin probar contra Google;
   - que `dte:firma-check` responde con el certificado de producción **sin transmitir**;
   - nombres de tablas en minúsculas y que `routines`, `triggers` y `events` se importan sin error de `DEFINER` (R7).
5. Al terminar, borrar la copia de la máquina de ensayo y anotar que se borró.

### Fase E — El día del cambio

**Antes del día:**
- Fase D aprobada, PR de las fases A y B mezclados e imagen construida con su etiqueta.
- Reserva de DHCP confirmada.
- Disco nuevo con Linux instalado y compose listo. **El disco de Windows se desconecta físicamente mientras se instala Linux**, para que el instalador no lo toque.
- Ventana fuera del horario de facturación, sin rutas en curso y sin contingencia abierta.
- Avisar a los usuarios.

**Pasos:**

1. En Windows:
   - comprobar que no hay DTE pendientes de transmitir ni lotes de contingencia;
   - esperar a que la tabla `jobs` quede vacía y revisar `failed_jobs`.
2. `php artisan down` en Windows.
3. Detener en Windows el worker, el firmador, cloudflared y las tareas programadas. **Deshabilitarlas**, no borrarlas, para no tener dos instancias a la vez si se arranca Windows por error.
4. Respaldo final:
   - `backup:mysql-diario --origen=manual` (o un `mysqldump` equivalente);
   - copia de `storage/app/private`, `storage/app/templates`, `backups/`, `.env` y certificados al disco de Linux o a un disco externo;
   - anotar el SHA-256 de cada archivo.
5. Apagar Windows. Arrancar desde el disco de Linux.
6. Verificar los hashes, restaurar la base en `mysql`, copiar `storage` y aplicar `chown -R 33:33` a `storage` y `backups`.
7. `docker compose up -d` sin `cloudflared`. Revisar `/up`, `/salud`, `dte:firma-check`, el login local y un PDF.
8. Encender `cloudflared` (Windows ya apagado: **un solo conector para el túnel**). Probar el login por Cloudflare Access desde fuera.
9. Confirmar que Tailscale Serve y el SSH de despliegue responden.
10. Lector: pedir una marca de prueba en un ESP32 real y ver su `ping` en el panel.
11. **Primer DTE real**, con alguien de facturación al lado: firma, transmisión, sello de Hacienda y correo al cliente.
12. Confirmar que el programador corre lo esperado y que la tabla `jobs` avanza.
13. `php artisan up` si se usó modo mantenimiento en Linux, y avisar a los usuarios.

**Vuelta atrás:**

- **Cuándo:** el firmador no firma, Hacienda rechaza, los lectores no marcan, el login por Cloudflare no funciona o falta algún dato. Se decide **antes de una hora límite** fijada de antemano.
- **Si en Linux todavía no se emitió ningún DTE ni se registró nada:**
  1. `docker compose down`;
  2. reiniciar en Windows;
  3. volver a habilitar las tareas, el worker, el firmador y cloudflared;
  4. `php artisan up`.
  Windows queda tal como estaba.
- **Si en Linux ya se emitieron DTE o entraron marcas o cobros:**
  1. sacar en Linux un volcado y una copia de `storage/app/private`;
  2. pasarlos a Windows y restaurarlos ahí antes de reabrir. Depende de que el MySQL de Windows acepte un volcado de 8.4 (P6);
  3. **nunca** reabrir Windows con su base vieja, porque se repetirían correlativos ya usados en Hacienda.
- Al volver a Windows, comprobar la hora del sistema antes de firmar (R9).

### Fase F — Después del cambio

1. **Primera semana:**
   - revisar cada día `/salud`, alertas, `failed_jobs` y los registros del programador;
   - confirmar el respaldo diario y su subida a Drive con rclone;
   - confirmar que `LectorSinPing` está en silencio en horario.
2. Primera prueba de restauración en Linux con `scripts/linux/backup-restore-test.sh`; después, cada mes.
3. Probar un apagado real con NUT en un horario sin uso.
4. **Nuevo despliegue:** construir la imagen con etiqueta, subir y hacer `docker compose up -d` para web, worker y programador (con opcache sin `validate_timestamps`, el reinicio es obligatorio). El `CHANGELOG` se actualiza solo al desplegar. Se termina el despliegue archivo por archivo.
5. Actualizar la documentación:
   - `docs/DESPLIEGUE_SERVIDOR.md`, `docs/ARRANQUE_WINDOWS.md` y `docs/BACKUPS_WINDOWS.md` pasan a historia o se reemplazan por la guía de Linux;
   - `docs/FIRMADOR_LOCAL.md` se actualiza con el contenedor.
6. **Retiro de Windows:** el disco de Windows se mantiene sin tocar durante un período acordado (P15). Solo después se decide si se reutiliza.

---

## 6. Riesgos y preguntas abiertas

### 6.1 Riesgos

| # | Riesgo | Mitigación |
|---|---|---|
| R1 | **Programador nuevo en producción.** Si hoy no corre `schedule:run` (P1), al pasar a Linux se encienden de golpe hasta 13 tareas: sincronizaciones de Gmail, cobros, alertas, spatie a la 01:30 y el cierre automático de rutas | Responder P1 en la fase B. Si no corre hoy, decidir con Melqui qué interruptores se encienden el día del cambio y cuáles después |
| R2 | **Doble emisión.** Dos instancias con el mismo certificado, Hacienda activa o el mismo túnel | Principio 3. En los ensayos, `DRY_RUN`, apitest o una red aislada. El día del cambio, Windows deshabilitado antes de encender Linux |
| R3 | **Firmador expuesto.** Acepta CORS `*` y recibe la contraseña del certificado en cada pedido | Firmador sin `ports:`, en red `internal`, y escaneo de puertos desde fuera |
| R4 | **Certificado equivocado.** El de producción y el de pruebas tienen el mismo NIT y el mismo nombre de archivo | Carpetas del host separadas con nombres claros, un servicio por certificado y `dte:firma-check` antes del primer DTE |
| R5 | **Proxies de confianza.** Sin el cambio de `bootstrap/app.php` se apaga el login de Cloudflare y el límite de intentos cuenta mal | Cambio de la sección 4.1, con su prueba, en la fase A |
| R6 | **Permisos.** El worker o los comandos a mano corren como root y la web no puede leer lo que ellos escriben | `user: www-data` en todos los servicios y `chown -R 33:33` tras copiar desde Windows |
| R7 | **Diferencias de MySQL entre Windows y Linux:** `lower_case_table_names` (en Linux solo se fija al crear la base), `sql_mode`, juego de caracteres, `DEFINER` en rutinas o eventos y el permiso `EVENT` para `--events` | Revisarlo en la fase D con la copia real; llevar a `mysql:8.4` los valores que importan del `my.ini` de producción (P7) |
| R8 | **Trabajos largos cortados.** Con `pcntl`, `RecuperarPeriodoCompras` (3600 s) se corta o se repite si `retry_after` es menor | Un solo worker y `DB_QUEUE_RETRY_AFTER` mayor que 3600 |
| R9 | **Reloj con arranque doble.** Windows guarda la hora local en el reloj de hardware y Linux guarda UTC. Al cambiar de sistema la hora puede quedar corrida y Hacienda rechaza el DTE | Alinear los dos sistemas (Linux con reloj local o Windows con UTC) y revisar la hora antes de firmar |
| R10 | **ESP32 sin conexión.** Si cambia la IP, el puerto o el Host aceptado, los lectores dejan de marcar | Reserva de DHCP (mismo equipo, misma tarjeta de red), puerto 80 publicado y `ServerName facturacion.test` |
| R11 | **`APP_KEY` distinta.** No se pueden leer los tokens de Gmail ni los ajustes cifrados | Copiar exactamente la de producción y comprobarlo en la fase D |
| R12 | **CSS distinto.** El build nuevo cambia cómo se ven pantallas que hoy dependen del CSS viejo de producción | Fase B, paso 2, con revisión visual |
| R13 | **Imagen incompleta:** falta bcmath, gd, zip o imap, o los límites de subida quedan en los de Docker | Comprobaciones de la fase A, paso 4, y `ext-bcmath` en `composer.json` |
| R14 | **Volcado con cliente equivocado.** El `mysqldump` de MariaDB contra MySQL 8.4, o `MYSQL_PWD` rechazada | Cliente oficial 8.4 en la imagen y prueba en la fase C |
| R15 | **Sin internet en el local.** La decisión 0006 da por supuesto que sin internet se sigue facturando en la red local | Confirmar que el acceso local (`http://facturacion.test` o el vhost HTTPS de contingencia) funciona con el contenedor y sin túnel |
| R16 | **Docker se salta el cortafuegos del host** al publicar puertos | Publicar solo lo indispensable y verificar con un escaneo externo |
| R17 | **Pérdida de los únicos originales.** El JAR, el JDK y los certificados existen solo en el servidor Windows | Copia previa cifrada fuera del servidor, hecha por Melqui, antes de la fase E |

### 6.2 Preguntas abiertas

| # | Pregunta | Dónde se responde |
|---|---|---|
| P1 | ¿Hoy corre `schedule:run` en producción? `docs/DESPLIEGUE_SERVIDOR.md §7.1` dice que no, pero esa tabla está vieja | Tareas programadas del servidor (fase B) |
| P2 | ¿Qué es el firmador del puerto 8119, cómo arranca y con qué certificado? ¿Es el de apitest? ¿Se sigue usando? | Servidor (fase B) |
| P3 | ¿Cuál de los dos workers corre en producción: el `.bat` de la raíz (`--tries=1`, sin `--timeout`) o `scripts/windows/queue-worker-auto.bat`? | Tareas de Windows (fase B) |
| P4 | ¿Producción tiene `ext-imap` activa? Si no, hoy la lectura de Compras por IMAP no funciona y encenderla es un cambio de comportamiento | `php -m` en el servidor |
| P5 | ¿El respaldo diario pasa a `routes/console.php` (cambio de código) o a un cron del host? | Decisión de Melqui |
| P6 | ¿Qué versión de MySQL tiene producción? ¿Un volcado de 8.4 se puede restaurar ahí si hay que volver atrás? | Servidor y fase D |
| P7 | ¿Qué `sql_mode`, juego de caracteres, `lower_case_table_names` y tamaños de memoria tiene el `my.ini` de producción? | Servidor (fase B) |
| P8 | ¿Existe hoy el vhost HTTPS de contingencia para la red local (decisión 0006)? | Configuración de Apache del servidor |
| P9 | ¿El dominio público, `APP_URL`, `SESSION_SECURE_COOKIE` y `GMAIL_REDIRECT_URI` quedan iguales? | `.env` de producción (lo lee Melqui) |
| P10 | ¿Algún pedido web necesita más de 30 s? ¿Qué `php.ini` tiene producción (subidas, memoria, opcache)? | Servidor y registros |
| P11 | ¿Apache (menos cambio) o nginx + php-fpm? | Decisión técnica en la fase A |
| P12 | ¿Qué sube rclone hoy, cuándo y a qué remoto? (`C:\rclone` no se toca) | Melqui |
| P13 | ¿El paquete del MH trae JAR, compose o variante para Linux? ¿Exige otra versión de Java? ¿Se usa el perfil `ssl` en producción? | Contenido de `resources/firmador/` en el servidor |
| P14 | ¿Qué UPS hay y se puede conectar por USB para NUT? | Melqui |
| P15 | ¿Cuánto tiempo se guarda el disco de Windows intacto después del cambio? | Decisión de Melqui |
| P16 | ¿Tailscale en el host (nodo nuevo) o en contenedor? ¿Cambia el nombre que usa el despliegue por SSH? | Decisión técnica y consola de Tailscale |
| P17 | ¿El disco de Windows tiene BitLocker? Si lo tiene, ¿la clave de recuperación está a mano antes de cambiar el orden de arranque? | Melqui |
| P18 | ¿Quién consulta `/salud` desde afuera (issue #163)? ¿Hay que avisarle de una ventana sin servicio? | Melqui |
| P19 | ¿Existe hoy `storage/app/templates/exportaciones/lista_empaque.xlsx` en producción? | Servidor (fase B) |
