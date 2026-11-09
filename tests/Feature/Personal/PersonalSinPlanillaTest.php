<?php

namespace Tests\Feature\Personal;

use App\Enums\AreaSistema;
use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Usuarios\CrearUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * «Personal siempre tiene que funcionar» (Melqui, 08/11/2026).
 *
 * Con Planilla APAGADA la administración sigue dando de alta, editando, dando acceso y de
 * baja desde la ficha. Lo que es de Planilla de verdad (planillas, sueldos, vacaciones,
 * indemnización, anticipos) sigue apagado con el módulo. Y «Accesos externos» lista las
 * cuentas de afuera, que solo pueden tener el rol Contabilidad.
 */
class PersonalSinPlanillaTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE = 'Clave-Ficticia-2026';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['asistencia.enabled' => true, 'planilla.enabled' => false, 'gastos.enabled' => false]);

        $this->admin = User::factory()->create(['activo' => true, 'name' => 'Admin Inventado']);
        $this->admin->syncPermissions(PermisoSistema::todos());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = $this->admin->fresh();
    }

    private function persona(): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->admin, [
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-1',
            'con_descuentos_ley' => true, 'alta_el' => '2026-01-01',
        ]);
    }

    public function test_la_administracion_da_de_alta_edita_da_acceso_y_baja_con_planilla_apagada(): void
    {
        $this->actingAs($this->admin)->get(route('personal.empleados'))->assertOk()
            ->assertSee(route('planilla.empleados.create'), false);
        // El alta no ofrece el sueldo: es de Planilla.
        $this->get(route('planilla.empleados.create'))->assertOk()->assertDontSee('name="sueldo_mensual"', false);

        $this->post(route('planilla.empleados.store'), [
            'nombres' => 'Beto', 'apellidos' => 'Inventado', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-2',
            'con_descuentos_ley' => '1', 'alta_el' => '2026-02-01', 'marca' => '1',
        ])->assertSessionHasNoErrors();
        $p = PlanillaEmpleado::sole();
        $ficha = route('personal.empleados.show', 'p'.$p->id);
        // Con descuentos y Planilla apagada no se manda al contrato: queda en su ficha.
        $this->assertNotNull($p->asistencia_empleado_id);

        $this->get($ficha)->assertOk()->assertSee('Beto Inventado')
            ->assertDontSee('Últimos pagos')->assertDontSee('Cambiar sueldo')
            ->assertDontSee(route('planilla.empleados.contrato', $p), false);

        $this->from($ficha)->post(route('planilla.empleados.datos', $p), [
            'nombres' => 'Beto', 'apellidos' => 'Inventado Ruiz',
            'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-2', 'cargo' => 'Bodega', 'alta_el' => '2026-02-01',
        ])->assertRedirect($ficha)->assertSessionHasNoErrors();
        $this->assertSame('Bodega', $p->fresh()->cargo);
        $this->assertSame('Inventado Ruiz', AsistenciaEmpleado::sole()->apellidos);

        $this->from($ficha)->post(route('planilla.empleados.modulos', $p), [
            'usa_sistema' => '1', 'rol' => 'contabilidad', 'email' => 'beto@example.test',
            'password' => self::CLAVE, 'password_confirmation' => self::CLAVE,
        ])->assertRedirect($ficha)->assertSessionHasNoErrors();
        $usuario = User::where('email', 'beto@example.test')->sole();
        $this->assertSame($usuario->id, $p->fresh()->user_id);
        $this->assertFalse($usuario->cuenta_externa);

        $this->from($ficha)->post(route('planilla.empleados.baja', $p), ['fecha' => '2026-11-02', 'motivo_baja' => 'renuncia'])
            ->assertRedirect($ficha)->assertSessionHasNoErrors()
            ->assertSessionHas('planilla.aviso', fn (string $aviso) => ! str_contains($aviso, 'se calcula desde su ficha'));
        $this->assertFalse($p->fresh()->activo);

        $this->from($ficha)->post(route('planilla.empleados.reactivar', $p))->assertRedirect($ficha)->assertSessionHasNoErrors();
        $this->assertTrue($p->fresh()->activo);
    }

    public function test_el_sueldo_no_se_carga_en_el_alta_con_planilla_apagada(): void
    {
        $this->actingAs($this->admin)->post(route('planilla.empleados.store'), [
            'nombres' => 'Beto', 'apellidos' => 'Inventado', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-2',
            'con_descuentos_ley' => '0', 'sueldo_mensual' => '500.00',
        ])->assertSessionHasErrors('sueldo_mensual');

        $this->assertSame(0, PlanillaEmpleado::count());
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function pantallasDePlanilla(): array
    {
        return [
            'planillas' => ['get', 'planilla.index', false],
            'abrir' => ['get', 'planilla.create', false],
            'vacaciones' => ['get', 'planilla.vacaciones', false],
            'anticipos' => ['get', 'planilla.anticipos', false],
            'renta' => ['get', 'planilla.renta.index', false],
            'contrato' => ['get', 'planilla.empleados.contrato', true],
            'sueldo' => ['post', 'planilla.empleados.sueldo', true],
            'indemnización' => ['get', 'planilla.empleados.indemnizacion', true],
            'anticipo' => ['post', 'planilla.anticipos.store', true],
        ];
    }

    #[DataProvider('pantallasDePlanilla')]
    public function test_lo_de_planilla_sigue_apagado(string $verbo, string $ruta, bool $conPersona): void
    {
        $url = $conPersona ? route($ruta, $this->persona()) : route($ruta);

        $this->actingAs($this->admin)->$verbo($url)->assertNotFound();
    }

    public function test_el_menu_muestra_empleados_y_no_planilla(): void
    {
        // Con Asistencia también apagada, se aterriza en Empleados.
        config(['asistencia.enabled' => false]);
        $this->assertSame('personal.empleados', AreaSistema::Asistencia->rutaInicioPara($this->admin));

        $html = $this->actingAs($this->admin)->get(route('personal.empleados'))->assertOk()->getContent();
        $menu = substr($html, strpos($html, '<aside'), strpos($html, '</aside>') - strpos($html, '<aside'));

        $this->assertStringContainsString(route('personal.empleados'), $menu);
        $this->assertStringNotContainsString(route('planilla.index'), $menu);
        $this->assertStringNotContainsString(route('planilla.anticipos'), $menu);
    }

    /** @return array<string, array{0: string}> */
    public static function rolesSinPersonal(): array
    {
        return ['vendedor' => ['vendedor'], 'jefe de vendedores' => ['jefe_vendedores'], 'jefatura' => ['jefatura'], 'facturación' => ['facturacion'], 'contabilidad' => ['contabilidad']];
    }

    #[DataProvider('rolesSinPersonal')]
    public function test_los_otros_roles_siguen_sin_entrar_a_personal(string $rol): void
    {
        $p = $this->persona();
        $usuario = User::factory()->create(['activo' => true])->assignRole($rol);

        $this->assertFalse($usuario->can('planilla.ver'));
        $this->actingAs($usuario)->get(route('personal.empleados'))->assertForbidden();
        $this->get(route('personal.empleados.show', 'p'.$p->id))->assertForbidden();
        $this->get(route('planilla.empleados.create'))->assertForbidden();
        $this->post(route('planilla.empleados.store'), ['nombres' => 'X', 'apellidos' => 'Y'])->assertForbidden();
        $this->post(route('planilla.empleados.baja', $p), ['fecha' => '2026-11-02', 'motivo_baja' => 'otro'])->assertForbidden();
        $this->assertTrue($p->fresh()->activo);
    }

    public function test_asistencia_y_rutas_no_crean_personas_sueltas(): void
    {
        $this->actingAs($this->admin)->get(route('asistencia.empleados.create'))->assertOk()
            ->assertDontSee('name="nombres"', false)->assertSee('Dar de alta en Personal');
        $this->post(route('asistencia.empleados.store'), ['nombres' => 'Bea', 'apellidos' => 'Ficticia'])->assertSessionHasErrors('nombres');
        $this->get(route('rutas.personal.create'))->assertRedirect(route('planilla.empleados.create'));
        $this->post(route('rutas.personal.store'), ['nombre' => 'Bea Ficticia'])->assertSessionHasErrors('nombre');

        $this->assertSame(0, AsistenciaEmpleado::count());
        $this->assertSame(0, PersonalRuta::count());
    }

    public function test_accesos_externos_lista_solo_las_cuentas_sin_ficha(): void
    {
        $crear = app(CrearUsuario::class);
        $contadora = $crear->crear(['name' => 'Carla Inventada', 'email' => 'carla@example.test', 'password' => self::CLAVE, 'activo' => true, 'rol' => 'contabilidad', 'cuenta_externa' => true]);
        $crear->crear(['name' => 'Suelto Inventado', 'email' => 'suelto@example.test', 'password' => self::CLAVE, 'activo' => true, 'rol' => 'vendedor']);
        $p = $this->persona();
        $conFicha = $crear->crear(['name' => 'Ana Ficticia', 'email' => 'ana@example.test', 'password' => self::CLAVE, 'activo' => true, 'rol' => 'contabilidad', 'cuenta_externa' => true]);
        $p->update(['user_id' => $conFicha->id]);

        $this->actingAs($this->admin)->get(route('personal.accesos.index'))->assertOk()
            ->assertSee(route('personal.accesos.index', ['externas' => 1]), false)
            ->assertSee('suelto@example.test')->assertSee('carla@example.test');

        $this->get(route('personal.accesos.index', ['externas' => 1]))->assertOk()
            ->assertSee('Accesos externos')->assertSee('carla@example.test')
            ->assertDontSee('suelto@example.test')->assertDontSee('ana@example.test');
        $this->get(route('personal.accesos.index', ['externas' => 1, 'q' => 'nadie']))->assertOk()
            ->assertSee('Nada coincide con estos filtros.')->assertSee('name="externas" value="1"', false);
        $this->assertTrue($contadora->fresh()->cuenta_externa);
    }

    public function test_la_cuenta_externa_solo_puede_ser_de_contabilidad(): void
    {
        $datos = ['name' => 'Carla Inventada', 'email' => 'carla@example.test', 'password' => self::CLAVE, 'password_confirmation' => self::CLAVE, 'cuenta_externa' => '1'];
        $antes = User::count();

        foreach (['administrador', 'vendedor', 'facturacion', 'jefatura'] as $rol) {
            $this->actingAs($this->admin)->post(route('personal.accesos.store'), $datos + ['rol' => $rol])->assertSessionHasErrors('rol');
        }
        // Sin marcarla externa tampoco: el acceso de un empleado se da en su ficha.
        $this->post(route('personal.accesos.store'), array_diff_key($datos, ['cuenta_externa' => 1]) + ['rol' => 'contabilidad'])
            ->assertSessionHasErrors('cuenta_externa');
        $this->assertSame($antes, User::count());

        $this->post(route('personal.accesos.store'), $datos + ['rol' => 'contabilidad'])->assertSessionHasNoErrors();
        $carla = User::where('email', 'carla@example.test')->sole();
        $this->assertTrue($carla->cuenta_externa);
        $this->assertSame(['contabilidad'], $carla->getRoleNames()->all());

        // Y después tampoco se le cambia a otro rol.
        $this->put(route('personal.accesos.update', $carla), ['name' => 'Carla Inventada', 'email' => 'carla@example.test', 'rol' => 'administrador'])
            ->assertSessionHasErrors('rol', null, 'acceso');
        $this->assertSame(['contabilidad'], $carla->fresh()->getRoleNames()->all());
    }

    public function test_configuracion_lleva_a_accesos_externos(): void
    {
        $this->actingAs($this->admin)->get(route('configuracion.resumen'))->assertOk()
            ->assertSee(route('personal.accesos.index', ['externas' => 1]), false)
            ->assertSee('Accesos externos');
    }
}
