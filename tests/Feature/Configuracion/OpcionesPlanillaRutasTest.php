<?php

namespace Tests\Feature\Configuracion;

use App\Facades\Ajustes;
use App\Http\Controllers\Configuracion\OpcionesModuloController;
use App\Models\User;
use App\Services\Planilla\SalarioMinimo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Configuración › Módulos › Planilla, por su ruta: lo propio de esta página.
 *
 * El método compartido de las páginas de opciones ya se prueba desde otras páginas en
 * {@see OpcionesModuloTest}. Acá va lo que solo tiene Planilla: el sector del salario
 * mínimo (con él se controla que ningún sueldo quede por debajo) y los datos del patrono
 * del contrato, que son datos de una persona y no dejan su valor en la auditoría. Datos
 * ficticios.
 */
class OpcionesPlanillaRutasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['planilla.enabled' => true, 'planilla.sector_salario_minimo' => 'industria']);
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function campo(string $clave): string
    {
        return OpcionesModuloController::campo($clave);
    }

    public function test_el_sector_del_salario_minimo_pide_confirmacion_y_despues_se_guarda_y_queda_en_la_auditoria(): void
    {
        $admin = $this->admin();
        $campo = $this->campo('planilla.sector_salario_minimo');
        $minimoAntes = app(SalarioMinimo::class)->vigenteEn('2026-01-01');

        // Sin confirmar, solo muestra qué cambia.
        $this->actingAs($admin)->put(route('configuracion.opciones.planilla.update'), [$campo => 'agropecuario'])
            ->assertOk()
            ->assertSee('Esto es lo que va a cambiar')
            ->assertSee('name="'.$campo.'" value="agropecuario"', false);

        $this->assertDatabaseCount('ajustes_sistema', 0);
        $this->assertSame($minimoAntes, app(SalarioMinimo::class)->vigenteEn('2026-01-01'));

        $this->actingAs($admin)->put(route('configuracion.opciones.planilla.update'), [$campo => 'agropecuario', 'confirmacion' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.opciones.planilla'))
            ->assertSessionHas('status', 'Opciones de Planilla guardadas.');

        $this->assertSame('agropecuario', Ajustes::texto('planilla.sector_salario_minimo'));
        // El control del salario mínimo usa ya el dato nuevo, no el viejo.
        $this->assertSame(27253, app(SalarioMinimo::class)->vigenteEn('2026-01-01'));
        $this->assertNotSame($minimoAntes, 27253);

        $actividad = Activity::query()->where('log_name', 'ajustes')->sole();
        $this->assertSame($admin->id, (int) $actividad->causer_id);
        $this->assertSame('planilla.sector_salario_minimo', $actividad->properties['clave']);
        $this->assertSame('industria', (string) $actividad->properties['valor_antes']);
        $this->assertSame('agropecuario', (string) $actividad->properties['valor_despues']);
    }

    /** El DUI y la fecha de nacimiento del patrono se guardan, pero la auditoría solo dice que cambiaron. */
    public function test_los_datos_del_patrono_se_guardan_y_la_auditoria_no_guarda_su_valor(): void
    {
        $admin = $this->admin();
        $datos = [
            $this->campo('planilla.contrato.patrono.dui') => '00000000-0',
            $this->campo('planilla.contrato.patrono.fecha_nacimiento') => '1980-01-01',
            $this->campo('planilla.contrato.patrono.ciudad') => 'Ciudad de prueba',
        ];

        $this->actingAs($admin)->put(route('configuracion.opciones.planilla.update'), $datos)
            ->assertOk()->assertSee('Esto es lo que va a cambiar');
        $this->assertDatabaseCount('ajustes_sistema', 0);

        $this->actingAs($admin)->put(route('configuracion.opciones.planilla.update'), $datos + ['confirmacion' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.opciones.planilla'));

        $this->assertSame('00000000-0', Ajustes::texto('planilla.contrato.patrono.dui'));
        $this->assertSame('1980-01-01', Ajustes::texto('planilla.contrato.patrono.fecha_nacimiento'));
        $this->assertSame('Ciudad de prueba', Ajustes::texto('planilla.contrato.patrono.ciudad'));

        $dui = Activity::query()->where('log_name', 'ajustes')->get()
            ->firstWhere(fn (Activity $a) => $a->properties['clave'] === 'planilla.contrato.patrono.dui');
        $this->assertNotNull($dui, 'El cambio del DUI tiene que quedar en la auditoría.');
        $this->assertTrue((bool) $dui->properties['dato_personal']);
        $this->assertArrayNotHasKey('valor_despues', $dui->properties->all());
        $this->assertStringNotContainsString('00000000-0', json_encode(Activity::query()->pluck('properties')->all()));

        // La ciudad es de la empresa, no de la persona: esa sí deja su valor.
        $ciudad = Activity::query()->where('log_name', 'ajustes')->get()
            ->firstWhere(fn (Activity $a) => $a->properties['clave'] === 'planilla.contrato.patrono.ciudad');
        $this->assertSame('Ciudad de prueba', (string) $ciudad->properties['valor_despues']);
    }

    public function test_un_sector_que_no_existe_o_una_fecha_mal_escrita_no_se_guardan(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('configuracion.opciones.planilla.update'), [
            $this->campo('planilla.sector_salario_minimo') => 'sector_inventado',
            'confirmacion' => '1',
        ])->assertSessionHasErrors($this->campo('planilla.sector_salario_minimo'));

        $this->actingAs($admin)->put(route('configuracion.opciones.planilla.update'), [
            $this->campo('planilla.contrato.patrono.fecha_nacimiento') => '31/12/1980',
            'confirmacion' => '1',
        ])->assertSessionHasErrors($this->campo('planilla.contrato.patrono.fecha_nacimiento'));

        $this->assertDatabaseCount('ajustes_sistema', 0);
        $this->assertSame('industria', Ajustes::texto('planilla.sector_salario_minimo'));
    }

    public function test_sin_permiso_da_403_y_con_planilla_apagada_404(): void
    {
        $facturacion = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $datos = [$this->campo('planilla.sector_salario_minimo') => 'agropecuario', 'confirmacion' => '1'];

        $this->actingAs($facturacion)->put(route('configuracion.opciones.planilla.update'), $datos)->assertForbidden();

        config(['planilla.enabled' => false]);
        $this->actingAs($this->admin())->put(route('configuracion.opciones.planilla.update'), $datos)->assertNotFound();

        $this->assertDatabaseCount('ajustes_sistema', 0);
        $this->assertSame('industria', Ajustes::texto('planilla.sector_salario_minimo'));
    }
}
