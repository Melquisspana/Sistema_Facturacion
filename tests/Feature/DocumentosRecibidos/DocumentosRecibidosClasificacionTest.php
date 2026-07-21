<?php

namespace Tests\Feature\DocumentosRecibidos;

use App\Models\DocumentoRecibido;
use App\Models\User;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\DocumentosRecibidos\SincronizadorDocumentosRecibidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fase 1 de la clasificación de documentos recibidos: distinguir POR QUÉ un
 * documento quedó sin datos de DTE (no_es_dte / json_invalido / tipo_no_soportado
 * / falta_adjunto) de un DTE realmente válido (dte_valido), y el mapeo de total
 * del Comprobante de Retención (07). `clasificacion` es independiente de `estado`
 * (pendiente/enviado/ignorado, que sigue siendo triage manual).
 */
class DocumentosRecibidosClasificacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function sincronizadorCon(MailboxClient $buzon): SincronizadorDocumentosRecibidos
    {
        $this->app->instance(MailboxClient::class, $buzon);

        return app(SincronizadorDocumentosRecibidos::class);
    }

    private function buzonCon(array $mensajes): MailboxClient
    {
        $buzon = \Mockery::mock(MailboxClient::class);
        $buzon->shouldReceive('disponible')->andReturn(true);
        $buzon->shouldReceive('fuente')->andReturn('IMAP dulceslanegrita@yahoo.com');
        $buzon->shouldReceive('mensajesConAdjuntos')->andReturn($mensajes);

        return $buzon;
    }

    private function jsonCcf(string $codigo, string $numero): array
    {
        return [
            'identificacion' => ['tipoDte' => '03', 'numeroControl' => $numero, 'codigoGeneracion' => $codigo, 'fecEmi' => '2026-07-10'],
            'emisor' => ['nombre' => 'PROVEEDOR EJEMPLO S.A.', 'nit' => '06140000000000', 'nrc' => '999999'],
            'receptor' => ['nombre' => 'DULCES LA NEGRITA'],
            'resumen' => ['totalPagar' => 250.75],
        ];
    }

    /** Estructura real (sanitizada) de un Comprobante de Retención (07). */
    private function jsonRetencion(string $codigo, string $numero): array
    {
        return [
            'identificacion' => ['tipoDte' => '07', 'numeroControl' => $numero, 'codigoGeneracion' => $codigo, 'fecEmi' => '2026-07-10'],
            'emisor' => ['nombre' => 'PROVEEDOR RETENIDO S.A.', 'nit' => '06140000000001', 'nrc' => '888888'],
            'receptor' => ['nombre' => 'DULCES LA NEGRITA'],
            'resumen' => [
                'totalSujetoRetencion' => 146.88,
                'totalIVAretenido' => 1.47,
                'totalIVAretenidoLetras' => 'UN 47/100',
            ],
        ];
    }

    // ---------- PDF solo, sin JSON ----------

    public function test_pdf_solo_sin_indicios_de_dte_se_clasifica_no_es_dte(): void
    {
        Mail::fake();
        Storage::fake('local');

        $mensajes = [[
            'id' => 'uid-estado-cuenta', 'asunto' => 'Estado de Cuenta Corriente Bancoagrícola', 'remitente' => 'estadosdecuenta@banco.com', 'fecha' => '2026-07-10',
            'adjuntos' => [
                ['filename' => '5040117729.pdf', 'mime' => 'application/pdf', 'data' => '%PDF-1.4 fake'],
            ],
        ]];

        $sync = $this->sincronizadorCon($this->buzonCon($mensajes));
        $sync->sincronizar();

        $doc = DocumentoRecibido::firstOrFail();
        $this->assertSame('no_es_dte', $doc->clasificacion);
        $this->assertNull($doc->tipo_documento);
        $this->assertNull($doc->total);
        $this->assertSame('pendiente', $doc->estado); // estado no lo decide la clasificación
    }

    public function test_pdf_solo_con_indicios_de_dte_se_clasifica_falta_adjunto(): void
    {
        Mail::fake();
        Storage::fake('local');

        $mensajes = [[
            'id' => 'uid-solo-pdf-dte', 'asunto' => 'Envío de Comprobante de Crédito Fiscal DTE-03-M001P001-1', 'remitente' => 'proveedor@correo.com', 'fecha' => '2026-07-10',
            'adjuntos' => [
                ['filename' => 'DTE-03-M001P001-000000000000001.pdf', 'mime' => 'application/pdf', 'data' => '%PDF-1.4 fake'],
            ],
        ]];

        $sync = $this->sincronizadorCon($this->buzonCon($mensajes));
        $sync->sincronizar();

        $doc = DocumentoRecibido::firstOrFail();
        $this->assertSame('falta_adjunto', $doc->clasificacion);
    }

    // ---------- JSON válido ----------

    public function test_json_valido_tipo_03_se_clasifica_dte_valido(): void
    {
        Mail::fake();
        Storage::fake('local');

        $mensajes = [[
            'id' => 'uid-ccf', 'asunto' => 'CCF de proveedor', 'remitente' => 'proveedor@correo.com', 'fecha' => '2026-07-10',
            'adjuntos' => [
                ['filename' => 'dte.json', 'mime' => 'application/json', 'data' => json_encode($this->jsonCcf('COD-03', 'DTE-03-BBB-1'))],
                ['filename' => 'dte.pdf', 'mime' => 'application/pdf', 'data' => '%PDF-1.4 fake'],
            ],
        ]];

        $sync = $this->sincronizadorCon($this->buzonCon($mensajes));
        $sync->sincronizar();

        $doc = DocumentoRecibido::firstOrFail();
        $this->assertSame('dte_valido', $doc->clasificacion);
        $this->assertSame(250.75, (float) $doc->total);
        $this->assertSame('Total', $doc->totalLabel());
    }

    public function test_json_valido_tipo_07_usa_total_sujeto_a_retencion(): void
    {
        Mail::fake();
        Storage::fake('local');

        $mensajes = [[
            'id' => 'uid-retencion', 'asunto' => 'Comprobante de Retención', 'remitente' => 'proveedor@correo.com', 'fecha' => '2026-07-10',
            'adjuntos' => [
                ['filename' => 'retencion.json', 'mime' => 'application/json', 'data' => json_encode($this->jsonRetencion('COD-07', 'DTE-07-M001P002-000000000000334'))],
                ['filename' => 'retencion.pdf', 'mime' => 'application/pdf', 'data' => '%PDF-1.4 fake'],
            ],
        ]];

        $sync = $this->sincronizadorCon($this->buzonCon($mensajes));
        $sync->sincronizar();

        $doc = DocumentoRecibido::firstOrFail();
        $this->assertSame('07', $doc->tipo_documento);
        $this->assertSame('dte_valido', $doc->clasificacion);
        // 146.88 = resumen.totalSujetoRetencion del JSON real (NO totalPagar: ese
        // campo no existe en un Comprobante de Retención).
        $this->assertSame(146.88, (float) $doc->total);
        $this->assertSame('Monto sujeto a retención', $doc->totalLabel());
    }

    public function test_json_roto_se_clasifica_json_invalido_con_diagnostico_sin_contenido(): void
    {
        Mail::fake();
        Storage::fake('local');

        $mensajes = [[
            'id' => 'uid-roto', 'asunto' => 'CCF de proveedor', 'remitente' => 'proveedor@correo.com', 'fecha' => '2026-07-10',
            'adjuntos' => [
                // JSON deliberadamente inválido (llave sin cerrar).
                ['filename' => 'roto.json', 'mime' => 'application/json', 'data' => '{"identificacion": {"tipoDte": "03"'],
                ['filename' => 'roto.pdf', 'mime' => 'application/pdf', 'data' => '%PDF-1.4 fake'],
            ],
        ]];

        $sync = $this->sincronizadorCon($this->buzonCon($mensajes));
        $sync->sincronizar();

        $doc = DocumentoRecibido::firstOrFail();
        $this->assertSame('json_invalido', $doc->clasificacion);
        $this->assertNotNull($doc->clasificacion_diagnostico);
        $diag = $doc->clasificacion_diagnostico;
        $this->assertArrayHasKey('error', $diag);
        $this->assertArrayNotHasKey('primeros_500', $diag);
        $this->assertArrayNotHasKey('contenido', $diag);
        // El diagnóstico no debe filtrar el contenido crudo del adjunto.
        $this->assertStringNotContainsString('identificacion', json_encode($diag));
    }

    public function test_tipo_reconocido_pero_sin_mapeo_de_total_conserva_datos_y_se_marca_tipo_no_soportado(): void
    {
        Mail::fake();
        Storage::fake('local');

        $json = [
            'identificacion' => ['tipoDte' => '04', 'numeroControl' => 'DTE-04-XXX-1', 'codigoGeneracion' => 'COD-04', 'fecEmi' => '2026-07-10'],
            'emisor' => ['nombre' => 'PROVEEDOR REMISION S.A.', 'nit' => '06140000000002', 'nrc' => '777777'],
        ];

        $mensajes = [[
            'id' => 'uid-remision', 'asunto' => 'Nota de Remisión', 'remitente' => 'proveedor@correo.com', 'fecha' => '2026-07-10',
            'adjuntos' => [
                ['filename' => 'remision.json', 'mime' => 'application/json', 'data' => json_encode($json)],
            ],
        ]];

        $sync = $this->sincronizadorCon($this->buzonCon($mensajes));
        $sync->sincronizar();

        $doc = DocumentoRecibido::firstOrFail();
        $this->assertSame('tipo_no_soportado', $doc->clasificacion);
        // Los datos que SÍ se pudieron leer (número de control, emisor) se conservan.
        $this->assertSame('DTE-04-XXX-1', $doc->numero_control);
        $this->assertSame('PROVEEDOR REMISION S.A.', $doc->emisor_nombre);
    }

    // ---------- Estado independiente de clasificación ----------

    public function test_clasificacion_y_estado_son_independientes(): void
    {
        $doc = DocumentoRecibido::create([
            'gmail_message_id' => 'm-independiente',
            'tipo_documento' => '03',
            'estado' => 'pendiente',
            'clasificacion' => 'dte_valido',
            'total' => 100,
            'tiene_pdf' => true,
            'tiene_json' => true,
            'fecha_correo' => now(),
        ]);

        $admin = $this->usuario('administrador');
        $this->actingAs($admin)->patch(route('documentos-recibidos.ignorar', $doc))->assertRedirect();

        $doc->refresh();
        $this->assertSame('ignorado', $doc->estado);
        $this->assertSame('dte_valido', $doc->clasificacion); // no cambia al ignorar

        $this->actingAs($admin)->patch(route('documentos-recibidos.enviado', $doc))->assertRedirect();
        $doc->refresh();
        $this->assertSame('enviado', $doc->estado);
        $this->assertSame('dte_valido', $doc->clasificacion); // no cambia al marcar enviado
    }

    public function test_sincronizar_nunca_marca_ignorado_automaticamente(): void
    {
        Mail::fake();
        Storage::fake('local');

        $mensajes = [[
            'id' => 'uid-no-dte', 'asunto' => 'Operador Santa Tecla las Ramblas', 'remitente' => 'operador@x.com', 'fecha' => '2026-07-10',
            'adjuntos' => [['filename' => 'DILVE.pdf', 'mime' => 'application/pdf', 'data' => '%PDF-1.4 fake']],
        ]];

        $sync = $this->sincronizadorCon($this->buzonCon($mensajes));
        $sync->sincronizar();

        $doc = DocumentoRecibido::firstOrFail();
        $this->assertSame('no_es_dte', $doc->clasificacion);
        $this->assertSame('pendiente', $doc->estado); // sigue pendiente: el usuario decide si ignorarlo
    }

    // ---------- Interfaz ----------

    public function test_interfaz_muestra_la_clasificacion(): void
    {
        DocumentoRecibido::create([
            'gmail_message_id' => 'm-ui-valido', 'emisor_nombre' => 'PROVEEDOR UI', 'tipo_documento' => '03',
            'numero_control' => 'DTE-03-UI-0001', 'estado' => 'pendiente', 'clasificacion' => 'dte_valido',
            'total' => 100, 'tiene_pdf' => true, 'tiene_json' => true, 'fecha_correo' => now(),
        ]);
        DocumentoRecibido::create([
            'gmail_message_id' => 'm-ui-no-dte', 'asunto' => 'Estado de Cuenta X', 'estado' => 'pendiente',
            'clasificacion' => 'no_es_dte', 'tiene_pdf' => true, 'tiene_json' => false, 'fecha_correo' => now(),
        ]);
        DocumentoRecibido::create([
            'gmail_message_id' => 'm-ui-07', 'emisor_nombre' => 'PROVEEDOR RETENIDO', 'tipo_documento' => '07',
            'numero_control' => 'DTE-07-UI-0001', 'estado' => 'pendiente', 'clasificacion' => 'dte_valido',
            'total' => 146.88, 'tiene_pdf' => true, 'tiene_json' => true, 'fecha_correo' => now(),
        ]);

        $resp = $this->actingAs($this->usuario('contador'))
            ->get(route('documentos-recibidos.index', ['vista' => 'bandeja']))
            ->assertOk();

        $resp->assertSee('DTE válido');
        $resp->assertSee('No es DTE');
        $resp->assertSee('Monto sujeto a retención');
    }

    // ---------- Comando de backfill ----------

    public function test_comando_reclasificar_dry_run_no_persiste_nada(): void
    {
        Storage::fake('local');
        $ruta = 'documentos-recibidos/1/dte.json';
        Storage::disk('local')->put($ruta, json_encode($this->jsonCcf('COD-BACKFILL', 'DTE-03-BF-1')));

        $doc = DocumentoRecibido::create([
            'gmail_message_id' => 'm-backfill-dry', 'estado' => 'pendiente', 'tiene_pdf' => true, 'tiene_json' => true,
            'fecha_correo' => now(), 'metadata_json' => ['archivos' => [$ruta], 'adjuntos' => [['filename' => 'dte.json', 'mime' => 'application/json']]],
        ]);
        $this->assertNull($doc->clasificacion);

        Artisan::call('documentos-recibidos:reclasificar');
        $salida = Artisan::output();

        $doc->refresh();
        $this->assertNull($doc->clasificacion); // dry-run: no escribió nada
        $this->assertStringContainsString('DRY-RUN', $salida);
        $this->assertStringContainsString('dte_valido', $salida);
    }

    public function test_comando_reclasificar_apply_es_idempotente(): void
    {
        Storage::fake('local');
        $ruta = 'documentos-recibidos/2/dte.json';
        Storage::disk('local')->put($ruta, json_encode($this->jsonCcf('COD-BACKFILL-2', 'DTE-03-BF-2')));

        $doc = DocumentoRecibido::create([
            'gmail_message_id' => 'm-backfill-apply', 'estado' => 'enviado', 'tiene_pdf' => true, 'tiene_json' => true,
            'fecha_correo' => now(), 'metadata_json' => ['archivos' => [$ruta], 'adjuntos' => [['filename' => 'dte.json', 'mime' => 'application/json']]],
        ]);

        Artisan::call('documentos-recibidos:reclasificar', ['--apply' => true]);
        $doc->refresh();
        $this->assertSame('dte_valido', $doc->clasificacion);
        $this->assertSame('enviado', $doc->estado); // --apply no toca estado

        // Segunda corrida: mismo resultado, sin duplicar filas ni reventar.
        Artisan::call('documentos-recibidos:reclasificar', ['--apply' => true]);
        $doc->refresh();
        $this->assertSame('dte_valido', $doc->clasificacion);
        $this->assertSame(1, DocumentoRecibido::count());
    }

    public function test_comando_reclasificar_no_toca_total_ni_reintenta_correo(): void
    {
        // PDF sin JSON de un correo que NO es un DTE: total debe seguir NULL
        // después de --apply. Fuera del caso tipo 07, el comando solo toca
        // clasificacion/diagnóstico.
        Storage::fake('local');
        $doc = DocumentoRecibido::create([
            'gmail_message_id' => 'm-backfill-no-dte', 'asunto' => 'Estado de Cuenta Corriente Bancoagrícola',
            'estado' => 'pendiente', 'tiene_pdf' => true, 'tiene_json' => false, 'total' => null,
            'fecha_correo' => now(), 'metadata_json' => ['adjuntos' => [['filename' => '5040117729.pdf', 'mime' => 'application/pdf']]],
        ]);

        Artisan::call('documentos-recibidos:reclasificar', ['--apply' => true]);

        $doc->refresh();
        $this->assertSame('no_es_dte', $doc->clasificacion);
        $this->assertNull($doc->total);
    }

    // ---------- Backfill: completar total del tipo 07 (resumen.totalSujetoRetencion) ----------

    public function test_backfill_tipo07_con_total_null_recupera_total_sujeto_a_retencion(): void
    {
        Storage::fake('local');
        $ruta = 'documentos-recibidos/7/retencion.json';
        Storage::disk('local')->put($ruta, json_encode($this->jsonRetencion('COD-BF-07-1', 'DTE-07-M001P002-000000000000334')));

        $doc = DocumentoRecibido::create([
            'gmail_message_id' => 'm-bf-07-1', 'tipo_documento' => '07', 'estado' => 'pendiente',
            'tiene_pdf' => true, 'tiene_json' => true, 'total' => null, 'fecha_correo' => now(),
            'metadata_json' => ['archivos' => [$ruta], 'adjuntos' => [['filename' => 'retencion.json', 'mime' => 'application/json']]],
        ]);

        Artisan::call('documentos-recibidos:reclasificar', ['--apply' => true]);

        $doc->refresh();
        // 146.88 = resumen.totalSujetoRetencion del fixture (NO totalIVAretenido = 1.47).
        $this->assertSame(146.88, (float) $doc->total);
        $this->assertSame('dte_valido', $doc->clasificacion);
    }

    public function test_backfill_tipo07_con_total_existente_no_se_sobrescribe(): void
    {
        Storage::fake('local');
        $ruta = 'documentos-recibidos/8/retencion.json';
        Storage::disk('local')->put($ruta, json_encode($this->jsonRetencion('COD-BF-07-2', 'DTE-07-M001P002-000000000000335')));

        $doc = DocumentoRecibido::create([
            'gmail_message_id' => 'm-bf-07-2', 'tipo_documento' => '07', 'estado' => 'pendiente',
            'tiene_pdf' => true, 'tiene_json' => true, 'total' => 999.99, 'fecha_correo' => now(),
            'metadata_json' => ['archivos' => [$ruta], 'adjuntos' => [['filename' => 'retencion.json', 'mime' => 'application/json']]],
        ]);

        Artisan::call('documentos-recibidos:reclasificar', ['--apply' => true]);

        $doc->refresh();
        // 999.99 es un total ya poblado (manual o de otra fuente): nunca se pisa.
        $this->assertSame(999.99, (float) $doc->total);
    }

    public function test_backfill_tipo07_dry_run_no_persiste_total_ni_clasificacion(): void
    {
        Storage::fake('local');
        $ruta = 'documentos-recibidos/9/retencion.json';
        Storage::disk('local')->put($ruta, json_encode($this->jsonRetencion('COD-BF-07-3', 'DTE-07-M001P002-000000000000336')));

        $doc = DocumentoRecibido::create([
            'gmail_message_id' => 'm-bf-07-3', 'tipo_documento' => '07', 'estado' => 'pendiente',
            'tiene_pdf' => true, 'tiene_json' => true, 'total' => null, 'fecha_correo' => now(),
            'metadata_json' => ['archivos' => [$ruta], 'adjuntos' => [['filename' => 'retencion.json', 'mime' => 'application/json']]],
        ]);

        Artisan::call('documentos-recibidos:reclasificar'); // sin --apply
        $salida = Artisan::output();

        $doc->refresh();
        $this->assertNull($doc->total);
        $this->assertNull($doc->clasificacion);
        $this->assertStringContainsString('146.88', $salida);
        $this->assertStringContainsString('IDs cuyo total sería completado', $salida);
        $this->assertStringContainsString((string) $doc->id, $salida);
    }

    public function test_backfill_tipo07_apply_persiste_clasificacion_y_total(): void
    {
        Storage::fake('local');
        $ruta = 'documentos-recibidos/10/retencion.json';
        Storage::disk('local')->put($ruta, json_encode($this->jsonRetencion('COD-BF-07-4', 'DTE-07-M001P002-000000000000337')));

        $doc = DocumentoRecibido::create([
            'gmail_message_id' => 'm-bf-07-4', 'tipo_documento' => '07', 'estado' => 'pendiente',
            'tiene_pdf' => true, 'tiene_json' => true, 'total' => null, 'fecha_correo' => now(),
            'metadata_json' => ['archivos' => [$ruta], 'adjuntos' => [['filename' => 'retencion.json', 'mime' => 'application/json']]],
        ]);

        Artisan::call('documentos-recibidos:reclasificar', ['--apply' => true]);

        $doc->refresh();
        $this->assertSame('dte_valido', $doc->clasificacion);
        $this->assertSame(146.88, (float) $doc->total);
    }

    public function test_backfill_tipo07_segunda_ejecucion_no_propone_cambios(): void
    {
        Storage::fake('local');
        $ruta = 'documentos-recibidos/11/retencion.json';
        Storage::disk('local')->put($ruta, json_encode($this->jsonRetencion('COD-BF-07-5', 'DTE-07-M001P002-000000000000338')));

        DocumentoRecibido::create([
            'gmail_message_id' => 'm-bf-07-5', 'tipo_documento' => '07', 'estado' => 'pendiente',
            'tiene_pdf' => true, 'tiene_json' => true, 'total' => null, 'fecha_correo' => now(),
            'metadata_json' => ['archivos' => [$ruta], 'adjuntos' => [['filename' => 'retencion.json', 'mime' => 'application/json']]],
        ]);

        Artisan::call('documentos-recibidos:reclasificar', ['--apply' => true]);

        // Segunda corrida en dry-run: ya no debería proponer nada para este registro.
        Artisan::call('documentos-recibidos:reclasificar');
        $salida = Artisan::output();

        $this->assertStringContainsString('Se actualizarían con --apply: 0', $salida);
        $this->assertStringContainsString('IDs cuyo total sería completado: (ninguno)', $salida);
    }

    public function test_backfill_tipo07_no_toca_estado_ni_adjuntos(): void
    {
        Storage::fake('local');
        $rutaJson = 'documentos-recibidos/12/retencion.json';
        $rutaPdf = 'documentos-recibidos/12/retencion.pdf';
        Storage::disk('local')->put($rutaJson, json_encode($this->jsonRetencion('COD-BF-07-6', 'DTE-07-M001P002-000000000000339')));
        Storage::disk('local')->put($rutaPdf, '%PDF-1.4 fake');

        $doc = DocumentoRecibido::create([
            'gmail_message_id' => 'm-bf-07-6', 'tipo_documento' => '07', 'estado' => 'enviado',
            'tiene_pdf' => true, 'tiene_json' => true, 'total' => null, 'fecha_correo' => now(),
            'metadata_json' => [
                'archivos' => [$rutaJson, $rutaPdf],
                'adjuntos' => [['filename' => 'retencion.json', 'mime' => 'application/json'], ['filename' => 'retencion.pdf', 'mime' => 'application/pdf']],
            ],
        ]);

        Artisan::call('documentos-recibidos:reclasificar', ['--apply' => true]);

        $doc->refresh();
        $this->assertSame('enviado', $doc->estado); // estado manual intacto
        $this->assertTrue(Storage::disk('local')->exists($rutaJson));
        $this->assertTrue(Storage::disk('local')->exists($rutaPdf));
        $this->assertSame('%PDF-1.4 fake', Storage::disk('local')->get($rutaPdf)); // adjunto sin tocar
    }

    public function test_backfill_tipo07_json_invalido_no_rellena_total_y_guarda_diagnostico_seguro(): void
    {
        Storage::fake('local');
        $ruta = 'documentos-recibidos/13/retencion.json';
        Storage::disk('local')->put($ruta, '{"identificacion": {"tipoDte": "07"'); // roto a propósito

        $doc = DocumentoRecibido::create([
            'gmail_message_id' => 'm-bf-07-7', 'tipo_documento' => '07', 'estado' => 'pendiente',
            'tiene_pdf' => true, 'tiene_json' => true, 'total' => null, 'fecha_correo' => now(),
            'metadata_json' => ['archivos' => [$ruta], 'adjuntos' => [['filename' => 'retencion.json', 'mime' => 'application/json']]],
        ]);

        Artisan::call('documentos-recibidos:reclasificar', ['--apply' => true]);

        $doc->refresh();
        $this->assertNull($doc->total);
        $this->assertSame('json_invalido', $doc->clasificacion);
        $this->assertNotNull($doc->clasificacion_diagnostico);
        $this->assertArrayNotHasKey('primeros_500', $doc->clasificacion_diagnostico);
    }
}
