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
