<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DteGeneradoInmutableTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // El listado principal muestra SOLO producción (ambiente 01): estos DTEs deben nacer
        // en producción para aparecer en el listado verificado.
        config(['dte.ambiente' => '01']);
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '01', 'ultimo_numero' => 0, 'activo' => true]);

        return compact('estab', 'pv');
    }

    private function ccfBorrador(array $emisor): Dte
    {
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);

        return $dte->refresh();
    }

    private function ccfGenerado(array $emisor): Dte
    {
        $dte = $this->ccfBorrador($emisor);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    public function test_borrador_muestra_editar_y_eliminar_en_listado(): void
    {
        $this->ccfBorrador($this->emisor());

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.index'))
            ->assertOk()
            ->assertSee('Editar')
            ->assertSee('Eliminar');
    }

    public function test_generado_no_muestra_editar_ni_eliminar_en_listado(): void
    {
        $this->ccfGenerado($this->emisor());

        $html = $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.index'))
            ->assertOk()
            ->assertDontSee('Eliminar')
            ->getContent();

        // No debe haber enlace de edición hacia el documento generado.
        $this->assertStringNotContainsString('/editar', $html);
    }

    public function test_generado_no_permite_editar_por_url(): void
    {
        $dte = $this->ccfGenerado($this->emisor());

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.edit', $dte))
            ->assertForbidden();
    }

    public function test_generado_no_permite_eliminar(): void
    {
        $dte = $this->ccfGenerado($this->emisor());

        $this->actingAs($this->usuario('facturacion'))
            ->delete(route('facturacion.destroy', $dte))
            ->assertForbidden();

        $this->assertDatabaseHas('dtes', ['id' => $dte->id, 'deleted_at' => null]);
    }

    public function test_generado_no_permite_modificar_lineas(): void
    {
        $dte = $this->ccfGenerado($this->emisor());
        $linea = $dte->lineas()->first();
        $producto = Producto::factory()->create(['tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $facturacion = $this->usuario('facturacion');

        $this->actingAs($facturacion)
            ->post(route('facturacion.lineas.store', $dte), ['producto_id' => $producto->id, 'cantidad' => 1])
            ->assertForbidden();
        $this->actingAs($facturacion)
            ->patch(route('facturacion.lineas.update', [$dte, $linea]), ['cantidad' => 5])
            ->assertForbidden();
        $this->actingAs($facturacion)
            ->delete(route('facturacion.lineas.destroy', [$dte, $linea]))
            ->assertForbidden();
    }

    public function test_borrador_si_permite_editar_y_eliminar(): void
    {
        $dte = $this->ccfBorrador($this->emisor());
        $facturacion = $this->usuario('facturacion');

        $this->actingAs($facturacion)->get(route('facturacion.edit', $dte))->assertOk();
        $this->actingAs($facturacion)->delete(route('facturacion.destroy', $dte))->assertRedirect();
        $this->assertSoftDeleted('dtes', ['id' => $dte->id]);
    }

    public function test_impresion_borrador_muestra_marca_borrador(): void
    {
        $dte = $this->ccfBorrador($this->emisor());

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.imprimir', $dte))
            ->assertOk()
            ->assertSee('BORRADOR');
    }

    public function test_impresion_generado_no_muestra_opciones_de_edicion(): void
    {
        $dte = $this->ccfGenerado($this->emisor());

        $html = $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.imprimir', $dte))
            ->assertOk()
            ->assertDontSee('BORRADOR')
            ->getContent();

        $this->assertStringNotContainsString('Editar', $html);
        $this->assertStringNotContainsString('Eliminar', $html);
    }
}
