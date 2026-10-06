<?php

namespace Tests\Feature\Ppq;

use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Services\Ppq\AlbaranParser;
use App\Services\Ppq\QuedanCallejaExporter;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * El portal de Calleja busca el albarán por sala + número + AÑO + MES de su creación. El
 * lector tomaba la primera fecha del PDF, que es la del PEDIDO, y los albaranes creados en
 * octubre para pedidos de septiembre salían con mes 9: «no encontrado» en el portal.
 */
class AlbaranFechaCreacionTest extends TestCase
{
    use RefreshDatabase;

    private const PEDIDO = "Albarán de Compras\nPedido de Compras 00/5235/26 de Fecha 23/09/2026 Ref. \n26090046007047\n";

    public function test_toma_la_fecha_del_albaran_y_no_la_del_pedido(): void
    {
        $texto = self::PEDIDO."AC01/0046/00/7047/26\n01/10/2026\nTOTAL ALBARAN 10.00";

        $this->assertSame('01/10/2026', (new AlbaranParser)->desdeTexto($texto)['fecha']);
    }

    public function test_prefiere_la_fecha_rotulada_de_creacion(): void
    {
        $texto = "Impreso 05/10/2026\n".self::PEDIDO."Fecha Creación: 01/10/2026\nAC01/0046/00/7047/26";

        $this->assertSame('01/10/2026', (new AlbaranParser)->desdeTexto($texto)['fecha']);
    }

    public function test_sin_otra_fecha_queda_la_del_pedido(): void
    {
        $texto = self::PEDIDO.'AC01/0046/00/7047/26';

        $this->assertSame('23/09/2026', (new AlbaranParser)->desdeTexto($texto)['fecha']);
    }

    public function test_el_comando_corrige_la_fecha_y_el_quedan_mezcla_septiembre_y_octubre(): void
    {
        $disco = Storage::fake((string) config('dte.storage.disk', 'local'));
        $disco->put('ppq/albaranes/octubre.pdf', $this->pdf(
            ['Albarán de Compras', 'Pedido de Compras 00/5235/26 de Fecha 23/09/2026 Ref.', '26090046007047',
                'AC01/0046/00/7047/26', '01/10/2026', 'TOTAL ALBARAN 10.00']
        ));
        $octubre = PpqAlbaran::create(['numero_albaran' => 'AC01/0046/00/7047', 'numero_orden_compra' => '26090046007047',
            'monto_albaran' => 10, 'fecha_albaran' => '2026-09-23', 'origen' => 'gmail', 'archivo_path' => 'ppq/albaranes/octubre.pdf']);
        $septiembre = PpqAlbaran::create(['numero_albaran' => 'AC01/0046/00/6990', 'numero_orden_compra' => '26090046006990',
            'monto_albaran' => 10, 'fecha_albaran' => '2026-09-28', 'origen' => 'gmail']);

        $this->artisan('ppq:recalcular-fecha-albaranes', ['--dry-run' => true])
            ->expectsOutputToContain('Cambiarían: 1')->assertSuccessful();
        $this->assertSame('2026-09-23', $octubre->refresh()->fecha_albaran->toDateString());

        $this->artisan('ppq:recalcular-fecha-albaranes')->expectsOutputToContain('Corregidos: 1')->assertSuccessful();
        $this->assertSame('2026-10-01', $octubre->refresh()->fecha_albaran->toDateString());
        $this->artisan('ppq:recalcular-fecha-albaranes')->expectsOutputToContain('Corregidos: 0')->assertSuccessful();

        $lote = PpqLote::create(['referencia' => 'Quedan mixto', 'fecha' => now(), 'estado' => 'borrador']);
        foreach ([[$septiembre, '000000000000100'], [$octubre, '000000000000200']] as [$albaran, $correlativo]) {
            $lote->items()->create(['origen' => 'gmail', 'tipo_dte' => '03', 'numero_control' => 'DTE-03-M001P002-'.$correlativo,
                'numero_orden_compra' => $albaran->numero_orden_compra, 'monto_dte' => 10, 'ppq_albaran_id' => $albaran->id,
                'monto_albaran' => 10, 'sin_albaran' => false]);
        }

        $ruta = app(QuedanCallejaExporter::class)->generar($lote->fresh());
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);

        $meses = [];
        foreach ([2, 3] as $fila) {
            $meses[(string) $hoja->getCell('B'.$fila)->getValue()] = [$hoja->getCell('C'.$fila)->getValue(), $hoja->getCell('D'.$fila)->getValue()];
        }
        $this->assertSame(['6990' => [26, 9], '7047' => [26, 10]], $meses);
    }

    /** @param  array<int, string>  $lineas */
    private function pdf(array $lineas): string
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<html><body>'.implode('', array_map(fn ($l) => '<p>'.e($l).'</p>', $lineas)).'</body></html>');
        $dompdf->render();

        return $dompdf->output();
    }
}
