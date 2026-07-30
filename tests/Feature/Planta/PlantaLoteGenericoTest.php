<?php

namespace Tests\Feature\Planta;

use App\Exceptions\Planta\LoteGenericoNoAplicableException;
use App\Models\Planta\PlantaLote;
use App\Services\Planta\LoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lote genérico: el lote de sistema que da quinta dimensión al bucket de los
 * insumos que no controlan lotes.
 *
 * Lo que estas pruebas fijan es que el genérico se comporte como un detalle
 * interno del motor y no como un lote más: uno por insumo, código determinista,
 * fecha congelada, invisible en los listados de lotes y fuera del alcance de la
 * edición y el borrado.
 */
class PlantaLoteGenericoTest extends TestCase
{
    use InventarioPlantaFixtures;
    use RefreshDatabase;

    private function lotes(): LoteService
    {
        return app(LoteService::class);
    }

    public function test_se_crea_para_un_insumo_que_no_controla_lotes(): void
    {
        $bolsa = $this->insumoBolsa();

        $lote = $this->lotes()->resolverGenerico($bolsa, '2026-07-30');

        $this->assertTrue($lote->es_generico);
        $this->assertSame('GEN-'.$bolsa->id, $lote->codigo_interno);
        $this->assertSame($bolsa->id, $lote->planta_insumo_id);
        $this->assertSame('2026-07-30', $lote->fecha_recepcion->toDateString());
        $this->assertTrue($lote->activo);
    }

    public function test_se_reutiliza_en_las_llamadas_siguientes(): void
    {
        $bolsa = $this->insumoBolsa();

        $primero = $this->lotes()->resolverGenerico($bolsa, '2026-07-30');
        $segundo = $this->lotes()->resolverGenerico($bolsa, '2026-08-15');

        $this->assertSame($primero->id, $segundo->id);
    }

    public function test_no_se_duplica_aunque_se_pida_muchas_veces(): void
    {
        $bolsa = $this->insumoBolsa();

        for ($i = 0; $i < 10; $i++) {
            $this->lotes()->resolverGenerico($bolsa, '2026-07-30');
        }

        $this->assertSame(1, PlantaLote::where('planta_insumo_id', $bolsa->id)->genericos()->count());
    }

    public function test_cada_insumo_tiene_el_suyo(): void
    {
        $una = $this->insumoBolsa();
        $otra = $this->insumoBolsa();

        $primero = $this->lotes()->resolverGenerico($una, '2026-07-30');
        $segundo = $this->lotes()->resolverGenerico($otra, '2026-07-30');

        $this->assertNotSame($primero->id, $segundo->id);
        $this->assertSame('GEN-'.$una->id, $primero->codigo_interno);
        $this->assertSame('GEN-'.$otra->id, $segundo->codigo_interno);
    }

    public function test_conserva_la_fecha_de_la_operacion_que_lo_creo(): void
    {
        $bolsa = $this->insumoBolsa();

        $this->lotes()->resolverGenerico($bolsa, '2026-03-01');
        // Una operación posterior NO puede reescribir la fecha de entrada: esa
        // fecha es cuándo entró el insumo por primera vez, no cuándo se movió por
        // última vez, que ya está en el mayor.
        $reutilizado = $this->lotes()->resolverGenerico($bolsa, '2026-12-31');

        $this->assertSame('2026-03-01', $reutilizado->fecha_recepcion->toDateString());
    }

    public function test_lo_rechaza_un_insumo_que_controla_lotes(): void
    {
        $materiaPrima = $this->insumo(['controla_lotes' => true]);

        $this->expectException(LoteGenericoNoAplicableException::class);

        $this->lotes()->resolverGenerico($materiaPrima, '2026-07-30');
    }

    public function test_no_se_puede_eliminar(): void
    {
        $bolsa = $this->insumoBolsa();
        $lote = $this->lotes()->resolverGenerico($bolsa, '2026-07-30');

        $this->expectException(LoteGenericoNoAplicableException::class);

        $lote->delete();
    }

    public function test_no_se_puede_editar(): void
    {
        $bolsa = $this->insumoBolsa();
        $lote = $this->lotes()->resolverGenerico($bolsa, '2026-07-30');

        $lote->fecha_recepcion = '2026-01-01';

        $this->expectException(LoteGenericoNoAplicableException::class);

        $lote->save();
    }

    public function test_el_candado_no_alcanza_a_los_lotes_reales(): void
    {
        $insumo = $this->insumo();
        $real = $this->lote($insumo);

        $real->codigo_proveedor = 'ABC-123';
        $real->save();

        $this->assertSame('ABC-123', $real->fresh()->codigo_proveedor);
    }

    public function test_no_aparece_entre_los_lotes_reales(): void
    {
        $bolsa = $this->insumoBolsa();
        $generico = $this->lotes()->resolverGenerico($bolsa, '2026-07-30');

        $insumo = $this->insumo();
        $real = $this->lote($insumo);

        $reales = PlantaLote::reales()->pluck('id')->all();

        $this->assertContains($real->id, $reales);
        $this->assertNotContains($generico->id, $reales);
    }

    public function test_resolverlo_dentro_de_una_transaccion_tambien_funciona(): void
    {
        $bolsa = $this->insumoBolsa();

        // Es el caso real: la recepción lo pedirá con la transacción ya abierta, y
        // ahí es donde la relectura bloqueante importa.
        $lote = DB::transaction(fn () => $this->lotes()->resolverGenerico($bolsa, '2026-07-30'));

        $this->assertTrue($lote->es_generico);
        $this->assertSame(1, PlantaLote::genericos()->count());
    }
}
