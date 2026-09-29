<?php

namespace Tests\Feature\Cobros;

use App\Models\Cliente;
use App\Services\Cobros\AuditorEnviados\AnalizadorAuditoriaEnviados;
use App\Services\Cobros\AuditorEnviadosService;
use App\Services\Ppq\DteCorreoParser;
use App\Services\Ppq\GmailClient;
use App\Services\Ppq\JsonAdjuntoDecoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * EL AUDITOR DE ENVIADOS, DE PUNTA A PUNTA CONTRA UN GMAIL DE MENTIRA.
 *
 * Comprueba la parte que {@see AnalizadorAuditoriaEnviadosTest} no cubre: la
 * paginación real de `idsDeCobros`, la lectura de adjuntos, la decodificación del
 * JSON (incluido uno inválido) y el truncamiento por `--limite`. Ningún caso llama
 * a Gmail de verdad: {@see GmailClientDeAuditoria} es un doble en memoria.
 */
class AuditorEnviadosServiceTest extends TestCase
{
    use RefreshDatabase;

    private function cliente(string $nit = '0614-010101-101-1'): Cliente
    {
        return Cliente::factory()->contribuyente()->create(['num_documento' => $nit]);
    }

    private function servicio(GmailClient $gmail): AuditorEnviadosService
    {
        return new AuditorEnviadosService($gmail, new JsonAdjuntoDecoder, new DteCorreoParser, new AnalizadorAuditoriaEnviados);
    }

    /** @return array<string, mixed> */
    private function jsonCcf(string $codigoGeneracion, string $nit): array
    {
        return [
            'identificacion' => ['numeroControl' => 'DTE-03-M001P001-'.str_pad('1', 15, '0', STR_PAD_LEFT), 'codigoGeneracion' => $codigoGeneracion, 'tipoDte' => '03', 'fecEmi' => '2026-09-01'],
            'resumen' => ['totalPagar' => 100.0],
            'receptor' => ['nit' => $nit, 'nombre' => 'Cliente de prueba'],
        ];
    }

    /** @return array<string, mixed> */
    private function jsonNc(string $codigoGeneracion, string $nit, array $relacionados): array
    {
        return [
            'identificacion' => ['numeroControl' => 'DTE-05-M001P001-'.str_pad('1', 15, '0', STR_PAD_LEFT), 'codigoGeneracion' => $codigoGeneracion, 'tipoDte' => '05', 'fecEmi' => '2026-09-02'],
            'resumen' => ['montoTotalOperacion' => 10.0],
            'receptor' => ['nit' => $nit, 'nombre' => 'Cliente de prueba'],
            'documentoRelacionado' => array_map(fn ($codigo) => [
                'tipoDocumento' => '03',
                'numeroDocumento' => $codigo,
                'fechaEmision' => '2026-09-01',
            ], $relacionados),
        ];
    }

    private function adjuntoJson(array $json, string $filename = 'dte.json'): array
    {
        return ['filename' => $filename, 'mime' => 'application/json', 'data' => json_encode($json)];
    }

    // ------------------------------------------------------------ dorada: ccf + nc relacionados

    public function test_ccf_y_nc_relacionados_se_ven_completos_de_punta_a_punta(): void
    {
        $cliente = $this->cliente();
        $nit = $cliente->num_documento;

        $gmail = new GmailClientDeAuditoria(
            paginas: [['m1', 'm2']],
            adjuntosPorId: [
                'm1' => [$this->adjuntoJson($this->jsonCcf('CCF-1', $nit))],
                'm2' => [$this->adjuntoJson($this->jsonNc('NC-1', $nit, ['CCF-1']))],
            ],
        );

        $r = $this->servicio($gmail)->auditar($cliente, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), 100);

        $this->assertSame(1, $r->ccf);
        $this->assertSame(1, $r->nc);
        $this->assertSame([], $r->ncSinCcfEnBarrido);
        $this->assertFalse($r->truncado);
    }

    // ------------------------------------------------------------ receptor ajeno

    public function test_receptor_de_otro_cliente_no_se_cuenta(): void
    {
        $cliente = $this->cliente();

        $gmail = new GmailClientDeAuditoria(
            paginas: [['m1']],
            adjuntosPorId: ['m1' => [$this->adjuntoJson($this->jsonCcf('CCF-1', '9999999999999'))]],
        );

        $r = $this->servicio($gmail)->auditar($cliente, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), 100);

        $this->assertSame(0, $r->ccf);
        $this->assertSame(1, $r->correosReceptorDistinto);
    }

    // ------------------------------------------------------------ json inválido

    public function test_adjunto_json_ilegible_se_cuenta_como_correo_sin_json_legible(): void
    {
        $cliente = $this->cliente();

        $gmail = new GmailClientDeAuditoria(
            paginas: [['m1']],
            adjuntosPorId: ['m1' => [['filename' => 'dte.json', 'mime' => 'application/json', 'data' => '{esto no es json']]],
        );

        $r = $this->servicio($gmail)->auditar($cliente, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), 100);

        $this->assertSame(1, $r->correosSinJsonLegible);
        $this->assertSame(0, $r->ccf);
    }

    // ------------------------------------------------------------ paginación

    public function test_recorre_varias_paginas_de_gmail_hasta_agotar_las_ids(): void
    {
        $cliente = $this->cliente();
        $nit = $cliente->num_documento;

        $gmail = new GmailClientDeAuditoria(
            paginas: [['m1'], ['m2'], ['m3']],
            adjuntosPorId: [
                'm1' => [$this->adjuntoJson($this->jsonCcf('CCF-1', $nit))],
                'm2' => [$this->adjuntoJson($this->jsonCcf('CCF-2', $nit))],
                'm3' => [$this->adjuntoJson($this->jsonCcf('CCF-3', $nit))],
            ],
        );

        $r = $this->servicio($gmail)->auditar($cliente, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), 100);

        $this->assertSame(3, $r->correosRevisados);
        $this->assertSame(3, $r->ccf);
        $this->assertFalse($r->truncado);
    }

    // ------------------------------------------------------------ límite / truncamiento

    public function test_el_limite_trunca_y_lo_informa_sin_afirmar_cobertura_completa(): void
    {
        $cliente = $this->cliente();
        $nit = $cliente->num_documento;

        $gmail = new GmailClientDeAuditoria(
            paginas: [['m1'], ['m2'], ['m3']],
            adjuntosPorId: [
                'm1' => [$this->adjuntoJson($this->jsonCcf('CCF-1', $nit))],
                'm2' => [$this->adjuntoJson($this->jsonCcf('CCF-2', $nit))],
                'm3' => [$this->adjuntoJson($this->jsonCcf('CCF-3', $nit))],
            ],
        );

        $r = $this->servicio($gmail)->auditar($cliente, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), 2);

        $this->assertSame(2, $r->correosRevisados);
        $this->assertTrue($r->truncado);
    }

    // ------------------------------------------------------------ mismo código en varios mensajes

    public function test_el_mismo_dte_reenviado_en_otro_correo_no_se_duplica_en_el_conteo(): void
    {
        $cliente = $this->cliente();
        $nit = $cliente->num_documento;

        $gmail = new GmailClientDeAuditoria(
            paginas: [['m1', 'm2']],
            adjuntosPorId: [
                'm1' => [$this->adjuntoJson($this->jsonCcf('CCF-1', $nit))],
                'm2' => [$this->adjuntoJson($this->jsonCcf('CCF-1', $nit))], // reenvío
            ],
        );

        $r = $this->servicio($gmail)->auditar($cliente, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), 100);

        $this->assertSame(1, $r->ccf);
        $this->assertSame(1, $r->dtesRepetidosEnOtroCorreo);
    }

    // ------------------------------------------------------------ cliente sin nit

    public function test_cliente_sin_numero_de_documento_no_se_puede_auditar(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['num_documento' => '']);
        $gmail = new GmailClientDeAuditoria(paginas: [[]], adjuntosPorId: []);

        $this->expectException(\RuntimeException::class);

        $this->servicio($gmail)->auditar($cliente, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), 100);
    }
}

/**
 * Gmail de mentira: `idsDeCobros` devuelve páginas fijas (token = índice de página en
 * texto); `adjuntos` devuelve lo que se le haya precargado por id de mensaje. No hace
 * ninguna llamada real ni tiene con qué escribir en una cuenta.
 */
class GmailClientDeAuditoria extends GmailClient
{
    /**
     * @param  array<int, array<int, string>>  $paginas
     * @param  array<string, array<int, array{filename:string, mime:string, data:string}>>  $adjuntosPorId
     */
    public function __construct(
        private readonly array $paginas,
        private readonly array $adjuntosPorId,
    ) {
        parent::__construct();
    }

    public function idsDeCobros(string $query, ?string $token = null, int $max = 100): array
    {
        $indice = (int) ($token ?? 0);
        $ids = $this->paginas[$indice] ?? [];
        $siguiente = isset($this->paginas[$indice + 1]) ? (string) ($indice + 1) : null;

        return ['ids' => $ids, 'siguiente' => $siguiente];
    }

    public function adjuntos(string $messageId): array
    {
        return $this->adjuntosPorId[$messageId] ?? [];
    }
}
