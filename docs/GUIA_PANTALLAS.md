# Guía de pantallas

**Estado:** aprobada por Melqui el 03/11/2026, con las tres decisiones de la sección 4. Revisión hecha el 03/11/2026 sobre `master` en `8e3a908`.

Para Melqui el sistema es una sola aplicación, pero cada módulo se hizo en otro momento y quedó a su manera. Esta guía fija **una sola forma** para cada cosa que se repite en las pantallas. Cada forma se tomó de la mejor versión que **ya existe** en el sistema; no se inventó nada de cero.

Reglas para aplicarla:

- Se aplica módulo por módulo, en PR chicos (ver la [sección 3](#3-plan-por-pr)). Ninguno cambia lo que hace una pantalla, solo cómo se ve y cómo se dice.
- Si una pantalla tiene un motivo real para ser distinta (por ejemplo, Gastos maneja varias monedas), se deja como está y se anota el motivo en la vista.
- Código nuevo: seguir esta guía desde ya.

---

## 1. Lo que se encontró

Números contados en las 337 vistas de `resources/views`.

| Tema | Qué pasa hoy | Dónde |
|---|---|---|
| **Idioma** | `APP_LOCALE=en` y no existe `lang/es`. La paginación dice «Showing 1 to 15 of 80 results» y «Next». Los errores de validación sin mensaje propio salen en inglés. Los meses salen en inglés: el inicio dice «Tuesday 03 de November de 2026» y Rutas, «03 Nov 2026». | Todo el sistema. Cobros, Planilla y Gastos ya lo esquivan cada uno por su lado (`->locale('es')` y un calendario propio). |
| **Botón principal** | 60 combinaciones distintas de clases para el mismo botón índigo. `x-primary-button` existe, pero es gris oscuro y en MAYÚSCULAS (viene de Breeze), y solo lo usan 33 vistas. | Todos |
| **Colores de botón** | El color no dice nada: «Agregar» es ámbar en una pantalla y gris en otra; «Terminar ruta» es verde y gris; «Buscar» es rosa y gris; «Imprimir» y «Descargar Excel» son verdes. | Facturación, PPQ, Rutas, Exportaciones |
| **Guardar y Cancelar** | Dos órdenes: Planta pone Cancelar a la izquierda y Guardar a la derecha; Facturación, Clientes, Productos, Ajustes, Usuarios y Asistencia ponen Guardar primero y Cancelar como enlace de texto. Gastos mezcla los dos. | Todos |
| **Encabezado** | El título es `h1` en Gastos y Planilla y `h2` en el resto (la página queda sin `h1`). 36 encabezados con botón no se acomodan en el celular (sin `flex-wrap`). | Planta (15), Rutas (10), Exportaciones, PPQ, Clientes, Productos, Usuarios, Personal |
| **Volver** | 25 textos distintos («Volver», «← Volver al listado», «Volver a Cobros»…). En Facturación está abajo; en Gastos, Rutas, PPQ y Cobros, arriba. | Todos |
| **Mensajes de éxito y error** | 4 componentes que hacen lo mismo (`x-asistencia.avisos`, `x-planta.avisos`, `x-rutas.avisos`, `x-gastos-aviso`) y además el mensaje escrito a mano en 30+ vistas. Tres nombres de sesión para lo mismo: `status`, `gastos.aviso`, `planilla.aviso`. El error es rojo en casi todo y rosa en la ficha de Facturación. Solo Gastos y Planilla avisan al lector de pantalla (`role="status"`). | Todos |
| **Errores de formulario** | Tres formas: `x-input-error`, `@error` escrito a mano y el componente `x-configuracion.campo`. Algunas pantallas muestran además un resumen arriba («Revisá estos datos. No se guardó nada.»), otras no. | Todos |
| **Obligatorio u opcional** | Planta marca con `*` lo obligatorio; Facturación, Gastos, PPQ, Clientes y Rutas marcan «(opcional)» lo que no lo es; el resto no marca nada. | Todos |
| **Trato** | Gastos, Planilla y Rutas tratan de «vos» («Revisá», «Elegí»); Planta y Ajustes de «usted» («Seleccione», «Cree el primero»); Facturación usa los dos. | Todos |
| **Tarjetas** | Tres estilos de caja: sombra (`shadow sm:rounded-lg`, 41 vistas), borde (`border border-gray-200`, Gastos y Planilla) y anillo (`ring-1 rounded-xl`, Planta y Rutas). | Todos |
| **Filtros** | El botón «Filtrar» es gris oscuro en unas pantallas e índigo en otras. «Limpiar» solo aparece en algunas. Contingencia tiene tres paginaciones en la misma pantalla sin `withQueryString()`: al cambiar de página en una, las otras vuelven a la primera. | Facturación, Planta, PPQ, Exportaciones |
| **Sin resultados** | Cada lista lo dice a su manera; casi ninguna distingue «todavía no hay nada» de «nada coincide con el filtro». | Todos |
| **Fechas** | La forma común es `d/m/Y` (119 veces) y `d/m/Y H:i` (60). Gastos muestra `2026-11-03` en 9 lugares. Rutas muestra «03 Nov 2026» (sale en inglés por el idioma). | Gastos, Rutas |
| **Montos** | `$1,234.56` escrito a mano con `number_format` en 136 lugares; Gastos y Planilla usan `Dinero::mostrar` con el código de moneda delante («USD 1,234.56»). Hay dos clases `Dinero` (`App\Support` y `App\Services\Gastos`). | Todos |
| **Modo oscuro** | Conviven dos sistemas: clases `dark:` con los tonos `ink`/`paper` que solo existen en `tailwind.config.js` (Planta, Rutas, Asistencia, componentes) y los arreglos globales de `app.css` para la paleta gris (el resto). Faltan arreglos para tonos que sí se usan: `text-amber-900` (15 vistas), `text-red-800` (13), `text-blue-800` (9), `border-indigo-200` (9), `text-emerald-700` (8), `text-slate-*`, `text-purple-700`, `bg-purple-50`. Esos textos quedan oscuros sobre fondo oscuro. | Facturación, Gastos, Planilla, PPQ, Ajustes |
| **Celular** | Gastos, Planilla y Rutas tienen botones y campos de 44 px (`min-h-11`) y lista en tarjetas en el celular. El resto tiene botones de 36 px y tablas que se corren de lado. | Todos menos Gastos, Planilla y Rutas |
| **Etiquetas de estado** | Seis componentes que dibujan la misma pastilla de color: `x-planta.badge`, `x-rutas.estado-badge`, `x-estado-dte-badge`, `x-configuracion.badge`, `x-asistencia.estado` y `x-planta.toggle-activo` (estos dos últimos son el mismo botón Activo/Inactivo). | Planta, Rutas, Facturación, Ajustes, Asistencia |
| **Confirmaciones** | `confirm()` del navegador en 40 lugares, `x-modal` en Cobros y Perfil, y pantallas propias de confirmación para Hacienda. Está bien que haya niveles; falta decir cuándo va cada uno. | Todos |

---

## 2. La forma única

### Idioma y trato

- Todo en castellano, también lo que escribe Laravel: paginación, errores de validación, nombres de meses y días.
- Trato de **vos** (Gastos, Planilla y Rutas, que son las pantallas más nuevas y como habla la oficina): «Revisá», «Elegí», «Guardá». *Decidido por Melqui.*
- Frases cortas que digan qué pasó y qué hacer. Sin palabras técnicas («dry-run», «mock», «payload») en lo que ve la oficina.

### Encabezado de la pantalla

Tomado de Gastos.

```blade
<x-slot name="header">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">Insumos</h1>
        {{-- acciones: como mucho un botón principal y un «Volver» --}}
    </div>
</x-slot>
```

- Un solo `h1` por pantalla, en el encabezado.
- Las acciones de la pantalla («Nuevo insumo», «Volver a…») van a la derecha del título y bajan solas en el celular.

### Barra lateral

- Los grupos se arman con `x-sidebar-group` y sus opciones con `x-sidebar-link`.
- **Los títulos de grupo de la barra van en una línea, cortos**: una o dos palabras («Seguimiento», «Contabilidad», «Ventas y facturación» es el máximo). Un título partido en dos se lee como dos grupos. *Pedido de Melqui, 10/11/2026.*
- Si alguno no entra, el componente lo corta con «…» y el texto completo queda al pasar el mouse; eso es un aviso de que hay que acortarlo, no una forma de usarlo.
- La opción de adentro no repite la palabra del grupo: grupo «Seguimiento», opción «Facturas por cobrar».

### Botones

Tomado de Gastos y Planilla (botones de 44 px, que se tocan bien con el dedo). Las clases viven **solo** en los componentes `x-primary-button`, `x-secondary-button` y `x-danger-button`, que ya existen; se cambia cómo se ven, no se crean otros.

| Tipo | Para qué | Aspecto |
|---|---|---|
| **Principal** | La acción que se viene a hacer. **Una sola por pantalla o formulario.** | Índigo: `inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700` |
| **Secundario** | Cancelar, Volver, Filtrar, Imprimir, Descargar. | Blanco con borde: `inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50` |
| **Peligro** | Eliminar, Anular, Invalidar, Revertir. | Rojo: igual que el principal con `bg-red-600 hover:bg-red-700` |
| **Terminar** | Solo para cerrar algo que salió bien en Rutas: «Entregado» (el cierre de día y «Terminar ruta» de las salidas de antes se retiraron el 11/11/2026). Quién lo entregó se elige en la misma tarjeta del CCF con pastillas, una por persona que va en la salida. | Verde: igual que el principal con `bg-green-600 hover:bg-green-700` |

- Sin mayúsculas sostenidas: «Guardar insumo», no «GUARDAR INSUMO».
- El ámbar, el celeste y el rosa son para avisos y etiquetas, **nunca** para botones.
- Texto del principal: verbo + qué. «Guardar insumo», «Registrar pago», «Emitir factura». En una edición, «Guardar cambios».
- Texto de cancelar: siempre «Cancelar». Lleva a donde se estaba antes (la lista o la ficha) y no borra nada.
- Las acciones en una fila de tabla son enlaces de texto índigo («Editar», «Ver»), no botones.

**Lugar.** Tomado de Planta y del diálogo de `x-modal`: al pie del formulario, separados por una línea, alineados a la derecha, **Cancelar a la izquierda y el principal a la derecha**. En el celular quedan uno arriba del otro, a todo el ancho, en el mismo orden: Cancelar arriba y el principal abajo. No se usa `flex-col-reverse` ni `order-*`: el CSS de producción no se compila en el servidor y esas clases no están, así que los botones quedaban en fila.

```blade
<div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
    <x-secondary-button :href="route('planta.insumos.index')">Cancelar</x-secondary-button>
    <x-primary-button>Guardar insumo</x-primary-button>
</div>
```

(`x-secondary-button` hoy es solo `<button>`; para usarlo como enlace se le agrega `href` en el PR de piezas comunes.)

### Formularios

Tomado de `x-configuracion.campo`, que ya junta etiqueta, ayuda y error en un solo lugar; se mueve a `x-campo` para que lo use todo el sistema.

- Etiqueta arriba del campo: `block text-sm font-medium text-gray-700`.
- Ayuda debajo, en gris chico: `mt-1 text-xs text-gray-500`.
- Error debajo del campo, en rojo: `mt-1 text-sm text-red-600`. Siempre junto a su campo.
- Si hay errores, además un resumen arriba del formulario (de Gastos): «Revisá estos datos. No se guardó nada.» con la lista, `role="alert"`.
- Se marca lo **opcional** con «(opcional)» en la etiqueta; lo obligatorio no lleva marca pero sí el atributo `required`. (Es lo que hacen Facturación, Gastos, PPQ, Clientes y Rutas. Planta deja el `*`.)
- Al volver de un error, los campos conservan lo escrito (`old()`). Los archivos no se pueden conservar: se avisa que hay que elegirlos de nuevo (como hace Gastos).
- Campos de 44 px de alto (`min-h-11`) en las pantallas que se usan en el celular.

### Confirmaciones

Tres niveles, según lo que se pierde si uno se equivoca:

1. **Se puede deshacer o no importa** (guardar, filtrar, marcar leído): sin confirmación.
2. **Borra o anula algo interno** (eliminar sala, quitar huella, eliminar correlativo): `confirm()` del navegador con pregunta y consecuencia: «¿Eliminar esta sala? No se puede deshacer.»
3. **Toca a Hacienda, dinero o a otra persona** (emitir en producción, invalidar, transmitir): bloque de confirmación en la propia pantalla, como `x-dte.confirmacion-produccion` y `x-configuracion.confirmacion-n2`, que dice qué va a pasar con los datos del caso.

### Mensajes de éxito y de error

Tomado de `x-rutas.avisos` (éxito, error y errores de validación en un solo componente) más lo que agregó Gastos para el lector de pantalla.

- Un solo componente `x-avisos`, que reemplaza a los cuatro de hoy y a los escritos a mano.
- Dos claves de sesión y nada más: `status` para éxito y `error` para fallas. `gastos.aviso` y `planilla.aviso` pasan a `status`.
- Éxito: caja verde `rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-700` con `role="status"`.
- Error: caja roja (no rosa) igual, con `role="alert"`.
- Va arriba del contenido, debajo del encabezado. Al final se dibuja una sola vez desde el layout, así ninguna pantalla puede perder un mensaje.
- Texto: qué pasó, en pasado y con el dato que importa. «Pago de $120.00 registrado.», «No se pudo emitir: Hacienda no respondió. Probá de nuevo en unos minutos.»

### Listas y tablas

Tomado de Planta (tabla, filtros y «Limpiar») y de Gastos (lista en tarjetas en el celular).

- **Caja:** `rounded-lg border border-gray-200 bg-white` (la de Gastos y Planilla). El modo oscuro lo resuelve `app.css`; no hacen falta clases `dark:`.
- **Filtros:** formulario `GET` arriba de la tabla, etiquetas chicas, botón secundario «Filtrar» y enlace «Limpiar» que aparece solo si hay algún filtro puesto.
- **Encabezado de la tabla:** `bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500`.
- **Números y montos** alineados a la derecha con `tabular-nums`. La columna de acciones va última, a la derecha, y se llama «Acciones».
- **Pocas opciones en el celular:** en Rutas, en lugar del formulario, pastillas que son enlaces (`?ver=`): «Faltan 3 · Entregados 5 · Todos 8», la elegida en índigo y con `aria-current="page"`. Si la elegida no tiene nada, se dice y se ofrece «Ver todos».
- **Paginación:** `->paginate()->withQueryString()` en el controlador, para no perder los filtros al cambiar de página, y `{{ $lista->links() }}` al pie de la caja (`border-t border-gray-100 px-4 py-3`).
- **Sin resultados:** una sola fila, centrada, `py-10 text-center text-gray-500`, con dos textos distintos:
  - sin filtros: «Todavía no hay insumos.» + enlace «Registrar el primero» si tiene permiso;
  - con filtros: «Nada coincide con estos filtros.» + enlace «Limpiar filtros».
- **Celular:** en las pantallas que se usan en ruta o en el celular (Rutas, Cobros, Gastos), la tabla se cambia por una lista de tarjetas (`sm:hidden` / `hidden sm:block`). En las de oficina alcanza con que la tabla se corra de lado (`overflow-x-auto`).

### Pestañas

Tomado de Gastos. Para las partes de una misma pantalla (Gastos por estado; el tablero de Rutas: Resumen, Viajes, Mapa y Vendedores).

- Una fila de enlaces dentro de un `<nav>` con `aria-label`: `flex flex-wrap gap-1 border-b border-gray-200`.
- Cada pestaña: `inline-flex min-h-11 items-center rounded-t-md px-3 text-sm`. La elegida lleva `border-b-2 border-indigo-600 font-semibold text-indigo-700` y `aria-current="page"`; las otras, `text-gray-600 hover:text-gray-900`.
- Si una pestaña pide un permiso, sin él no se dibuja. La ruta igual lleva su candado.

### Menú del celular del vendedor

Del flujo único de Rutas (10/11/2026). Las pantallas del vendedor no llevan barra lateral: arriba va una fila de pastillas con sus tres pantallas, siempre en este orden: **Inicio**, **Sacar pedido** y **Por entregar**.

- Un `<nav>` con `aria-label` y `grid grid-cols-3 gap-2`. Cada pastilla: `inline-flex min-h-11 items-center justify-center whitespace-nowrap rounded-full px-2 text-sm font-medium`. La elegida va en índigo (`bg-indigo-600 text-white`) y con `aria-current="page"`.
- Cada título entra en una línea en el celular más angosto (375 px). Un título nuevo que no entre no se agrega: se busca uno más corto.
- En la barra lateral de la oficina el orden es Tablero, Pedidos, Ventas, Todos los viajes y Zonas, y al final **una sola** entrada, «Menú del vendedor», que abre el Inicio del celular (a quien solo puede mirar, «Por entregar»). Las tres pantallas no se repiten en la barra. *Pedido de Melqui, 11/11/2026.*
- Quien entra sin ficha de vendedor no queda en una pantalla vacía: se le dice «Tu usuario no es vendedor» y se le ofrece «Por entregar».

### Las palabras de Rutas

De la revisión del 11/11/2026. En todo Rutas, y en Fábrica y Facturación cuando nombran lo mismo:

- **Zona** es lo que antes se llamaba ruta (el catálogo). Sin zona se dice «Sin zona». En el mapa, el departamento o distrito es el **lugar**, y el selector se llama «Ver por».
- **Viaje** es lo que antes se llamaba salida. El enlace dice «Ver viaje». «Salida» queda solo para las armadas a mano de antes del 10/11/2026.
- El viaje está **Abierto** o **Cerrado** (o Cancelado). No se mezcla con «en curso», «finalizada» ni «en camino». Sale de `SalidaRuta::estadoLegible()`.
- **Sugerido** es solo la hoja que firma el gerente de la sala. La pantalla del celular se llama «Pedido de <sala>».
- Las fechas de un viaje van como todas: `dd/mm/aaaa`, y un rango, «14/08/2026 al 16/08/2026».

### Mapas y gráficos

Tomado del mapa de ventas de Rutas (09/11/2026).

- Los colores propios del dibujo (rampas, rayado, leyenda) van como variables en un bloque `<style>` de la vista, con sus tonos para la clase `dark` de `<html>`. Lo demás (cajas, textos, botones) usa las clases de siempre.
- Siempre con leyenda y con una tabla de los mismos datos, que sirve sin el dibujo y con lector de pantalla.
- Las librerías van como archivo en `public/vendor/<nombre>/` con su licencia, no por CDN: así andan en contingencia y sin internet.

### Fichas y detalles

Tomado de las fichas de Gastos y Rutas.

- En el encabezado: título con lo que identifica la cosa («Factura 00000123», «Ruta Oriente») y, a la derecha, «Volver a <lugar>» como botón secundario. Nunca solo «Volver».
- Arriba de todo: la etiqueta de estado y los avisos.
- Primero los datos que se buscan al abrir la ficha (quién, cuánto, cuándo, estado); después el detalle (líneas, movimientos); al final el historial y los datos técnicos.
- Datos en lista de pares `dt`/`dd`: la etiqueta en gris chico arriba y el valor debajo.
- Las acciones de la ficha (Editar, Anular, Imprimir) van juntas, en una sola barra arriba del detalle, con las reglas de los botones.

### Fechas y montos

- **Fecha:** `d/m/Y` → `03/11/2026`. **Con hora:** `d/m/Y H:i` → `03/11/2026 14:05`. Los segundos solo en Auditoría.
- En listas del celular se puede usar la fecha corta `d/m` o «hoy»/«ayer».
- Los campos de fecha (`<input type="date">`) siguen usando `Y-m-d` por dentro; eso no se ve.
- **Monto:** `$1,234.56`, siempre con dos decimales, alineado a la derecha.
- Si el monto no es en dólares, va el código de moneda delante: `EUR 1,234.56`. Es el único caso donde Gastos sigue distinto, porque maneja varias monedas.
- Una sola función para mostrar montos (`App\Support\Dinero`), en vez de `number_format` escrito en cada vista.

### Modo claro y oscuro

- En las vistas se usan **solo** los colores normales de Tailwind (gris, índigo, verde, ámbar, rojo, celeste). `app.css` los pasa al tono oscuro.
- Los tonos `ink` y `paper` de `tailwind.config.js` quedan **solo** para la barra superior, la barra lateral y los componentes compartidos.
- Todo color que se use en una vista tiene que tener su arreglo en `app.css`. Hoy faltan los de la tabla de la sección 1; se agregan en un PR.
- Las clases `dark:` que ya existen en Planta, Rutas y Asistencia se van quitando cuando se toque cada pantalla; no se hace un PR solo para eso.
- Cada PR de pantallas se revisa en claro, en oscuro y con el ancho de un celular.

### Etiquetas de estado

- Un solo componente `x-insignia` (sale de `x-planta.badge`) que recibe el color que da el enum: `x-rutas.estado-badge`, `x-estado-dte-badge` y `x-configuracion.badge` lo usan por dentro.
- `x-planta.toggle-activo` se reemplaza por `x-asistencia.estado`, que ya hace lo mismo y además recibe el permiso; pasa a llamarse `x-estado-activo`.
- Colores con significado: verde = listo/activo, ámbar = falta algo, rojo = rechazado/error, gris = inactivo/borrador, índigo/azul = en proceso.

### Pantalla táctil (Fábrica)

Para la pantalla de la fábrica (`/fabrica/pedidos`), que se usa de pie, con el dedo o con el mouse de una laptop. Agregado el 09/11/2026.

- **Botón principal:** `min-h-12 py-5 text-xl font-bold rounded-xl`, índigo («Se manda todo», «Mandar»).
- **Secundarios:** `min-h-12 py-4 text-lg font-semibold rounded-xl`, blancos con borde («Faltan cosas», «No se trae», «Deshacer»).
- **Cantidad:** `text-2xl font-bold tabular-nums`, alineada a la derecha.
- Sin texto con prefijo de pantalla: el botón dice lo que hace.
- Lo que se puede deshacer no se confirma: sale el aviso con un botón «Deshacer».
- `confirm()` solo para «Deshacer» un envío y para «Borrar lo marcado».
- La lista se recarga sola cuando nadie la usa (dedo, mouse, rueda o teclado); la hoja que se está llenando, nunca (guarda sola). Cada recarga renueva la sesión: para quien ve más que Fábrica, deja de recargarse antes de que la sesión venza por inactividad.
- La hoja guarda con un solo retardo corto y, al salir, primero termina de guardar. Los errores del guardado se muestran con texto propio en castellano (por ejemplo, «Se cerró la sesión. Volvé a entrar.»), nunca el mensaje en inglés del sistema.

### Páginas para imprimir

Para lo que se imprime o se guarda como PDF desde el navegador: la lista de empaque (`facturacion/listas/imprimir`) y la hoja de lo que salió de la fábrica (`rutas/pedidos/hoja`). Agregado el 09/11/2026.

- Documento aparte: su propio `<html>`, sus estilos en un `<style>` y sin el bundle de la aplicación ni la barra. No lleva modo oscuro.
- Color de acento con los mismos colores del sistema: índigo para el encabezado y la tabla, verde, ámbar y rojo con el significado de las etiquetas de estado. Se imprime tal cual (`-webkit-print-color-adjust: exact; print-color-adjust: exact;`). **Regla:** la hoja se tiene que leer igual en una impresora en blanco y negro: nada se dice solo con color, los textos de color son tonos oscuros (`700`/`800`) y las cajas claras llevan borde. Entra en hoja carta vertical (`@page { size: letter portrait; }`).
- La lista de empaque (`facturacion/listas/imprimir`) todavía sale en blanco y negro; se pasa a color cuando se toque esa pantalla.
- Arriba, una barra que no se imprime (`.no-print`) con «Volver a <lugar>» y el botón «Imprimir», de 44 px de alto.
- Encabezado en una franja índigo: el nombre de la empresa (`config('app.name')`, nunca escrito a mano), el título con lo que es («Lo que salió de la fábrica») y el número a la derecha.
- Los datos en cajitas suaves etiqueta/valor; el estado como insignia del color que da el enum. Después la tabla con encabezado índigo y texto blanco, filas alternadas suaves, números grandes a la derecha y el encabezado que se repite en cada hoja (`thead { display: table-header-group; }`).
- Fechas `d/m/Y` y con hora `d/m/Y H:i`, como en el resto.
- Lo que hay que ver de un vistazo va marcado con una letra en un recuadro, que el color solo refuerza: la «F» de lo que faltó, como la que ponía a mano la fábrica, en rojo sobre fondo rojo claro y con `aria-label="Faltó"`; al lado, en rojo, cuánto faltó. Lo que todavía va en otro envío, en ámbar.
- Al pie, el espacio para las firmas.
- Lo que el papel no necesita no va: la hoja de la fábrica no lleva precios.

---

## 3. Plan por PR

Ordenado por cuánto ayuda en el uso diario. Cada PR es chico, se puede revisar solo y no cambia lo que hacen las pantallas. Ninguno toca `CHANGELOG.md`.

| # | PR | Qué cambia | Por qué en este lugar |
|---|---|---|---|
| 1 | **Castellano en todo el sistema** | `lang/es` (paginación, validación, contraseñas), idioma `es` por defecto. Los `->locale('es')` que ya existen se dejan: no estorban y protegen documentos como el contrato. | Arregla de una vez el inglés que se ve en todas las pantallas. Al desplegar hay que cambiar `APP_LOCALE=es` en el `.env` del servidor. Revisar pruebas que esperen textos en inglés. |
| 2 | **Modo oscuro: tonos que faltan** | Solo `app.css`: los tonos de la sección 1 que hoy no se leen. | Texto ilegible hoy, arreglo de un solo archivo. |
| 3 | **Piezas comunes** | `x-avisos`, botones con el aspecto nuevo (y `href` en el secundario), `x-campo`, `x-insignia`, `x-estado-activo`. Solo se cambian los lugares que ya usan esos componentes. | Todo lo que sigue depende de esto. |
| 4 | **Facturación** | Avisos, botones (orden y color), encabezados, fichas con «Volver» arriba, paginación de Contingencia con `withQueryString()`. | Es lo que más usa la oficina todos los días. |
| 5 | **Cobros y PPQ** | Avisos, botones, filtros, sin resultados, lista en el celular en Cobros. | Uso diario de la oficina. |
| 6 | **Gastos** | Fechas `d/m/Y`, clave `status`, botones a los componentes. Ya es el más cercano a la guía. | Uso diario; cambio chico. |
| 7 | **Planilla** | Clave `status`, botones, encabezados. | Uso quincenal. |
| 8 | **Rutas** | Encabezados que se acomoden, fechas `d/m/Y`, botones verdes solo para «Terminar», `x-avisos`. | Lo usan los vendedores en el celular. **Esperar** a que la sesión «Enlazar Usuarios con las fichas de Personal» esté en `master` y tomar lo suyo como referencia. |
| 9 | **Personal, Usuarios y Asistencia** | Igual que el resto. | **Esperar** a la misma sesión; hoy se están unificando ahí. |
| 10 | **Clientes, Productos, Exportaciones, Documentos recibidos, Contabilidad** | Avisos, botones, encabezados, formularios. | Uso frecuente, menos diario. |
| 11 | **Planta** | Quitar clases `dark:` repetidas al tocar, «(opcional)» en vez de `*`, trato de vos, encabezados que se acomoden. | Ya es de lo más ordenado. |
| 12 | **Ajustes y Auditoría** | Trato de vos, botones, avisos. | Uso ocasional. |
| 13 | **Cierre** | `x-avisos` se dibuja una sola vez desde el layout y se quitan los que quedaron en las vistas. Borrar los componentes viejos que ya nadie usa. | Solo cuando todos los anteriores estén en `master`. |

Cada PR de pantallas incluye: las pruebas que ya existen pasando, una revisión en claro, en oscuro y en el celular, y en la descripción la lista de pantallas tocadas.

---

## 4. Decisiones de Melqui

Tomadas el 03/11/2026:

1. **Trato de vos**, como Gastos, Planilla y Rutas.
2. **Cancelar a la izquierda y Guardar a la derecha**, como Planta y los diálogos. En Facturación cambia de lugar.
3. **Verde solo para «Terminar» en Rutas** («Entregado», «Terminar día», «Terminar ruta»). Todo lo demás, índigo.
