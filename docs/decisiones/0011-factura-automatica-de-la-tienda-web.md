# 0011. Factura automática de la tienda web

## Estado

Aceptada

## Fecha

2026-11-16 (decisión de Melqui; complementa la 0008, no la reemplaza)

## Contexto

La tienda web (dulceslanegrita.com) cobra con tarjeta a consumidores finales. El sistema trae cada minuto los pedidos pagados (`tienda:traer-pedidos`, etapa 1) y los deja en la fábrica como pedidos «web».

La 0008 exige, para toda emisión real, la frase `EMITIR PRODUCCION`, una casilla de revisión y el control previo de cada tipo. Eso está pensado para documentos que arma una persona en la oficina. Un pedido web ya está pagado y el cliente espera su factura sin que nadie la toque, y Melqui pidió que salga sola desde el primer día.

## Decisión

- **Solo para los pedidos de la tienda web**, la Factura (01) se emite sin la frase, sin la casilla y sin el control previo de la 0008. Todo lo demás de la 0008 sigue igual: el punto de venta propio, los correlativos y los candados del entorno (`DteTransmisionService::evaluarCandados`: transmisión habilitada, sin `dry_run`, confirmación real y producción permitida). Si el entorno no deja transmitir de verdad, la factura automática tampoco sale.
- **Candados propios de la emisión automática.** La factura sale sola **solo si se cumplen todos**:
  1. El pedido web está `importado`, tiene su pedido de fábrica y todavía no tiene factura.
  2. La pasarela no es la de demostración y el pedido no trae alerta (`pago_doble` u otra).
  3. La suma de `cantidad × precio` de las líneas, en centavos con IVA, es igual al `total` del pedido, y cada precio es mayor que cero.
  4. Todos los productos se encontraron por código de barras (ya lo exige la etapa 1).
  5. El cliente tiene nombre y un correo válido.

  Si alguno falla, **no se emite nada**: el pedido queda `para_revisar` con su motivo y el detector de alertas avisa por correo.
- **El mismo camino que la oficina.** Se crea el borrador con `DteBorradorService`, con el emisor de `ResuelveEmisorUnico::resolver(null, null)`, condición de operación **contado (1)** y forma de pago tarjeta. Cada línea lleva el precio con IVA que pagó el cliente (`precioOverride`). Después se genera (`DteGeneracionService::generar`), se firma (`DteFirmaService`) y se transmite con la política oficial (`DteTransmisionResiliente` más `ContingenciaAutomaticaService`), todo bajo `CandadoEmisionDte`. No hay una segunda forma de transmitir.
- **Sin usuario.** La emisión queda sin persona responsable (`created_by` y los demás vacíos), y en la auditoría figura `origen: tienda_web`. Nunca se inventa un usuario.
- **Receptor.** La factura lleva el nombre y el correo del cliente como consumidor final, guardados en el propio documento (`dtes.receptor_nombre` y `dtes.receptor_correo`), sin crear un `Cliente`. Sin documento de identidad y sin dirección.
- **Una sola factura por pedido.** El pedido web guarda su `dte_id` dentro de la misma transacción que crea el borrador, con la fila bloqueada. Si la pasada se cae, la siguiente retoma ese mismo documento; nunca crea otro.
- **Si Hacienda rechaza**, el pedido queda `para_revisar` con el motivo, y la oficina lo corrige como cualquier rechazo. Si queda en contingencia o con el estado de recepción incierto, sigue esperando y lo retoman la pasada siguiente o las tareas de contingencia de siempre.
- **Correo y aviso a la página.** Cuando Hacienda la acepta, la factura se manda al correo del cliente (`EnvioDteCorreoService`, en español) y se le avisa a la página (`factura: {numero_control, codigo_generacion, fecha}`). Los dos pasos se reintentan en la pasada siguiente si fallan, y ninguno se repite si ya salió.
- **Interruptor.** Todo corre dentro de `tienda:traer-pedidos`, que solo funciona con `tienda.pedidos.automatica` encendido.

## Consecuencias

La venta web se factura sin que nadie intervenga, con los mismos números, firma y reintentos que la oficina. A cambio, los candados de esta regla son la única barrera antes de Hacienda, así que cada uno tiene su prueba y cualquier duda se resuelve con «no emitir y avisar». Si mañana se quiere quitar algún candado, se cambia esta decisión, no el código a escondidas.
