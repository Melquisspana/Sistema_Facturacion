# Plan: el nuevo inicio de Rutas (tablero del jefe de vendedores)

**Estado:** propuesta revisada con las decisiones de Melqui del 03/11/2026 (sección 6). Al escribirlo no había código; el tablero para jefes ya entró a `master` (#263). Escrito el 03/11/2026 sobre `master` en `14a60bf`.

**En una frase:** el inicio de Rutas pasa a ser un tablero para 1 o 2 jefes de vendedores que ven todas las rutas del país y les avisa **cuándo toca ir a cada sala y a cada ruta a sacar pedido**. El ritmo se calcula solo, con las facturas (CCF) que ya hay.

Cómo se trabaja hoy, y lo que el plan respeta:

- Rutas sirve para dos cosas: **sacar pedido** y **entregar**. Primero se saca el pedido y se entrega 1 o 2 días después.
- Al escribir este plan, sacar pedido no existía en el sistema. Hoy ya existe (pedidos de sala, `app/Services/Rutas/PedidosSala.php`).
- No hay calendario fijo: se va «cuando se cree que toca». El sistema **avisa, no agenda**.
- El tablero no gira alrededor de los CCF. Los CCF se usan solo para calcular el ritmo, porque la fecha del CCF es casi la del pedido.
- Todo simple, para el celular, y siguiendo [la guía de pantallas](GUIA_PANTALLAS.md).

---

## El flujo único (10/11/2026): manda sobre lo que sigue

Melqui aprobó el 10/11/2026 un solo flujo, de sacar pedido a entregar. La decisión y sus motivos están en [la decisión 0010](decisiones/0010-el-viaje-reemplaza-a-la-ruta-fija.md). Donde este plan diga otra cosa, vale esto:

- **El viaje se arma solo** con el primer pedido del vendedor y suma los días seguidos en la misma zona o en una vecina. Se cierra solo cuando todo está facturado y entregado.
- **Nadie arma salidas.** No hay «Armar salida», «Salir a esta ruta», «Tu próxima salida», propósito de la salida, carga de los pendientes de la ruta ni asignación de salas a una ruta. Las etapas 2 (PR 6 y 8) y 3 de abajo quedan sustituidas en eso.
- **Las rutas son zonas:** nombre y cobertura. Los «días objetivo» de la sección 2 ya no se cargan ni se editan; el ritmo sale de los pedidos reales. Lo que una zona ya tuviera cargado queda de respaldo mientras no tenga ritmo.
- **El celular del vendedor:** Inicio, Sacar pedido y Por entregar. «Terminar el día» da el PDF del día, con los gastos y «Entregó / Recibió» para firmar. Con ese PDF se entrega el dinero; en el sistema no se liquida nada nuevo.
- **La oficina y el jefe:** Tablero (Resumen, Viajes, Mapa, Vendedores), Pedidos, Ventas, Viajes anteriores y Zonas. La pestaña Viajes dice en qué paso va cada pedido, lo trabado, las facturas que no se pudieron entregar dos veces y los quedan por cobrar.
- **Permisos:** `rutas.salidas.armar` ya no se usa. `rutas.salidas.terminar` sigue solo para terminar las salidas viejas que quedaron abiertas. Facturación da de alta el cliente y la sala de la tienda nueva de un pedido, al facturar, con el permiso `clientes.alta`: vale solo con ese pedido y no deja editar, borrar ni tocar el perfil documental (eso sigue en `clientes.gestionar`, del administrador). **Al desplegar:** crear el permiso `clientes.alta`, dárselo al rol Facturación y quitarle `clientes.gestionar` si lo tuviera.
- **Lo viejo:** las salidas armadas a mano quedan en «Viajes anteriores» como historial. No se borró ninguna tabla ni dato.

---

## Menú, Ventas y números por zona (11/11/2026)

Decidido por Melqui el 11/11/2026. Donde este plan diga otra cosa, vale esto:

- **Menú de la oficina y del jefe:** Tablero primero, después Pedidos, Ventas (solo quien la ve), Todos los viajes y Zonas. Al final, una sola entrada, **«Menú del vendedor»**, que abre el Inicio del celular; Inicio, Sacar pedido y Por entregar son sus pestañas. A quien solo puede mirar (Jefatura y Facturación) le abre «Por entregar».
- **El administrador hace todo lo del vendedor** desde «Menú del vendedor»: sacar pedido, marcar entregas y terminar el día. Tiene ficha de Rutas como el vendedor y el jefe (`FichaVendedor::ROLES`), con las mismas reglas: usa la de Personal si existe y no duplica personas. A los administradores que ya existían se les arma sola la primera vez que entran (`FichaVendedor::asegurar()`), sin migración. Jefatura y Facturación no tienen ficha: miran «Por entregar» y ven el aviso «Tu usuario no es vendedor».
- **Ventas es solo de Administrador y Jefatura.** Sigue siendo su apartado, con Nacional y Exportación. Facturación y el jefe de vendedores ven lo suyo en el Resumen y el Mapa del tablero, que son solo nacionales.
- **Los números por zona cuentan todas las salas.** «CCF por entregar» de cada zona, el aviso del Panel principal y Ventas por zona usan el criterio del tablero (`PropuestaRutas::zonaDe()`): la ruta de la sala si está activa, la zona que cubre su distrito o su departamento o, si ninguna, «Sin zona».
- **Al desplegar (permisos):** quitarle `rutas.ventas.ver` al rol `facturacion`. Correr `php artisan db:seed --class=RolesSeeder` lo deja igual que `PermisoSistema::paraRol` y limpia la caché de permisos. El administrador ya tiene `rutas.pedidos.registrar` y `rutas.entregas.registrar` (tiene todos): no hay que darle nada.

---

## 1. Qué ve el jefe de vendedores

Una sola pantalla, de arriba hacia abajo, en este orden:

1. **Resumen.** Cuatro números grandes: rutas atrasadas, salas atrasadas, salas que tocan esta semana y salidas en camino. Tocar un número filtra la lista de abajo.
2. **En camino.** Las salidas que andan en la calle ahora, con su avance (ya existe; se conserva).
3. **Rutas.** Una tarjeta por ruta, las más urgentes primero: hace cuánto se fue, cada cuánto se suele ir y cuántas salas suyas están atrasadas.
4. **Salas que tocan.** La lista de salas, las atrasadas primero, con el último pedido, la última entrega y el ritmo. Se puede buscar por nombre y filtrar por ruta.
5. **Vendedores.** Una tarjeta por vendedor: teléfono, salida actual o la última, sus rutas y un enlace a su ficha.

### Maqueta en el celular

Nombres inventados.

```
┌──────────────────────────────────────┐
│ Rutas                   [Armar salida]│
├──────────────────────────────────────┤
│  ┌────────┐ ┌────────┐               │
│  │   2    │ │   14   │               │
│  │ rutas  │ │ salas  │               │
│  │atrasad.│ │atrasad.│               │
│  └────────┘ └────────┘               │
│  ┌────────┐ ┌────────┐               │
│  │   23   │ │   1    │               │
│  │ tocan  │ │salida  │               │
│  │ semana │ │en cam. │               │
│  └────────┘ └────────┘               │
├──────────────────────────────────────┤
│ EN CAMINO                            │
│ Ruta Norte · Ana Ejemplo             │
│ 8 de 12 entregados        [Abrir]    │
├──────────────────────────────────────┤
│ RUTAS                                │
│ ┌──────────────────────────────────┐ │
│ │ Ruta Oriente          ● Atrasada │ │
│ │ Se fue hace 13 días              │ │
│ │ Suele ir cada 10 días            │ │
│ │ 5 salas atrasadas · 3 tocan      │ │
│ │ [Ver salas]  [Salir a esta ruta] │ │
│ └──────────────────────────────────┘ │
│ ┌──────────────────────────────────┐ │
│ │ Ruta Costa         ● Toca pronto │ │
│ │ Se fue hace 6 días               │ │
│ │ Suele ir cada 7 días             │ │
│ └──────────────────────────────────┘ │
├──────────────────────────────────────┤
│ SALAS QUE TOCAN                      │
│ [Buscar sala…]     [Ruta: todas ▾]   │
│ (Atrasadas) (Tocan pronto) (Todas)   │
│                                      │
│ Súper Ficticio Centro    ● Atrasada  │
│ Ruta Oriente                         │
│ Último pedido hace 18 días           │
│ Compra cada 14 días                  │
│ Última entrega hace 16 días          │
│ ──────────────────────────────────── │
│ Tienda La Inventada    ● Toca pronto │
│ Ruta Costa                           │
│ Último pedido hace 6 días            │
│ Compra cada 7 días (aprox.)          │
│ Última entrega: sin registro         │
├──────────────────────────────────────┤
│ VENDEDORES                           │
│ Luis Inventado                       │
│ Tel. 7000-0000                         │
│ Ahora: Ruta Norte, desde el lunes    │
│ Sus rutas: Norte, Oriente            │
│ [Ver ficha]                          │
│ ──────────────────────────────────── │
│ Rosa Ficticia                        │
│ Tel. 7000-0001                         │
│ Última: Ruta Costa, 28/10/2026       │
│ Sus rutas: Costa                     │
│ [Ver ficha]                          │
└──────────────────────────────────────┘
```

En la computadora, lo mismo en dos o tres columnas.

### Detalles de la pantalla

- **Colores** (los de la guía): rojo = atrasada, ámbar = toca pronto, verde = al día, gris = todavía sin ritmo, azul = en camino.
- **Fechas:** «hace N días» en la lista y `d/m/Y` cuando se muestra la fecha.
- **Teléfono:** se toca y llama (enlace `tel:`). Si no tiene teléfono cargado, dice «Sin teléfono» y lleva a su ficha.
- **Sus rutas:** no hay rutas fijas por vendedor (el sistema lo decidió así a propósito). Se muestran las rutas a las que fue en los últimos 90 días, la más frecuente primero.
- **Ver ficha:** al jefe lo lleva a la ficha de campo del vendedor en Rutas (teléfono, funciones y salidas). El jefe no tiene permiso de Planilla, así que nunca ve sueldos ni la ficha de Personal. A quien sí puede abrir la ficha de Personal (por ejemplo, el administrador), el enlace lo lleva ahí.
- **Salir a esta ruta:** el botón que ya existe hoy. Solo lo ve quien puede armar salidas.
- **Salas sin ruta:** el aviso de hoy se conserva al pie («N salas activas sin ruta»).
- Los CCF por entregar quedan como una línea chica dentro de cada tarjeta de ruta, no como lo principal.

---

## 2. Cómo se calcula el ritmo

### Ritmo de una sala: cada cuánto compra

1. Se toman las fechas de los **CCF aceptados por Hacienda** de la sala en los **últimos 12 meses**, sin los invalidados ni los archivados. Es el mismo filtro que ya usa Rutas para las entregas.
2. Si hay varios CCF del mismo pedido (el mismo día o con 1 día de diferencia), cuentan como **un solo pedido**.
3. Se cuentan los días entre un pedido y el siguiente. Se usan los **últimos 10 intervalos**, para que si la sala cambia de costumbre, el ritmo cambie con ella.
4. **El ritmo es la mediana** de esos intervalos: se ordenan de menor a mayor y se toma el del medio. La mediana no se deja llevar por un mes raro: si una sala compra cada 14 días y una vez pasó 60 sin comprar, el ritmo sigue en 14.

Ejemplo: pedidos el 01/09, 15/09, 28/09, 13/10 y 27/10. Intervalos: 14, 13, 15 y 14. Ordenados: 13, 14, 14, 15. La mediana es **14 días**.

### Cuándo el dato es confiable

| Pedidos de la sala en 12 meses | Qué se muestra |
|---|---|
| 4 o más (3 intervalos o más) | **Confiable.** «Compra cada 14 días». |
| 3 (2 intervalos) | **Aproximado.** «Compra cada 14 días (aprox.)», en gris. Se usa igual para avisar. |
| 0, 1 o 2 | **Sin ritmo todavía.** Solo «Último pedido hace N días», sin color. |

### Cuándo está atrasada

- **Próximo pedido esperado** = fecha del último pedido + el ritmo.
- **Margen** = el 20 % del ritmo, y como mínimo 2 días. Sirve para no marcar en rojo por un día de diferencia.

| Estado | Cuándo | Color |
|---|---|---|
| Al día | Faltan más de 2 días para el próximo pedido esperado. | Verde |
| Toca pronto | Faltan 2 días o menos, o ya pasó la fecha pero está dentro del margen. Se avisa 2 días antes porque el pedido se saca antes de que llegue la fecha. | Ámbar |
| Atrasada | Pasó la fecha esperada **más** el margen. | Rojo |
| Sin ritmo | La sala tiene menos de 3 pedidos. | Gris |

Ejemplo: la sala compra cada 14 días, así que el margen es 3 días (20 % de 14 es 2.8; se redondea). Su último pedido fue hace 18 días. La fecha esperada era hace 4 días, y 4 es más que el margen de 3: **atrasada**.

Si una sala pasa **más de 3 veces su ritmo** sin pedido, la tarjeta dice además «¿Dejó de comprar?». No se oculta: puede ser una sala perdida y eso le interesa al jefe.

### Ritmo de una ruta: cada cuánto se va

1. Se juntan las fechas de pedido de **todas las salas de la ruta**.
2. Los días seguidos con pedidos, sin un hueco de más de 3 días, forman **una vuelta** (una salida puede durar varios días y cada sala pide un día distinto).
3. **El ritmo de la ruta es la mediana** de los días entre el comienzo de una vuelta y el de la siguiente.
4. La regla de confiable y de atrasada es la misma que la de las salas.

**Hace cuánto no se va a la ruta:** se cuenta desde la última salida terminada (desde su regreso) o desde la última visita a cualquiera de sus salas, lo que sea más nuevo; se marca «en camino» si hay una en curso. Es lo que hace `RitmoRutas::porRuta`.

**Hace cuánto no se entrega a una sala:** la más nueva entre su último CCF facturado y la última entrega conocida, sea registrada por el vendedor, por la oficina o probada por un albarán de entrega (`EntregasCcf::ultimaVisitaPorSala`). El semáforo de la sala cuenta desde su última visita: esa entrega o el último pedido sacado, lo que sea más nuevo.

### Los «días objetivo» que hoy se cargan a mano

Hoy cada ruta puede tener «días objetivo» cargados a mano (`frecuencia_objetivo_dias`). Con el plan:

- Manda el **ritmo calculado** (decidido por Melqui).
- El campo **queda como respaldo**: se usa solo para una ruta que todavía no tiene ritmo (menos de 3 vueltas).
- **Se deja de mostrar en el tablero.** La tarjeta de la ruta dice «Suele ir cada N días» con el ritmo calculado; si se está usando el respaldo, dice «cada N días (cargado a mano)».
- En el formulario de la ruta se conserva, con el rótulo «Días aproximados, solo mientras la ruta no tenga ritmo (opcional)».
- No se pide cargar nada a mano para empezar.

### Cuando el CCF sale después de la visita

A veces el vendedor lleva el producto en la ruta, saca la orden de compra en la sala y Melqui factura después. En esos casos el CCF sale **después** de la visita, no antes.

**Aceptado (decidido por Melqui):** se sigue usando la fecha del CCF. Esa diferencia de unos días entra en el margen del 20 % (mínimo 2 días), y la mediana no se mueve por un pedido suelto que salió corrido. Se corrige del todo cuando existan los pedidos reales (etapa 3).

### La fuente del cálculo se puede cambiar

El cálculo no lee los CCF directamente. Le pide a una **fuente** «las fechas de pedido de estas salas» (y en la etapa 4, «qué productos y cuántos»).

- Hoy la fuente son **los CCF**.
- Cuando existan los pedidos reales (etapa 3), se agrega la fuente **pedidos** y el tablero no se toca.
- Mientras una sala tenga pocos pedidos reales, se completa con sus CCF anteriores, para no perder el historial.

---

## 3. El rol nuevo «Jefe de vendedores»

Hoy hay seis roles: Administrador, Jefatura, Facturación, Contabilidad, Vendedor y Producción. Cada usuario tiene **uno solo**. Se agrega **Jefe de vendedores** (`jefe_vendedores`), con sus permisos definidos en `PermisoSistema::paraRol`, como los demás.

### Qué puede

| Permiso | Ya existe | Para qué |
|---|---|---|
| `rutas.ver` | Sí | Entrar a Rutas y ver el tablero, las rutas, las salas y las salidas de todo el país. |
| ~~`rutas.personal.ver`~~ | Sí | **Quitado el 04/11/2026:** las fichas de los empleados son solo de la administración (`planilla.ver`). Ve el teléfono de cada vendedor en el tablero. |
| `rutas.entregas.registrar` | Sí | Ir en una salida como cualquier vendedor y usar «Mi salida» desde el celular. |
| `rutas.salidas.armar` | **No: nuevo, en la etapa 2** | Armar una salida: a qué ruta, qué día y quiénes van. |
| `rutas.salidas.terminar` | **No: nuevo, en la etapa 2** | «Terminar día» y «Terminar ruta» de cualquier salida, aunque él no haya ido. |

### El jefe termina la ruta, pero no recibe el dinero

Decidido por Melqui: el jefe **puede terminar la ruta**, pero el dinero no pasa por él.

- El dinero de los clientes lo cobran en la ruta los que van en la salida, y se le entrega a **la oficina**.
- Al terminar el día o la ruta, el jefe elige **quién de la salida entrega el dinero**, como se hace hoy (`entrega_dinero_personal_id`). Si el jefe fue en esa salida y cobró, puede ser él quien entrega.
- **Quien recibe es siempre la oficina.** En la hoja impresa la firma de «Recibe» queda para la oficina, con la palabra «Oficina» debajo. El sistema anota al jefe como quien **terminó** la ruta, nunca como quien recibió el dinero.
- Los montos no cambian: salen de lo cobrado y de los gastos, igual que hoy.

### Cuando el jefe sale a ruta

El jefe no siempre sale, pero a veces sí (decidido por Melqui). Por eso:

- Tiene su **ficha de campo** en Rutas, creada sola al darle el rol, igual que hoy pasa con el rol Vendedor (`FichaVendedor`). Aparece en la sección Vendedores y se lo puede elegir al armar una salida.
- En **sus** salidas hace lo mismo que cualquier vendedor desde «Mi salida»: registra entregas, anota sus gastos del día y cierra su día. En las salidas ajenas no hace nada de eso.
- Al entrar al sistema va al **tablero**, no directo a «Mi salida» como el vendedor. Desde el tablero tiene un enlace «Mi salida» cuando va en una salida en curso.

### Qué no puede

- Nada de Facturación, Cobros, PPQ, Exportaciones ni Planta.
- **Nada de Planilla: no ve sueldos ni la ficha de Personal** (decidido por Melqui). Lo que necesita de cada vendedor lo ve en la sección Vendedores de Rutas.
- **No recibe dinero.** Termina días y rutas, pero el dinero lo recibe la oficina.
- No reabre días ya terminados y no anota ni borra gastos de salidas donde no va (eso sigue siendo `rutas.gestionar`, de la oficina).
- No cambia rutas, coberturas ni asignación de salas (eso sigue siendo `rutas.gestionar`).
- No da de alta personas (eso sigue siendo `rutas.personal.gestionar`).

**Por qué dos permisos nuevos:** `rutas.gestionar`, el que hoy permite crear y terminar salidas, también permite reabrir días, tocar los gastos de cualquier salida, asignar salas y configurar las rutas. Con dos permisos aparte el jefe arma y termina las salidas sin recibir el resto.

El rol se crea con `php artisan db:seed --class=RolesSeeder`, que ya existe y se puede correr varias veces. No es una migración. Las pruebas comprueban que ve el tablero, que usa «Mi salida» solo en las salidas donde va y que recibe «no autorizado» (403) en Facturación, Cobros, Planilla, Personal, cierres y gastos de la oficina.

---

## 4. Qué datos ya existen y cuáles faltan

### Ya existen (la etapa 1 y la 2 no necesitan migraciones)

| Dato | Dónde está | Para qué se usa |
|---|---|---|
| Fechas de los CCF de cada sala | `dtes.fecha_emision` y `dtes.cliente_sucursal_id` | Ritmo de salas y rutas. |
| Productos y cantidades de cada CCF | `DteLinea` | Pedido estimado (etapa 4). |
| Qué ruta atiende cada sala | `cliente_sucursales.ruta_id` | Agrupar salas por ruta. |
| Salidas, fechas y estado | `salidas_ruta` | Hace cuánto no se va a la ruta. Ya tiene el estado «planificada» para la etapa 2. |
| Quién va en cada salida | `salida_ruta_participantes` | Salida actual y «sus rutas» de cada vendedor. |
| Entregas registradas y albaranes | `salida_ruta_entregas` y `ppq_albaranes` | Hace cuánto no se entrega a una sala. El origen del registro (`origen_registro`, texto de 20) admite un valor nuevo `facturado_despues` sin migración. |
| Teléfono y funciones del vendedor | `rutas_personal` y `rutas_personal_funciones` | Sección Vendedores. |
| Ficha de campo y enlace a Personal | `rutas_personal` (con `user_id`) y `planilla_empleados.personal_ruta_id` | Botón «Ver ficha»: la de campo para el jefe; la de Personal solo para quien puede abrirla. |
| Días objetivo de la ruta | `rutas.frecuencia_objetivo_dias` | Solo respaldo para rutas sin ritmo; no se muestra en el tablero. |

### Límites de los datos de hoy

- **Las entregas se registran desde el 28/09/2026.** Antes de esa fecha solo las salas con albarán tienen «última entrega»; las demás dicen «sin registro» hasta su próxima entrega.
- **El CCF puede salir después de la visita** cuando la orden de compra se saca en la sala. Se acepta: entra en el margen (sección 2).
- **Solo cuentan los CCF.** Una sala que solo recibe facturas de consumidor final queda «sin ritmo». Si existen, se agregan esas facturas a la fuente en un PR chico.
- Una sala **sin ruta asignada** no entra en el ritmo de ninguna ruta; sigue apareciendo en el aviso «salas sin ruta».

### Migraciones que harán falta más adelante (no se crea ninguna ahora)

| Etapa | Migración | Por qué |
|---|---|---|
| 3 | Tabla `pedidos_sala`: sala, ruta, salida, vendedor, fecha, estado, número de OC, foto de la hoja firmada, notas. | Hoy el pedido vive en papel y en fotos de WhatsApp; no hay dónde guardarlo. |
| 3 | Tabla `pedido_sala_lineas`: producto, cantidad pedida, cantidad enviada y motivo de lo que no se mandó. | Producción tiene que marcar qué manda y por qué no manda el resto. |
| 3 | Enlace entre pedido y CCF (un pedido puede terminar en más de un CCF). | Para facturar desde el pedido y saber qué quedó sin facturar («regresa»). |
| 3 | Columna `salidas_ruta.proposito`: sacar pedido, entregar o las dos. | Hoy toda salida es para entregar. |
| Futuro, opcional | Columna `cliente_sucursales.ritmo_manual_dias`. | El ajuste a mano por sala que Melqui dejó para después. |

La etapa 4 (pedido estimado) se calcula en el momento y no necesita tabla.

---

## 5. Etapas, en PR chicos y ordenados

Cada PR: rama propia, pruebas, revisión en claro, en oscuro y con ancho de celular, sin tocar `CHANGELOG.md`. Las etapas 1 y 2 no tienen migraciones. Son 15 PR.

### Etapa 1: el tablero (ritmo, días, resumen y Vendedores)

| PR | Qué hace | Pruebas |
|---|---|---|
| 1. Ritmo por sala y por ruta | Servicio nuevo que calcula el ritmo con la fuente CCF (sección 2). `RitmoRutas` usa el ritmo calculado y deja los días objetivo solo de respaldo. Así también se actualiza solo el aviso de rutas del panel principal (`AvisoRutas`). Sin pantalla. | Mediana, CCF del mismo día como un solo pedido, confiable/aprox./sin ritmo, margen mínimo de 2 días, vueltas de ruta, CCF invalidados que no cuentan, «¿dejó de comprar?». |
| 2. Rol Jefe de vendedores | Rol nuevo con `rutas.ver`, `rutas.personal.ver` y `rutas.entregas.registrar`. Aparece en el formulario de Usuarios. `FichaVendedor` le crea su ficha de campo igual que al Vendedor. Al entrar va al tablero. | Entra al tablero; usa «Mi salida» solo en sus salidas; 403 en Facturación, Cobros, Planilla, Personal, cierres y gastos de la oficina; no ve «Salir a esta ruta» ni «Terminar ruta» hasta la etapa 2. |
| 3. Tablero nuevo: resumen, rutas y salas | Se rehace la vista del inicio con la maqueta de la sección 1: resumen, en camino, rutas, salas que tocan con búsqueda y filtro por ruta. Sigue la guía: un `h1`, botones de 44 px, tarjetas en el celular, fechas `d/m/Y`. | La pantalla abre con y sin datos; los filtros; el orden por urgencia; sin consultas por cada sala (todo en pocas consultas). |
| 4. Sección Vendedores | Tarjeta por vendedor activo (el jefe incluido): teléfono, salida actual o última, sus rutas de los últimos 90 días y «Ver ficha». | El jefe va a la ficha de campo y nunca ve datos de Planilla; el administrador va a la de Personal. Vendedor sin teléfono ni salidas. |

### Etapa 2: la salida (CCF facturado después, armarla, terminarla y que el vendedor la vea)

| PR | Qué hace | Pruebas |
|---|---|---|
| 5. CCF facturado después de la entrega | Meter en una salida un CCF que se facturó después de entregar el producto (detalle abajo). No depende del tablero: **se puede adelantar**, porque ya hay una sala que va a mandar su orden de compra para facturar algo que se le entregó. | Ver abajo. |
| 6. Armar salida con fecha | «Armar salida»: ruta, día (hoy o un día que viene) y quiénes van. Si es para hoy, sale en camino como ahora; si es para otro día, queda **planificada**. Permiso nuevo `rutas.salidas.armar` para el jefe; `rutas.gestionar` también puede. | Quién puede armar y quién no; no se arma con una persona inactiva; los CCF se cargan igual que hoy. |
| 7. El jefe termina días y rutas | Permiso nuevo `rutas.salidas.terminar`: el jefe usa «Terminar día» y «Terminar ruta» de la oficina. Elige quién de la salida entrega el dinero; la hoja impresa dice «Recibe: Oficina». Reabrir un día sigue siendo solo de `rutas.gestionar`. | El jefe termina una salida donde no fue; no reabre días; no anota gastos ajenos; quien entrega tiene que ser de la salida; los montos son los mismos que si termina la oficina; la hoja nunca lo muestra como quien recibe. |
| 8. Mi salida: «Tu próxima salida» | En el celular del vendedor (y del jefe, si va) aparece la salida apenas se arma: ruta, día, compañeros y **las salas que tocan sacar pedido**, las atrasadas primero, junto con los CCF por entregar. Solo lectura hasta que empieza. El vendedor la empieza con «Empezar ruta». | El vendedor ve solo sus salidas; no registra nada en una planificada; la empieza solo si va en ella. |

No se mandan mensajes de WhatsApp ni avisos al teléfono: el vendedor la ve al abrir el sistema.

#### PR 5 en detalle: el CCF que se factura después de entregar

**El caso.** A veces el producto se lleva en la ruta y se entrega **sin CCF**. La orden de compra llega días después y recién entonces Melqui factura. Ese CCF tiene que quedar como entregado en la salida donde se entregó, con quién lo entregó.

**Qué existe hoy.**

- `EntregasCcf::agregar` mete en una salida un CCF por su número de control, de cualquier sala, pero **solo si la salida sigue abierta** (planificada o en curso). Rechaza el CCF que no está aceptado por Hacienda, el que ya consta entregado, el que va en otra salida abierta y el que ya tiene albarán de entrega.
- `EntregasCcf::registrar` lo marca entregado **solo si la salida está en curso y el día de la entrega no está terminado**. Quien lo entregó tiene que ir en la salida. El cobro toma la forma habitual del cliente y entra en el cierre de ese día.
- Una salida terminada o liquidada no acepta nada.

**La propuesta.** En la hoja de la salida, un botón «Entregado antes de facturar» para la oficina (`rutas.gestionar`, que es quien factura). Pide tres datos: el número de control, **quién lo entregó** (de los que fueron) y **qué día** se entregó (entre la salida y el regreso).

| Cómo está la salida | Qué pasa |
|---|---|
| En curso, con ese día abierto | El CCF entra a la salida y queda entregado ese día, por esa persona. |
| En curso, con ese día ya terminado | Queda anotado «entregado en esta salida, el día D, por P». **No se reabre el día.** |
| Terminada o liquidada | Queda anotado igual: «entregado en la salida X, el día D, por P». **La liquidación no se toca.** |

**Qué pasa con el cobro.** Un CCF facturado después **nunca suma dinero a la salida**, en ninguno de los tres casos:

- Se anota sin cobro, y el cobro sigue el camino normal de cualquier CCF de esa sala: crédito, quedan o la próxima visita.
- Los totales del día y de la ruta, y sus hojas impresas, quedan **exactamente iguales**. Estos CCF salen aparte, en un bloque «Facturados después de entregar (sin dinero)».
- Si alguna vez la sala pagó en el momento, sin factura, ese dinero ya se entregó a la oficina por fuera. Se escribe en la nota del registro y no se suma de nuevo.

**Controles que se mantienen.**

- Las mismas reglas de `agregar`: aceptado por Hacienda, no entregado ya, no en otra salida abierta, sin albarán de entrega.
- Quien lo entregó tiene que haber ido en la salida, y el día tiene que estar dentro de las fechas de la salida.
- Queda quién lo anotó y cuándo. Se puede deshacer, solo desde la oficina; como no movió dinero, deshacerlo tampoco lo mueve.
- Sin migración: el registro lleva `origen_registro = facturado_despues`. La regla nueva vive en `EntregasCcf`, como el resto.

**En el tablero.** La «última entrega» de la sala toma el día real de la entrega. El ritmo sigue usando la fecha del CCF (decisión 5).

**Pruebas:** los tres casos de la tabla; los totales del cierre y de la liquidación son iguales antes y después; se rechaza a una persona que no fue, un día fuera de la salida y un CCF ya entregado o con albarán; sin `rutas.gestionar` da 403; deshacer no toca dinero.

### Etapa 3: sacar pedido y mandarlo a producción

Antes de programarla, una sesión propia de diseño con Melqui, que parte de la idea «pedidos de sala a producción» (pedido en el celular con foto de la hoja firmada, pantalla de producción, facturar desde el pedido).

| PR | Qué hace |
|---|---|
| 9. Tablas y permisos de pedidos | Las migraciones de la sección 4 y los permisos: el vendedor registra pedidos, producción los ve y los marca. |
| 10. Sacar pedido en el celular | Desde «Mi salida», en cada sala que toca: productos y cantidades, número de OC si la sala la usa (con el aviso de forma incorrecta del issue #169) y foto de la hoja firmada. |
| 11. Pantalla de producción | Producción ve los pedidos que llegan, marca qué manda y escribe por qué no manda lo demás. |
| 12. Facturar desde el pedido | Facturación arma el CCF a partir del pedido; lo que no se factura queda marcado «regresa». |
| 13. El ritmo pasa a los pedidos | La fuente del ritmo cambia a los pedidos reales, completando con CCF donde todavía haya pocos. El tablero no cambia. |

### Etapa 4: pedido estimado

| PR | Qué hace |
|---|---|
| 14. Cálculo del pedido estimado | Por sala: qué productos suele llevar y cuánto. Un producto entra si apareció en al menos la mitad de sus últimos 6 pedidos, y la cantidad es la mediana de lo que llevó esas veces. Usa la misma fuente cambiable: hoy los CCF (sus líneas), después los pedidos. Con pruebas. |
| 15. Mostrarlo al sacar pedido | Al abrir el pedido de una sala, aparece el estimado ya cargado y el vendedor lo corrige según lo que cuenta en la sala. Nunca se manda solo. |

---

## 6. Decisiones de Melqui (03/11/2026)

1. **El jefe también sale a ruta**, no siempre pero sí. Va en una salida como cualquier vendedor y usa «Mi salida» (sección 3).
2. **El jefe arma las salidas**, con su permiso propio `rutas.salidas.armar` (etapa 2).
3. **El jefe no ve sueldos ni nada de Planilla.** No se le da permiso de Planilla; lo que necesita de cada vendedor lo ve en la sección Vendedores de Rutas.
4. **El ritmo calculado reemplaza los días objetivo** cargados a mano. El campo queda solo de respaldo para rutas sin ritmo y deja de mostrarse en el tablero (sección 2).
5. **El CCF puede salir después de la visita**, cuando se lleva el producto y la orden se saca en la sala. Se acepta: se sigue usando la fecha del CCF y el margen del ritmo absorbe la diferencia (sección 2).
6. **El jefe puede terminar la ruta, pero no recibe el dinero.** El dinero lo cobran los de la salida y se le entrega a la oficina. Lleva el permiso propio `rutas.salidas.terminar` (sección 3 y PR 7).
7. **El CCF facturado después de entregar entra a la salida donde se entregó**, con quién lo entregó. Si la salida ya se terminó, queda anotado sin tocar su liquidación ni su dinero (PR 5).
