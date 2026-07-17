<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoProducto;
use App\Exceptions\Dte\DocumentoInmutableException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use Database\Seeders\CatalogosMhSeeder;
use Database\Seeders\CatalogosMhTablaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * DteBorradorService::agregarLineaLibre(): línea SIN producto de catálogo,
 * reservada a Factura de Exportación (11). Pensada para copiar líneas desde una
 * Lista de Empaque, pero probada aquí a nivel de servicio (sin el orquestador).
 */
class DteBorradorServiceAgregarLineaLibreTest extends TestCase
{
    use RefreshDatabase;

    private DteBorradorService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosMhSeeder::class);
        $this->seed(CatalogosMhTablaSeeder::class);
        $this->service = app(DteBorradorService::class);
        $this->user = User::factory()->create();
    }

    private function fex(): Dte
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        $cliente = Cliente::factory()->exportacion()->create();

        return $this->service->crearBorrador([
            'tipo_dte' => TipoDte::FacturaExportacion,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'tipo_item_expor' => 1,
            'recinto_fiscal' => '01',
            'tipo_regimen' => 'EX-1',
            'regimen' => '1000.000',
            'cod_incoterms' => '09',
        ], $this->user);
    }

    private function ccf(): Dte
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        $cliente = Cliente::factory()->contribuyente()->create();

        return $this->service->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ], $this->user);
    }

    public function test_agrega_linea_libre_sin_producto(): void
    {
        $dte = $this->fex();

        $linea = $this->service->agregarLineaLibre($dte, [
            'descripcion' => 'Canillitas 85 g',
            'unidad_codigo' => '99',
            'cantidad' => 10,
            'precio_unitario' => 18.00,
        ]);

        $this->assertNull($linea->producto_id);
        $this->assertSame('Canillitas 85 g', $linea->descripcion);
        $this->assertSame('99', $linea->unidad_codigo);
        $this->assertSame('10.0000', $linea->cantidad);
        $this->assertSame('18.000000', $linea->precio_unitario);
        $this->assertSame('0.00', $linea->descuento_monto);
        $this->assertSame(TipoProducto::Bien, $linea->tipo_producto);
        $this->assertSame(TipoImpuesto::Gravado, $linea->tipo_impuesto);
        $this->assertSame('180.00', $linea->total_linea);

        $dte->refresh();
        $this->assertSame('180.00', $dte->total_exportacion);
        $this->assertSame('180.00', $dte->total_pagar);
        $this->assertSame('0.00', $dte->iva);
    }

    public function test_rechaza_documento_que_no_es_fex(): void
    {
        $dte = $this->ccf();

        $this->expectException(ValidationException::class);
        $this->service->agregarLineaLibre($dte, [
            'descripcion' => 'Concepto libre',
            'unidad_codigo' => '99',
            'cantidad' => 1,
            'precio_unitario' => 10,
        ]);
    }

    public function test_rechaza_dte_no_editable(): void
    {
        $dte = $this->fex();
        $dte->estado = EstadoDte::Generado;
        $dte->save();

        $this->expectException(DocumentoInmutableException::class);
        $this->service->agregarLineaLibre($dte, [
            'descripcion' => 'Canillitas 85 g',
            'unidad_codigo' => '99',
            'cantidad' => 10,
            'precio_unitario' => 18,
        ]);
    }

    public function test_rechaza_descripcion_vacia(): void
    {
        $dte = $this->fex();

        $this->expectException(ValidationException::class);
        $this->service->agregarLineaLibre($dte, [
            'descripcion' => '',
            'unidad_codigo' => '99',
            'cantidad' => 10,
            'precio_unitario' => 18,
        ]);
    }

    public function test_rechaza_cantidad_cero(): void
    {
        $dte = $this->fex();

        $this->expectException(ValidationException::class);
        $this->service->agregarLineaLibre($dte, [
            'descripcion' => 'Canillitas 85 g',
            'unidad_codigo' => '99',
            'cantidad' => 0,
            'precio_unitario' => 18,
        ]);
    }

    public function test_rechaza_precio_negativo(): void
    {
        $dte = $this->fex();

        $this->expectException(ValidationException::class);
        $this->service->agregarLineaLibre($dte, [
            'descripcion' => 'Canillitas 85 g',
            'unidad_codigo' => '99',
            'cantidad' => 10,
            'precio_unitario' => -1,
        ]);
    }

    public function test_rechaza_falta_unidad_codigo(): void
    {
        $dte = $this->fex();

        $this->expectException(ValidationException::class);
        $this->service->agregarLineaLibre($dte, [
            'descripcion' => 'Canillitas 85 g',
            'cantidad' => 10,
            'precio_unitario' => 18,
        ]);
    }
}
