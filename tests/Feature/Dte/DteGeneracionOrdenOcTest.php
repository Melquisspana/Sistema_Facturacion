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
use Tests\Concerns\RepresentacionPdfDte;
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
    use RepresentacionPdfDte;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(DatosInicialesNegritaSeeder::class);

        // El seeder ya no trae estos productos (su catálogo es de ejemplo). El orden de
        // la OC se decide por código de barra en OrdenProductosOc, así que la prueba
        // crea los que necesita con los códigos que esa lista conoce.
        foreach (self::PRODUCTOS as $barra => [$nombre]) {
            Producto::factory()->create(['nombre' => $nombre, 'codigo_barra' => (string) $barra, 'precio_unitario' => '1.00']);
        }
    }

    /** código de barra => [nombre, cantidad], en el orden REVUELTO en que se agregan (no el de la OC). */
    private const PRODUCTOS = [
        '7412201700115' => ['MAZAPÁN', 5],                      // rank 16
        '7412201700031' => ['CANILLITAS', 10],                  // rank 8
        '7412201700178' => ['SEMILLA DE MARAÑON HORNEADA', 3],  // rank 0
        '7412201700135' => ['MIX', 2],                          // fuera
        '7412201700024' => ['LECHE DE BURRA', 4],               // rank 1
    ];

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
        foreach (self::PRODUCTOS as $barra => [, $cantidad]) {
            $this->add($dte, (string) $barra, $cantidad);
        }

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

        $dte->refresh();
        $this->assertImprimeElPdf($dte, User::factory()->create()->assignRole('facturacion'));

        // El orden de las líneas se comprueba sobre la representación real.
        $html = $this->htmlDelPdf($dte);
        $desde = 0;
        foreach (self::ORDEN_ESPERADO as $texto) {
            $posicion = strpos($html, $texto, $desde);
            $this->assertNotFalse($posicion, "«{$texto}» no aparece después de la línea anterior: el orden de la OC no se respetó.");
            $desde = $posicion + strlen($texto);
        }
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
