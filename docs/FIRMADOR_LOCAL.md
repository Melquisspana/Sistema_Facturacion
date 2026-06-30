# Firmador local del MH — guía de firma local

> Estado: **firma LOCAL real funcionando** (apagada por defecto: `DTE_FIRMA_ENABLED=false`).
> Firmar localmente **NO** transmite nada a Hacienda: un DTE solo queda emitido tras
> **firma + transmisión + recepción/sello + PDF definitivo**.

## 0. Resultado de la primera firma local real (2026-06-17)

La primera firma local real fue **exitosa** y **sin transmisión a Hacienda**:

- DTE **#30** (CCF 03) firmado localmente con el firmador local del MH.
- numeroControl: `DTE-03-M001P001-000000000000012`
- codigoGeneracion: `B58C589F-F27A-43EE-8EE8-A6E9B4C968BF`
- JWS: `storage/app/dte/firmados/dte-03-30-B58C589F-F27A-43EE-8EE8-A6E9B4C968BF.jws`
- Confirmado: **FIRMADO LOCALMENTE / SIN TRANSMISIÓN / NO ENVIADO A HACIENDA**.

No se transmitió a Hacienda, no se guardó sello de recepción y el estado **no** pasó a
aceptado. El JWS firmado se puede ver/descargar desde la vista del DTE (panel "DTE
firmado localmente", solo administrador/facturación).

El firmador es el servicio oficial Java/Spring del MH (`sv.mh.fe`, "Firma-Digital"),
incluido en `resources/firmador/`. Expone:

- `GET  http://localhost:8113/firmardocumento/status` → health check (no firma).
- `POST http://localhost:8113/firmardocumento/` → firma un `dteJson` y devuelve un
  JWS (serialización compacta) en `body` cuando `status = OK`.

## 1. Cómo corre el firmador

Carpeta recomendada en Windows: `resources/firmador/dte-firmador/dte-firmador/servicioFirmadoWindows`
(trae su propio JDK 21; no requiere Java en el sistema). Arranque en primer plano:

```
.\jdk-21\bin\java.exe -Dspring.profiles.active=nonssl -jar svfe-api-firmador-2.0.0-exec.jar
```

Perfiles: `nonssl` (HTTP, recomendado en localhost) o `ssl` (HTTPS con un `.p12` de TLS,
password por defecto `hacienda` — es el certificado del canal, **no** el de firma).

## 2. Dónde va el certificado de firma

El firmador busca el certificado del emisor, **por NIT**, en este orden:

1. `<directorio_de_trabajo>/uploads/<NIT>.crt`
   - Si arrancás con el comando de arriba, el directorio de trabajo es
     `...\servicioFirmadoWindows\`, por lo que el certificado va en:
     **`resources/firmador/dte-firmador/dte-firmador/servicioFirmadoWindows/uploads/<NIT>.crt`**
     (la carpeta `uploads/` hay que crearla; aún no existe).
2. `${CERTIFICATE_HOME}/<NIT>.crt` — si definís la variable de entorno `CERTIFICATE_HOME`
   apuntando a otra carpeta (recomendado: una carpeta FUERA del repo).
3. En Docker, el `docker-compose` monta `./certificado` del host como `/uploads`.

### Nombre del archivo
Debe llamarse exactamente **`<NIT>.crt`**, con el NIT **solo dígitos** (formato
`\d{14}` o `\d{9}`, sin guiones). Ej.: `06140000000011.crt`.

> El `.crt` del MH **es un XML** que contiene la llave privada cifrada; el firmador lo
> abre con la contraseña que se envía en cada request (`passwordPri`). La contraseña
> **no** se guarda en disco.

## 3. Variables `.env` que se necesitarán (SIN valores reales)

Ya existen en `config/dte.php` (todas leídas de `.env`, con defaults seguros):

```dotenv
# Conexión al firmador local (ya validado en preparación)
DTE_FIRMA_URL=http://localhost:8113
DTE_FIRMA_STATUS_PATH=/firmardocumento/status
DTE_FIRMA_ENDPOINT_PATH=/firmardocumento/
DTE_FIRMA_TIMEOUT=10

# Interruptor maestro de firma. Mantener en false hasta tener todo listo.
DTE_FIRMA_ENABLED=false

# NIT del emisor (solo dígitos, sin guiones). Define el nombre <NIT>.crt.
DTE_FIRMA_NIT=

# Contraseña del certificado de firma. NUNCA escribir el valor real en el repo;
# va solo en el .env local de la máquina del firmador.
DTE_CERT_PASSWORD=

# (Opcional) Carpeta del certificado, si NO se usa la carpeta uploads por defecto.
CERTIFICATE_HOME=
```

> `DTE_FIRMA_NIT` es el nombre de variable previsto; se confirmará al implementar
> `DteFirmaService::firmar()` (ver §5). Hoy ninguna de estas variables se usa para
> firmar (la firma está deshabilitada).

## 4. Advertencias de seguridad

- **NO subir al repositorio** ningún `.crt`, `.p12`, `.key`, `.pem` ni el `.env`.
  Ya están en `.gitignore` (`/resources/firmador/`, `*.crt`, `*.p12`, `*.key`, `*.pem`).
- **NO escribir contraseñas en el código** ni en `config/`. Solo en `.env` local.
- **NO compartir la contraseña del certificado en chats, tickets ni capturas.**
- Mantener el firmador accesible **solo en `localhost`**; nunca exponerlo a la red
  (acepta CORS `*`).
- **Firmar local NO es transmitir.** El sello de recepción y el estado "aceptado"
  solo existen tras la transmisión real a Hacienda (fase aparte, fuera de alcance).

## 5. Qué falta para la PRIMERA firma local real

Pendiente de confirmar antes de implementar `DteFirmaService::firmar()`:

1. **NIT exacto del emisor** (Dulces La Negrita), solo dígitos → nombre `<NIT>.crt`.
2. **Ubicación exacta del certificado** (carpeta `uploads/` junto al jar, o `CERTIFICATE_HOME`).
3. **Variable exacta de la contraseña** (previsto `DTE_CERT_PASSWORD`) y cómo se
   inyecta en runtime (no en código).
4. **Formato real esperado por el firmador**: confirmar contra el `.crt` real que el
   payload `{ nit, activo, passwordPri, dteJson }` produce `{ status: "OK", body: "<JWS>" }`.

Recién con esos cuatro datos se implementa la firma real: leer `json_generado_path`,
POST al firmador, validar que el JWS corresponda (codigoGeneracion), guardar el JWS en
`dte/firmados/` y setear `json_firmado_path` — **en transacción, sin transmitir, sin
sello, sin cambiar estado a aceptado**.
