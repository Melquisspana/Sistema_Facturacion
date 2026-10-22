<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Producto;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Support\Dinero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Normativa 2.0, Anexo IV, campos 102 y 157 (FE): `ivaItem` = (ventaGravada ÷ 1.13) × 13 %
 * por ítem, y `resumen.totalIva` = suma de los `ivaItem` − IVA del descuento global, con
 * holgura de ±0.01 (regla 7.3). Issue #42, H8: con muchas líneas de importe chico el
 * redondeo por ítem se acumula; esta prueba mide si el JSON queda dentro de la holgura.
 */
class IvaFacturaHacienda20Test extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private array $emisor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedCatalogosDte();
        $this->emisor = $this->crearEmisorDte();
        Correlativo::create([
            'tipo_dte' => '01', 'establecimiento_id' => $this->emisor['estab']->id,
            'punto_venta_id' => $this->emisor['pv']->id, 'ambiente' => '00',
            'ultimo_numero' => 0, 'activo' => true,
        ]);
    }

    /** @return array<string, mixed> JSON oficial de una FE con $lineas productos de $precio (IVA incluido). */
    private function jsonFactura(int $lineas, string $precio, array $extra = []): array
    {
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador(array_merge([
            'tipo_dte' => TipoDte::Factura,
            'establecimiento_id' => $this->emisor['estab']->id,
            'punto_venta_id' => $this->emisor['pv']->id,
        ], $extra));
        for ($i = 1; $i <= $lineas; $i++) {
            $producto = Producto::factory()->create(['nombre' => "Dulce {$i}", 'precio_unitario' => $precio, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
            $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 1);
        }
        app(DteGeneracionService::class)->generar($dte->refresh());
        $dte = Dte::query()->findOrFail($dte->id);

        return json_decode((string) Storage::disk('local')->get($dte->json_generado_path), true);
    }

    private function sumaIvaItem(array $json): string
    {
        return array_reduce($json['cuerpoDocumento'], fn (string $t, array $l) => Dinero::sumar($t, (string) $l['ivaItem']), '0');
    }

    public function test_cada_iva_item_respeta_la_formula_por_item(): void
    {
        $json = $this->jsonFactura(20, '1.01');

        foreach ($json['cuerpoDocumento'] as $linea) {
            $esperado = round($linea['ventaGravada'] / 1.13 * 0.13, 2);
            $this->assertEqualsWithDelta($esperado, $linea['ivaItem'], 0.01, 'ivaItem fuera de la holgura en la línea '.$linea['numItem']);
        }
    }

    public function test_total_iva_es_la_suma_de_los_iva_item_dentro_de_la_holgura(): void
    {
        $json = $this->jsonFactura(20, '1.01');

        $this->assertEqualsWithDelta(
            (float) $this->sumaIvaItem($json),
            $json['resumen']['totalIva'],
            0.01,
            'resumen.totalIva debe ser la suma de los ivaItem (campo 157), con ±0.01.'
        );
    }

    public function test_con_descuento_global_resta_solo_el_iva_del_descuento(): void
    {
        // El descuento global sale del % del cliente (descuento_global_default).
        $cliente = Cliente::factory()->contribuyente()->create(['descuento_global_default' => 10, 'correo' => 'fe@cliente.example.com']);
        $json = $this->jsonFactura(20, '1.01', ['cliente_id' => $cliente->id]);
        $this->assertGreaterThan(0, $json['resumen']['descuGravada'], 'El fixture debe tener descuento global gravado.');

        $ivaDescuento = round($json['resumen']['descuGravada'] / 1.13 * 0.13, 2);
        $this->assertEqualsWithDelta(
            (float) $this->sumaIvaItem($json) - $ivaDescuento,
            $json['resumen']['totalIva'],
            0.01,
            'resumen.totalIva = suma de ivaItem − IVA del descuento global gravado (campo 157).'
        );
    }
}
