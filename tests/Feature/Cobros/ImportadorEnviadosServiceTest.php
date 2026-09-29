<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroDocumentoProcedencia;
use App\Models\Cobros\CobroDocumentoRelacion;
use App\Models\Dte;
use App\Services\Cobros\ImportadorEnviados\ResultadoImportacionEnviados;
use App\Services\Cobros\ImportadorEnviadosService;
use App\Services\Ppq\GmailClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * LA IMPORTACIÓN DE CCF/NC DE CONTA DESDE CORREOS ENVIADOS, CONTRA UN GMAIL DE MENTIRA.
 *
 * Ningún caso llama a Gmail de verdad: {@see GmailClientDeImportacion} sirve páginas de
 * ids y adjuntos desde memoria. Se comprueba que en seco no se escribe nada, que la
 * identidad fiscal manda (y sus contradicciones quedan como excepción), que la relación
 * NC→CCF es solo la que declara el JSON, y que repetir la corrida no duplica.
 */
class ImportadorEnviadosServiceTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private const NIT = '0614-010101-101-1';

    private Cliente $cliente;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-25 10:00:00');
        config(['cobros.dias_revision_historica' => 30]);

        $this->cliente = Cliente::factory()->contribuyente()->create(['num_documento' => self::NIT]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------ apoyo

    private function codigo(): string
    {
        return strtoupper(Str::uuid()->toString());
    }

    /** @return array<string, mixed> */
    private function ccf(string $codigo, array $extra = []): array
    {
        $this->n++;

        return array_replace_recursive([
            'identificacion' => [
                'numeroControl' => 'DTE-03-M001P001-'.str_pad((string) $this->n, 15, '0', STR_PAD_LEFT),
                'codigoGeneracion' => $codigo,
                'tipoDte' => '03',
                'fecEmi' => '2026-09-10',
            ],
            'resumen' => ['totalPagar' => 100.0],
            'receptor' => ['nit' => self::NIT, 'nombre' => 'Calleja'],
            'selloRecibido' => '2026SELLO'.$codigo,
        ], $extra);
    }

    /** @param  array<int, string>  $relacionados */
    private function nc(string $codigo, array $relacionados, array $extra = []): array
    {
        $this->n++;

        return array_replace_recursive([
            'identificacion' => [
                'numeroControl' => 'DTE-05-M001P001-'.str_pad((string) $this->n, 15, '0', STR_PAD_LEFT),
                'codigoGeneracion' => $codigo,
                'tipoDte' => '05',
                'fecEmi' => '2026-09-12',
            ],
            'resumen' => ['montoTotalOperacion' => 10.0],
            'receptor' => ['nit' => self::NIT, 'nombre' => 'Calleja'],
            'documentoRelacionado' => array_map(fn ($c) => [
                'tipoDocumento' => '03', 'tipoGeneracion' => 2, 'numeroDocumento' => $c, 'fechaEmision' => '2026-09-10',
            ], $relacionados),
            'selloRecibido' => '2026SELLO'.$codigo,
        ], $extra);
    }

    private function adjunto(array $json, string $nombre = 'dte.json'): array
    {
        return ['filename' => $nombre, 'mime' => 'application/json', 'data' => json_encode($json)];
    }

    /**
     * @param  array<int, array<int, string>>  $paginas
     * @param  array<string, array<int, array<string, string>>>  $adjuntos
     */
    private function gmail(array $paginas, array $adjuntos): void
    {
        $this->app->instance(GmailClient::class, new GmailClientDeImportacion($paginas, $adjuntos));
    }

    private function importar(bool $aplicar = true, int $limite = 100): ResultadoImportacionEnviados
    {
        return app(ImportadorEnviadosService::class)->importar(
            $this->cliente, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $limite, $aplicar,
        );
    }

    // ------------------------------------------------------------ CCF externo

    public function test_en_seco_cuenta_pero_no_escribe_nada(): void
    {
        $this->gmail([['m1']], ['m1' => [$this->adjunto($this->ccf($this->codigo()))]]);

        $r = $this->importar(aplicar: false);

        $this->assertSame(1, $r->ccfCreados);
        $this->assertFalse($r->aplicado);
        $this->assertSame(0, CobroDocumento::count());
        $this->assertSame(0, CobroDocumentoProcedencia::count());
    }

    public function test_ccf_externo_entra_con_su_procedencia_y_sin_json_guardado(): void
    {
        $codigo = $this->codigo();
        $json = $this->ccf($codigo);
        $this->gmail([['m1']], ['m1' => [$this->adjunto($json, 'CCF.json')]]);

        $r = $this->importar();

        $this->assertSame(1, $r->ccfCreados);
        $doc = CobroDocumento::sole();
        $this->assertSame(OrigenCobroDocumento::Gmail, $doc->origen);
        $this->assertNull($doc->dte_id);
        $this->assertSame($codigo, $doc->codigo_generacion);
        $this->assertSame('2026SELLO'.$codigo, $doc->sello_recepcion);
        $this->assertSame('100.00', (string) $doc->monto);
        $this->assertSame('2026-09-10', $doc->fecha_emision->toDateString());
        $this->assertFalse($doc->revisar_historico);

        $procedencia = CobroDocumentoProcedencia::sole();
        $this->assertSame('m1', $procedencia->gmail_message_id);
        $this->assertSame('CCF.json', $procedencia->adjunto_nombre);
        $this->assertSame(hash('sha256', json_encode($json)), $procedencia->adjunto_hash);
    }

    public function test_json_sin_sello_no_se_importa_y_se_informa(): void
    {
        $json = $this->ccf($this->codigo());
        unset($json['selloRecibido']);
        $this->gmail([['m1']], ['m1' => [$this->adjunto($json)]]);

        $r = $this->importar();

        $this->assertSame(1, $r->omitidosSinSello);
        $this->assertSame(0, CobroDocumento::count());
    }

    public function test_ccf_viejo_sin_antecedente_entra_para_revision_historica(): void
    {
        $this->gmail([['m1']], ['m1' => [$this->adjunto($this->ccf($this->codigo(), ['identificacion' => ['fecEmi' => '2026-06-01']]))]]);

        $r = $this->importar();

        $this->assertSame(1, $r->revisionHistorica);
        $this->assertTrue(CobroDocumento::sole()->revisar_historico);
    }

    // ------------------------------------------------------------ NC y relación

    public function test_nc_externa_queda_relacionada_con_su_ccf_del_mismo_barrido(): void
    {
        $ccf = $this->codigo();
        $nc = $this->codigo();
        // La NC llega ANTES que el CCF en el buzón: igual se resuelve.
        $this->gmail([['m2', 'm1']], [
            'm1' => [$this->adjunto($this->ccf($ccf))],
            'm2' => [$this->adjunto($this->nc($nc, [$ccf]))],
        ]);

        $r = $this->importar();

        $this->assertSame(1, $r->ccfCreados);
        $this->assertSame(1, $r->ncCreadas);
        $relacion = CobroDocumentoRelacion::sole();
        $this->assertSame($ccf, $relacion->codigo_generacion_relacionado);
        $this->assertSame(CobroDocumento::where('codigo_generacion', $ccf)->value('id'), $relacion->ccf_cobro_documento_id);
        $this->assertSame(CobroDocumento::where('codigo_generacion', $nc)->value('id'), $relacion->nc_cobro_documento_id);
    }

    public function test_nc_con_varias_relaciones_las_guarda_todas_y_deja_pendiente_la_que_falta(): void
    {
        $ccfA = $this->codigo();
        $ccfB = $this->codigo();
        $this->gmail([['m1', 'm2']], [
            'm1' => [$this->adjunto($this->ccf($ccfA))],
            'm2' => [$this->adjunto($this->nc($this->codigo(), [$ccfA, $ccfB]))],
        ]);

        $r = $this->importar();

        $this->assertSame(2, CobroDocumentoRelacion::count());
        $this->assertSame(1, CobroDocumentoRelacion::whereNotNull('ccf_cobro_documento_id')->count());
        $this->assertSame([$ccfB], array_keys($r->relacionesSinCcf));
    }

    public function test_relacion_pendiente_se_resuelve_cuando_el_ccf_llega_en_otra_corrida(): void
    {
        $ccf = $this->codigo();
        $jsonCcf = $this->ccf($ccf);
        $this->gmail([['m2']], ['m2' => [$this->adjunto($this->nc($this->codigo(), [$ccf]))]]);
        $this->importar();
        $this->assertNull(CobroDocumentoRelacion::sole()->ccf_cobro_documento_id);

        $this->gmail([['m1']], ['m1' => [$this->adjunto($jsonCcf)]]);
        $enSeco = $this->importar(aplicar: false);
        $this->assertSame(1, $enSeco->relacionesResueltas);
        $this->assertNull(CobroDocumentoRelacion::sole()->ccf_cobro_documento_id);

        $r = $this->importar();

        $this->assertSame(1, $r->relacionesResueltas);
        $this->assertNotNull(CobroDocumentoRelacion::sole()->ccf_cobro_documento_id);
    }

    public function test_relacion_que_no_es_codigo_electronico_no_se_registra(): void
    {
        $this->gmail([['m1']], ['m1' => [$this->adjunto($this->nc($this->codigo(), ['12345']))]]);

        $r = $this->importar();

        $this->assertSame(1, $r->ncCreadas);
        $this->assertSame(1, $r->ncSinRelacion);
        $this->assertSame(0, CobroDocumentoRelacion::count());
        $this->assertNotEmpty($r->excepciones);
    }

    // ------------------------------------------------------------ reenvío e idempotencia

    public function test_reenvio_es_el_mismo_documento_con_dos_procedencias(): void
    {
        $json = $this->ccf($this->codigo());
        $this->gmail([['m1', 'm2']], ['m1' => [$this->adjunto($json)], 'm2' => [$this->adjunto($json)]]);

        $r = $this->importar();

        $this->assertSame(1, $r->reenvios);
        $this->assertSame(1, CobroDocumento::count());
        $this->assertSame(2, CobroDocumentoProcedencia::count());
    }

    public function test_repetir_la_corrida_no_duplica_nada(): void
    {
        $ccf = $this->codigo();
        $this->gmail([['m1', 'm2']], [
            'm1' => [$this->adjunto($this->ccf($ccf))],
            'm2' => [$this->adjunto($this->nc($this->codigo(), [$ccf]))],
        ]);
        $this->importar();

        $r = $this->importar();

        $this->assertSame(0, $r->ccfCreados + $r->ncCreadas);
        $this->assertSame(2, $r->yaExistian);
        $this->assertSame(0, $r->procedenciasNuevas);
        $this->assertSame(0, $r->relacionesNuevas);
        $this->assertSame(2, CobroDocumento::count());
        $this->assertSame(2, CobroDocumentoProcedencia::count());
        $this->assertSame(1, CobroDocumentoRelacion::count());
    }

    public function test_existente_a_mano_solo_se_completa_y_no_pierde_su_estado(): void
    {
        $codigo = $this->codigo();
        $json = $this->ccf($codigo);
        $existente = CobroDocumento::create([
            'cliente_id' => $this->cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => $json['identificacion']['numeroControl'],
            'monto' => '100.00',
            'observaciones' => 'Nota del operador',
            'presentacion_estado' => 'presentada',
        ]);
        $this->gmail([['m1']], ['m1' => [$this->adjunto($json)]]);

        $r = $this->importar();

        $existente->refresh();
        $this->assertSame(1, $r->yaExistian);
        $this->assertSame(1, $r->completados);
        $this->assertSame(OrigenCobroDocumento::Externo, $existente->origen);
        $this->assertSame($codigo, $existente->codigo_generacion);
        $this->assertSame('Nota del operador', $existente->observaciones);
        $this->assertSame('presentada', $existente->presentacion_estado->value);
        $this->assertSame(EstadoPagoCobro::Pendiente, $existente->pago_estado);
        $this->assertSame(1, CobroDocumentoProcedencia::count());
    }

    public function test_existente_con_otro_importe_es_excepcion_y_no_se_toca(): void
    {
        $json = $this->ccf($this->codigo());
        $existente = CobroDocumento::create([
            'cliente_id' => $this->cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => $json['identificacion']['numeroControl'],
            'monto' => '99.00',
        ]);
        $this->gmail([['m1']], ['m1' => [$this->adjunto($json)]]);

        $r = $this->importar();

        $this->assertCount(1, $r->excepciones);
        $this->assertNull($existente->refresh()->codigo_generacion);
        $this->assertSame(0, CobroDocumentoProcedencia::count());
    }

    // ------------------------------------------------------------ paginación y truncamiento

    public function test_pagina_hasta_cubrir_el_rango(): void
    {
        $this->gmail([['m1'], ['m2'], ['m3']], [
            'm1' => [$this->adjunto($this->ccf($this->codigo()))],
            'm2' => [$this->adjunto($this->ccf($this->codigo()))],
            'm3' => [$this->adjunto($this->ccf($this->codigo()))],
        ]);

        $r = $this->importar();

        $this->assertFalse($r->truncado);
        $this->assertSame(3, $r->correosRevisados);
        $this->assertSame(3, CobroDocumento::count());
    }

    public function test_el_limite_trunca_y_lo_avisa(): void
    {
        $this->gmail([['m1', 'm2'], ['m3']], [
            'm1' => [$this->adjunto($this->ccf($this->codigo()))],
            'm2' => [$this->adjunto($this->ccf($this->codigo()))],
            'm3' => [$this->adjunto($this->ccf($this->codigo()))],
        ]);

        $r = $this->importar(limite: 2);

        $this->assertTrue($r->truncado);
        $this->assertSame(2, $r->correosRevisados);
        $this->assertSame(2, CobroDocumento::count());
    }

    // ------------------------------------------------------------ receptor y JSON

    public function test_receptor_ajeno_no_se_importa(): void
    {
        $this->gmail([['m1']], ['m1' => [$this->adjunto($this->ccf($this->codigo(), ['receptor' => ['nit' => '9999-999999-999-9']]))]]);

        $r = $this->importar();

        $this->assertSame(1, $r->receptorAjeno);
        $this->assertSame(0, CobroDocumento::count());
    }

    public function test_receptor_con_dos_identificadores_distintos_es_excepcion(): void
    {
        $this->gmail([['m1']], ['m1' => [$this->adjunto($this->ccf($this->codigo(), ['receptor' => ['numDocumento' => '1111-111111-111-1']]))]]);

        $r = $this->importar();

        $this->assertCount(1, $r->excepciones);
        $this->assertSame(0, CobroDocumento::count());
    }

    public function test_cliente_que_comparte_nit_con_otro_no_importa_nada(): void
    {
        Cliente::factory()->contribuyente()->create(['num_documento' => '06140101011011']);
        $this->gmail([['m1']], ['m1' => [$this->adjunto($this->ccf($this->codigo()))]]);

        $this->expectException(\RuntimeException::class);

        $this->importar();
    }

    public function test_json_ilegible_se_cuenta_y_no_frena_el_resto(): void
    {
        $this->gmail([['m1', 'm2']], [
            'm1' => [['filename' => 'dte.json', 'mime' => 'application/json', 'data' => '{esto no es json']],
            'm2' => [$this->adjunto($this->ccf($this->codigo()))],
        ]);

        $r = $this->importar();

        $this->assertSame(1, $r->adjuntosIlegibles);
        $this->assertSame(1, CobroDocumento::count());
    }

    public function test_copias_contradictorias_del_mismo_codigo_no_se_importan(): void
    {
        $codigo = $this->codigo();
        $a = $this->ccf($codigo);
        $b = $a;
        $b['resumen']['totalPagar'] = 120.0;
        $this->gmail([['m1', 'm2']], ['m1' => [$this->adjunto($a)], 'm2' => [$this->adjunto($b)]]);

        $r = $this->importar();

        $this->assertCount(1, $r->excepciones);
        $this->assertSame(0, CobroDocumento::count());
    }

    // ------------------------------------------------------------ DTE local

    public function test_dte_propio_identico_no_se_importa(): void
    {
        $dte = $this->dteLocal();
        $json = $this->ccf($dte->codigo_generacion, ['identificacion' => ['numeroControl' => $dte->numero_control]]);
        $this->gmail([['m1']], ['m1' => [$this->adjunto($json)]]);

        $r = $this->importar();

        $this->assertSame(1, $r->propiosDelSistema);
        $this->assertSame(0, CobroDocumento::count());
    }

    public function test_conflicto_con_dte_local_es_excepcion(): void
    {
        $dte = $this->dteLocal();
        $json = $this->ccf($this->codigo(), ['identificacion' => ['numeroControl' => $dte->numero_control]]);
        $this->gmail([['m1']], ['m1' => [$this->adjunto($json)]]);

        $r = $this->importar();

        $this->assertCount(1, $r->excepciones);
        $this->assertSame(0, CobroDocumento::count());
    }

    private function dteLocal(): Dte
    {
        $this->seedCatalogosDte();
        $estab = $this->crearEmisorDte()['estab'];

        return Dte::create([
            'establecimiento_id' => $estab->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'numero_control' => 'DTE-03-M001P002-000000000000777',
            'codigo_generacion' => $this->codigo(),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-09-10',
            'hora_emision' => '08:00:00',
            'total_pagar' => '100.00',
        ]);
    }
}
