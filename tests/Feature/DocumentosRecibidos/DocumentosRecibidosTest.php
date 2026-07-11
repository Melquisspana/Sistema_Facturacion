<?php

namespace Tests\Feature\DocumentosRecibidos;

use App\Models\DocumentoRecibido;
use App\Models\User;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\DocumentosRecibidos\DocumentosRecibidosExcel;
use App\Services\DocumentosRecibidos\DocumentosRecibidosQuery;
use App\Services\DocumentosRecibidos\ParserDocumentoRecibido;
use App\Services\DocumentosRecibidos\SincronizadorDocumentosRecibidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Documentos recibidos: herramienta interna para preparar lo que se le manda a la
 * contadora (ella no entra al sistema). Fuente de correo INDEPENDIENTE de Gmail/PPQ
 * (MailboxClient). Verifica parser, dedupe, defaults (pendientes del mes),
 * paginación, filtros, resumen y Excel. Nada envía correos ni toca el buzón.
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

    private function doc(array $extra = []): DocumentoRecibido
    {
        static $n = 0;
        $n++;

        return DocumentoRecibido::create($extra + [
            'gmail_message_id' => 'm'.$n,
            'emisor_nombre' => 'PROVEEDOR '.$n,
            'tipo_documento' => '03',
            'numero_control' => 'DTE-03-XXX-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'estado' => 'pendiente',
            'total' => 100.00,
            'tiene_pdf' => true,
            'tiene_json' => true,
            'fecha_correo' => now(),
        ]);
    }

    private function jsonDte(string $codigo = 'ABC-123', string $numero = 'DTE-03-XXXX-001'): array
    {
        return [
            'identificacion' => ['tipoDte' => '03', 'numeroControl' => $numero, 'codigoGeneracion' => $codigo, 'fecEmi' => '2026-07-10'],
            'emisor' => ['nombre' => 'PROVEEDOR EJEMPLO S.A.', 'nit' => '06140000000000', 'nrc' => '999999'],
            'receptor' => ['nombre' => 'DULCES LA NEGRITA'],
            'resumen' => ['totalPagar' => 250.75],
        ];
    }

    private function sincronizadorCon(MailboxClient $buzon): SincronizadorDocumentosRecibidos
    {
        $this->app->instance(MailboxClient::class, $buzon);

        return app(SincronizadorDocumentosRecibidos::class);
    }

    // ---------- parser / sincronización ----------

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
        $this->assertSame('2026-07-10', $datos['fecha']);
    }

    public function test_sincronizar_crea_registros_con_fecha_dte_y_deduplica(): void
    {
        Mail::fake();
        \Illuminate\Support\Facades\Storage::fake('local');

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

        $this->assertSame(1, $sync->sincronizar()['nuevos']);
        $doc = DocumentoRecibido::firstOrFail();
        $this->assertSame('COD-UNICO', $doc->codigo_generacion);
        $this->assertSame('2026-07-10', $doc->fecha_dte->format('Y-m-d'));
        $this->assertTrue($doc->tiene_pdf && $doc->tiene_json);

        $this->assertSame(0, $sync->sincronizar()['nuevos']); // dedupe
        $this->assertSame(1, DocumentoRecibido::count());
        Mail::assertNothingSent();
    }

    public function test_sin_correo_configurado_no_falla_y_avisa(): void
    {
        $r = app(SincronizadorDocumentosRecibidos::class)->sincronizar();
        $this->assertFalse($r['disponible']);
        $this->assertNotNull($r['error']);
    }

    public function test_el_modulo_no_referencia_gmailclient(): void
    {
        foreach (glob(app_path('Services/DocumentosRecibidos/*.php')) ?: [] as $archivo) {
            $this->assertStringNotContainsString('GmailClient', (string) file_get_contents($archivo));
        }
    }

    // ---------- vista por defecto: pendientes del mes actual ----------

    public function test_por_defecto_muestra_pendientes_del_mes_actual(): void
    {
        $pendienteEsteMes = $this->doc(['emisor_nombre' => 'DEL MES PENDIENTE', 'estado' => 'pendiente', 'fecha_correo' => now()]);
        $enviadoEsteMes = $this->doc(['emisor_nombre' => 'DEL MES ENVIADO', 'estado' => 'enviado', 'fecha_correo' => now()]);
        $pendienteMesPasado = $this->doc(['emisor_nombre' => 'MES PASADO', 'estado' => 'pendiente', 'fecha_correo' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3)]);

        $resp = $this->actingAs($this->usuario('contador'))
            ->get(route('documentos-recibidos.index'))
            ->assertOk();

        $resp->assertSee('DEL MES PENDIENTE');          // pendiente + mes actual
        $resp->assertDontSee('DEL MES ENVIADO');        // otro estado
        $resp->assertDontSee('MES PASADO');             // mes anterior
        $this->assertSame('pendientes', $resp->viewData('filtros')['vista']);
        $this->assertSame('mes_actual', $resp->viewData('filtros')['rango']);
    }

    public function test_paginacion_respeta_por_pagina(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->doc(['estado' => 'pendiente', 'fecha_correo' => now()]);
        }

        $resp = $this->actingAs($this->usuario('administrador'))
            ->get(route('documentos-recibidos.index'))
            ->assertOk();

        $paginador = $resp->viewData('documentos');
        $this->assertSame(30, $paginador->total());
        $this->assertSame(25, $paginador->perPage());
        $this->assertCount(25, $paginador->items());
    }

    public function test_filtros_por_numero_control_y_monto(): void
    {
        $this->doc(['numero_control' => 'DTE-03-BUSCAME-1', 'total' => 500, 'fecha_correo' => now()]);
        $this->doc(['numero_control' => 'DTE-03-OTRO-2', 'total' => 50, 'fecha_correo' => now()]);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('documentos-recibidos.index', ['vista' => 'bandeja', 'numero_control' => 'BUSCAME']))
            ->assertOk()->assertSee('DTE-03-BUSCAME-1')->assertDontSee('DTE-03-OTRO-2');

        $this->actingAs($this->usuario('administrador'))
            ->get(route('documentos-recibidos.index', ['vista' => 'bandeja', 'monto_min' => 100]))
            ->assertOk()->assertSee('DTE-03-BUSCAME-1')->assertDontSee('DTE-03-OTRO-2');
    }

    public function test_resumen_calcula_cantidades_y_total_del_rango(): void
    {
        $this->doc(['estado' => 'pendiente', 'total' => 100, 'fecha_correo' => now()]);
        $this->doc(['estado' => 'pendiente', 'total' => 200, 'fecha_correo' => now()]);
        $this->doc(['estado' => 'enviado', 'total' => 300, 'fecha_correo' => now()]);
        $this->doc(['estado' => 'ignorado', 'total' => 50, 'fecha_correo' => now()]);

        $resumen = $this->actingAs($this->usuario('contador'))
            ->get(route('documentos-recibidos.index', ['vista' => 'bandeja']))
            ->assertOk()
            ->viewData('resumen');

        $this->assertSame(4, $resumen['total_docs']);
        $this->assertSame(650.0, $resumen['total_monto']);
        $this->assertSame(2, $resumen['pendiente']);
        $this->assertSame(1, $resumen['enviado']);
        $this->assertSame(1, $resumen['ignorado']);
    }

    // ---------- Excel ----------

    public function test_excel_tiene_las_columnas_esperadas(): void
    {
        $this->doc(['emisor_nombre' => 'PROV EXCEL', 'emisor_nit' => '0614X', 'numero_control' => 'DTE-03-EXCEL-1', 'total' => 123.45, 'fecha_correo' => now(), 'fecha_dte' => now()->toDateString()]);

        $filtros = DocumentosRecibidosQuery::filtros(['vista' => 'bandeja', 'rango' => 'todos']);
        $docs = DocumentosRecibidosQuery::query($filtros)->get();
        $ruta = (new DocumentosRecibidosExcel())->generar($docs);

        $hoja = IOFactory::load($ruta)->getActiveSheet();
        foreach (DocumentosRecibidosExcel::COLUMNAS as $i => $titulo) {
            $this->assertSame($titulo, $hoja->getCell([$i + 1, 1])->getValue());
        }
        $this->assertSame('PROV EXCEL', $hoja->getCell([3, 2])->getValue());
        $this->assertSame('DTE-03-EXCEL-1', $hoja->getCell([7, 2])->getValue());
        @unlink($ruta);
    }

    public function test_exportar_descarga_xlsx_con_nombre_del_mes(): void
    {
        $this->doc(['fecha_correo' => now()]);

        $this->actingAs($this->usuario('contador'))
            ->get(route('documentos-recibidos.exportar'))
            ->assertOk()
            ->assertDownload('documentos_recibidos_'.now()->format('Y-m').'.xlsx');
    }

    // ---------- estados (manual, sin correo) ----------

    public function test_marcar_estados_no_envia_correo(): void
    {
        Mail::fake();
        $doc = $this->doc(['estado' => 'pendiente', 'fecha_correo' => now()]);
        $admin = $this->usuario('administrador');

        $this->actingAs($admin)->patch(route('documentos-recibidos.enviado', $doc))->assertRedirect();
        $this->assertSame('enviado', $doc->refresh()->estado);

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
