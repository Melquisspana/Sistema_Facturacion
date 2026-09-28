<?php

namespace Tests\Feature\Exportaciones;

use App\Models\Cliente;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionItem;
use App\Models\ExportacionProducto;
use App\Models\User;
use App\Services\Exportaciones\ListaPreciosExportacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El precio vigente de cada cliente sale de su última lista de empaque, salvo
 * que ya tenga un precio más nuevo. Ver docs/DISENO_CATALOGO_EXPORTACION.md.
 */
class PrecioVigenteDesdeListaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function producto(string $nombre = 'Maní dulce'): ExportacionProducto
    {
        return ExportacionProducto::create([
            'nombre_es' => $nombre, 'nombre_en' => 'Sweet baked peanut', 'unidad' => 'Bolsa de polipropileno 12x12',
            'unidades_por_caja' => 144, 'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3, 'precio_caja' => 120,
            'peso_neto_caja_kg' => 13, 'peso_bruto_caja_kg' => 14, 'peso_neto_caja_lb' => 28.66, 'peso_bruto_caja_lb' => 30.86,
            'activo' => true,
        ]);
    }

    private function cliente(): ExportacionCliente
    {
        $clienteDte = Cliente::factory()->exportacion()->create(['nombre' => 'IMPORTADOR NORTE LLC']);

        return ExportacionCliente::create(['cliente_id' => $clienteDte->id, 'nombre' => $clienteDte->nombre, 'activo' => true]);
    }

    private function lista(ExportacionCliente $cliente, string $fecha, array $items): Exportacion
    {
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $cliente->id, 'cliente_nombre' => $cliente->nombre,
            'exportador_nombre' => 'Dulces La Negrita', 'fecha' => $fecha, 'estado' => Exportacion::ESTADO_BORRADOR,
        ]);

        foreach ($items as [$producto, $precio]) {
            $lista->items()->create(['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 3,
                'precio_caja' => $precio] + $producto->datosSnapshot());
        }

        return $lista;
    }

    private function precio(ExportacionCliente $cliente, ExportacionProducto $producto): ?string
    {
        return ExportacionClienteProducto::where('exportacion_cliente_id', $cliente->id)
            ->where('exportacion_producto_id', $producto->id)->value('precio_caja');
    }

    public function test_la_lista_deja_su_precio_como_vigente_y_crea_el_que_falta(): void
    {
        $cliente = $this->cliente();
        $mani = $this->producto();
        $coco = $this->producto('Coco rayado');
        $cliente->productos()->create(['exportacion_producto_id' => $mani->id, 'precio_caja' => 120, 'precio_fijado_en' => '2026-07-01', 'activo' => false]);

        $lista = $this->lista($cliente, '2026-09-23', [[$mani, 136.80], [$coco, 136.80]]);
        $cambios = app(ListaPreciosExportacion::class)->actualizarDesdeLista($lista);

        $this->assertSame('136.80', $this->precio($cliente, $mani));
        $this->assertSame('136.80', $this->precio($cliente, $coco));
        $asignacion = $cliente->productos()->where('exportacion_producto_id', $mani->id)->sole();
        $this->assertTrue($asignacion->activo, 'la asignación apagada se reactiva');
        $this->assertSame('2026-09-23', $asignacion->precio_fijado_en->toDateString());
        $this->assertSame($lista->id, $asignacion->precio_desde_exportacion_id);
        $this->assertCount(2, $cambios);
        $this->assertSame(120.0, $cambios[0]['antes']);
        $this->assertNull($cambios[1]['antes']);
    }

    public function test_una_lista_vieja_finalizada_tarde_no_pisa_un_precio_mas_nuevo(): void
    {
        $cliente = $this->cliente();
        $mani = $this->producto();
        $cliente->productos()->create(['exportacion_producto_id' => $mani->id, 'precio_caja' => 140, 'precio_fijado_en' => '2026-09-20', 'activo' => true]);

        $vieja = $this->lista($cliente, '2026-09-10', [[$mani, 136.80]]);
        app(ListaPreciosExportacion::class)->actualizarDesdeLista($vieja);

        $this->assertSame('140.00', $this->precio($cliente, $mani));
    }

    public function test_al_armar_la_lista_el_precio_se_puede_cambiar_pero_no_en_cero(): void
    {
        $cliente = $this->cliente();
        $mani = $this->producto();
        $usuario = User::factory()->create()->assignRole('facturacion');
        $payload = fn (array $item) => [
            'exportacion_cliente_id' => $cliente->id, 'cliente_nombre' => $cliente->nombre,
            'exportador_nombre' => 'Dulces La Negrita', 'fecha' => '2026-09-23',
            'items' => [['exportacion_producto_id' => $mani->id, 'cantidad_cajas' => 3] + $item],
        ];

        $this->actingAs($usuario)->post(route('facturacion.listas.store'), $payload(['precio_caja' => 0]))
            ->assertSessionHasErrors('items');
        $this->assertSame(0, ExportacionItem::count());

        $this->actingAs($usuario)->post(route('facturacion.listas.store'), $payload(['precio_caja' => 145.50]))
            ->assertSessionHasNoErrors();

        $this->assertSame('145.50', ExportacionItem::sole()->precio_caja);
    }
}
