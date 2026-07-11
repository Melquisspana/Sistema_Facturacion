<?php

namespace Tests\Feature\Exportaciones;

use App\Models\Dte;
use App\Models\Exportacion;
use App\Models\ExportacionItem;
use App\Models\ExportacionProducto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fase 1 de "Preparar factura de exportación": vista de AYUDA calculada en vivo
 * desde el snapshot de la lista (descripción es/en - units, cantidad, precio, total),
 * con copiar/descargar Excel y aprobación de la lista.
 *
 * SOLO LECTURA: no es un DTE, no emite, no transmite, no toca correlativos ni
 * Conta Portable; no persiste ninguna factura ni modifica la lista.
 */
class PrepararFacturaExportacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'contador', 'consulta'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function lista(string $estado = 'borrador'): Exportacion
    {
        return Exportacion::create([
            'cliente_nombre' => 'CAROLINAS WHOLESALE LLC',
            'cliente_direccion' => '123 Main St',
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-07-11',
            'estado' => $estado,
        ]);
    }

    private function item(Exportacion $e, array $extra = []): ExportacionItem
    {
        return $e->items()->create($extra + [
            'nombre_es' => 'Caja de dulce de nance',
            'nombre_en' => 'Yellow cherry candy',
            'unidad' => 'Bolsa',
            'unidades_por_caja' => 144,
            'cantidad_cajas' => 3,
            'precio_caja' => 120.96,
            'gramos_por_unidad' => 70,
            'onzas_por_unidad' => 2.47,
            'peso_neto_caja_kg' => 10,
            'peso_bruto_caja_kg' => 11,
            'peso_neto_caja_lb' => 22,
            'peso_bruto_caja_lb' => 24,
        ]);
    }

    // ---------- formato de descripción ----------

    public function test_descripcion_factura_formato_es_en_units(): void
    {
        $item = $this->item($this->lista());

        $this->assertSame('Caja de dulce de nance / Yellow cherry candy - 144 units', $item->descripcionFactura());
    }

    public function test_descripcion_omite_units_si_no_hay_unidades_por_caja(): void
    {
        $item = $this->item($this->lista(), ['unidades_por_caja' => 0]);

        $this->assertSame('Caja de dulce de nance / Yellow cherry candy', $item->descripcionFactura());
    }

    // ---------- líneas desde el snapshot ----------

    public function test_lineas_factura_usan_snapshot_no_el_catalogo_actual(): void
    {
        $producto = ExportacionProducto::create([
            'nombre_es' => 'Caja de dulce de nance', 'nombre_en' => 'Yellow cherry candy',
            'unidad' => 'Bolsa', 'unidades_por_caja' => 144, 'precio_caja' => 120.96,
            'gramos_por_unidad' => 70, 'onzas_por_unidad' => 2.47,
            'peso_neto_caja_kg' => 10, 'peso_bruto_caja_kg' => 11, 'peso_neto_caja_lb' => 22, 'peso_bruto_caja_lb' => 24,
            'activo' => true,
        ]);
        $lista = $this->lista();
        $this->item($lista, ['exportacion_producto_id' => $producto->id, 'precio_caja' => 120.96, 'cantidad_cajas' => 3]);

        // Cambia el precio del catálogo DESPUÉS: la línea preparada NO debe cambiar.
        $producto->update(['precio_caja' => 999.99]);

        $lineas = $lista->fresh()->load('items')->lineasFactura();
        $this->assertCount(1, $lineas);
        $this->assertSame(120.96, $lineas[0]['precio_unitario']);
        $this->assertSame(3, $lineas[0]['cantidad']);
        $this->assertSame(362.88, $lineas[0]['total']); // 3 * 120.96
    }

    // ---------- aprobación ----------

    public function test_marcar_como_aprobada_y_revertir(): void
    {
        $lista = $this->lista();

        $this->actingAs($this->usuario('administrador'))
            ->patch(route('exportaciones.aprobar', $lista))
            ->assertRedirect(route('exportaciones.show', $lista));
        $this->assertTrue($lista->fresh()->estaAprobada());

        $this->actingAs($this->usuario('administrador'))
            ->patch(route('exportaciones.desaprobar', $lista));
        $this->assertFalse($lista->fresh()->estaAprobada());
    }

    // ---------- vista preparar ----------

    public function test_vista_preparar_muestra_lineas_y_total(): void
    {
        $lista = $this->lista('aprobada');
        $this->item($lista);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.preparar-factura', $lista))
            ->assertOk()
            ->assertSee('Caja de dulce de nance / Yellow cherry candy - 144 units')
            ->assertSee('362.88')          // total de línea y total general
            ->assertSee('No es un DTE', false)
            ->assertSee('si editás la lista', false); // aviso de cálculo en vivo
    }

    public function test_vista_preparar_advierte_si_no_aprobada(): void
    {
        $lista = $this->lista('borrador');
        $this->item($lista);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.preparar-factura', $lista))
            ->assertOk()
            ->assertSee('no está aprobada', false);
    }

    public function test_preparar_redirige_si_lista_sin_productos(): void
    {
        $lista = $this->lista();

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.preparar-factura', $lista))
            ->assertRedirect(route('exportaciones.show', $lista))
            ->assertSessionHas('error');
    }

    // ---------- Excel simple ----------

    public function test_excel_simple_se_descarga(): void
    {
        $lista = $this->lista('aprobada');
        $this->item($lista);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.preparar-factura.excel', $lista))
            ->assertOk()
            ->assertDownload('factura_exportacion_lista_'.$lista->id.'.xlsx');
    }

    // ---------- seguridad ----------

    public function test_preparar_no_crea_dte_ni_modifica_la_lista(): void
    {
        $lista = $this->lista('aprobada');
        $item = $this->item($lista);
        $itemAntes = $item->only(['precio_caja', 'cantidad_cajas', 'nombre_es']);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.preparar-factura', $lista))->assertOk();
        $this->actingAs($this->usuario())
            ->get(route('exportaciones.preparar-factura.excel', $lista))->assertOk();

        $this->assertSame(0, Dte::count());
        $this->assertSame($itemAntes, $item->fresh()->only(['precio_caja', 'cantidad_cajas', 'nombre_es']));
        $this->assertSame('aprobada', $lista->fresh()->estado);
    }

    public function test_consulta_no_accede(): void
    {
        $lista = $this->lista('aprobada');
        $this->item($lista);

        $this->actingAs($this->usuario('consulta'))
            ->get(route('exportaciones.preparar-factura', $lista))
            ->assertForbidden();
    }
}
