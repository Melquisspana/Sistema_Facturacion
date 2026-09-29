<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Services\Cobros\VinculadorAlbaranes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * El albarán que un item PPQ del circuito ANTERIOR guardó para un CCF se recupera al
 * vincular Cobros, pero solo como candidato con identidad: pasa por las mismas
 * contradicciones y por la misma regla de CCF que comparten OC que el de la orden de
 * compra, y cualquier colisión lo manda a revisión.
 *
 * No prueba presentación ni pago, y el item pudo haberlo recibido prellenado: por eso
 * nunca se salta ninguna comprobación.
 */
class CobrosVinculoHistoricoPpqTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private const OC = '26090017003463';

    private const CONTROL = 'DTE-03-M001P002-000000000000131';

    /** Código de generación del CCF de referencia: la segunda identidad del snapshot. */
    private const CODIGO = 'A1B2C3D4-0000-4000-8000-000000000131';

    private ?Establecimiento $estab = null;

    private Cliente $cliente;

    private int $lotes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
    }

    // ------------------------------------------------------------------ utilidades

    private function dte(string $control = self::CONTROL, string $oc = self::OC): Dte
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];

        return Dte::create([
            'establecimiento_id' => $this->estab->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'numero_control' => $control,
            'codigo_generacion' => $control === self::CONTROL ? self::CODIGO : strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'numero_orden_compra' => $oc,
            'fecha_emision' => '2026-09-18',
            'hora_emision' => '08:00:00',
            'total_pagar' => 123.74,
        ]);
    }

    /** Seguimiento del CCF, como lo deja el alta automática. */
    private function documento(?Dte $dte = null): CobroDocumento
    {
        $dte ??= $this->dte();

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

    private function albaran(string $numero, array $datos = []): PpqAlbaran
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

    /**
     * Item PPQ histórico. Por defecto: snapshot de Gmail (sin dte_id) del mismo CCF, con
     * su código de generación, que es lo que lo distingue de un homónimo de otro ambiente.
     */
    private function item(?PpqAlbaran $albaran, array $datos = []): PpqItem
    {
        // Un lote por item; el primero se llama PPQ-JUNIO (la referencia puede ser única).
        $n = ++$this->lotes;
        $lote = PpqLote::create(['referencia' => $n === 1 ? 'PPQ-JUNIO' : 'PPQ-JUNIO-'.$n, 'fecha' => now(), 'estado' => 'listo']);

        return PpqItem::create($datos + [
            'ppq_lote_id' => $lote->id,
            'origen' => 'gmail',
            'tipo_dte' => '03',
            'numero_control' => self::CONTROL,
            'codigo_generacion' => self::CODIGO,
            'numero_orden_compra' => self::OC,
            'monto_dte' => 123.74,
            'ppq_albaran_id' => $albaran?->id,
            'monto_albaran' => $albaran?->monto_albaran,
            'sin_albaran' => $albaran === null,
        ]);
    }

    private function auditar(CobroDocumento $documento): array
    {
        return app(VinculadorAlbaranes::class)->auditar($documento->fresh());
    }

    // ------------------------------------------------------------------ recuperación

    public function test_recupera_el_albaran_guardado_cuando_la_oc_sola_es_ambigua(): void
    {
        $a = $this->albaran('AC01/0017/00/5131');
        $this->albaran('AC01/0017/00/5132', ['monto_albaran' => '99.10']);
        $documento = $this->documento();

        // Sin item, la OC tiene dos albaranes: revisión (comportamiento de siempre).
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $this->auditar($documento)['estado']);

        $this->item($a);
        $veredicto = $this->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, $veredicto['estado']);
        $this->assertSame($a->id, $veredicto['albaran_id']);
        $this->assertStringContainsString('lote PPQ PPQ-JUNIO', (string) $veredicto['motivo']);

        // auditar no escribe nada.
        $this->assertNull($documento->fresh()->ppq_albaran_id);
        $this->assertSame(EstadoVinculacionAlbaran::SinAlbaran, $documento->fresh()->vinculacion_estado);
    }

    public function test_aplicar_vincula_sin_tocar_presentacion_pago_ni_revision_historica(): void
    {
        $a = $this->albaran('AC01/0017/00/5131');
        $this->albaran('AC01/0017/00/5132', ['monto_albaran' => '99.10']);
        $documento = $this->documento();
        $documento->forceFill(['revisar_historico' => true, 'revisar_historico_motivo' => 'Ya viajó en PPQ-JUNIO.'])->save();
        // El item del lote figura conciliado: eso NO se copia al seguimiento.
        $this->item($a, ['conciliacion_estado' => 'pagado', 'monto_pagado' => 123.74]);

        $estado = app(VinculadorAlbaranes::class)->aplicar($documento);

        $documento->refresh();
        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, $estado);
        $this->assertSame($a->id, $documento->ppq_albaran_id);
        // Lo persistido dice POR QUÉ: el lote PPQ, no la fórmula de la comprobación bajo bloqueo.
        $this->assertStringContainsString('lote PPQ PPQ-JUNIO', (string) $documento->vinculacion_motivo);
        $this->assertSame($a->id, $documento->vinculacion_candidatos[0]['id']);
        $this->assertSame('sin_presentar', $documento->presentacion_estado->value);
        $this->assertSame('pendiente', $documento->pago_estado->value);
        $this->assertSame(0, bccomp('0', (string) $documento->monto_pagado, 2));
        $this->assertTrue($documento->revisar_historico);
        $this->assertSame('Ya viajó en PPQ-JUNIO.', $documento->revisar_historico_motivo);
    }

    public function test_con_muchos_lotes_de_referencia_larga_el_motivo_cabe_en_la_columna(): void
    {
        $a = $this->albaran('AC01/0017/00/5131');
        $this->albaran('AC01/0017/00/5132', ['monto_albaran' => '99.10']);
        $documento = $this->documento();

        // Cinco lotes del mismo CCF, todos con el mismo albarán y referencias de 120 caracteres.
        $lotes = [];
        foreach (range(1, 5) as $n) {
            $lote = PpqLote::create([
                'referencia' => 'LOTE-'.$n.'-'.str_repeat('X', 113),
                'fecha' => now(),
                'estado' => 'listo',
            ]);
            $lotes[] = $lote;
            $this->item($a, ['ppq_lote_id' => $lote->id]);
        }

        $estado = app(VinculadorAlbaranes::class)->aplicar($documento);

        $motivo = (string) $documento->refresh()->vinculacion_motivo;
        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, $estado);
        $this->assertSame($a->id, $documento->ppq_albaran_id);
        $this->assertLessThanOrEqual(255, mb_strlen($motivo), 'vinculacion_motivo es VARCHAR(255).');
        // Rastreable: los dos primeros lotes por su id y cuántos más hay.
        $this->assertStringContainsString('lote PPQ LOTE-1-', $motivo);
        $this->assertStringContainsString('(#'.$lotes[0]->id.')', $motivo);
        $this->assertStringContainsString('(#'.$lotes[1]->id.')', $motivo);
        $this->assertStringContainsString('y 3 más', $motivo);
    }

    public function test_muchos_ccf_con_la_misma_oc_se_guardan_en_revision_sin_desbordar_el_motivo(): void
    {
        $a = $this->albaran('AC01/0017/00/5131');
        $documento = $this->documento();
        $this->item($a);
        // Doce CCF más con la misma OC: la lista de competidores pasa de 255 caracteres.
        foreach (range(200, 211) as $n) {
            $this->documento($this->dte('DTE-03-M001P002-000000000000'.$n));
        }
        $documento->forceFill(['revisar_historico' => true, 'revisar_historico_motivo' => 'Ya viajó en PPQ-JUNIO.'])->save();

        // Lo que se muestra en lectura sigue entero.
        $auditoria = $this->auditar($documento);
        $this->assertGreaterThan(255, mb_strlen((string) $auditoria['motivo']));

        $estado = app(VinculadorAlbaranes::class)->aplicar($documento);

        $documento->refresh();
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $estado);
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $documento->vinculacion_estado);
        $this->assertNull($documento->ppq_albaran_id);
        $motivo = (string) $documento->vinculacion_motivo;
        $this->assertLessThanOrEqual(255, mb_strlen($motivo));
        $this->assertStringStartsWith('Coincide por albarán guardado en el lote PPQ', $motivo);
        $this->assertStringContainsString('pero algo no cuadra', $motivo);
        $this->assertSame($a->id, $documento->vinculacion_candidatos[0]['id'], 'Los candidatos se guardan completos.');
        $this->assertSame('sin_presentar', $documento->presentacion_estado->value);
        $this->assertSame('pendiente', $documento->pago_estado->value);
        $this->assertTrue($documento->revisar_historico);
    }

    public function test_el_snapshot_se_reconoce_por_el_control_completo_y_no_por_el_correlativo(): void
    {
        $a = $this->albaran('AC01/0017/00/5131');
        $this->albaran('AC01/0017/00/5132', ['monto_albaran' => '99.10']);
        $documento = $this->documento();

        // Solo el correlativo: no es identidad. Se queda en la ambigüedad de la OC.
        $this->item($a, ['numero_control' => '131']);
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $this->auditar($documento)['estado']);
        $this->assertStringContainsString('sin vincular', (string) $this->auditar($documento)['motivo']);

        PpqItem::query()->delete();

        // El control completo escrito sin separadores sí lo es.
        $this->item($a, ['numero_control' => 'DTE03M001P002000000000000131']);
        $veredicto = $this->auditar($documento);
        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, $veredicto['estado']);
        $this->assertSame($a->id, $veredicto['albaran_id']);
    }

    public function test_un_item_sin_albaran_sigue_la_logica_de_la_oc(): void
    {
        $a = $this->albaran('AC01/0017/00/5131');
        $this->albaran('AC01/0017/00/5132', ['monto_albaran' => '99.10']);
        $documento = $this->documento();
        // Marcado «sin albarán» aunque tenga un id puesto: no es evidencia.
        $this->item($a, ['sin_albaran' => true]);

        $veredicto = $this->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertStringContainsString('2 albaranes de entrega sin vincular', (string) $veredicto['motivo']);
    }

    // ------------------------------------------------------------------ colisiones

    public function test_dos_ccf_que_comparten_oc_siguen_en_revision_aunque_haya_item(): void
    {
        $a = $this->albaran('AC01/0017/00/5131');
        $documento = $this->documento();
        $this->documento($this->dte('DTE-03-M001P002-000000000000132'));
        $this->item($a);

        $veredicto = $this->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertStringContainsString('otros CCF comparten la orden de compra', (string) $veredicto['motivo']);
    }

    public function test_un_albaran_reclamado_por_items_de_dos_documentos_va_a_revision(): void
    {
        $a = $this->albaran('AC01/0017/00/5131');
        $documento = $this->documento();
        $this->item($a);
        $this->item($a, ['numero_control' => 'DTE-03-M001P002-000000000000900']);

        $veredicto = $this->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertStringContainsString('items de otros documentos', (string) $veredicto['motivo']);
        $this->assertStringContainsString('000000000000900', (string) $veredicto['motivo']);
    }

    public function test_dos_albaranes_distintos_para_el_mismo_ccf_van_a_revision(): void
    {
        $a = $this->albaran('AC01/0017/00/5131');
        $b = $this->albaran('AC01/0017/00/5132', ['monto_albaran' => '99.10']);
        $documento = $this->documento();
        $this->item($a);
        $this->item($b);

        $veredicto = $this->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertStringContainsString('2 albaranes distintos', (string) $veredicto['motivo']);
        $this->assertCount(2, $veredicto['candidatos']);
    }

    public function test_el_vinculo_explicito_no_tapa_un_item_que_apunta_a_otra_entrega(): void
    {
        $dte = $this->dte();
        $documento = $this->documento($dte);
        $explicito = $this->albaran('AC01/0017/00/5132', ['dte_id' => $dte->id]);
        $this->item($this->albaran('AC01/0017/00/5131'));

        $veredicto = $this->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertStringContainsString('apunta explícitamente', (string) $veredicto['motivo']);
        $this->assertCount(2, $veredicto['candidatos']);
        $this->assertContains($explicito->id, array_column($veredicto['candidatos'], 'id'));
    }

    // ------------------------------------------------------------------ tipos que no sirven

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function tiposQueNoPruebanEntrega(): array
    {
        return [
            'avería AC02' => ['AC02/0017/00/5199', 'AC02'],
            'devolución AC04' => ['AC04/0017/00/5199', 'AC04'],
            'sin tipo' => ['5199', null],
        ];
    }

    #[DataProvider('tiposQueNoPruebanEntrega')]
    public function test_un_albaran_que_no_es_de_entrega_no_se_usa(string $numero, ?string $tipo): void
    {
        $entrega = $this->albaran('AC01/0017/00/5131');
        $credito = $this->albaran($numero, ['tipo_codigo' => $tipo]);
        $documento = $this->documento();
        $this->item($credito);

        $veredicto = $this->auditar($documento);

        // Se ignora el del item y decide la OC de siempre, con el único AC01.
        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, $veredicto['estado']);
        $this->assertSame($entrega->id, $veredicto['albaran_id']);
        $this->assertStringContainsString('orden de compra', (string) $veredicto['motivo']);
        $this->assertStringNotContainsString('lote PPQ', (string) $veredicto['motivo']);
    }

    // ------------------------------------------------------------------ contradicciones

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}> */
    public static function contradicciones(): array
    {
        return [
            'importe' => [['monto_albaran' => '100.00'], [], 'importe del CCF'],
            'sala' => [['sala_codigo' => '0261'], [], 'sala 0017'],
            'OC del albarán' => [['numero_orden_compra' => '26090261004111'], [], 'orden de compra no es'],
            'OC del item' => [[], ['numero_orden_compra' => '26090261004111'], 'orden de compra no es'],
        ];
    }

    // ------------------------------------------------------------------ OC e identidad del item

    public function test_un_item_sin_orden_de_compra_no_acredita_el_albaran(): void
    {
        // Antes la OC vacía del item se descartaba y el vínculo salía automático.
        $a = $this->albaran('AC01/0017/00/5131');
        $this->albaran('AC01/0017/00/5132', ['monto_albaran' => '99.10']);
        $documento = $this->documento();
        $this->item($a, ['numero_orden_compra' => null]);

        $veredicto = $this->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertNull($veredicto['albaran_id']);
        $this->assertStringContainsString('2 albaranes de entrega sin vincular', (string) $veredicto['motivo']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: EstadoVinculacionAlbaran, 2: string}> */
    public static function segundaIdentidadDelSnapshot(): array
    {
        return [
            'código igual, en minúsculas y con espacios' => [
                ['codigo_generacion' => '  '.strtolower(self::CODIGO).' '], EstadoVinculacionAlbaran::Vinculado, 'lote PPQ'],
            'sin código, con el sello del DTE' => [
                ['codigo_generacion' => null, 'sello_recepcion' => 'SELLO-DEL-DTE'], EstadoVinculacionAlbaran::Vinculado, 'lote PPQ'],
            'mismo control, código distinto' => [
                ['codigo_generacion' => 'FFFFFFFF-0000-4000-8000-000000000131'], EstadoVinculacionAlbaran::Revisar, 'mezcla la identidad'],
            'código igual, sello distinto' => [
                ['sello_recepcion' => 'OTRO-SELLO'], EstadoVinculacionAlbaran::Revisar, 'mezcla la identidad'],
            // Sin segunda identidad no es evidencia: decide la OC, que tiene dos albaranes.
            'mismo control, sin código ni sello' => [
                ['codigo_generacion' => null], EstadoVinculacionAlbaran::Revisar, 'sin vincular'],
        ];
    }

    #[DataProvider('segundaIdentidadDelSnapshot')]
    public function test_el_snapshot_exige_una_segunda_identidad_que_no_contradiga(array $datosItem, EstadoVinculacionAlbaran $esperado, string $motivo): void
    {
        $a = $this->albaran('AC01/0017/00/5131');
        $this->albaran('AC01/0017/00/5132', ['monto_albaran' => '99.10']);
        $dte = $this->dte();
        $dte->forceFill(['sello_recepcion' => 'SELLO-DEL-DTE'])->save();
        $documento = $this->documento($dte);
        $this->item($a, $datosItem);

        $veredicto = $this->auditar($documento);

        $this->assertSame($esperado, $veredicto['estado']);
        $this->assertStringContainsString($motivo, (string) $veredicto['motivo']);
        if ($esperado === EstadoVinculacionAlbaran::Vinculado) {
            $this->assertSame($a->id, $veredicto['albaran_id']);
        } elseif ($motivo === 'mezcla la identidad') {
            // El albarán del item queda a la vista para decidir.
            $this->assertSame([$a->id], array_column($veredicto['candidatos'], 'id'));
        }
    }

    public function test_un_item_sin_tipo_no_acredita_y_uno_de_otro_tipo_se_revisa(): void
    {
        $a = $this->albaran('AC01/0017/00/5131');
        $this->albaran('AC01/0017/00/5132', ['monto_albaran' => '99.10']);
        $documento = $this->documento();

        // Sin tipo, aunque control, código y OC coincidan: no es evidencia; decide la OC.
        $item = $this->item($a, ['tipo_dte' => null]);
        $veredicto = $this->auditar($documento);
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertNull($veredicto['albaran_id']);
        $this->assertStringContainsString('2 albaranes de entrega sin vincular', (string) $veredicto['motivo']);

        // Con tipo NC: el item se contradice con un CCF.
        $item->forceFill(['tipo_dte' => '05'])->save();
        $veredicto = $this->auditar($documento);
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertStringContainsString('mezcla la identidad', (string) $veredicto['motivo']);
    }

    public function test_un_item_con_dte_id_exacto_no_necesita_segundo_dato_pero_no_puede_contradecir(): void
    {
        $a = $this->albaran('AC01/0017/00/5131');
        $this->albaran('AC01/0017/00/5132', ['monto_albaran' => '99.10']);
        $documento = $this->documento();

        $item = $this->item($a, ['origen' => 'local', 'dte_id' => $documento->dte_id, 'codigo_generacion' => null]);
        $veredicto = $this->auditar($documento);
        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, $veredicto['estado']);
        $this->assertSame($a->id, $veredicto['albaran_id']);

        $item->forceFill(['codigo_generacion' => 'FFFFFFFF-0000-4000-8000-000000000131'])->save();
        $veredicto = $this->auditar($documento);
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertStringContainsString('mezcla la identidad', (string) $veredicto['motivo']);
    }

    // ------------------------------------------------------------------ explícito contra historial

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function historialQueContradiceAlExplicito(): array
    {
        return [
            'OC del item distinta' => [['numero_orden_compra' => '26090261004111']],
            'código de generación distinto' => [['codigo_generacion' => 'FFFFFFFF-0000-4000-8000-000000000131']],
        ];
    }

    #[DataProvider('historialQueContradiceAlExplicito')]
    public function test_el_explicito_no_se_vincula_si_el_historial_se_contradice(array $datosItem): void
    {
        $dte = $this->dte();
        $documento = $this->documento($dte);
        $explicito = $this->albaran('AC01/0017/00/5132', ['dte_id' => $dte->id]);
        $historico = $this->albaran('AC01/0017/00/5131');
        $this->item($historico, $datosItem);

        $veredicto = $this->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertStringContainsString('historial PPQ se contradice', (string) $veredicto['motivo']);
        $ids = array_column($veredicto['candidatos'], 'id');
        sort($ids);
        $this->assertSame([$explicito->id, $historico->id], $ids, 'Ambos candidatos, sin repetir.');
    }

    public function test_el_explicito_sigue_mandando_si_el_historial_no_es_evidencia(): void
    {
        $dte = $this->dte();
        $documento = $this->documento($dte);
        $explicito = $this->albaran('AC01/0017/00/5132', ['dte_id' => $dte->id]);
        // Item sin OC: no acredita nada, ni a favor ni en contra.
        $this->item($this->albaran('AC01/0017/00/5131'), ['numero_orden_compra' => null]);

        $veredicto = $this->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, $veredicto['estado']);
        $this->assertSame($explicito->id, $veredicto['albaran_id']);
    }

    #[DataProvider('contradicciones')]
    public function test_una_contradiccion_manda_a_revision_aunque_el_item_lo_guarde(array $albaran, array $item, string $motivo): void
    {
        $a = $this->albaran('AC01/0017/00/5131', $albaran);
        $documento = $this->documento();
        $this->item($a, $item);

        $veredicto = $this->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertNull($veredicto['albaran_id']);
        $this->assertStringContainsString($motivo, (string) $veredicto['motivo']);
    }
}
