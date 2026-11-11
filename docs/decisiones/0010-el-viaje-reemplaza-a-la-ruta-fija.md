# 0010. El viaje reemplaza a la ruta fija

## Estado

Aceptada.

## Fecha

2026-11-10

## Contexto

Rutas nació con la idea de que cada sala pertenece a una ruta fija y que la oficina arma cada salida: a qué ruta, qué día, quiénes van y para qué (entregar, sacar pedido o las dos). Una revisión de solo lectura de los datos reales mostró que eso no describe el trabajo:

- Un viaje real dura varios días seguidos, cubre unos cuatro departamentos vecinos y mezcla salas de varias rutas configuradas.
- Casi ninguna entrega se marcaba, porque para marcar había que armar antes la salida.
- Las salas se visitan cada unos 29 días; las rutas configuradas decían 10, 15 o 30.

Había tres caminos: seguir con rutas fijas y pedir más disciplina, dejar que el vendedor arme su propia salida, o que el sistema la arme solo con lo que el vendedor ya hace.

## Decisión

El **viaje** se arma solo y reemplaza a la salida armada a mano.

- El viaje se abre con el primer pedido que saca el vendedor. Los pedidos de los días seguidos en la misma zona, o en una vecina, se suman al mismo viaje.
- El viaje no termina por fecha. Se cierra solo cuando ya no suma pedidos y todos sus pedidos están facturados y entregados. La entrega la marca cualquier vendedor o la confirma el albarán del supermercado.
- Nadie arma salidas, ni las deja programadas, ni elige su propósito, ni carga los pendientes de una ruta, ni asigna salas a una ruta. La frecuencia objetivo ya no se carga ni se edita: el ritmo sale de los pedidos reales de cada sala. El valor que una zona ya tuviera queda solo de respaldo, mientras no tenga ritmo propio.
- Las rutas quedan como **catálogo de zonas**: un nombre y los lugares que cubre (departamentos y distritos). La zona le pone nombre al viaje («Lunes · San Miguel») y agrupa el tablero y el mapa. La zona de una sala es la ruta que ya tenía o, si no tiene, la que cubre su lugar.
- El dinero no se liquida en el sistema. El vendedor entrega el efectivo, los cheques y los quedan con el PDF del día, que firman quien entrega y quien recibe. Cada día del viaje tiene su PDF: el vendedor abre los suyos y la oficina los de cualquier viaje. Por eso el vendedor solo deshace lo que marcó hoy; lo de días anteriores lo deshace la oficina (revisión del 11/11/2026).
- El viaje es de quien lo abrió. Quien entrega un CCF de ese viaje queda como acompañante: no lo ve como suyo ni lo corta.
- El viaje se guarda en las mismas tablas de la salida (`salidas_ruta` y `salida_ruta_entregas`), marcado como automático. Cobros sigue leyendo las entregas sin cambios.

## Consecuencias

- El vendedor usa tres pantallas: Inicio, Sacar pedido y Por entregar. La oficina mira los viajes en el tablero y no arma nada.
- Las salidas armadas a mano que ya existen quedan como historial en «Todos los viajes». Las que siguen abiertas se pueden terminar como antes; no se crean nuevas.
- No se borran tablas ni columnas. `rutas.frecuencia_objetivo_dias`, `cliente_sucursales.ruta_id` y el propósito de la salida se conservan con sus datos, sin pantalla que los edite. Volver atrás es reponer pantallas, no recuperar datos.
- Una sala sin zona (ninguna ruta cubre su lugar) no bloquea nada: su pedido y su entrega van a un viaje sin zona. El tablero avisa cuántas salas están así para que se agregue la cobertura.
- Se pierde el control de caja por salida (cierre del día y liquidación) para lo nuevo. Lo reemplaza el PDF firmado, fuera del sistema. Si hiciera falta volver a controlarlo adentro, habría que diseñarlo sobre el viaje.
- Los permisos para armar salidas dejan de tener uso. El de terminar salidas se conserva mientras quede alguna salida vieja abierta.

## Referencias

- [Plan del tablero de Rutas](../PLAN_TABLERO_RUTAS.md), sección «El flujo único (10/11/2026)».
- [0007. Seguimiento de CCF es el recorrido principal de los quedan](0007-seguimiento-de-ccf-recorrido-principal-de-quedan.md).
