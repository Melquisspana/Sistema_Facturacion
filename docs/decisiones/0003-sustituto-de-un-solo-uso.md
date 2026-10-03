# 0003. Sustituto de un solo uso

## Estado

Aceptada por Melqui

## Fecha

2026-10-03

## Contexto

El manual del MH revisado en `docs/dte` exige que el documento sustituto esté previamente aceptado. No establece una regla sobre reutilizarlo para invalidar varios documentos.

## Decisión

Cada documento nuevo corrige solo un documento invalidado. Se registra `codigoGeneracionR` en la respuesta de invalidación y se bloquea su reutilización, tanto al verificarlo como al buscar candidatos. El envío toma también un bloqueo por código de sustituto.

## Consecuencias

Para corregir otro documento hay que emitir otro sustituto aceptado. No se necesita una migración: el código queda en la columna JSON existente, también en las respuestas rechazadas y simuladas. Solo los eventos con sello de invalidación reservan el sustituto.

Las invalidaciones anteriores a este cambio no guardaron el código del sustituto y no se pueden detectar mediante esta regla. No se reconstruyen datos históricos.
