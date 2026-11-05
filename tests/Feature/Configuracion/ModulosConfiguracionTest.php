<?php

namespace Tests\Feature\Configuracion;

use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\CatalogoAjustes;
use App\Ajustes\Definicion\NivelConfirmacion;
use App\Ajustes\RepositorioAjustes;
use App\Enums\AreaSistema;
use App\Enums\ModuloSistema;
use App\Facades\Ajustes;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Configuración › Módulos: prender y apagar Gastos, Producción, Asistencia y
 * Planilla desde la pantalla.
 *
 * Lo que se prueba es que el cambio tiene efecto REAL, no solo en el menú: la puerta
 * (middleware `modulo.*`) responde 404 con el módulo apagado aunque el .env diga lo
 * contrario, y al revés. Apagar pide confirmación N2, cada cambio queda auditado y,
 * sin la tabla de ajustes o sin base, se vuelve al valor del .env sin romper.
 */
class ModulosConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    /** Todos los módulos apagados en el .env, como en una instalación nueva. */
    private function envApagado(): void
    {
        config([
            'gastos.enabled' => false, 'planta.enabled' => false, 'asistencia.enabled' => false,
            'planilla.enabled' => false, 'planilla.pagos_enabled' => false,
        ]);
    }

    /** Lo que la pantalla manda: una casilla por módulo, marcadas las que se pasan. */
    private function formulario(array $encendidos, bool $confirmar = false): array
    {
        $datos = [];
        foreach ($encendidos as $modulo) {
            $datos[$modulo->value] = '1';
        }

        return $confirmar ? $datos + ['confirmacion' => '1'] : $datos;
    }

    /**
     * Cada módulo con la pantalla que protege su middleware.
     *
     * @return array<string, array{0: ModuloSistema, 1: string}>
     */
    public static function modulosConPuerta(): array
    {
        return [
            'gastos' => [ModuloSistema::Gastos, 'gastos.panel'],
            'producción' => [ModuloSistema::Planta, 'planta.dashboard'],
            'asistencia' => [ModuloSistema::Asistencia, 'asistencia.dashboard'],
            'planilla' => [ModuloSistema::Planilla, 'planilla.index'],
        ];
    }

    // ------------------------------------------------------------ catálogo

    public function test_los_cinco_modulos_estan_en_la_lista_blanca_como_n2_editables(): void
    {
        $catalogo = app(CatalogoAjustes::class);

        foreach (ModuloSistema::cases() as $modulo) {
            $definicion = $catalogo->definicion($modulo->clave());

            $this->assertSame('modulos', $definicion->seccion);
            $this->assertSame(NivelConfirmacion::N2, $definicion->nivel);
            $this->assertTrue($definicion->editabilidad->permiteEscritura());
            $this->assertSame($modulo->claveConfig(), $definicion->claveConfig);
            $this->assertTrue($definicion->valorAuditable(), 'El antes y el después de un módulo deben quedar en la auditoría.');
        }
    }

    // ------------------------------------------------------------ permisos

    public function test_sin_permiso_no_se_ve_ni_se_cambia(): void
    {
        $this->envApagado();
        $facturacion = User::factory()->create(['activo' => true])->assignRole('facturacion');

        $this->actingAs($facturacion)->get(route('configuracion.modulos'))->assertForbidden();
        $this->actingAs($facturacion)->put(route('configuracion.modulos.update'), $this->formulario([ModuloSistema::Gastos]))
            ->assertForbidden();

        $this->assertFalse(ModuloSistema::Gastos->activo());
        $this->assertSame(0, Activity::query()->where('log_name', 'ajustes')->count());
    }

    public function test_la_pantalla_lista_los_modulos_con_su_fuente(): void
    {
        $this->envApagado();

        $resp = $this->actingAs($this->admin())->get(route('configuracion.modulos'))->assertOk();

        foreach (ModuloSistema::cases() as $modulo) {
            $resp->assertSee($modulo->etiqueta())->assertSee($modulo->variableEntorno());
        }
        $resp->assertSee('Archivo de configuración / .env')
            ->assertSee('Guardar módulos')
            ->assertSee('los datos no se borran');
    }

    // ------------------------------------------------------------ efecto real

    /** Prender desde la pantalla abre la puerta aunque el .env lo tenga apagado. */
    #[DataProvider('modulosConPuerta')]
    public function test_prender_desde_la_pantalla_abre_la_puerta_aunque_el_env_diga_apagado(ModuloSistema $modulo, string $ruta): void
    {
        $this->envApagado();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route($ruta))->assertNotFound();

        // Prender no pide confirmación: guarda directo.
        $this->actingAs($admin)->put(route('configuracion.modulos.update'), $this->formulario([$modulo]))
            ->assertRedirect(route('configuracion.modulos'))
            ->assertSessionHas('status');

        $this->assertTrue($modulo->activo());
        $this->assertFalse((bool) config($modulo->claveConfig()), 'El .env no se tocó.');
        $this->actingAs($admin)->get(route($ruta))->assertOk();
    }

    /** Apagar desde la pantalla cierra la puerta y el menú aunque el .env diga encendido. */
    #[DataProvider('modulosConPuerta')]
    public function test_apagar_desde_la_pantalla_cierra_la_puerta_aunque_el_env_diga_encendido(ModuloSistema $modulo, string $ruta): void
    {
        $this->envApagado();
        config([$modulo->claveConfig() => true]);
        $admin = $this->admin();
        $this->actingAs($admin)->get(route($ruta))->assertOk();

        // Primer envío: confirmación, sin guardar nada.
        $this->actingAs($admin)->put(route('configuracion.modulos.update'), $this->formulario([]))
            ->assertOk()
            ->assertSee('Vas a apagar un módulo')
            ->assertSee($modulo->alApagar())
            ->assertSee('Los datos no se borran')
            ->assertSee('Confirmar y apagar');
        $this->assertTrue($modulo->activo(), 'Sin confirmar no se apaga nada.');
        $this->actingAs($admin)->get(route($ruta))->assertOk();

        // Segundo envío, confirmado.
        $this->actingAs($admin)->put(route('configuracion.modulos.update'), $this->formulario([], confirmar: true))
            ->assertRedirect(route('configuracion.modulos'));

        $this->assertFalse($modulo->activo());
        $this->assertTrue((bool) config($modulo->claveConfig()), 'El .env no se tocó.');
        $this->actingAs($admin)->get(route($ruta))->assertNotFound();
    }

    /** El menú obedece al mismo interruptor que la puerta: el área desaparece. */
    public function test_apagar_gastos_lo_saca_del_menu(): void
    {
        config(['gastos.enabled' => true]);
        $admin = $this->admin();

        $this->assertContains(AreaSistema::Gastos, AreaSistema::visiblesPara($admin));
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee(route('gastos.panel'), false);

        Ajustes::guardarComoSistema(ModuloSistema::Gastos->clave(), false);

        $this->assertNotContains(AreaSistema::Gastos, AreaSistema::visiblesPara($admin));
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertDontSee(route('gastos.panel'), false);
    }

    /** Y el programador de tareas también: con Gastos apagado no genera ni avisa. */
    public function test_apagar_gastos_detiene_sus_tareas_programadas(): void
    {
        config([
            'gastos.enabled' => true,
            'gastos.recurrencias.generacion_automatica' => true,
            'gastos.avisos.automaticos' => true,
        ]);

        $eventos = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'gastos:'));
        $this->assertCount(2, $eventos);
        $eventos->each(fn ($e) => $this->assertTrue($e->filtersPass($this->app)));

        Ajustes::guardarComoSistema(ModuloSistema::Gastos->clave(), false);

        $eventos->each(fn ($e) => $this->assertFalse($e->filtersPass($this->app)));
        $this->artisan('gastos:avisos')->assertFailed();
    }

    public function test_apagar_y_prender_en_el_mismo_envio_pide_confirmacion_y_lista_solo_lo_que_se_apaga(): void
    {
        $this->envApagado();
        config(['gastos.enabled' => true]);

        $this->actingAs($this->admin())
            ->put(route('configuracion.modulos.update'), $this->formulario([ModuloSistema::Planta]))
            ->assertOk()
            ->assertSee(ModuloSistema::Gastos->alApagar())
            ->assertDontSee(ModuloSistema::Planta->alApagar());

        $this->assertTrue(ModuloSistema::Gastos->activo());
        $this->assertFalse(ModuloSistema::Planta->activo(), 'Nada se guarda hasta confirmar.');
    }

    public function test_sin_cambios_no_escribe_nada(): void
    {
        $this->envApagado();

        $this->actingAs($this->admin())->put(route('configuracion.modulos.update'), $this->formulario([]))
            ->assertRedirect(route('configuracion.modulos'))
            ->assertSessionHas('status', 'No había nada que cambiar en los módulos.');

        $this->assertSame(0, Activity::query()->where('log_name', 'ajustes')->count());
        // Un módulo que no se tocó sigue obedeciendo al .env.
        $this->assertFalse(Ajustes::fuente(ModuloSistema::Gastos->clave())->esOverride());
    }

    public function test_solo_se_guarda_lo_que_cambia(): void
    {
        $this->envApagado();

        $this->actingAs($this->admin())->put(route('configuracion.modulos.update'), $this->formulario([ModuloSistema::Gastos]));

        $this->assertTrue(Ajustes::fuente(ModuloSistema::Gastos->clave())->esOverride());
        $this->assertFalse(Ajustes::fuente(ModuloSistema::Planta->clave())->esOverride(), 'Planta no cambió: sigue saliendo del .env.');
    }

    public function test_un_valor_que_no_es_si_o_no_se_rechaza(): void
    {
        $this->envApagado();

        $this->actingAs($this->admin())->from(route('configuracion.modulos'))
            ->put(route('configuracion.modulos.update'), ['gastos' => 'tal vez'])
            ->assertRedirect(route('configuracion.modulos'))
            ->assertSessionHasErrors('gastos');

        $this->assertFalse(ModuloSistema::Gastos->activo());
    }

    /** Un campo manipulado no puede elegir otro ajuste: las claves salen del enum. */
    public function test_un_campo_ajeno_no_escribe_otro_ajuste(): void
    {
        $this->envApagado();

        $this->actingAs($this->admin())->put(route('configuracion.modulos.update'), [
            'gastos' => '1',
            'ppq.gmail.enabled' => '1',
            'modulos.planta' => '1',
        ])->assertRedirect(route('configuracion.modulos'));

        $this->assertTrue(ModuloSistema::Gastos->activo());
        $this->assertFalse(ModuloSistema::Planta->activo());
        $this->assertFalse(Ajustes::fuente('ppq.gmail.enabled')->esOverride());
    }

    // ------------------------------------------------------------ auditoría

    public function test_cada_cambio_queda_en_la_auditoria_con_el_antes_y_el_despues(): void
    {
        $this->envApagado();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('configuracion.modulos.update'), $this->formulario([ModuloSistema::Gastos]));

        $actividad = Activity::query()->where('log_name', 'ajustes')->latest('id')->firstOrFail();
        $this->assertSame('modulos.gastos', $actividad->getExtraProperty('clave'));
        $this->assertSame($admin->id, $actividad->causer_id);
        $this->assertSame('No', $actividad->getExtraProperty('valor_antes'));
        $this->assertSame('Sí', $actividad->getExtraProperty('valor_despues'));
        $this->assertSame('configuracion', $actividad->getExtraProperty('fuente_antes'));
        $this->assertSame('base_de_datos', $actividad->getExtraProperty('fuente_despues'));
    }

    // ------------------------------------------------------------ arranque sin base

    /** Sin la tabla de ajustes (ventana entre desplegar y migrar) manda el .env. */
    public function test_sin_tabla_de_ajustes_cae_al_valor_del_env(): void
    {
        $admin = $this->admin();
        Schema::drop('ajustes_sistema');
        app(RepositorioAjustes::class)->invalidar();

        config(['gastos.enabled' => true]);
        $this->assertTrue(ModuloSistema::Gastos->activo());
        $this->actingAs($admin)->get(route('gastos.panel'))->assertOk();

        config(['gastos.enabled' => false]);
        $this->assertFalse(ModuloSistema::Gastos->activo());
        $this->actingAs($admin)->get(route('gastos.panel'))->assertNotFound();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    }

    /** Si la base no responde, el interruptor cae al .env en vez de romper. */
    public function test_si_la_base_no_responde_cae_al_valor_del_env(): void
    {
        $roto = \Mockery::mock(ServicioAjustes::class);
        $roto->shouldReceive('bool')->andThrow(new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));
        Ajustes::swap($roto);

        config(['planta.enabled' => true, 'planilla.pagos_enabled' => false]);

        $this->assertTrue(ModuloSistema::Planta->activo());
        $this->assertFalse(ModuloSistema::PlanillaPagos->activo());
    }

    /** Sin nada guardado, cada instalación sigue haciendo lo que dice su .env. */
    public function test_sin_nada_guardado_manda_el_env(): void
    {
        config(['asistencia.enabled' => true, 'planilla.pagos_enabled' => false]);

        $this->assertTrue(ModuloSistema::Asistencia->activo());
        $this->assertFalse(ModuloSistema::PlanillaPagos->activo());
        $this->assertSame('configuracion', Ajustes::fuente(ModuloSistema::Asistencia->clave())->value);
    }
}
