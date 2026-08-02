<?php

namespace Tests\Feature\Planta;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Candados y superficie de las dos pantallas de consulta.
 *
 * Existencias e Historial son las únicas pantallas del módulo que NO tienen una
 * sola acción de escritura, y esa ausencia es lo que se prueba aquí: no basta
 * con que hoy no haya un botón, tiene que ser imposible mandarles un POST.
 *
 * Los permisos van separados —`existencias.ver` y `movimientos.ver`— porque
 * responden preguntas distintas y podrían repartirse entre personas distintas.
 * Producción tiene los dos.
 */
class PlantaConsultaAutorizacionTest extends TestCase
{
    use RecepcionPlantaFixtures;
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function pantallas(): array
    {
        return [
            'existencias' => ['planta.existencias.index'],
            'movimientos' => ['planta.movimientos.index'],
        ];
    }

    // --- Módulo apagado ---

    #[DataProvider('pantallas')]
    public function test_con_el_modulo_apagado_responde_404(string $ruta): void
    {
        config()->set('planta.enabled', false);

        // El administrador incluido: el flag apaga el área entera, no los permisos.
        $this->actingAs($this->admin())->get(route($ruta))->assertNotFound();
    }

    // --- Invitado ---

    #[DataProvider('pantallas')]
    public function test_un_invitado_va_al_login(string $ruta): void
    {
        $this->encenderModulo();

        $this->get(route($ruta))->assertRedirect(route('login'));
    }

    // --- Quien sí entra ---

    #[DataProvider('pantallas')]
    public function test_el_administrador_entra(string $ruta): void
    {
        $this->encenderModulo();

        $this->actingAs($this->admin())->get(route($ruta))->assertOk();
    }

    #[DataProvider('pantallas')]
    public function test_produccion_entra(string $ruta): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuarioConRol('produccion'))->get(route($ruta))->assertOk();
    }

    public function test_produccion_tiene_los_dos_permisos_de_consulta(): void
    {
        $produccion = $this->usuarioConRol('produccion');

        $this->assertTrue($produccion->can('planta.existencias.ver'));
        $this->assertTrue($produccion->can('planta.movimientos.ver'));
    }

    // --- Roles ajenos ---

    /** @return array<string, array{0: string, 1: string}> */
    public static function rolesAjenosPorPantalla(): array
    {
        $casos = [];

        foreach (['facturacion', 'contabilidad', 'jefatura'] as $rol) {
            foreach (self::pantallas() as $nombre => [$ruta]) {
                $casos["{$rol} en {$nombre}"] = [$rol, $ruta];
            }
        }

        return $casos;
    }

    #[DataProvider('rolesAjenosPorPantalla')]
    public function test_los_roles_ajenos_reciben_403(string $rol, string $ruta): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuarioConRol($rol))->get(route($ruta))->assertForbidden();
    }

    // --- Superficie: solo lectura ---

    /** @return array<string, array{0: string, 1: string}> */
    public static function verbosDeEscritura(): array
    {
        $casos = [];

        foreach (['post', 'put', 'patch', 'delete'] as $verbo) {
            foreach (self::pantallas() as $nombre => [$ruta]) {
                $casos["{$verbo} a {$nombre}"] = [$verbo, $ruta];
            }
        }

        return $casos;
    }

    /**
     * 405, no 403 ni 404: el verbo ni siquiera está registrado. La diferencia
     * importa porque un 403 significaría «existe, pero no puedes» y dejaría
     * abierta la puerta a que un permiso mal repartido lo habilitase. Un 405
     * significa que no hay nada que habilitar.
     *
     * `app.debug` se apaga a propósito: con él encendido, Laravel construye la
     * página de depuración con la traza completa para cada uno de estos ocho
     * casos, y eso multiplica por cuarenta lo que tarda la prueba sin cambiar en
     * nada lo que verifica. Lo que se comprueba es el código de estado.
     */
    #[DataProvider('verbosDeEscritura')]
    public function test_los_verbos_de_escritura_no_existen(string $verbo, string $ruta): void
    {
        $this->encenderModulo();
        config()->set('app.debug', false);

        $this->actingAs($this->admin())->$verbo(route($ruta))->assertMethodNotAllowed();
    }

    public function test_las_dos_pantallas_solo_registran_get(): void
    {
        $metodos = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array($r->getName(), ['planta.existencias.index', 'planta.movimientos.index'], true))
            ->flatMap(fn ($r) => $r->methods())
            ->unique()
            ->sort()
            ->values()
            ->all();

        // HEAD lo añade Laravel solo, junto a cada GET.
        $this->assertSame(['GET', 'HEAD'], $metodos);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function permisosPorPantalla(): array
    {
        return [
            'existencias' => ['planta.existencias.index', 'permission:planta.existencias.ver'],
            'movimientos' => ['planta.movimientos.index', 'permission:planta.movimientos.ver'],
        ];
    }

    /** Cada pantalla exige SU permiso, además del de entrada al área. */
    #[DataProvider('permisosPorPantalla')]
    public function test_cada_pantalla_exige_exactamente_sus_permisos(string $ruta, string $propio): void
    {
        $permisos = array_values(array_filter(
            app('router')->getRoutes()->getByName($ruta)->gatherMiddleware(),
            fn ($capa) => is_string($capa) && str_starts_with($capa, 'permission:')
        ));

        $this->assertEqualsCanonicalizing(['permission:planta.ver', $propio], $permisos);
    }

    // --- Navegación ---

    public function test_produccion_ve_los_dos_enlaces_en_la_sidebar(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuarioConRol('produccion'))
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertSee(route('planta.existencias.index'), false)
            ->assertSee(route('planta.movimientos.index'), false)
            ->assertSee('Existencias')
            ->assertSee('Movimientos');
    }

    /**
     * Sin el permiso, el enlace no se dibuja. Ocultar no autoriza —de eso se
     * ocupan las pruebas de 403 de más arriba—, pero un enlace que siempre
     * responde 403 es ruido que enseña puertas que no se pueden abrir.
     */
    public function test_quien_no_tiene_los_permisos_no_ve_los_enlaces(): void
    {
        $this->encenderModulo();

        $sinConsulta = User::factory()->create()->givePermissionTo(['planta.ver']);

        $this->actingAs($sinConsulta)
            ->get(route('planta.dashboard'))
            ->assertOk()
            ->assertDontSee(route('planta.existencias.index'), false)
            ->assertDontSee(route('planta.movimientos.index'), false);
    }
}
