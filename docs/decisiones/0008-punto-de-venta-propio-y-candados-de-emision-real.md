# 0008. Punto de venta propio y candados de emisión real

## Estado

Aceptada

## Fecha

2026-11-10 (registra decisiones de julio de 2026 que ya están construidas)

## Contexto

El sistema empezó a emitir en producción mientras el sistema de facturación anterior seguía en uso. Los dos no pueden compartir correlativos, y un envío real a Hacienda no se puede deshacer con un clic.

## Decisión

- El sistema emite por un **punto de venta propio**, con correlativos por tipo de documento y por ambiente, separados de los del sistema anterior. Se fija con `DTE_PUNTO_VENTA_PREDETERMINADO` y nunca vuelve en silencio a otro punto de venta: si el código no existe o está inactivo, se detiene con un aviso (`App\Support\Dte\ResuelveEmisorUnico`).
- Toda emisión real exige escribir la frase `EMITIR PRODUCCION`, y el control previo exige que la cola esté activa.
- Factura, CCF y Factura de Exportación tienen los mismos candados, cada tipo con su control previo propio (`PreflightEmisionProduccion*`).
- Invalidar en producción tiene dirección y llave propias (`DTE_PROD_ANULACION_URL`, `DTE_INVALIDACION_PRODUCCION_ENABLED`).
- Los documentos configurados como evidencia no se invalidan ni se modifican (`Dte::estaProtegidoComoEvidencia()`).
- En la Factura de Exportación el recinto fiscal por defecto es 01; otro valor del catálogo vale si se elige, y siempre se transmite el valor guardado en el documento.
- En la Factura (01) los montos gravados viajan con IVA incluido.

## Consecuencias

Los dos sistemas pueden convivir sin pisarse los números. Emitir lleva un paso más, a propósito. Cambiar de punto de venta es una decisión de configuración y deja rastro; no pasa sola.
