<?php

namespace Tests\Feature\Configuracion;

use App\Facades\Ajustes;
use App\Http\Controllers\Configuracion\OpcionesModuloController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Configuración › Módulos › Avisos de fallas y › Gastos, por sus RUTAS
 * (`configuracion.opciones.avisos.update` y `configuracion.opciones.gastos.update`).
 *
 * El método compartido de estas páginas ya se prueba en {@see OpcionesModuloTest}
 * (validación, lista blanca, módulos apagados). Acá va el efecto de cada página: lo que
 * se guarda queda en `ajustes_sistema` y en la auditoría, lo de nivel 2 pide
 * confirmación antes, y guardar dos veces lo mismo no escribe ni audita otra vez. Datos
 * ficticios.
 */
class OpcionesAvisosGastosRutasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Lo del archivo del servidor, fijo para no depender de la máquina.
        config([
            'alertas.enabled' => false,
            'alertas.correo' => '',
            'alertas.reaviso_minutos' => 60,
            'alertas.tope_diario' => 6,
            'alertas.respaldo_hora_limite' => '06:00',
            'asistencia.enabled' => false,
            'gastos.enabled' => true,
            'gastos.recurrencias.generacion_automatica' => false,
            'gastos.avisos.automaticos' => false,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function campo(string $clave): string
    {
        return OpcionesModuloController::campo($clave);
    }

    /** La línea de auditoría de una clave (tiene que haber exactamente una). */
    private function auditoriaDe(string $clave): Activity
    {
        return Activity::query()->where('log_name', 'ajustes')->get()
            ->filter(fn (Activity $a) => ($a->properties['clave'] ?? null) === $clave)
            ->sole();
    }

    // ------------------------------------------------------------------ avisos

    public function test_avisos_encender_y_poner_el_correo_pide_confirmacion_y_despues_se_guarda(): void
    {
        $admin = $this->admin();
        $datos = [
            $this->campo('alertas.enabled') => '1',
            $this->campo('alertas.correo') => 'avisos@ejemplo.com, soporte@ejemplo.com',
        ];

        $this->actingAs($admin)->put(route('configuracion.opciones.avisos.update'), $datos)
            ->assertOk()
            ->assertSee('Vas a cambiar opciones de Avisos de fallas');
        $this->assertDatabaseCount('ajustes_sistema', 0);

        $this->actingAs($admin)->put(route('configuracion.opciones.avisos.update'), $datos + ['confirmacion' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.opciones.avisos'))
            ->assertSessionHas('status', 'Opciones de Avisos de fallas guardadas.');

        $this->assertDatabaseHas('ajustes_sistema', ['clave' => 'alertas.enabled', 'valor' => '1']);
        $this->assertDatabaseHas('ajustes_sistema', ['clave' => 'alertas.correo', 'valor' => 'avisos@ejemplo.com, soporte@ejemplo.com']);
        $this->assertTrue(Ajustes::bool('alertas.enabled'));

        $encendido = $this->auditoriaDe('alertas.enabled');
        $this->assertSame($admin->id, (int) $encendido->causer_id);
        $this->assertSame('No', $encendido->properties['valor_antes']);
        $this->assertSame('Sí', $encendido->properties['valor_despues']);
        $this->assertSame('avisos@ejemplo.com, soporte@ejemplo.com', $this->auditoriaDe('alertas.correo')->properties['valor_despues']);
    }

    /** Lo de nivel 1 (minutos, tope, hora) se guarda directo, y solo lo que cambia. */
    public function test_avisos_los_numeros_y_la_hora_se_guardan_directo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('configuracion.opciones.avisos.update'), [
            $this->campo('alertas.reaviso_minutos') => '30',
            $this->campo('alertas.tope_diario') => '6',
            $this->campo('alertas.respaldo_hora_limite') => '07:30',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.opciones.avisos'));

        $this->assertDatabaseHas('ajustes_sistema', ['clave' => 'alertas.reaviso_minutos', 'valor' => '30']);
        $this->assertDatabaseHas('ajustes_sistema', ['clave' => 'alertas.respaldo_hora_limite', 'valor' => '07:30']);
        // El tope no cambió (6 en el servidor): sigue saliendo del servidor.
        $this->assertDatabaseMissing('ajustes_sistema', ['clave' => 'alertas.tope_diario']);
        // Y apagado, como estaba: tampoco se escribe.
        $this->assertDatabaseMissing('ajustes_sistema', ['clave' => 'alertas.enabled']);

        $this->assertSame('60', (string) $this->auditoriaDe('alertas.reaviso_minutos')->properties['valor_antes']);
        $this->assertSame('30', (string) $this->auditoriaDe('alertas.reaviso_minutos')->properties['valor_despues']);
    }

    /** Con Asistencia encendida, el horario del lector se guarda desde la misma página. */
    public function test_avisos_con_asistencia_encendida_guarda_el_horario_del_lector(): void
    {
        config(['asistencia.enabled' => true]);

        $this->actingAs($this->admin())->put(route('configuracion.opciones.avisos.update'), [
            $this->campo('alertas.lector.sin_ping_minutos') => '20',
            $this->campo('alertas.lector.horario.1') => '07:00-16:00',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.opciones.avisos'));

        $this->assertDatabaseHas('ajustes_sistema', ['clave' => 'alertas.lector.sin_ping_minutos', 'valor' => '20']);
        $this->assertDatabaseHas('ajustes_sistema', ['clave' => 'alertas.lector.horario.1', 'valor' => '07:00-16:00']);
    }

    public function test_avisos_guardar_dos_veces_lo_mismo_no_escribe_ni_audita_otra_vez(): void
    {
        $admin = $this->admin();
        $datos = [$this->campo('alertas.enabled') => '1', $this->campo('alertas.reaviso_minutos') => '45', 'confirmacion' => '1'];

        $this->actingAs($admin)->put(route('configuracion.opciones.avisos.update'), $datos)->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('configuracion.opciones.avisos.update'), $datos)
            ->assertRedirect(route('configuracion.opciones.avisos'))
            ->assertSessionHas('status', 'No había nada que cambiar en Avisos de fallas.');

        $this->assertDatabaseCount('ajustes_sistema', 2);
        $this->assertSame(2, Activity::query()->where('log_name', 'ajustes')->count());
    }

    // ------------------------------------------------------------------ gastos

    public function test_gastos_los_avisos_automaticos_se_guardan_directo_en_ajustes_sistema(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('configuracion.opciones.gastos.update'), [
            $this->campo('gastos.avisos.automaticos') => '1',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.opciones.gastos'))
            ->assertSessionHas('status', 'Opciones de Gastos guardadas.');

        $this->assertDatabaseHas('ajustes_sistema', ['clave' => 'gastos.avisos.automaticos', 'valor' => '1']);
        $this->assertTrue(Ajustes::bool('gastos.avisos.automaticos'));
        // La generación sigue apagada y saliendo del servidor.
        $this->assertDatabaseMissing('ajustes_sistema', ['clave' => 'gastos.recurrencias.generacion_automatica']);

        $auditoria = $this->auditoriaDe('gastos.avisos.automaticos');
        $this->assertSame($admin->id, (int) $auditoria->causer_id);
        $this->assertSame('Sí', $auditoria->properties['valor_despues']);
    }

    /** Crear solos los gastos que se repiten es de nivel 2: primero se confirma. */
    public function test_gastos_la_generacion_automatica_pide_confirmacion_y_se_puede_volver_a_apagar(): void
    {
        $admin = $this->admin();
        $encender = [$this->campo('gastos.recurrencias.generacion_automatica') => '1'];

        $this->actingAs($admin)->put(route('configuracion.opciones.gastos.update'), $encender)
            ->assertOk()
            ->assertSee('Vas a cambiar opciones de Gastos');
        $this->assertDatabaseCount('ajustes_sistema', 0);

        $this->actingAs($admin)->put(route('configuracion.opciones.gastos.update'), $encender + ['confirmacion' => '1'])
            ->assertRedirect(route('configuracion.opciones.gastos'));
        $this->assertDatabaseHas('ajustes_sistema', ['clave' => 'gastos.recurrencias.generacion_automatica', 'valor' => '1']);
        $this->assertTrue(Ajustes::bool('gastos.recurrencias.generacion_automatica'));

        // Desmarcar la casilla (no llega en el envío) la apaga, también con confirmación.
        $this->actingAs($admin)->put(route('configuracion.opciones.gastos.update'), ['confirmacion' => '1'])
            ->assertRedirect(route('configuracion.opciones.gastos'));
        $this->assertDatabaseHas('ajustes_sistema', ['clave' => 'gastos.recurrencias.generacion_automatica', 'valor' => '0']);
        $this->assertFalse(Ajustes::bool('gastos.recurrencias.generacion_automatica'));
        $this->assertSame(2, Activity::query()->where('log_name', 'ajustes')->count());
    }

    public function test_gastos_guardar_dos_veces_lo_mismo_no_duplica(): void
    {
        $admin = $this->admin();
        $datos = [$this->campo('gastos.avisos.automaticos') => '1'];

        $this->actingAs($admin)->put(route('configuracion.opciones.gastos.update'), $datos)->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('configuracion.opciones.gastos.update'), $datos)
            ->assertSessionHas('status', 'No había nada que cambiar en Gastos.');

        $this->assertDatabaseCount('ajustes_sistema', 1);
        $this->assertSame(1, Activity::query()->where('log_name', 'ajustes')->count());
    }

    // ------------------------------------------------------------------ acceso

    public function test_sin_permiso_da_403_y_con_gastos_apagado_404_sin_guardar_nada(): void
    {
        $facturacion = User::factory()->create(['activo' => true])->assignRole('facturacion');

        $this->actingAs($facturacion)->put(route('configuracion.opciones.avisos.update'), [
            $this->campo('alertas.reaviso_minutos') => '30',
        ])->assertForbidden();
        $this->actingAs($facturacion)->put(route('configuracion.opciones.gastos.update'), [
            $this->campo('gastos.avisos.automaticos') => '1',
        ])->assertForbidden();

        config(['gastos.enabled' => false]);
        $this->actingAs($this->admin())->put(route('configuracion.opciones.gastos.update'), [
            $this->campo('gastos.avisos.automaticos') => '1',
        ])->assertNotFound();

        $this->assertDatabaseCount('ajustes_sistema', 0);
        $this->assertSame(0, Activity::query()->where('log_name', 'ajustes')->count());
    }
}
