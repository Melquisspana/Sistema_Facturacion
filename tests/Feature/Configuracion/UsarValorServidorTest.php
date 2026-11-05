<?php

namespace Tests\Feature\Configuracion;

use App\Ajustes\Definicion\FuenteAjuste;
use App\Enums\ModuloSistema;
use App\Facades\Ajustes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * «Usar el valor del servidor»: borra lo guardado de un ajuste para que vuelva a mandar
 * el .env. Respeta el nivel (N2 confirma), pasa por la lista blanca, no toca secretos y
 * queda en la auditoría como cualquier cambio.
 */
class UsarValorServidorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function ruta(string $clave): string
    {
        return route('configuracion.ajustes.servidor', $clave);
    }

    public function test_un_n1_vuelve_al_env_con_un_clic_y_queda_auditado(): void
    {
        config(['cobros.correo.limite' => 50]);
        Ajustes::guardarComoSistema('cobros.correo.limite', 20);
        Activity::query()->delete();

        $this->actingAs($this->admin())
            ->from(route('configuracion.opciones.cobros'))
            ->delete($this->ruta('cobros.correo.limite'))
            ->assertRedirect(route('configuracion.opciones.cobros'))
            ->assertSessionHas('status');

        $this->assertSame(50, Ajustes::entero('cobros.correo.limite'));
        $this->assertSame(FuenteAjuste::Configuracion, Ajustes::fuente('cobros.correo.limite'));

        $actividad = Activity::query()->where('log_name', 'ajustes')->sole();
        $this->assertSame('override_quitado', $actividad->properties['accion']);
        $this->assertSame('20', (string) $actividad->properties['valor_antes']);
        $this->assertSame('50', (string) $actividad->properties['valor_despues']);
        $this->assertSame(FuenteAjuste::BaseDeDatos->value, $actividad->properties['fuente_antes']);
        $this->assertSame(FuenteAjuste::Configuracion->value, $actividad->properties['fuente_despues']);
    }

    public function test_un_n2_pide_confirmacion_y_anuncia_el_valor_que_vuelve(): void
    {
        config(['ppq.codigo_proveedor' => '000123']);
        Ajustes::guardarComoSistema('ppq.codigo_proveedor', '000999');
        $admin = $this->admin();
        $volver = route('configuracion.opciones.pronto-pago');

        $this->actingAs($admin)->from($volver)->delete($this->ruta('ppq.codigo_proveedor'))
            ->assertOk()
            ->assertSee('Vas a volver al valor del servidor')
            ->assertSee('000999')
            ->assertSee('000123')
            ->assertSee('name="volver" value="'.$volver.'"', false);

        $this->assertSame('000999', Ajustes::texto('ppq.codigo_proveedor'));

        $this->actingAs($admin)->delete($this->ruta('ppq.codigo_proveedor'), ['confirmacion' => '1', 'volver' => $volver])
            ->assertRedirect($volver);

        $this->assertSame('000123', Ajustes::texto('ppq.codigo_proveedor'));
        $this->assertSame(FuenteAjuste::Configuracion, Ajustes::fuente('ppq.codigo_proveedor'));
    }

    /** También en las pantallas que ya existían: un módulo vuelve a obedecer al .env. */
    public function test_vale_para_los_ajustes_que_ya_existian(): void
    {
        config(['gastos.enabled' => false]);
        Ajustes::guardarComoSistema(ModuloSistema::Gastos->clave(), true);

        $this->actingAs($this->admin())->get(route('configuracion.modulos'))->assertOk()
            ->assertSee('Usar el valor del servidor')
            ->assertSee($this->ruta(ModuloSistema::Gastos->clave()), false)
            ->assertSee('id="form-usar-valor-servidor"', false);

        $this->actingAs($this->admin())
            ->delete($this->ruta(ModuloSistema::Gastos->clave()), ['confirmacion' => '1']);

        $this->assertFalse(ModuloSistema::Gastos->activo());
    }

    public function test_el_boton_solo_aparece_si_hay_algo_guardado(): void
    {
        config(['cobros.correo.limite' => 50]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('configuracion.opciones.cobros'))->assertOk()
            ->assertDontSee($this->ruta('cobros.correo.limite'), false);

        Ajustes::guardarComoSistema('cobros.correo.limite', 20);

        $this->actingAs($admin)->get(route('configuracion.opciones.cobros'))->assertOk()
            ->assertSee($this->ruta('cobros.correo.limite'), false)
            ->assertSee('Usar el valor del servidor');
    }

    public function test_si_ya_usa_el_valor_del_servidor_no_hace_nada(): void
    {
        $this->actingAs($this->admin())->delete($this->ruta('cobros.correo.limite'))
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame(0, Activity::query()->where('log_name', 'ajustes')->count());
    }

    public function test_no_sirve_para_secretos_ni_para_claves_desconocidas_o_cerradas(): void
    {
        Ajustes::guardarComoSistema('documentos_recibidos.password', 'clave-de-prueba');
        $admin = $this->admin();

        foreach (['documentos_recibidos.password', 'no.existe', 'dte.ambiente', 'ppq.gmail.storage_dir'] as $clave) {
            $this->actingAs($admin)->delete($this->ruta($clave), ['confirmacion' => '1'])->assertNotFound();
        }

        $this->assertSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente('documentos_recibidos.password'));
    }

    public function test_sin_permiso_no_se_borra(): void
    {
        Ajustes::guardarComoSistema('cobros.correo.limite', 20);
        $facturacion = User::factory()->create(['activo' => true])->assignRole('facturacion');

        $this->actingAs($facturacion)->delete($this->ruta('cobros.correo.limite'), ['confirmacion' => '1'])
            ->assertForbidden();

        $this->assertSame(20, Ajustes::entero('cobros.correo.limite'));
    }

    /** Un `volver` armado a mano no convierte el botón en una redirección afuera. */
    public function test_no_redirige_fuera_de_la_aplicacion(): void
    {
        Ajustes::guardarComoSistema('cobros.correo.limite', 20);

        $this->actingAs($this->admin())
            ->delete($this->ruta('cobros.correo.limite'), ['volver' => 'https://ejemplo.invalid/robar'])
            ->assertRedirect(route('configuracion.resumen'));
    }
}
