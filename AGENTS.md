# Función de Codex en este proyecto

## Cambio de roles — 27/09/2026 (prevalece sobre lo que sigue)

El usuario invirtió los roles: **Claude coordina y hace lo importante** (análisis, diseño, decisiones, reglas complejas y revisión final); **Codex desarrolla** la implementación, gastando la cuota de ChatGPT. Codex participa siempre en el desarrollo; Claude solo programa directamente cuando sea necesario (arreglos críticos, piezas delicadas o cuando corregir sea más barato que otra ronda).

- Claude envía encargos con `scripts/windows/Invoke-CodexCollaborator.ps1` (mismo flujo `-SoloPreparar` / `-InstruccionPreparada` y `-SessionId`; `-Modelo` opcional). Registros en `tmp/codex-colaboracion`.
- Si el usuario trabaja desde la app de Codex, Codex implementa directamente y consulta a Claude por `Invoke-ClaudeCollaborator.ps1` para diseño o revisión de lo importante.
- Siguen vigentes todas las restricciones de abajo: sin commit, push, despliegue, migraciones nuevas ni producción sin instrucción expresa; no tocar `C:\rclone` ni `firmware/asistencia/asistencia.ino`.

## Reparto anterior (24/09/2026, sustituido en roles)

Preferencia expresa del usuario, aclarada el 24/09/2026: **Claude es el desarrollador principal; Codex asiste al usuario, coordina y revisa, y también puede programar cuando sea útil o necesario.** Ambos colaboran en la misma tarea, sin consumir Claude para análisis, documentación, verificaciones o arreglos acotados que Codex puede resolver con seguridad.

- Por defecto, Codex analiza, diagnostica, diseña, documenta, prepara encargos, revisa cambios y ejecuta comprobaciones; Claude implementa las entregas sustanciales mediante el puente local. Codex puede tomar cambios acotados conforme a la autorización siguiente.
- El usuario dio autorización permanente a Codex para implementar o corregir código de la aplicación cuando sea útil o necesario dentro de un encargo de desarrollo vigente. Codex anuncia qué parte tomará, evita editar simultáneamente los mismos archivos que Claude y prueba el resultado. Esto no autoriza migraciones nuevas, producción, tareas fuera del alcance ni cambios irreversibles sin el encargo correspondiente.
- No consultar a Claude por costumbre para asuntos que Codex pueda resolver como asistente. Cuando sí haya desarrollo, enviarle una tarea pequeña y definida, sin duplicar toda la conversación ni encargarle pruebas de revisión que Codex puede ejecutar. Explicar brevemente por qué se lo convoca.
- Antes de llamar al puente de Claude, usar `-SoloPreparar` para generar la instrucción **completa**, incluidas las reglas fijas del puente. Dejar visible en la conversación el modelo elegido, el alcance y un enlace a ese archivo; enviar luego con `-InstruccionPreparada` para comprobar que no cambió. No añadir instrucciones ocultas ni credenciales. Después, informar el modelo efectivo, el resultado y el consumo que devuelva el puente, si está disponible.
- Llevar el control en esta misma conversación: antes de cada llamada, modelo, motivo, encargo exacto y archivos esperados; después, archivos cambiados, pruebas, `usage` de esa llamada y estado pendiente. `modelUsage` y `total_cost_usd` del puente pueden acumular la sesión completa; no presentarlos como gasto de una sola llamada ni como cargo real de la suscripción. Consultar los límites de Codex cuando el usuario lo pida o cuando afecten la continuidad, sin inventar un saldo de Claude que el puente no exponga.
- Elegir el modelo de Claude por resultado esperado, no solo por precio por token: Haiku para trabajo mecánico, Sonnet para cambios delimitados y Opus 5.5 para reglas complejas o tareas donde su mayor acierto pueda evitar rondas. Opus 5.5 es más barato que Opus 5, pero no se fija como único modelo; contrastar consumo por tarea terminada. Codex también puede recomendar Luna, Sol o Astra para futuras tareas según complejidad; el modelo de esta conversación no cambia automáticamente. Verificar disponibilidad real antes de usar un modelo y no degradar silenciosamente uno pedido expresamente para una tarea concreta.
- Acotar cada encargo a los archivos y criterios necesarios. Usar una sesión de Claude por tarea o conjunto coherente y conservar su SessionId solo para revisiones de ese trabajo; no arrastrar indefinidamente todo el historial del módulo. Evitar ciclos de ida y vuelta que no cambien la calidad del resultado.
- Ante una falla, diagnosticar primero y explicar causa y solución. Tras delimitar la corrección, asignar el código a Claude o tomar un arreglo acotado en Codex según complejidad, riesgo y disponibilidad; explicarlo al usuario.
- Se permite mantener documentación de asistencia y estas instrucciones cuando el usuario lo solicite.
- No hacer commit, push ni despliegue sin instrucción expresa. No modificar producción ni el respaldo externo de Drive en `C:\rclone`.
- Preservar las modificaciones locales ajenas de `firmware/asistencia/asistencia.ino`; excluirlas de cualquier commit.

Estas instrucciones regulan la colaboración; no cambian las restricciones sobre producción y entregas.

## Colaboración entre sesiones

- El puente local permite que Claude desarrolle mientras Codex acompaña al usuario y revisa. El usuario no debe copiar mensajes entre herramientas.
- Antes de usarlo, leer `docs/COLABORACION_CODEX_CLAUDE.md`. El puente está en `scripts/windows/Invoke-ClaudeCollaborator.ps1`.
- Claude sigue como desarrollador principal para entregas sustanciales; Codex puede asumir arreglos acotados del encargo vigente según la autorización anterior. Si se usa Claude, Codex muestra el encargo exacto y el modelo antes de enviarlo, y revisa el resultado. Esta preferencia no autoriza tareas nuevas por iniciativa propia ni modifica las restricciones de producción, migraciones, commit, push o despliegue.
- Trabajar por turnos sobre los mismos archivos. Incluir el contexto necesario en cada encargo y conservar el SessionId para sus revisiones; las conversaciones no se comparten automáticamente.
- El 22/09/2026 se completó la primera consulta y revisión por el puente, con continuación en el mismo SessionId. El usuario autorizó procesar el código pertinente en el servicio externo de Claude, sin credenciales ni datos de producción. Ver los registros en docs/COLABORACION_CODEX_CLAUDE.md y docs/RELEVO_COBROS_PPQ_NC.md.
- El 23/09/2026 se validó la primera entrega en modo Desarrollo: Claude implementó datos propios de NC en PPQ, Codex revisó, pidió correcciones y verificó 68 pruebas/337 aserciones y renders claro/oscuro. Ver docs/REVISION_NC_PPQ_20260923.md. Mantener este método habitual; no confundir esta entrega con el cierre del módulo.
- El pedido previo de Claude Opus 5.5 (`claude-opus-5-5`) se cumplió en aquellas entregas. Desde el 24/09/2026 no es modelo obligatorio para toda tarea nueva: aplicar la selección proporcional de arriba y verificar el modelo efectivo cuando se use el puente. No hay una ejecución programada a las 6 a. m.

## Relevo del módulo Calleja

- Para la próxima sesión de Cobros, PPQ y formatos de NC, leer `docs/RELEVO_COBROS_PPQ_NC.md`: reúne los requisitos del usuario, el estado reportado y el orden propuesto. No depender de recuperar automáticamente el historial del chat.
- Mantener esa sesión enfocada en el módulo. El usuario desea una revisión general del proyecto por etapas; registrar hallazgos externos sin ampliar por iniciativa propia la implementación actual.
