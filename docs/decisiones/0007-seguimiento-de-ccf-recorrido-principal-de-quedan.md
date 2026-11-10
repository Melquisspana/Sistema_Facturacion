# 0007. Seguimiento de CCF es el recorrido principal de los quedan

## Estado

Aceptada

## Fecha

2026-11-10 (registra una decisión de septiembre de 2026 que ya está construida)

## Contexto

Había dos caminos para pedirle el pago de los CCF a un cliente con portal de proveedores: el lote PPQ armado a mano y la bandeja de Cobros. Las notas de crédito se presentaban aparte, y una factura podía subirse sin sus notas. El cliente no paga un CCF presentado sin ellas.

## Decisión

- **«Seguimiento de CCF»** (Cobros) es la pantalla principal para los quedan nuevos. El PPQ anterior queda como historial. «PPQ», «pronto pago» y «quedan» nombran el mismo trámite. Lo más reciente va primero.
- En el portal se sube **primero** el archivo de notas de crédito (AC02/AC04) y **después** el de quedan (AC01). La carga real de las notas se registra antes de habilitar el quedan de esos CCF.
- Una factura con notas no se presenta sola. La vista previa muestra las notas de cada CCF por su vínculo fiscal explícito. La solicitud guarda qué notas y qué lote la respaldaron, y rechaza confirmar si aparecieron o cambiaron notas.
- Un CCF externo sin relación verificable queda bloqueado: no se declara «sin notas» por omisión. No hallar una nota en una búsqueda limitada no prueba que no exista.
- Los CCF y las notas del sistema anterior salen de los enviados de la misma cuenta de correo, con su JSON. `cobros:auditar-enviados` solo cuenta y avisa, sin duplicar por código de generación; no importa nada.
- Si la sala de la orden de compra y la del albarán se contradicen, no se renumera el albarán ni se reescribe el DTE: queda como excepción a la vista.

## Consecuencias

Hay un solo lugar para armar un quedan y las notas ya no se pueden olvidar. A cambio, un CCF con una nota sin resolver se queda sin presentar hasta que alguien la revise. El detalle del circuito está en [`docs/COBROS_CALLEJA.md`](../COBROS_CALLEJA.md).
