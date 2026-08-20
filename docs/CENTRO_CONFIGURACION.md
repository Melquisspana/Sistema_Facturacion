# Centro de Configuración — arquitectura

Capa única de resolución de la configuración **administrable por la aplicación**.
No sustituye a `config()` de Laravel: `config()` sigue siendo la configuración del
framework y del despliegue (base de datos, colas, discos, rutas del servidor), y de
hecho esta capa se apoya en ella como respaldo.

Código: `app/Ajustes/`. Fachada: `App\Facades\Ajustes`. Tabla: `ajustes_sistema`.

---

## 1. Piezas

| Clase | Responsabilidad |
|---|---|
| `CatalogoAjustes` | **Lista blanca**. Un ajuste que no esté declarado no existe. |
| `DefinicionAjuste` | Metadata de un ajuste (tipo, nivel, impacto, persistencia, fallback, validación). |
| `Ajustes` | API pública: leer, resolver, mostrar, guardar. |
| `RepositorioAjustes` | Tabla `ajustes_sistema` + cifrado + caché versionada. |
| `AdaptadorConfiguraciones` | Puente hacia la tabla `configuraciones` existente. |
| `ConversorValor` | Conversión determinista texto ↔ tipo + validación. |
| `AuditoriaAjustes` | Registro central en Activitylog. |
| `EstadoAjuste` | Único DTO que puede salir a una vista o a un JSON. |
| `ValorAjuste` | Resultado interno: valor + fuente. **No va a pantallas.** |

---

## 2. Lista blanca

```php
Ajustes::get('contabilidad.correo');          // OK, está declarado
Ajustes::get($request->input('clave'));       // AjusteDesconocidoException
```

Una clave desconocida **no se lee, no se escribe y lanza excepción**. No es
burocracia: sin lista blanca, `Ajustes::get($request->clave)` deja que el navegador
elija qué trozo de configuración leer, y esta capa va a guardar contraseñas.

Se eligió lista blanca y no lista negra porque la lista negra falla hacia el lado
equivocado: olvidarse de agregar una clave nueva significaría exponerla.

Agregar un ajuste = agregar una `DefinicionAjuste` en `CatalogoAjustes::construir()`.
Se revisa en un diff. **No hay forma de crear claves desde la web.**

---

## 3. Orden de resolución

Explícito **por ajuste**. Nunca hay un `env()` dinámico sobre una clave arbitraria.

1. **Override**, en la única ubicación que la definición declara (§5).
2. **`config($definicion->claveConfig)`**, solo si la definición declara esa clave.
3. **Valor por defecto** de la definición.
4. `null` → fuente `no_configurado`.

`Ajustes::fuente($clave)` devuelve de dónde salió el valor que se está usando:
`base_de_datos`, `base_de_datos_legacy`, `configuracion`, `defecto`, `no_configurado`.

---

## 4. Tipos

`texto`, `entero`, `decimal`, `booleano`, `email`, `enumerado`, `secreto`, `lista`.

La conversión es **determinista**. Los valores viajan como texto y volver a
convertirlos no puede depender de las reglas laxas de PHP:

| Trampa de PHP | Aquí |
|---|---|
| `(bool) 'false' === true` | `'false'` → `false`. Gramática cerrada de verdaderos/falsos. |
| `(int) '30dias' === 30` | Error de validación explícito. |
| `float` pierde precisión | Los decimales se devuelven como **cadena** y se operan con `App\Support\Dinero` (bcmath). |

Booleanos verdaderos: `1, true, on, yes, si, sí`. Falsos: `0, false, off, no, ''`.
Cualquier otra cosa es un error, no una interpretación optimista.

Validación por ajuste: `required`, `min:N` / `max:N` (enteros), `maxlen:N` (textos),
`opciones` (allowlist estricta de los enumerados) y la validación propia del tipo
(email válido, entero, decimal).

---

## 5. Dónde se guarda cada ajuste (`Persistencia`)

Cada clave declara **una sola** ubicación de escritura. Es la respuesta al riesgo
de "el mismo valor en dos tablas y nadie sabe cuál manda".

| Estrategia | Dónde escribe | Se usa hoy para |
|---|---|---|
| `Nueva` | tabla `ajustes_sistema` (soporta cifrado) | respaldos, secreto SMTP |
| `Legacy` | tabla `configuraciones` existente | correo.\*, contabilidad.\* |
| `Ninguna` | no hay override; solo lectura de `config()` | los cuatro N3 fiscales |

Migrar una clave de `Legacy` a `Nueva` será un cambio **explícito** de este enum
acompañado de su traslado de datos. Nunca una duplicación silenciosa.

### Convivencia con la tabla `configuraciones`

Las 8 claves actuales **siguen exactamente donde están**. `AdaptadorConfiguraciones`
delega en `App\Models\Configuracion`, no la reimplementa: mientras la clave viva en
la tabla anterior hay una sola ruta de lectura/escritura y por tanto una sola caché.

**Consecuencia aceptada:** para las claves legacy sigue vigente la caché estática de
proceso de `Configuracion`, con su límite en workers de vida larga (§8). Las claves
que persisten en la tabla nueva no lo tienen. La forma de quitarlo es migrar la
clave, no envolverla.

---

## 6. Secretos

**Regla absoluta: un secreto nunca vuelve al navegador.**

```php
Ajustes::secretoParaRuntime('mail.smtp.password');  // al servicio autorizado
Ajustes::estadoParaPantalla('mail.smtp.password');  // { configurado: true, fuente: "base_de_datos" }
Ajustes::get('mail.smtp.password');                 // LogicException
```

La garantía es **estructural**, no una convención:

- `EstadoAjuste` para un secreto se construye con `valor = null`. No hay camino
  —ni un `dd()`, ni un `response()->json()`— que lo devuelva.
- `AjusteSistema` tiene `valor` en `$hidden`: ninguna serialización accidental
  (toArray, toJson, payload de cola) arrastra el criptograma.
- La auditoría registra el **hecho**, nunca el valor, el anterior ni un hash
  (un hash convierte el log de auditoría en un objetivo para probar contraseñas
  offline).
- `get()` rechaza secretos: pedir uno tiene que ser un acto deliberado y visible
  en una búsqueda del código.

De un secreto se publica: si está configurado y desde dónde sale. Nada más.

---

## 7. Cifrado y APP_KEY

Se usa **`Crypt` de Laravel** (`RepositorioAjustes` es el único punto del sistema
que cifra y descifra ajustes). No hay criptografía propia.

`ajustes_sistema.cifrado` marca qué filas están cifradas. **No es decorativo:** es
la precondición para poder rotar `APP_KEY` algún día — sin ella no habría forma de
saber qué hay que volver a cifrar.

> **Dependencia crítica:** los valores cifrados solo se recuperan con la `APP_KEY`
> con la que se cifraron. Perderla = perderlos. Cambiarla a mano con secretos
> guardados = dejarlos irrecuperables.

Procedimiento de rotación: **`docs/ROTACION_APP_KEY.md`**. No está implementado ni
se ha ejecutado.

---

## 8. Caché e invalidación

El problema que resuelve: la caché estática de `App\Models\Configuracion` vive en
una propiedad `static` del proceso. En una petición web da igual; el worker de
colas vive horas y se queda con el valor que leyó la primera vez.

**Estrategia: caché del store compartido, versionada por huella.**

```
ajustes:huella          → UUID que cambia en CADA escritura
ajustes:mapa:{huella}   → mapa completo clave ⇒ fila (TTL 5 min como red de seguridad)
```

Cada lectura consulta la huella (1 hit de caché) y solo reutiliza su memoria de
proceso si no cambió. Al guardar, la huella cambia y **todos** los procesos —web,
worker, CLI— pasan a la entrada nueva en su siguiente lectura, sin reinicios.

El mapa se guarda entero en una entrada, no clave por clave: la tabla es de decenas
de filas por diseño (overrides, no catálogo) y así una escritura invalida un objeto
en vez de N.

**Límite conocido, sin magia:** esto funciona porque el store de caché es
compartido (`database` en producción, `file` en desarrollo). Con `CACHE_STORE=array`
(la suite de tests) la huella no se comparte entre procesos y un worker seguiría sin
enterarse. **Las claves `Legacy` conservan la caché estática antigua** hasta que se
migren; para ellas, un cambio sí puede necesitar reinicio del worker.

---

## 9. Niveles N1 / N2 / N3

| Nivel | Impacto | Ceremonia | Permiso |
|---|---|---|---|
| **N1** | bajo/medio | guardar + auditoría | `configuracion.gestionar` |
| **N2** | alto | confirmación explícita + auditoría | `configuracion.gestionar` |
| **N3** | fiscal crítico | permiso especial + frase exacta + reautenticación + precondiciones | `configuracion.critica` |

Esta fase construye el **nivel y el permiso**. Los modales de N2/N3 son de la fase
siguiente; la metadata ya permite saber cuál corresponde a cada clave
(`Ajustes::nivel($clave)`, `NivelConfirmacion::requiereConfirmacion()`,
`requiereCeremoniaFuerte()`).

### El permiso `configuracion.critica`

`configuracion.gestionar` deja de ser una llave maestra. Quien administra la
plantilla del correo no tiene por qué poder poner el sistema a emitir en producción.

Hoy solo lo tiene el **administrador** (recibe todos los permisos). Ningún rol
existente ganó acceso.

### Orden de comprobaciones al escribir

1. la clave existe en el catálogo;
2. **el actor tiene el permiso del nivel**;
3. el ajuste está abierto a escritura;
4. nadie lo cambió mientras tanto (si se pasa `$vistoEn`);
5. el valor es válido para su tipo.

El permiso va **antes** que la editabilidad a propósito: así "un usuario sin
`configuracion.critica` intenta tocar el ambiente fiscal" responde 403 y no
"todavía no es editable", y el día que N3 se abra el mismo código lo seguirá
negando sin cambiar una línea.

---

## 10. Auditoría

Log `ajustes` en Activitylog. Central: un solo sitio decide qué se registra, y por
eso puede aplicar la regla de secretos sin excepciones.

```
Usuario cambió la configuración «contabilidad.correo»
  clave, seccion, nivel, impacto, accion, fuente_antes, fuente_despues, ip
  valor_antes: "conta@ejemplo.com"    valor_despues: "conta2@ejemplo.com"

Usuario reemplazó el secreto «mail.smtp.password»
  clave, seccion, nivel, impacto, accion, fuente_antes, fuente_despues, ip
  (sin valor, sin valor anterior, sin hash)
```

`fuente_antes` / `fuente_despues` es la parte menos obvia y la más útil: deja
registrado que un valor dejó de leerse del `.env` y pasó a leerse de la base —o al
revés— sin necesidad de conocer su contenido.

La IP se registra solo si la petición trae `REMOTE_ADDR`. En consola/worker no lo
trae y queda ausente: mejor ausente que una IP local inventada.

---

## 11. Concurrencia

**Decisión: una fila por ajuste + comprobación optimista opcional por `updated_at`.**

- Dos administradores en pantallas distintas **no chocan**: cada ajuste es su propia
  fila y no hay un documento único que se sobrescriba entero.
- El choque real —los dos editan el mismo campo— se detecta pasando a `guardar()`
  el `updated_at` que el formulario tenía al abrirse:

  ```php
  Ajustes::guardar('respaldos.dias_retencion', 90, $vistoEn);  // ConflictoDeAjusteException
  ```

- Un formulario con varios campos usa `guardarVarios()`, que escribe **todo en una
  transacción**: un valor inválido en el tercero no deja los dos primeros aplicados.
  Los permisos se comprueban todos *antes* de abrir la transacción.

No se implementó bloqueo pesimista ni versionado por fila: para dos o tres
administradores, un `updated_at` resuelve el caso real y no añade estado que
mantener.

---

## 12. Cómo agregar un ajuste nuevo

1. Añadir la `DefinicionAjuste` en `CatalogoAjustes::construir()`, decidiendo:
   - **tipo** (¿es un secreto? entonces `Sensibilidad::SecretoCritico`);
   - **impacto** y **nivel** (¿fiscal? entonces N3);
   - **persistencia** (`Nueva` salvo que la clave ya viva en `configuraciones`);
   - **fallback**: `claveConfig` si hoy sale de `config()`/`.env`;
   - **porDefecto** y **reglas** de validación.
2. Si es N3, dejarlo en `Editabilidad::Futura` hasta que exista su ceremonia.
3. Cambiar los consumidores para que lean por `Ajustes::` en vez de `config()`
   directamente. **No copiar el valor a la base:** el fallback ya lo resuelve.
4. Añadir tests: resolución, fallback, validación y —si es secreto— no fuga.

`DefinicionAjuste::hacer()` valida los invariantes al arrancar: un secreto en la
tabla legacy, un enumerado sin opciones o un editable sin dónde guardarse fallan
inmediatamente, no en producción.

---

## 13. Qué NO debe convertirse nunca en un ajuste web

- `APP_KEY` — descifra todo lo demás.
- `DB_*` — cambiarla desde la web deja la aplicación sin la base desde la que
  leería el cambio.
- Credenciales del túnel de Cloudflare / acceso remoto.
- `SESSION_*`, `QUEUE_CONNECTION`, `CACHE_STORE`, disco principal de `filesystems`.
- Rutas del servidor.

Todas son configuración de **infraestructura**: si el cambio sale mal, la pantalla
que lo hizo deja de estar disponible para deshacerlo. Van en `.env`, con acceso al
servidor.

Tampoco: **editor web de `.env`**, **textarea genérico clave/valor**, ni creación de
claves arbitrarias. `RegistryAjustesTest` fija estas exclusiones.

---

## 14. Estado actual (Fase 2)

Catálogo **mínimo y representativo**, no las ~140 claves del inventario. Sirve para
ejercitar las tres estrategias de persistencia, los tres niveles y el camino de los
secretos.

| Clave | Nivel | Persistencia | Fallback |
|---|---|---|---|
| `contabilidad.correo` | N1 | Legacy | — |
| `contabilidad.enviar_copia` | N1 | Legacy | defecto `false` |
| `correo.auto_envio` | N1 | Legacy | defecto `false` |
| `correo.adjuntar_jws` | N1 | Legacy | defecto `false` |
| `correo.plantilla` | N1 | Legacy | defecto `PlantillaCorreo::DEFAULT` |
| `respaldos.notificaciones.correo` | N2 | Nueva | `backup.notifications.mail.to` |
| `respaldos.dias_retencion` | N2 | Nueva | `backup_diario.dias_retencion`, defecto 30 |
| `mail.smtp.password` | N2 | Nueva (cifrado) | `mail.mailers.smtp.password` |
| `dte.ambiente` | **N3** | Ninguna (solo lectura) | `dte.ambiente` |
| `dte.transmision.ambiente` | **N3** | Ninguna (solo lectura) | `dte.transmision.ambiente` |
| `dte.firma.enabled` | **N3** | Ninguna (solo lectura) | `dte.firma.enabled` |
| `dte.transmision.enabled` | **N3** | Ninguna (solo lectura) | `dte.transmision.enabled` |

**Ningún valor existente fue migrado.** `MAIL_PASSWORD`, `DTE_PROD_PASSWORD`,
`DTE_CERT_PASSWORD`, `GMAIL_CLIENT_SECRET`, `IMAP_PASSWORD` y el resto siguen donde
estaban. `mail.smtp.password` está **declarado**, no copiado: si no hay override,
se resuelve desde `config()` igual que antes.

Consumidor real ya migrado: el **correo de contabilidad** (`CorreoContabilidad`),
que unifica los cuatro sitios que resolvían la misma dirección por separado.
