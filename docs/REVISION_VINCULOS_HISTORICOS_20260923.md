# Reutilización conservadora de vínculos PPQ históricos en Cobros — 23/09/2026

Alcance: código y pruebas de desarrollo. Codex diagnosticó, encargó y revisó; Claude Opus 5.5 implementó mediante el puente local, conservando SessionId `e4f53e43-f440-4a2e-b1a9-dd11860317ef`. El modelo efectivo figura como `claude-opus-5-5` en los registros de `tmp/claude-colaboracion/20260923-174659-c8b54094-respuesta.json`, `20260923-174946-479c3c8a-respuesta.json`, `20260923-175439-4995ffc8-respuesta.json`, `20260923-175835-de737862-respuesta.json`, `20260923-180103-28ab1093-respuesta.json` y `20260923-180327-16713c59-respuesta.json`. Claude no recibió credenciales ni datos de producción.

## Hallazgo

`AltaCobrosService` detectaba que un CCF había aparecido en un lote PPQ y lo marcaba para revisión histórica, pero `VinculadorAlbaranes::auditar()` no consultaba el `ppq_albaran_id` que ese item había guardado. Su búsqueda por `PpqAlbaran.dte_id` o por orden de compra podía dejar «sin albarán» o «revisar» un CCF cuyo albarán AC01 ya constaba en PPQ. Un item de PPQ no prueba, por sí solo, presentación ni pago y pudo recibir el albarán prellenado; no se trató como una decisión humana infalible.

## Cambio acotado de Claude

- En `app/Services/Cobros/VinculadorAlbaranes.php`, después del vínculo explícito al DTE y antes de la búsqueda por OC, se consulta la evidencia del item PPQ para CCF local con DTE, control y OC. Exige item tipo 03, no marcado sin albarán, albarán activo de entrega, OC coherente entre DTE/item/albarán e identidad completa. Para un snapshot de Gmail sin `dte_id`, el control normalizado necesita además código de generación o sello coincidente; los identificadores poblados que contradicen al DTE mandan a revisión.
- Varios albaranes para el mismo CCF, un albarán reclamado por otro item/documento, OC contradictoria, importe/sala/cliente/periodo incompatibles o varios CCF con la misma OC siguen en revisión. Un tipo AC02/AC04 o desconocido no respalda la entrega. Un item sin OC, tipo o segunda identidad suficiente no crea un vínculo nuevo. La prioridad explícita tampoco tapa una contradicción positiva del historial.
- `aplicar()` mantiene la verificación bajo bloqueo, guarda el motivo original de la decisión cuando el vínculo se confirma y conserva una referencia breve y rastreable a los lotes. Limita a 255 caracteres únicamente el motivo persistido cuando es demasiado largo; la auditoría en seco conserva el texto completo y los candidatos no se recortan.
- No se modifica el DTE, el albarán, la presentación, el pago ni el bloqueo de revisión histórica. No hay migración ni cambios de interfaz. La vinculación automática no se activó.

## Verificación y límite de evidencia

La prueba nueva `tests/Feature/Cobros/CobrosVinculoHistoricoPpqTest.php` cubre recuperación frente a dos AC01 de una OC, snapshot con segundo identificador, colisiones, OC/tipo/importe/sala contradictorios, preservación de estados y motivos largos. Junto con `CobrosSolicitudTest`, `CobrosProteccionesProduccionTest` y `CobrosAltaTest`, pasaron **65 pruebas y 280 aserciones**. Pint `--test` pasó para los dos archivos tocados. No se ejecutó la suite global ni se hizo una prueba de navegador para esta entrega de lógica.

El ensayo de solo lectura `cobros:sincronizar --cliente=10 --vincular`, en entorno `local` con MySQL en `127.0.0.1` y sin `--aplicar`, mostró 14 documentos que entrarían nuevos, cuatro con antecedente PPQ y **cero documentos ya existentes para auditar**. Por eso no demuestra cuántos vínculos históricos reales recuperaría esta copia. No se incorporaron esos documentos ni se aplicó ningún vínculo. Antes de cualquier uso sobre datos reales se necesita auditar en seco la población correspondiente, revisar candidatos y contradicciones, y mantener separada la revisión histórica de las decisiones de presentación y pago.

Queda pendiente el resto del módulo: preparación de quedan con su plantilla, organización y paginación de Cobros/PPQ/formatos NC, historial de envíos y redescarga, y conciliación de TXT/evidencias sin duplicar pagos ni inferir deuda por ausencia. Producción, respaldos, commit, push y despliegue no se tocaron.
