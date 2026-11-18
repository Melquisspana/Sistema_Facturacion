<?php

namespace Tests\Feature\Personal;

use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\BajaEmpleado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Personal › Empleados y Personal › Accesos abren solo con los activos (18/11/2026): quien
 * se dio de baja no sigue saliendo en la lista. No se borra nada: se ve con «Inactivos» o
 * «Todos», y su ficha sigue igual.
 */
class ListasSoloActivosTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE = 'Prueba#Segura2026';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['asistencia.enabled' => true, 'planilla.enabled' => true, 'gastos.enabled' => false]);

        $this->admin = $this->usuario(PermisoSistema::todos());
    }

    /** @param  array<int, string>  $permisos */
    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function persona(string $apellidos, string $dui, array $cambios = []): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->admin, array_replace([
            'nombres' => 'Empleado', 'apellidos' => $apellidos, 'documento_tipo' => 'pasaporte', 'dui' => $dui,
            'con_descuentos_ley' => false, 'alta_el' => '2026-01-01', 'marca' => true, 'sale_rutas' => false,
        ], $cambios));
    }

    /** Alguien con acceso, huella y ficha de Rutas, dado de baja desde su ficha. */
    private function deBaja(): PlanillaEmpleado
    {
        $e = $this->persona('Baja De Prueba', 'FICTICIO-BAJA', [
            'usa_sistema' => true, 'sale_rutas' => true, 'rol' => 'vendedor',
            'email' => 'sefue@example.test', 'password' => self::CLAVE, 'password_confirmation' => self::CLAVE,
        ]);
        app(BajaEmpleado::class)->dar($this->admin, $e, '2026-11-02', 'otro');

        return $e->fresh();
    }

    /** Que la página tenga un enlace con ese texto a esa dirección. */
    private function assertEnlace(TestResponse $respuesta, string $texto, string $url): void
    {
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(e($url), '#').'"[^>]*>\s*'.preg_quote($texto, '#').'\s*</a>#u',
            $respuesta->getContent(),
            "Falta el enlace «{$texto}» a {$url}."
        );
    }

    // ─────────────────────────────── Empleados ───────────────────────────────

    public function test_empleados_abre_sin_quien_se_dio_de_baja_y_lo_muestra_con_inactivos_y_todos(): void
    {
        $this->persona('Activo De Prueba', 'FICTICIO-1');
        $seFue = $this->deBaja();
        $ficha = route('personal.empleados.show', 'p'.$seFue->id);

        $this->actingAs($this->admin)->get(route('personal.empleados'))->assertOk()
            ->assertSee('Empleado Activo De Prueba')->assertDontSee('Empleado Baja De Prueba')
            ->assertSee('value="1" selected>Activos</option>', false)
            ->assertSee('>Inactivos</option>', false)
            ->assertSee('>Todos</option>', false);

        $this->get(route('personal.empleados', ['activo' => '0']))->assertOk()
            ->assertSee('Empleado Baja De Prueba')->assertDontSee('Empleado Activo De Prueba')
            ->assertSee($ficha, false)->assertSee('value="0" selected>Inactivos</option>', false);

        $this->get(route('personal.empleados', ['activo' => 'todos']))->assertOk()
            ->assertSee('Empleado Baja De Prueba')->assertSee('Empleado Activo De Prueba');

        // Nada se borra: su ficha y su historial siguen ahí.
        $this->get($ficha)->assertOk()->assertSee('Empleado Baja De Prueba')->assertSee('Reactivar');
    }

    public function test_empleados_la_busqueda_se_combina_con_el_estado(): void
    {
        $this->persona('Activo De Prueba', 'FICTICIO-1');
        $this->deBaja();

        $vacia = $this->actingAs($this->admin)->get(route('personal.empleados', ['q' => 'baja de prueba']))->assertOk()
            ->assertDontSee('Empleado Baja De Prueba')->assertSee('Nada coincide con estos filtros.');
        // Quien se busca está de baja: la lista vacía lo dice con «Ver inactivos», con la misma búsqueda.
        $this->assertEnlace($vacia, 'Ver inactivos', route('personal.empleados', ['q' => 'baja de prueba', 'activo' => '0']));
        $this->assertEnlace($vacia, 'Limpiar filtros', route('personal.empleados'));
        // Si tampoco hay nadie de baja que coincida, no lo ofrece.
        $this->get(route('personal.empleados', ['q' => 'nadie']))->assertOk()
            ->assertSee('Nada coincide con estos filtros.')->assertDontSee('Ver inactivos');
        $this->get(route('personal.empleados', ['q' => 'baja de prueba', 'activo' => '0']))->assertOk()
            ->assertSee('Empleado Baja De Prueba');
        $this->get(route('personal.empleados', ['q' => 'de prueba', 'activo' => 'todos']))->assertOk()
            ->assertSee('Empleado Baja De Prueba')->assertSee('Empleado Activo De Prueba');
    }

    /** Con nadie cargado lo dice así; si solo hay gente de baja, lo que falta es el filtro. */
    public function test_empleados_sin_activos_no_dice_que_no_hay_nadie(): void
    {
        $this->actingAs($this->admin)->get(route('personal.empleados'))->assertOk()
            ->assertSee('Todavía no hay personas dadas de alta.');

        $this->deBaja();

        $soloBajas = $this->get(route('personal.empleados'))->assertOk()
            ->assertDontSee('Todavía no hay personas dadas de alta.')->assertSee('Nada coincide con estos filtros.')
            // No hay búsqueda que limpiar: lo que esconde a la gente es «Activos».
            ->assertDontSee('Limpiar filtros');
        $this->assertEnlace($soloBajas, 'Ver inactivos', route('personal.empleados', ['activo' => '0']));
    }

    public function test_empleados_la_paginacion_conserva_el_estado(): void
    {
        foreach (range(1, 26) as $n) {
            AsistenciaEmpleado::factory()->inactivo()->create(['nombres' => 'Inactivo', 'apellidos' => sprintf('Prueba %02d', $n)]);
        }

        $this->actingAs($this->admin)->get(route('personal.empleados', ['activo' => '0']))->assertOk()
            ->assertSee('Inactivo Prueba 01')->assertDontSee('Inactivo Prueba 26')
            ->assertSee(route('personal.empleados', ['activo' => '0', 'page' => 2]));
        $this->get(route('personal.empleados', ['activo' => '0', 'page' => 2]))->assertOk()
            ->assertSee('Inactivo Prueba 26')->assertDontSee('Inactivo Prueba 01');

        // Por defecto no hay nadie activo: tampoco hay página 2.
        $this->get(route('personal.empleados'))->assertOk()
            ->assertDontSee('Inactivo Prueba 01')->assertDontSee(route('personal.empleados', ['page' => 2]));
    }

    /** «Bajo el mínimo» cuenta solo a los activos, mire uno los activos o los inactivos. */
    public function test_empleados_bajo_el_minimo_no_cambia_con_el_estado(): void
    {
        $bajo = ['con_descuentos_ley' => false, 'sueldo_mensual' => '300.00', 'confirmar_bajo_minimo' => true];
        $this->persona('Activo De Prueba', 'FICTICIO-1', $bajo);
        app(BajaEmpleado::class)->dar($this->admin, $this->persona('Baja De Prueba', 'FICTICIO-2', $bajo), '2026-11-02', 'otro');

        foreach (['1', '0', 'todos'] as $activo) {
            $this->actingAs($this->admin)->get(route('personal.empleados', ['activo' => $activo]))->assertOk()
                ->assertSee('Una persona queda')->assertDontSee('2 personas quedan');
        }
    }

    // ─────────────────────────────── Accesos ───────────────────────────────

    public function test_accesos_abre_sin_la_cuenta_de_quien_se_fue_y_la_muestra_con_inactivos_y_todos(): void
    {
        User::factory()->create(['name' => 'Cuenta activa de prueba', 'email' => 'activa@example.test', 'activo' => true]);
        $seFue = $this->deBaja();
        $this->assertFalse($seFue->usuario->activo);

        $this->actingAs($this->admin)->get(route('personal.accesos.index'))->assertOk()
            ->assertSee('activa@example.test')->assertDontSee('sefue@example.test')
            ->assertSee('value="1" selected>Activos</option>', false)
            ->assertSee('>Inactivos</option>', false)
            ->assertSee('>Todos</option>', false);

        $this->get(route('personal.accesos.index', ['activo' => '0']))->assertOk()
            ->assertSee('sefue@example.test')->assertDontSee('activa@example.test')
            ->assertSee(route('personal.empleados.show', 'p'.$seFue->id), false);

        $this->get(route('personal.accesos.index', ['activo' => 'todos']))->assertOk()
            ->assertSee('sefue@example.test')->assertSee('activa@example.test');
    }

    public function test_accesos_el_estado_se_combina_con_busqueda_sin_ficha_y_externas(): void
    {
        foreach (['suelta' => true, 'suelta-inactiva' => false] as $correo => $activo) {
            User::factory()->create(['email' => $correo.'@example.test', 'activo' => $activo, 'cuenta_externa' => false]);
        }
        foreach (['externa' => true, 'externa-inactiva' => false] as $correo => $activo) {
            User::factory()->create(['email' => $correo.'@example.test', 'activo' => $activo, 'cuenta_externa' => true]);
        }
        $this->actingAs($this->admin);

        $this->get(route('personal.accesos.index', ['sin_ficha' => 1]))->assertOk()
            ->assertSee('suelta@example.test')->assertDontSee('suelta-inactiva@example.test')->assertDontSee('externa@example.test');
        $this->get(route('personal.accesos.index', ['sin_ficha' => 1, 'activo' => '0']))->assertOk()
            ->assertSee('suelta-inactiva@example.test')->assertDontSee('suelta@example.test')->assertDontSee('externa-inactiva@example.test');

        // El formulario lleva la vista puesta: elegir el estado no la pierde.
        $this->get(route('personal.accesos.index', ['externas' => 1]))->assertOk()
            ->assertSee('externa@example.test')->assertDontSee('externa-inactiva@example.test')
            ->assertSee('name="externas" value="1"', false);
        $this->get(route('personal.accesos.index', ['externas' => 1, 'activo' => '0']))->assertOk()
            ->assertSee('externa-inactiva@example.test')->assertDontSee('externa@example.test')->assertDontSee('suelta-inactiva@example.test');

        $this->get(route('personal.accesos.index', ['q' => 'inactiva', 'activo' => 'todos']))->assertOk()
            ->assertSee('suelta-inactiva@example.test')->assertSee('externa-inactiva@example.test')
            ->assertDontSee('suelta@example.test')->assertDontSee('externa@example.test');
        $vacia = $this->get(route('personal.accesos.index', ['q' => 'inactiva']))->assertOk()
            ->assertSee('Nada coincide con estos filtros.')->assertDontSee('suelta-inactiva@example.test');
        $this->assertEnlace($vacia, 'Ver inactivos', route('personal.accesos.index', ['q' => 'inactiva', 'activo' => '0']));
        $this->assertEnlace($vacia, 'Limpiar filtros', route('personal.accesos.index'));
        // «Ver inactivos» y «Limpiar filtros» no sacan de la vista puesta.
        $sinFicha = $this->get(route('personal.accesos.index', ['sin_ficha' => 1, 'q' => 'suelta-inactiva']))->assertOk()
            ->assertSee('Nada coincide con estos filtros.');
        $this->assertEnlace($sinFicha, 'Ver inactivos', route('personal.accesos.index', ['sin_ficha' => 1, 'q' => 'suelta-inactiva', 'activo' => '0']));
        $this->assertEnlace($sinFicha, 'Limpiar filtros', route('personal.accesos.index', ['sin_ficha' => 1]));
        $this->get(route('personal.accesos.index', ['externas' => 1, 'activo' => '0', 'q' => 'nadie']))->assertOk()
            ->assertSee('Nada coincide con estos filtros.');
    }

    /**
     * Con una cuenta externa apagada no dice «Crear el primero»: crearla otra vez choca con su
     * correo o duplica a la persona. Ofrece verla con «Inactivos», donde se reactiva.
     */
    public function test_accesos_externos_con_solo_una_apagada_ofrece_verla_y_no_crear_otra(): void
    {
        $externas = route('personal.accesos.index', ['externas' => 1]);

        $vacia = $this->actingAs($this->admin)->get($externas)->assertOk()
            ->assertSee('Todavía no hay accesos externos.')->assertDontSee('Ver inactivos');
        $this->assertEnlace($vacia, 'Crear el primero', route('personal.accesos.create'));

        User::factory()->create(['email' => 'externa-inactiva@example.test', 'activo' => false, 'cuenta_externa' => true]);

        $apagada = $this->get($externas)->assertOk()
            ->assertDontSee('Todavía no hay accesos externos')->assertDontSee('Crear el primero')
            ->assertSee('Nada coincide con estos filtros.');
        $this->assertEnlace($apagada, 'Ver inactivos', route('personal.accesos.index', ['externas' => 1, 'activo' => '0']));
        $this->get(route('personal.accesos.index', ['externas' => 1, 'activo' => '0']))->assertOk()
            ->assertSee('externa-inactiva@example.test');
    }

    /** El aviso dice «siguen entrando» y «Ver solo esos» abre en Activos: cuenta solo a esos. */
    public function test_accesos_el_aviso_sin_ficha_cuenta_solo_a_quien_sigue_entrando(): void
    {
        User::factory()->create(['email' => 'suelta@example.test', 'activo' => true]);
        User::factory()->create(['email' => 'suelta-inactiva@example.test', 'activo' => false]);

        // El administrador y la suelta activa; la inactiva no entra.
        $this->actingAs($this->admin)->get(route('personal.accesos.index'))->assertOk()
            ->assertSee('Hay 2 usuarios sin ficha en Personal.');
        $this->get(route('personal.accesos.index', ['activo' => '0']))->assertOk()
            ->assertSee('Hay 2 usuarios sin ficha en Personal.')->assertSee('suelta-inactiva@example.test');
    }

    /**
     * Con «sin ficha» puesto, el aviso sigue aunque ya no quede ninguna cuenta activa sin ficha:
     * la lista puede seguir mostrando las apagadas y es lo único que dice que está filtrada.
     */
    public function test_accesos_sin_ficha_sin_ninguna_activa_igual_avisa_el_filtro_y_deja_salir(): void
    {
        // El administrador ya tiene ficha: no queda ninguna cuenta activa sin ficha.
        $this->persona('Administrador De Prueba', 'FICTICIO-ADM')->update(['user_id' => $this->admin->id]);
        User::factory()->create(['email' => 'suelta-inactiva@example.test', 'activo' => false]);
        $this->actingAs($this->admin);

        $this->get(route('personal.accesos.index'))->assertOk()
            ->assertDontSee('Mostrando solo las cuentas sin ficha')->assertDontSee('usuarios sin ficha en Personal');

        $filtrada = $this->get(route('personal.accesos.index', ['sin_ficha' => 1, 'activo' => 'todos']))->assertOk()
            ->assertSee('suelta-inactiva@example.test')
            ->assertSee('Mostrando solo las cuentas sin ficha en Personal.');
        $this->assertEnlace($filtrada, 'Ver todos', route('personal.accesos.index'));
    }

    public function test_accesos_la_paginacion_conserva_el_estado(): void
    {
        foreach (range(1, 16) as $n) {
            User::factory()->create(['name' => sprintf('Cuenta inactiva %02d', $n), 'email' => sprintf('inactiva%02d@example.test', $n), 'activo' => false]);
        }

        $this->actingAs($this->admin)->get(route('personal.accesos.index', ['activo' => '0']))->assertOk()
            ->assertSee('inactiva01@example.test')->assertDontSee('inactiva16@example.test')
            ->assertSee(route('personal.accesos.index', ['activo' => '0', 'page' => 2]));
        $this->get(route('personal.accesos.index', ['activo' => '0', 'page' => 2]))->assertOk()
            ->assertSee('inactiva16@example.test')->assertDontSee('inactiva01@example.test');

        $this->get(route('personal.accesos.index'))->assertOk()
            ->assertDontSee('inactiva01@example.test')->assertDontSee(route('personal.accesos.index', ['page' => 2]));
    }

    // ─────────────────────────────── Permisos ───────────────────────────────

    public function test_el_estado_no_abre_las_listas_a_quien_no_tiene_permiso(): void
    {
        $this->deBaja();
        $sinPermiso = $this->usuario(['dashboard.ver']);
        // Ve Empleados, pero Accesos es de quien gestiona usuarios.
        $soloPersonal = $this->usuario(['planilla.ver', 'asistencia.ver']);

        foreach (['0', 'todos'] as $activo) {
            $this->actingAs($sinPermiso)->get(route('personal.empleados', ['activo' => $activo]))->assertForbidden();
            $this->actingAs($sinPermiso)->get(route('personal.accesos.index', ['activo' => $activo]))->assertForbidden();
            $this->actingAs($soloPersonal)->get(route('personal.accesos.index', ['activo' => $activo]))->assertForbidden();
        }
        $this->actingAs($soloPersonal)->get(route('personal.empleados', ['activo' => '0']))->assertOk()
            ->assertSee('Empleado Baja De Prueba');
    }
}
