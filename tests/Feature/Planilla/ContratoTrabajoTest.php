<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Empresa;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\ContratoTrabajo;
use App\Services\Planilla\SueldoHabitual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Todo con datos ficticios: nombres, documentos, patrono y direcciones. */
class ContratoTrabajoTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config([
            'planilla.enabled' => true,
            'planilla.contrato.patrono' => [
                'sexo' => 'F', 'fecha_nacimiento' => '1970-01-31', 'estado_civil' => 'Casada', 'profesion' => 'Comerciante',
                'domicilio' => 'San Ficticio', 'residencia' => 'Calle Ejemplo 1', 'nacionalidad' => 'salvadoreña',
                'dui' => '00000000-0', 'dui_expedido_en' => 'San Ficticio', 'dui_expedido_el' => '2020-01-31',
                'actividad' => 'Actividad de ejemplo', 'ciudad' => 'Ciudad Ejemplo', 'direccion_pago' => 'Dirección Ejemplo',
            ],
        ]);

        $this->actor = User::factory()->create(['activo' => true]);
        $this->actor->syncPermissions(PermisoSistema::todos());
        Empresa::create(['razon_social' => 'Patrona Ejemplo', 'nombre_comercial' => 'Negocio Ejemplo', 'nit' => '00000000000000', 'ambiente' => '00', 'activo' => true]);
    }

    private function empleado(array $cambios = []): PlanillaEmpleado
    {
        $e = app(AltaEmpleado::class)->registrar($this->actor, array_replace([
            'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'documento_tipo' => 'dui', 'dui' => '00000000-0',
            'con_descuentos_ley' => true, 'alta_el' => '2026-01-15', 'sueldo_mensual' => '408.80', 'cargo' => 'Operaria',
        ], $cambios));

        $e->update([
            'sexo' => 'F', 'fecha_nacimiento' => '1990-05-10', 'estado_civil' => 'Soltera', 'profesion' => 'Operaria',
            'domicilio' => 'San Ficticio', 'residencia' => 'Pasaje Ejemplo 2', 'nacionalidad' => 'salvadoreña',
            'dui_expedido_en' => 'San Ficticio', 'dui_expedido_el' => '2018-02-03', 'funciones' => 'Empacar',
            'lugar_trabajo' => 'Planta Ejemplo', 'herramientas' => 'Mesa y guantes',
        ]);

        return $e->fresh();
    }

    public function test_con_todos_los_datos_genera_el_pdf_y_lo_registra(): void
    {
        $e = $this->empleado();

        $this->assertSame([], app(ContratoTrabajo::class)->faltantes($e));

        $respuesta = $this->actingAs($this->actor)->get(route('planilla.empleados.contrato.pdf', $e));
        $respuesta->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));

        $actividad = Activity::where('description', 'generó el contrato')->sole();
        $this->assertStringNotContainsString('408', json_encode($actividad->properties));
    }

    public function test_el_texto_lleva_el_sueldo_en_letras_y_la_fecha_de_hoy(): void
    {
        $this->travelTo('2027-03-09');
        $e = $this->empleado();

        $html = view('planilla.contrato-pdf', app(ContratoTrabajo::class)->datos($e) + ['logo' => null])->render();

        $this->assertStringContainsString('CUATROCIENTOS OCHO 80/100 DÓLARES ($408.80)', $html);
        $this->assertStringContainsString('A los 9 días del mes marzo de 2027', $html);
        $this->assertStringContainsString('15 de enero de 2026', $html);
        $this->assertStringContainsString('Edad: 36', $html);
    }

    public function test_el_horario_suma_44_horas_y_no_cuenta_el_almuerzo(): void
    {
        $html = view('planilla.contrato-pdf', app(ContratoTrabajo::class)->datos($this->empleado()) + ['logo' => null])->render();
        $html = preg_replace('/\s+/', ' ', $html);

        $this->assertStringContainsString('Del día lunes al día viernes, de 8:00 a 17:00.', $html);
        $this->assertStringContainsString('El día sábado de 8:00 a 12:00.', $html);
        $this->assertStringContainsString('Esa hora no se cuenta como jornada.', $html);
        $this->assertStringContainsString('8 horas diarias de lunes a viernes y de 4 horas el sábado, para una semana laboral de 44 horas.', $html);
        $this->assertStringNotContainsString('semana Laboral 8 horas', $html);
    }

    public function test_el_contrato_sale_con_el_sueldo_vigente_el_dia_que_se_genera(): void
    {
        $this->travelTo('2026-11-02');
        $e = $this->empleado();
        app(SueldoHabitual::class)->registrarMensual($this->actor, $e, ['sueldo_mensual' => '500.00', 'vigente_desde' => '2026-12-01']);

        $this->assertStringContainsString('408.80', app(ContratoTrabajo::class)->datos($e)['sueldo']);

        $this->travelTo('2026-12-01');
        $this->assertStringContainsString('500.00', app(ContratoTrabajo::class)->datos($e)['sueldo']);
    }

    public function test_quien_va_sin_descuentos_no_lleva_contrato(): void
    {
        $e = $this->empleado(['con_descuentos_ley' => false]);

        $this->actingAs($this->actor)->get(route('planilla.empleados.contrato.pdf', $e))->assertSessionHasErrors('contrato');
        $this->actingAs($this->actor)->put(route('planilla.empleados.contrato.update', $e), [])->assertSessionHasErrors('contrato');
        $this->actingAs($this->actor)->get(route('planilla.empleados.contrato', $e))->assertOk()->assertSee('no lleva contrato');
    }

    public function test_el_contrato_vuelve_a_la_ficha_de_la_persona(): void
    {
        $e = $this->empleado();

        $this->actingAs($this->actor)->get(route('planilla.empleados.contrato', $e))->assertOk()
            ->assertSee('href="'.route('personal.empleados.show', 'p'.$e->id).'"', false);
    }

    public function test_sin_datos_dice_cuales_faltan(): void
    {
        $e = $this->empleado();
        $e->update(['estado_civil' => null, 'residencia' => null]);

        $this->expectExceptionMessage('estado civil, residencia');
        app(ContratoTrabajo::class)->datos($e);
    }

    public function test_sin_datos_del_patrono_no_sale_y_no_inventa_ninguno(): void
    {
        config(['planilla.contrato.patrono.dui' => '', 'planilla.contrato.patrono.ciudad' => '']);

        $faltan = implode(', ', app(ContratoTrabajo::class)->faltantes($this->empleado()));

        $this->assertStringContainsString('DUI del patrono', $faltan);
        $this->assertStringContainsString('ciudad de firma del patrono', $faltan);
    }

    public function test_el_pasaporte_no_pide_expedicion_de_dui(): void
    {
        $e = $this->empleado(['documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-9']);
        $e->update(['dui_expedido_en' => null, 'dui_expedido_el' => null]);

        $this->assertSame([], app(ContratoTrabajo::class)->faltantes($e));

        $html = view('planilla.contrato-pdf', app(ContratoTrabajo::class)->datos($e) + ['logo' => null])->render();
        $this->assertStringContainsString('Pasaporte: FICTICIO-9', $html);
    }

    public function test_sin_sueldo_no_sale(): void
    {
        $e = $this->empleado(['sueldo_mensual' => null]);

        $this->assertContains('sueldo', app(ContratoTrabajo::class)->faltantes($e));
    }

    public function test_guardar_datos_valida_fechas_y_dependientes(): void
    {
        $e = $this->empleado();
        $url = route('planilla.empleados.contrato.update', $e);
        $this->actingAs($this->actor);

        $this->put($url, ['fecha_nacimiento' => now()->addDay()->toDateString()])->assertSessionHasErrors('fecha_nacimiento');
        $this->put($url, ['fecha_nacimiento' => '1800-01-01'])->assertSessionHasErrors('fecha_nacimiento');
        $this->put($url, ['fecha_nacimiento' => '1990-01-01', 'dui_expedido_el' => now()->addDay()->toDateString()])->assertSessionHasErrors('dui_expedido_el');
        $this->put($url, ['fecha_nacimiento' => '1990-01-01', 'dui_expedido_el' => '1985-01-01'])->assertSessionHasErrors('dui_expedido_el');
        $this->put($url, ['sexo' => 'X'])->assertSessionHasErrors('sexo');

        $cinco = array_fill(0, 5, ['nombre' => 'Hijo', 'apellido' => 'Ficticio']);
        $this->put($url, ['dependientes' => $cinco])->assertSessionHasErrors('dependientes');

        $this->put($url, ['dependientes' => [
            ['nombre' => 'Hijo', 'apellido' => 'Ficticio', 'edad' => 7, 'direccion' => 'Calle Ejemplo'],
            ['nombre' => '', 'apellido' => '', 'edad' => '', 'direccion' => ''],
        ]])->assertSessionHasNoErrors();

        $this->assertCount(1, $e->fresh()->dependientes);
    }

    public function test_menor_de_18_avisa_sin_frenar(): void
    {
        $e = $this->empleado();
        $e->update(['fecha_nacimiento' => now()->subYears(16)->toDateString()]);

        $this->assertStringContainsString('menor de 18', app(ContratoTrabajo::class)->avisos($e->fresh())[0]);
        $this->assertSame([], app(ContratoTrabajo::class)->faltantes($e->fresh()));
    }

    public function test_el_formulario_ofrece_los_dos_modelos_para_precargar(): void
    {
        config(['planilla.contrato.modelos.produccion.lugar_trabajo' => 'Planta de ejemplo']);

        $this->actingAs($this->actor)->get(route('planilla.empleados.contrato', $this->empleado()))->assertOk()
            ->assertSee('Modelo de contrato')
            ->assertSee('Producción')
            ->assertSee('Ventas')
            ->assertSee('Trabajador en producci')
            ->assertSee('Planta de ejemplo');
    }

    public function test_los_modelos_traen_los_mismos_datos_y_ninguna_direccion_real(): void
    {
        foreach (config('planilla.contrato.modelos') as $modelo) {
            $this->assertSame(['etiqueta', 'cargo', 'funciones', 'lugar_trabajo', 'herramientas'], array_keys($modelo));
            $this->assertNotSame('', $modelo['funciones']);
        }

        $config = file_get_contents(config_path('planilla.php'));

        foreach (['Olocuilta', 'kilometro', 'aeropuerto'] as $real) {
            $this->assertStringNotContainsString($real, $config);
        }
    }

    public function test_lo_precargado_se_guarda_editado_y_sale_en_el_contrato(): void
    {
        $e = $this->empleado();
        $modelo = config('planilla.contrato.modelos.ventas');

        $this->actingAs($this->actor)->put(route('planilla.empleados.contrato.update', $e), [
            'cargo' => $modelo['cargo'], 'funciones' => $modelo['funciones'].' Texto agregado.',
            'lugar_trabajo' => 'Ruta de ejemplo', 'herramientas' => $modelo['herramientas'],
        ])->assertSessionHasNoErrors();

        $html = view('planilla.contrato-pdf', app(ContratoTrabajo::class)->datos($e->fresh()) + ['logo' => null])->render();

        $this->assertStringContainsString('como: Vendedor', $html);
        $this->assertStringContainsString('Texto agregado.', $html);
        $this->assertStringContainsString('Ruta de ejemplo', $html);
    }

    /** Persona recién dada de alta: sin datos del puesto escritos. */
    private function alta(array $cambios = []): PlanillaEmpleado
    {
        return app(AltaEmpleado::class)->registrar($this->actor, array_replace([
            'nombres' => 'Bea', 'apellidos' => 'Ejemplo', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-7',
            'con_descuentos_ley' => true, 'alta_el' => '2026-01-15', 'sueldo_mensual' => '408.80',
        ], $cambios));
    }

    /** @return array<string, mixed> */
    private function usuarioNuevo(string $rol): array
    {
        Role::findOrCreate($rol, 'web');

        return [
            'usa_sistema' => true, 'rol' => $rol, 'email' => 'bea@example.test',
            'password' => 'Prueba#Segura2026', 'password_confirmation' => 'Prueba#Segura2026',
        ];
    }

    public function test_con_rol_vendedor_sale_ventas(): void
    {
        $e = $this->alta($this->usuarioNuevo(RolSistema::Vendedor->value));

        $this->assertSame('ventas', app(ContratoTrabajo::class)->modeloDe($e));
    }

    public function test_quien_sale_a_rutas_sin_usuario_sale_ventas(): void
    {
        $e = $this->alta(['sale_rutas' => true]);

        $this->assertNull($e->user_id);
        $this->assertSame('ventas', app(ContratoTrabajo::class)->modeloDe($e));
    }

    public function test_quien_solo_marca_sale_produccion(): void
    {
        $this->assertSame('produccion', app(ContratoTrabajo::class)->modeloDe($this->alta(['marca' => true])));
    }

    public function test_otro_rol_del_sistema_sale_produccion(): void
    {
        $e = $this->alta($this->usuarioNuevo(RolSistema::Facturacion->value));

        $this->assertSame('produccion', app(ContratoTrabajo::class)->modeloDe($e));
    }

    public function test_una_ficha_de_rutas_sin_funcion_de_vendedor_sale_produccion(): void
    {
        $ficha = PersonalRuta::create(['nombre' => 'Bea Ejemplo', 'activo' => true]);
        $ficha->funciones()->create(['funcion' => 'repartidor']);

        $this->assertSame('produccion', app(ContratoTrabajo::class)->modeloDe($this->alta(['personal_ruta_id' => $ficha->id])));
    }

    public function test_el_formulario_abre_con_el_modelo_marcado_y_el_puesto_lleno(): void
    {
        $e = $this->alta(['sale_rutas' => true]);

        $html = $this->actingAs($this->actor)->get(route('planilla.empleados.contrato', $e))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="ventas"\s+selected/', $html);
        $this->assertStringContainsString('value="Vendedor"', $html);
        $this->assertStringContainsString('Motocicleta', $html);
        $this->assertNull($e->fresh()->funciones, 'Abrir el formulario no guarda nada.');
    }

    public function test_el_contrato_sale_sin_elegir_ni_guardar_el_puesto(): void
    {
        $e = $this->alta(['sale_rutas' => true]);
        $e->update([
            'sexo' => 'F', 'fecha_nacimiento' => '1990-05-10', 'estado_civil' => 'Soltera', 'profesion' => 'Vendedora',
            'domicilio' => 'San Ficticio', 'residencia' => 'Pasaje Ejemplo 2', 'nacionalidad' => 'salvadoreña',
        ]);

        $this->assertSame([], app(ContratoTrabajo::class)->faltantes($e->fresh()));

        $html = view('planilla.contrato-pdf', app(ContratoTrabajo::class)->datos($e->fresh()) + ['logo' => null])->render();
        $this->assertStringContainsString('como: Vendedor', $html);
        $this->assertStringContainsString('puntos de venta', $html);
    }

    public function test_lo_escrito_a_mano_manda_sobre_el_modelo(): void
    {
        $e = $this->alta(['sale_rutas' => true, 'cargo' => 'Supervisor de zona']);

        $this->assertSame('Supervisor de zona', app(ContratoTrabajo::class)->conModelo($e)->cargo);
    }

    public function test_produccion_sin_direccion_en_el_env_pide_el_lugar(): void
    {
        config(['planilla.contrato.modelos.produccion.lugar_trabajo' => '']);

        $this->assertContains('lugar de trabajo', app(ContratoTrabajo::class)->faltantes($this->alta()));

        config(['planilla.contrato.modelos.produccion.lugar_trabajo' => 'Planta de ejemplo']);

        $this->assertNotContains('lugar de trabajo', app(ContratoTrabajo::class)->faltantes($this->alta(['dui' => 'FICTICIO-8', 'nombres' => 'Cruz'])));
    }

    public function test_el_contrato_exige_ver_importes(): void
    {
        $e = $this->empleado();
        $sinImportes = User::factory()->create(['activo' => true]);
        $sinImportes->syncPermissions(['planilla.ver', 'planilla.gestionar']);

        $this->actingAs($sinImportes);
        $this->get(route('planilla.empleados.contrato', $e))->assertForbidden();
        $this->get(route('planilla.empleados.contrato.pdf', $e))->assertForbidden();
        $this->put(route('planilla.empleados.contrato.update', $e), [])->assertForbidden();
    }

    public function test_el_alta_con_descuentos_lleva_a_los_datos_del_contrato(): void
    {
        $respuesta = $this->actingAs($this->actor)->post(route('planilla.empleados.store'), [
            'nombres' => 'Bea', 'apellidos' => 'Ficticia', 'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-5',
            'con_descuentos_ley' => '1',
        ]);

        $respuesta->assertRedirect(route('planilla.empleados.contrato', PlanillaEmpleado::sole()));
    }

    public function test_la_vista_del_contrato_no_trae_datos_del_patrono_escritos(): void
    {
        $vista = file_get_contents(resource_path('views/planilla/contrato-pdf.blade.php'));

        foreach (['Olocuilta', 'Hernández', 'Hernandez'] as $real) {
            $this->assertStringNotContainsString($real, $vista);
        }
    }
}
