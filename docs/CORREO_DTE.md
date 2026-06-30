# Correo de DTE (envío al cliente) — punto estable

> Estado: **FUNCIONANDO** (envío real por SMTP confirmado con PDF + JSON).
> El correo **no transmite a Hacienda, no firma y no cambia el estado fiscal** del DTE:
> solo entrega al cliente lo ya generado. No se toca PPQ.

## 1. SMTP (Gmail)

- Cuenta emisora: `dulceslanegritadte@gmail.com`.
- **Requiere un App Password de 16 caracteres** (no la contraseña normal de la cuenta).
  Se genera con verificación en 2 pasos activada → https://myaccount.google.com/apppasswords
- Va en `.env` (sin espacios). Tras cambiarlo: `php artisan config:clear`.

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587            # 587 => STARTTLS automático en Symfony Mailer
MAIL_USERNAME=dulceslanegritadte@gmail.com
MAIL_PASSWORD=xxxxxxxxxxxxxxxx   # App Password de 16 chars
MAIL_FROM_ADDRESS=dulceslanegritadte@gmail.com
MAIL_FROM_NAME="Dulces La Negrita"
```

### Certificado TLS (clave en Windows/Laragon)

Symfony Mailer abre STARTTLS con **streams de OpenSSL**, que usan `openssl.cafile`
(**no** `curl.cainfo`). Si está sin valor, Gmail falla con `certificate verify failed`.

En `php.ini` (PHP de Laragon) debe quedar:

```ini
openssl.cafile=C:\laragon\etc\ssl\cacert.pem
```

> Diagnóstico rápido del error 535 vs. TLS: si el envío llega a "Username and Password
> not accepted (535)", el TLS ya está bien y el problema es el App Password.

## 2. La cola: el envío desde la UI es ASÍNCRONO

El botón "Enviar por correo" de la pantalla del DTE **encola** el envío
(`QUEUE_CONNECTION=database`, job `App\Jobs\EnviarDteCorreo` es `ShouldQueue`) para no
bloquear la interfaz con la latencia del SMTP. **Por eso debe haber un worker corriendo**
o el correo queda esperando en la tabla `jobs` sin salir:

```bash
php artisan queue:work --tries=1
```

- Tras cambiar `.env`/config, reiniciar el worker: `php artisan queue:restart` (solo
  **señala** a un worker vivo para que se recicle; **no** arranca uno nuevo).
- Si el mailer activo es `log`/`array` (no SMTP real), el envío se registra como
  **"simulado"** (honesto: no salió por SMTP; el correo se escribe en `laravel.log`).

## 3. Generación del JSON oficial (precondición del adjunto)

- **Al generar un CCF o Nota de Crédito el JSON oficial se construye automáticamente**
  (en `DteGeneracionService::generar()`, de forma **atómica** con la generación): asigna
  número de control + código de generación, serializa, valida contra el schema del MH y
  guarda el archivo + `json_generado_path`. Si el JSON no pasa el schema, **se revierte
  toda la generación** (el documento queda en borrador; no quedan números a medias).
- El botón "Generar JSON oficial" de la pantalla quedó como herramienta de
  **diagnóstico/regeneración**: solo aparece si un documento quedó sin JSON.
- **Documentos viejos** que quedaron en estado *generado* sin JSON: comando de backfill
  **seguro** (no cambia totales ni datos fiscales, no firma, no transmite):

  ```bash
  php artisan dte:backfill-json --dry-run          # lista lo que afectaría
  php artisan dte:backfill-json --ids=71            # solo IDs concretos
  php artisan dte:backfill-json                     # todos los pendientes
  ```

## 4. Qué adjunta y qué dice el correo

- **Adjuntos:** el **PDF** (representación gráfica) + el **JSON** oficial guardado
  (`json_generado_path`). El JWS firmado se adjunta solo si `correo.adjuntar_jws` está
  activo en Configuración.
- Si se intenta enviar y **falta el JSON**, el sistema intenta generarlo al vuelo; si no
  puede, **bloquea** con: *"Este DTE no tiene JSON generado. Regenerá el JSON antes de
  enviar."* (no manda correos incompletos).
- **Cuerpo** (plantilla configurable en Configuración → Correo, `App\Support\Dte\PlantillaCorreo`):
  variables `{{cliente}}`, `{{documento}}`, `{{numero_control}}`, `{{codigo_generacion}}`,
  `{{fecha}}` (dd/mm/yyyy), `{{empresa}}`, `{{total}}`.

## 5. Límites (lo que el correo NO hace)

- No transmite a Hacienda, no firma, no guarda sello, no cambia el estado fiscal del DTE.
- No toca el módulo **PPQ / Prontos Pagos**.
