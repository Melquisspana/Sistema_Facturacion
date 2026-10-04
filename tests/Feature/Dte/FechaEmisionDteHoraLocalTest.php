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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

class FechaEmisionDteHoraLocalTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    public static function instantes(): array
    {
        return [
            ['2026-10-03 23:59:00', '2026-10-03', '17:59:00', null],
            ['2026-10-04 00:00:00', '2026-10-03', '18:00:00', null],
            ['2026-10-04 05:59:00', '2026-10-03', '23:59:00', null],
            ['2026-10-04 06:00:00', '2026-10-04', '00:00:00', null],
            ['2026-10-04 11:59:00', '2026-10-04', '05:59:00', null],
            ['2026-10-04 01:00:00', '2026-10-03', '19:00:00', '2026-10-05 15:00:00'],
        ];
    }

    #[DataProvider('instantes')]
    public function test_borrador_y_json_conservan_emision_local(string $instante, string $fecha, string $hora, ?string $generarEn): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        config(['app.zona_negocio' => 'America/El_Salvador']);
        $this->travelTo(Carbon::parse($instante, 'UTC'));
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
        $this->assertSame($fecha, $dte->fecha_emision->format('Y-m-d'));
        $this->assertSame($hora, $dte->hora_emision);

        $producto = Producto::factory()->create([
            'unidad_medida_id' => UnidadMedida::whereNotNull('codigo')->firstOrFail()->id,
            'precio_unitario' => 1.13,
        ]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);
        if ($generarEn !== null) {
            $this->travelTo(Carbon::parse($generarEn, 'UTC'));
        }
        app(DteGeneracionService::class)->generar($dte, $usuario);
        $this->assertSame($fecha, $dte->fresh()->fecha_emision->format('Y-m-d'));
        $this->assertSame($hora, $dte->fresh()->hora_emision);
        $salida = app(MapeadorDteSalida::class)->mapear($dte->fresh());
        $json = app(SerializadorFacturaMh::class)->serializar($salida);
        $this->assertSame($fecha, $json['identificacion']['fecEmi']);
        $this->assertSame($hora, $json['identificacion']['horEmi']);
    }
}
