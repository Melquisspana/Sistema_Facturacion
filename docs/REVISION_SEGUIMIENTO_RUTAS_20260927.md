# Revisión de Seguimiento y plan de Rutas — 27/09/2026

Revisión del árbol local solicitado por el usuario. Seguimiento conserva el diseño elegido; el desarrollo pendiente de Rutas corresponde a Claude. Codex revisa y verifica. No se modificó código de aplicación, no se aplicaron migraciones en bases reales ni se hizo commit, push o despliegue.

## Seguimiento: estado observado

- Bandeja por mes y etapa: no entregados, entregados por presentar, en PPQ/presentados y pagados; orden por recencia y paginación.
- CCF con albarán, sala y NC locales relacionadas en la misma fila.
- Selección para crear un PPQ con CCF y NC locales; ficha con descarga de NC y quedan, y reporte del caso.
- TXT cargado desde Seguimiento que también concilia los PPQ afectados.
- Aviso cuando Gmail está habilitado pero desconectado.
- Importador de enviados con persistencia de CCF/NC externos y relaciones fiscales.

Los documentos anteriores de rediseño describen estados intermedios: el circuito principal actual crea PPQ desde Seguimiento. No deben usarse sus listas antiguas como inventario automático de pendientes.

## Dos hallazgos antes de declarar cierre técnico completo

1. **Pagos parciales y diferencias aparecen como pagados en la bandeja.** `CobrosController::aplicarEtapa()` incluye cualquier estado distinto de pendiente en «Pagados»; la vista usa la misma condición para la etiqueta. El modelo todavía distingue parcial, diferencia y pagado, y considera los dos primeros pendientes de cobro. El caso existente de `CobrosBandejaTest` crea un documento pagado y otro con 40 de 100 cobrados, espera dos pagados y exige que no se vea «Pago parcial». Por ello, una suite verde no descarta este hallazgo. Conservar el diseño, pero distinguir el resultado incompleto para que el operador vea la diferencia.

2. **Las NC externas de Conta no están integradas en la nueva selección de PPQ.** El importador conserva `cobro_documento_relaciones`, pero `notasPorCcf()` consulta únicamente DTE locales y `CrearPpqDesdeSeguimiento::crear()` omite la búsqueda de NC cuando el CCF no tiene `dte_id`. Un CCF externo entregado puede entrar al PPQ sin las NC externas ya relacionadas. Hace falta consumir esas relaciones o bloquear explícitamente el caso hasta resolverlo; no asumir ausencia de notas. Este es un hallazgo de código, no una afirmación sobre documentos reales afectados.

## Rutas: contraste con el plan acordado

Referencia vigente: [DISENO_MODULO_RUTAS_20260926.md](DISENO_MODULO_RUTAS_20260926.md).

| Etapa | Estado comprobado en código | Pendiente |
|---|---|---|
| 1. Base y cobertura | Implementada: área `/rutas`, retirada de custodia, cobertura por departamento/distrito, propuesta y confirmación de salas, frecuencia objetivo, personal y salidas básicas. | Configuración operativa de cobertura y frecuencia; comprobar asignaciones con datos actuales. |
| 2. Entregas | Hay salidas con participantes y cambios de estado; tablero por última salida y frecuencia. | CCF pendientes por salida, exclusión de otra salida activa, intentos, entregado/no entregado con motivo y vendedor, avería, confirmación automática por albarán, última visita por sala, cantidades/importes pendientes y salas sin visita en tablero. |
| 3. Vendedor | Existe la función de personal «Vendedor», pero no el rol de acceso `Vendedor`. | Rol, aislamiento por participante, pantalla móvil «Mi salida», permisos y pruebas de acceso. |
| 4. Ventas | No se encontró implementación de esta etapa en Rutas. | CCF menos NC sin IVA, dimensiones y comparación temporal, alertas de caída, atribución a vendedor o «sin vendedor». |

La siguiente entrega de Claude corresponde a la **etapa 2**. No confundir el tablero básico existente con el tablero completo de entregas. Los conteos de salas del plan son del 26/09 y no se volvieron a consultar en la base real en esta revisión.

## Verificación y límites

Regresión de Rutas, Cobros y navegación sobre SQLite en memoria, con el candado de base de pruebas del proyecto y bloqueo de HTTP real: **408 pruebas, 2.213 aserciones, 7 fallos**. Los siete fallos corresponden a `NavigationTest`; las **349 pruebas de Rutas y Cobros pasaron**.

Se repitió únicamente navegación para diagnosticar esos fallos: **59 pruebas, 345 aserciones, los mismos 7 fallos**. Tres expectativas conservan el rótulo «Pronto pago» y cuatro casos por rol esperan el inventario anterior de enlaces. Hay que alinear las pruebas con el menú aprobado «Cobros Calleja / Seguimiento de CCF / Historial de PPQ», comprobando que sus destinos y permisos sigan siendo correctos. No se debe revertir el diseño elegido solo para satisfacer las expectativas antiguas.

Orden ejecutada: `php vendor/phpunit/phpunit/phpunit tests/Feature/Rutas tests/Feature/Cobros tests/Feature/NavigationTest.php --colors=never` con PHP 8.3.30. La repetición diagnóstica de navegación guardó su JUnit en `tmp/revision-seguimiento-rutas-navigation.xml`.

Esta revisión no certifica Gmail conectado, datos históricos de producción, aceptación del portal de Calleja ni una inspección visual interactiva en navegador. No se convocó a Claude para esta revisión: se contrastó el plan guardado con la implementación local.
