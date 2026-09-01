<?php

namespace Tests\Feature\DocumentosRecibidos;

use App\Console\Commands\ComprasSincronizarCommand;
use App\Exceptions\DocumentosRecibidos\AutenticacionBuzonException;
use App\Models\DocumentoRecibido;
use App\Models\DocumentoRecibidoProgreso;
use App\Services\DocumentosRecibidos\BitacoraSincronizacionCompras;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\DocumentosRecibidos\ProgresoSincronizacionCompras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuzonFalso;
use Tests\TestCase;

/**
 * El comando `compras:sincronizar`: dry-run, bloqueo por concurrencia y bitácora.
 *
 * Es el mismo recorrido que usa el scheduler y que usa el botón de la pantalla, así que
 * lo que se prueba acá es la envoltura: que no escriba sin `--aplicar`, que dos corridas
 * no se pisen, y que el resultado quede registrado para que alguien pueda mirarlo
 * después — porque cuando corre solo, nadie está mirando.
 */
class ComprasSincronizarCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function conBuzon(BuzonFalso $buzon): void
    {
        $this->app->instance(MailboxClient::class, $buzon);
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $this->conBuzon((new BuzonFalso)->conDte(1, '2026-08-05 09:00:00', 'COD-DRY'));

        $this->artisan('compras:sincronizar', ['--desde' => '2026-08-05', '--hasta' => '2026-08-05'])
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $this->assertSame(0, DocumentoRecibido::count());
        $this->assertSame(0, DocumentoRecibidoProgreso::count());
    }

    public function test_con_aplicar_guarda_y_deja_el_dia_completo(): void
    {
        $this->conBuzon((new BuzonFalso)->conDte(1, '2026-08-05 09:00:00', 'COD-OK'));

        $this->artisan('compras:sincronizar', [
            '--desde' => '2026-08-05', '--hasta' => '2026-08-05', '--aplicar' => true,
        ])->assertSuccessful();

        $this->assertSame(1, DocumentoRecibido::count());
        $this->assertTrue(
            DocumentoRecibidoProgreso::where('dia', '2026-08-05')->firstOrFail()->estaCompleto()
        );
    }

    /** Correrlo dos veces sobre el mismo rango deja exactamente el mismo resultado. */
    public function test_repetir_el_rango_no_duplica(): void
    {
        $this->conBuzon((new BuzonFalso)->conDte(1, '2026-08-05 09:00:00', 'COD-IDEM'));

        $args = ['--desde' => '2026-08-05', '--hasta' => '2026-08-05', '--aplicar' => true];
        $this->artisan('compras:sincronizar', $args)->assertSuccessful();
        $this->artisan('compras:sincronizar', $args)->assertSuccessful();

        $this->assertSame(1, DocumentoRecibido::count());
    }

    /**
     * CONCURRENCIA. Dos corridas sobre el mismo día se pisarían el cursor y podrían
     * saltear una página, así que la segunda no arranca.
     */
    public function test_no_arranca_si_ya_hay_una_corrida_en_curso(): void
    {
        $this->conBuzon((new BuzonFalso)->conDte(1, '2026-08-05 09:00:00', 'COD-LOCK'));

        // Otra corrida ya tomó el bloqueo (el worker, el scheduler, otra pestaña).
        $lock = Cache::lock(ComprasSincronizarCommand::LOCK, 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('compras:sincronizar', [
                '--desde' => '2026-08-05', '--hasta' => '2026-08-05', '--aplicar' => true,
            ])
                ->expectsOutputToContain('Ya hay una sincronización de compras en curso')
                ->assertFailed();

            $this->assertSame(0, DocumentoRecibido::count(), 'la segunda corrida no tocó nada');
        } finally {
            $lock->release();
        }
    }

    /** Y cuando la primera termina, el bloqueo queda libre para la siguiente. */
    public function test_el_bloqueo_se_libera_al_terminar(): void
    {
        $this->conBuzon((new BuzonFalso)->conDte(1, '2026-08-05 09:00:00', 'COD-LIB'));

        $this->artisan('compras:sincronizar', [
            '--desde' => '2026-08-05', '--hasta' => '2026-08-05', '--aplicar' => true,
        ])->assertSuccessful();

        $lock = Cache::lock(ComprasSincronizarCommand::LOCK, 60);
        $this->assertTrue($lock->get(), 'el bloqueo tiene que quedar libre');
        $lock->release();
    }

    /** Un fallo de autenticación sale como FALLO del comando, no como corrida vacía. */
    public function test_la_autenticacion_fallida_devuelve_codigo_de_error(): void
    {
        $this->conBuzon((new BuzonFalso)->queFalla(new AutenticacionBuzonException('AUTHENTICATIONFAILED')));

        $this->artisan('compras:sincronizar', [
            '--desde' => '2026-08-05', '--hasta' => '2026-08-05', '--aplicar' => true,
        ])
            ->expectsOutputToContain('AUTENTICACIÓN FALLIDA')
            ->assertFailed();

        $bitacora = app(BitacoraSincronizacionCompras::class);
        $this->assertNotNull($bitacora->ultimoError());
        $this->assertNull($bitacora->ultimoExito(), 'un fallo no puede quedar registrado como éxito');
    }

    /** La bitácora deja el rastro que la pantalla lee después. */
    public function test_registra_inicio_exito_y_conteos(): void
    {
        $this->conBuzon((new BuzonFalso)->conDte(1, '2026-08-05 09:00:00', 'COD-BIT'));

        $this->artisan('compras:sincronizar', [
            '--desde' => '2026-08-05', '--hasta' => '2026-08-05', '--aplicar' => true,
        ])->assertSuccessful();

        $bitacora = app(BitacoraSincronizacionCompras::class);
        $this->assertNotNull($bitacora->ultimoInicio());
        $this->assertNotNull($bitacora->ultimoExito());
        $this->assertNull($bitacora->ultimoError());
        $this->assertSame(1, $bitacora->ultimoResumen()['nuevos']);
    }

    /** Un éxito posterior borra el error viejo: ya no describe el estado actual. */
    public function test_un_exito_limpia_el_error_anterior(): void
    {
        $bitacora = app(BitacoraSincronizacionCompras::class);
        $bitacora->fallo('el buzón rechazó las credenciales');
        $this->assertNotNull($bitacora->ultimoError());

        $this->conBuzon((new BuzonFalso)->conDte(1, '2026-08-05 09:00:00', 'COD-REC'));
        $this->artisan('compras:sincronizar', [
            '--desde' => '2026-08-05', '--hasta' => '2026-08-05', '--aplicar' => true,
        ])->assertSuccessful();

        $this->assertNull($bitacora->ultimoError());
    }

    /** Sin fechas, la ventana sale de la marca de progreso menos el solape. */
    public function test_sin_fechas_arranca_en_la_marca_menos_el_solape(): void
    {
        $progreso = app(ProgresoSincronizacionCompras::class);
        foreach (['2026-08-01', '2026-08-02', '2026-08-03'] as $dia) {
            $progreso->marcarCompleto(Carbon::parse($dia), 'INBOX', 5001, null, []);
        }
        $this->conBuzon(new BuzonFalso);

        // Día siguiente al último completo (04) menos 2 de solape → 02.
        $this->artisan('compras:sincronizar', ['--hasta' => '2026-08-05', '--solape' => 2])
            ->expectsOutputToContain('Ventana: 2026-08-02 → 2026-08-05')
            ->assertSuccessful();
    }

    /** Sin progreso todavía, avisa que NO está cubriendo el histórico anterior. */
    public function test_sin_marca_avisa_que_no_cubre_el_historico(): void
    {
        $this->conBuzon(new BuzonFalso);

        $this->artisan('compras:sincronizar', ['--hasta' => '2026-08-05'])
            ->expectsOutputToContain('Todavía no hay progreso guardado')
            ->assertSuccessful();
    }

    /** Un rango invertido se rechaza en vez de recorrer cero días en silencio. */
    public function test_rechaza_un_rango_invertido(): void
    {
        $this->conBuzon(new BuzonFalso);

        $this->artisan('compras:sincronizar', ['--desde' => '2026-08-10', '--hasta' => '2026-08-05'])
            ->expectsOutputToContain('es posterior a --hasta')
            ->assertFailed();
    }

    /** Soltar los cursores toca el progreso guardado: exige confirmación explícita. */
    public function test_reiniciar_uid_validity_exige_aplicar(): void
    {
        $this->conBuzon(new BuzonFalso);

        $this->artisan('compras:sincronizar', ['--reiniciar-uid-validity' => true, '--hasta' => '2026-08-05'])
            ->expectsOutputToContain('hay que confirmarlo con --aplicar')
            ->assertFailed();
    }
}
