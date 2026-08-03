<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoAjuste;
use App\Enums\Planta\TipoInsumo;
use App\Enums\Planta\TipoUbicacion;
use App\Enums\Planta\UnidadBase;
use App\Exceptions\Planta\UnidadBaseInmutableException;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaProveedor;
use App\Models\Planta\PlantaRecepcion;
use App\Models\Planta\PlantaUbicacion;
use App\Models\User;
use App\Services\Planta\PlantaAjusteService;
use App\Services\Planta\PlantaTrasladoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * CRUD, filtros, validaciones y auditoría de los catálogos de Planta.
 *
 * Ninguna prueba depende de CASA, FABRICA ni TRANSITO: ese seeder no existe
 * todavía y las reglas de ubicación de sistema dependen de la BANDERA, no del
 * código.
 */
class PlantaCatalogosCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('planta.enabled', true);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    /** @return array<string, mixed> */
    private function datosInsumo(array $sobrescribir = []): array
    {
        return array_merge([
            'codigo' => 'AZUCAR',
            'nombre' => 'Azúcar blanca',
            'tipo' => TipoInsumo::MateriaPrima->value,
            'unidad_base' => UnidadBase::Libra->value,
            'controla_lotes' => '1',
            'permite_fraccion' => '1',
            'factor_conversion_sugerido' => '2.20462262',
            'unidad_recepcion_sugerida' => 'saco',
            'contenido_sugerido' => '100',
            'stock_minimo' => '50',
            'observaciones' => null,
            'activo' => '1',
        ], $sobrescribir);
    }

    /** @return array<string, mixed> */
    private function datosUbicacion(array $sobrescribir = []): array
    {
        return array_merge([
            'codigo' => 'BODEGA1',
            'nombre' => 'Bodega principal',
            'tipo' => TipoUbicacion::Fisica->value,
            'es_sistema' => '0',
            'permite_operacion_manual' => '1',
            'activo' => '1',
            'orden' => '0',
        ], $sobrescribir);
    }

    // ------------------------------------------------------------------
    // Insumos
    // ------------------------------------------------------------------

    public function test_el_administrador_crea_edita_y_alterna_un_insumo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('planta.insumos.store'), $this->datosInsumo())
            ->assertRedirect(route('planta.insumos.index'))
            ->assertSessionHas('status');

        $insumo = PlantaInsumo::firstWhere('codigo', 'AZUCAR');
        $this->assertNotNull($insumo);
        $this->assertSame(TipoInsumo::MateriaPrima, $insumo->tipo);
        $this->assertSame(UnidadBase::Libra, $insumo->unidad_base);
        $this->assertTrue($insumo->controla_lotes);

        $this->actingAs($admin)
            ->put(route('planta.insumos.update', $insumo), $this->datosInsumo(['nombre' => 'Azúcar morena']))
            ->assertRedirect(route('planta.insumos.index'));

        $this->assertSame('Azúcar morena', $insumo->fresh()->nombre);

        $this->actingAs($admin)
            ->patch(route('planta.insumos.toggle-activo', $insumo))
            ->assertRedirect();

        $this->assertFalse($insumo->fresh()->activo);

        $this->actingAs($admin)->patch(route('planta.insumos.toggle-activo', $insumo));
        $this->assertTrue($insumo->fresh()->activo);
    }

    public function test_el_codigo_de_insumo_no_se_repite(): void
    {
        PlantaInsumo::factory()->create(['codigo' => 'AZUCAR']);

        $this->actingAs($this->admin())
            ->post(route('planta.insumos.store'), $this->datosInsumo(['codigo' => 'AZUCAR']))
            ->assertSessionHasErrors('codigo');

        $this->assertSame(1, PlantaInsumo::where('codigo', 'AZUCAR')->count());
    }

    public function test_al_editar_un_insumo_su_propio_codigo_no_choca_consigo_mismo(): void
    {
        $insumo = PlantaInsumo::factory()->create(['codigo' => 'AZUCAR']);

        $this->actingAs($this->admin())
            ->put(route('planta.insumos.update', $insumo), $this->datosInsumo(['codigo' => 'AZUCAR', 'nombre' => 'Otro nombre']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Otro nombre', $insumo->fresh()->nombre);
    }

    public function test_el_tipo_y_la_unidad_deben_pertenecer_al_enum(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('planta.insumos.store'), $this->datosInsumo(['tipo' => 'inventado']))
            ->assertSessionHasErrors('tipo');

        $this->actingAs($admin)
            ->post(route('planta.insumos.store'), $this->datosInsumo(['unidad_base' => 'kilogramo']))
            ->assertSessionHasErrors('unidad_base');

        $this->assertSame(0, PlantaInsumo::count());
    }

    public function test_los_decimales_del_insumo_no_admiten_valores_invalidos(): void
    {
        $admin = $this->admin();

        // El factor no puede ser 0: toda conversión daría 0.
        $this->actingAs($admin)
            ->post(route('planta.insumos.store'), $this->datosInsumo(['factor_conversion_sugerido' => '0']))
            ->assertSessionHasErrors('factor_conversion_sugerido');

        $this->actingAs($admin)
            ->post(route('planta.insumos.store'), $this->datosInsumo(['factor_conversion_sugerido' => '-1']))
            ->assertSessionHasErrors('factor_conversion_sugerido');

        $this->actingAs($admin)
            ->post(route('planta.insumos.store'), $this->datosInsumo(['stock_minimo' => '-5']))
            ->assertSessionHasErrors('stock_minimo');

        $this->actingAs($admin)
            ->post(route('planta.insumos.store'), $this->datosInsumo(['contenido_sugerido' => '-1']))
            ->assertSessionHasErrors('contenido_sugerido');
    }

    public function test_los_campos_sugeridos_pueden_quedar_vacios(): void
    {
        $this->actingAs($this->admin())
            ->post(route('planta.insumos.store'), $this->datosInsumo([
                'factor_conversion_sugerido' => null,
                'unidad_recepcion_sugerida' => null,
                'contenido_sugerido' => null,
                'stock_minimo' => null,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull(PlantaInsumo::firstWhere('codigo', 'AZUCAR')->stock_minimo);
    }

    public function test_una_bolsa_no_puede_permitir_fraccion(): void
    {
        // Se RECHAZA en vez de corregir en silencio: cambiar el dato del usuario
        // sin avisarle sería peor que pedirle que lo confirme.
        $this->actingAs($this->admin())
            ->post(route('planta.insumos.store'), $this->datosInsumo([
                'codigo' => 'BOLSA85',
                'tipo' => TipoInsumo::Bolsa->value,
                'unidad_base' => UnidadBase::Unidad->value,
                'permite_fraccion' => '1',
            ]))
            ->assertSessionHasErrors('permite_fraccion');

        $this->assertSame(0, PlantaInsumo::count());
    }

    public function test_una_bolsa_sin_fraccion_si_se_guarda(): void
    {
        $this->actingAs($this->admin())
            ->post(route('planta.insumos.store'), $this->datosInsumo([
                'codigo' => 'BOLSA85',
                'tipo' => TipoInsumo::Bolsa->value,
                'unidad_base' => UnidadBase::Unidad->value,
                'permite_fraccion' => '0',
                'controla_lotes' => '0',
            ]))
            ->assertSessionHasNoErrors();

        $insumo = PlantaInsumo::firstWhere('codigo', 'BOLSA85');
        $this->assertFalse($insumo->permite_fraccion);
        $this->assertFalse($insumo->controla_lotes);
    }

    public function test_los_filtros_de_insumos_funcionan(): void
    {
        PlantaInsumo::factory()->create(['codigo' => 'AZUCAR', 'nombre' => 'Azúcar blanca']);
        PlantaInsumo::factory()->bolsa()->create(['codigo' => 'BOLSA85', 'nombre' => 'Bolsa 85 g']);
        PlantaInsumo::factory()->create(['codigo' => 'VIEJO', 'nombre' => 'Insumo retirado', 'activo' => false]);

        $admin = $this->admin();

        // Texto.
        $this->actingAs($admin)->get(route('planta.insumos.index', ['q' => 'Azúcar']))
            ->assertOk()->assertSee('Azúcar blanca')->assertDontSee('Bolsa 85 g');

        // Tipo.
        $this->actingAs($admin)->get(route('planta.insumos.index', ['tipo' => TipoInsumo::Bolsa->value]))
            ->assertOk()->assertSee('Bolsa 85 g')->assertDontSee('Azúcar blanca');

        // Estado.
        $this->actingAs($admin)->get(route('planta.insumos.index', ['activo' => '0']))
            ->assertOk()->assertSee('Insumo retirado')->assertDontSee('Azúcar blanca');

        $this->actingAs($admin)->get(route('planta.insumos.index', ['activo' => '1']))
            ->assertOk()->assertSee('Azúcar blanca')->assertDontSee('Insumo retirado');

        // Sin filtros se ven todos.
        $this->actingAs($admin)->get(route('planta.insumos.index'))
            ->assertOk()->assertSee('Azúcar blanca')->assertSee('Insumo retirado');
    }

    // ------------------------------------------------------------------
    // Proveedores
    // ------------------------------------------------------------------

    public function test_el_administrador_crea_edita_y_alterna_un_proveedor(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('planta.proveedores.store'), [
                'nombre' => 'Distribuidora El Sol',
                'nombre_comercial' => 'El Sol',
                'telefono' => '2222-3333',
                'correo' => 'ventas@elsol.test',
                'contacto' => 'Ana',
                'direccion' => 'San Salvador',
                'nit' => null,
                'nrc' => null,
                'observaciones' => null,
                'activo' => '1',
            ])
            ->assertRedirect(route('planta.proveedores.index'));

        $proveedor = PlantaProveedor::firstWhere('nombre', 'Distribuidora El Sol');
        $this->assertNotNull($proveedor);

        $this->actingAs($admin)
            ->put(route('planta.proveedores.update', $proveedor), [
                'nombre' => 'Distribuidora El Sol', 'correo' => 'compras@elsol.test', 'activo' => '1',
            ])
            ->assertRedirect(route('planta.proveedores.index'));

        $this->assertSame('compras@elsol.test', $proveedor->fresh()->correo);

        $this->actingAs($admin)->patch(route('planta.proveedores.toggle-activo', $proveedor));
        $this->assertFalse($proveedor->fresh()->activo);
    }

    public function test_el_proveedor_exige_nombre(): void
    {
        $this->actingAs($this->admin())
            ->post(route('planta.proveedores.store'), ['nombre' => '', 'activo' => '1'])
            ->assertSessionHasErrors('nombre');
    }

    public function test_el_correo_del_proveedor_debe_ser_valido(): void
    {
        $this->actingAs($this->admin())
            ->post(route('planta.proveedores.store'), [
                'nombre' => 'Proveedor X', 'correo' => 'no-es-un-correo', 'activo' => '1',
            ])
            ->assertSessionHasErrors('correo');

        $this->assertSame(0, PlantaProveedor::count());
    }

    public function test_el_nit_y_el_nrc_pueden_repetirse(): void
    {
        // Sin unique a propósito: son texto libre y se corrigen sobre la marcha.
        $admin = $this->admin();
        $datos = ['nit' => '0614-010190-101-1', 'nrc' => '12345-6', 'activo' => '1'];

        $this->actingAs($admin)->post(route('planta.proveedores.store'), $datos + ['nombre' => 'Uno'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('planta.proveedores.store'), $datos + ['nombre' => 'Dos'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PlantaProveedor::where('nit', '0614-010190-101-1')->count());
    }

    public function test_los_filtros_de_proveedores_funcionan(): void
    {
        PlantaProveedor::factory()->create(['nombre' => 'Distribuidora El Sol']);
        PlantaProveedor::factory()->create(['nombre' => 'Empaques del Norte', 'activo' => false]);

        $admin = $this->admin();

        $this->actingAs($admin)->get(route('planta.proveedores.index', ['q' => 'Sol']))
            ->assertOk()->assertSee('Distribuidora El Sol')->assertDontSee('Empaques del Norte');

        $this->actingAs($admin)->get(route('planta.proveedores.index', ['activo' => '0']))
            ->assertOk()->assertSee('Empaques del Norte')->assertDontSee('Distribuidora El Sol');
    }

    // ------------------------------------------------------------------
    // Ubicaciones
    // ------------------------------------------------------------------

    public function test_el_administrador_crea_edita_y_alterna_una_ubicacion(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('planta.ubicaciones.store'), $this->datosUbicacion())
            ->assertRedirect(route('planta.ubicaciones.index'));

        $ubicacion = PlantaUbicacion::firstWhere('codigo', 'BODEGA1');
        $this->assertNotNull($ubicacion);
        $this->assertSame(TipoUbicacion::Fisica, $ubicacion->tipo);

        $this->actingAs($admin)
            ->put(route('planta.ubicaciones.update', $ubicacion), $this->datosUbicacion(['nombre' => 'Bodega norte']))
            ->assertRedirect(route('planta.ubicaciones.index'));

        $this->assertSame('Bodega norte', $ubicacion->fresh()->nombre);

        $this->actingAs($admin)->patch(route('planta.ubicaciones.toggle-activo', $ubicacion));
        $this->assertFalse($ubicacion->fresh()->activo);
    }

    public function test_una_ubicacion_de_transito_no_admite_operacion_manual(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('planta.ubicaciones.store'), $this->datosUbicacion([
                'codigo' => 'TR1',
                'tipo' => TipoUbicacion::Transito->value,
                'permite_operacion_manual' => '1',
            ]))
            ->assertSessionHasErrors('permite_operacion_manual');

        $this->assertSame(0, PlantaUbicacion::count());

        // Con la bandera correcta sí se guarda.
        $this->actingAs($admin)
            ->post(route('planta.ubicaciones.store'), $this->datosUbicacion([
                'codigo' => 'TR1',
                'tipo' => TipoUbicacion::Transito->value,
                'permite_operacion_manual' => '0',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertFalse(PlantaUbicacion::firstWhere('codigo', 'TR1')->permite_operacion_manual);
    }

    public function test_una_ubicacion_de_sistema_no_se_puede_desactivar_por_el_formulario(): void
    {
        $sistema = PlantaUbicacion::factory()->create(['codigo' => 'SYS1', 'es_sistema' => true]);

        $this->actingAs($this->admin())
            ->put(route('planta.ubicaciones.update', $sistema), $this->datosUbicacion([
                'codigo' => 'SYS1', 'es_sistema' => '1', 'activo' => '0',
            ]))
            ->assertSessionHasErrors('activo');

        $this->assertTrue($sistema->fresh()->activo);
    }

    public function test_una_ubicacion_de_sistema_no_se_puede_desactivar_por_el_toggle(): void
    {
        // El toggle no pasa por el Form Request: su candado vive en el controlador.
        $sistema = PlantaUbicacion::factory()->create(['codigo' => 'SYS1', 'es_sistema' => true]);

        $this->actingAs($this->admin())
            ->patch(route('planta.ubicaciones.toggle-activo', $sistema))
            ->assertForbidden();

        $this->assertTrue($sistema->fresh()->activo);
    }

    public function test_una_ubicacion_de_sistema_no_puede_cambiar_de_codigo(): void
    {
        $sistema = PlantaUbicacion::factory()->create(['codigo' => 'SYS1', 'es_sistema' => true]);

        $this->actingAs($this->admin())
            ->put(route('planta.ubicaciones.update', $sistema), $this->datosUbicacion([
                'codigo' => 'OTRO', 'es_sistema' => '1',
            ]))
            ->assertSessionHasErrors('codigo');

        $this->assertSame('SYS1', $sistema->fresh()->codigo);
    }

    public function test_una_ubicacion_de_sistema_no_puede_perder_su_bandera(): void
    {
        // Si se pudiera, las dos reglas anteriores se esquivarían en dos pasos:
        // quitar es_sistema y después desactivar.
        $sistema = PlantaUbicacion::factory()->create(['codigo' => 'SYS1', 'es_sistema' => true]);

        $this->actingAs($this->admin())
            ->put(route('planta.ubicaciones.update', $sistema), $this->datosUbicacion([
                'codigo' => 'SYS1', 'es_sistema' => '0',
            ]))
            ->assertSessionHasErrors('es_sistema');

        $this->assertTrue($sistema->fresh()->es_sistema);
    }

    public function test_una_ubicacion_de_sistema_si_admite_otros_cambios(): void
    {
        $sistema = PlantaUbicacion::factory()->create(['codigo' => 'SYS1', 'es_sistema' => true, 'orden' => 0]);

        $this->actingAs($this->admin())
            ->put(route('planta.ubicaciones.update', $sistema), $this->datosUbicacion([
                'codigo' => 'SYS1', 'es_sistema' => '1', 'nombre' => 'Nombre nuevo', 'orden' => '5',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Nombre nuevo', $sistema->fresh()->nombre);
        $this->assertSame(5, $sistema->fresh()->orden);
    }

    public function test_una_ubicacion_de_sistema_inactiva_si_se_puede_reactivar(): void
    {
        $sistema = PlantaUbicacion::factory()->create(['es_sistema' => true, 'activo' => false]);

        $this->actingAs($this->admin())
            ->patch(route('planta.ubicaciones.toggle-activo', $sistema))
            ->assertRedirect();

        $this->assertTrue($sistema->fresh()->activo);
    }

    public function test_el_codigo_de_ubicacion_no_se_repite(): void
    {
        PlantaUbicacion::factory()->create(['codigo' => 'BODEGA1']);

        $this->actingAs($this->admin())
            ->post(route('planta.ubicaciones.store'), $this->datosUbicacion(['codigo' => 'BODEGA1']))
            ->assertSessionHasErrors('codigo');
    }

    public function test_los_filtros_de_ubicaciones_funcionan(): void
    {
        PlantaUbicacion::factory()->create(['codigo' => 'BODEGA1', 'nombre' => 'Bodega principal']);
        PlantaUbicacion::factory()->transito()->create(['nombre' => 'En camino']);
        PlantaUbicacion::factory()->create(['codigo' => 'VIEJA', 'nombre' => 'Bodega cerrada', 'activo' => false]);

        $admin = $this->admin();

        $this->actingAs($admin)->get(route('planta.ubicaciones.index', ['q' => 'principal']))
            ->assertOk()->assertSee('Bodega principal')->assertDontSee('En camino');

        $this->actingAs($admin)->get(route('planta.ubicaciones.index', ['tipo' => TipoUbicacion::Transito->value]))
            ->assertOk()->assertSee('En camino')->assertDontSee('Bodega principal');

        $this->actingAs($admin)->get(route('planta.ubicaciones.index', ['activo' => '0']))
            ->assertOk()->assertSee('Bodega cerrada')->assertDontSee('Bodega principal');
    }

    // ------------------------------------------------------------------
    // Historial y auditoría
    // ------------------------------------------------------------------

    public function test_desactivar_conserva_el_registro_y_su_historial(): void
    {
        $insumo = PlantaInsumo::factory()->create(['codigo' => 'AZUCAR']);

        $this->actingAs($this->admin())->patch(route('planta.insumos.toggle-activo', $insumo));

        // No hay borrado: la fila sigue ahí, solo cambió `activo`.
        $this->assertDatabaseHas('planta_insumos', [
            'id' => $insumo->id, 'codigo' => 'AZUCAR', 'activo' => false, 'deleted_at' => null,
        ]);
    }

    public function test_no_existe_ruta_de_eliminacion_en_los_catalogos(): void
    {
        // La acción visible es activar/desactivar; el borrado no se ofrece.
        foreach (['planta.insumos', 'planta.proveedores', 'planta.ubicaciones'] as $prefijo) {
            $this->assertFalse(
                app('router')->has($prefijo.'.destroy'),
                "No debería existir la ruta {$prefijo}.destroy."
            );
        }
    }

    public function test_activitylog_registra_alta_edicion_y_cambio_de_estado(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('planta.insumos.store'), $this->datosInsumo());
        $insumo = PlantaInsumo::firstWhere('codigo', 'AZUCAR');

        $this->actingAs($admin)->put(route('planta.insumos.update', $insumo), $this->datosInsumo(['nombre' => 'Azúcar morena']));
        $this->actingAs($admin)->patch(route('planta.insumos.toggle-activo', $insumo));

        $registros = Activity::where('log_name', 'planta_insumo')
            ->where('subject_id', $insumo->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $registros);
        $this->assertSame('creó el insumo de planta', $registros[0]->description);
        $this->assertSame('actualizó el insumo de planta', $registros[1]->description);
        $this->assertSame('actualizó el insumo de planta', $registros[2]->description);

        // El cambio de estado deja constancia de qué cambió y quién lo hizo.
        $this->assertSame(false, $registros[2]->properties['attributes']['activo']);
        $this->assertSame($admin->id, $registros[2]->causer_id);
    }

    public function test_activitylog_no_registra_entradas_vacias(): void
    {
        // `logOnlyDirty` + `dontSubmitEmptyLogs`: guardar sin cambios no genera ruido.
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('planta.insumos.store'), $this->datosInsumo());
        $insumo = PlantaInsumo::firstWhere('codigo', 'AZUCAR');

        $antes = Activity::where('log_name', 'planta_insumo')->count();

        $this->actingAs($admin)->put(route('planta.insumos.update', $insumo), $this->datosInsumo());

        $this->assertSame($antes, Activity::where('log_name', 'planta_insumo')->count());
    }

    public function test_cada_catalogo_audita_con_su_propio_log_name(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('planta.proveedores.store'), ['nombre' => 'Proveedor X', 'activo' => '1']);
        $this->actingAs($admin)->post(route('planta.ubicaciones.store'), $this->datosUbicacion());

        $this->assertSame(1, Activity::where('log_name', 'planta_proveedor')->count());
        $this->assertSame(1, Activity::where('log_name', 'planta_ubicacion')->count());
    }

    // ---------------------------------------------------------------
    // Unidad base inmutable con historial
    //
    // `unidad_base` no es un rótulo del catálogo: es la unidad en la que están
    // escritas TODAS las cantidades del insumo. El mayor guarda una copia
    // congelada en cada asiento y `planta_existencias` la toma por JOIN, así que
    // cambiarla con historial dejaría el historial diciendo una cosa y el saldo
    // presentándose como otra sin que ninguna cifra se hubiera movido.
    //
    // La regla mira DOS señales y ninguna más: movimientos o existencias. No
    // depende del saldo actual.
    // ---------------------------------------------------------------

    use RecepcionPlantaFixtures {
        admin as private adminDeFixtures;
    }

    /** Insumo persistido, sin ningún historial. */
    private function insumoGuardado(array $sobrescribir = []): PlantaInsumo
    {
        $this->actingAs($this->admin())
            ->post(route('planta.insumos.store'), $this->datosInsumo($sobrescribir))
            ->assertSessionHasNoErrors();

        return PlantaInsumo::firstWhere('codigo', $sobrescribir['codigo'] ?? 'AZUCAR');
    }

    /** Intenta cambiar la unidad por HTTP y devuelve la respuesta. */
    private function cambiarUnidad(PlantaInsumo $insumo, UnidadBase $nueva)
    {
        return $this->actingAs($this->admin())
            ->put(route('planta.insumos.update', $insumo), $this->datosInsumo([
                'codigo' => $insumo->codigo,
                'unidad_base' => $nueva->value,
                // Coherencia con la validación de bolsas: si va a unidad, sin fracción.
                'permite_fraccion' => $nueva === UnidadBase::Unidad ? '0' : '1',
            ]));
    }

    /** Recepción confirmada sobre ESE insumo: la forma real de generar historial. */
    private function darHistorialA(PlantaInsumo $insumo): PlantaRecepcion
    {
        $admin = $this->adminDeFixtures();

        $recepcion = $this->servicioRecepcion()->crearBorrador(
            $this->payload($this->bodega(), [$this->linea($insumo)]),
            $admin,
        );

        return $this->servicioRecepcion()->confirmar($recepcion, $admin);
    }

    private function huellaInventario(): array
    {
        $existencias = DB::table('planta_existencias')
            ->selectRaw('COUNT(*) as filas, COALESCE(MAX(id), 0) as max_id')
            ->first();

        return [
            'mayor' => $this->huellaMayor(),
            'existencias' => ['filas' => (int) $existencias->filas, 'max_id' => (int) $existencias->max_id],
        ];
    }

    // --- Sin historial: se puede cambiar ---

    public function test_sin_historial_la_unidad_pasa_de_libra_a_unidad(): void
    {
        $insumo = $this->insumoGuardado();
        $this->assertSame(UnidadBase::Libra, $insumo->unidad_base);

        $this->cambiarUnidad($insumo, UnidadBase::Unidad)->assertSessionHasNoErrors();

        $this->assertSame(UnidadBase::Unidad, $insumo->fresh()->unidad_base);
    }

    public function test_sin_historial_la_unidad_pasa_de_unidad_a_libra(): void
    {
        $insumo = $this->insumoGuardado(['unidad_base' => UnidadBase::Unidad->value, 'permite_fraccion' => '0']);
        $this->assertSame(UnidadBase::Unidad, $insumo->unidad_base);

        $this->cambiarUnidad($insumo, UnidadBase::Libra)->assertSessionHasNoErrors();

        $this->assertSame(UnidadBase::Libra, $insumo->fresh()->unidad_base);
    }

    public function test_un_lote_sin_movimientos_no_bloquea_el_cambio(): void
    {
        $insumo = $this->insumoGuardado();
        PlantaLote::factory()->create(['planta_insumo_id' => $insumo->id]);

        $this->assertFalse($insumo->tieneHistorialDeInventario());

        $this->cambiarUnidad($insumo, UnidadBase::Unidad)->assertSessionHasNoErrors();

        $this->assertSame(UnidadBase::Unidad, $insumo->fresh()->unidad_base);
    }

    public function test_un_documento_solo_en_borrador_no_bloquea_el_cambio(): void
    {
        $insumo = $this->insumoGuardado();

        // Borrador SIN confirmar: no ha escrito nada en el inventario.
        $this->servicioRecepcion()->crearBorrador(
            $this->payload($this->bodega(), [$this->linea($insumo)]),
            $this->adminDeFixtures(),
        );

        $this->assertFalse($insumo->tieneHistorialDeInventario());

        $this->cambiarUnidad($insumo, UnidadBase::Unidad)->assertSessionHasNoErrors();

        $this->assertSame(UnidadBase::Unidad, $insumo->fresh()->unidad_base);
    }

    // --- Con historial: se bloquea ---

    public function test_con_movimientos_la_unidad_no_cambia(): void
    {
        $insumo = $this->insumoGuardado();
        $this->darHistorialA($insumo);

        $this->cambiarUnidad($insumo, UnidadBase::Unidad)
            ->assertSessionHasErrors('unidad_base');

        $this->assertSame(UnidadBase::Libra, $insumo->fresh()->unidad_base);
    }

    /** El saldo actual es irrelevante: lo que manda es que el historial exista. */
    public function test_con_movimientos_y_saldo_cero_la_unidad_tampoco_cambia(): void
    {
        $insumo = $this->insumoGuardado();
        $recepcion = $this->darHistorialA($insumo);

        // Reversar deja el saldo en cero y conserva movimientos originales y espejo.
        $this->servicioRecepcion()->reversar($recepcion->refresh(), 'devolución completa al proveedor', $this->adminDeFixtures());

        $this->assertSame('0.0000', $this->saldo($this->bucketDe($recepcion)));

        $this->cambiarUnidad($insumo, UnidadBase::Unidad)
            ->assertSessionHasErrors('unidad_base');

        $this->assertSame(UnidadBase::Libra, $insumo->fresh()->unidad_base);
    }

    /** Una fila de existencias EN CERO también cuenta como historial. */
    public function test_una_fila_de_existencias_en_cero_bloquea_el_cambio(): void
    {
        $insumo = $this->insumoGuardado();
        $lote = PlantaLote::factory()->create(['planta_insumo_id' => $insumo->id]);

        // Fila de proyección en cero SIN movimientos detrás: el caso anómalo que
        // la segunda señal existe para cubrir.
        DB::table('planta_existencias')->insert([
            'planta_insumo_id' => $insumo->id,
            'planta_lote_id' => $lote->id,
            'planta_ubicacion_id' => $this->bodega()->id,
            'estado' => EstadoDisponibilidad::Disponible->value,
            'planta_traslado_id' => 0,
            'cantidad' => 0,
            'actualizado_en' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, PlantaMovimiento::where('planta_insumo_id', $insumo->id)->count());
        $this->assertTrue($insumo->tieneHistorialDeInventario());

        $this->cambiarUnidad($insumo, UnidadBase::Unidad)
            ->assertSessionHasErrors('unidad_base');

        $this->assertSame(UnidadBase::Libra, $insumo->fresh()->unidad_base);
    }

    public function test_un_ajuste_historico_bloquea_el_cambio(): void
    {
        $insumo = $this->insumoGuardado();
        $recepcion = $this->darHistorialA($insumo);
        $detalle = $recepcion->refresh()->detalles->first();
        $admin = $this->adminDeFixtures();

        $ajuste = app(PlantaAjusteService::class)->crearBorrador([
            'tipo' => TipoAjuste::Merma->value,
            'fecha' => '2026-07-30',
            'motivo' => 'Producto dañado en la descarga',
            'responsable_nombre' => 'Quien constató',
            'detalles' => [[
                'planta_insumo_id' => $insumo->id,
                'planta_lote_id' => $detalle->planta_lote_id,
                'planta_ubicacion_id' => $recepcion->planta_ubicacion_id,
                'estado_disponibilidad' => EstadoDisponibilidad::Disponible->value,
                'cantidad' => '10',
            ]],
        ], $admin);

        app(PlantaAjusteService::class)->confirmar($ajuste, $admin);

        $this->cambiarUnidad($insumo, UnidadBase::Unidad)
            ->assertSessionHasErrors('unidad_base');
    }

    public function test_un_traslado_historico_bloquea_el_cambio(): void
    {
        $insumo = $this->insumoGuardado();
        $origen = $this->bodega();
        $destino = $this->bodega();
        $transito = PlantaUbicacion::factory()->transito()->create([
            'es_sistema' => true, 'activo' => true, 'permite_operacion_manual' => false,
        ]);
        $admin = $this->adminDeFixtures();

        $recepcion = $this->servicioRecepcion()->crearBorrador(
            $this->payload($origen, [$this->linea($insumo)]),
            $admin,
        );
        $this->servicioRecepcion()->confirmar($recepcion, $admin);
        $detalle = $recepcion->refresh()->detalles->first();

        $traslado = app(PlantaTrasladoService::class)->crearBorrador([
            'fecha' => '2026-07-30',
            'planta_ubicacion_origen_id' => $origen->id,
            'planta_ubicacion_destino_id' => $destino->id,
            'responsable_nombre' => 'Quien transporta',
            'detalles' => [[
                'planta_insumo_id' => $insumo->id,
                'planta_lote_id' => $detalle->planta_lote_id,
                'cantidad' => '100',
            ]],
        ], $admin);

        app(PlantaTrasladoService::class)->enviar($traslado, $admin);

        $this->assertNotNull($transito->id);

        $this->cambiarUnidad($insumo, UnidadBase::Unidad)
            ->assertSessionHasErrors('unidad_base');
    }

    // --- Lo que SÍ se puede seguir editando ---

    public function test_con_historial_los_demas_campos_si_se_editan(): void
    {
        $insumo = $this->insumoGuardado();
        $this->darHistorialA($insumo);

        $this->actingAs($this->admin())
            ->put(route('planta.insumos.update', $insumo), $this->datosInsumo([
                'nombre' => 'Azúcar morena refinada',
                'tipo' => TipoInsumo::MateriaPrima->value,
                'stock_minimo' => '120',
                'activo' => '0',
                'observaciones' => 'Se cambió el proveedor habitual',
                // Se reenvía la MISMA unidad: eso nunca activa el bloqueo.
                'unidad_base' => UnidadBase::Libra->value,
            ]))
            ->assertSessionHasNoErrors();

        $insumo->refresh();

        $this->assertSame('Azúcar morena refinada', $insumo->nombre);
        $this->assertSame('120.0000', $insumo->stock_minimo);
        $this->assertFalse($insumo->activo);
        $this->assertSame(UnidadBase::Libra, $insumo->unidad_base);
    }

    public function test_toggle_activo_sigue_funcionando_con_historial(): void
    {
        $insumo = $this->insumoGuardado();
        $this->darHistorialA($insumo);

        // `toggleActivo()` hace update(['activo']) sin enviar la unidad: el
        // candado del modelo no debe interferir.
        $this->actingAs($this->admin())
            ->patch(route('planta.insumos.toggle-activo', $insumo))
            ->assertRedirect();

        $this->assertFalse($insumo->fresh()->activo);
    }

    public function test_un_valor_fuera_del_enum_sigue_rechazado(): void
    {
        $insumo = $this->insumoGuardado();
        $this->darHistorialA($insumo);

        $this->actingAs($this->admin())
            ->put(route('planta.insumos.update', $insumo), $this->datosInsumo([
                'codigo' => $insumo->codigo,
                'unidad_base' => 'kilogramo',
            ]))
            ->assertSessionHasErrors('unidad_base');

        $this->assertSame(UnidadBase::Libra, $insumo->fresh()->unidad_base);
    }

    // --- La protección no escribe nada ---

    public function test_el_intento_rechazado_no_toca_el_inventario(): void
    {
        $insumo = $this->insumoGuardado();
        $this->darHistorialA($insumo);

        $antes = $this->huellaInventario();

        $this->cambiarUnidad($insumo, UnidadBase::Unidad)->assertSessionHasErrors('unidad_base');

        $this->assertSame($antes, $this->huellaInventario());
    }

    public function test_la_comprobacion_no_consulta_tablas_fiscales(): void
    {
        $insumo = $this->insumoGuardado();
        $this->darHistorialA($insumo);

        $sentencias = [];
        $midiendo = true;
        DB::listen(function ($query) use (&$sentencias, &$midiendo): void {
            if ($midiendo) {
                $sentencias[] = $query->sql;
            }
        });

        $this->cambiarUnidad($insumo, UnidadBase::Unidad);
        $midiendo = false;

        foreach (['dtes', 'documentos_recibidos', 'exportaciones'] as $tabla) {
            foreach ($sentencias as $sql) {
                $this->assertStringNotContainsString($tabla, $sql, "No debe consultarse «{$tabla}».");
            }
        }
    }

    // --- Candado del modelo (fuera del formulario) ---

    public function test_el_modelo_rechaza_el_cambio_directo_con_historial(): void
    {
        $insumo = $this->insumoGuardado();
        $this->darHistorialA($insumo);

        $this->expectException(UnidadBaseInmutableException::class);

        $insumo->update(['unidad_base' => UnidadBase::Unidad->value]);
    }

    public function test_el_modelo_permite_el_update_directo_si_la_unidad_no_cambia(): void
    {
        $insumo = $this->insumoGuardado();
        $this->darHistorialA($insumo);

        $insumo->update([
            'nombre' => 'Otro nombre',
            'unidad_base' => UnidadBase::Libra->value,
        ]);

        $this->assertSame('Otro nombre', $insumo->fresh()->nombre);
    }

    public function test_el_modelo_permite_el_cambio_directo_sin_historial(): void
    {
        $insumo = $this->insumoGuardado();

        $insumo->update(['unidad_base' => UnidadBase::Unidad->value]);

        $this->assertSame(UnidadBase::Unidad, $insumo->fresh()->unidad_base);
    }

    public function test_la_factory_y_la_creacion_siguen_intactas(): void
    {
        // El candado es de `updating`: crear nunca lo toca.
        $porFactory = PlantaInsumo::factory()->create(['unidad_base' => UnidadBase::Unidad->value]);
        $this->assertSame(UnidadBase::Unidad, $porFactory->unidad_base);

        $porHttp = $this->insumoGuardado(['codigo' => 'HARINA']);
        $this->assertSame(UnidadBase::Libra, $porHttp->unidad_base);
    }

    public function test_con_el_modulo_apagado_la_edicion_responde_404(): void
    {
        $insumo = $this->insumoGuardado();

        config()->set('planta.enabled', false);

        $this->actingAs($this->admin())
            ->put(route('planta.insumos.update', $insumo), $this->datosInsumo(['codigo' => $insumo->codigo]))
            ->assertNotFound();

        $this->assertSame(UnidadBase::Libra, $insumo->fresh()->unidad_base);
    }

    public function test_la_autorizacion_de_catalogos_sigue_intacta(): void
    {
        $insumo = $this->insumoGuardado();

        // Producción lee catálogos pero no los gestiona.
        $this->actingAs(User::factory()->create()->assignRole('produccion'))
            ->put(route('planta.insumos.update', $insumo), $this->datosInsumo(['codigo' => $insumo->codigo]))
            ->assertForbidden();

        $this->assertSame(UnidadBase::Libra, $insumo->fresh()->unidad_base);
    }
}
