<?php

namespace Tests\Feature\Configuracion;

use App\Ajustes\CatalogoAjustes;
use App\Ajustes\Definicion\NivelConfirmacion;
use App\Ajustes\Definicion\TipoAjuste;
use App\Ajustes\Excepciones\ValorAjusteInvalidoException;
use App\Facades\Ajustes;
use App\Http\Controllers\Configuracion\OpcionesModuloController;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\User;
use App\Services\Ppq\SalaSucursalResolver;
use App\Support\Sala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Etapa 4 de Configuración: el cliente del pronto pago (`ppq.cliente_default_id`) pasa
 * del .env a Configuración › Módulos › Pronto pago, elegido de la lista de clientes.
 * Riesgo alto (lotes y notas a otra empresa): N2, confirmación con el nombre y, en el
 * servidor, solo clientes que existen. Sin nada guardado manda el .env; con algo
 * guardado lo usan sus lectores reales. Datos ficticios.
 */
class ClientePpqAjusteTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Sala::olvidarCache();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function campo(string $clave): string
    {
        return OpcionesModuloController::campo($clave);
    }

    public function test_el_cliente_es_un_ajuste_n2_de_tipo_cliente(): void
    {
        $definicion = app(CatalogoAjustes::class)->definicion('ppq.cliente_default_id');

        $this->assertSame(NivelConfirmacion::N2, $definicion->nivel);
        $this->assertSame(TipoAjuste::Cliente, $definicion->tipo);
    }

    // ------------------------------------------------------------ Pronto pago

    public function test_el_cliente_se_elige_de_la_lista_y_pide_confirmacion_con_el_nombre(): void
    {
        $antes = Cliente::factory()->create(['nombre' => 'Distribuidora Ficticia Uno']);
        $despues = Cliente::factory()->create(['nombre' => 'Comercial Ficticia Dos']);
        config(['ppq.cliente_default_id' => (string) $antes->id]);
        $admin = $this->admin();
        $campo = $this->campo('ppq.cliente_default_id');

        $this->actingAs($admin)->get(route('configuracion.opciones.pronto-pago'))->assertOk()
            ->assertSee('Cliente del pronto pago')
            ->assertSee('<select id="'.$campo.'" name="'.$campo.'"', false)
            ->assertSee('<option value="'.$antes->id.'" selected', false)
            ->assertSee('Comercial Ficticia Dos');

        $this->actingAs($admin)->put(route('configuracion.opciones.pronto-pago.update'), [$campo => (string) $despues->id])
            ->assertOk()
            ->assertSee('Esto es lo que va a cambiar')
            ->assertSee('Distribuidora Ficticia Uno (n.º '.$antes->id.')')
            ->assertSee('Comercial Ficticia Dos (n.º '.$despues->id.')');
        $this->assertDatabaseCount('ajustes_sistema', 0);

        $this->actingAs($admin)->put(route('configuracion.opciones.pronto-pago.update'), [$campo => (string) $despues->id, 'confirmacion' => '1'])
            ->assertRedirect(route('configuracion.opciones.pronto-pago'));

        $this->assertSame($despues->id, Ajustes::cliente('ppq.cliente_default_id'));
        $actividad = Activity::query()->where('log_name', 'ajustes')->sole();
        $this->assertSame('ppq.cliente_default_id', $actividad->properties['clave']);
        $this->assertSame((string) $antes->id, (string) $actividad->properties['valor_antes']);
        $this->assertSame((string) $despues->id, (string) $actividad->properties['valor_despues']);
    }

    public function test_un_cliente_que_no_existe_se_rechaza_en_el_servidor(): void
    {
        $borrado = Cliente::factory()->create();
        $borrado->delete();
        $admin = $this->admin();
        $campo = $this->campo('ppq.cliente_default_id');

        foreach ([(string) ($borrado->id + 100), (string) $borrado->id, 'abc', '0', '-4'] as $valor) {
            $this->actingAs($admin)->from(route('configuracion.opciones.pronto-pago'))
                ->put(route('configuracion.opciones.pronto-pago.update'), [$campo => $valor, 'confirmacion' => '1'])
                ->assertSessionHasErrors($campo);
        }

        $this->assertDatabaseCount('ajustes_sistema', 0);

        $this->expectException(ValorAjusteInvalidoException::class);
        Ajustes::guardarComoSistema('ppq.cliente_default_id', $borrado->id);
    }

    public function test_sin_permiso_no_se_cambia(): void
    {
        $cliente = Cliente::factory()->create();
        $facturacion = User::factory()->create(['activo' => true])->assignRole('facturacion');

        $this->actingAs($facturacion)->get(route('configuracion.opciones.pronto-pago'))->assertForbidden();
        $this->actingAs($facturacion)->put(route('configuracion.opciones.pronto-pago.update'), [
            $this->campo('ppq.cliente_default_id') => (string) $cliente->id, 'confirmacion' => '1',
        ])->assertForbidden();

        $this->assertDatabaseCount('ajustes_sistema', 0);
    }

    public function test_sin_nada_guardado_los_lectores_del_cliente_usan_el_env_y_con_algo_guardado_lo_guardado(): void
    {
        $delEnv = Cliente::factory()->create(['nombre' => 'Cliente Ficticio Env']);
        $guardado = Cliente::factory()->create(['nombre' => 'Cliente Ficticio Guardado']);
        $salaEnv = ClienteSucursal::factory()->create(['cliente_id' => $delEnv->id, 'codigo' => '0260', 'nombre' => 'Sala Env']);
        $salaGuardada = ClienteSucursal::factory()->create(['cliente_id' => $guardado->id, 'codigo' => '0260', 'nombre' => 'Sala Guardada']);
        config(['ppq.cliente_default_id' => (string) $delEnv->id]);
        $admin = $this->admin();

        // .env
        $this->assertSame($delEnv->id, Ajustes::cliente('ppq.cliente_default_id'));
        $this->assertSame('Sala Env', Sala::nombre('0260'));
        $this->assertSame($salaEnv->id, app(SalaSucursalResolver::class)->resolver('0260')['cliente_sucursal_id']);
        $this->actingAs($admin)->post(route('ppq.lotes.store'), ['referencia' => 'Lote env', 'fecha' => '2026-11-02'])->assertRedirect();
        $this->assertDatabaseHas('ppq_lotes', ['referencia' => 'Lote env', 'cliente_id' => $delEnv->id]);

        // Guardado en pantalla
        Ajustes::guardarComoSistema('ppq.cliente_default_id', $guardado->id);
        Sala::olvidarCache();

        $this->assertSame('Sala Guardada', Sala::nombre('0260'));
        $this->assertSame($salaGuardada->id, app(SalaSucursalResolver::class)->resolver('0260')['cliente_sucursal_id']);
        $this->actingAs($admin)->post(route('ppq.lotes.store'), ['referencia' => 'Lote guardado', 'fecha' => '2026-11-02'])->assertRedirect();
        $this->assertDatabaseHas('ppq_lotes', ['referencia' => 'Lote guardado', 'cliente_id' => $guardado->id]);

        $this->artisan('ppq:sala-codigo 0300 --buscar="Sala Guardada"')->assertSuccessful();
        $this->assertSame('0300', $salaGuardada->refresh()->codigo);
        $this->assertSame('0260', $salaEnv->refresh()->codigo);
    }

    /** Un .env con «0» o texto quedaba «sin cliente» (`if ($id = config(...))`): sigue así. */
    public function test_un_env_mal_escrito_queda_sin_cliente_en_vez_de_romper(): void
    {
        foreach (['0', 'calleja', '-1'] as $malo) {
            config(['ppq.cliente_default_id' => $malo]);
            $this->assertNull(Ajustes::cliente('ppq.cliente_default_id'));
        }

        config(['ppq.cliente_default_id' => null]);
        $this->assertNull(Ajustes::cliente('ppq.cliente_default_id'));
    }
}
