<?php

namespace Tests\Feature\Cobros;

use App\Exceptions\Ppq\ArchivoProveedorInvalidoException;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\PpqConciliacion;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Cobros\AplicadorPagosTxt;
use App\Services\Ppq\ArchivoConciliacion;
use App\Services\Ppq\ConciliacionTxtParser;
use App\Services\Ppq\ConciliadorPpq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvidenciaEntreCircuitosTest extends TestCase
{
    use RefreshDatabase;

    private const CONTROL = 'DTE-03-M001P002-000000000090059';

    private function escenario(): array
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $documento = CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => 'externo',
            'tipo_dte' => '03',
            'numero_control' => self::CONTROL,
            'fecha_emision' => '2026-08-26',
            'monto' => '100.00',
        ]);
        $lote = PpqLote::create([
            'cliente_id' => $cliente->id,
            'referencia' => 'PPQ de prueba',
            'fecha' => '2026-09-01',
            'estado' => 'listo',
        ]);
        $item = $lote->items()->create([
            'origen' => 'local',
            'tipo_dte' => '03',
            'numero_control' => self::CONTROL,
            'monto_dte' => '100.00',
            'sin_albaran' => true,
        ]);
        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            .'000123;TITULAR DE EJEMPLO;CF;'.str_replace('-', '', self::CONTROL).";26-AGO-26;100.00\n";

        return [$cliente, $documento, $lote, $item, $txt,
            ArchivoConciliacion::desdeContenido($txt, 'pagos.txt'),
            app(ConciliacionTxtParser::class)->parse($txt)];
    }

    public function test_cobros_avisa_si_el_txt_ya_se_concilio_en_ppq_sin_duplicar_pagos(): void
    {
        [$cliente, $documento, $lote, $item, , $archivo, $filas] = $this->escenario();

        app(ConciliadorPpq::class)->conciliar($lote, $filas, null, $archivo);
        $informe = app(AplicadorPagosTxt::class)->aplicar($cliente, $filas, $archivo);

        $this->assertCount(1, $informe['en_ppq']);
        $this->assertSame($lote->id, $informe['en_ppq'][0]['lote_id']);
        $this->assertSame('pagado', $item->refresh()->conciliacion_estado);
        $this->assertSame('100.00', $documento->refresh()->monto_pagado);
        $this->assertSame(1, CobroEvento::count());

        $html = $this->actingAs(User::factory()->create())
            ->view('cobros.pagos', ['cliente' => $cliente, 'informe' => $informe]);
        $html->assertSee('Este mismo archivo ya se concilió en PPQ.');
        $html->assertSee('PPQ de prueba');
        $html->assertDontSee('El archivo trae códigos de proveedor ajenos:');

        $repetido = app(AplicadorPagosTxt::class)->aplicar($cliente, $filas, $archivo);
        $this->assertCount(1, $repetido['en_ppq']);
        $this->assertSame(1, CobroEvento::count());
        $this->assertSame('100.00', $documento->refresh()->monto_pagado);
    }

    public function test_ppq_avisa_si_el_txt_ya_se_aplico_en_cobros_sin_cambiar_el_importe(): void
    {
        [$cliente, $documento, $lote, $item, , $archivo, $filas] = $this->escenario();

        app(AplicadorPagosTxt::class)->aplicar($cliente, $filas, $archivo);
        $reporte = app(ConciliadorPpq::class)->conciliar($lote, $filas, null, $archivo);

        $this->assertSame(1, $reporte['enCobros']['pagos']);
        $this->assertSame(1, $reporte['enCobros']['documentos']);
        $this->assertSame(0, $reporte['enCobros']['ajustes']);
        $this->assertSame('pagado', $item->refresh()->conciliacion_estado);
        $this->assertSame('100.00', $documento->refresh()->monto_pagado);
        $this->assertSame(1, CobroEvento::count());
        $this->assertSame(1, PpqConciliacion::count());

        $html = $this->actingAs(User::factory()->create())
            ->view('ppq.lotes.conciliacion', [
                'lote' => $lote, 'reporte' => $reporte, 'archivo' => $archivo->nombre, 'totalFilas' => count($filas),
            ]);
        $html->assertSee('Este mismo archivo ya se aplicó en el seguimiento de Cobros.');
    }

    /**
     * Un TXT mixto (el proveedor esperado + uno ajeno) se rechaza ENTERO, aunque la fila
     * ajena no coincida con nada local: el código se verifica en CADA fila antes de
     * aplicar una sola.
     */
    public function test_txt_mixto_con_proveedor_ajeno_se_rechaza_entero(): void
    {
        [$cliente, , , , $txt] = $this->escenario();
        $mixto = $txt.'009999;OTRO;CF;SINCOINCIDENCIA;26-AGO-26;10.00'."\n";

        $this->expectException(ArchivoProveedorInvalidoException::class);

        try {
            app(AplicadorPagosTxt::class)->aplicar(
                $cliente,
                app(ConciliacionTxtParser::class)->parse($mixto),
                ArchivoConciliacion::desdeContenido($mixto, 'mixto.txt'),
            );
        } finally {
            $this->assertSame(0, CobroEvento::count(), 'Nada se aplica de un archivo con un código de proveedor ajeno.');
        }
    }

    public function test_otro_contenido_no_produce_aviso_entre_circuitos(): void
    {
        [$cliente, , $lote, , $txt, $archivo, $filas] = $this->escenario();
        app(ConciliadorPpq::class)->conciliar($lote, $filas, null, $archivo);

        $otroTxt = str_replace('TITULAR DE EJEMPLO;', 'OTRO;', $txt);
        $otroArchivo = ArchivoConciliacion::desdeContenido($otroTxt, 'pagos.txt');
        $otroInforme = app(AplicadorPagosTxt::class)->aplicar(
            $cliente, app(ConciliacionTxtParser::class)->parse($otroTxt), $otroArchivo
        );

        $this->assertSame([], $otroInforme['en_ppq']);
    }

    public function test_un_txt_solo_con_ajuste_tambien_deja_aviso_para_ppq(): void
    {
        [$cliente, , $lote] = $this->escenario();
        $txt = '000123;TITULAR DE EJEMPLO;QD;PPQ/19891;;-3.50';
        $archivo = ArchivoConciliacion::desdeContenido($txt, 'ajuste.txt');
        $filas = app(ConciliacionTxtParser::class)->parse($txt);

        app(AplicadorPagosTxt::class)->aplicar($cliente, $filas, $archivo);
        $reporte = app(ConciliadorPpq::class)->conciliar($lote, $filas, null, $archivo);

        $this->assertSame(0, $reporte['enCobros']['pagos']);
        $this->assertSame(1, $reporte['enCobros']['ajustes']);
        $this->assertSame(1, PpqConciliacion::count());
    }

    public function test_lote_ppq_eliminado_conserva_el_aviso_sin_enlace_roto(): void
    {
        [$cliente, , $lote, , , $archivo, $filas] = $this->escenario();
        app(ConciliadorPpq::class)->conciliar($lote, $filas, null, $archivo);
        $lote->delete();

        $informe = app(AplicadorPagosTxt::class)->aplicar($cliente, $filas, $archivo);

        $this->assertCount(1, $informe['en_ppq']);
        $this->assertFalse($informe['en_ppq'][0]['lote_disponible']);
        $this->actingAs(User::factory()->create())
            ->view('cobros.pagos', ['cliente' => $cliente, 'informe' => $informe])
            ->assertSee('ya no disponible');
    }
}
