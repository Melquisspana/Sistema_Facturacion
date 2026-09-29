<?php

namespace Tests\Feature\Ppq;

use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Ppq\ExcelCallejaExporter;
use App\Support\Sala;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * La DIFERENCIA de un item PPQ se muestra con el mismo signo que los montos que acompaña.
 *
 * En el Excel de cobro y en la ficha del lote, el monto del albarán (D) y el del CCF/NC (G)
 * van con signo: la NC resta. La diferencia (J / «Dif») salía de la columna persistida
 * `diferencia`, que es `monto_dte − monto_albaran` SIN signo, así que en una NC tenía el
 * signo contrario a G − D y la columna J no sumaba lo mismo que la diferencia del lote.
 *
 * Y una NC que difiere de SU albarán de crédito no es una «Posible NC/devolución»: ya lo es.
 */
class PpqDiferenciaSignoTest extends TestCase
{
    use RefreshDatabase;

    private const OC = '26050230001794';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sala::olvidarCache();
        $this->seed(DatosInicialesNegritaSeeder::class);
    }

    private function lote(): PpqLote
    {
        return PpqLote::create(['referencia' => 'PPQ signo', 'fecha' => now(), 'estado' => 'borrador']);
    }

    /** Item con o sin albarán. La columna `diferencia` la calcula el modelo al guardar. */
    private function item(PpqLote $lote, string $tipo, int $correlativo, float $montoDte, ?float $montoAlbaran, ?string $numeroAlbaran = null): PpqItem
    {
        $albaran = $montoAlbaran === null ? null : PpqAlbaran::create([
            'numero_albaran' => $numeroAlbaran,
            'numero_orden_compra' => self::OC,
            'monto_albaran' => $montoAlbaran,
            'fecha_albaran' => '2026-06-15',
            'origen' => 'manual',
        ]);

        return $lote->items()->create([
            'origen' => 'gmail',
            'tipo_dte' => $tipo,
            'numero_control' => 'DTE-'.$tipo.'-M001P002-'.str_pad((string) $correlativo, 15, '0', STR_PAD_LEFT),
            'numero_orden_compra' => self::OC,
            'monto_dte' => $montoDte,
            'ppq_albaran_id' => $albaran?->id,
            'monto_albaran' => $montoAlbaran,
            'sin_albaran' => $albaran === null,
        ]);
    }

    /** CCF 100 contra albarán de entrega 90; NC 20 contra su albarán de crédito 18.50. */
    private function loteMixto(): PpqLote
    {
        $lote = $this->lote();
        $this->item($lote, '03', 1, 100.00, 90.00, 'AC01/0230/00/1');
        $this->item($lote, '05', 2, 20.00, 18.50, 'AC04/0230/00/2');

        return $lote->fresh();
    }

    private function hoja(PpqLote $lote)
    {
        $ruta = app(ExcelCallejaExporter::class)->generar($lote);
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);

        return $hoja;
    }

    public function test_en_el_excel_la_diferencia_es_g_menos_d_tambien_en_la_nc(): void
    {
        $lote = $this->loteMixto();
        $hoja = $this->hoja($lote);

        // Fila 2: CCF (sin cambios respecto de antes).
        $this->assertEqualsWithDelta(90.00, (float) $hoja->getCell('D2')->getValue(), 0.001);
        $this->assertEqualsWithDelta(100.00, (float) $hoja->getCell('G2')->getValue(), 0.001);
        $this->assertEqualsWithDelta(10.00, (float) $hoja->getCell('J2')->getValue(), 0.001);

        // Fila 3: NC. D y G restan; J = G − D = −20 − (−18.50) = −1.50, no +1.50.
        $this->assertEqualsWithDelta(-18.50, (float) $hoja->getCell('D3')->getValue(), 0.001);
        $this->assertEqualsWithDelta(-20.00, (float) $hoja->getCell('G3')->getValue(), 0.001);
        $this->assertEqualsWithDelta(-1.50, (float) $hoja->getCell('J3')->getValue(), 0.001);

        // Con todos los items conciliables, la columna J suma la diferencia del lote.
        $sumaJ = (float) $hoja->getCell('J2')->getValue() + (float) $hoja->getCell('J3')->getValue();
        $this->assertEqualsWithDelta($lote->diferenciaTotal(), $sumaJ, 0.001);

        // La columna persistida no cambió de convención: la usan tolerancia y clasificación.
        $nc = $lote->items->firstWhere('tipo_dte', '05');
        $this->assertEqualsWithDelta(1.50, (float) $nc->diferencia, 0.001);
    }

    public function test_sin_albaran_la_columna_j_queda_vacia(): void
    {
        $lote = $this->lote();
        $this->item($lote, '03', 1, 100.00, null);

        $hoja = $this->hoja($lote->fresh());

        $this->assertEqualsWithDelta(100.00, (float) $hoja->getCell('G2')->getValue(), 0.001);
        $this->assertNull($hoja->getCell('D2')->getValue());
        $this->assertNull($hoja->getCell('J2')->getValue());
    }

    public function test_la_ficha_del_lote_muestra_la_diferencia_firmada_y_no_llama_posible_nc_a_una_nc(): void
    {
        $lote = $this->loteMixto();

        $resp = $this->actingAs(User::factory()->create()->assignRole('administrador'))
            ->get(route('ppq.lotes.show', $lote));

        $resp->assertOk();
        // La NC difiere en 1.50 (> 1.00): alerta propia, no «posible NC».
        $resp->assertSee('Difiere del albarán de crédito', false);
        $resp->assertSee('Dif −$1.50', false);
        // El CCF de 100 contra 90 conserva su etiqueta y su diferencia positiva.
        $resp->assertSee('Posible NC/devolución', false);
        $resp->assertSee('Dif $10.00', false);
    }
}
