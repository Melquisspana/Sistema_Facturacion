<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Empresa;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Planilla\ContratoTrabajo;
use App\Services\Planilla\SueldoHabitual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
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
