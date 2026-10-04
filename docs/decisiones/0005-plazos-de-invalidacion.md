# 0005. Plazos para transmitir el evento de invalidación

## Estado

Aceptada para implementación. Pendiente confirmar el calendario oficial con el MH.

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
- Días hábiles: lunes a viernes, excluyendo `config('dte.invalidacion.dias_inhabiles')`, por año, con listas de fechas `Y-m-d`. La configuración se deja vacía y se carga únicamente con el calendario oficial del MH. Fechas inválidas o del año incorrecto producen una excepción de configuración, nunca se ignoran.
- Si falta la tabla del año del mes siguiente, se calcula sin feriados y se avisa en el mensaje de fuera de plazo y en la pantalla. El respaldo es conservador: el límite queda igual o anterior al real, por lo que no permite algo fuera del plazo oficial; puede bloquear anticipadamente hasta cargar el calendario. Pasar la tabla al centro de Ajustes, para cargarla sin desplegar, queda en el issue #49.
- `PlazoInvalidacion` calcula sin base de datos; `ValidadorReglasInvalidacion::problemas()` agrega el cuarto bloque. El Form Request también consume estas reglas. Serializador, mock, consola y transmisión real las aplican antes de firmar o transmitir. La pantalla muestra el límite de forma informativa.

## Consecuencias

No hay cambios en la fecha del evento ni migraciones. Los fixtures antiguos de invalidación conservan la fecha de emisión que comprueban, pero usan un sello local reciente para no vencer accidentalmente con el paso del tiempo. Las pruebas específicas de plazo fijan el reloj y el calendario.

## Pendiente confirmar con el MH

- Calendario oficial de días inhábiles por año. El ejemplo 19-03-2026 → 20-04-2026 solo cuadra si del 1 al 6 de abril de 2026 son inhábiles para el MH. Esa hipótesis se usa únicamente en las pruebas del ejemplo, no se carga como calendario oficial.
- Que el sábado no cuente como día hábil.

## Referencias

- Manual Funcional del Sistema de Transmisión v2.0, págs. 11-12 (texto externo de auditoría; no se copia al repositorio).
- [Decisión 0004](0004-zona-horaria-de-negocio.md): tratamiento de `fecha_procesamiento_mh`.
- [Auditoría Hacienda 2.0, punto 2](../dte/AUDITORIA_HACIENDA_2_0_2026-09-20.md).
