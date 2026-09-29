# Cierre conjunto — Notas de crédito + Cobros/PPQ

> **Documento histórico del 18/09/2026.** La entrega continuó después de esa fecha. Para el estado final del pase, ver [`RESULTADO_DESPLIEGUE_COBROS_PPQ_NC_20260924.md`](RESULTADO_DESPLIEGUE_COBROS_PPQ_NC_20260924.md). En la base local de desarrollo, `migrate:status` del 24/09/2026 no mostró migraciones pendientes; las afirmaciones de abajo sobre migraciones pendientes en origen y destino describen el estado anterior al despliegue.

Qué lleva esta entrega, qué hay que migrar, qué hay que configurar y **en qué orden**
comprobarlo y encenderlo en la instalación de destino.

Estado en origen al momento de escribir esto: **suite completa en verde — 5.747 pruebas,
23.180 aserciones, 0 fallos, 0 errores** (48 min, pico 904 MB, 2026-09-18). Nada se editó
durante la corrida.

> **Todo lo que este documento dice del DESTINO es «por verificar».** Está escrito desde el
> entorno de origen, sin acceso a la instalación de destino: ni a su base, ni a su `.env`,
> ni a su historial de migraciones. Los apartados §2 y §3 son **hipótesis que hay que
> comprobar allá antes de actuar**, no inventario confirmado.

---

## 0 · Alcance

Esta entrega es **Notas de crédito + Cobros/PPQ**. El árbol de trabajo contiene además un
tercer cuerpo de trabajo anterior —**Gastos, Planilla y el selector de áreas**— que **NO
forma parte de ella**.

Cuatro archivos llevan cambios de las dos cosas mezclados, así que separarlos es un paso
previo con su propio procedimiento: **[`ENTREGA_CALLEJA_ALCANCE.md`](ENTREGA_CALLEJA_ALCANCE.md)**.

Todo lo que sigue asume que la entrega ya viene recortada a Calleja. Si se despliega el
árbol entero sin recortar, se despliegan los tres cuerpos de trabajo.

---

## 1 · Cambios incluidos

### B · Notas de crédito

*(Trabajo de la otra sesión; se describe por lo que consta en el diff, no por haberlo
hecho.)* Toca el formulario y el flujo de NC: `ModalidadNotaCredito`,
`BusquedaCcfParaNotaCredito`, `AlbaranNotaCreditoService`, `DteController`,
`ClientePerfilDocumento::reglaOperativaPara()` y su uso desde `PerfilDocumentoResolver`, más
las vistas `create-nota-credito`, `edit-nc`, `resumen-nc`, `albaran-nc` y
`reversion-nota-credito`. Prueba nueva: `NcDevolucionFaltanteAlbaranTest`.

**Punto de contacto con C:** `PerfilDocumentoResolver` y `ClientePerfilDocumento`. Cobros
saca de ahí el código de proveedor con el que nombra los archivos de solicitud. Las 223
pruebas de NC y las 406 de Cobros/PPQ pasan con los dos cambios conviviendo.

### C · Cobros Calleja / PPQ

**Corrección con impacto en dinero**

- `ConciliacionTxtParser`: `-.96` se leía como **−96**. Sobre el archivo real del 07/09/2026
  inflaba el total de NC de −$125.90 a −$220.94 y descuadraba el neto en **$95.04**. Los
  importes pasan a ser cadenas decimales exactas.

**Formatos nuevos del portal de Calleja** (los anteriores se conservan)

- `ExportadorNcCargaMasivaV1` — 8 columnas, notas de crédito.
- `ExportadorSolicitudCargaMasivaV1` — 5 columnas, solicitudes/quedan.
- Los lotes viejos se siguen descargando **con su formato original**: el slug queda
  congelado en el lote.

**Módulo nuevo: seguimiento de factura a pago** (`/cobros`)

- `cobro_documentos` como sujeto del seguimiento, con dos fuentes (DTE aceptado e
  incorporación manual) y anti-duplicado por número de control normalizado.
- Presentación y pago como **ejes independientes**; observaciones que no se pierden al
  mover ninguno.
- Vinculación de albaranes **auditada**: solo coincidencia única por OC o vínculo explícito;
  importe, fecha y correlativo nunca crean un vínculo, solo lo impiden.
- Solicitudes con presentación registrada a mano, acuse del cliente y reenvío sin duplicar
  deuda.
- Aplicación del TXT: idempotente, con pagos en revisión cuando dos archivos distintos
  informan el mismo cobro, y ajustes QD que **no se reparten** entre facturas.
- Lectura del buzón: consulta sin `subject:`, cuerpo HTML con tablas preservadas, barrido
  que avanza entre corridas y confirma **solo sobre lo registrado**.

**Pruebas nuevas:** 99 en `tests/Feature/Cobros/` + 19 del parser + 17 del formato de NC.
Fixture real del TXT en `tests/Fixtures/Ppq/`.

**Documentación:** `docs/COBROS_CALLEJA.md`.

---

## 2 · Migraciones — POR VERIFICAR en destino

### De esta entrega

**Una sola**, la de Cobros. Notas de crédito **no trae ninguna**.

```
2026_09_17_180000_create_cobros_calleja_tables
```

Crea **siete** tablas y nada más:

```
cobro_solicitudes      cobro_documentos       cobro_solicitud_items
cobro_eventos          cobro_ajustes          cobro_correos
cobro_correo_progresos
```

Revisado su contenido: **no contiene ni un `Schema::table()` ni un `dropColumn`**. No altera
ninguna tabla existente y no migra datos. Su `down()` borra solo esas siete.

### Estado en destino: desconocido

En el entorno de origen (`dulces_negrita_dev`) figura como **pendiente**. En destino **no se
ha comprobado** y no se puede comprobar desde acá. El primer paso del §4 es precisamente
mirarlo allá.

### Si el alcance acordado incluyera además Gastos/Planilla

No es el caso de esta entrega, pero conviene dejarlo dicho por si esa decisión cambia: son
**siete** migraciones más, y **no todas se limitan a crear tablas**.

| Migración | Crea | Altera |
|---|---|---|
| `2026_09_06_120000_create_gastos_core_tables` | 6 | — |
| `2026_09_08_150000_create_gastos_ajustes_y_fuentes` | 2 | `gastos`, `gastos_eventos` |
| `2026_09_09_120000_create_gastos_recurrencias_y_avisos` | 6 | `gastos_eventos` |
| `2026_09_11_100000_create_planilla_tables` | 4 | — |
| `2026_09_12_100000_create_planilla_pagos_y_anticipos` | 6 | `planilla_empleados` |
| `2026_09_13_100000_create_planilla_sueldos_y_periodos` | 1 | `planilla_conceptos`, `planilla_detalles` |
| `2026_09_15_160000_agregar_certeza_a_gastos` | **0** | `gastos`, `gastos_eventos` |

Las alteraciones recaen **solo sobre tablas del propio módulo** —ninguna toca `dtes`,
`clientes` ni nada del área fiscal—, pero la última no crea nada: es únicamente un `ALTER`,
y eso cambia cómo hay que planificar su vuelta atrás. Revisarlas una por una antes de
incluirlas, no darlas por equivalentes a la de Cobros.

---

## 3 · Configuración — POR VERIFICAR en destino

Lo que sigue es lo que el código **lee**. Qué tiene puesto hoy el `.env` de destino, y si
alguna de estas claves ya existe allá con otro valor, **no se ha comprobado**: hay que
mirarlo antes de escribir nada.

Todas nacen **apagadas**, y ninguna es obligatoria para que el sistema siga funcionando como
hoy: si no están, el módulo queda inerte y el resto no cambia.

| Clave | Para qué | Valor de arranque |
|---|---|---|
| `COBROS_DIAS_REVISION_HISTORICA` | Antigüedad desde la que un documento sin antecedentes se marca para revisión | `30` |
| `COBROS_SOLICITUDES_STORAGE_DIR` | Copia del archivo presentado (disco privado) | `cobros/solicitudes` |
| `COBROS_ALTA_AUTO` | El alta horaria corre sola | `false` |
| `COBROS_CORREO_ENABLED` | El módulo puede leer el buzón cuando se lo piden | `false` |
| `COBROS_CORREO_AUTO` | Y lo hace sin que se lo pidan, cada media hora | `false` |
| `COBROS_CORREO_QUERY` | Consulta de Gmail — **sin `subject:`** | ver `.env.example` |
| `COBROS_CORREO_LIMITE` | Mensajes por corrida | `50` |

Para la lectura de correo hace falta además la conexión de Gmail que ya usa PPQ
(`PPQ_GMAIL_ENABLED` y cuenta conectada). Son **tres llaves** y cada una responde algo
distinto: si el sistema *puede* hablar con Gmail, si este módulo *puede* leer el buzón, y si
lo hace *solo*.

**Configuración de datos, no de `.env`:** para que Calleja use el formato nuevo de NC hay que
poner su perfil documental en `carga_masiva_nc_v1`. En `dulces_negrita_dev` ya está hecho
(cliente id 10, NIT `0614-555555-101-5`).

En destino **no se ha comprobado** qué formato tiene configurado, ni con qué id existe el
cliente, ni si tiene perfil documental activo. Hay que mirarlo allá:

```
php artisan perfil-documento:cliente <id>     # sin más opciones: solo MUESTRA
```

Identificar al cliente **por NIT, no por nombre**: en la base de origen hay dos homónimos
borrados en blando, y no hay motivo para suponer que en destino no pase lo mismo.

---

## 3 bis · Información que hace falta del destino

Nada de lo que sigue se puede averiguar desde desarrollo. **Hasta tenerlo, el paso 2 no se
puede dar por válido.** Son órdenes de **solo lectura** y **ninguna muestra contraseñas**.

Ejecutar en el servidor, en la carpeta del proyecto, y traer la salida:

```cmd
rem 1 · En qué commit está, y si tiene cambios locales sin commitear
git rev-parse --short HEAD
git status --short
git log -1 --date=short --format="%h %ad %s"

rem 2 · Qué migraciones tiene aplicadas (y cuáles le quedarían pendientes)
php artisan migrate:status

rem 3 · Versiones y drivers. `about` NO imprime contraseñas.
php artisan about --only=environment,drivers
php -v
composer -V
node -v && npm -v

rem 4 · Qué claves existen en su .env — SOLO LOS NOMBRES, nunca los valores
powershell -Command "Get-Content .env | Select-String '^[A-Z][A-Z0-9_]*=' | ForEach-Object { ($_ -split '=')[0] }"

rem 5 · Si están las piezas que no vienen en git
dir vendor\autoload.php
dir public\build\manifest.json
dir public\storage

rem 6 · Último respaldo correcto
php artisan tinker --execute="echo App\Models\RespaldoEjecucion::where('exitoso',true)->latest('id')->value('created_at');"

rem 7 · Espacio libre en disco
powershell -Command "Get-PSDrive C | Select-Object Used,Free"
```

Y un dato de negocio, también de solo lectura:

```cmd
rem 8 · El cliente Calleja en destino: su id y qué formato tiene hoy
php artisan tinker --execute="foreach(App\Models\Cliente::where('num_documento','0614-555555-101-5')->withTrashed()->get(['id','nombre','num_documento','deleted_at']) as $c){ echo $c->id.' | '.$c->nombre.' | borrado: '.($c->deleted_at ?? 'no').PHP_EOL; }"
php artisan perfil-documento:cliente <id>
```

### Para qué sirve cada cosa

| Dato | Decide |
|---|---|
| Commit + `git status` | Si los tres archivos compartidos se aplican tal cual o hay que fusionarlos |
| `migrate:status` | Qué queda pendiente **de verdad** allá, que puede no ser lo de §2 |
| Claves del `.env` | Cuáles de §3 hay que añadir y cuáles ya existen con otro valor |
| `vendor` / `build` / `storage` | Cuánto del paso 2 bis hay que ejecutar |
| Último respaldo correcto | Si se puede empezar |
| Id y perfil de Calleja | El paso 7, sin confundirse de cliente |

---

## 4 · Orden de comprobación y activación en destino

Cada paso comprueba antes de encender. No saltarse el orden: los pasos 2, 4 y 6 son
precisamente los que evitan encender algo sobre datos que nadie miró.

**0 · Respaldo, y comprobar que el respaldo existe**

No es un paso de trámite: es el único que permite deshacer todo lo demás.

```cmd
php artisan backup:mysql-diario --origen=manual
copy .env .env.backup-antes-calleja-%date:~-4%%date:~3,2%%date:~0,2%
```

**Comprobar que el respaldo quedó registrado como exitoso antes de seguir.** Si falló, no
continuar: sin respaldo no hay vuelta atrás de la migración.

```cmd
php artisan tinker --execute="$r=App\Models\RespaldoEjecucion::latest('id')->first(); echo $r->created_at.' exitoso='.var_export($r->exitoso,true);"
```

Anotar también el commit actual, que es el punto de retorno del código:

```cmd
git rev-parse HEAD > punto-de-retorno.txt
```

**1 · Levantar el estado real de destino**

```
php artisan migrate:status        # QUÉ hay pendiente ALLÁ, de verdad
```

Y mirar el `.env` de destino para las claves del §3. Respaldo de la base **antes** de
cualquier cosa.

De acá sale la lista real de pendientes, que puede no coincidir con la de §2: el destino
puede tener migraciones que en origen no están, o al revés. **La lista que manda es la de
destino.**

**2 · Migrar — solo lo acordado, y solo lo que corresponda**

No hay un `php artisan migrate` a secas en este procedimiento, y no es por prudencia
decorativa: `migrate` aplica **todo** lo pendiente en destino, incluido lo que no forma parte
de esta entrega. Si el árbol desplegado quedó bien recortado (§0), lo pendiente de esta
entrega debería ser una sola migración; si aparece alguna más, **parar y averiguar qué es
antes de aplicarla**.

Con el alcance confirmado y la lista de destino delante:

```
# Aplicar EXACTAMENTE la de esta entrega:
php artisan migrate --path=database/migrations/2026_09_17_180000_create_cobros_calleja_tables.php

php artisan config:clear
```

Comprobar después:

```
php artisan migrate:status | grep cobros_calleja      # debe decir «Ran»
```

Esa migración **solo crea siete tablas nuevas** (§2): no altera ninguna existente y no toca
un solo dato. Esa afirmación vale para ella y **no se extiende a ninguna otra**; cualquier
migración adicional que aparezca pendiente hay que leerla antes de aplicarla.

**2 bis · Requisitos de instalación que NO vienen en el repositorio**

Tres cosas que el control de versiones no trae y sin las cuales el sistema arranca roto.
Las tres se descubrieron montando la copia de prueba, y cada una tiene un síntoma
característico:

| Falta | Síntoma | Se resuelve con |
|---|---|---|
| `vendor/` | `Class not found` de todo | `composer install --no-dev --optimize-autoloader` |
| `public/build/` (assets compilados) | **500 en toda página que se renderice**, `ViteManifestNotFoundException` | `npm ci && npm run build` |
| Enlace `public/storage` | Salud del sistema en **crítico** (`storage_link`) | `php artisan storage:link` |

`public/build` está en `.gitignore` —es salida de compilación, no fuente—, así que **no
viaja con el código**. Si el despliegue se hace copiando el repositorio, hay que compilar en
destino o llevar esa carpeta aparte.

> Estas tres no son de esta entrega: son de cualquier instalación. Se anotan acá porque el
> procedimiento anterior no las mencionaba y son el primer motivo por el que un despliegue
> nuevo «no funciona».

**3 · Comprobar que nada se rompió**

Entrar a Facturación, emitir nada, solo mirar: el listado de DTE, el formulario de NC y
`/ppq`. El método anterior tiene que seguir funcionando **antes** de configurar lo nuevo.

**4 · Cobros, en seco**

```
php artisan cobros:sincronizar            # ENSAYO: no escribe
```
Revisar cuántos documentos entrarían y cuántos irían a revisión histórica. Si el número de
«revisión histórica» es casi todo, es lo esperado: son facturas anteriores al seguimiento.

**5 · Cobros, de verdad**

```
COBROS_ALTA_AUTO=true
php artisan config:clear
php artisan cobros:sincronizar --aplicar
```
Entrar a `/cobros` y comprobar la bandeja. El botón «Traer documentos aceptados» queda como
recuperación.

**6 · Vinculación de albaranes, en seco**

`/cobros` → «Auditar vinculación de albaranes». **No aplicar** hasta ver cuántos salen
únicos y cuántos ambiguos sobre los datos reales. Aplicar solo si el reparto es razonable;
los ambiguos quedan en «Revisar vinculación» y se resuelven a mano.

**7 · Formato nuevo de NC**

Ficha del cliente → Perfil documental → «Carga masiva de notas de crédito (portal de
Calleja)». O:

```
php artisan perfil-documento:cliente <id> --formato=carga_masiva_nc_v1
```
**Afecta solo a los lotes futuros.** Los anteriores se siguen descargando con su formato.

**8 · Primera solicitud de verdad**

Generar una solicitud con pocas facturas, subirla al portal y **comprobar que Calleja la
acepta** antes de confiar en el formato. Es lo único que todavía no está validado (§5).

**9 · Archivo de pagos**

Cargar un TXT real en `/cobros` → «Aplicar pagos». Capturar la fecha del pago aparte: el
archivo trae la del documento. Revisar el informe: aplicados, en revisión, no identificados
y conservados.

**10 · Correo, en seco**

```
php artisan cobros:leer-correos           # ENSAYO: no escribe ni el avance del barrido
```
Comprobar que los acuses reales se interpretan: archivo, referencia y fecha programada.

**11 · Correo, de verdad**

```
COBROS_CORREO_ENABLED=true
php artisan config:clear
php artisan cobros:leer-correos --aplicar
```

**12 · Automáticas, al final**

```
COBROS_CORREO_AUTO=true
php artisan config:clear
```
Y algo que ejecute `schedule:run` cada minuto. Con una sola de las dos cosas, no corre.
Comprobar en `storage/logs/cobros-alta.log` y `cobros-correos.log`.

**Para apagar cualquiera de las dos:** su llave a `false` y `config:clear`. Lo ya importado
no se toca.

---

## 4 bis · Recuperación: cómo se deshace

Ordenado de menos a más drástico. **Elegir el más pequeño que resuelva el problema.**

### a) Apagar sin desinstalar — resuelve casi todo

Lo nuevo nace apagado y se puede volver a apagar. Es reversible al instante y **no toca un
solo dato**:

```cmd
rem En .env:
COBROS_ALTA_AUTO=false
COBROS_CORREO_ENABLED=false
COBROS_CORREO_AUTO=false
php artisan config:clear
```

Y devolver el perfil de Calleja a su formato anterior, si se cambió:

```cmd
php artisan perfil-documento:cliente <id> --formato=albaran_nc_v1
```

Con esto, el circuito vuelve a comportarse como antes. **Los lotes ya generados conservan su
formato**, así que nada de lo entregado al cliente cambia.

### b) Volver el código atrás, conservando los datos

```cmd
git reset --hard <commit de punto-de-retorno.txt>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan config:clear && php artisan view:clear
```

Las siete tablas `cobro_*` quedan en la base sin que nadie las use. **No estorban y no se
borran**: si se decide reintentar, los datos que hubiera siguen ahí.

> `git reset --hard` **descarta cambios locales del servidor**. Por eso el paso 1 comprueba
> antes que no los haya. Si los hubiera, guardarlos primero.

### c) Deshacer la migración

Solo si hay que dejar la base exactamente como estaba:

```cmd
php artisan migrate:rollback --step=1
```

Borra las siete tablas `cobro_*` **y todo lo que contengan**. No toca ninguna otra tabla:
la migración no altera nada existente (§2). Irreversible salvo por el respaldo.

### d) Restaurar el respaldo completo

El último recurso, y el único que revierte datos de otros módulos. Ver
[`RESTORE_BACKUP_WINDOWS.md`](RESTORE_BACKUP_WINDOWS.md).

### Lo que la recuperación NO puede deshacer

- **Un archivo ya subido al portal de Calleja.** Descargarlo o generarlo de nuevo no lo
  retira de allá: eso se resuelve hablando con el cliente, no con el sistema.
- **Un correo leído** ya quedó registrado. No es un problema —la lectura no modifica el
  buzón— pero volver a instalar no lo «desmarca».

Por eso los pasos 8 y 11 —la primera solicitud real y la primera lectura de correo con
`--aplicar`— van al final y con poco volumen: son los dos únicos que salen del sistema.

---

## 5 · Lo que sigue SIN validar

Nada de esto se ha comprobado contra el mundo real. No darlo por bueno hasta la primera
pasada, y no encender lo automático mientras siga abierto.

### Contra el PORTAL de Calleja — pendiente (paso 8)

1. **El archivo de solicitud nunca se subió.** Sala, número y tipo van como texto (para no
   perder el `0017`) y año y mes como número; la plantilla del cliente **no declara formato
   en ninguna columna**, así que esto es la lectura más fiel, no un dato confirmado.
   Tampoco se replica el objeto «Tabla3» de Excel.
2. **El «# ALBARAN» va como número suelto** (`5131`), no canónico, porque sala y tipo tienen
   columna propia. Es una lectura de la plantilla, no algo que el portal haya confirmado.
3. **El formato de NC de 8 columnas tampoco se ha cargado nunca** en el portal. Mismos dos
   puntos anteriores: formato de columnas y ausencia de la tabla de Excel.

> Cómo cerrarlo: una solicitud con **pocas facturas**, subida al portal, y confirmar que
> Calleja la acepta. Hasta entonces, no generar tandas grandes con este formato.

### Contra el BUZÓN — pendiente (paso 10)

4. **La consulta a Gmail no se ha ejecutado nunca contra la cuenta real.** Lo que sí está
   probado: el intérprete, contra los correos reales que describió operación; y el barrido,
   contra un buzón de mentira. Lo que falta es la consulta de verdad.
5. **No se sabe cuántos mensajes devuelve la consulta en el buzón real**, y de eso depende
   cuántas corridas tarda el barrido en llegar al fondo. El primer ensayo en seco lo dirá.
6. **La conversión del HTML no se ha probado contra un correo real de Calleja**, solo contra
   una tabla construida a mano con la misma forma.

> Cómo cerrarlo: `cobros:leer-correos` **en seco** contra la cuenta, y comprobar en la salida
> que el archivo, la referencia y la fecha programada salen bien de un acuse de verdad.

### Sin respaldo documental

7. **`AC06`** no tiene significado asignado en ninguna parte del código, y así se queda
   hasta que exista respaldo del cliente. No suponerlo.

---

## 6 · Lo que se conserva del método anterior

- **Lotes PPQ**, su Excel de cobro y su conciliación: intactos.
- **Formato de NC de 17 columnas**: sigue existiendo y los lotes viejos se regeneran con él.
- **Incorporación manual** de documentos de contabilidad o correo: sigue siendo una fuente
  de primera clase, y cuando el mismo documento aparece emitido, el alta lo **adopta** en vez
  de duplicarlo.
- **Rutas** sigue mostrando documentos. Cobros no le quita nada: responde otra pregunta.
- **Botones manuales** de alta y de vinculación: son la recuperación cuando la automática
  está apagada o el planificador se cayó.

---

## 7 · Nota de mantenimiento

El pico de memoria de la suite va en **904 MB** contra el `memory_limit = 1G` de
`tests/bootstrap.php`: queda ~10% de margen, y viene subiendo con cada módulo (754 → 824 →
870 → 888 → 902 → 904 MB). Conviene subir el límite **antes** de que reviente: cuando pase
de 1 G la suite muere a mitad y el síntoma no se parece a un problema de memoria. No se tocó
en esta entrega porque no forma parte de ella.
