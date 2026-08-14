<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPpq;
use App\Enums\EstadoSalidaRuta;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaDocumento;
use App\Models\User;
use App\Services\Rutas\BandejaDocumentos;
use App\Services\Rutas\Cobranza;
use App\Services\Rutas\SeguimientoDocumentos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * El dinero de la cobranza: facturado, cobrado, NC y saldo.
 *
 * Lo que estas pruebas defienden por encima de todo:
 *
 *  - LO DESCONOCIDO NO ES CERO. Un documento sin monto queda fuera de los totales y
 *    se declara; jamás se suma como cero ni se da por cobrado;
 *  - una MISMA NC no resta dos veces: o cuenta como aplicada, o como aceptada por
 *    aplicar, nunca en las dos;
 *  - una NC solo resta si el vínculo es FISCAL (`dte_relacionado_id`). Por orden de
 *    compra no, porque una OC ampara varios CCF y descontaría de más;
 *  - el saldo se parte SIEMPRE en fuera de PPQ / en PPQ sin pagar;
 *  - la antigüedad corre desde la EMISIÓN y solo sobre lo que falta cobrar.
 */
class CobranzaTest extends TestCase
{
    use RefreshDatabase;

    private function establecimiento(): Establecimiento
    {
        return Establecimiento::firstOr(function () {
            $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);

            return Establecimiento::create([
                'empresa_id' => $empresa->id,
                'codigo' => 'M001',
                'nombre' => 'Casa Matriz',
                'activo' => true,
            ]);
        });
    }

    private function sala(Ruta $ruta): ClienteSucursal
    {
        $cliente = Cliente::firstOrCreate(['nombre' => 'Calleja'], Cliente::factory()->raw(['nombre' => 'Calleja']));

        return $cliente->sucursales()->create([
            'nombre' => 'Selectos San Miguel',
            'codigo' => '0240',
            'ruta_id' => $ruta->id,
        ]);
    }

    private function salida(?Ruta $ruta = null, string $inicio = '2026-08-14'): SalidaRuta
    {
        return SalidaRuta::create([
            'ruta_id' => ($ruta ?? Ruta::create(['nombre' => 'San Miguel']))->id,
            'fecha_inicio' => $inicio,
            'fecha_fin_estimada' => $inicio,
            'estado' => EstadoSalidaRuta::Planificada,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function ccf(?ClienteSucursal $sala, string $control, array $extra = []): Dte
    {
        return Dte::create($extra + [
            'establecimiento_id' => $this->establecimiento()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'cliente_id' => $sala?->cliente_id,
            'cliente_sucursal_id' => $sala?->id,
            'numero_control' => $control,
            'numero_orden_compra' => '260600232002345',
            'fecha_emision' => '2026-08-14',
            'hora_emision' => '10:00:00',
            'total_pagar' => 100.00,
        ]);
    }

    private function documento(SalidaRuta $salida, Dte $ccf): SalidaRutaDocumento
    {
        return SalidaRutaDocumento::create([
            'salida_ruta_id' => $salida->id,
            'dte_id' => $ccf->id,
            'numero_control' => $ccf->numero_control,
            'numero_orden_compra' => $ccf->numero_orden_compra,
            'cliente_sucursal_id' => $ccf->cliente_sucursal_id,
            'origen' => SalidaRutaDocumento::ORIGEN_P002,
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ]);
    }

    private function item(string $control, ?string $estado, ?float $monto = null, string $tipo = '03'): PpqItem
    {
        $lote = PpqLote::create(['referencia' => 'PPQ prueba', 'fecha' => '2026-08-20', 'estado' => EstadoPpq::Listo]);

        return PpqItem::create([
            'ppq_lote_id' => $lote->id,
            'dte_id' => null,
            'origen' => 'gmail',
            'numero_control' => $control,
            'tipo_dte' => $tipo,
            'monto_dte' => $monto ?? 100.00,
            'conciliacion_estado' => $estado,
            'fecha_pago' => $estado ? '2026-08-25' : null,
            'monto_pagado' => $estado ? $monto : null,
        ]);
    }

    /** @param Collection<int, SalidaRutaDocumento> $docs */
    private function dinero(SalidaRuta $salida): array
    {
        $seguimiento = app(SeguimientoDocumentos::class);

        return app(Cobranza::class)->resumen($seguimiento->documentosDe($salida->fresh()));
    }

    // ================================================== facturado y cobrado

    public function test_suma_lo_facturado_y_lo_cobrado_por_separado(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);

        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]));
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000002', ['total_pagar' => 50.00]));
        $this->item('DTE-03-M001P002-000000000000001', 'pagado', 100.00);

        $dinero = $this->dinero($salida);

        $this->assertSame(150.00, $dinero['facturado']);
        $this->assertSame(100.00, $dinero['cobrado']);
        $this->assertSame(50.00, $dinero['saldo']);
    }

    public function test_el_saldo_usa_lo_realmente_pagado_no_lo_facturado(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);
        $documento = $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]));

        // Calleja pagó de menos: el resto sigue siendo saldo.
        $this->item('DTE-03-M001P002-000000000000001', 'pagado', 80.00);

        $this->assertSame(80.00, $documento->montoCobrado());
        $this->assertSame(20.00, $documento->saldoPendiente());
        $this->assertTrue($documento->tieneSaldo());
    }

    // ============================================ lo desconocido no es cero

    public function test_un_documento_sin_monto_queda_fuera_de_las_sumas_y_se_declara(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);

        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]));

        // Histórico P001 sin monto: no se sabe cuánto es.
        SalidaRutaDocumento::create([
            'salida_ruta_id' => $salida->id,
            'dte_id' => null,
            'numero_control' => 'DTE-03-M001P001-000000000000940',
            'origen' => SalidaRutaDocumento::ORIGEN_P001,
            'monto' => null,
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ]);

        $dinero = $this->dinero($salida);

        $this->assertSame(100.00, $dinero['facturado'], 'el desconocido no suma cero: no suma');
        $this->assertSame(100.00, $dinero['saldo']);
        $this->assertSame(1, $dinero['sin_monto']);
        $this->assertSame(1, $dinero['saldo_desconocido']);
        $this->assertSame(1, $dinero['documentos_con_saldo'], 'el sin monto no cuenta como documento con saldo');
    }

    public function test_un_saldo_desconocido_no_se_da_por_cobrado(): void
    {
        $salida = $this->salida();

        $documento = SalidaRutaDocumento::create([
            'salida_ruta_id' => $salida->id,
            'dte_id' => null,
            'numero_control' => 'DTE-03-M001P001-000000000000940',
            'origen' => SalidaRutaDocumento::ORIGEN_P001,
            'monto' => null,
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ]);

        $this->assertNull($documento->saldoPendiente());
        $this->assertFalse($documento->saldoConocido());
        // Ni con saldo ni sin saldo: desconocido es su propio estado.
        $this->assertFalse($documento->tieneSaldo());
    }

    public function test_un_pago_sin_importe_deja_el_saldo_desconocido(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);
        $documento = $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]));

        // Conciliado como pagado pero el TXT no traía valor.
        $this->item('DTE-03-M001P002-000000000000001', 'pagado', null);

        $this->assertTrue($documento->pagado());
        $this->assertNull($documento->montoCobrado());
        $this->assertNull($documento->saldoPendiente(), 'no se puede restar lo que no se sabe');
    }

    // ==================================================== notas de crédito

    public function test_una_nc_aceptada_sin_aplicar_reduce_el_saldo(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]);
        $documento = $this->documento($salida, $ccf);

        $this->ccf($sala, 'DTE-05-M001P002-000000000000010', [
            'tipo_dte' => '05',
            'dte_relacionado_id' => $ccf->id,
            'estado' => 'aceptado',
            'total_pagar' => 30.00,
        ]);

        $this->assertSame(30.00, $documento->montoNcAceptadaPorAplicar());
        $this->assertNull($documento->montoNcAplicada());
        $this->assertSame(70.00, $documento->saldoPendiente());
    }

    public function test_una_nc_aplicada_deja_de_contar_como_aceptada_por_aplicar(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]);
        $documento = $this->documento($salida, $ccf);

        $nc = $this->ccf($sala, 'DTE-05-M001P002-000000000000010', [
            'tipo_dte' => '05',
            'dte_relacionado_id' => $ccf->id,
            'estado' => 'aceptado',
            'total_pagar' => 30.00,
        ]);
        $this->item($nc->numero_control, 'aplicada', 30.00, '05');

        // La misma NC no puede restar dos veces: cuenta en aplicada y en ninguna otra.
        $this->assertSame(30.00, $documento->montoNcAplicada());
        $this->assertNull($documento->montoNcAceptadaPorAplicar());
        $this->assertSame(70.00, $documento->saldoPendiente(), 'restada UNA sola vez');

        $dinero = $this->dinero($salida);
        $this->assertSame(30.00, $dinero['nc_aplicada']);
        $this->assertSame(0.0, $dinero['nc_aceptada']);
        $this->assertSame(70.00, $dinero['saldo']);
    }

    public function test_una_nc_rechazada_o_invalidada_no_resta_nada(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]);
        $documento = $this->documento($salida, $ccf);

        $this->ccf($sala, 'DTE-05-M001P002-000000000000010', [
            'tipo_dte' => '05',
            'dte_relacionado_id' => $ccf->id,
            'estado' => 'rechazado',
            'total_pagar' => 30.00,
        ]);

        $this->assertNull($documento->montoNcAceptadaPorAplicar());
        $this->assertSame(100.00, $documento->saldoPendiente());
    }

    public function test_una_nc_generada_todavia_no_resta(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]);
        $documento = $this->documento($salida, $ccf);

        // Existe pero Hacienda todavía no la aceptó: no descuenta nada.
        $this->ccf($sala, 'DTE-05-M001P002-000000000000010', [
            'tipo_dte' => '05',
            'dte_relacionado_id' => $ccf->id,
            'estado' => 'generado',
            'total_pagar' => 30.00,
        ]);

        $this->assertNull($documento->montoNcAceptadaPorAplicar());
        $this->assertSame(100.00, $documento->saldoPendiente());
    }

    public function test_una_nc_hallada_solo_por_orden_de_compra_no_resta(): void
    {
        // Dos CCF con la MISMA orden de compra. Si la NC restara por OC, se
        // descontaría de los dos y el saldo total quedaría 30 más bajo de lo real.
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);

        $a = $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]));
        $b = $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000002', ['total_pagar' => 100.00]));

        // NC sin vínculo fiscal, solo comparte la orden de compra.
        $this->ccf($sala, 'DTE-05-M001P002-000000000000010', [
            'tipo_dte' => '05',
            'dte_relacionado_id' => null,
            'estado' => 'aceptado',
            'total_pagar' => 30.00,
        ]);

        // Se la ve como NC del documento (eso no cambia)...
        $this->assertNotNull($a->notaCredito());
        // ...pero no entra en el conjunto con el que se resta plata.
        $this->assertTrue($a->notasCreditoVinculadas()->isEmpty());
        $this->assertNull($a->montoNcAceptadaPorAplicar());
        $this->assertSame(100.00, $a->saldoPendiente());
        $this->assertSame(100.00, $b->saldoPendiente());

        $this->assertSame(200.00, $this->dinero($salida)['saldo']);
    }

    public function test_un_historico_p001_no_tiene_nc_atribuible(): void
    {
        $salida = $this->salida();

        $documento = SalidaRutaDocumento::create([
            'salida_ruta_id' => $salida->id,
            'dte_id' => null,
            'numero_control' => 'DTE-03-M001P001-000000000000940',
            'numero_orden_compra' => '260600232002345',
            'origen' => SalidaRutaDocumento::ORIGEN_P001,
            'monto' => 100.00,
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ]);

        $sala = $this->sala($salida->ruta);
        $this->ccf($sala, 'DTE-05-M001P002-000000000000010', [
            'tipo_dte' => '05',
            'estado' => 'aceptado',
            'total_pagar' => 30.00,
        ]);

        // Sin dte_id no hay vínculo fiscal posible: no se adivina por OC.
        $this->assertTrue($documento->notasCreditoVinculadas()->isEmpty());
        $this->assertSame(100.00, $documento->saldoPendiente());
    }

    // ======================================== lotes borrados (soft delete)

    public function test_un_item_de_un_lote_borrado_no_pone_el_documento_en_ppq(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);
        $documento = $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]));

        // El lote se borra (lógico). Sus items sobreviven: el cascadeOnDelete de la
        // clave foránea no se dispara con un soft delete.
        $item = $this->item('DTE-03-M001P002-000000000000001', null);
        $item->lote->delete();
        $this->assertDatabaseHas('ppq_items', ['id' => $item->id]);

        // Un lote retirado no es un lote: el documento está pendiente de ingresar.
        $this->assertFalse($documento->enPpq());
        $this->assertNull($documento->ppqItem());

        $dinero = $this->dinero($salida);
        $this->assertSame(100.00, $dinero['saldo_fuera_ppq']);
        $this->assertSame(0.0, $dinero['saldo_en_ppq']);
    }

    public function test_un_documento_en_un_lote_vivo_y_otro_borrado_sigue_en_ppq(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);
        $documento = $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]));

        $borrado = $this->item('DTE-03-M001P002-000000000000001', null);
        $borrado->lote->delete();
        $vivo = $this->item('DTE-03-M001P002-000000000000001', null);

        // El filtro es por ITEM: alcanza con que uno de sus renglones siga en pie.
        $this->assertTrue($documento->enPpq());
        $this->assertSame($vivo->id, $documento->ppqItem()->id);

        $dinero = $this->dinero($salida);
        $this->assertSame(100.00, $dinero['saldo_en_ppq']);
    }

    public function test_un_item_conciliado_en_un_lote_borrado_tampoco_cuenta(): void
    {
        // DECISIÓN FIJADA a propósito. Si el lote se retiró, ese renglón no prueba un
        // cobro vigente y el documento vuelve a contar como deuda. Se prefiere
        // SOBREESTIMAR la deuda —que lleva a ir a preguntar y descubrir el error— antes
        // que subestimarla, que hace que nunca se reclame. Hoy no existe ningún caso
        // así en los datos; si el negocio decidiera lo contrario, este test es el
        // lugar donde cambiar la regla a conciencia.
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);
        $documento = $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]));

        $item = $this->item('DTE-03-M001P002-000000000000001', 'pagado', 100.00);
        $item->lote->delete();

        $this->assertFalse($documento->pagado());
        $this->assertSame(100.00, $documento->saldoPendiente());
    }

    // ============================ NC aceptada tapada por una NC posterior

    public function test_una_nc_generada_posterior_no_tapa_una_aceptada(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]);
        $documento = $this->documento($salida, $ccf);

        $this->ccf($sala, 'DTE-05-M001P002-000000000000010', [
            'tipo_dte' => '05', 'dte_relacionado_id' => $ccf->id,
            'estado' => 'aceptado', 'total_pagar' => 16.61,
        ]);
        // Emitida DESPUÉS: la insignia mostrará esta, pero no borra la aceptada.
        $generada = $this->ccf($sala, 'DTE-05-M001P002-000000000000011', [
            'tipo_dte' => '05', 'dte_relacionado_id' => $ccf->id,
            'estado' => 'generado', 'total_pagar' => 5.65,
        ]);

        // La pantalla sigue mostrando la más reciente: eso NO cambia.
        $this->assertSame($generada->id, $documento->notaCredito()->id);

        // El dinero mira todas: la aceptada descuenta, la generada no.
        $this->assertSame(16.61, $documento->montoNcAceptadaPorAplicar());
        $this->assertSame(83.39, $documento->saldoPendiente());
    }

    public function test_una_nc_rechazada_posterior_tampoco_tapa_una_aceptada(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]);
        $documento = $this->documento($salida, $ccf);

        $this->ccf($sala, 'DTE-05-M001P002-000000000000010', [
            'tipo_dte' => '05', 'dte_relacionado_id' => $ccf->id,
            'estado' => 'aceptado', 'total_pagar' => 1.13,
        ]);
        $this->ccf($sala, 'DTE-05-M001P002-000000000000011', [
            'tipo_dte' => '05', 'dte_relacionado_id' => $ccf->id,
            'estado' => 'rechazado', 'total_pagar' => 5.54,
        ]);

        $this->assertSame(1.13, $documento->montoNcAceptadaPorAplicar());
        $this->assertSame(98.87, $documento->saldoPendiente());
    }

    public function test_varias_nc_aceptadas_descuentan_todas(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]);
        $documento = $this->documento($salida, $ccf);

        foreach ([['010', 10.00], ['011', 20.00]] as [$sufijo, $monto]) {
            $this->ccf($sala, 'DTE-05-M001P002-0000000000000'.$sufijo, [
                'tipo_dte' => '05', 'dte_relacionado_id' => $ccf->id,
                'estado' => 'aceptado', 'total_pagar' => $monto,
            ]);
        }

        // Dos correcciones aceptadas descuentan las dos, no una.
        $this->assertSame(30.00, $documento->montoNcAceptadaPorAplicar());
        $this->assertSame(70.00, $documento->saldoPendiente());
    }

    public function test_una_aplicada_y_una_aceptada_suman_cada_una_en_su_columna(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]);
        $documento = $this->documento($salida, $ccf);

        $aplicada = $this->ccf($sala, 'DTE-05-M001P002-000000000000010', [
            'tipo_dte' => '05', 'dte_relacionado_id' => $ccf->id,
            'estado' => 'aceptado', 'total_pagar' => 20.00,
        ]);
        $this->item($aplicada->numero_control, 'aplicada', 20.00, '05');

        $this->ccf($sala, 'DTE-05-M001P002-000000000000011', [
            'tipo_dte' => '05', 'dte_relacionado_id' => $ccf->id,
            'estado' => 'aceptado', 'total_pagar' => 10.00,
        ]);

        // Cada nota en UNA columna, ninguna en las dos.
        $this->assertSame(20.00, $documento->montoNcAplicada());
        $this->assertSame(10.00, $documento->montoNcAceptadaPorAplicar());
        $this->assertSame(70.00, $documento->saldoPendiente());

        $dinero = $this->dinero($salida);
        $this->assertSame(20.00, $dinero['nc_aplicada']);
        $this->assertSame(10.00, $dinero['nc_aceptada']);
    }

    // ============================================ el saldo va siempre partido

    public function test_el_saldo_se_parte_en_fuera_de_ppq_y_en_ppq_sin_pagar(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);

        // 1) fuera de PPQ  2) en PPQ sin pagar  3) pagado (no deja saldo)
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]));
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000002', ['total_pagar' => 60.00]));
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000003', ['total_pagar' => 40.00]));

        $this->item('DTE-03-M001P002-000000000000002', null);
        $this->item('DTE-03-M001P002-000000000000003', 'pagado', 40.00);

        $dinero = $this->dinero($salida);

        $this->assertSame(160.00, $dinero['saldo']);
        $this->assertSame(100.00, $dinero['saldo_fuera_ppq']);
        $this->assertSame(60.00, $dinero['saldo_en_ppq']);
        // El total nunca reemplaza a sus componentes: la suma tiene que cerrar.
        $this->assertSame($dinero['saldo'], round($dinero['saldo_fuera_ppq'] + $dinero['saldo_en_ppq'], 2));

        $this->assertSame(1, $dinero['documentos_fuera_ppq']);
        $this->assertSame(1, $dinero['documentos_en_ppq']);
    }

    // ==================================================== antigüedad

    public function test_la_antiguedad_corre_desde_la_emision(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);

        $documento = $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', [
            'fecha_emision' => now()->subDays(45)->toDateString(),
            'total_pagar' => 100.00,
        ]));

        $this->assertSame(45, $documento->diasAntiguedad());
    }

    public function test_los_tramos_agrupan_solo_lo_que_falta_cobrar(): void
    {
        $salida = $this->salida();
        $sala = $this->sala($salida->ruta);

        // 10 días (con saldo), 100 días (con saldo), 200 días (ya cobrado).
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', [
            'fecha_emision' => now()->subDays(10)->toDateString(), 'total_pagar' => 100.00,
        ]));
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000002', [
            'fecha_emision' => now()->subDays(100)->toDateString(), 'total_pagar' => 70.00,
        ]));
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000003', [
            'fecha_emision' => now()->subDays(200)->toDateString(), 'total_pagar' => 40.00,
        ]));
        $this->item('DTE-03-M001P002-000000000000003', 'pagado', 40.00);

        $seguimiento = app(SeguimientoDocumentos::class);
        $antiguedad = app(Cobranza::class)->antiguedad($seguimiento->documentosDe($salida->fresh()));

        $this->assertSame(100.00, $antiguedad['0-30']['monto']);
        $this->assertSame(70.00, $antiguedad['90+']['monto']);
        // El de 200 días ya se cobró: no envejece.
        $this->assertSame(1, $antiguedad['90+']['documentos']);
        $this->assertSame(0.0, $antiguedad['31-60']['monto']);
    }

    public function test_sin_fecha_no_se_reparte_entre_los_tramos(): void
    {
        $salida = $this->salida();

        SalidaRutaDocumento::create([
            'salida_ruta_id' => $salida->id,
            'dte_id' => null,
            'numero_control' => 'DTE-03-M001P001-000000000000940',
            'origen' => SalidaRutaDocumento::ORIGEN_P001,
            'monto' => 90.00,
            'fecha_documento' => null,
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ]);

        $seguimiento = app(SeguimientoDocumentos::class);
        $antiguedad = app(Cobranza::class)->antiguedad($seguimiento->documentosDe($salida->fresh()));

        $this->assertSame(90.00, $antiguedad['sin_fecha']['monto']);
        $this->assertSame(0.0, $antiguedad['0-30']['monto'], 'no puede pasar por reciente sin prueba');
    }

    // ==================================================== filtros y pantalla

    public function test_se_puede_filtrar_por_saldo_y_por_tramo(): void
    {
        $salida = $this->salida($ruta = Ruta::create(['nombre' => 'Sonsonate']), now()->toDateString());
        $sala = $this->sala($ruta);

        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', [
            'fecha_emision' => now()->subDays(5)->toDateString(), 'total_pagar' => 100.00,
        ]));
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000002', [
            'fecha_emision' => now()->subDays(120)->toDateString(), 'total_pagar' => 40.00,
        ]));
        $this->item('DTE-03-M001P002-000000000000002', 'pagado', 40.00);

        $bandeja = app(BandejaDocumentos::class);

        $this->assertCount(1, $bandeja->consultar(['saldo' => BandejaDocumentos::SALDO_CON])['documentos']);
        $this->assertCount(1, $bandeja->consultar(['saldo' => BandejaDocumentos::SALDO_SIN])['documentos']);
        $this->assertCount(1, $bandeja->consultar(['antiguedad' => '0-30'])['documentos']);
        // El de 120 días ya se cobró: no aparece en ningún tramo.
        $this->assertCount(0, $bandeja->consultar(['antiguedad' => '90+'])['documentos']);
    }

    public function test_la_bandeja_muestra_el_dinero_con_sus_componentes(): void
    {
        $admin = User::factory()->create(['activo' => true])->assignRole('administrador');
        $salida = $this->salida($ruta = Ruta::create(['nombre' => 'Sonsonate']), now()->toDateString());
        $sala = $this->sala($ruta);

        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['total_pagar' => 100.00]));

        $this->actingAs($admin)->get(route('rutas.documentos.index'))
            ->assertOk()
            ->assertSee('Facturado')
            ->assertSee('Cobrado')
            ->assertSee('NC aplicada')
            ->assertSee('NC por aplicar')
            ->assertSee('Saldo pendiente')
            // Los dos componentes nunca se ocultan detrás del total.
            ->assertSee('Fuera de PPQ')
            ->assertSee('En PPQ sin pagar');
    }

    public function test_la_pantalla_declara_lo_que_quedo_fuera_de_los_totales(): void
    {
        $admin = User::factory()->create(['activo' => true])->assignRole('administrador');
        $salida = $this->salida($ruta = Ruta::create(['nombre' => 'Sonsonate']), now()->toDateString());

        SalidaRutaDocumento::create([
            'salida_ruta_id' => $salida->id,
            'dte_id' => null,
            'numero_control' => 'DTE-03-M001P001-000000000000940',
            'origen' => SalidaRutaDocumento::ORIGEN_P001,
            'monto' => null,
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ]);

        $this->actingAs($admin)->get(route('rutas.documentos.index'))
            ->assertOk()
            ->assertSee('sin monto conocido')
            ->assertSee('No se cuentan como cero.');
    }
}
