<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Services\Dte\DteBorradorService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Confirma el comportamiento de la migración
 * 2026_07_17_150000_alcance_numero_control_unico_por_ambiente: `numero_control`
 * deja de ser único a nivel GLOBAL y pasa a serlo por (ambiente, numero_control).
 * Prueba el ÍNDICE de la base de datos directamente (sin pasar por generar()),
 * para aislar la garantía de la migración de la lógica de generación.
 */
class NumeroControlUnicoPorAmbienteTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();
    }

    private function borrador(string $ambiente): Dte
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        $cliente = Cliente::factory()->create();

        return app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::Factura,
            'ambiente' => $ambiente,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
    }

    public function test_numero_control_puede_repetirse_entre_ambientes_tras_la_migracion(): void
    {
        $dtePrueba = $this->borrador('00');
        $dteProduccion = $this->borrador('01');

        $numeroControl = 'DTE-01-M001P001-000000000000001';
        $dtePrueba->update(['numero_control' => $numeroControl]);

        // Mismo numero_control, ambiente DISTINTO: no debe lanzar UniqueConstraintViolationException.
        $dteProduccion->numero_control = $numeroControl;
        $dteProduccion->save();

        $this->assertSame($numeroControl, $dtePrueba->fresh()->numero_control);
        $this->assertSame($numeroControl, $dteProduccion->fresh()->numero_control);
    }

    public function test_numero_control_sigue_siendo_unico_dentro_del_mismo_ambiente(): void
    {
        $dteUno = $this->borrador('00');
        $dteDos = $this->borrador('00');

        $numeroControl = 'DTE-01-M001P001-000000000000002';
        $dteUno->update(['numero_control' => $numeroControl]);

        $this->expectException(UniqueConstraintViolationException::class);
        $dteDos->update(['numero_control' => $numeroControl]);
    }
}
