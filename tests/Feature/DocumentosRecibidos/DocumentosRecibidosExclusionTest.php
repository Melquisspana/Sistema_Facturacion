<?php

namespace Tests\Feature\DocumentosRecibidos;

use App\Models\DocumentoRecibido;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\DocumentosRecibidos\ResumenSincronizacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Tests\Support\BuzonFalso;
use Tests\Support\SincronizaCompras;
use Tests\TestCase;

/**
 * PASO 2 — Exclusión de correos NO-DTE durante la sincronización (estados de cuenta,
 * órdenes de compra, PDF-only sin DTE). Se descartan ANTES de crear el registro y de
 * guardar adjuntos; un JSON DTE válido nunca se descarta. El buzón sigue SOLO LECTURA.
 * Config en config/documentos_recibidos.php ('exclusiones'), sin variables .env.
 */
class DocumentosRecibidosExclusionTest extends TestCase
{
    use RefreshDatabase;
    use SincronizaCompras;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        // Exclusión activa por defecto (config del repo); explícito para claridad.
        config([
            'documentos_recibidos.exclusiones.activo' => true,
            'documentos_recibidos.exclusiones.descartar_no_dte' => true,
            'documentos_recibidos.exclusiones.reglas' => [
                ['nombre' => 'estado_de_cuenta', 'asunto' => ['estado de cuenta'], 'adjunto' => []],
                ['nombre' => 'orden_de_compra', 'asunto' => ['orden de compra'], 'adjunto' => []],
            ],
        ]);
    }

    /**
     * Instala un buzón falso con estos mensajes y devuelve el resumen de sincronizar el
     * día que todos usan (2026-07-10).
     *
     * @param  array<int, array<string, mixed>>  $mensajes
     */
    private function sync(array $mensajes): ResumenSincronizacion
    {
        $buzon = new BuzonFalso;
        foreach ($mensajes as $m) {
            $buzon->conMensaje($m);
        }
        $this->instalarBuzon($buzon);

        return $this->sincronizar('2026-07-10');
    }

    private function mensaje(string $id, string $asunto, string $remitente, array $adjuntos): array
    {
        return [
            'uid' => (int) preg_replace('/\D/', '', $id) ?: crc32($id) % 100000,
            'message_id' => '<'.$id.'@proveedor.example>',
            'asunto' => $asunto,
            'remitente' => $remitente,
            'adjuntos' => $adjuntos,
            'fecha' => '2026-07-10 09:00:00',
        ];
    }

    private function pdf(string $nombre = 'documento.pdf'): array
    {
        return ['filename' => $nombre, 'mime' => 'application/pdf', 'data' => '%PDF-1.4 fake'];
    }

    private function jsonCcf(string $codigo = 'COD-1', string $numero = 'DTE-03-BBB-1'): array
    {
        return ['filename' => 'dte.json', 'mime' => 'application/json', 'data' => json_encode([
            'identificacion' => ['tipoDte' => '03', 'numeroControl' => $numero, 'codigoGeneracion' => $codigo, 'fecEmi' => '2026-07-10'],
            'emisor' => ['nombre' => 'PROVEEDOR EJEMPLO S.A.', 'nit' => '06140000000000', 'nrc' => '999999'],
            'receptor' => ['nombre' => 'DULCES LA NEGRITA'],
            'resumen' => ['totalPagar' => 250.75],
        ])];
    }

    // 1) Estado de cuenta sin JSON DTE se descarta.
    public function test_estado_de_cuenta_sin_json_se_descarta(): void
    {
        Log::spy();
        $r = $this->sync([
            $this->mensaje('uid-ec', 'Estado de Cuenta Corriente Bancoagrícola', 'estadosdecuenta@banco.com', [$this->pdf('5040117729.pdf')]),
        ]);

        $this->assertSame(0, $r->nuevos);
        $this->assertSame(1, $r->descartados);
        $this->assertSame(0, DocumentoRecibido::count());
        $this->assertEmpty(Storage::disk('local')->allFiles()); // no se guardaron adjuntos
        Mail::assertNothingSent();

        // Log SOLO con metadatos (regla), nunca con el contenido del adjunto.
        Log::shouldHaveReceived('info')->withArgs(function ($msg, $ctx) {
            return $msg === 'documentos_recibidos.correo_descartado'
                && ($ctx['regla'] ?? null) === 'estado_de_cuenta'
                && ($ctx['adjuntos'] ?? null) === ['5040117729.pdf']
                && ! str_contains(json_encode($ctx), '%PDF-1.4');
        })->once();
    }

    // 2) OC sin JSON DTE se descarta, incluido el typo "ORDEN D ECOMPRA".
    public function test_orden_de_compra_con_typo_se_descarta(): void
    {
        $r = $this->sync([
            $this->mensaje('uid-oc', 'ORDEN D ECOMPRA', 'operador@lasramblas.com', [$this->pdf('DILVE.pdf')]),
        ]);

        $this->assertSame(0, $r->nuevos);
        $this->assertSame(1, $r->descartados);
        $this->assertSame(0, DocumentoRecibido::count());
    }

    // 3) CCF válido del MISMO remitente (banco) se importa.
    public function test_ccf_valido_del_mismo_remitente_se_importa(): void
    {
        $r = $this->sync([
            $this->mensaje('uid-ccf-banco', 'Estado de Cuenta', 'estadosdecuenta@banco.com', [$this->jsonCcf('COD-BANCO', 'DTE-03-BANK-1'), $this->pdf()]),
        ]);

        $this->assertSame(1, $r->nuevos);
        $this->assertSame(0, $r->descartados);
        $this->assertSame('dte_valido', DocumentoRecibido::firstOrFail()->clasificacion);
    }

    // 4) PDF-only que NO parece DTE se descarta (descarte general no_es_dte).
    public function test_pdf_only_que_no_parece_dte_se_descarta(): void
    {
        $r = $this->sync([
            $this->mensaje('uid-promo', 'Promociones del mes', 'marketing@proveedor.com', [$this->pdf('boletin.pdf')]),
        ]);

        $this->assertSame(0, $r->nuevos);
        $this->assertSame(1, $r->descartados);
        $this->assertSame(0, DocumentoRecibido::count());
    }

    // 5) JSON DTE válido con asunto "estado de cuenta" se importa (guardia gana).
    public function test_json_dte_valido_con_asunto_estado_de_cuenta_se_importa(): void
    {
        $r = $this->sync([
            $this->mensaje('uid-trampa', 'Estado de cuenta y CCF adjunto', 'proveedor@correo.com', [$this->jsonCcf('COD-OK', 'DTE-03-OK-1'), $this->pdf()]),
        ]);

        $this->assertSame(1, $r->nuevos);
        $this->assertSame(0, $r->descartados);
        $this->assertSame('dte_valido', DocumentoRecibido::firstOrFail()->clasificacion);
    }

    // 6) falta_adjunto (PDF que parece DTE, sin JSON) se conserva.
    public function test_falta_adjunto_se_conserva(): void
    {
        $r = $this->sync([
            $this->mensaje('uid-falta', 'Comprobante de Crédito Fiscal', 'proveedor@correo.com', [$this->pdf('DTE-03-M001P001-000000000000001.pdf')]),
        ]);

        $this->assertSame(1, $r->nuevos);
        $this->assertSame(0, $r->descartados);
        $this->assertSame('falta_adjunto', DocumentoRecibido::firstOrFail()->clasificacion);
    }

    // 7) json_invalido (JSON que no decodifica) se conserva.
    public function test_json_invalido_se_conserva(): void
    {
        $r = $this->sync([
            $this->mensaje('uid-roto', 'CCF de proveedor', 'proveedor@correo.com', [
                ['filename' => 'roto.json', 'mime' => 'application/json', 'data' => '{"identificacion": {"tipoDte": "03"'],
                $this->pdf(),
            ]),
        ]);

        $this->assertSame(1, $r->nuevos);
        $this->assertSame(0, $r->descartados);
        $this->assertSame('json_invalido', DocumentoRecibido::firstOrFail()->clasificacion);
    }

    // 8) El resumen cuenta los descartados (mezcla de descartados + válido).
    public function test_resumen_cuenta_descartados(): void
    {
        $r = $this->sync([
            $this->mensaje('uid-ec', 'Estado de Cuenta', 'banco@x.com', [$this->pdf('ec.pdf')]),
            $this->mensaje('uid-oc', 'Orden de compra 123', 'op@x.com', [$this->pdf('oc.pdf')]),
            $this->mensaje('uid-ccf', 'CCF', 'prov@x.com', [$this->jsonCcf('C-1', 'DTE-03-Z-1'), $this->pdf()]),
        ]);

        $this->assertSame(3, $r->correos);
        $this->assertSame(1, $r->nuevos);
        $this->assertSame(2, $r->descartados);
        $this->assertSame(1, DocumentoRecibido::count());
    }

    // 9) El contrato del buzón NO expone métodos de escritura (no borra/mueve/marca).
    public function test_el_buzon_no_expone_metodos_de_escritura(): void
    {
        $metodos = array_map(
            fn ($m) => strtolower($m->getName()),
            (new ReflectionClass(MailboxClient::class))->getMethods()
        );

        // Solo lectura: disponible / fuente / estado / mensajesDelDia.
        sort($metodos);
        $this->assertSame(['disponible', 'estado', 'fuente', 'mensajesdeldia'], $metodos);

        foreach (['eliminar', 'borrar', 'mover', 'marcar', 'delete', 'move', 'expunge', 'flag', 'seen'] as $mutador) {
            $this->assertNotContains($mutador, $metodos, "El buzón no debe exponer un método de escritura: {$mutador}");
        }
    }

    // 10) Exclusiones desactivadas desde config conservan el comportamiento anterior.
    public function test_exclusiones_desactivadas_conservan_comportamiento(): void
    {
        config(['documentos_recibidos.exclusiones.activo' => false]);

        $r = $this->sync([
            $this->mensaje('uid-ec', 'Estado de Cuenta Corriente', 'banco@x.com', [$this->pdf('ec.pdf')]),
        ]);

        $this->assertSame(1, $r->nuevos);
        $this->assertSame(0, $r->descartados);
        $this->assertSame('no_es_dte', DocumentoRecibido::firstOrFail()->clasificacion);
    }
}
