<?php

namespace App\Support\Dte;

use App\Enums\TipoDte;
use App\Models\Dte;
use App\Models\DteLinea;

/**
 * Etiqueta de PRESENTACIÓN (columna "Present.") de una línea del DTE.
 *
 * El código MH "99" (CAT-014 "Otra") NO significa "caja" en todos los casos: lo
 * usan también productos reales vendidos por Bolsa (UnidadMedidaSeeder mapea
 * "Bolsa" a 99 porque el catálogo oficial no tiene un código propio) y los
 * conceptos manuales de Nota de Crédito (pronto pago/descuento/ajuste, sin
 * producto ni unidad física). Traducir "99" a Caja/Cajas SOLO es correcto para
 * las líneas SIN producto de catálogo (producto_id null) de una Factura de
 * exportación (tipo 11) — el único flujo que copia cajas de una Lista de
 * Empaque. Cualquier otro caso muestra el nombre real de la unidad (o el
 * código, si no hay nombre). Solo texto de presentación: NO cambia
 * `unidad_codigo` ni lo que se guarda/envía en el JSON oficial.
 */
final class PresentacionUnidadLinea
{
    public static function etiqueta(DteLinea $linea, Dte $dte): string
    {
        $esCajaDeExportacion = $dte->tipo_dte === TipoDte::FacturaExportacion
            && $linea->producto_id === null
            && trim((string) $linea->unidad_codigo) === '99';

        if ($esCajaDeExportacion) {
            return ((float) $linea->cantidad === 1.0) ? 'Caja' : 'Cajas';
        }

        $texto = trim((string) ($linea->unidad_nombre ?: $linea->unidad_codigo));

        return $texto !== '' ? $texto : '—';
    }
}
