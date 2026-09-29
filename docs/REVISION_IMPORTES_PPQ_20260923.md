# Revisión de importes AC04/AC02 y diferencia en PPQ — 23/09/2026

Alcance: desarrollo local, sin producción ni documentos reales. Codex coordinó y revisó; Claude Opus 5.5 diagnosticó e implementó por el puente local con SessionId `e4f53e43-f440-4a2e-b1a9-dd11860317ef`. El modelo efectivo figura como `claude-opus-5-5` en los registros `tmp/claude-colaboracion/20260923-173428-992a2289-respuesta.json`, `20260923-173830-093afb7d-respuesta.json` y `20260923-174145-81781df3-respuesta.json`.

## Diagnóstico contrastado

- AC02 (avería) toma el descuento del CCF; AC04 (devolución) debe ir sin descuento cuando el perfil del cliente lo establece. Las pruebas de `NcDevolucionFaltanteAlbaranTest` y `NcPerfilDescuentoAlbaranTest` fijan esos casos. Las 55 pruebas focalizadas de NC y conciliación de importes pasaron con 404 aserciones. No se encontró un defecto de cálculo de emisión en esos recorridos.
- La consulta de solo lectura `perfil-documento:cliente 10` confirmó en esta base **local** el perfil activo de Calleja: AC02 hereda descuento del CCF y AC04 no aplica descuento. Esto no afirma nada sobre el perfil ni los importes de producción. Sin perfil activo o mapeo de modalidad, `DteBorradorService` conserva una regla histórica que puede heredar el descuento también para AC04; verificar el perfil del entorno correspondiente antes de atribuirle un importe real al código.
- El CCF se compara con su albarán de entrega; la NC con su propio albarán de crédito. El total fiscal de la NC puede diferir del albarán por descuento o retención. PPQ mantiene montos distintos para DTE y albarán. El Excel del formato de NC no se modificó.
- Había una inconsistencia verificable en PPQ: el Excel de lote escribía los montos D (albarán) y G (CCF/NC) negativos para NC, pero J (`diferencia`) sin signo. Por ello J no era G−D en las NC y una suma de J de un lote mixto completamente comparable no coincidía con la diferencia neta del lote. La ficha del lote mostraba el mismo desacuerdo visual y etiquetaba una NC con diferencia como «Posible NC/devolución».

## Entrega acotada de Claude, revisada por Codex

- `PpqItem::diferenciaConSigno()` calcula una diferencia derivada con la misma convención de signos de D y G; devuelve `null` si falta un monto de albarán comparable. La columna `diferencia` persistida, que usan clasificación y tolerancia, sigue intacta.
- El Excel PPQ usa esa diferencia en J; la ficha de lote la muestra con signo y da a la NC con diferencia el texto «Difiere del albarán de crédito». El CCF conserva su clasificación y textos. La ficha de búsqueda de NC local no se cambió porque no autovincula un albarán por OC.
- La prueba nueva cubre un lote CCF+NC con D, G y J correctos, suma de J igual al total del lote cuando todos tienen monto de albarán, J vacío sin albarán y etiqueta de NC en la ficha. Pasaron 67 pruebas y 276 aserciones al ejecutar la prueba nueva junto con `PpqModuloTest` y `ConciliacionNoDestructivaTest`. Tras un ajuste de estilo de Claude, la prueba nueva pasó Pint `--test` y sus 3 pruebas/16 aserciones pasaron de nuevo.
- Pint `--test` de `PpqItem.php` y `ExcelCallejaExporter.php` todavía informa reglas de estilo que ya fallaban en copias de `HEAD` antes de esta entrega; no se reformatearon archivos completos por ese motivo.

Límite: J queda vacío para un ítem sin albarán, mientras `PpqLote::diferenciaTotal()` incluye su DTE. Por tanto la suma de J coincide con ese total solo en lotes donde todos los ítems tienen monto de albarán. El encabezado J del formato PPQ conserva el texto histórico «Diferencia (CCF − albarán)» por compatibilidad; se deberá confirmar cualquier cambio de plantilla con quien recibe el archivo.

## Pendiente del plan

No está cerrado el módulo: faltan diagnóstico de vínculos históricos con evidencia, bandeja y preparación de quedan con sus estados separados, orden y paginación de Cobros/PPQ/formatos NC, redescarga e historial por lote, y conciliación de TXT/evidencias de pago sin duplicar ni inferir deuda de una ausencia. Próxima entrega sugerida: rastrear con casos verificables los vínculos y albaranes históricos antes de definir las bandejas. Producción, respaldos, migraciones, commit, push y despliegue quedaron fuera de esta entrega.
