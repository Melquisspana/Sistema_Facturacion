<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\Sistema\PanelSistema;
use App\Facades\Ajustes;
use App\Models\RespaldoEjecucion;
use App\Models\User;
use App\Services\Sistema\DiagnosticoSistemaService;
use App\Support\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Configuración → Sistema.
 *
 * Lo que se fija: que la política de respaldos es lo ÚNICO editable, que la salud
 * no se recalcula (se reutiliza el servicio compartido), que el entorno es
 * estrictamente de lectura y no publica credenciales, y que el botón de respaldo
 * respeta su propio permiso —más estrecho que el del grupo—.
 */
class SistemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    /** Puede administrar configuración, pero NO ejecutar respaldos. */
    private function gestorSinRespaldos(): User
    {
        $rol = Role::findOrCreate('gestor_configuracion', 'web');
        $rol->syncPermissions([Permission::findOrCreate('configuracion.gestionar', 'web')]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return User::factory()->create(['activo' => true])->assignRole('gestor_configuracion');
    }

    // ------------------------------------------------------------- pantalla

    public function test_el_administrador_ve_las_cuatro_secciones(): void
    {
        $this->actingAs($this->admin())
            ->get(route('configuracion.sistema'))
            ->assertOk()
            ->assertSee('Respaldos')
            ->assertSee('Cola de trabajos')
            ->assertSee('Salud del sistema')
            ->assertSee('Entorno');
    }

    public function test_un_rol_sin_permiso_no_entra(): void
    {
        $usuario = User::factory()->create(['activo' => true])->assignRole('facturacion');

        $this->actingAs($usuario)->get(route('configuracion.sistema'))->assertForbidden();
        $this->actingAs($usuario)->put(route('configuracion.sistema.update'), ['retencion' => 30])->assertForbidden();
        $this->actingAs($usuario)->post(route('configuracion.sistema.respaldar'))->assertForbidden();
    }

    /** La pantalla es de diagnóstico: no puede salir a la red para pintarse. */
    public function test_no_hace_ninguna_llamada_de_red(): void
    {
        Http::fake();

        $this->actingAs($this->admin())->get(route('configuracion.sistema'))->assertOk();

        Http::assertNothingSent();
    }

    /** El entorno se muestra, pero nunca credenciales. */
    public function test_el_entorno_no_publica_credenciales(): void
    {
        config([
            'database.connections.mysql.password' => 'clave-de-la-base',
            'database.connections.mysql.username' => 'usuario-de-la-base',
        ]);

        $html = $this->actingAs($this->admin())->get(route('configuracion.sistema'))->assertOk()->getContent();

        $this->assertStringNotContainsString('clave-de-la-base', $html);
        $this->assertStringNotContainsString('usuario-de-la-base', $html);
        $this->assertStringNotContainsString((string) config('app.key'), $html);
    }

    public function test_el_entorno_informa_lo_esperado(): void
    {
        $etiquetas = array_column(app(PanelSistema::class)->entorno(), 'etiqueta');

        foreach (['Entorno', 'PHP', 'Laravel', 'Base de datos', 'Caché', 'Cola', 'Almacenamiento', 'Cloudflare Access'] as $esperada) {
            $this->assertContains($esperada, $etiquetas, "Falta «{$esperada}» en el entorno.");
        }
    }

    // ------------------------------------------------------------ respaldos

    public function test_la_politica_de_respaldos_es_editable_con_confirmacion(): void
    {
        $admin = $this->admin();

        // Primer envío: solo confirmación, no escribe.
        $this->actingAs($admin)
            ->put(route('configuracion.sistema.update'), ['retencion' => 90, 'avisos' => 'avisos@ejemplo.com'])
            ->assertOk()
            ->assertSee('Esto es lo que va a cambiar');

        $this->assertDatabaseMissing('ajustes_sistema', ['clave' => 'respaldos.dias_retencion']);

        // Segundo: escribe.
        $this->actingAs($admin)
            ->put(route('configuracion.sistema.update'), ['retencion' => 90, 'avisos' => 'avisos@ejemplo.com', 'confirmacion' => '1'])
            ->assertRedirect(route('configuracion.sistema'));

        $this->assertSame(90, Ajustes::entero('respaldos.dias_retencion'));
        $this->assertSame('avisos@ejemplo.com', Ajustes::texto('respaldos.notificaciones.correo'));
        $this->assertSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente('respaldos.dias_retencion'));
    }

    /** Bajar la retención borra respaldos: la confirmación tiene que decirlo. */
    public function test_la_confirmacion_explica_la_consecuencia(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.sistema.update'), ['retencion' => 7])
            ->assertOk()
            ->assertSee('BORRA respaldos existentes');
    }

    public function test_una_retencion_invalida_se_rechaza(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.sistema.update'), ['retencion' => 0, 'confirmacion' => '1'])
            ->assertSessionHasErrors('retencion');

        $this->actingAs($this->admin())
            ->put(route('configuracion.sistema.update'), ['retencion' => 30, 'avisos' => 'no-es-correo', 'confirmacion' => '1'])
            ->assertSessionHasErrors('avisos');
    }

    /** La evidencia sale del registro real, no de mirar archivos. */
    public function test_muestra_la_ultima_ejecucion_y_el_ultimo_valido(): void
    {
        RespaldoEjecucion::create([
            'iniciado_en' => now()->subDays(2), 'terminado_en' => now()->subDays(2),
            'exitoso' => true, 'archivo_ruta' => 'backups/viejo.sql',
            'archivo_tamano_bytes' => 5 * 1048576, 'origen' => 'automatico',
        ]);
        RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(),
            'exitoso' => false, 'mensaje' => 'mysqldump falló', 'origen' => 'manual',
        ]);

        $respaldos = app(PanelSistema::class)->respaldos();

        $this->assertFalse($respaldos['ultima']->exitoso, 'La última ejecución es la que falló.');
        $this->assertTrue($respaldos['ultima_valida']->exitoso, 'El último válido es el de hace dos días.');
        $this->assertSame('5.0 MB', $respaldos['tamano']);
        $this->assertFalse($respaldos['hay_valido_hoy']);
    }

    /** Sin ruta registrada no se afirma que el archivo esté ni que falte. */
    public function test_sin_ruta_no_se_afirma_nada_del_archivo(): void
    {
        RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true, 'origen' => 'manual',
        ]);

        $this->assertNull(app(PanelSistema::class)->respaldos()['archivo_presente']);
    }

    // -------------------------------------------------------------- permiso

    /** El botón de respaldo tiene su propio permiso, más estrecho que el del grupo. */
    public function test_quien_no_puede_respaldar_no_ve_el_boton(): void
    {
        $this->actingAs($this->gestorSinRespaldos())
            ->get(route('configuracion.sistema'))
            ->assertOk()
            ->assertDontSee('Generar respaldo ahora');
    }

    public function test_quien_no_puede_respaldar_recibe_403(): void
    {
        $this->actingAs($this->gestorSinRespaldos())
            ->post(route('configuracion.sistema.respaldar'))
            ->assertForbidden();
    }

    public function test_el_administrador_si_ve_el_boton(): void
    {
        $this->actingAs($this->admin())
            ->get(route('configuracion.sistema'))
            ->assertOk()
            ->assertSee('Generar respaldo ahora');
    }

    /**
     * El botón reutiliza una operación que YA EXISTÍA; no se inventó ninguna.
     *
     * No se ejecuta el comando de verdad en la suite: volcaría una base. Lo que se
     * comprueba es que el comando al que llama el controlador está registrado
     * —o sea, que es el mismo que ya usaba «Preparar emisión real»— y que la
     * acción devuelve al usuario a la pantalla con un mensaje, salga como salga el
     * volcado en la máquina donde corra.
     */
    public function test_respaldar_usa_una_operacion_que_ya_existia(): void
    {
        $this->assertArrayHasKey(
            'backup:mysql-diario',
            Artisan::all(),
            'El botón debe apoyarse en el comando de respaldo existente.',
        );
    }

    public function test_respaldar_devuelve_a_la_pantalla_con_un_mensaje(): void
    {
        $respuesta = $this->actingAs($this->admin())->post(route('configuracion.sistema.respaldar'));

        $respuesta->assertRedirect(route('configuracion.sistema'));

        // Éxito o error según pueda o no volcar la base esta máquina; lo que no
        // puede pasar es que la acción termine sin decir nada.
        $this->assertTrue(
            session()->has('status') || session()->has('error'),
            'La acción debe informar su desenlace.',
        );
    }

    // --------------------------------------------------------------- salud

    /** No se recalcula: es exactamente lo que devuelve el servicio compartido. */
    public function test_la_salud_se_reutiliza_del_servicio_compartido(): void
    {
        $delServicio = app(DiagnosticoSistemaService::class)->evaluar();
        $delPanel = app(PanelSistema::class)->salud();

        $this->assertSame(
            array_column($delServicio['checks'], 'clave'),
            array_column($delPanel['checks'], 'clave'),
            'El panel debe mostrar los MISMOS controles que el resto del sistema.',
        );
    }

    // ---------------------------------------------------------------- cola

    public function test_la_cola_informa_conexion_pendientes_y_fallidos(): void
    {
        $cola = app(PanelSistema::class)->cola();

        $this->assertSame((string) config('queue.default'), $cola['conexion']);
        $this->assertIsInt($cola['pendientes']);
        $this->assertIsInt($cola['fallidos']);
        $this->assertNotEmpty($cola['mensaje']);
    }

    /** Sin latido no se dice "apagado": se dice que no se puede confirmar. */
    public function test_sin_evidencia_del_worker_no_se_inventa_el_estado(): void
    {
        WorkerHeartbeat::olvidar();

        $cola = app(PanelSistema::class)->cola();

        $this->assertSame('sin_datos', $cola['estado']);
        $this->assertNull($cola['ultimo_pulso']);
    }
}
