# Bandeja de Cobros: paginación y preparación de quedan — revisión (23/09/2026)

Claude Opus 5.5 implementó en desarrollo paginación real de la lista de documentos: 25 por página, orden estable por antigüedad e ID, y enlaces que conservan cliente y filtros. El antiguo corte `limit(500)` dejaba fuera los documentos posteriores. La pantalla distingue el rango y total **filtrado** del total **del cliente** que representan los contadores superiores; una página fuera de rango ofrece volver a la primera.

La columna «Quedan» indica si un CCF está listo para seleccionarse o muestra el primer motivo de bloqueo y enlaza a su ficha para el detalle. Una NC se identifica como ajena a ese formato y un CCF ya incluido muestra su solicitud. El botón se llama «Preparar archivo de quedan con lo marcado», y la pantalla aclara que la selección solo vale para la página actual y que preparar/descargar no equivale a presentar. Solo quien tiene permiso de gestión ve casillas y botón. La validación del servidor al crear la solicitud sigue vigente. No se añadió un filtro «Listos», ya que duplicar en SQL la regla de elegibilidad calculada por el modelo podría producir resultados contradictorios.

Codex revisó los cambios y corrigió con Claude una preparación defectuosa en una prueba: `pago_estado` no admite asignación masiva por diseño. La verificación final del código entregado fue **55 pruebas/388 aserciones correctas** en paginación, bandeja, solicitudes y pagos duplicados; Pint `--test` correcto. El registro del puente confirma `claude-opus-5-5`. No se ejecutaron migraciones ni acciones sobre producción.

## Ajustes y límites

- El 24/09 Claude cambió el fondo de revisión histórica a `bg-amber-50`, cuya equivalencia oscura existe en `resources/css/app.css`, y renombró el bloque a «Solicitudes de quedan», pues también contiene solicitudes sin presentar. Codex verificó el resultado con **20 pruebas/172 aserciones correctas**. Falta comprobación visual en navegador y móvil.
- La bandeja sigue siendo una tabla ancha con desplazamiento horizontal. Esta entrega resuelve el corte de 500 y la claridad de elegibilidad, no el rediseño completo de columnas ni la vista previa del XLSX antes de descargarlo.
- El historial de solicitudes mantiene su límite de 20; historial PPQ, formatos de NC y conciliación de pagos siguen como etapas separadas.

El primer intento del ajuste final terminó con `terminal_reason=api_error` por límite de sesión de Claude (`tmp/claude-colaboracion/20260923-184525-df407557-respuesta.json`). Tras el restablecimiento se completó en la misma SessionId `e4f53e43-f440-4a2e-b1a9-dd11860317ef`; registro: `tmp/claude-colaboracion/20260923-235951-316a6917-respuesta.json`.
