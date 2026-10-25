<?php

namespace Tests\Feature\Ppq;

use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Services\Ppq\ActualizarPpqConTxt;
use App\Services\Ppq\ArchivoConciliacion;
use App\Services\Ppq\ConciliacionTxtParser;
use App\Services\Ppq\ConciliadorPpq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConciliacionPorEjercicioTest extends TestCase
{
    use RefreshDatabase;

    private function lote(): PpqLote
    {
        return PpqLote::create(['referencia' => 'Cruce entre ejercicios', 'fecha' => '2027-02-01', 'estado' => 'borrador']);
    }

    private function item(PpqLote $lote, string $fecha): PpqItem
    {
        return $lote->items()->create(['origen' => 'gmail', 'numero_control' => 'DTE-03-M001P002-000000000000015',
            'tipo_dte' => '03', 'fecha_documento' => $fecha, 'monto_dte' => 100, 'sin_albaran' => true]);
    }

    private function filas(string $lineas): array
    {
        return app(ConciliacionTxtParser::class)->parse("CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n".$lineas);
    }

    public function test_dos_filas_del_mismo_numero_en_anios_distintos_concilian_sus_items(): void
    {
        $lote = $this->lote();
        $viejo = $this->item($lote, '2026-12-20');
        $nuevo = $this->item($lote, '2027-01-15');
        $filas = $this->filas("000123;EJEMPLO;CF;DTE03M001P002000000000000015;20-DIC-26;80.00\n"
            ."000123;EJEMPLO;CF;DTE03M001P002000000000000015;15-ENE-27;100.00\n");

        $informe = app(ConciliadorPpq::class)->conciliar($lote, $filas);

        $this->assertCount(2, $informe['ccfPagados']);
        $this->assertSame('80.00', $viejo->refresh()->monto_pagado);
        $this->assertSame('100.00', $nuevo->refresh()->monto_pagado);
        $this->assertCount(0, $informe['noEnPpq']);
    }

    public function test_sin_fecha_y_con_dos_items_no_concilia_ninguno_y_muestra_la_fila(): void
    {
        $lote = $this->lote();
        $viejo = $this->item($lote, '2026-12-20');
        $nuevo = $this->item($lote, '2027-01-15');

        $informe = app(ConciliadorPpq::class)->conciliar($lote, $this->filas("000123;EJEMPLO;CF;DTE03M001P002000000000000015;;100.00\n"));

        $this->assertFalse($viejo->refresh()->estaConciliado());
        $this->assertFalse($nuevo->refresh()->estaConciliado());
        $this->assertCount(1, $informe['noEnPpq']);
        $this->assertCount(2, $informe['ccfPendientes']);
        $this->assertCount(0, $informe['corrida']->movimientos);
    }

    public function test_sin_fecha_y_con_un_candidato_concilia_el_item(): void
    {
        $lote = $this->lote();
        $item = $this->item($lote, '2026-12-20');

        $informe = app(ConciliadorPpq::class)->conciliar($lote, $this->filas("000123;EJEMPLO;CF;DTE03M001P002000000000000015;;100.00\n"));

        $this->assertTrue($item->refresh()->estaConciliado());
        $this->assertCount(1, $informe['ccfPagados']);
        $this->assertCount(0, $informe['noEnPpq']);
    }

    public function test_actualizacion_sin_fecha_no_selecciona_un_lote_con_numero_ambiguo(): void
    {
        $lote = $this->lote();
        $viejo = $this->item($lote, '2026-12-20');
        $nuevo = $this->item($lote, '2027-01-15');

        $resultado = app(ActualizarPpqConTxt::class)->aplicar(
            $this->filas("000123;EJEMPLO;CF;DTE03M001P002000000000000015;;100.00\n"),
            ArchivoConciliacion::desdeContenido('pago sin fecha', 'pago.txt'),
        );

        $this->assertSame([], $resultado);
        $this->assertFalse($viejo->refresh()->estaConciliado());
        $this->assertFalse($nuevo->refresh()->estaConciliado());
        $this->assertSame(0, $lote->conciliaciones()->count());
    }

    public function test_actualizacion_sin_fecha_no_aplica_el_mismo_pago_a_dos_lotes(): void
    {
        $viejo = $this->item($this->lote(), '2026-12-20');
        $nuevo = $this->item($this->lote(), '2027-01-15');

        $resultado = app(ActualizarPpqConTxt::class)->aplicar(
            $this->filas("000123;EJEMPLO;CF;DTE03M001P002000000000000015;;100.00\n"),
            ArchivoConciliacion::desdeContenido('pago sin fecha entre lotes', 'pago.txt'),
        );

        $this->assertSame([], $resultado);
        $this->assertFalse($viejo->refresh()->estaConciliado());
        $this->assertFalse($nuevo->refresh()->estaConciliado());
    }

    public function test_otra_fila_fechada_no_hace_inequivoca_la_fila_sin_fecha(): void
    {
        $lote = $this->lote();
        $viejo = $this->item($lote, '2026-12-20');
        $nuevo = $this->item($this->lote(), '2027-01-15');
        $otro = $lote->items()->create(['origen' => 'gmail', 'numero_control' => 'DTE-03-M001P002-000000000000016',
            'tipo_dte' => '03', 'fecha_documento' => '2026-12-20', 'monto_dte' => 100, 'sin_albaran' => true]);

        app(ActualizarPpqConTxt::class)->aplicar(
            $this->filas("000123;EJEMPLO;CF;DTE03M001P002000000000000015;;100.00\n"
                ."000123;EJEMPLO;CF;DTE03M001P002000000000000016;20-DIC-26;100.00\n"),
            ArchivoConciliacion::desdeContenido('pago mixto', 'pago.txt'),
        );

        $this->assertTrue($otro->refresh()->estaConciliado());
        $this->assertFalse($viejo->refresh()->estaConciliado());
        $this->assertFalse($nuevo->refresh()->estaConciliado());
    }
}
