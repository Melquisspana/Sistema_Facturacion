# Registro de decisiones

Un registro de decisiones (ADR, por sus siglas en inglés) conserva el contexto, la decisión y sus consecuencias para que futuras personas colaboradoras comprendan por qué se eligió una solución. Se usa para decisiones duraderas de arquitectura, organización o desarrollo.

## Nombres y mantenimiento

Cada decisión se guarda en un archivo con el formato `NNNN-titulo-en-kebab.md`: número correlativo de cuatro dígitos y título en minúsculas, con palabras separadas por guiones. Por ejemplo, `0001-flujo-con-ramas-y-pull-requests.md`.

Una decisión aceptada conserva su contexto histórico. Si otra la reemplaza, se registra una nueva decisión y se enlazan ambas, actualizando el estado de la anterior.

## Plantilla

```markdown
# NNNN. Título de la decisión

## Estado

Propuesta, Aceptada o Sustituida (con enlace a la decisión que la sustituye).

## Fecha

AAAA-MM-DD

## Contexto

Problema, necesidades y restricciones que motivan la decisión.

## Decisión

Acuerdo adoptado y alcance de su aplicación.

## Consecuencias

Beneficios, costos, riesgos y medidas para mitigarlos.
```

Puede agregarse una sección de referencias relacionadas. Usá solo datos inventados: no incluyas datos de clientes o empleados, identificadores tributarios, correos, credenciales ni detalles del servidor o del despliegue.

## Índice

| Decisión | Estado | Fecha |
| --- | --- | --- |
| [0001. Flujo con ramas y pull requests](0001-flujo-con-ramas-y-pull-requests.md) | Aceptada | 2026-09-28 |
| [0002. Aplicar migraciones pendientes y migraciones destructivas](0002-migraciones-pendientes-y-destructivas.md) | Propuesta | 2026-09-29 |
| [0003. Sustituto de un solo uso](0003-sustituto-de-un-solo-uso.md) | Aceptada | 2026-10-03 |
| [0004. Zona horaria: guardar y calcular el día de negocio en hora de El Salvador](0004-zona-horaria-de-negocio.md) | Propuesta (corregida el 2026-10-06) | 2026-10-03 |
