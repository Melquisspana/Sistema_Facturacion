<?php

namespace Tests\Feature\DocumentosRecibidos;

use App\Exceptions\DocumentosRecibidos\AutenticacionBuzonException;
use App\Exceptions\DocumentosRecibidos\BuzonFrenadoException;
use App\Services\DocumentosRecibidos\BitacoraSincronizacionCompras;
use App\Services\DocumentosRecibidos\BuzonConFreno;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\DocumentosRecibidos\ResumenSincronizacion;
use App\Services\DocumentosRecibidos\SincronizadorDocumentosRecibidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuzonFalso;
use Tests\TestCase;

/**
 * Yahoo frena el login IMAP («Too many login failures») y después vuelve a dejar entrar
 * (#162). Es pasajero: la sincronización programada avisa y termina bien, y mientras dura
 * la espera nadie vuelve a intentar entrar, ni el scheduler ni el botón de Cobros.
 */
class ComprasBuzonFrenadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-10-25 10:00:00');
    }

    private function frenado(): BuzonFrenadoException
    {
        return new BuzonFrenadoException('Too many login failures');
    }

    private function conFreno(BuzonFalso $falso): BuzonConFreno
    {
        $buzon = new BuzonConFreno($falso);
        $this->app->instance(MailboxClient::class, $buzon);

        return $buzon;
    }

    public function test_el_comando_avisa_y_termina_bien_si_yahoo_frena_el_login(): void
    {
        $this->conFreno((new BuzonFalso)->queFalla($this->frenado()));

        $this->artisan('compras:sincronizar', [
            '--desde' => '2026-10-25', '--hasta' => '2026-10-25', '--aplicar' => true,
        ])
            ->expectsOutputToContain('BUZÓN FRENADO')
            ->doesntExpectOutputToContain('AUTENTICACIÓN FALLIDA')
            ->assertSuccessful();

        $bitacora = app(BitacoraSincronizacionCompras::class);
        $this->assertStringContainsString('pidió esperar', (string) $bitacora->ultimoError());
        $this->assertNull($bitacora->ultimoExito(), 'el buzón no se leyó: no es un éxito');
    }

    public function test_las_credenciales_malas_siguen_dando_error_aunque_haya_freno(): void
    {
        $this->conFreno((new BuzonFalso)->queFalla(new AutenticacionBuzonException('AUTHENTICATIONFAILED')));

        $this->artisan('compras:sincronizar', [
            '--desde' => '2026-10-25', '--hasta' => '2026-10-25', '--aplicar' => true,
        ])
            ->expectsOutputToContain('AUTENTICACIÓN FALLIDA')
            ->assertFailed();
    }

    public function test_el_sincronizador_lo_informa_como_buzon_frenado(): void
    {
        $this->conFreno((new BuzonFalso)->queFalla($this->frenado()));

        $r = app(SincronizadorDocumentosRecibidos::class)
            ->sincronizarRango(Carbon::parse('2026-10-25'), Carbon::parse('2026-10-25'), 30, true);

        $this->assertSame(ResumenSincronizacion::BUZON_FRENADO, $r->desenlace);
        $this->assertTrue($r->fallo(), 'el rango no se leyó');
    }

    /** Mientras dura la espera no se vuelve a entrar: cada intento alargaría el freno. */
    public function test_mientras_dura_la_espera_no_vuelve_a_entrar(): void
    {
        $falso = (new BuzonFalso)->queFalla($this->frenado());
        $buzon = $this->conFreno($falso);

        try {
            $buzon->estado();
            $this->fail('tenía que avisar el freno');
        } catch (BuzonFrenadoException $e) {
            $this->assertStringContainsString('5 minutos', $e->getMessage());
        }
        $falso->sinFalla();

        $this->travel(4)->minutes();
        $this->assertFrenado(fn () => $buzon->adjuntosDeRemitente('pagos@cliente.example', now(), ['txt']));
        $this->assertSame(0, $falso->llamadas(), 'no tenía que intentar entrar durante la espera');

        $this->travel(2)->minutes();
        $buzon->adjuntosDeRemitente('pagos@cliente.example', now(), ['txt']);
        $this->assertSame(1, $falso->llamadas(), 'pasada la espera, vuelve a probar');
    }

    /** Si Yahoo vuelve a frenar enseguida, la espera crece; al entrar bien, se olvida. */
    public function test_la_espera_crece_con_cada_freno_y_se_olvida_al_entrar(): void
    {
        $falso = (new BuzonFalso)->queFalla($this->frenado());
        $buzon = $this->conFreno($falso);

        $this->assertFrenado(fn () => $buzon->estado(), '5 minutos');
        $this->travel(6)->minutes();
        $this->assertFrenado(fn () => $buzon->estado(), '10 minutos');
        $this->travel(11)->minutes();
        $this->assertFrenado(fn () => $buzon->estado(), '20 minutos');

        $falso->sinFalla();
        $this->travel(21)->minutes();
        $buzon->estado();

        $falso->queFalla($this->frenado());
        $this->assertFrenado(fn () => $buzon->estado(), '5 minutos');
    }

    /** Las credenciales malas no frenan: hay que verlas cada vez, hasta que se corrijan. */
    public function test_las_credenciales_malas_no_ponen_espera(): void
    {
        $falso = (new BuzonFalso)->queFalla(new AutenticacionBuzonException('AUTHENTICATIONFAILED'));
        $buzon = $this->conFreno($falso);

        foreach ([1, 2] as $vez) {
            try {
                $buzon->adjuntosDeRemitente('pagos@cliente.example', now(), ['txt']);
                $this->fail('tenía que fallar');
            } catch (AutenticacionBuzonException) {
            }
        }
        $this->assertSame(2, $falso->llamadas());
    }

    private function assertFrenado(callable $llamada, ?string $espera = null): void
    {
        try {
            $llamada();
            $this->fail('tenía que avisar el freno');
        } catch (BuzonFrenadoException $e) {
            $this->assertStringContainsString('pidió esperar', $e->getMessage());
            if ($espera !== null) {
                $this->assertStringContainsString($espera, $e->getMessage());
            }
        }
    }
}
