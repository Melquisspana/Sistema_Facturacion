# Entrega delimitada a Calleja — alcance y separación

Qué entra en la entrega de **Notas de crédito + Cobros/PPQ**, qué NO entra, y cómo separar
lo uno de lo otro **sin perder una línea del trabajo local**.

Este documento **no ejecuta nada**. Es el plan; la puesta en marcha va en
[`CIERRE_NC_COBROS.md`](CIERRE_NC_COBROS.md).

---

## 1 · El problema

El árbol de trabajo tiene tres cuerpos de trabajo sin commitear a la vez:

| | Cuerpo | Qué es |
|---|---|---|
| **A** | Gastos, Planilla y selector de áreas | Trabajo anterior, ya en el árbol antes de esta entrega |
| **B** | Notas de crédito | Esta entrega |
| **C** | Cobros Calleja / PPQ | Esta entrega |

**A no entra**, y no entra por omisión: cuatro archivos llevan cambios de A y de B/C
mezclados en el mismo fichero. Hay que separarlos a mano, hunk por hunk. Si se commitea «lo
que está modificado», A se va con ellos.

---

## 2 · Qué entra (B + C)

### Archivos nuevos — se agregan enteros

```
app/Console/Commands/CobrosLeerCorreosCommand.php
app/Console/Commands/CobrosSincronizarCommand.php
app/Enums/Cobros/                       (6 enums)
app/Http/Controllers/Cobros/            (CobrosController)
app/Models/Cobros/                      (7 modelos)
app/Services/Cobros/                    (7 servicios + Exportadores/)
app/Services/Ppq/Exportadores/ExportadorNcCargaMasivaV1.php
app/Support/Correo/CuerpoHtml.php
app/Support/NumeroControl.php
config/cobros.php
database/migrations/2026_09_17_180000_create_cobros_calleja_tables.php
resources/views/cobros/                 (index, show, vinculacion, pagos)
tests/Feature/Cobros/                   (99 pruebas)
tests/Feature/Dte/NcDevolucionFaltanteAlbaranTest.php
tests/Feature/Ppq/ConciliacionTxtImportesTest.php
tests/Feature/Ppq/NcExportacionCargaMasivaTest.php
tests/Fixtures/Ppq/pagos-000123-20260907.txt
docs/COBROS_CALLEJA.md
docs/CIERRE_NC_COBROS.md
docs/ENTREGA_CALLEJA_ALCANCE.md          (este archivo)
```

### Archivos modificados — solo de B/C, se agregan enteros

```
app/Console/Commands/PerfilDocumentoClienteCommand.php
app/Enums/ModalidadNotaCredito.php
app/Http/Controllers/Clientes/ClientePerfilDocumentoController.php
app/Http/Controllers/Facturacion/DteController.php
app/Http/Controllers/Ppq/NcExportacionController.php
app/Models/ClientePerfilDocumento.php
app/Services/Dte/AlbaranNotaCreditoService.php
app/Services/Dte/BusquedaCcfParaNotaCredito.php
app/Services/Dte/PerfilDocumentoResolver.php
app/Services/Ppq/ConciliacionTxtParser.php
app/Services/Ppq/Exportadores/ExportadorNc.php
app/Services/Ppq/Exportadores/ExportadorNcAlbaranV1.php
app/Services/Ppq/Exportadores/ExportadorNcFactory.php
app/Services/Ppq/GmailClient.php
app/Services/Ppq/NcExportacionService.php
resources/js/ccf-editor.js
resources/views/clientes/perfil-documento.blade.php
resources/views/clientes/show.blade.php
resources/views/components/dte/reversion-nota-credito.blade.php
resources/views/facturacion/create-nota-credito.blade.php
resources/views/facturacion/edit-nc.blade.php
resources/views/facturacion/index.blade.php
resources/views/facturacion/partials/albaran-nc.blade.php
resources/views/facturacion/partials/resumen-nc.blade.php
resources/views/ppq/nc-exportaciones/index.blade.php
```

---

## 3 · Qué NO entra (A)

Estos archivos **no se tocan**: se quedan modificados en el árbol, tal como están.

```
app/Enums/AreaSistema.php
app/Http/Middleware/RedirigirAreaPrincipal.php
app/Providers/AppServiceProvider.php
app/Console/Commands/GastosAvisosCommand.php          (nuevo)
app/Console/Commands/GastosGenerarRecurrentesCommand.php (nuevo)
app/Http/Controllers/Gastos/  ·  app/Http/Controllers/Planilla/
app/Http/Middleware/ModuloGastos*.php  ·  ModuloPlanillaActivo.php
app/Http/Requests/Gastos/  ·  app/Mail/Gastos/
app/Models/Gastos/  ·  app/Models/Planilla/
app/Services/Gastos/  ·  app/Services/Planilla/
config/gastos.php  ·  config/planilla.php
database/migrations/2026_09_06_*  …  2026_09_15_160000_*   (7 migraciones)
database/seeders/GastosDemoSeeder.php
resources/js/gastos-form.js
resources/views/components/gastos-*.blade.php
resources/views/components/area-selector*.blade.php  ·  sidebar-icon.blade.php
resources/views/emails/gastos-resumen.blade.php
resources/views/gastos/  ·  resources/views/planilla/
resources/views/layouts/navigation.blade.php
resources/views/layouts/partials/sidebar-facturacion.blade.php
resources/views/layouts/partials/sidebar-gastos.blade.php
resources/css/app.css  ·  resources/js/app.js
routes/gastos.php  ·  routes/planilla.php
bootstrap/app.php  ·  phpunit.xml
tests/Feature/Gastos/  ·  tests/Feature/Planilla/  ·  tests/Feature/NavigationTest.php
docs/DISENO-GASTOS-Y-PAGOS.md  ·  docs/diagramas/  ·  docs/evidencias/
AGENTS.md  ·  .gitignore
```

**Fuera también, y por instrucción expresa:**

```
firmware/asistencia/asistencia.ino
```

`AGENTS.md` lo dice: son modificaciones locales ajenas y hay que **excluirlas de cualquier
commit**. No forman parte de ninguna de las tres entregas.

---

## 4 · Los cuatro archivos compartidos

Aquí está todo el trabajo real de la separación. En estos cuatro conviven hunks de A y de
B/C, así que hay que elegir por hunk, no por archivo.

### `routes/web.php`

| Entra (B/C) | No entra (A) |
|---|---|
| El bloque `Route::prefix('cobros')` entero, con sus ~20 rutas | `require __DIR__.'/gastos.php';` |
| El `use App\Http\Controllers\Cobros\CobrosController;` | `require __DIR__.'/planilla.php';` |

Son dos líneas sueltas de A, juntas y al final del archivo: fáciles de dejar fuera.

> Si se dejan por error, la aplicación **revienta al arrancar**: `routes/gastos.php` no
> existiría en destino. Es un fallo ruidoso e inmediato, no silencioso.

### `routes/console.php`

| Entra (B/C) | No entra (A) |
|---|---|
| `Schedule::command('cobros:sincronizar --aplicar')` y su bloque de comentario | `Schedule::command('gastos:generar-recurrentes --aplicar')` |
| `Schedule::command('cobros:leer-correos --aplicar')` y el suyo | `Schedule::command('gastos:avisos --aplicar')` |

Cuatro bloques consecutivos al final del archivo, cada uno con su comentario encima. Los de
Gastos van antes que los de Cobros.

> Si se cuelan los de Gastos: `schedule:list` los mostraría y fallarían al ejecutarse
> (comando inexistente). También ruidoso.

### `.env.example`

| Entra (B/C) | No entra (A) |
|---|---|
| `COBROS_DIAS_REVISION_HISTORICA`, `COBROS_SOLICITUDES_STORAGE_DIR`, `COBROS_CORREO_ENABLED`, `COBROS_CORREO_QUERY`, `COBROS_CORREO_LIMITE`, `COBROS_ALTA_AUTO`, `COBROS_CORREO_AUTO`, con sus comentarios | `GASTOS_ENABLED`, `GASTOS_RECURRENCIAS_AUTO`, `GASTOS_AVISOS_AUTO`, `PLANILLA_ENABLED`, con los suyos |

> **Ojo con la prueba `EnvExampleTest`**: exige que toda clave documentada tenga un
> consumidor en `config/*.php`. Si entra `GASTOS_ENABLED` sin `config/gastos.php`, esa
> prueba falla. Es la red que avisa si la separación quedó a medias.

### `app/Enums/PermisoSistema.php`

| Entra (B/C) | No entra (A) |
|---|---|
| **Una línea de comentario** sobre `ppq.revertir-conciliacion` | ~20 líneas: los permisos de Gastos y Planilla y su reparto por rol |

`ppq.revertir-conciliacion` **ya existe en `HEAD`**: no es un permiso nuevo. Lo único de
B/C aquí es un comentario, así que este archivo se puede dejar **entero fuera** sin perder
nada funcional. Es la opción más simple y la recomendada.

---

## 5 · Cómo separarlo sin perder trabajo

La regla: **nada de `git checkout --`, nada de `git stash`, nada de borrar.** Lo de A se
queda donde está —modificado en el árbol— y sigue disponible para su propia entrega.

```bash
# 1. Rama propia. El trabajo de A sigue intacto en el árbol.
git checkout -b entrega-calleja

# 2. Todo lo que es solo de B/C, entero (§2).
git add app/Enums/Cobros app/Http/Controllers/Cobros app/Models/Cobros \
        app/Services/Cobros app/Support/Correo/CuerpoHtml.php app/Support/NumeroControl.php \
        app/Console/Commands/CobrosLeerCorreosCommand.php \
        app/Console/Commands/CobrosSincronizarCommand.php \
        config/cobros.php resources/views/cobros \
        database/migrations/2026_09_17_180000_create_cobros_calleja_tables.php \
        tests/Feature/Cobros tests/Fixtures \
        tests/Feature/Ppq/ConciliacionTxtImportesTest.php \
        tests/Feature/Ppq/NcExportacionCargaMasivaTest.php \
        tests/Feature/Dte/NcDevolucionFaltanteAlbaranTest.php \
        docs/COBROS_CALLEJA.md docs/CIERRE_NC_COBROS.md docs/ENTREGA_CALLEJA_ALCANCE.md
# …y los modificados de la segunda lista de §2.

# 3. Los DOS compartidos que sí llevan algo de B/C, hunk por hunk.
git add -p routes/web.php        # sí al bloque `cobros`; NO a los require de gastos/planilla
git add -p routes/console.php    # sí a las dos tareas `cobros:`; NO a las dos `gastos:`
git add -p .env.example          # sí al bloque COBROS_*; NO a GASTOS_*/PLANILLA_*

# 4. PermisoSistema.php NO se agrega (§4): lo único de B/C es un comentario.

# 5. Comprobar qué quedó dentro y qué fuera, ANTES de commitear.
git diff --cached --stat                       # dentro
git diff --stat                                # fuera: tiene que ser todo A + el firmware
git diff --cached | grep -inE "gastos|planilla"   # debe salir VACÍO
```

El paso 5 es el que cierra la operación: si `git diff --cached` menciona Gastos o Planilla,
la separación no está bien hecha y hay que rehacer ese hunk.

### Y la comprobación al revés, que es la que de verdad falla

Listar a mano lo que entra **deja archivos fuera**. Pasó montando la copia de prueba: se
quedó atrás `ExportadorNcCargaMasivaV1.php` —un archivo nuevo que vive en la carpeta de
PPQ, no en la de Cobros, y que por eso no cayó en la lista— y la entrega no arrancaba.

Así que además de mirar lo que entró, hay que **enumerar todo lo nuevo del árbol y
comprobar que no falta nada**:

```bash
git status --porcelain | grep '^??' | sed 's/^?? //' | while read -r p; do
  if [ -d "$p" ]; then find "$p" -type f; else echo "$p"; fi
done | grep -viE 'gastos|planilla|docs/diagramas|docs/evidencias|AGENTS.md|^evidencias/'
```

Cada línea de esa salida tiene que estar dentro de la entrega o tener un motivo escrito
para quedarse fuera. Un archivo nuevo olvidado no da error al hacer `git add`: da
`Class not found` la primera vez que alguien usa la función, en destino.

### Comprobación funcional del recorte

Con el índice ya armado, y **antes** de commitear:

```bash
php artisan route:list --name=cobros      # 13 rutas
php artisan schedule:list                 # cobros:* sí; gastos:* según lo que quede fuera
vendor/bin/phpunit tests/Feature/Cobros tests/Feature/Ppq tests/Feature/Dte
```

> La suite **completa** de este árbol incluye Gastos y Planilla, así que su verde no
> demuestra que el recorte funcione por sí solo. Lo que lo demuestra es **montar un árbol
> aparte con solo B/C y correr la suite allí** — que es lo que se hizo (§8).

---

## 8 · Cómo se verificó, y tres trampas del montaje

El recorte se montó y se probó en `C:\laragon\www\Facturacion-calleja`: `git archive HEAD`
como base limpia, encima los archivos de B/C, y los tres compartidos recortados a mano.

Tres cosas salieron mal en el montaje y conviene no repetirlas:

**1 · `vendor` enlazado con un junction arruina el aislamiento.** El autoloader de Composer
calcula las rutas desde su propia ubicación, así que a través del enlace resolvía al
`vendor` original y Laravel deducía `base_path()` = **el árbol de siempre**. La «copia»
estaba ejecutando el código original con Gastos incluido, y la suite daba un verde que no
significaba nada. Hay que **copiar `vendor` de verdad** (~47.000 archivos) o hacer
`composer install` en la copia. Se comprueba con:

```bash
php artisan tinker --execute='echo base_path();'   # debe ser la COPIA
```

**2 · Faltaba un archivo nuevo** (`ExportadorNcCargaMasivaV1.php`, §2). Ver la comprobación
al revés de §5.

**3 · Falta lo que no está en git.** `public/build` está en `.gitignore`, así que
`git archive` no lo trae y **toda página daba 500**; y el enlace `public/storage` no existe
hasta que alguien lo crea. Son requisitos de cualquier instalación, y están anotados en
[`CIERRE_NC_COBROS.md` §4 paso 2 bis](CIERRE_NC_COBROS.md).

> Las dos primeras dan falsos verdes o falsos rojos sobre la entrega. La tercera da 1.022
> fallos de golpe, que asusta pero se arregla en un comando.

---

## 6 · Dependencias reales entre B/C y A

Revisado archivo por archivo: **ninguna**. Ni el código de Cobros ni el de Notas de crédito
importan, llaman ni consultan nada de `App\Models\Gastos`, `App\Models\Planilla`,
`App\Services\Gastos`, `App\Services\Planilla` ni sus configuraciones.

Lo único que comparten es el **fichero**, no el código: cuatro archivos donde las dos
entregas escribieron en sitios distintos. Por eso la separación es mecánica y no obliga a
tocar ninguna lógica.

Las dos únicas dependencias de B/C hacia fuera de sí mismas son internas del área fiscal, y
ya estaban:

- `PerfilDocumentoResolver` / `ClientePerfilDocumento` — los tocan **B y C a la vez**; por
  eso van juntos en una sola entrega y no por separado.
- `GmailClient` y `ConciliacionTxtParser` de PPQ — C los extiende; PPQ ya existe en `HEAD`.

---

## 7 · Qué queda pendiente después de separar

- El recorte **no se ha ejecutado**: este documento es el plan.
- La suite sobre un árbol que contenga **solo** B/C no se ha corrido (ver §5).
- Las comprobaciones contra el portal y contra el buzón siguen pendientes; están en
  [`CIERRE_NC_COBROS.md` §5](CIERRE_NC_COBROS.md).
- Qué se hace con **A** —si va después, en su propia entrega, o se queda local— es una
  decisión que este documento no toma.
