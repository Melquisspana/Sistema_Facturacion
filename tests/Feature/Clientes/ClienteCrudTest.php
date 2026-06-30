<?php

namespace Tests\Feature\Clientes;

use App\Models\Cliente;
use App\Models\Departamento;
use App\Models\Municipio;
use App\Models\Pais;
use App\Models\User;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ClienteCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CatalogosMhSeeder::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function datosClienteValido(array $override = []): array
    {
        $sansal = Departamento::where('codigo', '06')->firstOrFail();
        $muni = Municipio::where('departamento_id', $sansal->id)->where('nombre', 'San Salvador')->firstOrFail();
        $sv = Pais::where('codigo', '9300')->firstOrFail();

        return array_merge([
            'tipo_cliente' => 'consumidor_final',
            'nombre' => 'Cliente de Prueba',
            'pais_id' => $sv->id,
            'departamento_id' => $sansal->id,
            'municipio_id' => $muni->id,
            'activo' => '1',
        ], $override);
    }

    public function test_invitado_es_redirigido_al_login(): void
    {
        $this->get(route('clientes.index'))->assertRedirect('/login');
    }

    public function test_consulta_puede_listar_pero_no_crear(): void
    {
        $consulta = $this->usuario('consulta');

        $this->actingAs($consulta)->get(route('clientes.index'))->assertOk();
        $this->actingAs($consulta)->get(route('clientes.create'))->assertForbidden();
        $this->actingAs($consulta)->post(route('clientes.store'), $this->datosClienteValido())->assertForbidden();

        $this->assertDatabaseCount('clientes', 0);
    }

    public function test_contador_no_puede_crear(): void
    {
        $this->actingAs($this->usuario('contador'))
            ->post(route('clientes.store'), $this->datosClienteValido())
            ->assertForbidden();
    }

    public function test_administrador_crea_cliente_y_se_audita(): void
    {
        $admin = $this->usuario('administrador');

        $response = $this->actingAs($admin)->post(route('clientes.store'), $this->datosClienteValido([
            'nombre' => 'Pastelería El Buen Gusto',
        ]));

        $cliente = Cliente::firstOrFail();
        $response->assertRedirect(route('clientes.show', $cliente));

        $this->assertDatabaseHas('clientes', ['nombre' => 'Pastelería El Buen Gusto']);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'cliente',
            'description' => 'creó el cliente',
            'subject_type' => Cliente::class,
            'subject_id' => $cliente->id,
            'causer_id' => $admin->id,
        ]);
    }

    public function test_facturacion_puede_crear(): void
    {
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('clientes.store'), $this->datosClienteValido())
            ->assertRedirect();

        $this->assertDatabaseCount('clientes', 1);
    }

    public function test_administrador_edita_cliente(): void
    {
        $admin = $this->usuario('administrador');
        $cliente = Cliente::factory()->create(['nombre' => 'Nombre Viejo']);

        $this->actingAs($admin)->put(route('clientes.update', $cliente), $this->datosClienteValido([
            'nombre' => 'Nombre Nuevo',
        ]))->assertRedirect(route('clientes.show', $cliente));

        $this->assertDatabaseHas('clientes', ['id' => $cliente->id, 'nombre' => 'Nombre Nuevo']);
    }

    public function test_toggle_activo(): void
    {
        $admin = $this->usuario('administrador');
        $cliente = Cliente::factory()->create(['activo' => true]);

        $this->actingAs($admin)->patch(route('clientes.toggle-activo', $cliente))->assertRedirect();

        $this->assertFalse($cliente->fresh()->activo);
    }

    public function test_soft_delete(): void
    {
        $admin = $this->usuario('administrador');
        $cliente = Cliente::factory()->create();

        $this->actingAs($admin)->delete(route('clientes.destroy', $cliente))->assertRedirect(route('clientes.index'));

        $this->assertSoftDeleted('clientes', ['id' => $cliente->id]);
    }

    public function test_consulta_no_puede_eliminar(): void
    {
        $cliente = Cliente::factory()->create();

        $this->actingAs($this->usuario('consulta'))
            ->delete(route('clientes.destroy', $cliente))
            ->assertForbidden();

        $this->assertDatabaseHas('clientes', ['id' => $cliente->id, 'deleted_at' => null]);
    }

    public function test_admin_puede_ver_formularios(): void
    {
        $admin = $this->usuario('administrador');
        $cliente = Cliente::factory()->create();

        $this->actingAs($admin)->get(route('clientes.create'))->assertOk()->assertSee('Tipo de cliente');
        $this->actingAs($admin)->get(route('clientes.edit', $cliente))->assertOk();
        $this->actingAs($admin)->get(route('clientes.show', $cliente))->assertOk()->assertSee('Historial de auditoría');
    }

    public function test_orden_compra_usa_etiqueta_por_defecto_si_viene_vacia(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosClienteValido([
                'nombre' => 'Calleja / Super Selectos',
                'requiere_orden_compra' => '1',
                'etiqueta_orden_compra' => '',
            ]))->assertRedirect();

        $this->assertDatabaseHas('clientes', [
            'nombre' => 'Calleja / Super Selectos',
            'requiere_orden_compra' => 1,
            'etiqueta_orden_compra' => 'Orden de compra',
        ]);
    }

    public function test_orden_compra_conserva_etiqueta_personalizada(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosClienteValido([
                'requiere_orden_compra' => '1',
                'etiqueta_orden_compra' => 'No. de OC',
            ]))->assertRedirect();

        $this->assertDatabaseHas('clientes', [
            'requiere_orden_compra' => 1,
            'etiqueta_orden_compra' => 'No. de OC',
        ]);
    }

    public function test_sin_orden_compra_no_guarda_etiqueta(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosClienteValido([
                'requiere_orden_compra' => '0',
                'etiqueta_orden_compra' => 'No. de OC',
            ]))->assertRedirect();

        $this->assertDatabaseHas('clientes', [
            'requiere_orden_compra' => 0,
            'etiqueta_orden_compra' => null,
        ]);
    }

    public function test_tamanio_grande_marca_agente_retencion(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosClienteValido([
                'nombre' => 'Mayorista Grande',
                'tamanio_contribuyente' => 'grande',
            ]))->assertRedirect();

        $this->assertDatabaseHas('clientes', [
            'nombre' => 'Mayorista Grande',
            'tamanio_contribuyente' => 'grande',
            'es_agente_retencion' => 1,
        ]);
    }

    public function test_tamanio_pequeno_no_marca_agente_retencion(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosClienteValido([
                'nombre' => 'Tiendita Pequeña',
                'tamanio_contribuyente' => 'pequeno',
            ]))->assertRedirect();

        $this->assertDatabaseHas('clientes', [
            'nombre' => 'Tiendita Pequeña',
            'tamanio_contribuyente' => 'pequeno',
            'es_agente_retencion' => 0,
        ]);
    }

    public function test_tamanio_mediano_no_marca_agente_retencion(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosClienteValido([
                'nombre' => 'Comercial Mediana',
                'tamanio_contribuyente' => 'mediano',
            ]))->assertRedirect();

        $this->assertDatabaseHas('clientes', [
            'nombre' => 'Comercial Mediana',
            'tamanio_contribuyente' => 'mediano',
            'es_agente_retencion' => 0,
        ]);
    }

    public function test_request_no_puede_forzar_agente_retencion_distinto_al_tamanio(): void
    {
        $admin = $this->usuario('administrador');

        // Mediano intentando forzar agente=1 → se ignora, queda false.
        $this->actingAs($admin)->post(route('clientes.store'), $this->datosClienteValido([
            'nombre' => 'Mediano Tramposo',
            'tamanio_contribuyente' => 'mediano',
            'es_agente_retencion' => '1',
        ]))->assertRedirect();
        $this->assertDatabaseHas('clientes', ['nombre' => 'Mediano Tramposo', 'es_agente_retencion' => 0]);

        // Grande intentando forzar agente=0 → se ignora, queda true.
        $this->actingAs($admin)->post(route('clientes.store'), $this->datosClienteValido([
            'nombre' => 'Grande Tramposo',
            'tamanio_contribuyente' => 'grande',
            'es_agente_retencion' => '0',
        ]))->assertRedirect();
        $this->assertDatabaseHas('clientes', ['nombre' => 'Grande Tramposo', 'es_agente_retencion' => 1]);
    }

    public function test_guarda_descuento_global_default_del_cliente(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosClienteValido([
                'nombre' => 'Cliente Con Descuento',
                'descuento_global_default' => '5.50',
            ]))->assertRedirect();

        $this->assertDatabaseHas('clientes', [
            'nombre' => 'Cliente Con Descuento',
            'descuento_global_default' => '5.50',
        ]);
    }

    public function test_no_permite_descuento_negativo(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosClienteValido([
                'nombre' => 'Cliente Negativo',
                'descuento_global_default' => '-1',
            ]))->assertSessionHasErrors('descuento_global_default');

        $this->assertDatabaseMissing('clientes', ['nombre' => 'Cliente Negativo']);
    }

    public function test_no_permite_descuento_mayor_a_100(): void
    {
        // El descuento es un PORCENTAJE: máximo 100%.
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosClienteValido([
                'nombre' => 'Cliente Sobre 100',
                'descuento_global_default' => '101',
            ]))->assertSessionHasErrors('descuento_global_default');

        $this->assertDatabaseMissing('clientes', ['nombre' => 'Cliente Sobre 100']);
    }

    public function test_busqueda_por_nombre(): void
    {
        $admin = $this->usuario('administrador');
        Cliente::factory()->create(['nombre' => 'Distribuidora Alfa']);
        Cliente::factory()->create(['nombre' => 'Comercial Beta']);

        $this->actingAs($admin)->get(route('clientes.index', ['q' => 'Alfa']))
            ->assertOk()
            ->assertSee('Distribuidora Alfa')
            ->assertDontSee('Comercial Beta');
    }
}
