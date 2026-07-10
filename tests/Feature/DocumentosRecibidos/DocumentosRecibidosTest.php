<?php

namespace Tests\Feature\DocumentosRecibidos;

use App\Models\DocumentoRecibido;
use App\Models\User;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\DocumentosRecibidos\ParserDocumentoRecibido;
use App\Services\DocumentosRecibidos\SincronizadorDocumentosRecibidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Documentos recibidos (Fase 1, solo lectura). La fuente de correo es
 * INDEPENDIENTE de Gmail/PPQ (contrato MailboxClient). Verifica parseo del emisor,
 * deduplicación (con MailboxClient MOCKEADO), aviso sin configuración, filtros y
 * que no se envía ningún correo.
 */
class DocumentosRecibidosTest extends TestCase
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

    /** JSON de un DTE recibido (emisor = proveedor que nos manda el documento). */
    private function jsonDte(string $codigo = 'ABC-123', string $numero = 'DTE-03-XXXX-001'): array
    {
        return [
            'identificacion' => ['tipoDte' => '03', 'numeroControl' => $numero, 'codigoGeneracion' => $codigo, 'fecEmi' => '2026-07-10'],
            'emisor' => ['nombre' => 'PROVEEDOR EJEMPLO S.A.', 'nit' => '06140000000000', 'nrc' => '999999'],
            'receptor' => ['nombre' => 'DULCES LA NEGRITA'],
            'resumen' => ['totalPagar' => 250.75],
        ];
    }

    /** Sincronizador con un MailboxClient de prueba (sin IMAP/Gmail reales). */
    private function sincronizadorCon(MailboxClient $buzon): SincronizadorDocumentosRecibidos
    {
        $this->app->instance(MailboxClient::class, $buzon);

        return app(SincronizadorDocumentosRecibidos::class);
    }

    public function test_parser_extrae_emisor_y_campos_del_dte_recibido(): void
    {
        $datos = app(ParserDocumentoRecibido::class)->extraer($this->jsonDte('COD-1', 'DTE-03-AAA-9'));

        $this->assertSame('03', $datos['tipo_documento']);
        $this->assertSame('DTE-03-AAA-9', $datos['numero_control']);
        $this->assertSame('COD-1', $datos['codigo_generacion']);
        $this->assertSame('PROVEEDOR EJEMPLO S.A.', $datos['emisor_nombre']);
        $this->assertSame('06140000000000', $datos['emisor_nit']);
        $this->assertSame('999999', $datos['emisor_nrc']);
        $this->assertSame(250.75, $datos['total']);
    }

    public function test_sincronizar_crea_registros_y_deduplica_desde_el_buzon(): void
    {
        Mail::fake();
        \Illuminate\Support\Facades\Storage::fake('local');

        // MailboxClient de prueba (independiente de Gmail): un correo con JSON+PDF.
        $mensajes = [[
            'id' => 'uid-1', 'asunto' => 'CCF de proveedor', 'remitente' => 'proveedor@correo.com', 'fecha' => '2026-07-10',
            'adjuntos' => [
                ['filename' => 'dte.json', 'mime' => 'application/json', 'data' => json_encode($this->jsonDte('COD-UNICO', 'DTE-03-BBB-1'))],
                ['filename' => 'dte.pdf', 'mime' => 'application/pdf', 'data' => '%PDF-1.4 fake'],
            ],
        ]];
        $buzon = \Mockery::mock(MailboxClient::class);
        $buzon->shouldReceive('disponible')->andReturn(true);
        $buzon->shouldReceive('fuente')->andReturn('IMAP dulceslanegrita@yahoo.com');
        $buzon->shouldReceive('mensajesConAdjuntos')->andReturn($mensajes);

        $sync = $this->sincronizadorCon($buzon);

        $r1 = $sync->sincronizar();
        $this->assertSame(1, $r1['nuevos']);
        $doc = DocumentoRecibido::firstOrFail();
        $this->assertSame('COD-UNICO', $doc->codigo_generacion);
        $this->assertSame('PROVEEDOR EJEMPLO S.A.', $doc->emisor_nombre);
        $this->assertTrue($doc->tiene_pdf);
        $this->assertTrue($doc->tiene_json);
        $this->assertSame('pendiente', $doc->estado);

        // Segunda corrida: mismo mensaje → NO se duplica.
        $r2 = $sync->sincronizar();
        $this->assertSame(0, $r2['nuevos']);
        $this->assertSame(1, DocumentoRecibido::count());

        Mail::assertNothingSent();
    }

    public function test_sin_correo_configurado_no_falla_y_avisa(): void
    {
        // El binding por defecto (sin config IMAP) resuelve al NullMailboxClient.
        $sync = app(SincronizadorDocumentosRecibidos::class);
        $this->assertFalse($sync->disponible());

        $r = $sync->sincronizar();
        $this->assertFalse($r['disponible']);
        $this->assertSame(0, $r['nuevos']);
        $this->assertNotNull($r['error']);
    }

    public function test_la_fuente_por_defecto_no_es_gmail_y_no_esta_disponible(): void
    {
        // La fuente resuelta es del módulo (IMAP/Null), NUNCA el GmailClient de PPQ, y
        // sin credenciales/soporte queda no disponible (revisión deshabilitada).
        $buzon = app(MailboxClient::class);
        $this->assertInstanceOf(MailboxClient::class, $buzon);
        $this->assertStringContainsString('DocumentosRecibidos', get_class($buzon));
        $this->assertStringNotContainsString('Gmail', get_class($buzon));
        $this->assertFalse($buzon->disponible());
    }

    public function test_el_modulo_no_referencia_gmailclient(): void
    {
        // Garantía estructural: ningún archivo del módulo depende de GmailClient de PPQ.
        foreach (glob(app_path('Services/DocumentosRecibidos/*.php')) ?: [] as $archivo) {
            $this->assertStringNotContainsString('GmailClient', (string) file_get_contents($archivo),
                basename($archivo).' no debe usar GmailClient (la fuente es IMAP, independiente de PPQ).');
        }
        $this->assertStringNotContainsString('GmailClient',
            (string) file_get_contents(app_path('Http/Controllers/DocumentosRecibidos/DocumentoRecibidoController.php')));
    }

    public function test_listado_carga_y_filtra_por_estado_y_emisor(): void
    {
        DocumentoRecibido::create(['gmail_message_id' => 'm1', 'emisor_nombre' => 'PROVEEDOR UNO', 'tipo_documento' => '03', 'estado' => 'pendiente', 'tiene_pdf' => true, 'tiene_json' => true]);
        DocumentoRecibido::create(['gmail_message_id' => 'm2', 'emisor_nombre' => 'PROVEEDOR DOS', 'tipo_documento' => '03', 'estado' => 'ignorado', 'tiene_pdf' => true, 'tiene_json' => false]);

        $this->actingAs($this->usuario('contador'))
            ->get(route('documentos-recibidos.index', ['vista' => 'pendientes']))
            ->assertOk()
            ->assertSee('PROVEEDOR UNO')
            ->assertDontSee('PROVEEDOR DOS');
    }

    public function test_index_sin_config_muestra_aviso_y_no_truena(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('documentos-recibidos.index'))
            ->assertOk()
            ->assertSee('Configurar correo Yahoo/IMAP');
    }

    public function test_marcar_pendiente_e_ignorado_cambia_estado_sin_enviar_correo(): void
    {
        Mail::fake();
        $doc = DocumentoRecibido::create(['gmail_message_id' => 'm3', 'emisor_nombre' => 'X', 'estado' => 'pendiente']);
        $admin = $this->usuario('administrador');

        $this->actingAs($admin)->patch(route('documentos-recibidos.ignorar', $doc))->assertRedirect();
        $this->assertSame('ignorado', $doc->refresh()->estado);

        $this->actingAs($admin)->patch(route('documentos-recibidos.pendiente', $doc))->assertRedirect();
        $this->assertSame('pendiente', $doc->refresh()->estado);

        Mail::assertNothingSent();
    }

    public function test_consulta_no_accede(): void
    {
        $this->actingAs($this->usuario('consulta'))
            ->get(route('documentos-recibidos.index'))
            ->assertForbidden();
    }
}
