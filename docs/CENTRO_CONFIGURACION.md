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

Las dos ceremonias están construidas (§15 y §16). La metadata dice cuál toca:
`Ajustes::nivel($clave)`, `NivelConfirmacion::requiereConfirmacion()` y
`requiereCeremoniaFuerte()`.

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

## 14. Estado actual

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
| `mail.mailer` | N2 | **Ninguna (solo lectura)** | `mail.default` |
| `mail.smtp.host` | N2 | Nueva | `mail.mailers.smtp.host` |
| `mail.smtp.port` | N2 | Nueva | `mail.mailers.smtp.port` |
| `mail.smtp.scheme` | N2 | Nueva | `mail.mailers.smtp.scheme`, defecto `auto` |
| `mail.smtp.username` | N2 | Nueva | `mail.mailers.smtp.username` |
| `mail.smtp.password` | N2 | Nueva (cifrado) | `mail.mailers.smtp.password` |
| `mail.from.address` | N2 | Nueva | `mail.from.address` |
| `mail.from.name` | N2 | Nueva | `mail.from.name` |
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

---

## 15. Confirmación N2

Para cambios de impacto alto que **no** son fiscales. Enseña qué va a cambiar,
explica la consecuencia y exige un segundo clic. No pide frase exacta ni
contraseña: para el puerto del servidor de correo eso no añadiría seguridad,
añadiría gente que escribe su contraseña sin leer.

Piezas: `ConfirmacionN2` (calcula los cambios), `CambioPropuesto` (uno de ellos) y
el componente `<x-configuracion.confirmacion-n2>`.

**Flujo de dos pasos.** El primer envío del formulario no escribe: el controlador
calcula el diff y devuelve la pantalla de confirmación con los valores ya validados
en campos ocultos. Solo el segundo envío, con `confirmacion`, guarda.

**Solo se lista lo que cambia.** Un resumen que repite los ocho campos del
formulario, siete idénticos, es un resumen que nadie lee.

**Los secretos no viajan.** Un `CambioPropuesto` de tipo secreto se construye sin
`antes` ni `despues` y solo aporta la frase «… será reemplazada». Y los valores que
se reenvían en campos ocultos **nunca** incluyen un secreto: por eso exactamente la
contraseña SMTP tiene pantalla propia en vez de pasar por esta confirmación.

**Las claves no vienen del navegador.** El formulario manda nombres humanos
(`servidor`, `puerto`) y un mapa fijo en el controlador los traduce a claves del
registry. Un campo oculto manipulado no puede elegir qué ajuste se escribe ni con
qué nivel se trata.

---

## 16. Ceremonia N3

Para lo fiscal. Cuatro puertas, en este orden, y ninguna se salta:

1. permiso `configuracion.critica`;
2. **precondiciones** de la acción;
3. **frase exacta**;
4. **contraseña actual** del usuario.

El orden importa. El permiso va primero para que quien no lo tiene no pueda
averiguar qué frase pide la acción ni usar el formulario como oráculo de
contraseñas. Las precondiciones van antes que la frase porque no tiene sentido
hacer escribir una frase larga para después decir que faltaba un respaldo.

**La contraseña no se guarda, no se devuelve y no se audita.** Entra por parámetro,
se comprueba con `Auth::guard('web')->validate()` —el mismo mecanismo que la
pantalla de confirmar contraseña de Laravel— y se descarta. No se pasa a la acción,
no viaja en el resultado y `AuditoriaAjustes::accionCritica()` no tiene por dónde
recibirla.

**Límite de intentos**: 5 por usuario y acción en 5 minutos. El formulario acepta
contraseñas, así que es un oráculo; sin límite, una sesión secuestrada podría
probar contraseñas contra este endpoint sin las protecciones del login.

Una acción se declara como `AccionCriticaN3` (clave, título, consecuencia, frase,
precondiciones, callback y aviso persistente opcional) y se ejecuta con
`CeremoniaN3::ejecutar()`.

**NO HAY NINGUNA ACCIÓN N3 CONECTADA TODAVÍA.** La infraestructura está construida y
probada con una acción de prueba; el ambiente fiscal, la firma, la transmisión, las
credenciales del MH, el certificado y los correlativos siguen siendo de solo
lectura. Abrir el primero es una decisión aparte.

El **aviso persistente** se declara en la acción y el resultado lo devuelve. No se
persiste todavía: sin una acción N3 real, la tabla que lo guardara tendría columnas
inventadas a ciegas.

---

## 17. Configuración de correo en tiempo de ejecución

`ConfiguracionCorreoRuntime::aplicar()` vuelca los ajustes de correo sobre la
configuración viva del proceso y llama a `forgetMailers()`.

**El problema.** Laravel lee `config/mail.php` una vez al arrancar y el MailManager
cachea cada mailer que construye. En una petición web da igual —el proceso muere al
terminar—, pero el worker de colas vive horas: construye el transporte con el primer
correo del día y se queda con él. Sin esto, un administrador cambia el servidor SMTP,
la pantalla dice «guardado», y los documentos de la tarde siguen saliendo por el
servidor viejo. Nadie relacionaría una cosa con la otra.

**Dónde se aplica:**

- `JobProcessing` (AppServiceProvider) — antes de **cada** trabajo de la cola. Es lo
  que cubre al worker y también a los trabajos de correo que se escriban después,
  sin tener que acordarse de llamar a nada.
- `EnviarDteCorreo` y `EnviarDocumentoRecibidoContabilidad` — explícito en el job.
- `PaqueteContabilidadController` — el envío del paquete es **inline** (no pasa por
  la cola), así que no lo cubre el listener y lo pide por su cuenta.

**Qué NO hace: tocar `mail.default`.** Qué transporte se usa lo deciden el `.env`,
la segunda barrera de `AppServiceProvider` (fuera de producción, `log`) y
`CandadoCorreoReal`. Una cuarta autoridad sobre ese interruptor es exactamente como
se termina enviando correo real desde una máquina de pruebas. Por eso `mail.mailer`
está registrado como **solo lectura**: se informa, no se cambia.

**`mail.smtp.scheme` y no `encryption`.** Laravel 12 ya no lee `encryption`:
`MailManager::createSmtpTransport()` usa `scheme` (`smtp` = STARTTLS, `smtps` = TLS
implícito) y, si no hay ninguno, lo deriva del puerto (465 → `smtps`). Registrar un
ajuste llamado `encryption` habría creado una clave que no cambia nada y que parece
que sí. El valor `auto` de la lista no es un valor del mailer: es la ausencia de
valor, y el runtime la traduce a «no fijar scheme».

---

## 18. Verificaciones de configuración

Tabla `verificaciones_configuracion` + `RegistroVerificaciones`. Guarda «¿esto
funciona de verdad?»: fecha, resultado y un mensaje **ya saneado**.

**Por qué una tabla aparte y no columnas en `ajustes_sistema`.** Son dos cosas con
vidas distintas: una guarda lo que una persona decidió (se edita, una fila por
clave), la otra lo que el sistema observó (se añade, muchas filas por clave, y el
historial es lo útil). Mezclarlas movería el `updated_at` del ajuste cada vez que se
prueba una conexión —rompiendo la comprobación optimista de concurrencia— y añadiría
columnas vacías para casi todas las claves.

`clave` es el nombre del **servicio** (`smtp`, y mañana `hacienda`, `firmador`,
`gmail`, `imap`), no una clave del registry: una comprobación valida un conjunto de
ajustes a la vez.

Se conservan las **20 últimas por servicio**. Un botón «Probar conexión» pulsado con
insistencia no puede hacer crecer la tabla para siempre.

En pantalla se muestra el texto relativo («hace 2 horas»), que es lo que se lee, y el
timestamp exacto queda en el `title`, que es lo que se compara.

### Prueba de conexión SMTP

`PruebaConexionSmtp` llama a `SmtpTransport::start()`: conecta, saluda, negocia el
cifrado y **autentica**. Ahí termina — el mensaje solo viaja en `send()`, que no se
llama nunca. Es la comprobación completa de servidor + puerto + seguridad + usuario
+ contraseña **sin enviar ningún correo**, y por eso no existe la alternativa de
«mandarse un correo de prueba»: una dirección de prueba inventada es justo la clase
de cosa que un día acaba en la bandeja de un cliente.

El mensaje de error se sanea antes de mostrarse o guardarse: se tacha la contraseña
actual si aparece, se corta en la primera línea (Symfony añade el diálogo completo
con el servidor) y se acota. Nunca la excepción entera.

---

## 19. Pantallas

```
Configuración
├── Resumen                    ← estado de todo el sistema (solo lectura)
├── General
│   ├── Empresa emisora
│   ├── Establecimientos
│   └── Puntos de venta
├── Facturación electrónica
│   └── Correlativos
└── Correo
    ├── Correo y servidor      ← SMTP + documentos fiscales + contabilidad
    └── Contabilidad
```

El índice pasó de pestañas horizontales a **columna agrupada**: con las secciones
que vienen, una barra horizontal solo podía crecer desplazándose de lado y
escondiendo justo lo que el usuario no sabe que existe. En móvil el mismo marcado se
envuelve en pastillas; en ninguna de las dos formas hay desplazamiento horizontal.

Los grupos que todavía no tienen pantallas reales (Integraciones, Módulos, Sistema)
**no se dibujan**. Un grupo vacío o un enlace a una página inexistente enseña al
usuario a desconfiar del resto del índice.

### Resumen

Once tarjetas: ambiente fiscal, modo DTE, firmador, API Hacienda, SMTP, Gmail, IMAP,
respaldos, cola, Planta y Rutas/Cobros. Tres reglas lo gobiernan:

1. **Nunca hay red.** Ni un ping al firmador ni un login a Hacienda. Una pantalla de
   estado que se cuelga esperando a un servicio externo deja de ser una pantalla de
   estado.
2. **Nunca hay secretos.** De una credencial solo se dice si está y de dónde sale.
3. **Nunca se inventa un estado.** Si el sistema no puede saber si algo funciona, la
   tarjeta dice qué está configurado y deja claro que eso no es lo mismo. Un verde
   falso aquí es peor que no tener la pantalla.

### Contraseña SMTP

Pantalla propia (`/configuracion/correo/smtp/password`). El campo nunca se precarga
—ni con el valor ni con un relleno que insinúe su longitud—, usa
`autocomplete="new-password"` y la vista recibe un `EstadoAjuste`, que para un
secreto se construye con el valor en `null`.

Un envío en blanco **no borra nada**: la validación lo rechaza. Volver al `.env` es
una acción separada («quitar override») que se pide a propósito y también confirma.
