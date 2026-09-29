# Revisión de Codex: entrega 01 de invalidación

Fecha: 20 de septiembre de 2026. Revisión del working tree; no se modificó código de aplicación.

## Resultado

La separación entre política, requisitos y verificación del sustituto resuelve el defecto principal del encargo. Antes de aprobar esta entrega quedan las dos correcciones siguientes.

### R1. Limitar la prohibición por NC/ND al tipo documental respaldado

Ubicación: `app/Services/Dte/ValidadorReglasInvalidacion.php:81`, consumidores de `notasVigentes()` y `app/Models/Dte.php:392`.

Actualmente cualquier documento que tenga una relación con una NC/ND aceptada queda bloqueado, incluyendo FE, FEX o una NC. La fuente revisada y el encargo establecen esta prohibición para CCF. La capacidad del modelo de representar más relaciones no acredita una prohibición fiscal adicional. Para FE/FEX el manual contempla además relaciones con eventos de retorno, que requieren su propia política cuando se implementen.

Corrección solicitada: decidir en la política/validador si esa dependencia impide invalidar el tipo concreto y hacer que UI, serializador y servicio consuman esa decisión. No volver a habilitar el override del CCF. Se puede conservar una relación genérica `notasFiscalesVigentes()`; listar relaciones y decidir que prohíben invalidar son responsabilidades distintas.

Pruebas: CCF con NC/ND vigente sigue bloqueado incluso con flags antiguos; FE/FEX/NC no heredan esta prohibición solamente por tener una relación representable en el modelo. Mantener las demás restricciones y no afirmar que esto habilita nuevas clases de notas.

### R2. Validar el tipo de entrada antes de convertirlo a cadena

Ubicaciones: `app/Http/Requests/Dte/TransmitirInvalidacionRequest.php:50` y `app/Http/Controllers/Facturacion/DteController.php:1356`.

`prepareForValidation()` convierte `reemplazo` y `motivo` mediante `(string)` antes de que actúe la regla `string`. Un POST con `reemplazo[]=x` o `motivo[]=x` produce `Array to string conversion`; bajo el manejador de errores de Laravel termina en excepción en lugar de rechazo de validación. La misma conversión existe en el controlador usado por mock/dry-run. Además, convertir booleanos o números antes de validar puede ocultar el tipo recibido.

Reproducción independiente: se creó cada Form Request con un arreglo en uno de los campos y se invocó su normalización mediante reflexión, usando un manejador que convierte warnings a `ErrorException`. Ambos casos fallaron en las líneas 50 y 51. No hubo base de datos ni HTTP en esa reproducción.

Corrección solicitada: normalizar solo valores que sean cadenas; conservar los demás tipos para que el validador los rechace. Aplicar el mismo criterio a real, mock y dry-run. Evitar cambiar la matriz fiscal para resolver este error de entrada.

Pruebas: POST autorizado con arreglos en ambos campos debe producir errores de validación (422 para JSON, o redirección con errores para formulario), sin 500, firma, transmisión ni escritura de evidencia. Conservar vacío/espacios a null y normalización de UUID válido.

## Resolución de las tres consultas de Claude

1. **Mismo tipo del sustituto:** mantener por ahora la limitación documentada, acorde con la matriz encargada. No autorizar cruces FE/CCF por intuición; requieren fuente y casos de prueba. Esto no constituye certificación de que todos los casos fiscales posibles estén cubiertos.
2. **Bloqueo extendido a todos los documentos:** corregir conforme a R1; “conservador” no basta para convertirlo en regla del MH.
3. **Pint:** no bloquear esta revisión por formato preexistente ni formatear archivos ajenos. Si se verifica formato, acotarlo a los archivos de la entrega y distinguir fallos existentes de nuevos.

## Límites de la revisión

- Codex volvió a ejecutar las diez suites de invalidación indicadas en la entrega: **OK, 205 tests y 1015 assertions**, 1 min 32.857 s, sin deprecaciones reportadas. Se verificó el candado previo a migraciones que exige SQLite en memoria y el bloqueo de HTTP real de `Tests/TestCase`. Estas suites no cubrieron la entrada no escalar reproducida en R2.
- Los 5828 tests y las seis deprecaciones de la suite completa son resultados reportados por Claude; Codex no repitió esa suite de una hora.
- Las pruebas de la UI inspeccionadas verifican HTML/expresiones renderizadas; no equivalen a una ejecución del asistente en navegador. No afirmar QA visual/interactiva a partir de ellas.
- El aviso de obsolescencia está implementado en consola. El campo HTTP antiguo se acepta e ignora, pero no se encontró aviso equivalente en esa ruta; corregir la descripción de la entrega si pretende afirmar que ambas vías avisan.
- Plazos, datos históricos del receptor y aceptación real ante MH siguen pendientes. También registrar que `EmisorDte` compara el NIT de la empresa actual: el cierre de identidad histórica deberá incluir emisor y receptor, no solo cliente.

Después de R1/R2, entregar el diff acotado y las pruebas afectadas para revisión. No cambiar producción ni transmitir documentos reales.
