# Migración a producción del Centro de Configuración

Procedimiento para llevar a producción las **tres migraciones** de las Fases 2–4.

> **Estado: ENSAYADO DE PUNTA A PUNTA (Fase 6) contra una base MySQL aislada, con
> datos representativos, incluyendo la aplicación por ruta, reinicio de worker y los
> dos niveles de rollback selectivo — con migraciones de OTRO módulo pendientes en el
> árbol, que quedaron intactas. NO ejecutado en producción.**

**Nada de esto toca DTE, correlativos, firma, transmisión ni invalidación.** Las
tres migraciones solo crean tablas nuevas y mueven cinco filas de configuración de
correo. El ambiente fiscal, los correlativos y los interruptores de firma y
transmisión siguen viviendo en el `.env` y no se leen ni se escriben aquí.

---

## 1. Qué se va a aplicar

| Migración | Qué hace | Reversible |
|---|---|---|
| `2026_08_19_090000_create_ajustes_sistema_table` | Crea `ajustes_sistema` (vacía) | sí (`drop`) |
| `2026_08_20_090000_create_verificaciones_configuracion_table` | Crea `verificaciones_configuracion` (vacía) | sí (`drop`) |
| `2026_08_20_120000_migrar_configuraciones_correo_a_ajustes` | **Mueve 5 filas** de `configuraciones` a `ajustes_sistema` | sí (las devuelve) |

Solo la tercera toca datos. Mueve exactamente estas cinco claves:

```
contabilidad.correo        contabilidad.enviar_copia
correo.auto_envio          correo.adjuntar_jws          correo.plantilla
```

**No toca** las demás filas de `configuraciones`, que no son configuración sino
estado de proceso y deben quedarse donde están:

```
produccion.auth_prod_validada / _en / _fuente
produccion.ultimo_ccf_externo
ppq.albaranes.ultimo_dia_completo
```

### Nunca `php artisan migrate` a secas

`migrate --force` no sabe de fases: aplica **todas las migraciones pendientes en el
árbol desplegado**. El árbol contiene módulos que todavía no se despliegan —hoy,
Control de Asistencia—, y una corrida a secas se los llevaría por delante hacia
producción sin que nadie lo hubiera decidido.

**Todo este procedimiento nombra las tres migraciones por RUTA**, al aplicar y al
revertir. Es lo que hace que el despliegue del Centro sea independiente de qué más
haya pendiente en el árbol:

```
database/migrations/2026_08_19_090000_create_ajustes_sistema_table.php
database/migrations/2026_08_20_090000_create_verificaciones_configuracion_table.php
database/migrations/2026_08_20_120000_migrar_configuraciones_correo_a_ajustes.php
```

Las migraciones que quedan **deliberadamente fuera** y siguen `Pending` después de
este despliegue (tienen su propia fase):

```
database/migrations/2026_08_20_200000_create_asistencia_dispositivos_table.php
database/migrations/2026_08_20_200100_create_asistencia_empleados_table.php
database/migrations/2026_08_20_200200_create_asistencia_huellas_table.php
database/migrations/2026_08_20_200300_create_asistencia_marcaciones_table.php
```

Que sigan pendientes **no es un descuido, es el resultado esperado**: §4.7 lo
comprueba explícitamente.

### Qué datos se tocan y cuáles no

| | Se toca | No se toca |
|---|---|---|
| Tablas | `configuraciones` (borra 5 filas), `ajustes_sistema` (crea + inserta 5), `verificaciones_configuracion` (crea, vacía), `migrations` (3 filas), `cache` (una entrada de huella) | todo lo demás |
| Datos fiscales | — | `dtes`, `dte_envios`, `secuencias`/correlativos, `exportaciones`, catálogos MH |
| Configuración de despliegue | — | `.env`, `APP_KEY`, certificados, credenciales de Hacienda |
| Usuarios y permisos | — | `users`, `roles`, `permissions` |

---

## 2. La ventana entre desplegar y migrar

Un despliegue es `git pull` y después `php artisan migrate`. Entre las dos cosas
pasan segundos o minutos en los que **el código nuevo corre contra el esquema
viejo**, y con Apache siempre encendido cada petición de esa ventana entra por ahí.

Los tres momentos están cubiertos y **medidos en el ensayo de la Fase 6** sobre una
base MySQL con las cinco claves cargadas (`VentanaDespliegueTest` y
`EnsayoDespliegueTest` los fijan en la suite):

| Momento | Qué pasa | Resultado medido |
|---|---|---|
| Código nuevo, tablas nuevas ausentes | `RepositorioAjustes` y `RegistroVerificaciones` comprueban `Schema::hasTable` y devuelven vacío | Las 5 claves resuelven por la **tabla anterior**; 10/10 pantallas responden 200; ningún error de SQL |
| Tablas creadas, datos sin mover | Las cinco claves siguen en `configuraciones` | Se leen por la **lectura de transición** (`claveLegacy`) |
| Datos ya movidos | Las cinco claves están en `ajustes_sistema` | Se leen de ahí; la tabla anterior ya no las tiene |

**LEER nunca falla. ESCRIBIR sí se rechaza**, y a propósito: durante la primera
ventana no existe la tabla donde guardar. Guardar desde la pantalla devuelve un
error de formulario —«la configuración todavía no se puede guardar… volvé a
intentarlo en unos minutos; la configuración actual no se ha perdido»— en vez de un
500 con la excepción de SQL. El intento rechazado **no toca nada**: lo que había
sigue resolviéndose igual. La prueba de conexión sí funciona; lo único que no se
guarda en esa ventana es su línea de historial.

**Conclusión: no hay ventana de pérdida de configuración.** En los tres momentos
cada clave resuelve al mismo valor. Lo único que cambia es la FUENTE, y
`php artisan ajustes:estado` la muestra.

---

## 3. Los dos fallos que encontró el ensayo (y que ya están corregidos)

Se documentan porque explican por qué el procedimiento es como es.

### 3.1 La mudanza de datos apagaba el auto-envío durante 5 minutos

La migración escribe con `DB::table()` —correcto: una migración no debe depender de
los servicios de la aplicación— y por tanto pasaba **por detrás** de la caché
compartida de `RepositorioAjustes`, que está versionada por una huella que solo
cambiaba al escribir a través de él.

Medido: justo después de `migrate`, un proceso nuevo veía

```
contabilidad.correo   → NO configurado   (era contadora@…)
correo.auto_envio     → false            (era true)
correo.plantilla      → la plantilla por defecto
```

porque servía el mapa cacheado **antes** de la mudanza (vacío) mientras la migración
ya había borrado las filas de la tabla anterior. Duraba hasta que alguien corriera
`cache:clear` o venciera la TTL de 5 minutos, y en ese rato **los DTE aceptados no
encolaban el correo al cliente**.

Corregido: la migración invalida la caché al terminar `up()` y `down()`, y el `down()`
de la tabla hace lo mismo al borrarla. La ventana pasó a ser cero, sin depender de
que el operador se acuerde de limpiar cachés.

### 3.2 Un worker que no se reinicia lee una tabla vacía

Esto **no** se arregla con código: es una consecuencia de que `queue:work` mantiene
el código cargado en memoria durante toda su corrida. El código anterior al Centro
de Configuración lee `Configuracion::getBool('correo.auto_envio')` **directo de la
tabla `configuraciones`**, que la mudanza acaba de vaciar. Medido sobre la misma base:

| Código en memoria | Base | `correo.auto_envio` | `contabilidad.correo` |
|---|---|---|---|
| viejo | vieja | `true` | `contadora@…` |
| **viejo** | **migrada** | **`false`** | **`null`** |
| nuevo | migrada | `true` | `contadora@…` |

Por eso **el worker se reinicia ANTES de migrar, no después**: así entra a la mudanza
ya con el código nuevo, que sabe leer las dos ubicaciones. El paso 4.4 lo hace en ese
orden a propósito.

---

## 4. Procedimiento

### 4.0 Checklist previo

- [ ] Ventana de baja actividad acordada (idealmente fuera de horario de emisión).
- [ ] Nadie emitiendo DTE en ese momento.
- [ ] Acceso al servidor y a la base confirmado.
- [ ] `git status` en el servidor **limpio** (sin cambios locales que el pull pise).
- [ ] Espacio en disco suficiente para el respaldo.
- [ ] **`CACHE_STORE` del `.env` de producción es `database` o `file`, no `array`.**
      Con `array` la caché no se comparte entre procesos y el worker no se enteraría
      de un cambio de configuración hasta reiniciarse (ver `docs/CENTRO_CONFIGURACION.md`).
- [ ] Worker de colas y programador localizados (hay que reiniciar el worker).
- [ ] Este documento leído entero antes de empezar.

### 4.1 Respaldo (obligatorio)

```bash
php artisan backup:mysql-diario --origen=manual
```

Deja el dump verificado y su registro en `respaldo_ejecuciones`. Anotar la ruta del
archivo: es el punto de retorno.

Respaldo adicional acotado, por si hay que revertir solo la configuración:

```bash
mysqldump -u USUARIO -p BASE configuraciones gmail_cuentas > pre-migracion-config.sql
```

`gmail_cuentas` va incluida aunque la migración no la toque: sus tokens OAuth están
cifrados con la APP_KEY y son lo más caro de recuperar si algo sale mal (obligan a
reconectar la cuenta a mano desde Google).

**Qué más hay que tener a salvo antes de tocar producción**, y por qué:

| Qué | Por qué | Cómo |
|---|---|---|
| Dump completo de la base | Punto de retorno único | paso anterior |
| `configuraciones` + `gmail_cuentas` | Reversión acotada sin restaurar todo | `mysqldump` de arriba |
| **`.env`** | Contiene la `APP_KEY`. **Sin ella, todo lo cifrado es irrecuperable**: tokens de Gmail y cualquier secreto que se guarde después desde las pantallas nuevas | copia fuera del servidor |
| Certificado de firma (`resources/firmador/`, `.p12`/`.crt`) | No se versiona y no se puede volver a emitir a voluntad | copia fuera del servidor |
| SHA del commit desplegado hoy | Es el destino del rollback de código (§5.3) | `git rev-parse HEAD > commit-anterior.txt` |
| Salida de `ajustes:estado` | Es la referencia de comparación (§4.2) | `antes.txt` |

**Si falla:** detenerse. Sin respaldo no se migra.

### 4.2 Fotografía ANTES

```bash
php artisan ajustes:estado > antes.txt
php artisan migrate:status | tail -20
```

`antes.txt` es la referencia con la que se compara después. Guardarlo.

`migrate:status` debe mostrar las tres migraciones del Centro como **Pending**.
**Anotar la lista COMPLETA de pendientes**, incluidas las que no son del Centro: es
la referencia contra la que se comprueba, en §4.7, que el despliegue no aplicó nada
de más.

**Si falla:** el comando es de solo lectura; un fallo aquí indica un problema previo
(base inaccesible, `.env` roto). Resolver antes de continuar.

### 4.3 Desplegar el código

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan config:clear
```

> Desde este momento y hasta el paso 4.5 corre la ventana descrita en §2. Es segura
> para LEER; guardar configuración desde la pantalla se rechaza con un mensaje claro.

**Si falla `git pull`** (cambios locales): no forzar. Ver qué hay, decidir, y repetir.
Nada se ha migrado todavía; el sistema sigue entero.
**Si falla `composer install`:** volver al commit anterior con `git reset --hard` al
SHA que estaba desplegado y repetir `composer install`. La base no se tocó.

### 4.4 Reiniciar el worker ANTES de migrar

```bash
php artisan queue:restart
```

El worker termina el job en curso y el bucle de `queue-worker-auto.bat` lo relanza
con el código nuevo. **Esperar a que el heartbeat confirme el worker nuevo** antes de
seguir (`/admin/salud-sistema` → «Worker / cola»).

Por qué aquí y no al final: §3.2. Un worker con código viejo contra la base ya
migrada lee la tabla que la mudanza acaba de vaciar y apaga el auto-envío en silencio.

**Si falla:** no continuar a 4.5. Un worker que no se reinicia es exactamente el
escenario de §3.2. En el peor caso, detener la tarea programada del worker, migrar y
volver a arrancarla.

### 4.5 Migrar

```bash
php artisan migrate --force \
  --path=database/migrations/2026_08_19_090000_create_ajustes_sistema_table.php \
  --path=database/migrations/2026_08_20_090000_create_verificaciones_configuracion_table.php \
  --path=database/migrations/2026_08_20_120000_migrar_configuraciones_correo_a_ajustes.php
```

`--force` es obligatorio en producción (Laravel pide confirmación interactiva si no).
**Las tres rutas son obligatorias**: sin ellas se aplicaría también lo de los módulos
que no se despliegan hoy.

El orden lo decide el timestamp, no el orden de los `--path`, pero se escriben en el
orden real para que el comando se lea como lo que hace:

```
1) create_ajustes_sistema_table                 crea la tabla
2) create_verificaciones_configuracion_table    crea la tabla
3) migrar_configuraciones_correo_a_ajustes      MUEVE las cinco claves
```

La salida debe listar **exactamente esas tres** y ninguna más. Si aparece una cuarta,
detenerse: se ejecutó el comando sin las rutas.

Las tres entran en un mismo lote; por eso la reversión también va por ruta (§5.0).

Tiempo medido en el ensayo (MySQL 8.4 local, 10 filas en `configuraciones`):
**38 ms + 58 ms + 29 ms = 125 ms de trabajo real, 0,74 s de reloj** contando el
arranque de artisan.

**Si falla la 1 o la 2:** no se movió ningún dato. Se corrige la causa y se repite;
`migrate` retoma desde donde quedó.
**Si falla la 3:** su `up()` corre **dentro de una transacción**, así que o movió las
cinco claves o no movió ninguna. No existe el estado «media mudanza». Ir a §5.1.

### 4.6 Cachés

```bash
php artisan config:clear
```

`cache:clear` **ya no hace falta** para la configuración: la migración invalida su
propia caché (§3.1). Sigue siendo inofensivo correrlo.

Si el despliegue general va a cachear config/rutas/vistas, hacerlo aquí siguiendo
`docs/DESPLIEGUE_SERVIDOR.md` §5.

### 4.7 Fotografía DESPUÉS y comparación

```bash
php artisan ajustes:estado > despues.txt
diff antes.txt despues.txt
```

**Qué es correcto ver en el diff** (así salió en el ensayo):

- `Tabla ajustes_sistema AUSENTE` → `Tabla ajustes_sistema: presente` (ídem la otra);
- las cinco claves cambian de fuente `TABLA ANTERIOR` → `base de datos`;
- desaparece el aviso «Todavía en la tabla anterior (5): …»;
- aparece «Mudanza de datos COMPLETA».

**Qué NO puede aparecer:**

- ninguna clave con el **valor** cambiado;
- ninguna clave que pase de `configurado: sí` a `configurado: NO`;
- ninguna tabla marcada como AUSENTE.

Si aparece cualquiera de esas tres cosas, ir a §5.

**Y la comprobación que hace este despliegue independiente del resto del árbol:**

```bash
php artisan migrate:status | grep -i pending
```

Tiene que seguir listando **todo lo que anotaste en §4.2 menos las tres del Centro**.
Las cuatro de Control de Asistencia siguen `Pending`, y sus tablas no existen:

```sql
SELECT COUNT(*) FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name LIKE 'asistencia%';   -- debe dar 0
```

Si alguna dejó de estar pendiente, el comando de §4.5 corrió sin sus `--path`. Eso no
se revierte con §5: hay que revertir también lo que se aplicó de más, y este documento
no lo cubre.

Comprobación directa en la base (opcional, y la más rápida de leer):

```sql
SELECT COUNT(*) FROM configuraciones
 WHERE clave IN ('contabilidad.correo','contabilidad.enviar_copia',
                 'correo.auto_envio','correo.adjuntar_jws','correo.plantilla');   -- debe dar 0
SELECT COUNT(*) FROM ajustes_sistema;                                             -- debe dar 5
SELECT COUNT(*) FROM configuraciones;                                             -- las 5 ajenas siguen
```

### 4.8 Verificación funcional

- [ ] Entrar a **Configuración → Resumen**: sin avisos nuevos.
- [ ] **Configuración → Correo**: el correo de contabilidad y la copia oculta
      muestran lo mismo que antes.
- [ ] **Probar conexión** SMTP: correcto (no envía ningún correo).
- [ ] Emitir **nada**. Solo mirar. Si el auto-envío estaba activo, sigue activo.
- [ ] `php artisan ajustes:estado --pendientes`: nada inesperado sin configurar.
- [ ] Heartbeat del worker en verde.

---

## 5. Rollback

Elegir el nivel más bajo que resuelva el problema.

### 5.0 NUNCA revertir contando pasos

`migrate:rollback --step=N` deshace **las N últimas migraciones aplicadas**, sean
cuales sean. Ese número solo vale si las tres del Centro son lo último que se
aplicó, y deja de valer en cuanto el mismo despliegue lleva cualquier otra
migración: `--step=1` pasa a deshacer la de otro módulo y **la configuración ni se
toca**, mientras se borra una tabla ajena que sí tenía datos.

No es hipotético: se reprodujo en el ensayo con el módulo de Asistencia presente en
el árbol. `--step=1` borró `asistencia_marcaciones` y dejó las cinco claves donde
estaban. Un rollback que destruye lo que no debía y no revierte lo que debía es peor
que no tener rollback.

**Se revierte por RUTA, que nombra el archivo exacto.** `--step` sigue haciendo
falta, pero solo para que el candidato entre en la ventana de búsqueda: acota
*cuántas* mirar, y `--path` decide *cuál*. Un `--step` generoso es inofensivo porque
la ruta filtra.

### 5.1 Solo revertir la mudanza de datos

Devuelve las cinco claves a `configuraciones` y las quita de `ajustes_sistema`:

```bash
php artisan migrate:rollback \
  --path=database/migrations/2026_08_20_120000_migrar_configuraciones_correo_a_ajustes.php \
  --step=20 --force

php artisan ajustes:estado > rollback.txt && diff antes.txt rollback.txt
```

Verificado en el ensayo con siete migraciones en el mismo lote: revierte
**exactamente** esa (`ajustes_sistema` 5 → 0, `configuraciones` 5 → 10) y deja las
otras cuatro aplicadas y sus tablas intactas.

Medido: **38 ms de trabajo, ~0,65 s de reloj**. La reversa invalida la caché sola, así
que un proceso nuevo ve inmediatamente fuente `TABLA ANTERIOR` otra vez.

El código nuevo sigue funcionando: las lee por la vía de transición. **Esta es la
reversión esperada** si algo del diff de §4.7 no cuadra.

Comprobado en el ensayo: tras este rollback, las diez filas de `configuraciones`
vuelven con **clave y valor idénticos** a los de antes de migrar. Lo único que
cambia son `id`, `created_at` y `updated_at` de las cinco (se reinsertan con la
fecha del rollback). Es inofensivo: `updated_at` solo alimenta la comprobación
optimista de concurrencia, y moverla hacia adelante como mucho hace que un
formulario abierto desde antes pida recargar.

### 5.2 Revertir también las tablas

Solo después de 5.1, y nombrando los dos archivos:

```bash
php artisan migrate:rollback \
  --path=database/migrations/2026_08_20_090000_create_verificaciones_configuracion_table.php \
  --path=database/migrations/2026_08_19_090000_create_ajustes_sistema_table.php \
  --step=20 --force
```

Borra las dos tablas nuevas. **Esto sí destruye datos**: todo override que solo
viviera ahí —la contraseña SMTP, el secreto de Gmail o el de IMAP guardados desde
las pantallas nuevas— y el historial de verificaciones. Los tokens OAuth de Gmail
NO se pierden: viven en `gmail_cuentas`, que ninguna de estas migraciones toca.

Si alguien ya guardó un secreto desde las pantallas nuevas, hay que volver a
introducirlo a mano después: no está en el `.env` ni en ningún otro sitio.

Deja al sistema en la ventana de §2, que es segura; conviene completarla con 5.3 y no
quedarse ahí.

### 5.3 Volver al código anterior

```bash
git reset --hard 8f82944   # o el commit que estuviera desplegado
composer install --no-dev --optimize-autoloader
php artisan config:clear
php artisan queue:restart
```

Hacerlo **después** de 5.1 o 5.2: el código anterior no conoce `ajustes_sistema` y
leería las cinco claves de `configuraciones`, que la mudanza dejó vacía (§3.2).
El `queue:restart` no es opcional por el mismo motivo.

### 5.4 Restaurar el respaldo

Último recurso, si los datos quedaron inconsistentes:

```bash
mysql -u USUARIO -p BASE < pre-migracion-config.sql   # solo `configuraciones`
```

o el dump completo del paso 4.1 siguiendo `docs/RESTORE_BACKUP_WINDOWS.md`.

---

## 6. Secretos, APP_KEY y las integraciones

Comprobado en el ensayo sobre una base con una cuenta de Gmail ficticia y secretos
inventados en el `.env`. **Ninguna de las tres migraciones toca un secreto.**

### 6.1 Dónde vive cada secreto hoy, y dónde vivirá

| Secreto | Hoy (producción) | Después de migrar |
|---|---|---|
| `mail.smtp.password` | `.env` → `config('mail.mailers.smtp.password')` | igual, hasta que alguien lo reemplace desde la pantalla |
| `ppq.gmail.client_secret` | `.env` | igual |
| `documentos_recibidos.password` (IMAP) | `.env` | igual |
| Tokens OAuth de Gmail (`gmail_cuentas`) | tabla propia, cifrados con la APP_KEY | **sin cambios: ninguna migración la toca** |

La razón es que los tres secretos del catálogo persisten en `ajustes_sistema`, que
hoy **no existe**. La migración la crea VACÍA: no copia secretos desde ningún lado.
Medido antes y después — los tres siguen resolviéndose con fuente `configuracion`
(es decir, `.env`) y con el mismo valor.

### 6.2 Cuántas filas cifradas hay en juego

`php artisan ajustes:estado` termina con «Secretos cifrados en la base: N».

- **Antes de migrar: solo los 2 campos de `gmail_cuentas`.** `ajustes_sistema` no
  existe y el inventario de rotación la salta.
- **Justo después de migrar: los mismos 2.** Las cinco claves mudadas se insertan con
  `cifrado = 0` explícito — ninguna es un secreto y se mudan en claro, igual que
  estaban en la tabla anterior. Verificado fila por fila.
- **Solo sube cuando una persona guarda un secreto** desde las pantallas nuevas. En el
  ensayo, guardar la contraseña SMTP la dejó como criptograma de 228 caracteres
  (`cifrado = 1`) y el inventario pasó a 3.

### 6.3 Gmail OAuth: qué pasaría y qué no

Los tokens están cifrados con la APP_KEY **actual**. Como el procedimiento no toca la
APP_KEY ni `gmail_cuentas`, siguen descifrándose exactamente igual antes, durante y
después. Comprobado en las tres etapas del ensayo.

Lo único que los rompería es cambiar la APP_KEY, y eso **no se hace aquí**: tiene su
propio procedimiento (`docs/ROTACION_APP_KEY.md`) con re-cifrado verificado.

> **No rotar la APP_KEY el mismo día que se migra.** No porque interactúen, sino
> porque si algo falla habría dos cambios simultáneos y ninguna forma rápida de saber
> cuál fue. Dejar pasar un ciclo de uso entre los dos.

### 6.4 SMTP, IMAP y Gmail como servicios

El procedimiento **no prueba ninguna conexión por su cuenta** y no manda ningún
correo. Después de migrar, «Probar conexión» sigue disponible y sigue sin enviar nada
(abre el diálogo SMTP y lo cierra). Durante la ventana de §2 la prueba también
funciona; lo único que no se guarda entonces es su línea de historial.

---

## 7. Downtime

**Cero.** No hace falta `php artisan down` y el procedimiento no lo usa.

| Tramo | Estado de la aplicación | Duración medida |
|---|---|---|
| `git pull` + `composer install` | Arriba. Configuración se lee de la tabla anterior. **Guardar configuración se rechaza** con mensaje claro. | lo que tarde composer |
| `queue:restart` | Arriba. El worker termina su job y se relanza. | segundos |
| `migrate --force` | Arriba. Ninguna tabla se bloquea de forma apreciable: son dos `CREATE TABLE` y cinco `INSERT`/`DELETE`. | **0,74 s** |
| Después | Arriba, ya migrado. | — |

El único «no disponible» del procedimiento es **guardar configuración**, y solo entre
`git pull` y `migrate`. Ni la emisión, ni la impresión, ni el envío de correo, ni las
consultas se interrumpen en ningún momento.

---

## 8. Lo que este procedimiento NO hace

- No cambia el ambiente fiscal, ni los correlativos, ni la firma, ni la transmisión.
- No emite, no transmite y no invalida ningún documento.
- No toca `APP_KEY` (eso es `docs/ROTACION_APP_KEY.md`, y es otro procedimiento).
- No mueve secretos del `.env` a la base: los secretos siguen donde estén hasta que
  alguien los reemplace a mano desde la pantalla.
- No borra la tabla `configuraciones` ni ninguna clave ajena a las cinco listadas.
- No contacta a Hacienda, ni a Gmail, ni al buzón IMAP, ni al firmador.

---

## 9. Después de migrar: qué se puede retirar

Cuando **producción** confirme «Mudanza de datos COMPLETA» y pase un ciclo de uso
sin incidencias, se puede quitar:

1. `claveLegacy` de las cinco definiciones en `CatalogoAjustes`;
2. la lectura de transición en `Ajustes::overrideAlmacenado()`;
3. `AdaptadorConfiguraciones`, que se queda sin usuarios.

**No antes.** Mientras exista un despliegue sin migrar, esa lectura es lo único que
evita la pérdida de configuración descrita en §2.

---

## 10. Cómo repetir el ensayo

Reproducible en cualquier máquina con MySQL local. **No toca la base de desarrollo ni
la de producción**: crea una base aparte y la destruye al empezar.

```bash
# 1. Base de ensayo, aislada.
mysql -u root -e "DROP DATABASE IF EXISTS facturacion_ensayo; CREATE DATABASE facturacion_ensayo
                  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Todo el resto corre con estas variables por delante, para no usar el .env real:
export DB_DATABASE=facturacion_ensayo CACHE_STORE=database QUEUE_CONNECTION=database \
       MAIL_MAILER=array APP_ENV=local DOCUMENTOS_RECIBIDOS_MAIL_DRIVER=none \
       DTE_FIRMA_ENABLED=false DTE_TRANSMISION_ENABLED=false DTE_AMBIENTE=00

# 3. Estado "producción hoy": aplicar todo y deshacer lo que aún no está desplegado
#    (las tres del Centro MÁS las de los módulos pendientes; hoy, cuatro de Asistencia).
php artisan migrate --force
php artisan migrate:rollback --step=7 --force
php artisan db:seed --class=RolesSeeder --force

# 4. Cargar las cinco claves en `configuraciones` con SQL crudo (sin la app).

# 5. Aplicar SOLO el Centro, igual que en producción: por ruta.
php artisan ajustes:estado > antes.txt
php artisan migrate --force \
  --path=database/migrations/2026_08_19_090000_create_ajustes_sistema_table.php \
  --path=database/migrations/2026_08_20_090000_create_verificaciones_configuracion_table.php \
  --path=database/migrations/2026_08_20_120000_migrar_configuraciones_correo_a_ajustes.php

# 6. Comprobar: valores intactos y Asistencia todavía pendiente.
php artisan ajustes:estado > despues.txt && diff antes.txt despues.txt
php artisan migrate:status | grep -i pending      # las 4 de Asistencia siguen ahí

# 7. Revertir, también por ruta.
php artisan migrate:rollback --path=database/migrations/2026_08_20_120000_migrar_configuraciones_correo_a_ajustes.php --step=20 --force
php artisan migrate:rollback --path=database/migrations/2026_08_20_090000_create_verificaciones_configuracion_table.php --path=database/migrations/2026_08_19_090000_create_ajustes_sistema_table.php --step=20 --force
```

Resultado del ensayo con este escenario: las tres del Centro aplicadas, las cuatro de
Asistencia `Pending` y **cero** tablas `asistencia%` creadas; las cinco claves mudadas
sin cambiar de valor; la reversión selectiva devolvió exactamente el Centro y dejó el
árbol con las siete pendientes otra vez.

Lo que el ensayo comprueba y la suite ya no deja retroceder está en
`tests/Feature/Ajustes/EnsayoDespliegueTest.php` y
`tests/Feature/Ajustes/VentanaDespliegueTest.php`.
