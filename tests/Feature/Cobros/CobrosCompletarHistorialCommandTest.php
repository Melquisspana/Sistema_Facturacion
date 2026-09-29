<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\PpqConciliacion;
use App\Models\PpqLote;
use App\Services\Cobros\AltaCobrosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CobrosCompletarHistorialCommandTest extends TestCase
{
    use RefreshDatabase;

    private function doc(Cliente $cliente, string $correlativo, string $fecha, bool $revisar = true): CobroDocumento
    {
        return CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => 'externo',
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-'.str_pad($correlativo, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => $fecha,
            'monto' => '100.00',
            'revisar_historico' => $revisar,
            'revisar_historico_motivo' => AltaCobrosService::MOTIVO_SIN_ANTECEDENTE,
        ]);
    }

    public function test_aplica_los_txt_de_ppq_marca_lo_de_lotes_y_quita_la_marca_mal_puesta(): void
    {
        Storage::fake((string) config('dte.storage.disk', 'local'));
        config(['cobros.inicio_seguimiento' => '2026-07-01']);

        $cliente = Cliente::factory()->contribuyente()->create();
        $pagado = $this->doc($cliente, '101', '2026-06-20');
        $enLote = $this->doc($cliente, '102', '2026-06-21');
        $nuevo = $this->doc($cliente, '103', '2026-08-10');
        $viejoSinNada = $this->doc($cliente, '104', '2026-06-22');

        $lote = PpqLote::create(['cliente_id' => $cliente->id, 'referencia' => 'PPQ', 'fecha' => '2026-07-13', 'estado' => 'listo']);
        foreach (['101', '102'] as $n) {
            $lote->items()->create(['origen' => 'local', 'tipo_dte' => '03', 'monto_dte' => '100.00', 'sin_albaran' => true,
                'numero_control' => 'DTE-03-M001P002-'.str_pad($n, 15, '0', STR_PAD_LEFT)]);
        }

        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000101;20-JUN-26;100.00\n";
        $ruta = 'ppq/conciliaciones/'.hash('sha256', $txt).'.txt';
        Storage::disk((string) config('dte.storage.disk', 'local'))->put($ruta, $txt);
        PpqConciliacion::create(['ppq_lote_id' => $lote->id, 'origen' => 'txt', 'archivo_nombre' => 'pagos.txt',
            'archivo_hash' => hash('sha256', $txt), 'archivo_path' => $ruta]);

        // En seco no cambia nada.
        $this->artisan('cobros:completar-historial', ['--cliente' => $cliente->id])->assertSuccessful();
        $this->assertSame(EstadoPagoCobro::Pendiente, $pagado->refresh()->pago_estado);

        $this->artisan('cobros:completar-historial', ['--cliente' => $cliente->id, '--aplicar' => true])->assertSuccessful();
        // Repetir no duplica el pago.
        $this->artisan('cobros:completar-historial', ['--cliente' => $cliente->id, '--aplicar' => true])->assertSuccessful();

        $pagado->refresh();
        $this->assertSame(EstadoPagoCobro::Pagado, $pagado->pago_estado);
        $this->assertSame('100.00', (string) $pagado->monto_pagado);
        $this->assertSame(EstadoPresentacionCobro::Recibida, $pagado->presentacion_estado);
        $this->assertFalse($pagado->revisar_historico);

        $this->assertSame(EstadoPresentacionCobro::Presentada, $enLote->refresh()->presentacion_estado);
        $this->assertFalse($enLote->revisar_historico);

        $this->assertFalse($nuevo->refresh()->revisar_historico, 'Posterior al inicio: no es histórico.');
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $nuevo->presentacion_estado);

        $this->assertTrue($viejoSinNada->refresh()->revisar_historico, 'Anterior y sin evidencia: sigue en revisión.');
    }
}
