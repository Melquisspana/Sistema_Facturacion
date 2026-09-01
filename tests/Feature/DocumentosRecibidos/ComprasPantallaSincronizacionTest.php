<?php

namespace Tests\Feature\DocumentosRecibidos;

use App\Exceptions\DocumentosRecibidos\AutenticacionBuzonException;
use App\Exceptions\DocumentosRecibidos\BuzonInaccesibleException;
use App\Jobs\RecuperarPeriodoCompras;
use App\Models\DocumentoRecibido;
use App\Models\User;
use App\Services\DocumentosRecibidos\BitacoraSincronizacionCompras;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use App\Services\DocumentosRecibidos\ProgresoSincronizacionCompras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuzonFalso;
use Tests\TestCase;

/**
 * La pantalla de Compras: estado permanente y "Recuperar período".
 *
 * El cambio de fondo es que la sincronización dejó de ser una acción que hay que
 * acordarse de apretar y pasó a ser un estado que se puede mirar. Antes, el único rastro
 * de una revisión era un mensaje que desaparecía al recargar la página — y con la
 * sincronización corriendo sola, nadie está mirando cuando ocurre.
 */
class ComprasPantallaSincronizacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    private function conBuzon(?BuzonFalso $buzon = null): BuzonFalso
    {
        $buzon ??= new BuzonFalso;
        $this->app->instance(MailboxClient::class, $buzon);

        return $buzon;
    }

    // ---------------------------------------------------- estado visible

    public function test_sin_ninguna_sincronizacion_la_franja_avisa(): void
    {
        $this->conBuzon();

        $estado = $this->actingAs($this->admin())
            ->get(route('documentos-recibidos.index'))
            ->assertOk()
            ->assertSee('Todavía no hay ninguna sincronización registrada', false)
            ->viewData('estadoSync');

        $this->assertSame('ambar', $estado['color']);
        $this->assertNull($estado['ultimo_exito']);
    }

    public function test_con_todo_al_dia_la_franja_esta_en_verde(): void
    {
        $this->conBuzon();
        $progreso = app(ProgresoSincronizacionCompras::class);
        for ($d = now()->subMonthNoOverflow()->startOfMonth(); $d->lte(now()->startOfDay()); $d->addDay()) {
            $progreso->marcarCompleto($d->copy(), 'INBOX', 5001, null, []);
        }
        app(BitacoraSincronizacionCompras::class)->exito(['nuevos' => 0]);

        $estado = $this->actingAs($this->admin())
            ->get(route('documentos-recibidos.index'))
            ->assertOk()
            ->assertSee('Sincronización automática al día', false)
            ->viewData('estadoSync');

        $this->assertSame('verde', $estado['color']);
        $this->assertSame(0, $estado['dias_pendientes']);
    }

    /**
     * Si la última corrida falló, la franja se pone ROJA con el motivo. Es lo que
     * reemplaza al "0 correos revisados" en verde de un buzón con las credenciales
     * vencidas.
     */
    public function test_un_error_de_la_ultima_corrida_pone_la_franja_en_rojo(): void
    {
        $this->conBuzon();
        app(BitacoraSincronizacionCompras::class)->fallo('El buzón rechazó las credenciales: AUTHENTICATIONFAILED');

        $estado = $this->actingAs($this->admin())
            ->get(route('documentos-recibidos.index'))
            ->assertOk()
            ->assertSee('La última sincronización falló', false)
            ->assertSee('AUTHENTICATIONFAILED', false)
            ->viewData('estadoSync');

        $this->assertSame('rojo', $estado['color']);
    }

    /** Un scheduler apagado se delata solo: la última corrida buena queda vieja. */
    public function test_una_sincronizacion_vieja_pone_la_franja_en_ambar(): void
    {
        $this->conBuzon();
        $bitacora = app(BitacoraSincronizacionCompras::class);
        $this->travelTo(now()->subHours(6), fn () => $bitacora->exito(['nuevos' => 0]));

        $estado = $this->actingAs($this->admin())
            ->get(route('documentos-recibidos.index'))
            ->assertOk()
            ->assertSee('que no se sincroniza', false)
            ->viewData('estadoSync');

        $this->assertSame('ambar', $estado['color']);
    }

    /** Las compras sin fecha fiscal se anuncian: no entran en ningún paquete. */
    public function test_avisa_de_las_compras_sin_fecha_fiscal(): void
    {
        $this->conBuzon();
        DocumentoRecibido::create([
            'gmail_message_id' => 'sf1',
            'identidad' => 'mid:sf1@proveedor.example',
            'emisor_nombre' => 'PROVEEDOR SF',
            'estado' => 'pendiente',
            'tiene_pdf' => true,
            'tiene_json' => false,
            'fecha_correo' => now(),
            'fecha_dte' => null,
        ]);

        $this->actingAs($this->admin())
            ->get(route('documentos-recibidos.index', ['rango' => 'todos']))
            ->assertOk()
            ->assertSee('sin fecha de emisión legible', false)
            ->assertViewHas('sinFechaFiscal', 1);
    }

    // ---------------------------------------------------- botones

    public function test_ya_no_existe_el_boton_de_revisar_historico(): void
    {
        $this->conBuzon();

        $this->actingAs($this->admin())
            ->get(route('documentos-recibidos.index'))
            ->assertOk()
            ->assertSee('Revisar ahora', false)
            ->assertSee('Recuperar período', false)
            ->assertDontSee('Revisar histórico', false);
    }

    /** "Revisar ahora" corre la incremental y muestra el resultado. */
    public function test_revisar_ahora_sincroniza_y_reporta(): void
    {
        $this->conBuzon((new BuzonFalso)->conDte(1, now()->toDateTimeString(), 'COD-HOY'));

        $this->actingAs($this->admin())
            ->post(route('documentos-recibidos.sincronizar'))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'No se modificó ningún correo'));

        $this->assertSame(1, DocumentoRecibido::count());
    }

    /** Un fallo del buzón sale en ROJO, no como una revisión exitosa sin novedades. */
    public function test_revisar_ahora_con_el_buzon_caido_muestra_error(): void
    {
        $this->conBuzon((new BuzonFalso)->queFalla(new AutenticacionBuzonException('AUTHENTICATIONFAILED')));

        $this->actingAs($this->admin())
            ->post(route('documentos-recibidos.sincronizar'))
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Autenticación fallida'))
            ->assertSessionMissing('status');
    }

    // ---------------------------------------------------- recuperar período

    public function test_recuperar_periodo_encola_el_rango(): void
    {
        Queue::fake();
        $this->conBuzon();

        $this->actingAs($this->admin())
            ->post(route('documentos-recibidos.recuperar'), ['desde' => '2026-08-01', 'hasta' => '2026-08-31'])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_contains($m, '31 día(s)'));

        Queue::assertPushed(RecuperarPeriodoCompras::class, fn ($job) => $job->desde === '2026-08-01' && $job->hasta === '2026-08-31');
    }

    public function test_recuperar_periodo_exige_fechas_validas(): void
    {
        Queue::fake();
        $this->conBuzon();

        $this->actingAs($this->admin())
            ->post(route('documentos-recibidos.recuperar'), ['desde' => '2026-08-31', 'hasta' => '2026-08-01'])
            ->assertSessionHasErrors('hasta');

        $this->actingAs($this->admin())
            ->post(route('documentos-recibidos.recuperar'), ['desde' => 'ayer', 'hasta' => '2026-08-01'])
            ->assertSessionHasErrors('desde');

        Queue::assertNothingPushed();
    }

    /** Un rango absurdo casi siempre es una fecha mal tipeada; se para antes de encolar. */
    public function test_recuperar_periodo_rechaza_un_rango_mayor_a_un_año(): void
    {
        Queue::fake();
        $this->conBuzon();

        $this->actingAs($this->admin())
            ->post(route('documentos-recibidos.recuperar'), ['desde' => '2020-01-01', 'hasta' => '2026-08-31'])
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no puede pasar de un año'));

        Queue::assertNothingPushed();
    }

    public function test_recuperar_periodo_sin_buzon_configurado_avisa(): void
    {
        Queue::fake();
        $this->conBuzon(new BuzonFalso(disponible: false));

        $this->actingAs($this->admin())
            ->post(route('documentos-recibidos.recuperar'), ['desde' => '2026-08-01', 'hasta' => '2026-08-05'])
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no está configurado'));

        Queue::assertNothingPushed();
    }

    /** Recuperar es escritura: exige `documentos-recibidos.gestionar`. */
    public function test_recuperar_periodo_requiere_permiso_de_gestion(): void
    {
        Queue::fake();
        $this->conBuzon();
        $contable = User::factory()->create()->assignRole('contabilidad');

        $this->actingAs($contable)
            ->post(route('documentos-recibidos.recuperar'), ['desde' => '2026-08-01', 'hasta' => '2026-08-05'])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    /** El job invoca el mismo comando: un solo recorrido que mantener. */
    public function test_el_job_de_recuperacion_corre_el_comando_y_guarda(): void
    {
        $this->conBuzon((new BuzonFalso)->conDte(1, '2026-08-03 09:00:00', 'COD-JOB'));

        (new RecuperarPeriodoCompras('2026-08-01', '2026-08-05'))->handle();

        $this->assertSame(1, DocumentoRecibido::count());
        $this->assertSame(
            5,
            app(ProgresoSincronizacionCompras::class)
                ->cobertura(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'), 'INBOX')['dias_completos'],
        );
    }

    /**
     * UNA SOLA recuperación a la vez. El candado se toma al ENCOLAR, no al ejecutar: si
     * solo se tomara al ejecutar, apretar «Recuperar» tres veces dejaría tres trabajos en
     * cola que después se bloquean entre sí de a uno.
     */
    public function test_no_se_pueden_encolar_dos_recuperaciones_a_la_vez(): void
    {
        Queue::fake();
        $this->conBuzon();

        $this->actingAs($this->admin())
            ->post(route('documentos-recibidos.recuperar'), ['desde' => '2026-08-01', 'hasta' => '2026-08-10'])
            ->assertSessionHas('status');

        $this->actingAs($this->admin())
            ->post(route('documentos-recibidos.recuperar'), ['desde' => '2026-08-11', 'hasta' => '2026-08-20'])
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Ya hay una recuperación de compras en curso'));

        Queue::assertPushed(RecuperarPeriodoCompras::class, 1);
    }

    /** Cuando el trabajo termina, suelta el candado y se puede volver a recuperar. */
    public function test_al_terminar_la_recuperacion_se_puede_encolar_otra(): void
    {
        $this->conBuzon();

        // Cola `sync`: el trabajo corre en el momento y suelta el candado al terminar.
        $this->actingAs($this->admin())
            ->post(route('documentos-recibidos.recuperar'), ['desde' => '2026-08-01', 'hasta' => '2026-08-02'])
            ->assertSessionHas('status');

        $this->actingAs($this->admin())
            ->post(route('documentos-recibidos.recuperar'), ['desde' => '2026-08-03', 'hasta' => '2026-08-04'])
            ->assertSessionHas('status')
            ->assertSessionMissing('error');
    }

    /** Aunque el trabajo falle, el candado se suelta: si no, quedaría trabado para siempre. */
    public function test_una_recuperacion_fallida_libera_el_candado(): void
    {
        $this->conBuzon((new BuzonFalso)->queFalla(new BuzonInaccesibleException('se cayó la red')));

        $this->actingAs($this->admin())
            ->post(route('documentos-recibidos.recuperar'), ['desde' => '2026-08-01', 'hasta' => '2026-08-02'])
            ->assertSessionHas('status');

        $this->assertTrue(
            Cache::lock(RecuperarPeriodoCompras::LOCK, 10)->get(),
            'el candado tiene que quedar libre aunque la recuperación no haya funcionado',
        );
    }

    /**
     * Con una cola real, «encolada» significa que NO pasa nada hasta que un worker la
     * tome. Si el worker está caído, el usuario vería un mensaje verde y ningún documento
     * nuevo: justo la clase de silencio que este módulo vino a eliminar.
     */
    public function test_el_mensaje_avisa_que_hace_falta_el_worker_cuando_hay_cola_real(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        $this->conBuzon();

        $this->actingAs($this->admin())
            ->post(route('documentos-recibidos.recuperar'), ['desde' => '2026-08-01', 'hasta' => '2026-08-05'])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'worker de colas'));
    }

    /** Con la cola en `sync` no hay worker que esperar, y el mensaje lo dice. */
    public function test_el_mensaje_no_menciona_al_worker_si_la_cola_es_sincronica(): void
    {
        Queue::fake();
        config(['queue.default' => 'sync']);
        $this->conBuzon();

        $this->actingAs($this->admin())
            ->post(route('documentos-recibidos.recuperar'), ['desde' => '2026-08-01', 'hasta' => '2026-08-05'])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'en el momento') && ! str_contains($m, 'worker'));
    }
}
