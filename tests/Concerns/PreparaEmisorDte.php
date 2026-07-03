<?php

namespace Tests\Concerns;

use App\Enums\TipoEstablecimiento;
use App\Models\ActividadEconomica;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Municipio;
use App\Models\PuntoVenta;
use Database\Seeders\CatalogosMhSeeder;
use Database\Seeders\CatalogosMhTablaSeeder;

/**
 * Helper de pruebas para GENERAR el JSON oficial de un DTE: siembra los catálogos que la
 * generación necesita (incluida la tabla genérica `catalogos_mh` con CAT-014/CAT-019…) y
 * crea un emisor COMPLETO (empresa con NIT/NRC/actividad/ubicación/dirección + establecimiento
 * con tipo CAT-009 + punto de venta).
 *
 * Sin esto, generar un CCF/NC en pruebas fallaba con GeneracionException (faltaban datos del
 * emisor o el catálogo CAT-014). El receptor válido lo aporta ClienteFactory::contribuyente().
 */
trait PreparaEmisorDte
{
    /** Siembra los catálogos base + la tabla catalogos_mh (necesaria para el JSON oficial). */
    protected function seedCatalogosDte(): void
    {
        $this->seed(CatalogosMhSeeder::class);
        $this->seed(CatalogosMhTablaSeeder::class);
    }

    /**
     * Crea un emisor completo (empresa + establecimiento + punto de venta) apto para generar
     * el JSON oficial. Requiere que los catálogos ya estén sembrados ({@see seedCatalogosDte}).
     *
     * @return array{estab: Establecimiento, pv: PuntoVenta, empresa: Empresa}
     */
    protected function crearEmisorDte(string $estabCodigo = 'M001', string $pvCodigo = 'P001'): array
    {
        $depto = Departamento::query()->first();
        $muni = Municipio::query()->where('departamento_id', $depto?->id)->first() ?? Municipio::query()->first();
        $actividad = ActividadEconomica::query()->first();

        $empresa = Empresa::create([
            'razon_social' => 'Dulces La Negrita', 'nit' => '06140000000000', 'nrc' => '1234567',
            'actividad_economica_id' => $actividad?->id, 'departamento_id' => $depto?->id, 'municipio_id' => $muni?->id,
            'direccion' => 'Km 30 Carretera a Zacatecoluca', 'telefono' => '2200-0000', 'correo' => 'emisor@dulceslanegrita.sv',
            'ambiente' => '00', 'activo' => true,
        ]);
        $estab = Establecimiento::create([
            'empresa_id' => $empresa->id, 'codigo' => $estabCodigo, 'nombre' => 'Casa Matriz',
            'tipo_establecimiento' => TipoEstablecimiento::CasaMatriz->value, 'activo' => true,
        ]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => $pvCodigo, 'nombre' => 'Caja 1', 'activo' => true]);

        return compact('estab', 'pv', 'empresa');
    }
}
