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

        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
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
        $sv = Pais::where('codigo', 'SV')->firstOrFail();

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

    public function test_jefatura_puede_listar_pero_no_crear(): void
    {
        $jefatura = $this->usuario('jefatura');

        $this->actingAs($jefatura)->get(route('clientes.index'))->assertOk();
        $this->actingAs($jefatura)->get(route('clientes.create'))->assertForbidden();
        $this->actingAs($jefatura)->post(route('clientes.store'), $this->datosClienteValido())->assertForbidden();

        $this->assertDatabaseCount('clientes', 0);
    }

    public function test_contabilidad_no_puede_crear(): void
    {
        $this->actingAs($this->usuario('contabilidad'))
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

    public function test_facturacion_solo_ve_no_crea(): void
    {
        // Nueva política: facturación es SOLO lectura en clientes (la gestión es admin).
        $facturacion = $this->usuario('facturacion');

        $this->actingAs($facturacion)->get(route('clientes.index'))->assertOk();
        $this->actingAs($facturacion)->get(route('clientes.create'))->assertForbidden();
        $this->actingAs($facturacion)
            ->post(route('clientes.store'), $this->datosClienteValido())
            ->assertForbidden();

        $this->assertDatabaseCount('clientes', 0);
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

    public function test_jefatura_no_puede_eliminar(): void
    {
        $cliente = Cliente::factory()->create();

        $this->actingAs($this->usuario('jefatura'))
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

    /** Datos de un contribuyente válido (exige NRC, NIT, actividad y ubicación). */
    private function datosContribuyenteValido(array $override = []): array
    {
        return $this->datosClienteValido(array_merge([
            'tipo_cliente' => 'contribuyente',
            'tipo_persona' => 'juridica',
            'tipo_documento' => '36',
            'num_documento' => '0614-010101-101-1',
            'nrc' => '123456-7',
            'actividad_economica_id' => \App\Models\ActividadEconomica::firstOrFail()->id,
            'nombre' => 'Distribuidora Contribuyente, S.A. de C.V.',
        ], $override));
    }

    public function test_consumidor_final_se_crea_sin_departamento_ni_municipio(): void
    {
        $datos = $this->datosClienteValido(['nombre' => 'Cliente Rápido']);
        unset($datos['departamento_id'], $datos['municipio_id']);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $datos)
            ->assertRedirect();

        $this->assertDatabaseHas('clientes', [
            'nombre' => 'Cliente Rápido',
            'departamento_id' => null,
            'municipio_id' => null,
        ]);
    }

    public function test_contribuyente_marcado_sin_salas_falla_sin_ubicacion(): void
    {
        // Sin salas, la ubicación del propio cliente es la fiscal: se exige.
        $datos = $this->datosContribuyenteValido(['sin_salas' => '1']);
        unset($datos['departamento_id'], $datos['municipio_id']);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $datos)
            ->assertSessionHasErrors(['departamento_id', 'municipio_id']);

        $this->assertDatabaseCount('clientes', 0);
    }

    public function test_contribuyente_con_guardar_y_sala_se_crea_sin_ubicacion(): void
    {
        // La ubicación se va a cargar en la sala, no en el cliente.
        $datos = $this->datosContribuyenteValido([
            'nombre' => 'Contribuyente Con Salas',
            'accion' => 'guardar_y_sala',
        ]);
        unset($datos['departamento_id'], $datos['municipio_id']);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $datos)
            ->assertSessionDoesntHaveErrors();

        $cliente = Cliente::where('nombre', 'Contribuyente Con Salas')->firstOrFail();

        $this->assertNull($cliente->departamento_id);
        $this->assertNull($cliente->municipio_id);
    }

    public function test_contribuyente_sin_marcar_sin_salas_no_exige_ubicacion(): void
    {
        $datos = $this->datosContribuyenteValido(['nombre' => 'Contribuyente Sin Marcar', 'sin_salas' => '0']);
        unset($datos['departamento_id'], $datos['municipio_id']);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $datos)
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('clientes', ['nombre' => 'Contribuyente Sin Marcar', 'departamento_id' => null]);
    }

    public function test_sin_salas_no_se_persiste_como_atributo(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosContribuyenteValido([
                'nombre' => 'Contribuyente Sin Salas',
                'sin_salas' => '1',
            ]))->assertSessionDoesntHaveErrors();

        $cliente = Cliente::where('nombre', 'Contribuyente Sin Salas')->firstOrFail();

        // No existe la columna: el campo es solo del formulario.
        $this->assertArrayNotHasKey('sin_salas', $cliente->getAttributes());
    }

    // --- Contacto y orden de compra: por sala; en el cliente solo cuando aplica ---

    public function test_alta_de_cliente_ya_no_precarga_telefono_por_defecto(): void
    {
        // El 77777777 se movió al alta de sala; el cliente ya no lo sugiere.
        $this->actingAs($this->usuario('administrador'))
            ->get(route('clientes.create'))
            ->assertOk()
            ->assertDontSee('77777777');
    }

    public function test_cliente_con_salas_se_crea_sin_contacto_ni_orden_compra(): void
    {
        // Alta con "Guardar y agregar primera sala": contacto y OC van en la sala.
        $datos = $this->datosContribuyenteValido([
            'nombre' => 'Contribuyente Delegado',
            'accion' => 'guardar_y_sala',
        ]);
        unset($datos['departamento_id'], $datos['municipio_id'], $datos['correo'], $datos['telefono'], $datos['requiere_orden_compra']);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $datos)
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('clientes', [
            'nombre' => 'Contribuyente Delegado',
            'correo' => null,
            'telefono' => null,
            'requiere_orden_compra' => 0,
        ]);
    }

    public function test_cliente_sin_salas_muestra_y_guarda_contacto_y_orden_compra(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosContribuyenteValido([
                'nombre' => 'Contribuyente Directo',
                'sin_salas' => '1',
                'correo' => 'directo@cliente.sv',
                'telefono' => '2250-1234',
                'requiere_orden_compra' => '1',
                'etiqueta_orden_compra' => 'No. OC',
            ]))->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('clientes', [
            'nombre' => 'Contribuyente Directo',
            'correo' => 'directo@cliente.sv',
            'telefono' => '2250-1234',
            'requiere_orden_compra' => 1,
            'etiqueta_orden_compra' => 'No. OC',
        ]);
    }

    public function test_cliente_sin_salas_con_contacto_muestra_los_campos_al_editar(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create([
            'telefono' => '2250-7777',
            'correo' => 'heredado@cliente.sv',
        ]);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('clientes.edit', $cliente))
            ->assertOk()
            ->assertSee('2250-7777')
            ->assertSee('heredado@cliente.sv');
    }

    public function test_telefono_enviado_manualmente_se_conserva(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosClienteValido([
                'nombre' => 'Cliente Con Telefono',
                'telefono' => '2250-1234',
            ]))->assertRedirect();

        $this->assertDatabaseHas('clientes', ['nombre' => 'Cliente Con Telefono', 'telefono' => '2250-1234']);
    }

    public function test_edicion_no_sobreescribe_el_telefono_existente(): void
    {
        $cliente = Cliente::factory()->create(['telefono' => '2250-9999']);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('clientes.edit', $cliente))
            ->assertOk()
            ->assertSee('2250-9999')
            ->assertDontSee('77777777');
    }

    // --- Nombre comercial: fuera del alta, visible solo por compatibilidad ---

    public function test_alta_no_pide_nombre_comercial(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('clientes.create'))
            ->assertOk()
            ->assertDontSee('Nombre comercial');
    }

    public function test_cliente_antiguo_con_nombre_comercial_lo_muestra_y_no_lo_pierde(): void
    {
        $cliente = Cliente::factory()->create([
            'nombre' => 'Cliente Heredado',
            'nombre_comercial' => 'La Tiendita de Siempre',
        ]);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('clientes.edit', $cliente))
            ->assertOk()
            ->assertSee('Compatibilidad')
            ->assertSee('La Tiendita de Siempre');

        // Guardar sin enviar el campo no puede borrar la columna.
        $datos = $this->datosClienteValido(['nombre' => 'Cliente Heredado']);
        unset($datos['nombre_comercial']);

        $this->actingAs($this->usuario('administrador'))
            ->put(route('clientes.update', $cliente), $datos)
            ->assertRedirect();

        $this->assertDatabaseHas('clientes', [
            'id' => $cliente->id,
            'nombre_comercial' => 'La Tiendita de Siempre',
        ]);
    }

    // --- Bloque de ubicación: abierto según el estado real de salas ---

    public function test_alta_no_marca_sin_salas_ni_abre_la_ubicacion(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('clientes.create'))
            ->assertOk()
            ->assertSee('sinSalas: false', false)
            ->assertSee('clienteSinSalas: false', false);
    }

    public function test_editar_cliente_sin_salas_abre_la_ubicacion_sin_marcar_el_checkbox(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('clientes.edit', $cliente))
            ->assertOk()
            ->assertSee('clienteSinSalas: true', false)
            // El checkbox sigue siendo una decisión explícita: no se premarca.
            ->assertSee('sinSalas: false', false);
    }

    public function test_editar_cliente_con_salas_no_abre_la_ubicacion(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        \App\Models\ClienteSucursal::factory()->create(['cliente_id' => $cliente->id]);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('clientes.edit', $cliente))
            ->assertOk()
            ->assertSee('clienteSinSalas: false', false);
    }

    public function test_contribuyente_sin_salas_ni_ubicacion_muestra_aviso_bloqueante(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create([
            'departamento_id' => null,
            'municipio_id' => null,
        ]);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('clientes.show', $cliente))
            ->assertOk()
            ->assertSee('Este cliente todavía no puede facturar.');
    }

    public function test_guarda_condicion_de_pago(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosClienteValido([
                'nombre' => 'Cliente A Crédito',
                'condicion_operacion_default' => '2', // Crédito (CAT-016)
            ]))->assertRedirect();

        $this->assertDatabaseHas('clientes', [
            'nombre' => 'Cliente A Crédito',
            'condicion_operacion_default' => 2,
        ]);
    }

    public function test_condicion_de_pago_es_opcional(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosClienteValido([
                'nombre' => 'Cliente Sin Condicion',
                'condicion_operacion_default' => '',
            ]))->assertRedirect();

        $this->assertDatabaseHas('clientes', [
            'nombre' => 'Cliente Sin Condicion',
            'condicion_operacion_default' => null,
        ]);
    }

    public function test_condicion_de_pago_invalida_se_rechaza(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosClienteValido([
                'nombre' => 'Cliente Condicion Rara',
                'condicion_operacion_default' => '9', // fuera de CAT-016
            ]))->assertSessionHasErrors('condicion_operacion_default');

        $this->assertDatabaseMissing('clientes', ['nombre' => 'Cliente Condicion Rara']);
    }

    public function test_guarda_codigo_interno(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosClienteValido([
                'nombre' => 'Cliente Con Codigo',
                'codigo' => 'CLI-001',
            ]))->assertRedirect();

        $this->assertDatabaseHas('clientes', ['nombre' => 'Cliente Con Codigo', 'codigo' => 'CLI-001']);
    }

    public function test_codigo_interno_duplicado_se_rechaza(): void
    {
        Cliente::factory()->create(['codigo' => 'CLI-001']);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosClienteValido([
                'nombre' => 'Cliente Codigo Repetido',
                'codigo' => 'CLI-001',
            ]))->assertSessionHasErrors('codigo');

        $this->assertDatabaseMissing('clientes', ['nombre' => 'Cliente Codigo Repetido']);
    }

    public function test_guardar_y_sala_redirige_al_formulario_de_sala(): void
    {
        $respuesta = $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosContribuyenteValido([
                'nombre' => 'Cliente Con Salas',
                'accion' => 'guardar_y_sala',
            ]));

        $cliente = Cliente::where('nombre', 'Cliente Con Salas')->firstOrFail();

        $respuesta
            ->assertRedirect(route('clientes.sucursales.create', $cliente))
            ->assertSessionHas('status', 'Cliente creado. Agregue la primera sala.');
    }

    public function test_guardar_normal_sigue_redirigiendo_a_la_ficha(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->post(route('clientes.store'), $this->datosClienteValido([
                'nombre' => 'Cliente Normal',
                'accion' => 'guardar',
            ]))
            ->assertRedirect(route('clientes.show', Cliente::where('nombre', 'Cliente Normal')->firstOrFail()));
    }

    public function test_editar_cliente_existente_conserva_sus_datos(): void
    {
        $sansal = Departamento::where('codigo', '06')->firstOrFail();
        $muni = Municipio::where('departamento_id', $sansal->id)->where('nombre', 'San Salvador')->firstOrFail();

        $cliente = Cliente::factory()->create([
            'nombre' => 'Cliente Viejo',
            'codigo' => 'VIEJO-1',
            'departamento_id' => $sansal->id,
            'municipio_id' => $muni->id,
            'direccion' => 'Calle Vieja #1',
            'complemento_direccion' => 'Frente al parque',
            'contacto_principal' => 'Doña Marta',
            'observaciones' => 'Cliente histórico',
            'observaciones_facturacion' => 'Factura al cierre de mes',
            'condicion_operacion_default' => 2,
        ]);

        // El formulario de edición muestra todo lo cargado, aunque viva en el
        // bloque plegable de datos adicionales.
        $this->actingAs($this->usuario('administrador'))
            ->get(route('clientes.edit', $cliente))
            ->assertOk()
            ->assertSee('VIEJO-1')
            ->assertSee('Calle Vieja #1')
            ->assertSee('Frente al parque')
            ->assertSee('Doña Marta', false)
            ->assertSee('Cliente histórico', false)
            ->assertSee('Factura al cierre de mes');

        // Y guardar un cambio de nombre no pierde el resto.
        $this->actingAs($this->usuario('administrador'))
            ->put(route('clientes.update', $cliente), $this->datosClienteValido([
                'nombre' => 'Cliente Renombrado',
                'codigo' => 'VIEJO-1',
                'direccion' => 'Calle Vieja #1',
                'complemento_direccion' => 'Frente al parque',
                'contacto_principal' => 'Doña Marta',
                'observaciones' => 'Cliente histórico',
                'observaciones_facturacion' => 'Factura al cierre de mes',
                'condicion_operacion_default' => '2',
            ]))->assertRedirect(route('clientes.show', $cliente));

        $this->assertDatabaseHas('clientes', [
            'id' => $cliente->id,
            'nombre' => 'Cliente Renombrado',
            'codigo' => 'VIEJO-1',
            'direccion' => 'Calle Vieja #1',
            'complemento_direccion' => 'Frente al parque',
            'contacto_principal' => 'Doña Marta',
            'observaciones' => 'Cliente histórico',
            'observaciones_facturacion' => 'Factura al cierre de mes',
            'condicion_operacion_default' => 2,
        ]);
    }

    public function test_contribuyente_sin_salas_muestra_aviso_de_primera_sala(): void
    {
        $admin = $this->usuario('administrador');
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->actingAs($admin)->get(route('clientes.show', $cliente))
            ->assertOk()
            ->assertSee('Agregar la primera sala');

        \App\Models\ClienteSucursal::factory()->create(['cliente_id' => $cliente->id]);

        $this->actingAs($admin)->get(route('clientes.show', $cliente))
            ->assertOk()
            ->assertDontSee('Agregar la primera sala');
    }

    public function test_consumidor_final_sin_salas_no_muestra_el_aviso(): void
    {
        $cliente = Cliente::factory()->create(); // consumidor final

        $this->actingAs($this->usuario('administrador'))
            ->get(route('clientes.show', $cliente))
            ->assertOk()
            ->assertDontSee('Agregar la primera sala');
    }

    public function test_formulario_de_cliente_muestra_los_tres_bloques(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('clientes.create'))
            ->assertOk()
            ->assertSee('Identificación')
            ->assertSee('Reglas de facturación')
            ->assertSee('Datos adicionales')
            ->assertSee('Condición de pago')
            ->assertSee('Código interno')
            ->assertSee('Guardar cliente')
            ->assertSee('Guardar y agregar primera sala');
    }

    public function test_formulario_de_edicion_no_ofrece_guardar_y_sala(): void
    {
        $cliente = Cliente::factory()->create();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('clientes.edit', $cliente))
            ->assertOk()
            ->assertSee('Guardar cambios')
            ->assertDontSee('Guardar y agregar primera sala');
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
