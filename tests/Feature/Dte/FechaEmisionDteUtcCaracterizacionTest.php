<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Models\Correlativo;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\MapeadorDteSalida;
use App\Services\Dte\Serializadores\SerializadorFacturaMh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

class FechaEmisionDteUtcCaracterizacionTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    /**
     * Caracteriza fecEmi/horEmi en UTC. NO es el comportamiento deseado: lo corrige
     * el issue #46 tras ensayo con Hacienda; esta prueba evita que las etapas de
     * la decisión 0004 lo cambien sin querer. Al resolver #46 se actualiza.
     */
    public function test_borrador_y_json_conservan_fecha_y_hora_de_emision_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        config(['app.zona_negocio' => 'America/El_Salvador']);
        $this->travelTo(Carbon::parse('2026-10-04 01:00:00', 'UTC'));
        $this->seedCatalogosDte();
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        $correlativo = Correlativo::create([
            'tipo_dte' => '01', 'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id, 'ambiente' => '00',
            'ultimo_numero' => 0, 'activo' => true,
        ]);
        $usuario = User::factory()->create();
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::Factura, 'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id, 'correlativo_id' => $correlativo->id,
            'cliente_id' => null,
        ], $usuario);
        $dte->refresh();
        $this->assertSame('2026-10-04', $dte->fecha_emision->format('Y-m-d'));
        $this->assertSame('01:00:00', $dte->hora_emision);

        $producto = Producto::factory()->create([
            'unidad_medida_id' => UnidadMedida::whereNotNull('codigo')->firstOrFail()->id,
            'precio_unitario' => 1.13,
        ]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);
        app(DteGeneracionService::class)->generar($dte, $usuario);
        $salida = app(MapeadorDteSalida::class)->mapear($dte->fresh());
        $json = app(SerializadorFacturaMh::class)->serializar($salida);
        $this->assertSame('2026-10-04', $json['identificacion']['fecEmi']);
        $this->assertSame('01:00:00', $json['identificacion']['horEmi']);
    }
}
