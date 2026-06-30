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

class DteImpresionTest extends TestCase
{
    use RefreshDatabase;

    private DteBorradorService $borradores;

    private DteGeneracionService $generacion;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CatalogosMhSeeder::class);

        $this->borradores = app(DteBorradorService::class);
        $this->generacion = app(DteGeneracionService::class);

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'nit' => '0614-000000-000-0', 'ambiente' => '00', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $this->pv = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        foreach (['01', '03', '05', '11'] as $tipo) {
            Correlativo::create(['tipo_dte' => $tipo, 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        }
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function producto(): Producto
    {
        return Producto::factory()->create(['nombre' => 'Dulce de leche artesanal', 'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
    }

    private function borradorConLinea(TipoDte $tipo, ?Cliente $cliente, array $extra = []): Dte
    {
        $dte = $this->borradores->crearBorrador(array_merge([
            'tipo_dte' => $tipo,
            'cliente_id' => $cliente,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ], $extra));
        $this->borradores->agregarLineaDesdeProducto($dte, $this->producto(), cantidad: 10);

        return $dte->refresh();
    }

    private function generar(Dte $dte): Dte
    {
        $this->generacion->generar($dte);

        return $dte->refresh();
    }

    public function test_imprimir_ccf_generado(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja S.A. de C.V.']);
        $ccf = $this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, $cliente));

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.imprimir', $ccf))
            ->assertOk()
            ->assertSee('Representación gráfica preliminar')
            ->assertSee($ccf->numero_interno)
            ->assertSee('Calleja S.A. de C.V.')
            ->assertSee('Dulce de leche artesanal')
            ->assertSee('113.00'); // total con IVA
    }

    public function test_imprimir_factura_generada(): void
    {
        $factura = $this->generar($this->borradorConLinea(TipoDte::Factura, null));

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.imprimir', $factura))
            ->assertOk()
            ->assertSee('Factura')
            ->assertSee($factura->numero_interno);
    }

    public function test_imprimir_exportacion_generada(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $fex = $this->generar($this->borradorConLinea(TipoDte::FacturaExportacion, $cliente, ['flete' => 5, 'seguro' => 2]));

        $this->actingAs($this->usuario('contador'))
            ->get(route('facturacion.imprimir', $fex))
            ->assertOk()
            ->assertSee('Exportación')
            ->assertSee('Flete');
    }

    public function test_imprimir_nota_credito_generada(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $ccf = $this->aceptarCcf($this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, $cliente))); // la NC exige CCF aceptado
        $nc = $this->borradores->crearNotaCredito($ccf);
        $this->borradores->acreditarLinea($nc, $ccf->lineas()->first(), cantidad: 4);
        $nc = $this->generar($nc);

        $this->actingAs($this->usuario('consulta'))
            ->get(route('facturacion.imprimir', $nc))
            ->assertOk()
            ->assertSee($nc->numero_interno)
            ->assertSee($ccf->numero_control); // documento original relacionado (N° oficial MH)
    }

    public function test_invitado_no_puede_imprimir(): void
    {
        $ccf = $this->generar($this->borradorConLinea(TipoDte::CreditoFiscal, Cliente::factory()->contribuyente()->create()));

        $this->get(route('facturacion.imprimir', $ccf))->assertRedirect('/login');
    }

    public function test_borrador_muestra_marca_de_borrador(): void
    {
        $ccf = $this->borradorConLinea(TipoDte::CreditoFiscal, Cliente::factory()->contribuyente()->create());

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.imprimir', $ccf))
            ->assertOk()
            ->assertSee('BORRADOR');
    }

    public function test_imprimir_muestra_departamento_municipio_distrito(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $olocuilta = \App\Models\Distrito::where('nombre', 'Olocuilta')->firstOrFail();
        $sucursal = \App\Models\ClienteSucursal::create([
            'cliente_id' => $cliente->id,
            'nombre' => 'Súper Selectos Olocuilta',
            'departamento_id' => $olocuilta->departamento_id,
            'distrito_id' => $olocuilta->id,
            'activo' => true,
        ]);

        $ccf = $this->generar($this->borradorConLinea(
            TipoDte::CreditoFiscal, $cliente, ['cliente_sucursal_id' => $sucursal->id],
        ));

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.imprimir', $ccf))
            ->assertOk()
            ->assertSee('Departamento: La Paz')
            ->assertSee('Municipio: La Paz Oeste')
            ->assertSee('Distrito: Olocuilta');
    }
}
