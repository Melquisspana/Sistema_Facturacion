# Revisión de TXT de Calleja — ejemplo anonimizado

La revisión histórica comprobó las seis columnas del TXT y los tipos CF, NC y QD. Se retiraron de esta nota los importes, identificadores y detalles de operaciones reales.

El fixture `tests/Fixtures/Ppq/pagos-000123-20260907.txt` es ficticio: contiene 45 CF de 100.00, doce NC de -5.00, una NC escrita como `-.50` y un QD de -25.00. Totales ilustrativos: CF 4500.00; NC -60.50; QD -25.00; neto 4414.50. Conserva la regresión del decimal sin cero entero y las pruebas de idempotencia, documentos ausentes y ajustes globales. No representa un archivo enviado por el cliente ni es idéntico a un original operativo.

El QD es un ajuste global: no se resta otra vez de cada documento ni equivale por sí mismo a una NC fiscal emitida. Los documentos ausentes del archivo conservan su estado.

PPQ y Cobros validan el código contra `PPQ_CODIGO_PROVEEDOR`. Sin configuración, o si cualquier fila trae otro código o lo deja vacío, el archivo entero se rechaza antes de registrar pagos. El valor `000123` pertenece exclusivamente a los ejemplos y pruebas.

El TXT informa la fecha del documento, no la fecha real del pago. La interfaz mantiene esa distinción.

Las comprobaciones históricas documentadas no certifican este cambio de seguridad; consultar el informe de validación de esta entrega.
