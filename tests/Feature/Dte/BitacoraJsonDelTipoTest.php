<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Producto;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * La bitácora del JSON dice el tipo de documento que se generó. Antes decía «del CCF»
 * también para la Factura 01 de la tienda web.
 */
class BitacoraJsonDelTipoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    public static function tipos(): array
    {
        return [
            'Factura' => [TipoDte::Factura, 'generó el JSON oficial preliminar (Factura, sin firma ni transmisión)'],
            'CCF' => [TipoDte::CreditoFiscal, 'generó el JSON oficial preliminar (Comprobante de Crédito Fiscal, sin firma ni transmisión)'],
            'Exportación' => [TipoDte::FacturaExportacion, 'generó el JSON oficial preliminar (Factura de Exportación, sin firma ni transmisión)'],
        ];
    }

    #[DataProvider('tipos')]
    public function test_la_bitacora_del_json_dice_el_tipo_del_documento(TipoDte $tipo, string $esperado): void
    {
        Storage::fake('local');
        $this->seedCatalogosDte();
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        Correlativo::create([
            'tipo_dte' => $tipo->value, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
        ]);
        $datos = [
            'tipo_dte' => $tipo, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'cliente_id' => match ($tipo) {
                TipoDte::Factura => null,
                TipoDte::FacturaExportacion => Cliente::factory()->exportacion()->create()->id,
                default => Cliente::factory()->contribuyente()->create()->id,
            },
        ];
        if ($tipo === TipoDte::FacturaExportacion) {
            $datos += ['tipo_item_expor' => 1, 'recinto_fiscal' => '01', 'tipo_regimen' => 'EX-1', 'regimen' => '1000.000', 'cod_incoterms' => '09'];
        }
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador($datos);
        $borradores->agregarLineaDesdeProducto($dte, Producto::factory()->create([
            'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]), cantidad: 2);

        app(DteGeneracionService::class)->generar($dte);

        $anotacion = Activity::where('log_name', 'dte_json')->where('subject_id', $dte->id)->sole();
        $this->assertSame($esperado, $anotacion->description);
    }
}
