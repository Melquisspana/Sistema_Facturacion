<?php

namespace Tests\Feature\Planta;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Estructura de las seis tablas de catálogo del paso 2.
 *
 * Cada prueba con RefreshDatabase corre sobre una base recién migrada, así que
 * el propio hecho de que estas pruebas pasen demuestra que `migrate:fresh`
 * construye el esquema completo sin errores.
 */
class PlantaCatalogosEsquemaTest extends TestCase
{
    use RefreshDatabase;

    /** Las seis tablas del paso 2, en orden de dependencias. */
    private const TABLAS = [
        'planta_ubicaciones',
        'planta_proveedores',
        'planta_insumos',
        'planta_lotes',
        'planta_productos_base',
        'planta_presentaciones',
    ];

    public function test_las_seis_tablas_se_crean(): void
    {
        foreach (self::TABLAS as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "Falta la tabla {$tabla}.");
        }
    }

    public function test_planta_ubicaciones_tiene_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasColumns('planta_ubicaciones', [
            'id', 'codigo', 'nombre', 'tipo', 'es_sistema',
            'permite_operacion_manual', 'activo', 'orden',
            'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    public function test_planta_proveedores_tiene_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasColumns('planta_proveedores', [
            'id', 'nombre', 'nombre_comercial', 'telefono', 'correo', 'contacto',
            'direccion', 'nit', 'nrc', 'observaciones', 'activo',
            'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    public function test_planta_insumos_tiene_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasColumns('planta_insumos', [
            'id', 'codigo', 'nombre', 'tipo', 'unidad_base', 'controla_lotes',
            'permite_fraccion', 'factor_conversion_sugerido', 'unidad_recepcion_sugerida',
            'contenido_sugerido', 'stock_minimo', 'activo', 'observaciones',
            'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    public function test_planta_lotes_tiene_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasColumns('planta_lotes', [
            'id', 'planta_insumo_id', 'planta_proveedor_id', 'codigo_interno',
            'codigo_proveedor', 'es_generico', 'fecha_recepcion', 'fecha_elaboracion',
            'fecha_vencimiento', 'activo', 'created_at', 'updated_at',
        ]));
    }

    public function test_planta_productos_base_tiene_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasColumns('planta_productos_base', [
            'id', 'codigo', 'nombre', 'descripcion', 'activo',
            'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    public function test_planta_presentaciones_tiene_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasColumns('planta_presentaciones', [
            'id', 'planta_producto_base_id', 'codigo', 'nombre', 'contenido',
            'unidad_contenido', 'unidades_por_bulto', 'activo',
            'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    public function test_solo_planta_lotes_carece_de_soft_deletes(): void
    {
        // Un lote con movimientos no puede desaparecer del historial ni siquiera
        // de forma lógica; se retira de la operación con `activo = false`.
        $this->assertFalse(Schema::hasColumn('planta_lotes', 'deleted_at'));

        foreach (['planta_ubicaciones', 'planta_proveedores', 'planta_insumos',
            'planta_productos_base', 'planta_presentaciones'] as $catalogo) {
            $this->assertTrue(
                Schema::hasColumn($catalogo, 'deleted_at'),
                "{$catalogo} debería tener softDeletes."
            );
        }
    }

    public function test_planta_productos_base_no_referencia_el_catalogo_fiscal(): void
    {
        // Aislamiento: ni la columna existe. Dejarla sin uso invitaría a que
        // algún flujo acoplara Planta con Facturación por la puerta de atrás.
        $this->assertFalse(Schema::hasColumn('planta_productos_base', 'producto_id'));

        foreach (self::TABLAS as $tabla) {
            $this->assertFalse(
                Schema::hasColumn($tabla, 'producto_id'),
                "{$tabla} no debe referenciar `productos` de Facturación."
            );
            $this->assertFalse(
                Schema::hasColumn($tabla, 'cliente_id'),
                "{$tabla} no debe referenciar `clientes` de Facturación."
            );
        }
    }

    public function test_cada_tabla_de_los_pasos_posteriores_tiene_su_propia_prueba_de_esquema(): void
    {
        // Esta prueba nació como lista de tablas RESERVADAS que aún no debían
        // existir. Las fue soltando según se implementaban —`planta_empaque_configs`
        // en el paso 4, el mayor y la proyección en el paso 5, las recepciones en
        // el 6, los cambios de disponibilidad en el 7, los traslados en el 8— y con
        // los ajustes del paso 9 se vació. Se conserva invertida, como inventario
        // de que ninguna tabla del módulo quedó sin dueño: cada una la verifica en
        // detalle la prueba de esquema de SU paso, y esta solo comprueba que
        // siguen ahí y que la del paso 2 no se quedó atrás.
        foreach ([
            'planta_empaque_configs' => PlantaEmpaqueEsquemaTest::class,
            'planta_movimientos' => PlantaInventarioEsquemaTest::class,
            'planta_existencias' => PlantaInventarioEsquemaTest::class,
            'planta_recepciones' => PlantaRecepcionEsquemaTest::class,
            'planta_recepcion_detalles' => PlantaRecepcionEsquemaTest::class,
            'planta_cambios_disponibilidad' => PlantaCambioDisponibilidadEsquemaTest::class,
            'planta_traslados' => PlantaTrasladoEsquemaTest::class,
            'planta_traslado_detalles' => PlantaTrasladoEsquemaTest::class,
            'planta_ajustes' => PlantaAjusteEsquemaTest::class,
            'planta_ajuste_detalles' => PlantaAjusteEsquemaTest::class,
        ] as $tabla => $prueba) {
            $this->assertTrue(Schema::hasTable($tabla), "Falta la tabla {$tabla}.");
            $this->assertTrue(class_exists($prueba), "{$tabla} no tiene prueba de esquema propia.");
        }
    }

    public function test_las_columnas_derivadas_de_empaque_no_existen_todavia(): void
    {
        // marca_norm, vinieta_key y predeterminada_key son del paso 4, junto con
        // la decisión sobre columnas generadas STORED (Q7).
        foreach (self::TABLAS as $tabla) {
            foreach (['marca_norm', 'vinieta_key', 'predeterminada_key'] as $derivada) {
                $this->assertFalse(
                    Schema::hasColumn($tabla, $derivada),
                    "{$tabla}.{$derivada} pertenece al paso 4."
                );
            }
        }
    }
}
