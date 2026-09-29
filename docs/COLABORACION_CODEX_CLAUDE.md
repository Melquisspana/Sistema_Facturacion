# Colaboracion local entre Codex y Claude

## Roles desde el 27/09/2026

Claude coordina y hace lo importante; Codex desarrolla. Ver AGENTS.md. Desde Claude Code, el puente inverso es `scripts/windows/Invoke-CodexCollaborator.ps1`:

```powershell
$preparado = ./scripts/windows/Invoke-CodexCollaborator.ps1 -PromptFile ./tmp/encargo-codex.txt -Modo Desarrollo -SoloPreparar
./scripts/windows/Invoke-CodexCollaborator.ps1 -PromptFile ./tmp/encargo-codex.txt -Modo Desarrollo -InstruccionPreparada $preparado.Instruccion | Format-List
```

- Usa el CLI incluido en la app de Codex (Microsoft Store), la sesion iniciada y el modelo de `~/.codex/config.toml` salvo `-Modelo`.
- Consulta = aislamiento `read-only`; Desarrollo = `workspace-write`. Aprobaciones en `never`: si algo requiere permiso, Codex informa el bloqueo.
- A diferencia de Claude por su puente, Codex si tiene shell: puede correr pruebas puntuales y Pint sobre lo tocado. La suite completa la corre Claude al revisar (nunca dos corridas a la vez).
- Devuelve `SessionId` (id del hilo), `Respuesta`, `Uso` (tokens de esa llamada) y `Registro`. Continuar con `-SessionId`.
- Verificado el 27/09/2026: consulta nueva y continuacion en el mismo hilo.

Lo que sigue describe el reparto anterior (Codex coordinando y llamando a Claude), que sigue disponible cuando el usuario trabaja desde la app de Codex.

Codex asiste al usuario: analiza, delimita, documenta, prueba y revisa. Claude es el desarrollador principal. El 24/09/2026 el usuario autorizo tambien a Codex a programar cuando sea util o necesario dentro de un encargo vigente, especialmente para arreglos acotados. Los dos colaboran en la misma tarea sin exigir que el usuario copie mensajes. No se comparten automaticamente las conversaciones: cada encargo a Claude debe incluir el contexto necesario.

No consultar a Claude para cada diagnostico, explicacion, documento o prueba que Codex pueda resolver. Cuando se necesite desarrollo, acotar el encargo para que Claude no vuelva a analizar todo el modulo ni repita el trabajo de revision de Codex. Mostrar en la conversacion, **antes de invocar el puente**, el modelo elegido y un enlace al archivo con la instruccion **completa**, incluidas las reglas fijas del puente. Despues informar el modelo efectivo y el consumo que devuelva el puente, junto con los cambios y pruebas. No hay instrucciones ocultas al usuario.

Control en esta misma tarea: antes de cada envio indicar modelo, motivo, archivos previstos y el encargo exacto; despues indicar archivos realmente cambiados, pruebas y los campos `usage` de **esa llamada** (entrada, salida, cache leida y creada). Los campos `modelUsage` y `total_cost_usd` del JSON se acumulan al reutilizar la SessionId: no tratarlos como consumo de una sola llamada. `total_cost_usd` es una referencia a precio de lista de API, no necesariamente un cargo a la suscripcion del usuario. El puente no informa el porcentaje restante de cuota de Claude; una respuesta 429 solo indica el limite y, si viene indicado, su hora de restablecimiento. Los limites de Codex se pueden consultar aparte desde la app y son compartidos por la cuenta, no exclusivos de esta tarea.

Seleccion orientativa para Claude: Haiku 4.5 en cambios mecanicos, Sonnet 5 en cambios delimitados y Opus 5.5 en reglas complejas o cuando se espere que una mayor tasa de acierto evite rondas. Opus 5.5 es mas barato que Opus 5, aunque por token cuesta el doble que Sonnet 5 en la API; medir resultados por tarea terminada y no usar un solo modelo por inercia. La disponibilidad y los limites dependen de la cuenta; verificar el modelo efectivo. Para Codex, el usuario puede escoger un modelo mas ligero o potente segun la tarea; este puente no cambia automaticamente el modelo de la conversacion de Codex. Los precios por token de API no son una medida directa de los limites de una suscripcion.

El puente es `scripts/windows/Invoke-ClaudeCollaborator.ps1`. Usa la sesion iniciada y el modelo predeterminado de Claude Code; consume sus limites habituales. No requiere un servidor MCP ni permisos de administrador.

## Uso por Codex

Guardar el encargo en un archivo UTF-8. Para que el usuario vea exactamente lo que recibira Claude, primero generar la instruccion completa **sin llamar a Claude**, enlazar el archivo resultante en la conversacion y despues enviarlo:

```powershell
$env:ANTHROPIC_MODEL = 'claude-opus-5-5' # ejemplo; elegir por tarea
$preparado = ./scripts/windows/Invoke-ClaudeCollaborator.ps1 -PromptFile ./tmp/encargo-claude.txt -Modo Desarrollo -SoloPreparar
$preparado.Instruccion # mostrar este archivo completo al usuario antes de la llamada
./scripts/windows/Invoke-ClaudeCollaborator.ps1 -PromptFile ./tmp/encargo-claude.txt -Modo Desarrollo -InstruccionPreparada $preparado.Instruccion
```

El segundo paso verifica que el encargo y el modo no hayan cambiado desde la vista previa; si cambiaron, exige preparar de nuevo y no llama a Claude. El modo predeterminado Consulta permite lectura, busqueda y analisis. Para una consulta de solo lectura, usar tambien `-SoloPreparar` y `-InstruccionPreparada`, pero omitir `-Modo Desarrollo`.

La llamada directa anterior sigue disponible para compatibilidad:

```powershell
./scripts/windows/Invoke-ClaudeCollaborator.ps1 -PromptFile ./tmp/encargo-claude.txt -Modo Desarrollo
```

Desarrollo permite leer y editar archivos con el modo acceptEdits de Claude. El puente no expone shell: Codex revisa y ejecuta las comprobaciones necesarias por separado. Las restricciones y permisos de Claude siguen vigentes; no se usa bypassPermissions.

La respuesta incluye SessionId. Pasarlo mediante `-SessionId` para continuar exactamente esa conversacion. Nunca usar implicitamente la ultima sesion de Claude. Los encargos, respuestas JSON y errores quedan en `tmp/claude-colaboracion`, excluido de Git.

## Trabajo por turnos

1. Confirmar que no hay otra sesion editando los mismos archivos. El bloqueo del puente solo evita llamadas simultaneas de este script.
2. Codex delimita archivos, comportamiento esperado, restricciones y criterios de aceptacion. Guarda el encargo exacto en un archivo legible para el usuario y anuncia su enlace y modelo antes de enviarlo.
3. Claude desarrolla; Codex evita editar o correr una suite sobre esos archivos mientras cambian.
4. Codex revisa el diff, ejecuta las pruebas apropiadas y devuelve observaciones a la misma SessionId **de esa tarea**. Para una tarea nueva e independiente, no reutilizar indefinidamente una conversacion larga; iniciar un contexto acotado cuando convenga.
5. Informar al usuario el modelo efectivo, lo terminado, lo pendiente y las cifras de uso disponibles. Commit, push y despliegue requieren instruccion expresa.

El puente no activa trabajo autonomo permanente ni empieza por si solo el rediseño de Cobros. No conecta con una ventana de Claude existente y no elimina sus limites de uso. Un proceso sin respuesta o un error de cuota no cuenta como tarea completada.

## Prueba del 22/09/2026

Consulta y revision verificadas con SessionId `e4f53e43-f440-4a2e-b1a9-dd11860317ef`: Claude analizo la reutilizacion de datos de NC y respondio a las observaciones de Codex al reanudar la misma conversacion. El usuario autorizo expresamente procesar el codigo pertinente en el servicio externo de Claude, excluyendo credenciales y datos de produccion.

El intento dentro del aislamiento fallo con ConnectionRefused; funciono la ejecucion autorizada fuera del aislamiento. El SessionId de aquel intento fallido no pudo recuperarse y se inicio una nueva sesion. No generalizar que cualquier fallo de conexion tiene esa causa.

En esa fecha se valido encargo, respuesta y continuacion de lectura/revision. El modo Desarrollo se valido el 23/09, como se registra abajo. Detalle y siguiente entrega en docs/RELEVO_COBROS_PPQ_NC.md. Para mostrar toda la respuesta en PowerShell, usar `| Format-List` o leer el campo result del JSON registrado; el formato de tabla puede truncarla.

## Primera implementacion validada — 23/09/2026

Misma SessionId. Claude implemento reutilizacion de datos propios de NC en PPQ; Codex reviso el diff, pidio correcciones y ejecuto pruebas aisladas: 68 pruebas / 337 aserciones correctas, Pint en modo test y revision visual de renders ficticios en claro/oscuro. Ver docs/REVISION_NC_PPQ_20260923.md. Queda probado el ciclo de desarrollo y revision para esta entrega, sin declarar cerrado el modulo completo.

El usuario pidio **Opus 5.5** para las entregas anteriores y luego aclaro que no quiere usarlo por defecto en todo el modulo. El puente hereda las variables de su proceso: se puede seleccionar sin editar el script ni la configuracion global:

```powershell
$env:ANTHROPIC_MODEL = 'claude-opus-5-5'
./scripts/windows/Invoke-ClaudeCollaborator.ps1 -PromptFile ./tmp/encargo-claude.txt -Modo Desarrollo -SessionId e4f53e43-f440-4a2e-b1a9-dd11860317ef | Format-List
```

Usar esta variable en un proceso temporal o restaurarla al terminar si la consola se reutiliza. Verificar `modelUsage` del JSON: las correcciones de esta entrega confirmaron `claude-opus-5-5`. No confundir el nombre pedido con un modelo efectivo no comprobado.
