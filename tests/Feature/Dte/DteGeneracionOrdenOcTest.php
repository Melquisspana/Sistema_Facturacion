<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Al generar un CCF se ordenan las líneas según la orden de compra (OrdenProductosOc) y
 * se reasigna numero_linea/numItem 1..n en ese orden; PDF y JSON quedan iguales y los
 * totales no cambian. La reordenación es la que ejecuta DteGeneracionService::generar()
 * sobre el borrador antes de transicionar; se prueba aquí de forma aislada porque el
 * armado del JSON oficial exige catálogos MH (catalogos_mh) que no se seedean en tests.
 */
class DteGeneracionOrdenOcTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(DatosInicialesNegritaSeeder::class);
    }

    private const ORDEN_ESPERADO = [
        'SEMILLA DE MARAÑON HORNEADA', // rank 0
        'LECHE DE BURRA',              // rank 1
        'CANILLITAS',                  // rank 8
        'MAZAPÁN',                     // rank 16
        'MIX',                         // fuera de la OC → al final
    ];

    private function borrador(TipoDte $tipo = TipoDte::CreditoFiscal): Dte
    {
        $calleja = Cliente::where('nombre', 'Calleja, S.A. de C.V.')->firstOrFail();
        $estab = Establecimiento::where('codigo', 'M001')->firstOrFail();
        $pv = PuntoVenta::where('codigo', 'P001')->firstOrFail();

        return app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => $tipo,
            'cliente_id' => $calleja->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'numero_orden_compra' => 'OC-ORDEN-1',
        ]);
    }

    private function add(Dte $dte, string $barra, int $cant): void
    {
        $p = Producto::where('codigo_barra', $barra)->firstOrFail();
        app(DteBorradorService::class)->establecerCantidadProducto($dte, $p, $cant);
    }

    /** CCF con productos agregados en orden REVUELTO (no OC). */
    private function ccfRevuelto(TipoDte $tipo = TipoDte::CreditoFiscal): Dte
    {
        $dte = $this->borrador($tipo);
        $this->add($dte, '7412201700115', 5);  // MAZAPÁN          rank 16
        $this->add($dte, '7412201700031', 10); // CANILLITAS       rank 8
        $this->add($dte, '7412201700178', 3);  // SEMILLA HORNEADA rank 0
        $this->add($dte, '7412201700135', 2);  // MIX              fuera
        $this->add($dte, '7412201700024', 4);  // LECHE DE BURRA   rank 1

        return $dte->refresh();
    }

    public function test_reordena_lineas_en_orden_oc(): void
    {
        $dte = $this->ccfRevuelto();

        app(DteGeneracionService::class)->reordenarLineasSegunOc($dte);
        $dte->refresh()->load('lineas');

        $this->assertSame(self::ORDEN_ESPERADO, $dte->lineas->pluck('descripcion')->all());
    }

    public function test_numero_linea_numitem_queda_secuencial_1_a_n(): void
    {
        $dte = $this->ccfRevuelto();

        app(DteGeneracionService::class)->reordenarLineasSegunOc($dte);
        $dte->refresh()->load('lineas');

        // numero_linea es la fuente de numItem en el JSON y del orden en el PDF.
        $this->assertSame(range(1, 5), $dte->lineas->pluck('numero_linea')->map(fn ($n) => (int) $n)->all());
    }

    public function test_impresion_pdf_lista_productos_en_orden_oc(): void
    {
        $dte = $this->ccfRevuelto();
        app(DteGeneracionService::class)->reordenarLineasSegunOc($dte);

        $this->actingAs(User::factory()->create()->assignRole('facturacion'))
            ->get(route('facturacion.imprimir', $dte->refresh()))
            ->assertOk()
            ->assertSeeInOrder(self::ORDEN_ESPERADO, false);
    }

    public function test_los_totales_no_cambian_al_reordenar(): void
    {
        $dte = $this->ccfRevuelto();
        $antes = [
            'gravado' => $dte->total_gravado,
            'descuento' => $dte->total_descuento,
            'iva' => $dte->iva,
            'iva_retenido' => $dte->iva_retenido,
            'total' => $dte->total_pagar,
        ];

        app(DteGeneracionService::class)->reordenarLineasSegunOc($dte);
        $dte->refresh();

        $this->assertSame($antes['gravado'], $dte->total_gravado);
        $this->assertSame($antes['descuento'], $dte->total_descuento);
        $this->assertSame($antes['iva'], $dte->iva);
        $this->assertSame($antes['iva_retenido'], $dte->iva_retenido);
        $this->assertSame($antes['total'], $dte->total_pagar);
    }

    public function test_no_reordena_documentos_que_no_son_ccf(): void
    {
        // Una factura (01) conserva el orden de inserción (la OC solo aplica al CCF).
        $dte = $this->ccfRevuelto(TipoDte::Factura);
        $ordenInsercion = $dte->lineas->sortBy('numero_linea')->pluck('descripcion')->values()->all();

        app(DteGeneracionService::class)->reordenarLineasSegunOc($dte);
        $dte->refresh()->load('lineas');

        $this->assertSame($ordenInsercion, $dte->lineas->pluck('descripcion')->all());
        $this->assertNotSame(self::ORDEN_ESPERADO, $dte->lineas->pluck('descripcion')->all());
    }
}
