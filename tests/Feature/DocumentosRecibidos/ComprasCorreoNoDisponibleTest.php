<?php

namespace Tests\Feature\DocumentosRecibidos;

use App\Exceptions\DocumentosRecibidos\AutenticacionBuzonException;
use App\Exceptions\DocumentosRecibidos\BuzonInaccesibleException;
use App\Services\DocumentosRecibidos\BitacoraSincronizacionCompras;
use App\Services\DocumentosRecibidos\BuzonConFreno;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\DocumentosRecibidos\ImapMailboxClient;
use App\Services\DocumentosRecibidos\ResumenSincronizacion;
use App\Services\DocumentosRecibidos\SincronizadorDocumentosRecibidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuzonFalso;
use Tests\TestCase;

/**
 * Gmail a veces contesta «[UNAVAILABLE] … temporarily not available» (16/11/2026, y antes
 * el 13/11). Es una caída suya de unos minutos: la contraseña está bien y la corrida
 * siguiente entra. No tiene que llegar como «autenticación fallida» ni mandar el aviso de
 * tarea caída; una contraseña mala de verdad sí.
 */
class ComprasCorreoNoDisponibleTest extends TestCase
{
    use RefreshDatabase;

    private const GMAIL_CAIDO = 'Can not authenticate to IMAP server: [UNAVAILABLE] AUTHENTICATE Service is temporarily not available';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-11-16 20:16:00');
    }

    private function caido(): BuzonInaccesibleException
    {
        return ImapMailboxClient::excepcionPara(self::GMAIL_CAIDO, 'No se pudo abrir el buzón');
    }

    public function test_la_caida_de_gmail_no_es_credencial_mala(): void
    {
        foreach ([self::GMAIL_CAIDO, '[UNAVAILABLE] Server error - Please try again later.'] as $motivo) {
            $e = ImapMailboxClient::excepcionPara($motivo, 'No se pudo abrir el buzón');

            $this->assertNotInstanceOf(AutenticacionBuzonException::class, $e, $motivo);
            $this->assertInstanceOf(BuzonInaccesibleException::class, $e, $motivo);
            $this->assertStringNotContainsString('Revisá usuario y contraseña', $e->getMessage());
        }
    }

    public function test_el_comando_avisa_y_termina_bien_si_gmail_no_responde(): void
    {
        $this->app->instance(MailboxClient::class, new BuzonConFreno((new BuzonFalso)->queFalla($this->caido())));

        $this->artisan('compras:sincronizar', [
            '--desde' => '2026-11-16', '--hasta' => '2026-11-16', '--aplicar' => true,
        ])
            ->expectsOutputToContain('CORREO NO DISPONIBLE')
            ->doesntExpectOutputToContain('AUTENTICACIÓN FALLIDA')
            ->assertSuccessful();

        $bitacora = app(BitacoraSincronizacionCompras::class);
        $this->assertStringContainsString('UNAVAILABLE', (string) $bitacora->ultimoError());
        $this->assertNull($bitacora->ultimoExito(), 'el buzón no se leyó: no es un éxito');
    }

    public function test_el_sincronizador_lo_informa_como_pasajero(): void
    {
        $this->app->instance(MailboxClient::class, (new BuzonFalso)->queFalla($this->caido()));

        $r = app(SincronizadorDocumentosRecibidos::class)
            ->sincronizarRango(Carbon::parse('2026-11-16'), Carbon::parse('2026-11-16'), 30, true);

        $this->assertSame(ResumenSincronizacion::CORREO_NO_DISPONIBLE, $r->desenlace);
        $this->assertTrue($r->fallo(), 'el rango no se leyó');
        $this->assertTrue($r->pasajera());
    }

    public function test_una_contrasena_mala_sigue_dando_error(): void
    {
        $this->app->instance(MailboxClient::class, (new BuzonFalso)->queFalla(
            ImapMailboxClient::excepcionPara('[AUTHENTICATIONFAILED] Invalid credentials (Failure)', 'No se pudo abrir el buzón')
        ));

        $this->artisan('compras:sincronizar', [
            '--desde' => '2026-11-16', '--hasta' => '2026-11-16', '--aplicar' => true,
        ])
            ->expectsOutputToContain('AUTENTICACIÓN FALLIDA')
            ->assertFailed();
    }
}
