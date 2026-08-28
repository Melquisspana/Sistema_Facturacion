<?php

namespace Tests\Feature\Clientes;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Distrito;
use App\Models\User;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DIRECTORIO DE CLIENTES Y SALAS.
 *
 * Lo que se fija aquí es que encontrar un cliente y agregarle una sala siga siendo
 * rápido cuando la base tenga clientes de verdad: que el buscador entre también por
 * los datos de la SALA, que los conteos no cuesten una consulta por fila, y que el
 * camino «cliente → agregar sala» esté a un clic desde el listado.
 *
 * Ninguna prueba toca autorizaciones nuevas: se comprueba que las de siempre
 * (clientes.ver / clientes.gestionar, vía ClientePolicy) sigan mandando.
 */
class ClienteDirectorioTest extends TestCase
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

    private function admin(): User
    {
        return $this->usuario('administrador');
    }

    /**
     * Alta de sala válida. La ubicación administrativa 2024 (departamento + distrito)
     * es obligatoria por requisito legal, así que un POST sin ella se rechaza y no
     * probaría nada de lo que interesa acá.
     */
    private function datosSala(array $override = []): array
    {
        $distrito = Distrito::where('nombre', 'Olocuilta')->first() ?? Distrito::firstOrFail();

        return array_merge([
            'nombre' => 'Sala de Prueba',
            'departamento_id' => $distrito->departamento_id,
            'distrito_id' => $distrito->id,
            'activo' => '1',
            'requiere_orden_compra' => '',   // hereda del cliente
        ], $override);
    }

    // ============================================================ BÚSQUEDA

    public function test_busqueda_por_nombre_y_por_documento(): void
    {
        $admin = $this->admin();
        Cliente::factory()->create(['nombre' => 'Distribuidora Alfa', 'num_documento' => '0614-111111-001-1']);
        Cliente::factory()->create(['nombre' => 'Comercial Beta', 'num_documento' => '0614-222222-002-2']);

        $this->actingAs($admin)->get(route('clientes.index', ['q' => 'Alfa']))
            ->assertOk()
            ->assertSee('Distribuidora Alfa')
            ->assertDontSee('Comercial Beta');

        // Y por el documento, que es como llega el dato desde contabilidad.
        $this->actingAs($admin)->get(route('clientes.index', ['q' => '0614-222222']))
            ->assertOk()
            ->assertSee('Comercial Beta')
            ->assertDontSee('Distribuidora Alfa');
    }

    /** Código interno, NRC y correo son las otras tres puertas de entrada. */
    public function test_busqueda_por_codigo_nrc_y_correo(): void
    {
        $admin = $this->admin();
        Cliente::factory()->create([
            'nombre' => 'Super Selectos', 'codigo' => 'CL-0099',
            'nrc' => '987654-3', 'correo' => 'pagos@selectos.test',
        ]);
        Cliente::factory()->create(['nombre' => 'Otro Cliente', 'codigo' => 'CL-0001', 'nrc' => '111111-1']);

        foreach (['CL-0099', '987654-3', 'pagos@selectos.test'] as $termino) {
            $this->actingAs($admin)->get(route('clientes.index', ['q' => $termino]))
                ->assertOk()
                ->assertSee('Super Selectos')
                ->assertDontSee('Otro Cliente');
        }
    }

    /**
     * La búsqueda por SALA es la que faltaba: quien atiende conoce «Sala Metrocentro»,
     * no la razón social que la factura.
     */
    public function test_busqueda_por_nombre_de_sala_encuentra_al_cliente(): void
    {
        $admin = $this->admin();
        $calleja = Cliente::factory()->contribuyente()->create(['nombre' => 'Operadora del Sur SA de CV']);
        ClienteSucursal::factory()->for($calleja)->create(['nombre' => 'Sala Metrocentro', 'codigo' => 'MTC-01']);
        Cliente::factory()->create(['nombre' => 'Cliente Sin Relacion']);

        $this->actingAs($admin)->get(route('clientes.index', ['q' => 'Metrocentro']))
            ->assertOk()
            ->assertSee('Operadora del Sur SA de CV')
            ->assertDontSee('Cliente Sin Relacion');
    }

    public function test_busqueda_por_codigo_de_sala_encuentra_al_cliente(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Operadora del Norte SA']);
        ClienteSucursal::factory()->for($cliente)->create(['nombre' => 'Sala Norte', 'codigo' => 'NRT-77']);
        Cliente::factory()->create(['nombre' => 'Cliente Sin Relacion']);

        $this->actingAs($admin)->get(route('clientes.index', ['q' => 'NRT-77']))
            ->assertOk()
            ->assertSee('Operadora del Norte SA')
            ->assertDontSee('Cliente Sin Relacion');
    }

    /**
     * Si el cliente apareció por una sala, la fila tiene que decir por cuál. Sin eso,
     * el resultado parece un error del buscador.
     */
    public function test_la_coincidencia_por_sala_muestra_su_contexto(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Operadora Central SA']);
        ClienteSucursal::factory()->for($cliente)->create(['nombre' => 'Sala Metrocentro', 'codigo' => 'MTC-01']);
        ClienteSucursal::factory()->for($cliente)->create(['nombre' => 'Bodega Soyapango', 'codigo' => 'SOY-02']);

        $this->actingAs($admin)->get(route('clientes.index', ['q' => 'Metrocentro']))
            ->assertOk()
            ->assertSee('Coincide en')
            ->assertSee('Sala Metrocentro')
            // Solo la sala que casó: la otra no explica nada y sería ruido.
            ->assertDontSee('Bodega Soyapango');
    }

    /** Cuando la coincidencia es por datos del cliente, no se inventa contexto de sala. */
    public function test_sin_coincidencia_de_sala_no_se_muestra_el_contexto(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Distribuidora Omega']);
        ClienteSucursal::factory()->for($cliente)->create(['nombre' => 'Sala Uno', 'codigo' => 'U-1']);

        $this->actingAs($admin)->get(route('clientes.index', ['q' => 'Omega']))
            ->assertOk()
            ->assertSee('Distribuidora Omega')
            ->assertDontSee('Coincide en');
    }

    // ============================================================== FILTROS

    /** Sin filtro explícito el directorio muestra ACTIVOS: es sobre los que se opera. */
    public function test_filtro_activos_por_defecto(): void
    {
        $admin = $this->admin();
        Cliente::factory()->create(['nombre' => 'Cliente Vigente', 'activo' => true]);
        Cliente::factory()->create(['nombre' => 'Cliente Retirado', 'activo' => false]);

        $this->actingAs($admin)->get(route('clientes.index'))
            ->assertOk()
            ->assertSee('Cliente Vigente')
            ->assertDontSee('Cliente Retirado');
    }

    public function test_filtro_todos_incluye_los_inactivos(): void
    {
        $admin = $this->admin();
        Cliente::factory()->create(['nombre' => 'Cliente Vigente', 'activo' => true]);
        Cliente::factory()->create(['nombre' => 'Cliente Retirado', 'activo' => false]);

        $this->actingAs($admin)->get(route('clientes.index', ['estado' => 'todos']))
            ->assertOk()
            ->assertSee('Cliente Vigente')
            ->assertSee('Cliente Retirado');
    }

    public function test_filtro_inactivos_deja_solo_los_inactivos(): void
    {
        $admin = $this->admin();
        Cliente::factory()->create(['nombre' => 'Cliente Vigente', 'activo' => true]);
        Cliente::factory()->create(['nombre' => 'Cliente Retirado', 'activo' => false]);

        $this->actingAs($admin)->get(route('clientes.index', ['estado' => 'inactivos']))
            ->assertOk()
            ->assertSee('Cliente Retirado')
            ->assertDontSee('Cliente Vigente');
    }

    public function test_filtro_sin_salas(): void
    {
        $admin = $this->admin();
        $conSala = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente Con Sala']);
        ClienteSucursal::factory()->for($conSala)->create(['nombre' => 'Sala Unica']);
        Cliente::factory()->create(['nombre' => 'Cliente Pelado']);

        $this->actingAs($admin)->get(route('clientes.index', ['salas' => 'sin']))
            ->assertOk()
            ->assertSee('Cliente Pelado')
            ->assertDontSee('Cliente Con Sala');
    }

    public function test_filtro_con_salas(): void
    {
        $admin = $this->admin();
        $conSala = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente Con Sala']);
        ClienteSucursal::factory()->for($conSala)->create(['nombre' => 'Sala Unica']);
        Cliente::factory()->create(['nombre' => 'Cliente Pelado']);

        $this->actingAs($admin)->get(route('clientes.index', ['salas' => 'con']))
            ->assertOk()
            ->assertSee('Cliente Con Sala')
            ->assertDontSee('Cliente Pelado');
    }

    /**
     * Una sala BORRADA (soft delete) no cuenta como sala: el cliente vuelve a estar
     * en «Sin salas», que es donde alguien tiene que ir a arreglarlo.
     */
    public function test_una_sala_borrada_no_cuenta_para_los_filtros(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente Sin Salas Vivas']);
        $sala = ClienteSucursal::factory()->for($cliente)->create(['nombre' => 'Sala Cerrada']);
        $sala->delete();

        $this->actingAs($admin)->get(route('clientes.index', ['salas' => 'sin']))
            ->assertOk()
            ->assertSee('Cliente Sin Salas Vivas');
    }

    /** El filtro de tipo de cliente, que ya existía, se combina con los rápidos. */
    public function test_el_filtro_de_tipo_de_cliente_sigue_funcionando_y_se_combina(): void
    {
        $admin = $this->admin();
        Cliente::factory()->contribuyente()->create(['nombre' => 'Contribuyente Uno']);
        Cliente::factory()->create(['nombre' => 'Consumidor Uno']);   // consumidor_final
        Cliente::factory()->contribuyente()->create(['nombre' => 'Contribuyente Retirado', 'activo' => false]);

        $this->actingAs($admin)->get(route('clientes.index', ['tipo_cliente' => 'contribuyente']))
            ->assertOk()
            ->assertSee('Contribuyente Uno')
            ->assertDontSee('Consumidor Uno')
            // Se combina con el filtro rápido por defecto (activos).
            ->assertDontSee('Contribuyente Retirado');

        $this->actingAs($admin)->get(route('clientes.index', ['tipo_cliente' => 'contribuyente', 'estado' => 'todos']))
            ->assertOk()
            ->assertSee('Contribuyente Retirado');
    }

    /** Compatibilidad: un enlace guardado con ?activo=0 sigue significando lo mismo. */
    public function test_el_parametro_activo_anterior_se_sigue_entendiendo(): void
    {
        $admin = $this->admin();
        Cliente::factory()->create(['nombre' => 'Cliente Vigente', 'activo' => true]);
        Cliente::factory()->create(['nombre' => 'Cliente Retirado', 'activo' => false]);

        $this->actingAs($admin)->get(route('clientes.index', ['activo' => '0']))
            ->assertOk()
            ->assertSee('Cliente Retirado')
            ->assertDontSee('Cliente Vigente');

        // El «Todos» del select viejo mandaba activo='' y debe seguir mostrando todo.
        $this->actingAs($admin)->get(route('clientes.index', ['activo' => '']))
            ->assertOk()
            ->assertSee('Cliente Vigente')
            ->assertSee('Cliente Retirado');
    }

    // ============================================== COMBINACIONES DE FILTROS

    /**
     * Los dos grupos son independientes y se cruzan. Este es el escenario que motivó
     * separarlos: «activos que todavía no tienen sala» es el pendiente de trabajo, y
     * con un grupo único de pastillas había que elegir entre ver los activos o ver los
     * que no tienen sala, nunca la intersección.
     */
    public function test_activos_mas_sin_salas_da_los_pendientes_de_agregar_sala(): void
    {
        $admin = $this->admin();

        $pendiente = Cliente::factory()->create(['nombre' => 'Activo Sin Sala', 'activo' => true]);
        $atendido = Cliente::factory()->contribuyente()->create(['nombre' => 'Activo Con Sala', 'activo' => true]);
        ClienteSucursal::factory()->for($atendido)->create();
        Cliente::factory()->create(['nombre' => 'Inactivo Sin Sala', 'activo' => false]);

        $this->actingAs($admin)->get(route('clientes.index', ['estado' => 'activos', 'salas' => 'sin']))
            ->assertOk()
            ->assertSee('Activo Sin Sala')
            ->assertDontSee('Activo Con Sala')
            // El inactivo NO entra: el estado sigue filtrando aunque se pida «sin salas».
            ->assertDontSee('Inactivo Sin Sala');

        $this->assertSame($pendiente->id, $pendiente->fresh()->id); // el filtro no muta nada
    }

    /** Y ese cruce es también el que sale por defecto al elegir solo «Sin salas». */
    public function test_sin_salas_sin_tocar_el_estado_sigue_siendo_solo_activos(): void
    {
        $admin = $this->admin();
        Cliente::factory()->create(['nombre' => 'Activo Sin Sala', 'activo' => true]);
        Cliente::factory()->create(['nombre' => 'Inactivo Sin Sala', 'activo' => false]);

        $this->actingAs($admin)->get(route('clientes.index', ['salas' => 'sin']))
            ->assertOk()
            ->assertSee('Activo Sin Sala')
            ->assertDontSee('Inactivo Sin Sala');
    }

    /** Los inactivos sin sala solo aparecen si se piden los dos a la vez. */
    public function test_inactivos_mas_sin_salas_solo_cuando_se_elige_explicitamente(): void
    {
        $admin = $this->admin();
        Cliente::factory()->create(['nombre' => 'Activo Sin Sala', 'activo' => true]);
        Cliente::factory()->create(['nombre' => 'Inactivo Sin Sala', 'activo' => false]);
        $inactivoConSala = Cliente::factory()->contribuyente()->create(['nombre' => 'Inactivo Con Sala', 'activo' => false]);
        ClienteSucursal::factory()->for($inactivoConSala)->create();

        $this->actingAs($admin)->get(route('clientes.index', ['estado' => 'inactivos', 'salas' => 'sin']))
            ->assertOk()
            ->assertSee('Inactivo Sin Sala')
            ->assertDontSee('Activo Sin Sala')
            ->assertDontSee('Inactivo Con Sala');
    }

    public function test_todos_mas_con_salas_cruza_los_dos_estados(): void
    {
        $admin = $this->admin();
        $activo = Cliente::factory()->contribuyente()->create(['nombre' => 'Activo Con Sala', 'activo' => true]);
        ClienteSucursal::factory()->for($activo)->create();
        $inactivo = Cliente::factory()->contribuyente()->create(['nombre' => 'Inactivo Con Sala', 'activo' => false]);
        ClienteSucursal::factory()->for($inactivo)->create();
        Cliente::factory()->create(['nombre' => 'Activo Sin Sala', 'activo' => true]);

        $this->actingAs($admin)->get(route('clientes.index', ['estado' => 'todos', 'salas' => 'con']))
            ->assertOk()
            ->assertSee('Activo Con Sala')
            ->assertSee('Inactivo Con Sala')
            ->assertDontSee('Activo Sin Sala');
    }

    /** Al entrar sin nada: activos, con cualquier cantidad de salas. */
    public function test_al_entrar_se_ven_los_activos_con_y_sin_salas(): void
    {
        $admin = $this->admin();
        $conSala = Cliente::factory()->contribuyente()->create(['nombre' => 'Activo Con Sala', 'activo' => true]);
        ClienteSucursal::factory()->for($conSala)->create();
        Cliente::factory()->create(['nombre' => 'Activo Sin Sala', 'activo' => true]);
        Cliente::factory()->create(['nombre' => 'Retirado Cualquiera', 'activo' => false]);

        $this->actingAs($admin)->get(route('clientes.index'))
            ->assertOk()
            ->assertSee('Activo Con Sala')
            ->assertSee('Activo Sin Sala')
            ->assertDontSee('Retirado Cualquiera');
    }

    /** Los cuatro filtros a la vez: búsqueda + tipo + estado + salas. */
    public function test_los_cuatro_filtros_se_combinan(): void
    {
        $admin = $this->admin();

        $buscado = Cliente::factory()->contribuyente()->create(['nombre' => 'Operadora Zeta', 'activo' => true]);
        ClienteSucursal::factory()->for($buscado)->create(['nombre' => 'Sala Zeta']);
        // Mismo nombre buscable, pero cada uno falla en un filtro distinto.
        Cliente::factory()->create(['nombre' => 'Operadora Zeta Consumidor', 'activo' => true]);
        $zetaInactiva = Cliente::factory()->contribuyente()->create(['nombre' => 'Operadora Zeta Retirada', 'activo' => false]);
        ClienteSucursal::factory()->for($zetaInactiva)->create();
        Cliente::factory()->contribuyente()->create(['nombre' => 'Operadora Zeta Pelada', 'activo' => true]);

        $this->actingAs($admin)->get(route('clientes.index', [
            'q' => 'Zeta',
            'tipo_cliente' => 'contribuyente',
            'estado' => 'activos',
            'salas' => 'con',
        ]))
            ->assertOk()
            ->assertSee('Operadora Zeta')
            ->assertDontSee('Operadora Zeta Consumidor')
            ->assertDontSee('Operadora Zeta Retirada')
            ->assertDontSee('Operadora Zeta Pelada');
    }

    /** Cambiar un grupo conserva el otro, la búsqueda y el tipo. */
    public function test_las_pastillas_conservan_el_resto_del_estado(): void
    {
        $admin = $this->admin();
        Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente Cualquiera']);

        $contenido = $this->actingAs($admin)->get(route('clientes.index', [
            'q' => 'Cualquiera',
            'tipo_cliente' => 'contribuyente',
            'estado' => 'todos',
            'salas' => 'con',
        ]))->assertOk()->getContent();

        // El enlace de «Sin salas» cambia solo su grupo y arrastra lo demás.
        $esperado = route('clientes.index', [
            'q' => 'Cualquiera',
            'tipo_cliente' => 'contribuyente',
            'estado' => 'todos',
            'salas' => 'sin',
        ]);
        $this->assertStringContainsString(e($esperado), $contenido);
    }

    /** La pantalla dice en palabras qué está filtrando, y ofrece limpiar. */
    public function test_se_muestran_los_filtros_activos_y_el_enlace_de_limpiar(): void
    {
        $admin = $this->admin();
        Cliente::factory()->create(['nombre' => 'Cliente Cualquiera']);

        // Por defecto: sin lista de filtros y sin «Limpiar» (no hay nada que limpiar).
        $this->actingAs($admin)->get(route('clientes.index'))
            ->assertOk()
            ->assertSee('Mostrando')
            ->assertSee('clientes activos')
            ->assertDontSee('Filtros activos');

        $this->actingAs($admin)->get(route('clientes.index', ['estado' => 'inactivos', 'salas' => 'sin']))
            ->assertOk()
            ->assertSee('Filtros activos')
            ->assertSee('Inactivos · Sin salas')
            ->assertSee('Limpiar');
    }

    /** «Limpiar» apunta al directorio desnudo, que es Activos + Todas. */
    public function test_limpiar_vuelve_a_activos_y_todas(): void
    {
        $admin = $this->admin();
        Cliente::factory()->create(['nombre' => 'Activo Cualquiera', 'activo' => true]);
        Cliente::factory()->create(['nombre' => 'Retirado Cualquiera', 'activo' => false]);

        $this->actingAs($admin)->get(route('clientes.index', ['estado' => 'todos', 'salas' => 'con']))
            ->assertOk()
            ->assertSee('href="'.e(route('clientes.index')).'"', false);

        // Y ese destino es, efectivamente, activos con o sin salas.
        $this->actingAs($admin)->get(route('clientes.index'))
            ->assertOk()
            ->assertSee('Activo Cualquiera')
            ->assertDontSee('Retirado Cualquiera');
    }

    /** Un valor inventado en la URL no rompe nada: cae al valor por defecto. */
    public function test_valores_desconocidos_caen_al_valor_por_defecto(): void
    {
        $admin = $this->admin();
        Cliente::factory()->create(['nombre' => 'Activo Cualquiera', 'activo' => true]);
        Cliente::factory()->create(['nombre' => 'Retirado Cualquiera', 'activo' => false]);

        $this->actingAs($admin)->get(route('clientes.index', ['estado' => 'zzz', 'salas' => 'qqq']))
            ->assertOk()
            ->assertSee('Activo Cualquiera')
            ->assertDontSee('Retirado Cualquiera');
    }

    /** La búsqueda y el filtro sobreviven al paginado (withQueryString). */
    public function test_la_paginacion_conserva_busqueda_y_filtro(): void
    {
        $admin = $this->admin();
        Cliente::factory()->count(20)->create(['nombre' => 'Cliente Masivo', 'activo' => true]);

        $this->actingAs($admin)
            ->get(route('clientes.index', ['q' => 'Masivo', 'estado' => 'todos']))
            ->assertOk()
            ->assertSee('q=Masivo', false)
            ->assertSee('estado=todos', false);
    }

    // =============================================================== CONTEOS

    public function test_conteo_de_salas_totales_y_activas(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente Con Tres Salas']);
        ClienteSucursal::factory()->for($cliente)->count(2)->create(['activo' => true]);
        ClienteSucursal::factory()->for($cliente)->create(['activo' => false]);

        $this->actingAs($admin)->get(route('clientes.index'))
            ->assertOk()
            ->assertSee('3 salas · 2 activas');
    }

    public function test_una_sola_sala_se_dice_en_singular(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente De Una Sala']);
        ClienteSucursal::factory()->for($cliente)->create(['activo' => true]);

        $this->actingAs($admin)->get(route('clientes.index'))
            ->assertOk()
            ->assertSee('1 sala · 1 activa');
    }

    /** Sin salas: aviso claro en la fila, no un cero mudo. */
    public function test_cliente_sin_salas_se_avisa_en_el_listado(): void
    {
        $admin = $this->admin();
        Cliente::factory()->create(['nombre' => 'Cliente Pelado']);

        $this->actingAs($admin)->get(route('clientes.index'))
            ->assertOk()
            ->assertSee('Cliente Pelado')
            // La celda, no la pastilla del filtro: ahora ambas dicen «Sin salas».
            ->assertSee('<span class="text-amber-700">Sin salas</span>', false);
    }

    // ======================================================= ACCESO A SALAS

    /** El acceso directo de la fila apunta a clientes.sucursales.create de ESE cliente. */
    public function test_el_listado_enlaza_directo_a_agregar_sala(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::factory()->create(['nombre' => 'Cliente Directo']);

        $this->actingAs($admin)->get(route('clientes.index'))
            ->assertOk()
            ->assertSee('Agregar sala')
            ->assertSee(route('clientes.sucursales.create', $cliente), false);
    }

    /** Y ese enlace abre, sin pasos intermedios, el formulario del cliente correcto. */
    public function test_el_acceso_directo_abre_el_formulario_del_cliente_correcto(): void
    {
        $admin = $this->admin();
        $correcto = Cliente::factory()->contribuyente()->create(['nombre' => 'Razon Social Correcta SA de CV']);
        Cliente::factory()->contribuyente()->create(['nombre' => 'Razon Social Ajena SA de CV']);

        $this->actingAs($admin)->get(route('clientes.sucursales.create', $correcto))
            ->assertOk()
            ->assertSee('Nueva sala para Razon Social Correcta SA de CV')
            ->assertDontSee('Razon Social Ajena SA de CV');
    }

    /** El formulario de edición también se identifica, y con el nombre fiscal. */
    public function test_el_formulario_de_edicion_identifica_al_cliente(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Razon Social Correcta SA de CV']);
        $sala = ClienteSucursal::factory()->for($cliente)->create(['nombre' => 'Sala Editable']);

        $this->actingAs($admin)->get(route('clientes.sucursales.edit', [$cliente, $sala]))
            ->assertOk()
            ->assertSee('Editar sala — Razon Social Correcta SA de CV');
    }

    /** Al crear, se vuelve a la ficha y la sala nueva queda nombrada y señalada. */
    public function test_al_crear_una_sala_se_vuelve_a_la_ficha_con_la_sala_visible(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->actingAs($admin)
            ->post(route('clientes.sucursales.store', $cliente), $this->datosSala(['nombre' => 'Sala Recien Nacida']))
            ->assertRedirect(route('clientes.show', $cliente))
            ->assertSessionHas('status', 'Sala «Sala Recien Nacida» creada correctamente.')
            ->assertSessionHas('sala_destacada');

        $this->actingAs($admin)->followingRedirects()
            ->post(route('clientes.sucursales.store', $cliente), $this->datosSala(['nombre' => 'Sala Segunda']))
            ->assertOk()
            ->assertSee('Sala «Sala Segunda» creada correctamente.')
            ->assertSee('Recién creada');
    }

    // ============================================================= PERMISOS

    public function test_invitado_no_entra_al_directorio(): void
    {
        $this->get(route('clientes.index'))->assertRedirect('/login');
    }

    /**
     * Quien solo puede VER no ve acciones de gestión. Las autorizaciones son las de
     * siempre (ClientePolicy); acá se comprueba que la pantalla nueva las respete.
     */
    public function test_quien_solo_ve_no_recibe_acciones_de_gestion(): void
    {
        $cliente = Cliente::factory()->create(['nombre' => 'Cliente Visible']);

        $this->actingAs($this->usuario('jefatura'))->get(route('clientes.index'))
            ->assertOk()
            ->assertSee('Cliente Visible')
            ->assertDontSee('Agregar sala')
            ->assertDontSee(route('clientes.edit', $cliente), false)
            ->assertDontSee('Eliminar cliente');
    }

    public function test_quien_solo_ve_no_puede_abrir_el_alta_de_sala(): void
    {
        $cliente = Cliente::factory()->create();

        $this->actingAs($this->usuario('jefatura'))
            ->get(route('clientes.sucursales.create', $cliente))
            ->assertForbidden();
    }

    public function test_administrador_si_recibe_las_acciones(): void
    {
        $cliente = Cliente::factory()->create(['nombre' => 'Cliente Gestionable']);

        $this->actingAs($this->admin())->get(route('clientes.index'))
            ->assertOk()
            ->assertSee('Agregar sala')
            ->assertSee(route('clientes.edit', $cliente), false)
            ->assertSee('Eliminar cliente');
    }

    // ================================================================= N+1

    /**
     * El número de consultas NO puede depender de cuántos clientes trae la página.
     * Es la prueba que impide que alguien vuelva a leer `$cliente->sucursales` dentro
     * del @foreach: con 15 filas eso son 15 consultas más por pantalla.
     */
    public function test_el_listado_no_hace_consultas_por_cliente(): void
    {
        $admin = $this->admin();

        $consultas = function (int $cuantos) use ($admin): int {
            Cliente::query()->forceDelete();
            for ($i = 0; $i < $cuantos; $i++) {
                $cliente = Cliente::factory()->contribuyente()->create(['nombre' => "Cliente {$i}"]);
                ClienteSucursal::factory()->for($cliente)->count(2)->create();
            }

            // Petición de calentamiento SIN medir: la primera del test paga cachés
            // frías (permisos de spatie, vistas compiladas) y esas consultas no
            // tienen nada que ver con cuántos clientes hay.
            $this->actingAs($admin)->get(route('clientes.index'))->assertOk();

            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->actingAs($admin)->get(route('clientes.index'))->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $conPocos = $consultas(2);
        $conMuchos = $consultas(12);

        $this->assertSame($conPocos, $conMuchos,
            "El listado pasó de {$conPocos} a {$conMuchos} consultas al crecer de 2 a 12 clientes: hay un N+1.");
    }

    /** Lo mismo buscando por sala: el contexto se precarga, no se consulta por fila. */
    public function test_la_busqueda_por_sala_tampoco_consulta_por_cliente(): void
    {
        $admin = $this->admin();

        $consultas = function (int $cuantos) use ($admin): int {
            Cliente::query()->forceDelete();
            for ($i = 0; $i < $cuantos; $i++) {
                $cliente = Cliente::factory()->contribuyente()->create(['nombre' => "Cliente {$i}"]);
                ClienteSucursal::factory()->for($cliente)->create(['nombre' => "Sala Comun {$i}"]);
            }

            $this->actingAs($admin)->get(route('clientes.index', ['q' => 'Sala Comun']))->assertOk();

            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->actingAs($admin)->get(route('clientes.index', ['q' => 'Sala Comun']))->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $this->assertSame($consultas(2), $consultas(12));
    }

    // ============================================================== LA FICHA

    /** La sección de salas va ANTES del detalle fiscal: es a lo que se viene. */
    public function test_la_ficha_pone_las_salas_antes_del_detalle(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        ClienteSucursal::factory()->for($cliente)->create(['nombre' => 'Sala Primera']);

        $contenido = $this->actingAs($this->admin())
            ->get(route('clientes.show', $cliente))
            ->assertOk()
            ->getContent();

        $posSalas = strpos($contenido, '>Salas<');
        $posDetalle = strpos($contenido, 'Tamaño de contribuyente');

        $this->assertNotFalse($posSalas, 'La ficha debe tener la sección «Salas».');
        $this->assertNotFalse($posDetalle);
        $this->assertLessThan($posDetalle, $posSalas, 'Las salas deben ir antes del detalle fiscal.');
    }

    public function test_la_ficha_muestra_el_conteo_y_el_boton_principal(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        ClienteSucursal::factory()->for($cliente)->count(9)->create(['activo' => true]);
        ClienteSucursal::factory()->for($cliente)->count(3)->create(['activo' => false]);

        $this->actingAs($this->admin())->get(route('clientes.show', $cliente))
            ->assertOk()
            ->assertSee('12 salas · 9 activas')
            ->assertSee('Agregar sala');
    }

    /** Con muchas salas aparecen los controles; la lista completa sigue en el HTML. */
    public function test_la_ficha_con_muchas_salas_ofrece_busqueda_y_filtro(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        for ($i = 1; $i <= 12; $i++) {
            ClienteSucursal::factory()->for($cliente)->create(['nombre' => "Sala Numero {$i}"]);
        }

        $respuesta = $this->actingAs($this->admin())->get(route('clientes.show', $cliente))->assertOk();

        $respuesta->assertSee('Buscar sala')
            ->assertSee('id="filtro-salas"', false)
            ->assertSee('id="estado-salas"', false);

        // Ninguna sala se pierde: el filtro es del navegador, no del servidor.
        for ($i = 1; $i <= 12; $i++) {
            $respuesta->assertSee("Sala Numero {$i}");
        }
    }

    /** Con pocas salas no se agregan controles que solo estorban. */
    public function test_la_ficha_con_pocas_salas_no_muestra_controles(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        ClienteSucursal::factory()->for($cliente)->count(3)->create();

        $this->actingAs($this->admin())->get(route('clientes.show', $cliente))
            ->assertOk()
            ->assertDontSee('id="filtro-salas"', false);
    }

    /** Estado vacío con salida, no un guion. */
    public function test_la_ficha_sin_salas_ofrece_crear_la_primera(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();

        $this->actingAs($this->admin())->get(route('clientes.show', $cliente))
            ->assertOk()
            ->assertSee('Este cliente todavía no tiene salas')
            ->assertSee('Agregar primera sala');
    }

    /** «Sala» en lo visible; «sucursal» puede seguir viviendo en rutas y modelo. */
    public function test_la_ficha_no_habla_de_sucursales_en_pantalla(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        ClienteSucursal::factory()->for($cliente)->create(['nombre' => 'Sala Unica']);

        $contenido = $this->actingAs($this->admin())
            ->get(route('clientes.show', $cliente))
            ->assertOk()
            ->getContent();

        // Se ignoran las URL (clientes/{id}/sucursales/...), que son nombres internos.
        $sinUrls = preg_replace('#https?://[^"\'\s]+#', '', $contenido);
        $this->assertStringNotContainsStringIgnoringCase('sucursal', (string) $sinUrls);
    }
}
