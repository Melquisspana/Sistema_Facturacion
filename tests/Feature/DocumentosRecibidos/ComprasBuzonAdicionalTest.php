<?php

namespace Tests\Feature\DocumentosRecibidos;

use App\Ajustes\Integraciones\ConfiguracionBuzonAdicional;
use App\Models\DocumentoRecibido;
use App\Models\DocumentoRecibidoProgreso;
use App\Services\DocumentosRecibidos\BitacoraSincronizacionCompras;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\DocumentosRecibidos\ProgresoSincronizacionCompras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuzonFalso;
use Tests\TestCase;

/**
 * Cambio de correo: durante un tiempo los DTE llegan a DOS buzones y `compras:sincronizar`
 * tiene que leer los dos sin perder ni duplicar compras, cada uno con su propio progreso.
 */
class ComprasBuzonAdicionalTest extends TestCase
{
    use RefreshDatabase;

    private const DIA = ['--desde' => '2026-10-07', '--hasta' => '2026-10-07', '--aplicar' => true];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function conBuzones(BuzonFalso $principal, ?BuzonFalso $adicional, string $carpetaAdicional = 'DTE-Compras'): void
    {
        $this->app->instance(MailboxClient::class, $principal);

        if ($adicional !== null) {
            config([
                'documentos_recibidos.buzon_adicional.host' => 'imap.example.com',
                'documentos_recibidos.buzon_adicional.username' => 'compras@example.com',
                'documentos_recibidos.buzon_adicional.password' => 'secreto-de-prueba',
                'documentos_recibidos.buzon_adicional.folder' => $carpetaAdicional,
            ]);
            $this->app->instance(ConfiguracionBuzonAdicional::LECTOR, $adicional);
        }
    }

    public function test_lee_los_dos_buzones_con_progreso_separado(): void
    {
        $this->conBuzones(
            (new BuzonFalso(5001, 'INBOX'))->conDte(1, '2026-10-07 09:00:00', 'COD-YAHOO'),
            (new BuzonFalso(7001, 'DTE-Compras'))->conDte(1, '2026-10-07 10:00:00', 'COD-GMAIL'),
        );

        $this->artisan('compras:sincronizar', self::DIA)
            ->expectsOutputToContain('Buzón adicional')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['COD-YAHOO', 'COD-GMAIL'],
            DocumentoRecibido::pluck('codigo_generacion')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['INBOX', 'DTE-Compras'],
            DocumentoRecibidoProgreso::where('dia', '2026-10-07')->pluck('carpeta')->all(),
        );
        $this->assertTrue(DocumentoRecibidoProgreso::where('carpeta', 'DTE-Compras')->firstOrFail()->estaCompleto());
    }

    /** El proveedor manda el mismo DTE a las dos direcciones: queda UNA compra. */
    public function test_el_mismo_dte_en_los_dos_buzones_no_se_duplica(): void
    {
        $this->conBuzones(
            (new BuzonFalso(5001, 'INBOX'))->conDte(1, '2026-10-07 09:00:00', 'COD-DOBLE'),
            // Envío aparte: otro Message-ID, mismo código de generación.
            (new BuzonFalso(7001, 'DTE-Compras'))->conDte(1, '2026-10-07 09:01:00', 'COD-DOBLE', null, [
                'message_id' => '<otro-envio@proveedor.example>',
            ]),
        );

        $this->artisan('compras:sincronizar', self::DIA)->assertSuccessful();
        $this->artisan('compras:sincronizar', self::DIA)->assertSuccessful();

        $this->assertSame(1, DocumentoRecibido::count());
    }

    /** El incremental de cada buzón arranca en SU marca: uno al día no adelanta al otro. */
    public function test_el_incremental_usa_la_marca_de_cada_carpeta(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');
        $progreso = app(ProgresoSincronizacionCompras::class);
        $progreso->marcarCompleto(Carbon::parse('2026-10-07'), 'INBOX', 5001, 1, []);

        // El Gmail recién empieza: su correo del 03/10 cae antes de la marca del Yahoo
        // y tiene que leerse igual.
        $this->conBuzones(
            new BuzonFalso(5001, 'INBOX'),
            (new BuzonFalso(7001, 'DTE-Compras'))->conDte(1, '2026-10-03 10:00:00', 'COD-GMAIL-ANTERIOR'),
        );

        $this->artisan('compras:sincronizar', ['--aplicar' => true])->assertSuccessful();

        $this->assertTrue(DocumentoRecibido::where('codigo_generacion', 'COD-GMAIL-ANTERIOR')->exists());
    }

    public function test_misma_carpeta_que_el_principal_se_frena(): void
    {
        $this->conBuzones(
            (new BuzonFalso(5001, 'INBOX'))->conDte(1, '2026-10-07 09:00:00', 'COD-YAHOO'),
            (new BuzonFalso(7001, 'INBOX'))->conDte(1, '2026-10-07 10:00:00', 'COD-GMAIL'),
            carpetaAdicional: 'INBOX',
        );

        $this->artisan('compras:sincronizar', self::DIA)
            ->expectsOutputToContain('misma carpeta')
            ->assertFailed();

        $this->assertSame(['COD-YAHOO'], DocumentoRecibido::pluck('codigo_generacion')->all());
    }

    public function test_solo_principal_no_lee_el_adicional(): void
    {
        $this->conBuzones(
            (new BuzonFalso(5001, 'INBOX'))->conDte(1, '2026-10-07 09:00:00', 'COD-YAHOO'),
            (new BuzonFalso(7001, 'DTE-Compras'))->conDte(1, '2026-10-07 10:00:00', 'COD-GMAIL'),
        );

        $this->artisan('compras:sincronizar', self::DIA + ['--buzon' => 'principal'])->assertSuccessful();

        $this->assertSame(['COD-YAHOO'], DocumentoRecibido::pluck('codigo_generacion')->all());
    }

    public function test_sin_adicional_configurado_todos_lee_solo_el_principal(): void
    {
        $this->conBuzones((new BuzonFalso(5001, 'INBOX'))->conDte(1, '2026-10-07 09:00:00', 'COD-YAHOO'), null);

        $this->artisan('compras:sincronizar', self::DIA)
            ->doesntExpectOutputToContain('Buzón adicional')
            ->assertSuccessful();

        $this->artisan('compras:sincronizar', self::DIA + ['--buzon' => 'adicional'])
            ->expectsOutputToContain('no está configurado')
            ->assertFailed();
    }

    /** La pantalla muestra la bitácora del principal: el adicional no la pisa. */
    public function test_una_falla_del_adicional_no_pisa_la_bitacora_del_principal(): void
    {
        $this->conBuzones(
            (new BuzonFalso(5001, 'INBOX'))->conDte(1, '2026-10-07 09:00:00', 'COD-YAHOO'),
            new BuzonFalso(7001, 'DTE-Compras', disponible: false),
        );

        $this->artisan('compras:sincronizar', self::DIA)->assertFailed();

        $this->assertNull(app(BitacoraSincronizacionCompras::class)->ultimoError());
        $this->assertNotNull(app(BitacoraSincronizacionCompras::class)->ultimoExito());
    }

    /** Una fila vieja del principal (UID 1, sin identidad) no tapa al UID 1 del adicional. */
    public function test_una_fila_vieja_sin_identidad_no_tapa_un_correo_del_adicional(): void
    {
        DocumentoRecibido::create([
            'gmail_message_id' => '1', 'estado' => 'pendiente', 'asunto' => 'Fila vieja del buzón principal',
        ]);
        $this->conBuzones(
            new BuzonFalso(5001, 'INBOX'),
            (new BuzonFalso(7001, 'DTE-Compras'))->conDte(1, '2026-10-07 10:00:00', 'COD-GMAIL'),
        );

        $this->artisan('compras:sincronizar', self::DIA)->assertSuccessful();

        $this->assertTrue(DocumentoRecibido::where('codigo_generacion', 'COD-GMAIL')->exists());
        $this->assertNull(DocumentoRecibido::where('gmail_message_id', '1')->value('identidad'));
    }

    public function test_rechaza_un_buzon_desconocido(): void
    {
        $this->artisan('compras:sincronizar', ['--buzon' => 'yahoo'])->assertFailed();
    }
}
