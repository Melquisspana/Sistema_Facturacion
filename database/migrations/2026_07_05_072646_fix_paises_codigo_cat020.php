<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La tabla `paises` se sembró con códigos numéricos inventados (9300, 9320…) que NO
 * corresponden al catálogo real CAT-020 del MH (visible en `catalogos_mh`, importado del
 * Excel oficial), que usa códigos ISO alpha-2 (SV, US, CR…). Esto hacía que el receptor de
 * la Factura de Exportación (11) resolviera `nombrePais` vacío. Alinea `paises.codigo` a los
 * códigos ISO reales sin tocar los ids (las FK de clientes/empresas/establecimientos no se
 * ven afectadas).
 */
return new class extends Migration
{
    private const MAPA = [
        '9300' => 'SV',
        '9320' => 'US',
        '9280' => 'GT',
        '9120' => 'HN',
        '9160' => 'NI',
        '9040' => 'CR',
        '9200' => 'PA',
        '9440' => 'MX',
    ];

    public function up(): void
    {
        foreach (self::MAPA as $antiguo => $nuevo) {
            DB::table('paises')->where('codigo', $antiguo)->update(['codigo' => $nuevo]);
        }
    }

    public function down(): void
    {
        foreach (self::MAPA as $antiguo => $nuevo) {
            DB::table('paises')->where('codigo', $nuevo)->update(['codigo' => $antiguo]);
        }
    }
};
