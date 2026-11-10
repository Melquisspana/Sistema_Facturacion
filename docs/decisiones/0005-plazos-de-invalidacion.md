# 0005. Plazos para transmitir el evento de invalidación

## Estado

Aceptada para implementación. El calendario 2026 quedó confirmado con el Calendario Tributario 2026 del MH (09/11/2026). Sigue pendiente confirmar que el sábado no cuenta.

## Fecha

2026-10-04

## Contexto

Fuente: Manual Funcional del Sistema de Transmisión v2.0, mayo de 2026, páginas impresas 11-12. La tabla y sus cinco ejemplos usan el décimo día hábil del mes siguiente al mes del sello para CCF, NC, ND y otros tipos del grupo. El párrafo de la página 12 dice diez días hábiles posteriores a la transmisión y contradice esa tabla.

Los ejemplos de sello 15-04-2026 → límite 15-05-2026 y sello 19-03-2026 → límite 20-04-2026 distinguen ambas interpretaciones: no son diez días hábiles contados desde el sello. La fecha de generación puede pertenecer a otro mes; no decide el plazo de transmisión.

## Decisión

- Manda la tabla con los cinco ejemplos sobre el párrafo contradictorio. CCF (03), NC (05), ND (06) y cualquier otro tipo soportado fuera del grupo de tres meses tienen como límite el décimo día hábil del mes calendario siguiente al mes del sello. Esto no habilita tipos que la matriz fiscal todavía no soporta.
- Factura (01), Exportación (11) y Sujeto excluido (14, cuando exista en `TipoDte`) tienen tres meses calendario desde el sello, sin desbordar: 30-11-2025 → 28-02-2026. No son noventa días y no se desplaza el vencimiento por fines de semana.
- El límite es 23:59:59 de El Salvador, convertido a UTC por `HoraNegocio::finDelDiaUtc`. Se compara el instante real `now()`; solo está fuera cuando es mayor que el límite, por lo que el último segundo está incluido.
- El día del sello se obtiene de `fecha_procesamiento_mh->format('Y-m-d')`, sin convertir con `aLocal`: la columna conserva la hora local de `fhProcesamiento` marcada como UTC (decisión 0004). Si es null, este bloque no agrega problemas; las otras reglas exigen aceptación real.
- Días hábiles: lunes a viernes, excluyendo los días inhábiles del MH del año. Desde el issue #49 se cargan en Configuración → Feriados de Hacienda (ajustes N2 `dte.invalidacion.dias_inhabiles`, `dte.invalidacion.anios_completos` y `dte.invalidacion.anios_en_configuracion`, con confirmación y auditoría), sin desplegar. Un año que Configuración no tiene sale de `config('dte.invalidacion.dias_inhabiles')`, por año, con listas de fechas `Y-m-d`; ahí está el oficial 2026, tomado del Calendario Tributario 2026 del MH (página 1, «Días de asueto»). Un año del archivo que ya se cambió en Configuración se queda ahí, aunque quede sin días o sin marcar: lo confirmado es lo que usa el plazo. Solo se cargan calendarios oficiales del MH. Fechas inválidas o del año incorrecto producen una excepción de configuración, nunca se ignoran.
- Si el año del mes siguiente no tiene calendario, o lo tiene sin marcar como completo, se calcula con lo que haya (sin feriados si no hay nada) y se avisa en el mensaje de fuera de plazo y en la pantalla que hay que cargarlo en Configuración. El respaldo es conservador: el límite queda igual o anterior al real, por lo que no permite algo fuera del plazo oficial; puede bloquear anticipadamente hasta cargar el calendario.
- `PlazoInvalidacion` lee el calendario de `CalendarioInhabiles` (Configuración y, de respaldo, el archivo); `ValidadorReglasInvalidacion::problemas()` agrega el cuarto bloque. El Form Request también consume estas reglas. Serializador, mock, consola y transmisión real las aplican antes de firmar o transmitir. La pantalla muestra el límite de forma informativa.

## Consecuencias

No hay cambios en la fecha del evento ni migraciones. Los fixtures antiguos de invalidación conservan la fecha de emisión que comprueban, pero usan un sello local reciente para no vencer accidentalmente con el paso del tiempo. Las pruebas específicas de plazo fijan el reloj y el calendario.

## Pendiente confirmar con el MH

- Que el sábado no cuente como día hábil.

Confirmado el 09/11/2026: el calendario 2026. El Calendario Tributario 2026 del MH declara inhábiles del 1 al 6 de abril, que es lo que hace cuadrar el ejemplo 19-03-2026 → 20-04-2026; las pruebas de los dos ejemplos usan ahora ese calendario oficial. El mismo calendario avisa que la Asamblea Legislativa puede modificar las fechas: por eso se corrigen desde Configuración.

## Referencias

- Manual Funcional del Sistema de Transmisión v2.0, págs. 11-12 (texto externo de auditoría; no se copia al repositorio).
- Calendario Tributario 2026 del MH, página 1, «Días de asueto» (no se copia al repositorio; las fechas están en `config/dte.php`).
- [Decisión 0004](0004-zona-horaria-de-negocio.md): tratamiento de `fecha_procesamiento_mh`.
- Auditoría Hacienda 2.0 del 20/09/2026, punto 2 (bitácora retirada de `docs/`; queda en el historial de git).
