<?php

namespace Tests\Feature\Ordenes;

use App\Exceptions\Ppq\GmailDesconectadoException;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\OrdenCompraRecibida;
use App\Services\Ppq\GmailClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cobros\GmailClientDeImportacion;
use Tests\TestCase;

/**
 * `ordenes:sincronizar` con un Gmail en memoria: guarda cada correo una sola vez, con
 * su PDF; lo ilegible queda «sin leer»; sin Gmail termina sin romper. Datos ficticios.
 */
class OrdenesSincronizarCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('dte.storage.disk', 'local'));
    }

    /** @return array<int, array<string, string>> */
    private function adjunto(string $texto, string $nombre = 'orden.pdf'): array
    {
        return [['filename' => $nombre, 'mime' => 'application/pdf', 'data' => OrdenCompraParserTest::pdf($texto)]];
    }

    private function gmail(array $paginas, array $adjuntos): GmailClientDeImportacion
    {
        $gmail = new GmailClientDeImportacion($paginas, $adjuntos);
        $this->app->instance(GmailClient::class, $gmail);

        return $gmail;
    }

    public function test_guarda_las_ordenes_con_su_pdf_y_la_sala_resuelta(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        config(['ppq.cliente_default_id' => $cliente->id]);
        $sala = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'codigo' => '0232']);
        // El mismo código en otro cliente no se cuela.
        ClienteSucursal::factory()->create(['cliente_id' => Cliente::factory()->contribuyente()->create()->id, 'codigo' => '0232']);

        $this->gmail([['m1', 'm2']], [
            'm1' => $this->adjunto(OrdenCompraParserTest::texto('261102320010394')),
            'm2' => $this->adjunto("nada legible\n"),
        ]);

        $this->artisan('ordenes:sincronizar')->assertSuccessful();

        $leida = OrdenCompraRecibida::where('gmail_message_id', 'm1')->firstOrFail();
        $this->assertSame('261102320010394', $leida->numero);
        $this->assertSame('0232', $leida->sala_codigo);
        $this->assertSame($sala->id, $leida->cliente_sucursal_id);
        $this->assertSame('2026-11-05', $leida->fecha_creacion->toDateString());
        $this->assertSame('22.90', $leida->total);
        $this->assertCount(2, $leida->lineas);
        $this->assertSame(OrdenCompraRecibida::LEIDA, $leida->estado);
        Storage::disk((string) config('dte.storage.disk', 'local'))->assertExists($leida->pdf_path);
        $this->assertStringStartsWith(OrdenCompraRecibida::CARPETA_PDF.'/', $leida->pdf_path);
        $this->assertSame(64, strlen($leida->pdf_hash));

        // Lo ilegible se guarda igual, para revisarlo a mano.
        $sinLeer = OrdenCompraRecibida::where('gmail_message_id', 'm2')->firstOrFail();
        $this->assertNull($sinLeer->numero);
        $this->assertSame(OrdenCompraRecibida::SIN_LEER, $sinLeer->estado);
        Storage::disk((string) config('dte.storage.disk', 'local'))->assertExists($sinLeer->pdf_path);
    }

    public function test_es_idempotente_y_no_vuelve_a_bajar_lo_guardado(): void
    {
        $gmail = new class([['m1']], ['m1' => $this->adjunto(OrdenCompraParserTest::texto('2611023200103'))]) extends GmailClientDeImportacion
        {
            public int $bajados = 0;

            public ?string $consulta = null;

            public function idsDeCobros(string $query, ?string $token = null, int $max = 100): array
            {
                $this->consulta = $query;

                return parent::idsDeCobros($query, $token, $max);
            }

            public function adjuntos(string $messageId): array
            {
                $this->bajados++;

                return parent::adjuntos($messageId);
            }
        };
        $this->app->instance(GmailClient::class, $gmail);

        $this->artisan('ordenes:sincronizar', ['--dias' => 30])->assertSuccessful();
        $this->artisan('ordenes:sincronizar', ['--dias' => 30])->assertSuccessful();

        $this->assertSame(1, OrdenCompraRecibida::count());
        $this->assertSame(1, $gmail->bajados);
        $this->assertStringStartsWith('label:Ordenes after:', $gmail->consulta);
        $this->assertStringContainsString(now()->subDays(29)->format('Y/m/d'), $gmail->consulta);
    }

    public function test_un_numero_que_ya_llego_en_otro_correo_queda_sin_leer(): void
    {
        $texto = OrdenCompraParserTest::texto('261102320010394');
        $this->gmail([['m1', 'm2']], ['m1' => $this->adjunto($texto), 'm2' => $this->adjunto($texto."\nReenvío")]);

        $this->artisan('ordenes:sincronizar')->assertSuccessful();

        $this->assertSame('261102320010394', OrdenCompraRecibida::where('gmail_message_id', 'm1')->value('numero'));
        $repetida = OrdenCompraRecibida::where('gmail_message_id', 'm2')->firstOrFail();
        $this->assertNull($repetida->numero);
        $this->assertSame(OrdenCompraRecibida::SIN_LEER, $repetida->estado);
    }

    public function test_un_correo_sin_pdf_se_avisa_y_no_se_guarda(): void
    {
        $this->gmail([['m1']], ['m1' => [['filename' => 'nota.txt', 'mime' => 'text/plain', 'data' => 'hola']]]);

        $this->artisan('ordenes:sincronizar')
            ->expectsOutputToContain('no trae PDF')
            ->assertSuccessful();

        $this->assertSame(0, OrdenCompraRecibida::count());
    }

    public function test_con_gmail_desconectado_termina_sin_romper(): void
    {
        // El cliente real, sin integración configurada: no está disponible.
        $this->artisan('ordenes:sincronizar')
            ->expectsOutputToContain('Gmail no está disponible')
            ->assertFailed();

        $this->assertSame(0, OrdenCompraRecibida::count());
    }

    public function test_si_gmail_se_desconecta_a_mitad_termina_sin_romper(): void
    {
        $this->app->instance(GmailClient::class, new class([['m1']], []) extends GmailClientDeImportacion
        {
            public function idsDeCobros(string $query, ?string $token = null, int $max = 100): array
            {
                throw new GmailDesconectadoException('La conexión con Gmail expiró.');
            }
        });

        $this->artisan('ordenes:sincronizar')
            ->expectsOutputToContain('Gmail se desconectó')
            ->assertFailed();
    }

    public function test_la_etiqueta_es_configurable(): void
    {
        config(['ppq.gmail.label_ordenes' => 'OC_Prueba']);
        $gmail = new class([[]], []) extends GmailClientDeImportacion
        {
            public ?string $consulta = null;

            public function idsDeCobros(string $query, ?string $token = null, int $max = 100): array
            {
                $this->consulta = $query;

                return parent::idsDeCobros($query, $token, $max);
            }
        };
        $this->app->instance(GmailClient::class, $gmail);

        $this->artisan('ordenes:sincronizar')->assertSuccessful();

        $this->assertStringStartsWith('label:OC_Prueba ', $gmail->consulta);
    }

    /** Una orden «sin leer» como las que dejó el lector anterior, con su PDF guardado (o no). */
    private function sinLeer(string $mensaje, ?string $pdf, array $datos = []): OrdenCompraRecibida
    {
        $ruta = OrdenCompraRecibida::CARPETA_PDF.'/'.$mensaje.'.pdf';
        if ($pdf !== null) {
            Storage::disk((string) config('dte.storage.disk', 'local'))->put($ruta, $pdf);
        }

        return OrdenCompraRecibida::create([
            'pdf_path' => $ruta,
            'pdf_hash' => hash('sha256', $mensaje),
            'gmail_message_id' => $mensaje,
            'estado' => OrdenCompraRecibida::SIN_LEER,
            ...$datos,
        ]);
    }

    public function test_relee_las_sin_leer_desde_su_pdf_sin_ir_a_gmail(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        config(['ppq.cliente_default_id' => $cliente->id]);
        $sala = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'codigo' => '0317']);
        // Lo que ya tenía dato no se pisa: la fecha de despacho leída antes queda.
        $orden = $this->sinLeer('m1', OrdenCompraParserTest::pdf(OrdenCompraParserTest::textoEdi()), ['fecha_despacho' => '2026-11-09']);
        $gmail = new class([[]], []) extends GmailClientDeImportacion
        {
            public int $bajados = 0;

            public function adjuntos(string $messageId): array
            {
                $this->bajados++;

                return parent::adjuntos($messageId);
            }
        };
        $this->app->instance(GmailClient::class, $gmail);

        $this->artisan('ordenes:sincronizar')
            ->expectsOutputToContain('1 orden(es) que estaban «sin leer» se leyeron de nuevo')
            ->assertSuccessful();

        $orden->refresh();
        $this->assertSame(OrdenCompraRecibida::LEIDA, $orden->estado);
        $this->assertSame('26110317004521', $orden->numero);
        $this->assertSame('0317', $orden->sala_codigo);
        $this->assertSame($sala->id, $orden->cliente_sucursal_id);
        $this->assertSame('2026-11-04', $orden->fecha_creacion->toDateString());
        $this->assertSame('2026-11-09', $orden->fecha_despacho->toDateString());
        $this->assertSame('77.94', $orden->total);
        $this->assertCount(10, $orden->lineas);
        $this->assertSame(0, $gmail->bajados);

        // Idempotente: la segunda corrida no la vuelve a tocar.
        $antes = $orden->updated_at;
        $this->travel(1)->minutes();
        $this->artisan('ordenes:sincronizar')
            ->doesntExpectOutputToContain('se leyeron de nuevo')
            ->assertSuccessful();
        $this->assertEquals($antes, $orden->fresh()->updated_at);
    }

    public function test_al_releer_un_numero_repetido_o_sin_pdf_sigue_sin_leer_y_la_leida_no_se_toca(): void
    {
        $this->gmail([[]], []);
        $pdf = OrdenCompraParserTest::pdf(OrdenCompraParserTest::textoEdi());
        $leida = OrdenCompraRecibida::create([
            'numero' => '26110317004521', 'sala_codigo' => '0317', 'total' => '1.00',
            'pdf_path' => OrdenCompraRecibida::CARPETA_PDF.'/m0.pdf', 'pdf_hash' => hash('sha256', 'm0'),
            'gmail_message_id' => 'm0', 'estado' => OrdenCompraRecibida::LEIDA,
        ]);
        $repetida = $this->sinLeer('m1', $pdf);
        $sinPdf = $this->sinLeer('m2', null);

        $this->artisan('ordenes:sincronizar')
            ->expectsOutputToContain("Orden #{$repetida->id}: el número 26110317004521 ya es de la orden #{$leida->id}")
            ->expectsOutputToContain("Orden #{$sinPdf->id}: no está su PDF")
            ->assertSuccessful();

        $this->assertSame(OrdenCompraRecibida::SIN_LEER, $repetida->fresh()->estado);
        $this->assertNull($repetida->fresh()->numero);
        $this->assertNull($repetida->fresh()->lineas);
        $this->assertSame(OrdenCompraRecibida::SIN_LEER, $sinPdf->fresh()->estado);
        $this->assertSame('1.00', $leida->fresh()->total);
        $this->assertNull($leida->fresh()->lineas);
    }

    public function test_esta_programada_cada_diez_minutos_y_solo_con_gmail_habilitado(): void
    {
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'ordenes:sincronizar'));

        $this->assertNotNull($evento);
        $this->assertSame('*/10 * * * *', $evento->expression);

        config(['ppq.gmail.enabled' => false]);
        $this->assertFalse($evento->filtersPass($this->app));
        config(['ppq.gmail.enabled' => true]);
        $this->assertTrue($evento->filtersPass($this->app));
    }
}
