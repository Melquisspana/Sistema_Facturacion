# Plan: el nuevo inicio de Rutas (tablero del jefe de vendedores)

**Estado:** propuesta para que Melqui la revise. Todavía no hay código. Escrito el 03/11/2026 sobre `master` en `14a60bf`.

**En una frase:** el inicio de Rutas pasa a ser un tablero para 1 o 2 jefes de vendedores que ven todas las rutas del país y les avisa **cuándo toca ir a cada sala y a cada ruta a sacar pedido**. El ritmo se calcula solo, con las facturas (CCF) que ya hay.

Cómo se trabaja hoy, y lo que el plan respeta:

- Rutas sirve para dos cosas: **sacar pedido** y **entregar**. Primero se saca el pedido y se entrega 1 o 2 días después.
- Sacar pedido todavía no existe en el sistema. Llega en la etapa 3.
- No hay calendario fijo: se va «cuando se cree que toca». El sistema **avisa, no agenda**.
- El tablero no gira alrededor de los CCF. Los CCF se usan solo para calcular el ritmo, porque la fecha del CCF es casi la del pedido.
- Todo simple, para el celular, y siguiendo [la guía de pantallas](GUIA_PANTALLAS.md).

---

## 1. Qué ve el jefe de vendedores

Una sola pantalla, de arriba hacia abajo, en este orden:

1. **Resumen.** Cuatro números grandes: rutas atrasadas, salas atrasadas, salas que tocan esta semana y salidas en camino. Tocar un número filtra la lista de abajo.
2. **En camino.** Las salidas que andan en la calle ahora, con su avance (ya existe; se conserva).
3. **Rutas.** Una tarjeta por ruta, las más urgentes primero: hace cuánto se fue, cada cuánto se suele ir y cuántas salas suyas están atrasadas.
4. **Salas que tocan.** La lista de salas, las atrasadas primero, con el último pedido, la última entrega y el ritmo. Se puede buscar por nombre y filtrar por ruta.
5. **Vendedores.** Una tarjeta por vendedor: teléfono, salida actual o la última, sus rutas y un enlace a su ficha de Personal.

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
- **Ver ficha:** lleva a su ficha de Personal si está enlazada y el jefe puede verla; si no, a su ficha de campo en Rutas, que tiene el teléfono y las salidas.
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

**Hace cuánto no se va a la ruta:** se cuenta desde la última salida terminada (desde su regreso) o se marca «en camino» si hay una en curso. Es lo que ya hace `RitmoRutas::porRuta`. Si la ruta nunca tuvo una salida registrada, se usa la última vuelta de pedidos.

**Hace cuánto no se entrega a una sala:** la última entrega conocida, sea registrada por el vendedor, por la oficina o probada por un albarán de entrega. Es lo que ya hace `EntregasCcf::ultimaVisitaPorSala`.

### Los «días objetivo» que hoy se cargan a mano

Hoy cada ruta puede tener «días objetivo» cargados a mano (`frecuencia_objetivo_dias`). Con el plan:

- Manda el **ritmo calculado**.
- Los días objetivo quedan solo de **respaldo**, para una ruta nueva que todavía no tiene ritmo.
- No se pide cargar nada a mano para empezar.

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
| `rutas.personal.ver` | Sí | Ver a los vendedores y su ficha de campo (teléfono, funciones, salidas). |
| `rutas.salidas.armar` | **No: nuevo, en la etapa 2** | Armar una salida: a qué ruta, qué día y quiénes van. Nada más. |

### Qué no puede

- Nada de Facturación, Cobros, PPQ, Exportaciones, Planta ni Planilla.
- No cierra días, no liquida salidas, no anota ni borra gastos, no toca dinero.
- No cambia rutas, coberturas ni asignación de salas (eso sigue siendo `rutas.gestionar`).
- No da de alta personas (eso sigue siendo `rutas.personal.gestionar`).

**Por qué un permiso nuevo para armar salidas:** `rutas.gestionar`, el que hoy permite crear salidas, también abre cierres, liquidaciones, gastos y la asignación de salas. Un permiso aparte deja al jefe armar la salida sin darle el resto.

El rol se crea con `php artisan db:seed --class=RolesSeeder`, que ya existe y se puede correr varias veces. No es una migración. Las pruebas comprueban que ve el tablero y que recibe «no autorizado» (403) en Facturación, Cobros, Planilla, cierres y gastos.

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
| Entregas registradas y albaranes | `salida_ruta_entregas` y `ppq_albaranes` | Hace cuánto no se entrega a una sala. |
| Teléfono y funciones del vendedor | `rutas_personal` y `rutas_personal_funciones` | Sección Vendedores. |
| Enlace a la ficha de Personal | `planilla_empleados.personal_ruta_id` y `rutas_personal.user_id` | Botón «Ver ficha». |
| Días objetivo de la ruta | `rutas.frecuencia_objetivo_dias` | Respaldo para rutas sin ritmo. |

### Límites de los datos de hoy

- **Las entregas se registran desde el 28/09/2026.** Antes de esa fecha solo las salas con albarán tienen «última entrega»; las demás dicen «sin registro» hasta su próxima entrega.
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

Cada PR: rama propia, pruebas, revisión en claro, en oscuro y con ancho de celular, sin tocar `CHANGELOG.md`. Las etapas 1 y 2 no tienen migraciones.

### Etapa 1: el tablero (ritmo, días, resumen y Vendedores)

| PR | Qué hace | Pruebas |
|---|---|---|
| 1. Ritmo por sala y por ruta | Servicio nuevo que calcula el ritmo con la fuente CCF (sección 2). `RitmoRutas` usa el ritmo calculado y deja los días objetivo de respaldo. Así también se actualiza solo el aviso de rutas del panel principal (`AvisoRutas`). Sin pantalla. | Mediana, CCF del mismo día como un solo pedido, confiable/aprox./sin ritmo, margen mínimo de 2 días, vueltas de ruta, CCF invalidados que no cuentan, «¿dejó de comprar?». |
| 2. Rol Jefe de vendedores | Rol nuevo con `rutas.ver` y `rutas.personal.ver`. Aparece en el formulario de Usuarios. | Entra al tablero; 403 en Facturación, Cobros, Planilla, cierres y gastos; no ve el botón «Salir a esta ruta». |
| 3. Tablero nuevo: resumen, rutas y salas | Se rehace la vista del inicio con la maqueta de la sección 1: resumen, en camino, rutas, salas que tocan con búsqueda y filtro por ruta. Sigue la guía: un `h1`, botones de 44 px, tarjetas en el celular, fechas `d/m/Y`. | La pantalla abre con y sin datos; los filtros; el orden por urgencia; sin consultas por cada sala (todo en pocas consultas). |
| 4. Sección Vendedores | Tarjeta por vendedor activo: teléfono, salida actual o última, sus rutas de los últimos 90 días y «Ver ficha». | El enlace va a Personal solo si el jefe puede abrirla; si no, a la ficha de campo. Vendedor sin teléfono ni salidas. |

### Etapa 2: armar la salida y que el vendedor vea su ruta

| PR | Qué hace | Pruebas |
|---|---|---|
| 5. Armar salida con fecha | «Armar salida»: ruta, día (hoy o un día que viene) y quiénes van. Si es para hoy, sale en camino como ahora; si es para otro día, queda **planificada**. Permiso nuevo `rutas.salidas.armar` para el jefe; `rutas.gestionar` también puede. | Quién puede armar y quién no; no se arma con una persona inactiva; los CCF se cargan igual que hoy. |
| 6. Mi salida: «Tu próxima salida» | En el celular del vendedor aparece la salida apenas se arma: ruta, día, compañeros y **las salas que tocan sacar pedido**, las atrasadas primero, junto con los CCF por entregar. Solo lectura hasta que empieza. El vendedor la empieza con «Empezar ruta». | El vendedor ve solo sus salidas; no registra nada en una planificada; la empieza solo si va en ella. |

No se mandan mensajes de WhatsApp ni avisos al teléfono: el vendedor la ve al abrir el sistema.

### Etapa 3: sacar pedido y mandarlo a producción

Antes de programarla, una sesión propia de diseño con Melqui, que parte de la idea «pedidos de sala a producción» (pedido en el celular con foto de la hoja firmada, pantalla de producción, facturar desde el pedido).

| PR | Qué hace |
|---|---|
| 7. Tablas y permisos de pedidos | Las migraciones de la sección 4 y los permisos: el vendedor registra pedidos, producción los ve y los marca. |
| 8. Sacar pedido en el celular | Desde «Mi salida», en cada sala que toca: productos y cantidades, número de OC si la sala la usa (con el aviso de forma incorrecta del issue #169) y foto de la hoja firmada. |
| 9. Pantalla de producción | Producción ve los pedidos que llegan, marca qué manda y escribe por qué no manda lo demás. |
| 10. Facturar desde el pedido | Facturación arma el CCF a partir del pedido; lo que no se factura queda marcado «regresa». |
| 11. El ritmo pasa a los pedidos | La fuente del ritmo cambia a los pedidos reales, completando con CCF donde todavía haya pocos. El tablero no cambia. |

### Etapa 4: pedido estimado

| PR | Qué hace |
|---|---|
| 12. Cálculo del pedido estimado | Por sala: qué productos suele llevar y cuánto. Un producto entra si apareció en al menos la mitad de sus últimos 6 pedidos, y la cantidad es la mediana de lo que llevó esas veces. Usa la misma fuente cambiable: hoy los CCF (sus líneas), después los pedidos. Con pruebas. |
| 13. Mostrarlo al sacar pedido | Al abrir el pedido de una sala, aparece el estimado ya cargado y el vendedor lo corrige según lo que cuenta en la sala. Nunca se manda solo. |

---

## 6. Riesgos y dudas para Melqui

1. ¿El jefe de vendedores también sale a ruta? Si sí, su rol tiene que incluir «Mi salida» y registrar entregas.
2. ¿El jefe arma salidas con un permiso propio (propuesto) o le damos todo `rutas.gestionar`, que incluye cierres, gastos y dinero?
3. La ficha de Personal pide el permiso de Planilla (sin sueldos): ¿se lo damos al jefe o le alcanza con la ficha de campo de Rutas?
4. El ritmo calculado reemplaza los «días objetivo» cargados a mano en cada ruta, que quedan solo de respaldo: ¿de acuerdo?
5. Riesgo: en las salas con orden de compra, el CCF se emite cuando llega la OC; si llega tarde, el ritmo de esa sala sale unos días más largo hasta que existan los pedidos reales (etapa 3).
