# Insumos oficiales del DTE (Ministerio de Hacienda — El Salvador)

Esta carpeta documenta **dónde y cómo** colocar los **JSON Schema oficiales** y los
**catálogos** del Ministerio de Hacienda (MH) que el sistema usará para generar y
validar los DTE. **No** se incluye ningún esquema aquí: deben descargarse del MH y
versionarse manualmente.

> ⚠️ **No inventar esquemas.** Mientras un esquema no esté colocado, el sistema lo
> reporta como faltante (`DteSchemaRepository`) y **no** se genera JSON oficial.

## 1. De dónde descargar

- **Portal oficial de Factura Electrónica del MH** (administración de DTE) y su
  **documentación técnica** (manuales de "Estructura de los DTE" / "Sistema de
  Transmisión"). De ahí provienen los JSON Schema y los catálogos oficiales.
- Descargá siempre la versión **vigente** publicada por el MH. Si tu contador o el
  MH te entregan los archivos directamente, usá esos mismos.

No descargamos nada automáticamente: el archivo lo colocás vos a mano.

## 2. Qué archivos se necesitan y dónde van

```
resources/dte/
├── schemas/
│   ├── 01_factura/        → JSON Schema Factura (tipo 01)
│   ├── 03_ccf/            → JSON Schema Crédito Fiscal (tipo 03)
│   ├── 05_nota_credito/   → JSON Schema Nota de Crédito (tipo 05)
│   └── 11_exportacion/    → JSON Schema Factura de Exportación (tipo 11)
├── catalogos/             → Catálogos oficiales (CAT-014, CAT-015, CAT-017, CAT-018…)
└── examples/              → JSON de EJEMPLO oficiales (uno por tipo) para pruebas
docs/dte/                  → Esta documentación + checklist de insumos
```

**Convención de nombre del schema:** un archivo `.json` por carpeta de tipo, con la
versión en el nombre (`-v3` o `_v3`). Ejemplos esperados:

| Tipo | Carpeta | Archivo esperado (ejemplo) |
|------|---------|----------------------------|
| 01 Factura | `01_factura/` | `fe-fc-v1.json` |
| 03 CCF | `03_ccf/` | `fe-ccf-v3.json` |
| 05 Nota de Crédito | `05_nota_credito/` | `fe-nc-v3.json` |
| 11 Exportación | `11_exportacion/` | `fe-fex-v1.json` |

El `DteSchemaRepository` toma el `.json` de cada carpeta y prefiere el que coincida
con la versión configurada en `config/dte.php` → `json.versiones`.

## 3. Versión de cada esquema

Las versiones que el proyecto **espera** (editables) están en
`config/dte.php` → `json.versiones`:

| Tipo | Versión esperada |
|------|------------------|
| 01 Factura | 1 |
| 03 CCF | 3 |
| 05 Nota de Crédito | 3 |
| 11 Exportación | 1 |

Si el MH publica una versión distinta, **actualizá `config/dte.php`** y colocá el
archivo con el nuevo número de versión.

## 4. Catálogos a completar

Ver el checklist en [INSUMOS_PENDIENTES.md](INSUMOS_PENDIENTES.md). En resumen, falta
completar/confirmar: **CAT-014** (unidades de medida, hoy incompleto), **CAT-015**
(tributos), **CAT-017** (formas de pago), **CAT-018** (incoterms) y verificar
CAT-011/012/013/019/020/021.

## 5. Política de versionado (no cambiar un esquema "a mano")

- **No** edites el contenido de un schema oficial. Si cambia, reemplazá el archivo
  completo por la nueva versión del MH.
- Cada vez que agregues/actualices un esquema o catálogo, **registralo** en
  `INSUMOS_PENDIENTES.md` con **fecha** y **versión** (marcá el ítem y anotá de dónde
  salió).
- Los esquemas **deben** provenir del portal/documentación oficial del MH, nunca de
  una fuente no oficial ni reconstruidos a mano.

## 6. Qué hace hoy el sistema con estos archivos

`App\Services\Dte\DteSchemaRepository`:
- lista los esquemas presentes (`disponibles()`),
- indica cuáles faltan (`faltantes()`, `falta($tipo)`),
- devuelve ruta/archivo/versión del esquema de un tipo (`paraTipo($tipo)`),
- lee el contenido crudo (`leer($tipo)`), **sin** validar ni interpretar.

La **validación** de un DTE contra el schema y el **mapeo** a JSON llegarán en pasos
posteriores, una vez los archivos oficiales estén colocados.
