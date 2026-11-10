# 0009. Reglas de cálculo de CCF y nota de crédito

## Estado

Aceptada

## Fecha

2026-11-10 (registra reglas de junio y julio de 2026 que ya están construidas)

## Contexto

Estas reglas se acordaron mientras se construía la facturación y solo estaban escritas en bitácoras de avance. Se dejan acá para que no dependan de esos archivos.

## Decisión

- **Precio aplicado:** el de la sala, luego el del cliente, luego el general (`PrecioProductoResolver`).
- **Descuento global:** es un porcentaje, con prioridad sala, cliente y cero. Se prorratea entre las líneas y el porcentaje aplicado queda guardado en el documento.
- **Retención de IVA 1 %:** automática, solo en CCF, solo si el cliente o la sala es agente de retención y la base gravada neta supera el umbral configurable (`dte.retencion_iva_umbral`).
- **Nota de crédito:** exige un CCF aceptado de verdad por Hacienda, y su gravado no supera el saldo de ese CCF (`SaldoMontoCcf`). Una nota solo generada no consume saldo, y las anuladas no cuentan.
- **Nota de crédito por modalidad:** devolución y faltante acreditan líneas del CCF; avería admite cualquier producto activo; pronto pago, descuento y ajuste son conceptos escritos a mano. El concepto manual usa la unidad «Otra» (99). Pronto pago no hereda descuento ni lleva retención.
- Solo el borrador se edita; el documento generado no cambia.
- La comparación de totales tolera un centavo. El validador de esquema trabaja con 8 decimales para no rechazar montos válidos.

## Consecuencias

Los montos de un documento se pueden explicar siempre con las mismas reglas. Cambiar una de ellas afecta a todos los documentos siguientes y necesita su propia decisión y sus pruebas.
