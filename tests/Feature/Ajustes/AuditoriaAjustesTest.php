<?php

namespace Tests\Feature\Ajustes;

use App\Facades\Ajustes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Auditoría central de la capa de ajustes: qué cambió, quién, de dónde venía y
 * adónde pasó a resolverse.
 *
 * La "fuente antes / fuente después" es la parte menos obvia y la más útil: deja
 * registrado que un valor dejó de leerse del .env y pasó a leerse de la base
 * —o al revés— sin necesidad de conocer su contenido. Es lo único que permite
 * reconstruir después por qué el sistema empezó a comportarse distinto.
 */
class AuditoriaAjustesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function ultima(): Activity
    {
        return Activity::query()->where('log_name', 'ajustes')->latest('id')->firstOrFail();
    }

    public function test_registra_el_antes_y_el_despues_de_un_ajuste_normal(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        Ajustes::guardar('contabilidad.correo', 'antes@ejemplo.com');
        Ajustes::guardar('contabilidad.correo', 'despues@ejemplo.com');

        $actividad = $this->ultima();

        $this->assertStringContainsString('contabilidad.correo', (string) $actividad->description);
        $this->assertSame('antes@ejemplo.com', $actividad->getExtraProperty('valor_antes'));
        $this->assertSame('despues@ejemplo.com', $actividad->getExtraProperty('valor_despues'));
        $this->assertSame($admin->id, $actividad->causer_id);
    }

    public function test_registra_la_metadata_del_ajuste(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar('respaldos.dias_retencion', 60);

        $actividad = $this->ultima();

        $this->assertSame('respaldos.dias_retencion', $actividad->getExtraProperty('clave'));
        $this->assertSame('respaldos', $actividad->getExtraProperty('seccion'));
        $this->assertSame('n2', $actividad->getExtraProperty('nivel'));
        $this->assertSame('alto', $actividad->getExtraProperty('impacto'));
        $this->assertSame('cambio', $actividad->getExtraProperty('accion'));
    }

    public function test_registra_el_cambio_de_fuente(): void
    {
        config(['backup_diario.dias_retencion' => 30]);
        $this->actingAs($this->admin());

        Ajustes::guardar('respaldos.dias_retencion', 60);

        $actividad = $this->ultima();

        $this->assertSame('configuracion', $actividad->getExtraProperty('fuente_antes'));
        $this->assertSame('base_de_datos', $actividad->getExtraProperty('fuente_despues'));
    }

    public function test_registra_cuando_se_quita_un_override(): void
    {
        config(['backup_diario.dias_retencion' => 30]);
        $this->actingAs($this->admin());

        Ajustes::guardar('respaldos.dias_retencion', 60);
        Ajustes::quitarOverride('respaldos.dias_retencion');

        $actividad = $this->ultima();

        $this->assertSame('override_quitado', $actividad->getExtraProperty('accion'));
        $this->assertSame('base_de_datos', $actividad->getExtraProperty('fuente_antes'));
        $this->assertSame('configuracion', $actividad->getExtraProperty('fuente_despues'));
    }

    public function test_los_booleanos_se_registran_de_forma_legible(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar('correo.auto_envio', true);

        $this->assertSame('Sí', $this->ultima()->getExtraProperty('valor_despues'));
    }

    /**
     * Un cambio hecho desde consola/worker NO inventa una IP local.
     *
     * Se simula quitando REMOTE_ADDR, que es exactamente lo que ocurre de verdad:
     * en CLI la request se construye desde `$_SERVER`, que no lo trae. La suite no
     * puede reproducirlo sola porque las requests de prueba lo ponen a 127.0.0.1.
     */
    public function test_no_registra_ip_fuera_de_una_peticion_web(): void
    {
        request()->server->remove('REMOTE_ADDR');

        Ajustes::guardarComoSistema('contabilidad.correo', 'conta@ejemplo.com');

        $this->assertNull($this->ultima()->getExtraProperty('ip'));
    }

    public function test_registra_la_ip_en_una_peticion_web(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.contabilidad.update'), [
                'correo_contabilidad' => 'conta@ejemplo.com',
                'enviar_copia_contabilidad' => '1',
            ])
            ->assertRedirect();

        $ips = Activity::query()->where('log_name', 'ajustes')->get()
            ->map(fn (Activity $a) => $a->getExtraProperty('ip'))
            ->filter()
            ->all();

        $this->assertNotEmpty($ips, 'Un cambio hecho desde el navegador debería dejar la IP.');
    }

    /** Guardar desde el formulario deja UNA entrada por clave tocada. */
    public function test_el_formulario_de_contabilidad_audita_las_dos_claves(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.contabilidad.update'), [
                'correo_contabilidad' => 'conta@ejemplo.com',
                'enviar_copia_contabilidad' => '1',
            ])
            ->assertRedirect();

        $claves = Activity::query()->where('log_name', 'ajustes')->get()
            ->map(fn (Activity $a) => $a->getExtraProperty('clave'))
            ->all();

        $this->assertContains('contabilidad.correo', $claves);
        $this->assertContains('contabilidad.enviar_copia', $claves);
    }
}
