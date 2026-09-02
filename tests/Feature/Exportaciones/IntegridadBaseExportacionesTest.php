<?php

namespace Tests\Feature\Exportaciones;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionProducto;
use App\Models\PuntoVenta;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Candados que viven en la BASE, no en la aplicación.
 *
 * Un candado que solo existe en un controlador protege del usuario, no del
 * sistema: un `DELETE` desde tinker, desde un script de mantenimiento o desde un
 * cliente SQL se lo salta entero. Estas pruebas atacan la base directamente —con
 * `DB::table()`, sin pasar por Eloquent ni por rutas— para demostrar que las
 * reglas se sostienen igual.
 */
class IntegridadBaseExportacionesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{establecimiento_id: int, punto_venta_id: int}|null */
    private ?array $emisorCache = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{establecimiento_id: int, punto_venta_id: int} */
    private function emisor(): array
    {
        if ($this->emisorCache !== null) {
            return $this->emisorCache;
        }

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);

        return $this->emisorCache = ['establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id];
    }

    private function producto(): ExportacionProducto
    {
        return ExportacionProducto::create([
            'nombre_es' => 'Caja de camote', 'nombre_en' => 'Sweet potato candy box', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 144, 'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3,
            'precio_caja' => 144.00,
            'peso_neto_caja_kg' => 19.40, 'peso_bruto_caja_kg' => 20.40,
            'peso_neto_caja_lb' => 42.77, 'peso_bruto_caja_lb' => 44.97,
            'activo' => true,
        ]);
    }

    private function fex(Cliente $cliente, string $numero, string $estado = 'generado', string $ambiente = '00'): Dte
    {
        return Dte::create($this->emisor() + [
            'tipo_dte' => TipoDte::FacturaExportacion->value,
            'cliente_id' => $cliente->id,
            'estado' => $estado,
            'ambiente' => $ambiente,
            'numero_control' => $numero,
            'fecha_emision' => '2026-09-01',
            'hora_emision' => '10:00:00',
            'total_pagar' => 100.00,
        ]);
    }

    /** @return array{cliente: Cliente, perfil: ExportacionCliente, lista: Exportacion} */
    private function escenario(): array
    {
        $cliente = Cliente::factory()->exportacion()->create(['nombre' => 'CAROLINAS WHOLESALE LLC']);
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => $cliente->nombre, 'activo' => true]);
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $perfil->id,
            'cliente_nombre' => $cliente->nombre,
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-09-01',
            'estado' => Exportacion::ESTADO_BORRADOR,
        ]);

        return ['cliente' => $cliente, 'perfil' => $perfil, 'lista' => $lista];
    }

    // ================================================== 1: borrado de productos

    /**
     * El caso que motivó la migración: un DELETE directo sobre un producto con
     * precios de cliente. Antes la base los borraba en cascada, en silencio. Ahora
     * lo rechaza.
     */
    public function test_la_base_rechaza_borrar_un_producto_de_exportacion_con_precios(): void
    {
        $producto = $this->producto();
        $perfil = ExportacionCliente::create(['nombre' => 'CAROLINAS', 'activo' => true]);
        $asignacion = ExportacionClienteProducto::create([
            'exportacion_cliente_id' => $perfil->id,
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 172.80,
            'activo' => true,
        ]);

        // Sin controlador, sin policy, sin modelo: SQL directo.
        try {
            DB::table('exportacion_productos')->where('id', $producto->id)->delete();
            $this->fail('La base debería haber rechazado el borrado de un producto con precios asignados.');
        } catch (QueryException) {
            // Es exactamente lo que se espera.
        }

        // Ni el producto ni el precio negociado desaparecieron.
        $this->assertNotNull(ExportacionProducto::find($producto->id));
        $this->assertNotNull(ExportacionClienteProducto::find($asignacion->id));
        $this->assertSame('172.80', (string) $asignacion->fresh()->precio_caja);
    }

    public function test_un_producto_sin_precios_si_se_puede_borrar_desde_la_base(): void
    {
        $producto = $this->producto();

        DB::table('exportacion_productos')->where('id', $producto->id)->delete();

        $this->assertNull(ExportacionProducto::find($producto->id));
    }

    /**
     * El camino normal para retirar un producto sigue siendo archivarlo, y archivar
     * no toca ni un precio.
     */
    public function test_archivar_es_la_operacion_normal_y_conserva_los_precios(): void
    {
        $producto = $this->producto();
        $perfil = ExportacionCliente::create(['nombre' => 'CAROLINAS', 'activo' => true]);
        ExportacionClienteProducto::create([
            'exportacion_cliente_id' => $perfil->id,
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 172.80,
            'activo' => true,
        ]);

        $this->actingAs($this->usuario())
            ->patch(route('productos.exportacion.toggle-activo', $producto))
            ->assertSessionHas('status');

        $this->assertFalse($producto->fresh()->activo);
        $this->assertSame(1, ExportacionClienteProducto::count());
    }

    // =============================================== 2: unicidad del vínculo FEX

    public function test_la_base_impide_vincular_la_misma_factura_a_dos_listas(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil, 'lista' => $primera] = $this->escenario();
        $segunda = Exportacion::create([
            'exportacion_cliente_id' => $perfil->id,
            'cliente_nombre' => $cliente->nombre,
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-09-01',
            'estado' => Exportacion::ESTADO_BORRADOR,
        ]);
        $fex = $this->fex($cliente, 'DTE-11-M001P001-000000000000001');

        DB::table('exportacion_dte')->insert([
            'exportacion_id' => $primera->id, 'dte_id' => $fex->id, 'principal' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            DB::table('exportacion_dte')->insert([
                'exportacion_id' => $segunda->id, 'dte_id' => $fex->id, 'principal' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('La base debería impedir que una FEX pertenezca a dos listas.');
        } catch (QueryException) {
            // Índice único sobre dte_id.
        }

        $this->assertSame(1, DB::table('exportacion_dte')->where('dte_id', $fex->id)->count());
    }

    public function test_la_base_impide_duplicar_el_mismo_vinculo(): void
    {
        ['cliente' => $cliente, 'lista' => $lista] = $this->escenario();
        $fex = $this->fex($cliente, 'DTE-11-M001P001-000000000000001');

        $fila = [
            'exportacion_id' => $lista->id, 'dte_id' => $fex->id, 'principal' => true,
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('exportacion_dte')->insert($fila);

        try {
            DB::table('exportacion_dte')->insert($fila);
            $this->fail('La base debería impedir duplicar el mismo vínculo.');
        } catch (QueryException) {
            // Índice único sobre (exportacion_id, dte_id).
        }

        $this->assertSame(1, DB::table('exportacion_dte')->count());
    }

    /**
     * Un DTE es evidencia fiscal: la base impide borrarlo mientras una lista lo
     * referencie, en vez de dejar el vínculo apuntando al vacío.
     */
    public function test_la_base_impide_borrar_un_dte_vinculado_a_una_lista(): void
    {
        ['cliente' => $cliente, 'lista' => $lista] = $this->escenario();
        $fex = $this->fex($cliente, 'DTE-11-M001P001-000000000000001');
        $lista->dtes()->attach($fex->id, ['principal' => true]);

        try {
            DB::table('dtes')->where('id', $fex->id)->delete();
            $this->fail('La base debería impedir borrar un DTE vinculado a una lista.');
        } catch (QueryException) {
            // restrictOnDelete en exportacion_dte.dte_id.
        }

        $this->assertNotNull(Dte::find($fex->id));
    }

    public function test_los_indices_unicos_del_pivote_existen(): void
    {
        $this->assertTrue(Schema::hasTable('exportacion_dte'));
        $this->assertTrue(Schema::hasColumns('exportacion_dte', ['exportacion_id', 'dte_id', 'principal']));

        // Se comprueba por comportamiento y no leyendo el catálogo del motor: así la
        // prueba vale igual en SQLite y en MySQL.
        ['cliente' => $cliente, 'lista' => $lista] = $this->escenario();
        $fex = $this->fex($cliente, 'DTE-11-M001P001-000000000000009');
        $lista->dtes()->attach($fex->id, ['principal' => true]);

        $this->expectException(QueryException::class);
        DB::table('exportacion_dte')->insert([
            'exportacion_id' => $lista->id, 'dte_id' => $fex->id, 'principal' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
