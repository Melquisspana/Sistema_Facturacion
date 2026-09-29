# Primera entrega: datos propios de NC en PPQ

Encargo autorizado por el usuario: Claude implementa en desarrollo; Codex coordina, revisa y ejecuta comprobaciones. Sin producción, migraciones de bases reales, commit, push ni despliegue.

## Sesión y modelo

SessionId: `e4f53e43-f440-4a2e-b1a9-dd11860317ef`.

La primera implementación quedó registrada el 22/09 en `tmp/claude-colaboracion/20260922-063206-7abfb15d-respuesta.json`, con modelo `claude-opus-5`. El 23/09 el usuario pidió continuar con Opus 5.5. Se solicita mediante `ANTHROPIC_MODEL=claude-opus-5-5` solo en el proceso de invocación del puente. `modelUsage` confirmó **claude-opus-5-5** en ambas correcciones: `20260923-114045-655ae16d-respuesta.json` y `20260923-114945-460c1ef6-respuesta.json`, en el mismo directorio de registros. No se modificó la configuración global de Claude.

El usuario confirmó que su otra instancia de Claude no está editando Cobros/PPQ.

## Revisión de la primera versión

- La búsqueda precarga el albarán propio y el CCF explícitamente relacionado. La ficha distingue vínculo guardado de sugerencia por OC.
- El alta toma número canónico, fecha y total del albarán guardado en la NC desde el servidor, evitando la recaptura. No usa el vínculo auxiliar por correlativo suelto, que puede confundir tipos.
- Pendiente corregir: un albarán PPQ existente con el mismo número/OC y monto o fecha distintos no debe sustituir silenciosamente los datos propios. Hay que rechazar conflictos sin sobrescribir evidencia.
- Pendiente corregir: la vista oculta campos ante datos incompletos y no comparte el criterio del servidor. Debe conservar datos presentes, permitir completar faltantes operativos y detectar identidad inválida, sin modificar DTE emitidos.
- Pendiente corregir: validar identidad completa de un albarán seleccionado y evitar escrituras parciales cuando falla el alta.
- La ficha sí recibe tema oscuro mediante overrides globales de `resources/css/app.css`; la afirmación inicial de Claude de que era exclusivamente clara fue corregida.

## Validación inicial de Codex

PHP 8.3.30, PHPUnit 11.5.55, SQLite `:memory:` con el candado de seguridad de `Tests\TestCase` y HTTP real bloqueado.

Se ejecutaron `NcDatosPropiosPpqTest`, `PpqBusquedaLocalPrimeroTest`, `PpqElegibilidadItemTest` y `PpqIdentidadItemTest`: **52 pruebas, 258 aserciones, todas correctas**. Son resultados de la primera versión, no certifican todavía las correcciones pedidas a Opus 5.5.

## Estado

Correcciones recibidas y contrastadas. El servicio nuevo `app/Services/Ppq/AlbaranPropioNc.php` comparte el criterio de albarán completo, parcial, inválido o ausente. La ficha pide solo faltantes. El servidor conserva los datos guardados, rechaza identidades y evidencias contradictorias, y guarda albarán/item en una transacción. La última corrección completa una fecha vacía en un registro PPQ compatible y calcula los mensajes sobre los datos efectivamente persistidos. Ninguna de esas operaciones edita la NC emitida.

La primera revisión con Opus 5.5 pasó **65 pruebas y 323 aserciones**. La corrida final, con tres casos más para fecha y rollback, pasó **68 pruebas y 337 aserciones** (24 pruebas nuevas y 44 de regresión), en 2:16.138 y 100 MB. Pint en modo `--test` pasó sobre los cuatro archivos PHP de la entrega; no se ejecutó formateo global. `git diff --check` no encontró errores de espacios en los archivos existentes de la entrega.

Revisión visual de Codex: seis renders de la plantilla real `ppq.partials.resultado`, con datos ficticios, cubrieron completo/parcial/inválido en claro y oscuro. Se inspeccionaron en el navegador a 1280 × 720 usando el CSS compilado existente; la información nueva y los controles se ven legibles. Son renders aislados, no una prueba de navegación con una cuenta y documentos reales. Los artefactos temporales están en `tmp/claude-colaboracion/qa-nc`. El servidor temporal y la pestaña de revisión se cerraron.

Alcance de archivos: cuatro archivos existentes (servicio de búsqueda, controlador de items y dos parciales), servicio nuevo y prueba nueva `tests/Feature/Ppq/NcDatosPropiosPpqTest.php`. La comparación SHA256 contra el inventario previo no detectó otras modificaciones en archivos preexistentes, incluido firmware. Documentación de asistencia actualizada por Codex.

Límites conservados: la opción explícita previa de agregar sin albarán continúa; NC sin datos guardados mantienen la captura manual. No se auditó integralmente esa ruta manual ni la concurrencia entre solicitudes simultáneas. La revisión visual no cubre todo el rediseño ni todos los tamaños de pantalla. No se modificaron formatos de NC ni se probaron cargas en el portal del cliente.

El alcance completo del módulo continúa en `docs/RELEVO_COBROS_PPQ_NC.md`.

Primera entrega terminada y validada en desarrollo. Queda comprobado el ciclo del puente: encargo de implementación, cambios de Claude, revisión de Codex, dos rondas de corrección en la misma sesión y pruebas finales. El módulo completo no está terminado ni desplegado.
