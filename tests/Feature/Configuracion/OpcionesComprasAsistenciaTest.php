<?php

namespace Tests\Feature\Configuracion;

use App\Ajustes\CatalogoAjustes;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\Definicion\NivelConfirmacion;
use App\Ajustes\Excepciones\ValorAjusteInvalidoException;
use App\Facades\Ajustes;
use App\Http\Controllers\Configuracion\OpcionesModuloController;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Etapa 4 de Configuración: dos opciones que solo se cambiaban en el .env del servidor
 * pasan a Configuración › Módulos (Compras y Asistencia). Para cada una: sin nada
 * guardado manda el .env; con algo guardado lo usan sus lectores reales (la tarea
 * programada de compras; el lector de huella en tests/Feature/Asistencia/AjustesLectorTest);
 * lo fuera de rango se rechaza en el servidor; solo quien tiene `configuracion.gestionar`
 * lo cambia; y queda en la auditoría. Datos ficticios.
 */
class OpcionesComprasAsistenciaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function campo(string $clave): string
    {
        return OpcionesModuloController::campo($clave);
    }

    public function test_las_opciones_estan_en_el_catalogo_con_su_nivel(): void
    {
        $catalogo = app(CatalogoAjustes::class);

        $this->assertSame(NivelConfirmacion::N2, $catalogo->definicion('documentos_recibidos.sincronizacion_automatica')->nivel);
        $this->assertSame(NivelConfirmacion::N1, $catalogo->definicion('asistencia.cooldown_segundos')->nivel);
        $this->assertSame(NivelConfirmacion::N2, $catalogo->definicion('asistencia.diferidas_max_dias')->nivel);
    }

    // ---------------------------------------------------------------- Compras

    public function test_la_tarea_de_compras_obedece_al_interruptor_guardado(): void
    {
        $evento = fn () => collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'compras:sincronizar'));

        // Sin nada guardado, el .env de siempre.
        config(['documentos_recibidos.sincronizacion_automatica' => false]);
        $this->assertFalse($evento()->filtersPass($this->app));
        config(['documentos_recibidos.sincronizacion_automatica' => true]);
        $this->assertTrue($evento()->filtersPass($this->app));

        // Guardado apagado gana al .env encendido, y al revés.
        Ajustes::guardarComoSistema('documentos_recibidos.sincronizacion_automatica', false);
        $this->assertFalse($evento()->filtersPass($this->app));

        config(['documentos_recibidos.sincronizacion_automatica' => false]);
        Ajustes::guardarComoSistema('documentos_recibidos.sincronizacion_automatica', true);
        $this->assertTrue($evento()->filtersPass($this->app));
    }

    public function test_la_pagina_de_compras_pide_confirmacion_y_audita(): void
    {
        config(['documentos_recibidos.sincronizacion_automatica' => false]);
        $admin = $this->admin();
        $campo = $this->campo('documentos_recibidos.sincronizacion_automatica');

        $this->actingAs($admin)->get(route('configuracion.opciones.compras'))->assertOk()
            ->assertSee('Bajar las compras del correo solo')
            ->assertSee('Archivo de configuración / .env');

        $this->actingAs($admin)->put(route('configuracion.opciones.compras.update'), [$campo => '1'])
            ->assertOk()->assertSee('Esto es lo que va a cambiar');
        $this->assertDatabaseCount('ajustes_sistema', 0);

        $this->actingAs($admin)->put(route('configuracion.opciones.compras.update'), [$campo => '1', 'confirmacion' => '1'])
            ->assertRedirect(route('configuracion.opciones.compras'));

        $this->assertTrue(Ajustes::bool('documentos_recibidos.sincronizacion_automatica'));
        $this->assertSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente('documentos_recibidos.sincronizacion_automatica'));
        $this->assertSame('documentos_recibidos.sincronizacion_automatica',
            Activity::query()->where('log_name', 'ajustes')->sole()->properties['clave']);
    }

    // ------------------------------------------------------------- Asistencia

    public function test_la_pagina_de_asistencia_sigue_al_modulo(): void
    {
        $admin = $this->admin();

        config(['asistencia.enabled' => false]);
        $this->actingAs($admin)->get(route('configuracion.opciones.asistencia'))->assertNotFound();
        $this->actingAs($admin)->put(route('configuracion.opciones.asistencia.update'), [
            $this->campo('asistencia.cooldown_segundos') => '30',
        ])->assertNotFound();
        $this->assertDatabaseCount('ajustes_sistema', 0);

        config(['asistencia.enabled' => true, 'asistencia.cooldown_segundos' => 90]);
        $this->actingAs($admin)->get(route('configuracion.opciones.asistencia'))->assertOk()
            ->assertSee('Segundos de espera entre dos marcas')
            ->assertSee('Días hacia atrás que se aceptan marcas')
            ->assertSee('value="90"', false);
    }

    public function test_asistencia_rechaza_valores_fuera_de_rango(): void
    {
        config(['asistencia.enabled' => true]);
        $admin = $this->admin();

        foreach ([
            ['asistencia.cooldown_segundos', '-1'],
            ['asistencia.cooldown_segundos', '601'],
            ['asistencia.cooldown_segundos', 'noventa'],
            ['asistencia.diferidas_max_dias', '61'],
            ['asistencia.diferidas_max_dias', '-3'],
            ['asistencia.diferidas_max_dias', '2.5'],
        ] as [$clave, $valor]) {
            $this->actingAs($admin)->from(route('configuracion.opciones.asistencia'))
                ->put(route('configuracion.opciones.asistencia.update'), [$this->campo($clave) => $valor, 'confirmacion' => '1'])
                ->assertSessionHasErrors($this->campo($clave));
        }

        $this->assertDatabaseCount('ajustes_sistema', 0);

        // También la segunda barrera (el catálogo), por fuera del formulario.
        $this->expectException(ValorAjusteInvalidoException::class);
        Ajustes::guardarComoSistema('asistencia.diferidas_max_dias', 61);
    }

    public function test_un_n1_de_asistencia_se_guarda_directo_y_el_n2_pide_confirmacion(): void
    {
        config(['asistencia.enabled' => true, 'asistencia.cooldown_segundos' => 90, 'asistencia.diferidas_max_dias' => 31]);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('configuracion.opciones.asistencia.update'), [
            $this->campo('asistencia.cooldown_segundos') => '45',
        ])->assertRedirect(route('configuracion.opciones.asistencia'));
        $this->assertSame(45, Ajustes::entero('asistencia.cooldown_segundos'));

        $this->actingAs($admin)->put(route('configuracion.opciones.asistencia.update'), [
            $this->campo('asistencia.diferidas_max_dias') => '7',
        ])->assertOk()->assertSee('Esto es lo que va a cambiar')->assertSee('31');
        $this->assertSame(FuenteAjuste::Configuracion, Ajustes::fuente('asistencia.diferidas_max_dias'));

        $this->actingAs($admin)->put(route('configuracion.opciones.asistencia.update'), [
            $this->campo('asistencia.diferidas_max_dias') => '7', 'confirmacion' => '1',
        ])->assertRedirect(route('configuracion.opciones.asistencia'));
        $this->assertSame(7, Ajustes::entero('asistencia.diferidas_max_dias'));

        $claves = Activity::query()->where('log_name', 'ajustes')->get()->map(fn ($a) => $a->properties['clave'])->all();
        $this->assertSame(['asistencia.cooldown_segundos', 'asistencia.diferidas_max_dias'], $claves);
    }

    /** El lector llama a la API muchas veces: leer el ajuste ya leído no consulta la base. */
    public function test_leer_el_ajuste_del_lector_no_agrega_consultas(): void
    {
        Ajustes::guardarComoSistema('asistencia.cooldown_segundos', 30);
        Ajustes::entero('asistencia.cooldown_segundos');

        DB::enableQueryLog();
        Ajustes::entero('asistencia.cooldown_segundos');
        Ajustes::entero('asistencia.diferidas_max_dias');
        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $consultas);
    }

    public function test_sin_permiso_no_se_cambian(): void
    {
        config(['asistencia.enabled' => true]);
        $facturacion = User::factory()->create(['activo' => true])->assignRole('facturacion');

        foreach ([
            ['configuracion.opciones.compras', 'documentos_recibidos.sincronizacion_automatica', '1'],
            ['configuracion.opciones.asistencia', 'asistencia.cooldown_segundos', '10'],
        ] as [$ruta, $clave, $valor]) {
            $this->actingAs($facturacion)->get(route($ruta))->assertForbidden();
            $this->actingAs($facturacion)->put(route($ruta.'.update'), [$this->campo($clave) => $valor, 'confirmacion' => '1'])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('ajustes_sistema', 0);
    }
}
