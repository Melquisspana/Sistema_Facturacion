<?php

namespace Tests\Feature\Ppq;

use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\User;
use App\Services\Ppq\PpqBusquedaService;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Búsqueda de texto libre de PPQ, campo por campo.
 *
 * LA REGRESIÓN QUE FIJA. Al introducir la búsqueda exacta de correlativos, el
 * servicio pasó a desviar por la rama del correlativo CUALQUIER término que
 * contuviera un dígito, y a resolverla contra literales fijos (`M001P001`,
 * `M001P002`) y un relleno fijo de 15. Consecuencia: un número de control
 * completo, un código de generación o una orden de compra dejaron de encontrarse,
 * y los correlativos de 16 dígitos tampoco casaban.
 *
 * LA REGLA QUE SE EXIGE AQUÍ:
 *   - término SOLO de dígitos  -> correlativo, coincidencia EXACTA contra el
 *     número de control (y subcadena contra sello, código y orden de compra,
 *     que también pueden ser numéricos);
 *   - cualquier otro término   -> subcadena literal contra los cuatro campos;
 *   - si el correlativo ya existe en P002, se muestra ese y no el de P001;
 *   - un archivado nunca aparece.
 */
class PpqBusquedaCorrelativoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatosInicialesNegritaSeeder::class);
    }

    private function servicio(): PpqBusquedaService
    {
        return app(PpqBusquedaService::class);
    }

    private function dte(string $numeroControl, array $extra = []): Dte
    {
        return Dte::create($extra + [
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'cliente_id' => Cliente::where('nombre', 'like', '%Calleja%')->firstOrFail()->id,
            'numero_control' => $numeroControl,
            'numero_orden_compra' => '260600232002345',
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_pagar' => 113.58,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<int, int>
     */
    private function idsDe(array $filtros): array
    {
        return $this->servicio()->buscar($filtros)->pluck('id')->sort()->values()->all();
    }

    // --- 1 y 2. Referencias literales completas ------------------------------

    public function test_encuentra_por_numero_control_completo(): void
    {
        $dte = $this->dte('DTE-03-M001P002-000000000000151');

        $this->assertSame([$dte->id], $this->idsDe(['q' => 'DTE-03-M001P002-000000000000151']));
    }

    public function test_encuentra_por_codigo_de_generacion_completo(): void
    {
        $dte = $this->dte('DTE-03-M001P001-0000000000000986', [
            'codigo_generacion' => 'A1B2C3D4-1111-2222-3333-444455556666',
        ]);

        $this->assertSame([$dte->id], $this->idsDe(['q' => 'A1B2C3D4-1111-2222-3333-444455556666']));
    }

    public function test_encuentra_por_sello_de_recepcion(): void
    {
        $dte = $this->dte('DTE-03-M001P001-0000000000000986', ['sello_recepcion' => 'SELLO2026XYZ99']);

        $this->assertSame([$dte->id], $this->idsDe(['q' => 'SELLO2026XYZ99']));
    }

    // --- 3. Últimos cuatro ---------------------------------------------------

    public function test_encuentra_por_los_ultimos_cuatro_digitos(): void
    {
        $dte = $this->dte('DTE-03-M001P001-0000000000000986');

        $this->assertSame([$dte->id], $this->idsDe(['q' => '0986']));
    }

    public function test_los_ultimos_cuatro_funcionan_con_secuencia_de_quince_y_de_dieciseis(): void
    {
        // El ancho de la secuencia no es uniforme en los documentos históricos;
        // dar por sentado uno de los dos fue parte del defecto.
        $quince = $this->dte('DTE-03-M001P001-000000000000986');
        $dieciseis = $this->dte('DTE-03-M001P001-0000000000000987');

        $this->assertSame([$quince->id], $this->idsDe(['q' => '0986']));
        $this->assertSame([$dieciseis->id], $this->idsDe(['q' => '0987']));
    }

    public function test_el_correlativo_se_encuentra_con_o_sin_ceros_a_la_izquierda(): void
    {
        $dte = $this->dte('DTE-03-M001P001-0000000000000986');

        $this->assertSame([$dte->id], $this->idsDe(['q' => '986']));
        $this->assertSame([$dte->id], $this->idsDe(['q' => '0986']));
        $this->assertSame([$dte->id], $this->idsDe(['q' => '00986']));
    }

    // --- 4 y 11. Prioridad y ambigüedad entre P001 y P002 --------------------

    public function test_prioriza_p002_sobre_p001_con_el_mismo_correlativo(): void
    {
        $viejo = $this->dte('DTE-03-M001P001-000000000000006');
        $nuevo = $this->dte('DTE-03-M001P002-000000000000006');

        $encontrados = $this->idsDe(['q' => '0006']);

        $this->assertSame([$nuevo->id], $encontrados, 'Con P002 emitido, el histórico P001 no debe aparecer.');
        $this->assertNotContains($viejo->id, $encontrados);
    }

    public function test_sin_p002_se_conserva_el_historico_p001(): void
    {
        $viejo = $this->dte('DTE-03-M001P001-000000000000006');

        $this->assertSame([$viejo->id], $this->idsDe(['q' => '0006']));
    }

    public function test_la_prioridad_p002_no_alcanza_a_correlativos_distintos(): void
    {
        // Un P002 con OTRO correlativo no puede esconder el P001 que sí se pidió.
        $this->dte('DTE-03-M001P002-000000000000007');
        $pedido = $this->dte('DTE-03-M001P001-000000000000006');

        $this->assertSame([$pedido->id], $this->idsDe(['q' => '0006']));
    }

    public function test_la_prioridad_p002_no_cruza_el_filtro_de_tipo(): void
    {
        // La NC de P002 comparte correlativo, pero se está buscando CCF: no debe
        // dejar sin resultados la búsqueda del CCF histórico.
        $this->dte('DTE-05-M001P002-000000000000006', ['tipo_dte' => '05']);
        $ccf = $this->dte('DTE-03-M001P001-000000000000006');

        $this->assertSame([$ccf->id], $this->idsDe(['q' => '0006', 'tipo' => '03']));
    }

    // --- 5, 6 y 7. Tipo por defecto y notas de crédito -----------------------

    public function test_por_defecto_el_servicio_no_mezcla_tipos_cuando_se_pide_ccf(): void
    {
        $ccf = $this->dte('DTE-03-M001P001-0000000000000340');
        $this->dte('DTE-05-M001P001-0000000000000340', ['tipo_dte' => '05']);

        $this->assertSame([$ccf->id], $this->idsDe(['q' => '0340', 'tipo' => '03']));
    }

    public function test_la_nc_aparece_solo_con_el_filtro_explicito(): void
    {
        $this->dte('DTE-03-M001P001-0000000000000340');
        $nc = $this->dte('DTE-05-M001P001-0000000000000340', ['tipo_dte' => '05']);

        $this->assertSame([$nc->id], $this->idsDe(['q' => '0340', 'tipo' => '05']));
    }

    public function test_la_busqueda_web_devuelve_ccf_por_defecto_y_excluye_la_nc(): void
    {
        // El valor por defecto lo pone el controlador, no el servicio: se
        // comprueba por la ruta para que la regla completa quede cubierta.
        $this->dte('DTE-03-M001P001-0000000000000340');
        $this->dte('DTE-05-M001P001-0000000000000340', ['tipo_dte' => '05']);

        $usuario = User::factory()->create();
        Role::findOrCreate('facturacion', 'web');
        $usuario->assignRole('facturacion');

        $this->actingAs($usuario)->get(route('ppq.index', ['q' => '0340']))
            ->assertOk()
            ->assertSee('DTE-03-M001P001-0000000000000340', false)
            ->assertDontSee('DTE-05-M001P001-0000000000000340', false);
    }

    // --- 8 y 9. Archivado ----------------------------------------------------

    public function test_el_dte_aparece_antes_de_archivarse_y_desaparece_despues(): void
    {
        $dte = $this->dte('DTE-03-M001P001-0000000000000986', ['estado' => 'rechazado']);

        $this->assertSame([$dte->id], $this->idsDe(['q' => '0986']), 'Antes de archivar debe encontrarse.');

        $dte->forceFill(['archivado' => true, 'archivado_en' => now()])->save();

        $this->assertSame([], $this->idsDe(['q' => '0986']), 'Archivado no puede aparecer en la búsqueda.');
    }

    public function test_un_p002_archivado_no_esconde_el_p001_vigente(): void
    {
        // Si el P002 está archivado no cuenta como «ya existe en P002»: de lo
        // contrario la búsqueda se quedaría sin ningún resultado.
        $this->dte('DTE-03-M001P002-000000000000006', ['estado' => 'rechazado'])
            ->forceFill(['archivado' => true, 'archivado_en' => now()])->save();
        $vigente = $this->dte('DTE-03-M001P001-000000000000006');

        $this->assertSame([$vigente->id], $this->idsDe(['q' => '0006']));
    }

    // --- 10 y 12. Sin coincidencias y exactitud ------------------------------

    public function test_un_termino_sin_coincidencias_no_devuelve_nada(): void
    {
        $this->dte('DTE-03-M001P001-0000000000000986');

        $this->assertSame([], $this->idsDe(['q' => '4321']));
        $this->assertSame([], $this->idsDe(['q' => 'NO-EXISTE-XYZ']));
    }

    public function test_la_busqueda_exacta_no_se_contamina_con_coincidencias_parciales(): void
    {
        // `0003401` CONTIENE 0340 pero su correlativo es otro: con subcadena
        // aparecería, y ese arrastre es justo lo que la búsqueda exacta corrige.
        $exacto = $this->dte('DTE-03-M001P001-0000000000000340');
        $this->dte('DTE-03-M001P001-0000000000003401');

        $this->assertSame([$exacto->id], $this->idsDe(['q' => '0340']));
    }

    public function test_el_correlativo_no_casa_con_un_numero_mayor_que_lo_contiene(): void
    {
        // `100986` termina en `0986`, pero no es el correlativo 986.
        $exacto = $this->dte('DTE-03-M001P001-0000000000000986');
        $this->dte('DTE-03-M001P001-0000000000100986');

        $this->assertSame([$exacto->id], $this->idsDe(['q' => '0986']));
    }

    public function test_un_termino_numerico_tambien_encuentra_por_orden_de_compra(): void
    {
        // Una orden de compra es solo dígitos: la rama del correlativo no puede
        // dejarla fuera, que es lo que pasaba.
        $dte = $this->dte('DTE-03-M001P001-0000000000000986', ['numero_orden_compra' => '260600232009999']);

        $this->assertSame([$dte->id], $this->idsDe(['q' => '260600232009999']));
    }
}
