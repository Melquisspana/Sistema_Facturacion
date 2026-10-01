<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PpqAlbaran;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Punto 1 de docs/REDISENO_SEGUIMIENTO_CALLEJA_20260924.md: unir automáticamente en Cobros
 * los albaranes AC01 ya importados que `VinculadorAlbaranes` audite como ÚNICOS y sin
 * contradicciones, en una corrida programada, sin que nadie pulse «auditar».
 *
 * Lo que protege, en orden de importancia:
 *
 *  1. Que `cobros.vinculacion.automatica` sea una llave APARTE de `cobros.alta.automatica`:
 *     encender el alta no vincula nada de rebote.
 *  2. Que encendida, vincule lo ÚNICO y deje «revisar»/«sin albarán» con su motivo, sin
 *     inferir por importe ni fecha (eso ya lo prueba VinculadorAlbaranes a fondo; acá solo
 *     se comprueba que el COMANDO llega a escribirlo sin que alguien audite a mano).
 *  3. Que repetir la corrida sin cambios reales no reescriba `vinculado_en` ni
 *     `vinculacion_motivo` (nada de escrituras sin cambio material).
 *  4. Que cubra más de 500 CCF sin quedar siempre atrapada en los primeros 500.
 */
class CobrosVinculacionAutomaticaTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private const OC = '26090017003463';

    private ?Establecimiento $estab = null;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
    }

    // ------------------------------------------------------------------ utilidades

    private function dte(string $control, string $oc = self::OC): Dte
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];

        return Dte::create([
            'establecimiento_id' => $this->estab->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'numero_control' => $control,
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'numero_orden_compra' => $oc,
            'fecha_emision' => '2026-09-18',
            'hora_emision' => '08:00:00',
            'total_pagar' => 123.74,
        ]);
    }

    private function documento(Dte $dte): CobroDocumento
    {
        return CobroDocumento::create([
            'cliente_id' => $this->cliente->id,
            'origen' => OrigenCobroDocumento::Dte->value,
            'dte_id' => $dte->id,
            'tipo_dte' => '03',
            'numero_control' => $dte->numero_control,
            'fecha_emision' => '2026-09-18',
            'monto' => '123.74',
        ]);
    }

    private function albaranEntrega(string $numero, array $datos = []): PpqAlbaran
    {
        return PpqAlbaran::create($datos + [
            'numero_albaran' => $numero,
            'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-09-17',
            'monto_albaran' => '123.74',
            'numero_orden_compra' => self::OC,
            'sala_codigo' => '0017',
        ]);
    }

    // ------------------------------------------------------------------ dorada

    /**
     * DORADA · con las dos llaves encendidas, la corrida vincula el candidato único y deja
     * el que no tiene albarán con su motivo a la vista, SIN que nadie haya pulsado
     * «auditar» en la pantalla.
     */
    public function test_dorada_vincula_lo_unico_y_deja_el_resto_con_motivo_sin_pulsar_auditar(): void
    {
        $ccfConAlbaran = $this->documento($this->dte('DTE-03-M001P002-000000000000001'));
        $albaran = $this->albaranEntrega('AC01/0017/00/0001');

        $ccfSinAlbaran = $this->documento($this->dte('DTE-03-M001P002-000000000000002', '26090099009999'));

        config()->set('cobros.alta.automatica', true);
        config()->set('cobros.vinculacion.automatica', true);

        $this->artisan('cobros:sincronizar', [
            '--cliente' => $this->cliente->id, '--aplicar' => true, '--vincular' => true,
        ])->assertExitCode(0);

        $ccfConAlbaran->refresh();
        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, $ccfConAlbaran->vinculacion_estado);
        $this->assertSame($albaran->id, $ccfConAlbaran->ppq_albaran_id);
        $this->assertNotNull($ccfConAlbaran->vinculado_en);

        $ccfSinAlbaran->refresh();
        $this->assertSame(EstadoVinculacionAlbaran::SinAlbaran, $ccfSinAlbaran->vinculacion_estado);
        $this->assertNull($ccfSinAlbaran->ppq_albaran_id);
        $this->assertNotNull($ccfSinAlbaran->vinculacion_motivo, 'El motivo tiene que quedar a la vista.');
    }

    /**
     * DORADA · dos albaranes libres para la misma OC: ambiguo, se queda para revisar y no
     * se elige ninguno por parecido.
     */
    public function test_dorada_ambiguo_no_vincula_ninguno_y_queda_para_revisar(): void
    {
        $ccf = $this->documento($this->dte('DTE-03-M001P002-000000000000003'));
        $this->albaranEntrega('AC01/0017/00/0003');
        $this->albaranEntrega('AC01/0017/00/0004');

        config()->set('cobros.alta.automatica', true);
        config()->set('cobros.vinculacion.automatica', true);

        $this->artisan('cobros:sincronizar', [
            '--cliente' => $this->cliente->id, '--aplicar' => true, '--vincular' => true,
        ])->assertExitCode(0);

        $ccf->refresh();
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $ccf->vinculacion_estado);
        $this->assertNull($ccf->ppq_albaran_id, 'Ambiguo: no se elige uno por parecido.');
        $this->assertNotNull($ccf->vinculacion_motivo);
    }

    /**
     * DORADA · `cobros.vinculacion.automatica` es una llave APARTE de `cobros.alta.automatica`.
     * Con el alta encendida y esta apagada, el CCF entra al seguimiento pero no se vincula
     * ningún albarán, y el comando lo dice.
     */
    public function test_dorada_la_llave_de_vinculacion_es_aparte_de_la_del_alta(): void
    {
        $ccf = $this->documento($this->dte('DTE-03-M001P002-000000000000005'));
        $this->albaranEntrega('AC01/0017/00/0005');

        config()->set('cobros.alta.automatica', true);
        config()->set('cobros.vinculacion.automatica', false);

        $this->artisan('cobros:sincronizar', [
            '--cliente' => $this->cliente->id, '--aplicar' => true, '--vincular' => true,
        ])->expectsOutputToContain('COBROS_VINCULACION_AUTO=false')
            ->assertExitCode(0);

        $ccf->refresh();
        $this->assertNull($ccf->ppq_albaran_id);
        $this->assertNull($ccf->vinculado_en, 'Sin la llave, la corrida no debe escribir ningún vínculo.');
    }

    /** El ensayo en seco audita la vinculación pero nunca la escribe, con la llave en cualquier estado. */
    public function test_el_ensayo_en_seco_audita_la_vinculacion_sin_escribir(): void
    {
        $ccf = $this->documento($this->dte('DTE-03-M001P002-000000000000006'));
        $this->albaranEntrega('AC01/0017/00/0006');

        config()->set('cobros.vinculacion.automatica', true);

        $this->artisan('cobros:sincronizar', ['--cliente' => $this->cliente->id, '--vincular' => true])
            ->expectsOutputToContain('1 se vincularían')
            ->assertExitCode(0);

        $ccf->refresh();
        $this->assertNull($ccf->ppq_albaran_id, 'El ensayo en seco no escribe.');
        $this->assertNull($ccf->vinculado_en);
    }

    /**
     * Repetir la corrida sin que nada cambie no reescribe `vinculado_en` ni el motivo: el
     * documento sigue «revisar» (mismo motivo) y el veredicto no cambió.
     */
    public function test_repetir_sin_cambios_no_reescribe_el_vinculo(): void
    {
        $ccf = $this->documento($this->dte('DTE-03-M001P002-000000000000007'));
        $this->albaranEntrega('AC01/0017/00/0007');
        $this->albaranEntrega('AC01/0017/00/0008');

        config()->set('cobros.alta.automatica', true);
        config()->set('cobros.vinculacion.automatica', true);

        $this->artisan('cobros:sincronizar', [
            '--cliente' => $this->cliente->id, '--aplicar' => true, '--vincular' => true,
        ])->assertExitCode(0);

        $ccf->refresh();
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $ccf->vinculacion_estado);
        $primeraVez = $ccf->vinculado_en;
        $primerMotivo = $ccf->vinculacion_motivo;
        $this->assertNotNull($primeraVez);

        $this->travel(2)->hours();

        $this->artisan('cobros:sincronizar', [
            '--cliente' => $this->cliente->id, '--aplicar' => true, '--vincular' => true,
        ])->assertExitCode(0);

        $ccf->refresh();
        $this->assertSame($primeraVez->toDateTimeString(), $ccf->vinculado_en->toDateTimeString(),
            'Sin cambio de veredicto, la segunda corrida no debe tocar vinculado_en.');
        $this->assertSame($primerMotivo, $ccf->vinculacion_motivo);
    }

    public function test_actualiza_candidatos_si_cambian_aunque_el_motivo_siga_igual(): void
    {
        $ccf = $this->documento($this->dte('DTE-03-M001P002-000000000090041'));
        $anteriorA = $this->albaranEntrega('AC01/0017/00/0040');
        $anteriorB = $this->albaranEntrega('AC01/0017/00/0041');

        config()->set('cobros.alta.automatica', true);
        config()->set('cobros.vinculacion.automatica', true);

        $this->artisan('cobros:sincronizar', [
            '--cliente' => $this->cliente->id, '--aplicar' => true, '--vincular' => true,
        ])->assertExitCode(0);

        $ccf->refresh();
        $motivo = $ccf->vinculacion_motivo;
        $vinculadoEn = $ccf->vinculado_en;
        $this->assertEqualsCanonicalizing([$anteriorA->id, $anteriorB->id], array_column($ccf->vinculacion_candidatos, 'id'));

        $anteriorA->delete();
        $anteriorB->delete();
        $nuevoA = $this->albaranEntrega('AC01/0017/00/0042');
        $nuevoB = $this->albaranEntrega('AC01/0017/00/0043');
        $this->travel(2)->hours();

        $this->artisan('cobros:sincronizar', [
            '--cliente' => $this->cliente->id, '--aplicar' => true, '--vincular' => true,
        ])->assertExitCode(0);

        $ccf->refresh();
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $ccf->vinculacion_estado);
        $this->assertSame($motivo, $ccf->vinculacion_motivo);
        $this->assertEqualsCanonicalizing([$nuevoA->id, $nuevoB->id], array_column($ccf->vinculacion_candidatos, 'id'));
        $this->assertTrue($ccf->vinculado_en->greaterThan($vinculadoEn));
    }

    /**
     * La vinculación automática no toca vínculos manuales, pagos, presentación, revisión
     * histórica ni el DTE: solo lee `dtes`/`ppq_albaranes` y escribe las columnas de
     * vinculación de un documento que todavía no tiene albarán.
     */
    public function test_no_toca_vinculos_manuales_pagos_presentacion_ni_revision_historica(): void
    {
        $dteYaVinculado = $this->dte('DTE-03-M001P002-000000000000009');
        $ccfYaVinculado = $this->documento($dteYaVinculado);
        $albaranManual = $this->albaranEntrega('AC01/0017/00/0009');
        $ccfYaVinculado->forceFill([
            'ppq_albaran_id' => $albaranManual->id,
            'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value,
            'vinculacion_motivo' => 'Vinculado a mano por alguien.',
            'vinculado_en' => now()->subDays(3),
            'presentacion_estado' => 'presentada',
            'monto_pagado' => '50.00',
            'pago_estado' => 'parcial',
            'revisar_historico' => true,
            'revisar_historico_motivo' => 'Sin rastro del circuito anterior.',
            'observaciones' => 'No perder esto.',
        ])->save();
        $antesFiscal = $dteYaVinculado->only(['estado', 'numero_control', 'sello_recepcion', 'total_pagar']);

        config()->set('cobros.alta.automatica', true);
        config()->set('cobros.vinculacion.automatica', true);

        $this->artisan('cobros:sincronizar', [
            '--cliente' => $this->cliente->id, '--aplicar' => true, '--vincular' => true,
        ])->assertExitCode(0);

        $ccfYaVinculado->refresh();
        $this->assertSame($albaranManual->id, $ccfYaVinculado->ppq_albaran_id);
        $this->assertSame('Vinculado a mano por alguien.', $ccfYaVinculado->vinculacion_motivo);
        $this->assertSame('presentada', $ccfYaVinculado->presentacion_estado->value);
        $this->assertSame('50.00', $ccfYaVinculado->monto_pagado);
        $this->assertSame('parcial', $ccfYaVinculado->pago_estado->value);
        $this->assertTrue($ccfYaVinculado->revisar_historico);
        $this->assertSame('No perder esto.', $ccfYaVinculado->observaciones);
        $this->assertSame($antesFiscal, $dteYaVinculado->refresh()->only(array_keys($antesFiscal)));
    }

    /**
     * Cubre más de 500 CCF sin quedar atrapada siempre en los primeros 500: el `chunkById`
     * del comando tiene que recorrer el total.
     */
    public function test_cubre_mas_de_500_ccf_sin_quedar_atrapada_en_los_primeros(): void
    {
        $total = 520;

        for ($i = 1; $i <= $total; $i++) {
            CobroDocumento::create([
                'cliente_id' => $this->cliente->id,
                'origen' => OrigenCobroDocumento::Externo->value,
                'tipo_dte' => '03',
                'numero_control' => sprintf('DTE-03-M001P002-%015d', $i),
                'fecha_emision' => '2026-09-18',
                'monto' => '10.00',
            ]);
        }

        config()->set('cobros.vinculacion.automatica', true);

        $this->artisan('cobros:sincronizar', [
            '--cliente' => $this->cliente->id, '--aplicar' => false, '--vincular' => true,
        ])->expectsOutputToContain($total.' sin albarán')
            ->assertExitCode(0);
    }
}
