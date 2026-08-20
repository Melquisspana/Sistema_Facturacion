<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\RepositorioAjustes;
use App\Facades\Ajustes;
use App\Models\Configuracion;
use App\Models\User;
use App\Support\Contabilidad\CorreoContabilidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * LA VENTANA ENTRE DESPLEGAR Y MIGRAR.
 *
 * Un despliegue normal es `git pull` y después `php artisan migrate`. Entre las
 * dos cosas pasan segundos o minutos en los que el CÓDIGO NUEVO corre contra el
 * ESQUEMA VIEJO, y en un servidor con Apache siempre encendido cada petición de
 * esa ventana entra por ahí.
 *
 * Este archivo prueba los dos órdenes posibles y exige lo mismo de los dos: que
 * la aplicación siga en pie y que NO se pierda ninguna configuración.
 *
 *   deploy → migrate : falta `ajustes_sistema`. Se lee de la tabla anterior.
 *   migrate → deploy : el esquema nuevo existe y el código viejo sigue leyendo
 *                      la tabla anterior, que la migración de datos ya vació.
 *
 * El segundo orden es el que se usa en la práctica (migrar es parte del
 * despliegue), pero el primero tiene que ser SEGURO igualmente: es lo que ocurre
 * durante el despliegue mismo.
 */
class VentanaDespliegueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('administrador', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Configuracion::olvidarCache();
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    /** Simula el esquema ANTERIOR: la tabla nueva todavía no existe. */
    private function sinTablaNueva(): void
    {
        Schema::drop('ajustes_sistema');
        app(RepositorioAjustes::class)->invalidar();
    }

    /** Deja la configuración donde estaba antes de la mudanza de datos. */
    private function configuracionEnLaTablaAnterior(): void
    {
        Configuracion::set('contabilidad.correo', 'conta@ejemplo.com');
        Configuracion::set('contabilidad.enviar_copia', true);
        Configuracion::set('correo.auto_envio', true);
        Configuracion::olvidarCache();
    }

    // ------------------------------------------- deploy ANTES que migrate

    /**
     * EL CASO PELIGROSO. Código nuevo, tabla nueva inexistente.
     *
     * Sin tolerancia a la tabla ausente, cada lectura de configuración lanzaría
     * una excepción de SQL y la aplicación entera devolvería 500 durante toda la
     * ventana del despliegue — no solo el Centro de Configuración: también el
     * envío de correo y el observer de DTE, que resuelven ajustes.
     */
    public function test_sin_la_tabla_nueva_la_configuracion_se_sigue_resolviendo(): void
    {
        $this->configuracionEnLaTablaAnterior();
        $this->sinTablaNueva();

        $this->assertSame('conta@ejemplo.com', Ajustes::texto('contabilidad.correo'));
        $this->assertTrue(Ajustes::bool('contabilidad.enviar_copia'));
        $this->assertTrue(Ajustes::bool('correo.auto_envio'));
    }

    /** Y los consumidores reales siguen funcionando, no solo el resolver. */
    public function test_sin_la_tabla_nueva_los_consumidores_siguen_funcionando(): void
    {
        $this->configuracionEnLaTablaAnterior();
        $this->sinTablaNueva();

        $contabilidad = app(CorreoContabilidad::class);

        $this->assertSame('conta@ejemplo.com', $contabilidad->direccion());
        $this->assertSame('conta@ejemplo.com', $contabilidad->copiaOculta());
    }

    /** Un ajuste sin fila en ninguna tabla cae a su fallback, no a una excepción. */
    public function test_sin_la_tabla_nueva_los_fallbacks_siguen_funcionando(): void
    {
        config(['backup_diario.dias_retencion' => 45, 'mail.mailers.smtp.host' => 'smtp.delenv.com']);
        $this->sinTablaNueva();

        $this->assertSame(45, Ajustes::entero('respaldos.dias_retencion'));
        $this->assertSame('smtp.delenv.com', Ajustes::texto('mail.smtp.host'));
    }

    /** Un secreto sin tabla se resuelve por config; no revienta ni se inventa. */
    public function test_sin_la_tabla_nueva_los_secretos_caen_a_config(): void
    {
        config(['mail.mailers.smtp.password' => 'clave-del-env']);
        $this->sinTablaNueva();

        $this->assertSame('clave-del-env', Ajustes::secretoParaRuntime('mail.smtp.password'));
    }

    /** Las pantallas del Centro de Configuración tampoco se caen. */
    public function test_sin_la_tabla_nueva_las_pantallas_responden(): void
    {
        $this->configuracionEnLaTablaAnterior();
        $admin = $this->admin();
        $this->sinTablaNueva();

        $this->actingAs($admin)->get(route('configuracion.resumen'))->assertOk();
        $this->actingAs($admin)->get(route('configuracion.correo.edit'))->assertOk();
        $this->actingAs($admin)->get(route('configuracion.contabilidad.edit'))->assertOk();
    }

    /** El comando de diagnóstico dice qué falta en vez de reventar. */
    public function test_el_diagnostico_avisa_de_la_tabla_ausente(): void
    {
        $this->sinTablaNueva();

        $this->artisan('ajustes:estado')
            ->expectsOutputToContain('ajustes_sistema AUSENTE')
            ->assertSuccessful();
    }

    // ------------------------------------------- migrate ANTES que deploy

    /**
     * El orden habitual. La migración de datos ya vació la tabla anterior, y lo
     * que quede de código viejo leyendo `Configuracion::` para estas claves
     * dejaría de verlas — por eso la fase 4 movió TODOS sus consumidores a
     * `Ajustes` antes de mudar los datos.
     */
    public function test_con_todo_migrado_la_configuracion_se_resuelve_igual(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        Ajustes::guardar('contabilidad.correo', 'conta@ejemplo.com');
        Ajustes::guardar('correo.auto_envio', true);

        $this->assertSame('conta@ejemplo.com', app(CorreoContabilidad::class)->direccion());
        $this->assertTrue(Ajustes::bool('correo.auto_envio'));
        $this->assertDatabaseMissing('configuraciones', ['clave' => 'contabilidad.correo']);
    }

    /**
     * Y la mudanza no puede perder nada: lo que había en la tabla anterior sigue
     * resolviéndose igual justo antes y justo después de correr la migración.
     */
    public function test_la_mudanza_no_cambia_ningun_valor_resuelto(): void
    {
        $this->configuracionEnLaTablaAnterior();

        $antes = [
            'correo' => Ajustes::texto('contabilidad.correo'),
            'copia' => Ajustes::bool('contabilidad.enviar_copia'),
            'auto' => Ajustes::bool('correo.auto_envio'),
        ];

        (require database_path('migrations/2026_08_20_120000_migrar_configuraciones_correo_a_ajustes.php'))->up();
        Configuracion::olvidarCache();
        app(RepositorioAjustes::class)->invalidar();

        $this->assertSame($antes['correo'], Ajustes::texto('contabilidad.correo'));
        $this->assertSame($antes['copia'], Ajustes::bool('contabilidad.enviar_copia'));
        $this->assertSame($antes['auto'], Ajustes::bool('correo.auto_envio'));
    }
}
