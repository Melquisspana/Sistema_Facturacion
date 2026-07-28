<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\TipoInsumo;
use App\Enums\Planta\TipoUbicacion;
use App\Enums\Planta\UnidadBase;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaPresentacion;
use App\Models\Planta\PlantaProductoBase;
use App\Models\Planta\PlantaProveedor;
use App\Models\Planta\PlantaUbicacion;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Modelos, relaciones, casts y restricciones de los catálogos del paso 2.
 *
 * Nada de lógica de inventario: los saldos, el libro mayor y los servicios
 * llegan en pasos posteriores.
 */
class PlantaCatalogosModelosTest extends TestCase
{
    use RefreshDatabase;

    // --- Nulabilidad y restricciones de base de datos ---

    public function test_la_fecha_de_recepcion_del_lote_rechaza_null(): void
    {
        // Un lote sin fecha de entrada no es trazable. La columna es NOT NULL,
        // así que ni siquiera saltándose el servicio se puede crear uno así.
        $insumo = PlantaInsumo::factory()->create();

        $this->expectException(QueryException::class);

        PlantaLote::factory()->create([
            'planta_insumo_id' => $insumo->id,
            'fecha_recepcion' => null,
        ]);
    }

    public function test_el_lote_generico_tambien_exige_fecha_de_recepcion(): void
    {
        $insumo = PlantaInsumo::factory()->bolsa()->create();

        $this->expectException(QueryException::class);

        PlantaLote::factory()->generico($insumo)->create(['fecha_recepcion' => null]);
    }

    public function test_las_columnas_requeridas_rechazan_null(): void
    {
        $casos = [
            [PlantaUbicacion::class, 'codigo'],
            [PlantaUbicacion::class, 'nombre'],
            [PlantaProveedor::class, 'nombre'],
            [PlantaInsumo::class, 'codigo'],
            [PlantaInsumo::class, 'nombre'],
            [PlantaInsumo::class, 'tipo'],
            [PlantaInsumo::class, 'unidad_base'],
            [PlantaProductoBase::class, 'codigo'],
            [PlantaProductoBase::class, 'nombre'],
            [PlantaPresentacion::class, 'codigo'],
            [PlantaPresentacion::class, 'nombre'],
        ];

        foreach ($casos as [$modelo, $columna]) {
            try {
                $modelo::factory()->create([$columna => null]);
                $this->fail("{$modelo}.{$columna} debería rechazar NULL.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_las_columnas_opcionales_aceptan_null(): void
    {
        $proveedor = PlantaProveedor::factory()->create([
            'nombre_comercial' => null, 'telefono' => null, 'correo' => null,
            'contacto' => null, 'direccion' => null, 'nit' => null,
            'nrc' => null, 'observaciones' => null,
        ]);

        $this->assertNull($proveedor->fresh()->nit);

        $insumo = PlantaInsumo::factory()->create([
            'factor_conversion_sugerido' => null, 'unidad_recepcion_sugerida' => null,
            'contenido_sugerido' => null, 'stock_minimo' => null, 'observaciones' => null,
        ]);

        $this->assertNull($insumo->fresh()->stock_minimo);
    }

    // --- Códigos únicos ---

    public function test_los_codigos_unicos_rechazan_duplicados(): void
    {
        $casos = [
            [PlantaUbicacion::class, 'codigo', 'CASA'],
            [PlantaInsumo::class, 'codigo', 'AZUCAR'],
            [PlantaProductoBase::class, 'codigo', 'COCO'],
            [PlantaPresentacion::class, 'codigo', 'COCO85'],
        ];

        foreach ($casos as [$modelo, $columna, $valor]) {
            $modelo::factory()->create([$columna => $valor]);

            try {
                $modelo::factory()->create([$columna => $valor]);
                $this->fail("{$modelo}.{$columna} debería ser único.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_el_codigo_interno_del_lote_es_unico(): void
    {
        PlantaLote::factory()->create(['codigo_interno' => 'INT-20260728-0001']);

        $this->expectException(QueryException::class);

        PlantaLote::factory()->create(['codigo_interno' => 'INT-20260728-0001']);
    }

    public function test_una_presentacion_no_repite_nombre_dentro_del_mismo_producto(): void
    {
        $base = PlantaProductoBase::factory()->create();

        PlantaPresentacion::factory()->create([
            'planta_producto_base_id' => $base->id,
            'nombre' => 'Coco 85 g',
        ]);

        $this->expectException(QueryException::class);

        PlantaPresentacion::factory()->create([
            'planta_producto_base_id' => $base->id,
            'nombre' => 'Coco 85 g',
        ]);
    }

    public function test_dos_productos_base_distintos_si_pueden_repetir_nombre_de_presentacion(): void
    {
        $uno = PlantaProductoBase::factory()->create();
        $otro = PlantaProductoBase::factory()->create();

        PlantaPresentacion::factory()->create(['planta_producto_base_id' => $uno->id, 'nombre' => '85 g']);
        PlantaPresentacion::factory()->create(['planta_producto_base_id' => $otro->id, 'nombre' => '85 g']);

        $this->assertSame(2, PlantaPresentacion::where('nombre', '85 g')->count());
    }

    public function test_el_nit_del_proveedor_puede_repetirse(): void
    {
        // Sin unique a propósito: es texto libre, llega vacío y se corrige.
        PlantaProveedor::factory()->create(['nit' => '0614-010190-101-1']);
        PlantaProveedor::factory()->create(['nit' => '0614-010190-101-1']);

        $this->assertSame(2, PlantaProveedor::where('nit', '0614-010190-101-1')->count());
    }

    // --- Claves foráneas y relaciones ---

    public function test_el_lote_pertenece_a_su_insumo_y_proveedor(): void
    {
        $insumo = PlantaInsumo::factory()->create();
        $proveedor = PlantaProveedor::factory()->create();

        $lote = PlantaLote::factory()->create([
            'planta_insumo_id' => $insumo->id,
            'planta_proveedor_id' => $proveedor->id,
        ]);

        $this->assertTrue($lote->insumo->is($insumo));
        $this->assertTrue($lote->proveedor->is($proveedor));
        $this->assertTrue($insumo->lotes->contains($lote));
        $this->assertTrue($proveedor->lotes->contains($lote));
    }

    public function test_el_lote_puede_no_tener_proveedor(): void
    {
        $lote = PlantaLote::factory()->create(['planta_proveedor_id' => null]);

        $this->assertNull($lote->proveedor);
    }

    public function test_la_presentacion_pertenece_a_su_producto_base(): void
    {
        $base = PlantaProductoBase::factory()->create();
        $presentacion = PlantaPresentacion::factory()->create(['planta_producto_base_id' => $base->id]);

        $this->assertTrue($presentacion->productoBase->is($base));
        $this->assertTrue($base->presentaciones->contains($presentacion));
    }

    public function test_una_clave_foranea_inexistente_es_rechazada(): void
    {
        $this->expectException(QueryException::class);

        PlantaLote::factory()->create(['planta_insumo_id' => 999999]);
    }

    public function test_no_se_puede_eliminar_un_insumo_con_lotes(): void
    {
        // restrictOnDelete: el insumo es la identidad de lo que hay en bodega.
        $insumo = PlantaInsumo::factory()->create();
        PlantaLote::factory()->create(['planta_insumo_id' => $insumo->id]);

        $this->expectException(QueryException::class);

        $insumo->forceDelete();
    }

    public function test_el_soft_delete_de_un_insumo_con_lotes_si_es_posible(): void
    {
        // El borrado lógico no toca la fila, así que la FK no se viola: es la
        // vía correcta para retirar un insumo conservando su historial.
        $insumo = PlantaInsumo::factory()->create();
        $lote = PlantaLote::factory()->create(['planta_insumo_id' => $insumo->id]);

        $insumo->delete();

        $this->assertSoftDeleted($insumo);
        $this->assertDatabaseHas('planta_lotes', ['id' => $lote->id, 'planta_insumo_id' => $insumo->id]);
    }

    public function test_al_eliminar_un_proveedor_el_lote_queda_sin_proveedor(): void
    {
        $proveedor = PlantaProveedor::factory()->create();
        $lote = PlantaLote::factory()->create(['planta_proveedor_id' => $proveedor->id]);

        $proveedor->forceDelete();

        // nullOnDelete: el lote sobrevive, solo pierde el dato del proveedor.
        $this->assertDatabaseHas('planta_lotes', ['id' => $lote->id, 'planta_proveedor_id' => null]);
        $this->assertNull($lote->fresh()->proveedor);
    }

    public function test_no_se_puede_eliminar_un_producto_base_con_presentaciones(): void
    {
        $base = PlantaProductoBase::factory()->create();
        PlantaPresentacion::factory()->create(['planta_producto_base_id' => $base->id]);

        $this->expectException(QueryException::class);

        $base->forceDelete();
    }

    // --- Soft deletes ---

    public function test_los_cinco_catalogos_usan_soft_deletes(): void
    {
        foreach ([
            PlantaUbicacion::class, PlantaProveedor::class, PlantaInsumo::class,
            PlantaProductoBase::class, PlantaPresentacion::class,
        ] as $modelo) {
            $this->assertContains(
                SoftDeletes::class,
                class_uses_recursive($modelo),
                "{$modelo} debería usar SoftDeletes."
            );
        }
    }

    public function test_planta_lote_no_usa_soft_deletes(): void
    {
        $this->assertNotContains(SoftDeletes::class, class_uses_recursive(PlantaLote::class));
    }

    public function test_el_soft_delete_oculta_pero_conserva(): void
    {
        $ubicacion = PlantaUbicacion::factory()->create();
        $ubicacion->delete();

        $this->assertNull(PlantaUbicacion::find($ubicacion->id));
        $this->assertNotNull(PlantaUbicacion::withTrashed()->find($ubicacion->id));
    }

    public function test_el_borrado_de_un_lote_es_definitivo(): void
    {
        // Sin SoftDeletes, `delete()` borra de verdad. En Fase 2 no hay ruta que
        // lo permita; la vía operativa será `activo = false`.
        $lote = PlantaLote::factory()->create();
        $lote->delete();

        $this->assertDatabaseMissing('planta_lotes', ['id' => $lote->id]);
    }

    // --- Casts ---

    public function test_los_casts_boolean_devuelven_booleanos(): void
    {
        $insumo = PlantaInsumo::factory()->create([
            'controla_lotes' => true,
            'permite_fraccion' => false,
            'activo' => true,
        ])->fresh();

        $this->assertTrue($insumo->controla_lotes);
        $this->assertFalse($insumo->permite_fraccion);
        $this->assertIsBool($insumo->activo);

        $ubicacion = PlantaUbicacion::factory()->transito()->create()->fresh();

        $this->assertTrue($ubicacion->es_sistema);
        $this->assertFalse($ubicacion->permite_operacion_manual);
    }

    public function test_los_casts_decimal_conservan_la_escala(): void
    {
        $insumo = PlantaInsumo::factory()->create([
            'factor_conversion_sugerido' => '2.20462262',
            'contenido_sugerido' => '100.5',
            'stock_minimo' => '25',
        ])->fresh();

        // decimal:N devuelve string con la escala exacta: nunca float, para que
        // las sumas del inventario no arrastren error binario.
        $this->assertSame('2.20462262', $insumo->factor_conversion_sugerido);
        $this->assertSame('100.5000', $insumo->contenido_sugerido);
        $this->assertSame('25.0000', $insumo->stock_minimo);

        $presentacion = PlantaPresentacion::factory()->create(['contenido' => '85'])->fresh();

        $this->assertSame('85.0000', $presentacion->contenido);
    }

    public function test_los_casts_date_devuelven_fechas(): void
    {
        $lote = PlantaLote::factory()->create([
            'fecha_recepcion' => '2026-07-28',
            'fecha_elaboracion' => '2026-07-01',
            'fecha_vencimiento' => '2027-07-01',
        ])->fresh();

        $this->assertInstanceOf(Carbon::class, $lote->fecha_recepcion);
        $this->assertSame('2026-07-28', $lote->fecha_recepcion->toDateString());
        $this->assertSame('2027-07-01', $lote->fecha_vencimiento->toDateString());
        $this->assertNull(PlantaLote::factory()->create(['fecha_vencimiento' => null])->fresh()->fecha_vencimiento);
    }

    public function test_los_casts_de_enum_devuelven_instancias(): void
    {
        $insumo = PlantaInsumo::factory()->bolsa()->create()->fresh();

        $this->assertSame(TipoInsumo::Bolsa, $insumo->tipo);
        $this->assertSame(UnidadBase::Unidad, $insumo->unidad_base);

        $ubicacion = PlantaUbicacion::factory()->transito()->create()->fresh();

        $this->assertSame(TipoUbicacion::Transito, $ubicacion->tipo);
        $this->assertTrue($ubicacion->tipo->esTransito());
    }

    // --- Valores por defecto ---

    public function test_los_valores_por_defecto_de_la_base_son_los_esperados(): void
    {
        $ubicacion = PlantaUbicacion::query()->create(['codigo' => 'X1', 'nombre' => 'X'])->fresh();

        $this->assertSame(TipoUbicacion::Fisica, $ubicacion->tipo);
        $this->assertFalse($ubicacion->es_sistema);
        $this->assertTrue($ubicacion->permite_operacion_manual);
        $this->assertTrue($ubicacion->activo);
        $this->assertSame(0, $ubicacion->orden);

        $lote = PlantaLote::query()->create([
            'planta_insumo_id' => PlantaInsumo::factory()->create()->id,
            'codigo_interno' => 'INT-DEFAULTS',
            'fecha_recepcion' => '2026-07-28',
        ])->fresh();

        $this->assertFalse($lote->es_generico);
        $this->assertTrue($lote->activo);
    }
}
