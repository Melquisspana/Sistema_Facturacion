# Módulo Rutas — diseño acordado (26/09/2026)

Decisiones tomadas con el usuario para convertir el área «Cobros» (`/rutas-cobros`, nombre técnico `rutas`) en un módulo de rutas hecho para la operación real. El seguimiento de CCF de Calleja (`/cobros`, en Facturación) **no se toca**: sigue siendo donde se controla albarán, quedan y pago.

## Cómo trabajan hoy (según el usuario)

- El «pedido» es el **CCF impreso** que se emite antes de salir. Viaja con el producto; en la sala (super Calleja, tienda o gasolinera) lo firman y sellan y el vendedor lo trae de vuelta. Lo entregado lo dejan en una bandeja; lo no entregado se queda en la caja del pedido.
- Si la sala da **nota de avería (AC02)**, el vendedor la trae junto al CCF. La AC04 llega por correo.
- En Calleja, cuando cae el albarán al correo, ya consta la entrega. En tiendas y gasolineras no hay albarán.
- Rutas conocidas: Santa Ana, San Miguel, Sonsonate, Usulután, Chalatenango y San Vicente. Cada una cubre departamentos o municipios cercanos (por ejemplo, San Vicente abarca Cojutepeque y Sensuntepeque).
- La frecuencia es aproximada y la llevan de memoria los vendedores y el hermano del usuario. Una salida puede durar varios días (San Miguel, Santa Ana) y pueden ir varios vendedores. Lo que sobra se entrega al día siguiente o en otra salida.
- Casi todos los CCF salen de este sistema; los emitidos en Conta son excepcionales (2 en el mes). No se contempla agregar documentos externos.

## Qué se elimina

La custodia del CCF físico (entregar, transferir, incidencia, anular), la recepción en oficina, las excepciones, la bandeja transversal de documentos, el tablero de saldos, la documentación física, «requiere NC» y el histórico de la salida. También se eliminan sus permisos (`rutas.custodia.ver`, `rutas.custodia.registrar`, `rutas.recepcion`, `rutas.custodia.corregir`) y sus tablas o columnas, mediante una migración. El usuario confirmó que **nunca se usó en producción**. Se conservan los catálogos `rutas`, `rutas_personal`, `rutas_personal_funciones`, la asignación `cliente_sucursales.ruta_id` y las salidas con participantes.

## Qué se construye

### 1. Rutas y salas
- La etiqueta del área cambia de «Cobros» a **Rutas**.
- Cada ruta tiene **días objetivo** aproximados (`frecuencia_objetivo_dias`, que ya existe) y una **cobertura por departamento o municipio**.
- Con la cobertura, el sistema **propone** la ruta de cada sala sin asignar y el usuario confirma o corrige. Hoy solo 18 de 135 salas tienen ruta.
- **Última visita por sala**: la fecha de la última entrega registrada, ya sea por el vendedor, por la oficina o por un albarán.

### 2. Salidas ligeras
- Una salida se crea con ruta, fecha de inicio, días estimados y vendedores. El sistema **propone los CCF emitidos y no entregados** de las salas de esa ruta que no estén en otra salida activa. Se pueden quitar o agregar.
- Por cada CCF se registra una de dos cosas:
  - **Entregado**: quién lo entregó, cuándo y si trae nota de avería.
  - **No entregado**, con un motivo:
    1. No alcanzó el tiempo (se terminó tarde o no se llegó antes de las 5).
    2. La sala ya no recibía a esa hora.
    3. Sala cerrada.
    4. La sala no lo aceptó.
    5. Otro, con nota.
- Un CCF puede pasar por varias salidas; cada paso es un intento con su resultado. Al finalizar la salida, lo no entregado vuelve a pendientes.
- **Calleja: albarán vinculado = entregado automático** con la fecha del albarán, aunque nadie lo haya marcado. Queda identificado como confirmado por albarán.
- No se guarda la hora límite de recepción por sala (el usuario lo decidió así por ahora).

### 3. Tablero «¿a dónde toca ir?»
Una tarjeta por ruta con la última salida, los días transcurridos frente al objetivo (verde, amarillo o rojo), los CCF pendientes de entregar (cantidad y monto) y las salas con más días sin visita. También muestra las salidas en curso.

### 4. Vendedores
- Rol nuevo **Vendedor**, aislado como Producción: entra directo a «Mi salida» y ve los CCF agrupados por sala, con los botones Entregado / No entregado. Solo ve las salidas donde participa y recibe 403 en todo lo demás, con pruebas que lo demuestran. No ve ventas, gráficos, facturación ni clientes.
- El usuario del vendedor se enlaza con su ficha de personal de campo (`rutas_personal.user_id`, que ya existe).
- La oficina puede marcar o corregir entregas por cualquier vendedor.
- **Acceso en producción:** Cloudflare Access envía un PIN al correo, así que un correo inventado no pasa. Cada vendedor usa su **Gmail real**. El usuario lo agrega a la política de Access y conviene alargar la duración de la sesión. El SSO existente abre la sesión solo si el correo coincide con un usuario activo. En local y por Tailscale se entra con correo y contraseña.

### 5. Ventas
- Venta = **CCF menos NC, sin IVA**.
- Gráficos por cliente, sala, ruta, vendedor y producto, con periodo y comparación contra el periodo anterior. Incluye un aviso de salas que bajaron o dejaron de comprar.
- La venta se atribuye al vendedor que registró la entrega; lo que solo se confirmó por albarán aparece como «sin vendedor».

## Permisos (siguen definidos en código, sin editor de roles)

| Permiso | Para qué | Roles |
|---|---|---|
| `rutas.ver` | Entrar y consultar el área | Administrador, Facturación, Jefatura |
| `rutas.gestionar` | Rutas, salas, salidas, marcar por otros | Administrador, Facturación, Jefatura |
| `rutas.entregas.registrar` | Marcar sus propias entregas | Administrador, Vendedor |
| `rutas.ventas.ver` | Gráficos de ventas | Administrador, Jefatura |
| `rutas.personal.ver` / `.gestionar` | Personal de campo (sin cambios) | Administrador |

Nota: hasta ahora Jefatura era solo lectura. Darle `rutas.gestionar` fue una decisión expresa del usuario.

## Orden de construcción

1. Limpieza de custodia, cambio de nombre, cobertura, asignación de salas y días objetivo.
2. Salidas con CCF pendientes, registro de entregas, albarán automático y tablero.
3. Rol Vendedor y pantalla móvil.
4. Ventas.

Cada etapa lleva sus pruebas. No se hace commit, push ni despliegue sin instrucción expresa.

## Estado

### Etapa 1 — hecha en desarrollo (26/09/2026, sin commit)

- Se retiraron la custodia, la recepción, las excepciones, la bandeja, el tablero de saldos, el seguimiento documental por salida y el comando `rutas:asociar-documentos`, con sus vistas, servicios, enums, `config/rutas.php` y las variables `RUTAS_*`. También se retiró el «papel físico» de PPQ: `PpqElegibilidad` queda solo con la regla fiscal.
- Migración `2026_09_26_100000_simplificar_modulo_rutas`:
  - borra `custodia_documento_eventos`, `salida_ruta_documentos` y `cliente_perfiles_documento.modo_papel_fisico`;
  - borra los cuatro permisos de custodia;
  - crea `ruta_coberturas`.
- El área se llama **Rutas** y vive en `/rutas`; `/rutas-cobros/*` redirige con 301. Facturación y Jefatura reciben `rutas.ver` y `rutas.gestionar`.
- Pantallas:
  - tablero «¿A dónde toca ir?» (`RitmoRutas`);
  - cobertura en la ficha de cada ruta;
  - «Asignar salas» (`PropuestaRutas`): el distrito gana sobre el departamento, solo cuentan las rutas activas y la propuesta se recalcula al aplicar.
- La barra de Rutas muestra el grupo «Cobros Calleja» igual que la de Facturación.
- **Al desplegar:** `php artisan migrate` y `php artisan db:seed --class=RolesSeeder` para que Facturación y Jefatura reciban los permisos.
### Etapa 2 — hecha en desarrollo (27/09/2026, sin commit)

Codex se quedó sin cuota al empezar (se restablece el 28/09 a las 2:55) y no dejó cambios. El usuario pidió que Claude la continuara.

- Migración `2026_09_27_100000_create_salida_ruta_entregas_table`: una fila por cada intento de entrega de un CCF en una salida. Modelo `SalidaRutaEntrega` y enums `ResultadoEntrega`, `MotivoNoEntrega` y `OrigenRegistroEntrega`.
- `config/rutas.php`, clave `entregas_desde` (`RUTAS_ENTREGAS_DESDE`, 2026-09-28 por defecto): solo cuentan los CCF emitidos desde esa fecha.
- `EntregasCcf` concentra toda la regla:
  - un CCF está pendiente si es un CCF vigente, emitido desde el corte, de una sala activa de la ruta, sin entrega registrada, sin albarán de entrega (`AlbaranLocalizador`) y sin estar sin registrar en otra salida abierta;
  - además: cargar pendientes, agregar por número de control, quitar, registrar, deshacer, «toda la sala», resumen, pendientes por ruta y última visita por sala.
- `SalidaEntregaController` y rutas `rutas.salidas.entregas.*` (con `rutas.gestionar`). Al crear una salida se cargan sus pendientes.
- Pantallas:
  - detalle de salida: resumen y CCF por sala en tarjetas para celular;
  - el aviso al finalizar indica cuántos quedan sin registrar;
  - columna «CCF entregados» en el listado de salidas;
  - «N CCF por entregar · $X» en cada tarjeta del tablero;
  - «Última visita» en las salas de cada ruta.
- Pruebas: `tests/Feature/Rutas/EntregasSalidaTest.php` (21).
- En local falta `php artisan migrate`: MySQL estaba apagado.

### Simplificación (27/09/2026, sin commit)

El usuario lo probó sobre una copia de producción y lo encontró complicado. Decidió:

- **salir de una vez**: la salida nace en curso;
- **sin responsable**;
- **aviso en el panel principal** para quien ve Rutas.

Además se corrigió un error: con dos vendedores, elegir responsable fallaba porque los ids del formulario llegaban como texto y se comparaban en modo estricto.

El módulo quedó en tres pantallas, más el aviso:

- **Rutas**: salidas en camino con su avance y una tarjeta por ruta («Faltan N días» / «N días tarde», barra, última salida, frecuencia, CCF por entregar) con «Salir a esta ruta» (elegir quiénes van y listo).
- **Hoja de la salida**: «¿Quién entrega?» una sola vez arriba; por CCF, Entregado / No entregado (el motivo se toca); «Todo entregado» por sala; «Terminar salida».
- **Configurar rutas**: nombre, frecuencia, lugares, «Asignar N salas sugeridas» y vendedores, todo en línea.
- **Panel principal** (`AvisoRutas`): rutas atrasadas o por tocar, con sus CCF. Nunca rompe el panel: sin permiso, sin tablas o ante un error devuelve vacío.

Facturación y Jefatura reciben también `rutas.personal.*` para poder dar de alta vendedores.

En desarrollo se probó sobre `base_ejemplo`, restaurada del respaldo de producción del 27/09 a las 2:30, con Gmail quitado, correo solo a log y firma y transmisión apagadas. Para volver: `DB_DATABASE=base_ejemplo`.

- Pendiente de datos reales, a cargo del usuario: cargar la cobertura de cada ruta y sus días objetivo.
- Rutas que describió el usuario el 27/09 (por confirmar contra las salas reales):
  - **Localidad A**: cobertura ilustrativa; completar desde Ajustes.
  - **Localidad B**: cobertura ilustrativa; completar desde Ajustes.
  - **Localidad C**: cobertura ilustrativa; completar desde Ajustes.
