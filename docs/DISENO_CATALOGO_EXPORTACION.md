# Catálogo de exportación: producto → presentaciones

Decidido con el usuario el 28/09/2026. Objetivo: agregar un producto en **un solo paso**, sin duplicados, y que el precio de cada cliente salga de su última lista de empaque.

## Modelo

- **`exportacion_productos_base`** (`ExportacionProductoBase`): identidad del dulce. `nombre_es` (único), `nombre_en`, `categoria` (enum `CategoriaProductoExportacion`), `activo`.
- **`exportacion_productos`** (`ExportacionProducto`) pasa a ser la **presentación** y gana `exportacion_producto_base_id`. Conserva todas sus columnas: listas, Excel y FEX siguen leyendo de acá sin cambios.
  - `nombre_es` / `nombre_en` de la presentación se copian del producto base. Renombrar el base los actualiza en todas sus presentaciones. Los items de listas pasadas no cambian: son una copia (snapshot).
  - Una presentación no se repite dentro de su base: la combinación (base, `unidades_por_caja`, `gramos_por_unidad`) es única.
- **`exportacion_cliente_productos`**: precio del cliente por presentación. Gana `precio_fijado_en` (fecha de la lista que lo fijó) y `precio_desde_exportacion_id`.

Categorías, en este orden: `semillas` (Semillas y maní), `tradicionales` (Dulces tradicionales), `caramelos` (Caramelos), `paletas` (Paletas y nougat), `chicles` (Chicles y chocolate) y `exhibidores` (Exhibidores).

## Reglas de pesos (verificadas en los 25 productos reales)

- `onzas_por_unidad` = gramos × 0.035274, redondeado a 2 decimales. **Siempre calculado**, nunca se captura.
- `peso_*_lb` = kg × 2.20462, redondeado a 2 decimales. **Siempre calculado.**
- `peso_bruto_caja_kg` propone neto + 1 kg y se puede editar.
- `peso_neto_caja_kg` **se captura**: no es unidades × gramos, porque incluye las bolsas.

## Empaques predefinidos

| Botón | Unidades | Texto de `unidad` |
|---|---|---|
| Caja 12×12 | 144 | `Bolsa de polipropileno 12x12` |
| Caja 12×18 | 216 | `Bolsa de polipropileno 12x18` |
| Caja 24×12 | 288 | `Bolsa de polipropileno 24x12` |
| Otro | libre | libre (p. ej. `Empaque plástico 36x1`, `Caja master`, `Fardo de 6 unidades`) |

## Precio vigente

1. Al **finalizar** una lista, cada item con presentación deja su precio como vigente del cliente: crea la asignación si no existe y la reactiva si estaba inactiva. No pisa un precio con `precio_fijado_en` posterior a la fecha de la lista.
2. Reabrir una lista **no** revierte precios.
3. En la lista, el precio de cada línea se puede editar. Por defecto toma el vigente del cliente; si no hay, el precio base de la presentación. El precio $0 no se acepta.
4. La asignación manual desde la ficha del cliente sigue existiendo como opción, sobre todo para negociar un precio antes de la primera lista, pero ya no es un paso obligatorio.

## FDA

En la lista de empaque va un solo número FDA, el de la empresa, que se configura en Configuración → Parámetros fiscales. Desde el 28/09/2026 la ficha del cliente ya no pide un FDA propio; la columna `exportacion_clientes.fda_reg_number` queda intacta y no se edita.

## Unificación de lo existente

La hace el comando `exportacion:unificar-catalogo`, con la tabla aprobada por el usuario el 28/09/2026. Simula por defecto y aplica solo con `--aplicar`. Antes de aplicar, verifica que cada registro coincida en nombre, unidades y gramos, y guarda un respaldo JSON de las filas afectadas.

## Estado al 28/09/2026

La implementación está completa en el árbol de trabajo, sin commit. Se verificó en la copia aislada `prueba_unificacion_exportacion`: 48 productos, 62 presentaciones activas y 7 archivadas; los 86 items de listas quedaron idénticos. Las pruebas de exportaciones, listas y clientes pasan (400). La suite completa da 7 fallas en `NavigationTest`, que vienen de cambios previos al menú lateral («Pronto pago» → «Cobros Calleja») y no de este trabajo.

Para aplicarlo en una base, en este orden:

1. `php artisan migrate`, que aplica `2026_09_28_120000_agrupar_catalogo_exportacion_en_productos_base`.
2. `php artisan exportacion:unificar-catalogo`: simula y muestra la tabla.
3. `php artisan exportacion:unificar-catalogo --aplicar`: escribe y deja un respaldo en `storage/app/respaldos/`.
