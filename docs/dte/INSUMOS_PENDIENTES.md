# Insumos pendientes del DTE (checklist)

Marcá cada ítem cuando coloques el archivo oficial. Anotá **versión** y **fecha** de
descarga, y de dónde salió (portal/documentación del MH). Ver
[README.md](README.md) para ubicaciones y convención de nombres.

## 🚧 BLOQUEO ACTUAL (siguiente paso real)

La fase preparatoria del JSON está **cerrada y estable**. Ya están listos, probados y
sin depender del esquema: campos base en `dtes`, `config/dte.php → json`, utilidades
(`CodigoGeneracion`, `NumeroControlBuilder`, `NumeroALetras`), `ValidacionPreJsonService`,
los DTOs de salida (`App\DataTransferObjects\Dte\Salida\*`) y el mapper interno
`MapeadorDteSalida` (`Dte → DteSalidaData`).

**No se puede avanzar al serializador con nombres oficiales, ni generar JSON, ni
validar contra schema, hasta conseguir y COLOCAR los siguientes insumos oficiales del MH:**

- [x] `fe-f-v2.json`   → `resources/dte/schemas/01_factura/` (Factura, v2)
- [x] `fe-ccf-v4.json` → `resources/dte/schemas/03_ccf/` (CCF, v4)
- [x] `fe-nc-v4.json`  → `resources/dte/schemas/05_nota_credito/` (Nota de crédito, v4)
- [x] `fe-fex-v3.json` → `resources/dte/schemas/11_exportacion/` (Exportación, v3)
- [x] `invalidacion-schema-v3.json` → `resources/dte/schemas/invalidacion/` (evento de anulación, v3)
- [x] **CAT-014** Unidades de medida (40) — importado del Excel a `catalogos_mh`
- [x] **CAT-015** Tributos (49) — importado
- [x] **CAT-017** Formas de pago (12) — importado
- [x] **CAT-018** **Plazo** (3) — importado · ⚠️ CAT-018 es **Plazo**, NO Incoterms
- [x] **CAT-031** **INCOTERMS** (11) — importado (este es el de exportación)

Mientras estos archivos no estén colocados, `DteSchemaRepository` los reporta como
faltantes y **no** se genera JSON oficial. Una vez colocados (marcá cada ítem y anotá
versión/fecha/origen en la tabla del final), el siguiente trabajo es: validador contra
schema → serializador `DteSalidaData → array oficial` por tipo → `DteJsonService`.

> 💡 Para ver el estado actual en cualquier momento, corré:
> `php artisan dte:insumos` (solo lectura; no genera nada).

## Estado verificado (2026-06-17)

- **Schemas:** ✅ los 4 COLOCADOS y detectados por `DteSchemaRepository`
  (`fe-f-v2`, `fe-ccf-v4`, `fe-nc-v4`, `fe-fex-v3`) + esquema de invalidación
  (`invalidacion-schema-v3`). Versiones en `config/dte.php → json.versiones`
  (01→2, 03→4, 05→4, 11→3).
- **Catálogos:** ✅ Excel oficial colocado e **importado** a la tabla `catalogos_mh`
  (**33 secciones CAT-001..033, 1680 registros**) con `php artisan dte:catalogos`.
  Lector `.xlsx` propio sin dependencias (`App\Support\Importacion\LectorXlsx`).
  Corrección importante: **CAT-018 = Plazo** y **CAT-031 = INCOTERMS** (antes la doc
  decía erróneamente que Incoterms era CAT-018).
- **Comandos:** `php artisan dte:insumos` (reporte) · `php artisan dte:catalogos` (importar).
- **Catálogos cargados (BD):** País (8), Departamento (14), Municipio (52 — parcial,
  suficiente para los clientes actuales), Actividad económica (5), Unidad de medida
  (10 — **parcial**, CAT-014 incompleto).
- **Catálogos por enum (OK):** CAT-001 Ambiente, CAT-002 Tipo DTE, CAT-009 Tipo
  establecimiento, CAT-011 Tipo de ítem, CAT-016 Condición de operación, tipo de
  documento del receptor.
- **Faltan:** CAT-014 completo, CAT-015 Tributos (hoy solo IVA="20" en config),
  **CAT-017 Formas de pago** y **CAT-018 Incoterms**.
- **Preparación de código:** completa y probada (DTOs de salida, `MapeadorDteSalida`,
  `ValidacionPreJsonService`, `DteSchemaRepository`, utilidades, `config/dte.php → json`).

## JSON Schemas (resources/dte/schemas/)

- [ ] Schema **Factura 01** → `01_factura/` (versión esperada: 1)
- [ ] Schema **CCF 03** → `03_ccf/` (versión esperada: 3)
- [ ] Schema **Nota de crédito 05** → `05_nota_credito/` (versión esperada: 3)
- [ ] Schema **Factura de exportación 11** → `11_exportacion/` (versión esperada: 1)

## Catálogos (resources/dte/catalogos/)

- [ ] **CAT-014** Unidades de medida — **completo** (hoy solo 59/99 cargados)
- [ ] **CAT-015** Tributos (IVA = "20")
- [ ] **CAT-017** Formas de pago
- [ ] **CAT-018** Incoterms (para exportación)
- [ ] Verificar **CAT-011** Tipo de ítem (bien/servicio/…)
- [ ] Verificar **CAT-012 / CAT-013** Departamento / Municipio
- [ ] Verificar **CAT-019** Actividad económica
- [ ] Verificar **CAT-020** País
- [ ] Verificar **CAT-021** Documento asociado (si aplica)

## Otros

- [ ] Verificar **versiones por tipo de DTE** (cuadrar `config/dte.php` → `json.versiones`)
- [ ] Colocar **JSON de ejemplo oficiales** (uno por tipo) en `resources/dte/examples/`
- [ ] Confirmar que `codEstableMH` / `codPuntoVentaMH` = nuestros códigos internos

## Registro de cambios (completar al colocar cada insumo)

| Fecha | Insumo | Versión | Origen | Notas |
|-------|--------|---------|--------|-------|
|       |        |         |        |       |
