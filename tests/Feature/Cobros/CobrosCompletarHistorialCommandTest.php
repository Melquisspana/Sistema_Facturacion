<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroAjuste;
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

    private function clienteDeCobros(): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        ClientePerfilDocumento::create(['cliente_id' => $cliente->id, 'activo' => true]);

        return $cliente;
    }

    /** Un lote PPQ del cliente con su TXT de pago guardado en disco. */
    private function loteConTxt(Cliente $cliente, string $txt): PpqLote
    {
        $lote = PpqLote::create(['cliente_id' => $cliente->id, 'referencia' => 'PPQ-'.$cliente->id, 'fecha' => '2026-07-13', 'estado' => 'listo']);
        $ruta = 'ppq/conciliaciones/'.hash('sha256', $txt).'.txt';
        Storage::disk((string) config('dte.storage.disk', 'local'))->put($ruta, $txt);
        PpqConciliacion::create(['ppq_lote_id' => $lote->id, 'origen' => 'txt', 'archivo_nombre' => 'pagos-'.$cliente->id.'.txt',
            'archivo_hash' => hash('sha256', $txt), 'archivo_path' => $ruta]);

        return $lote;
    }

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

        $cliente = $this->clienteDeCobros();
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

    /**
     * Issue #13: con dos clientes de cobros, el comando aplica solo los TXT de los lotes del
     * cliente indicado. Antes aplicaba los de todos, y el QD del otro cliente quedaba acá.
     */
    public function test_solo_aplica_los_txt_de_los_lotes_del_cliente(): void
    {
        Storage::fake((string) config('dte.storage.disk', 'local'));
        config(['ppq.codigo_proveedor' => '000123']);

        $cliente = $this->clienteDeCobros();
        $otro = $this->clienteDeCobros();
        $this->loteConTxt($otro, "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;QD;PPQ/33762;10-SEP-26;-1.50\n");

        $this->artisan('cobros:completar-historial', ['--cliente' => $cliente->id, '--aplicar' => true])->assertSuccessful();
        $this->assertSame(0, CobroAjuste::count(), 'El TXT del otro cliente no se aplicó acá.');

        $this->artisan('cobros:completar-historial', ['--cliente' => $otro->id, '--aplicar' => true])->assertSuccessful();
        $this->assertSame($otro->id, (int) CobroAjuste::sole()->cliente_id);
    }

    /** Issue #13: un cliente sin perfil documental activo no es de cobros: no se aplica nada. */
    public function test_rechaza_un_cliente_que_no_es_de_cobros(): void
    {
        Storage::fake((string) config('dte.storage.disk', 'local'));
        config(['ppq.codigo_proveedor' => '000123']);

        $ajeno = Cliente::factory()->contribuyente()->create();
        ClientePerfilDocumento::create(['cliente_id' => $ajeno->id, 'activo' => false]);
        $doc = $this->doc($ajeno, '101', '2026-06-20');
        $this->loteConTxt($ajeno, "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000101;20-JUN-26;100.00\n"
            ."000123;TITULAR DE EJEMPLO;QD;PPQ/33762;10-SEP-26;-1.50\n");

        $this->artisan('cobros:completar-historial', ['--cliente' => $ajeno->id, '--aplicar' => true])
            ->expectsOutputToContain('no tiene perfil documental activo')
            ->assertFailed();

        $this->assertSame(0, CobroAjuste::count());
        $this->assertSame(EstadoPagoCobro::Pendiente, $doc->refresh()->pago_estado);
        $this->assertTrue($doc->revisar_historico);
    }

    /** El lote PPQ se borró (borrado blando) después de conciliar: su TXT sigue siendo historia y se aplica. */
    public function test_aplica_el_txt_de_un_lote_borrado(): void
    {
        Storage::fake((string) config('dte.storage.disk', 'local'));
        config(['ppq.codigo_proveedor' => '000123']);

        $cliente = $this->clienteDeCobros();
        $pagado = $this->doc($cliente, '101', '2026-06-20');
        $this->loteConTxt($cliente, "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000101;20-JUN-26;100.00\n")->delete();

        $this->artisan('cobros:completar-historial', ['--cliente' => $cliente->id, '--aplicar' => true])
            ->expectsOutputToContain('1 aplicados')
            ->assertSuccessful();

        $this->assertSame(EstadoPagoCobro::Pagado, $pagado->refresh()->pago_estado);
    }

    /**
     * Issue #13: el mismo TXT ya se aplicó en otro cliente y su QD quedó allá. En el cliente
     * correcto el QD no se trae ni se mueve, pero el CF sí se aplica y el QD se avisa.
     */
    public function test_un_qd_que_ya_esta_en_otro_cliente_no_frena_los_pagos_del_archivo(): void
    {
        Storage::fake((string) config('dte.storage.disk', 'local'));
        config(['ppq.codigo_proveedor' => '000123']);

        $cliente = $this->clienteDeCobros();
        $otro = $this->clienteDeCobros();
        $pagado = $this->doc($cliente, '101', '2026-06-20');
        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000101;20-JUN-26;100.00\n"
            ."000123;TITULAR DE EJEMPLO;QD;PPQ/33762;10-SEP-26;-1.50\n";
        $this->loteConTxt($otro, $txt);
        $this->artisan('cobros:completar-historial', ['--cliente' => $otro->id, '--aplicar' => true])->assertSuccessful();
        $ajeno = CobroAjuste::sole();
        $this->assertSame($otro->id, (int) $ajeno->cliente_id);

        $this->loteConTxt($cliente, $txt);
        $this->artisan('cobros:completar-historial', ['--cliente' => $cliente->id, '--aplicar' => true])
            ->expectsOutputToContain('1 ajuste(s) QD ya están en otro cliente')
            ->assertSuccessful();

        $this->assertSame(EstadoPagoCobro::Pagado, $pagado->refresh()->pago_estado);
        $this->assertSame(1, CobroAjuste::count(), 'No se creó otro ajuste.');
        $this->assertSame($otro->id, (int) $ajeno->refresh()->cliente_id, 'Ni se movió el ajeno.');
    }
}
