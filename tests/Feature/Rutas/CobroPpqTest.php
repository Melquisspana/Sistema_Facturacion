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
use App\Services\Rutas\SeguimientoDocumentos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Seguimiento de COBRO de una salida: qué dice PPQ de cada documento.
 *
 * Lo que estas pruebas defienden:
 *
 *  - estar en un lote NO es estar pagado. Solo se llama «Pagado» a lo que PPQ
 *    concilió contra el TXT de Calleja, que es la única regla que el sistema ya
 *    tenía escrita para eso;
 *  - el estado se DERIVA al consultarlo: una salida finalizada el lunes muestra el
 *    pago que llegó el miércoles sin que nadie sincronice nada;
 *  - nada de PPQ se copia a `salida_ruta_documentos`, y consultar no audita;
 *  - P001 histórico se resuelve por número de control, igual que P002.
 */
class CobroPpqTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

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
        $cliente = Cliente::factory()->create(['nombre' => 'Calleja']);

        return $cliente->sucursales()->create([
            'nombre' => 'Selectos San Miguel',
            'codigo' => '0240',
            'ruta_id' => $ruta->id,
        ]);
    }

    private function salida(Ruta $ruta, EstadoSalidaRuta $estado = EstadoSalidaRuta::Planificada): SalidaRuta
    {
        return SalidaRuta::create([
            'ruta_id' => $ruta->id,
            'fecha_inicio' => '2026-08-14',
            'fecha_fin_estimada' => '2026-08-16',
            'estado' => $estado,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function ccf(?ClienteSucursal $sala, string $control, array $extra = []): Dte
    {
        return Dte::create($extra + [
            'establecimiento_id' => $this->establecimiento()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            // Fiscalmente VIGENTE. Desde la Fase 0, a una salida de ruta solo entra un
            // CCF que existe de verdad ante Hacienda: produccion, aceptado y con sello
            // real. Un CCF de prueba tiene que parecerse a uno real o no entra.
            'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(substr(md5($control), 0, 12)),
            'fecha_procesamiento_mh' => '2026-08-14 12:00:00',
            'cliente_id' => $sala?->cliente_id,
            'cliente_sucursal_id' => $sala?->id,
            'numero_control' => $control,
            'numero_orden_compra' => '260600232002345',
            'fecha_emision' => '2026-08-14',
            'hora_emision' => '10:00:00',
            'total_pagar' => 113.58,
        ]);
    }

    private function lote(string $referencia = 'PPQ de prueba', EstadoPpq $estado = EstadoPpq::Listo): PpqLote
    {
        return PpqLote::create([
            'referencia' => $referencia,
            'fecha' => '2026-08-20',
            'estado' => $estado,
        ]);
    }

    /** Renglón de PPQ. Por defecto pendiente (dentro del lote, todavía sin pagar). */
    private function item(PpqLote $lote, array $extra = []): PpqItem
    {
        return PpqItem::create($extra + [
            'ppq_lote_id' => $lote->id,
            'dte_id' => null,
            'origen' => 'gmail',
            'numero_control' => 'DTE-03-M001P002-000000000000001',
            'tipo_dte' => '03',
            'monto_dte' => 113.58,
        ]);
    }

    /** Documento P002 dentro de una salida. */
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

    private function seguimiento(): SeguimientoDocumentos
    {
        return app(SeguimientoDocumentos::class);
    }

    // ==================================================== 1) P002 por dte_id

    public function test_p002_encuentra_su_ppq_por_dte_id(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $ccf = $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001');
        $documento = $this->documento($salida, $ccf);

        // El item apunta al DTE y trae OTRO número de control: así se prueba que
        // ganó el vínculo explícito y no el número.
        $item = $this->item($this->lote(), [
            'dte_id' => $ccf->id,
            'numero_control' => 'DTE-03-M001P002-000000000000999',
        ]);

        $this->assertTrue($documento->enPpq());
        $this->assertSame($item->id, $documento->ppqItem()->id);
        $this->assertFalse($documento->pagado());
    }

    // ======================================= 2) P002 por número (dte_id NULL)

    public function test_p002_se_localiza_por_numero_de_control_cuando_el_item_no_tiene_dte_id(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $ccf = $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001');
        $documento = $this->documento($salida, $ccf);

        // Es el caso REAL de hoy: los 158 items existentes vienen de Gmail y todos
        // tienen dte_id NULL.
        $item = $this->item($this->lote(), ['dte_id' => null]);

        $this->assertTrue($documento->enPpq());
        $this->assertSame($item->id, $documento->ppqItem()->id);
    }

    public function test_el_numero_de_control_se_compara_normalizado(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $ccf = $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001');
        $documento = $this->documento($salida, $ccf);

        // Mismo documento escrito sin guiones: PPQ ya normaliza así para cruzar
        // contra el TXT, y acá se usa exactamente la misma función.
        $item = $this->item($this->lote(), ['numero_control' => 'DTE03M001P002000000000000001']);

        $this->assertTrue($documento->enPpq());
        $this->assertSame($item->id, $documento->ppqItem()->id);
    }

    public function test_el_numero_se_normaliza_tambien_del_lado_de_ppq(): void
    {
        // La dirección contraria de la prueba anterior, y la que de verdad pasa: el
        // histórico se teclea sin guiones y PPQ lo tiene con guiones. Normalizar un
        // solo lado arreglaba un caso y dejaba este roto.
        $salida = $this->salida(Ruta::create(['nombre' => 'San Miguel']));

        $documento = SalidaRutaDocumento::create([
            'salida_ruta_id' => $salida->id,
            'dte_id' => null,
            'numero_control' => 'DTE03M001P001000000000001090',
            'origen' => SalidaRutaDocumento::ORIGEN_P001,
            'monto' => 195.24,
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ]);

        $item = $this->item($this->lote(), [
            'numero_control' => 'DTE-03-M001P001-000000000001090',
            'monto_dte' => 195.24,
            'conciliacion_estado' => 'pagado',
            'fecha_pago' => '2026-07-10',
            'monto_pagado' => 195.24,
        ]);

        $this->assertSame($item->id, $documento->ppqItem()?->id);
        $this->assertTrue($documento->pagado());
    }

    // ============================================ 3) P001 por número de control

    public function test_p001_historico_se_localiza_por_numero_de_control(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);

        $documento = SalidaRutaDocumento::create([
            'salida_ruta_id' => $salida->id,
            'dte_id' => null,
            'numero_control' => 'DTE-03-M001P001-000000000000940',
            'origen' => SalidaRutaDocumento::ORIGEN_P001,
            'cliente_nombre' => 'Calleja',
            'monto' => 90.00,
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ]);

        $item = $this->item($this->lote(), [
            'numero_control' => 'DTE-03-M001P001-000000000000940',
            'monto_dte' => 90.00,
            'conciliacion_estado' => 'pagado',
            'fecha_pago' => '2026-08-25',
            'monto_pagado' => 90.00,
        ]);

        $this->assertTrue($documento->esHistorico());
        $this->assertTrue($documento->enPpq());
        $this->assertSame($item->id, $documento->ppqItem()->id);
        $this->assertTrue($documento->pagado());
        // Nada se importó a `dtes`: sigue siendo un histórico sin DTE.
        $this->assertNull($documento->dte_id);
        $this->assertDatabaseMissing('dtes', ['numero_control' => 'DTE-03-M001P001-000000000000940']);
    }

    // ================================================= 4) sin PPQ

    public function test_un_documento_sin_item_no_esta_en_ppq(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001'));

        $this->assertFalse($documento->enPpq());
        $this->assertNull($documento->ppqItem());
        $this->assertFalse($documento->pagado());
        $this->assertNull($documento->fechaPago());
        $this->assertNull($documento->montoPagado());
    }

    public function test_un_item_de_otro_documento_no_lo_contagia(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $sala = $this->sala($ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000001');
        $documento = $this->documento($salida, $ccf);

        // Otro CCF de la MISMA sala y la MISMA orden de compra, ya pagado. Si el
        // localizador cayera en casar por OC o por sala, este pago se vería en el
        // documento de arriba, que nadie pagó.
        $this->item($this->lote(), [
            'numero_control' => 'DTE-03-M001P002-000000000000002',
            'numero_orden_compra' => '260600232002345',
            'conciliacion_estado' => 'pagado',
            'fecha_pago' => '2026-08-25',
            'monto_pagado' => 113.58,
        ]);

        $this->assertFalse($documento->enPpq());
        $this->assertFalse($documento->pagado());
    }

    // ============================================ 5) en PPQ pero NO pagado

    public function test_estar_en_un_lote_no_es_estar_pagado(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001'));

        $this->item($this->lote(), ['conciliacion_estado' => null]);

        $this->assertTrue($documento->enPpq());
        $this->assertFalse($documento->pagado());
        $this->assertNull($documento->fechaPago());
    }

    public function test_un_lote_marcado_pagado_no_paga_a_sus_documentos(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001'));

        // El estado del LOTE es una etiqueta de gestión del paquete y se pone a mano;
        // la prueba de cobro de ESTE renglón es su conciliación contra el TXT.
        $this->item($this->lote('PPQ cerrado', EstadoPpq::Pagado), ['conciliacion_estado' => null]);

        $this->assertTrue($documento->enPpq());
        $this->assertFalse($documento->pagado());
    }

    // ============================================ 6) pagado con fecha y monto

    public function test_un_documento_pagado_muestra_fecha_y_monto(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001'));

        $this->item($this->lote(), [
            'conciliacion_estado' => 'pagado',
            'fecha_pago' => '2026-08-25',
            'monto_pagado' => 113.58,
        ]);

        $this->assertTrue($documento->pagado());
        $this->assertSame('2026-08-25', $documento->fechaPago()->toDateString());
        $this->assertSame(113.58, $documento->montoPagado());
        // Coincide con el documento: no hay diferencia que reportar.
        $this->assertNull($documento->diferenciaPago());
    }

    public function test_se_reporta_la_diferencia_cuando_pagaron_otro_monto(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001'));

        $this->item($this->lote(), [
            'conciliacion_estado' => 'pagado',
            'fecha_pago' => '2026-08-25',
            'monto_pagado' => 100.00,
        ]);

        $this->assertSame(13.58, $documento->diferenciaPago());
    }

    // ==================================== 7) el estado sigue vivo tras finalizar

    public function test_una_salida_finalizada_refleja_el_pago_que_llega_despues(): void
    {
        $admin = $this->admin();
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta, EstadoSalidaRuta::Finalizada);
        $ccf = $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001');
        $this->documento($salida, $ccf);

        // Lunes: la salida ya está cerrada y no hay nada en PPQ.
        $this->actingAs($admin)->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->assertSee('No está en PPQ');

        // Miércoles: entra a un lote. Nadie sincroniza nada.
        $item = $this->item($this->lote('PPQ del miércoles'));

        $this->actingAs($admin)->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->assertSee('En PPQ')
            ->assertSee('PPQ del miércoles')
            ->assertDontSee('No está en PPQ');

        // La semana siguiente: se concilia el pago.
        $item->forceFill([
            'conciliacion_estado' => 'pagado',
            'fecha_pago' => '2026-09-02',
            'monto_pagado' => 113.58,
        ])->save();

        $this->actingAs($admin)->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->assertSee('Pagado');

        // Y la salida siguió finalizada todo el tiempo: el cobro no la reabre.
        $this->assertSame(EstadoSalidaRuta::Finalizada, $salida->fresh()->estado);
    }

    // ================================================= 8) NC y PPQ separados

    public function test_la_nc_y_el_cobro_son_dos_hechos_distintos(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $sala = $this->sala($ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000001');
        $documento = $this->documento($salida, $ccf);

        // NC real emitida contra el CCF, y ella misma aplicada en PPQ...
        $nc = $this->ccf($sala, 'DTE-05-M001P002-000000000000002', [
            'tipo_dte' => '05',
            'dte_relacionado_id' => $ccf->id,
            'total_pagar' => 20.00,
        ]);

        $lote = $this->lote();
        $this->item($lote, [
            'numero_control' => $nc->numero_control,
            'tipo_dte' => '05',
            'monto_dte' => 20.00,
            'conciliacion_estado' => 'aplicada',
            'fecha_pago' => '2026-08-25',
            'monto_pagado' => 20.00,
        ]);

        // ...pero el CCF NO entró a ningún lote.
        $this->assertNotNull($documento->notaCredito());
        $this->assertSame($nc->id, $documento->notaCredito()->id);

        // Que la NC esté aplicada no cobra el documento.
        $this->assertFalse($documento->enPpq());
        $this->assertFalse($documento->pagado());

        // La NC aplicada se lee igual, por su cuenta y con su nombre.
        $this->assertNotNull($documento->ppqNotaCredito());
        $this->assertSame('aplicada', $documento->ppqNotaCredito()->conciliacion_estado);
    }

    // ============================== una NC sin efecto no cuenta como corrección

    public function test_una_nc_rechazada_no_cuenta_como_correccion_vigente(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $sala = $this->sala($ruta);

        $conRechazada = $this->documento($salida, $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000001'));
        $this->ccf($sala, 'DTE-05-M001P002-000000000000010', [
            'tipo_dte' => '05',
            'dte_relacionado_id' => $ccf->id,
            'estado' => 'rechazado',
            'total_pagar' => 15.00,
        ]);

        $conAceptada = $this->documento($salida, $otro = $this->ccf($sala, 'DTE-03-M001P002-000000000000002'));
        $this->ccf($sala, 'DTE-05-M001P002-000000000000011', [
            'tipo_dte' => '05',
            'dte_relacionado_id' => $otro->id,
            'estado' => 'aceptado',
            'total_pagar' => 20.00,
        ]);

        // Las dos NC se HALLAN y las dos se muestran...
        $this->assertNotNull($conRechazada->notaCredito());
        $this->assertNotNull($conAceptada->notaCredito());

        // ...pero solo una sigue corrigiendo algo.
        $this->assertFalse($conRechazada->notaCreditoVigente());
        $this->assertTrue($conAceptada->notaCreditoVigente());

        $seguimiento = $this->seguimiento();
        $resumen = $seguimiento->resumen($seguimiento->documentosDe($salida->fresh()));

        $this->assertSame(2, $resumen['nc_reales'], 'las dos se ven en la lista');
        $this->assertSame(1, $resumen['nc_vigentes'], 'solo una descuenta algo');
    }

    public function test_una_nc_invalidada_tampoco_cuenta(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $sala = $this->sala($ruta);

        $documento = $this->documento($salida, $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000001'));
        $this->ccf($sala, 'DTE-05-M001P002-000000000000010', [
            'tipo_dte' => '05',
            'dte_relacionado_id' => $ccf->id,
            'estado' => 'invalidado',
            'total_pagar' => 15.00,
        ]);

        $this->assertNotNull($documento->notaCredito());
        $this->assertFalse($documento->notaCreditoVigente());
    }

    public function test_la_pantalla_declara_las_nc_sin_efecto_en_vez_de_esconderlas(): void
    {
        $admin = $this->admin();
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $sala = $this->sala($ruta);

        $this->documento($salida, $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000001'));
        $this->ccf($sala, 'DTE-05-M001P002-000000000000010', [
            'tipo_dte' => '05',
            'dte_relacionado_id' => $ccf->id,
            'estado' => 'rechazado',
            'total_pagar' => 15.00,
        ]);

        $this->actingAs($admin)->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->assertSee('1 sin efecto')
            // La NC sigue a la vista en la tarjeta del documento.
            ->assertSee('NC rechazada');
    }

    // ================================= 9) nada de PPQ se escribe en la tabla

    public function test_consultar_el_cobro_no_escribe_nada_en_el_documento(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001'));

        $this->item($this->lote(), [
            'conciliacion_estado' => 'pagado',
            'fecha_pago' => '2026-08-25',
            'monto_pagado' => 113.58,
        ]);

        $antes = $documento->fresh()->toArray();

        $this->assertTrue($documento->pagado());
        $this->assertSame(113.58, $documento->montoPagado());

        $this->assertSame($antes, $documento->fresh()->toArray());

        // La tabla no tiene —ni debe tener— columnas de cobro.
        foreach (['pagado', 'fecha_pago', 'monto_pagado', 'conciliacion_estado'] as $columna) {
            $this->assertFalse(
                Schema::hasColumn('salida_ruta_documentos', $columna),
                "salida_ruta_documentos no debe tener la columna {$columna}: la verdad de cobro vive en ppq_items.",
            );
        }
    }

    public function test_consultar_el_cobro_no_toca_ppq(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001'));

        $item = $this->item($this->lote(), [
            'conciliacion_estado' => 'pagado',
            'fecha_pago' => '2026-08-25',
            'monto_pagado' => 113.58,
        ]);

        $antes = $item->fresh()->toArray();
        $documento->pagado();
        $this->assertSame($antes, $item->fresh()->toArray());
    }

    // ============================================ 10) consultar no audita

    public function test_consultar_el_estado_de_cobro_no_genera_auditoria(): void
    {
        $admin = $this->admin();
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $this->documento($salida, $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001'));

        $this->item($this->lote(), [
            'conciliacion_estado' => 'pagado',
            'fecha_pago' => '2026-08-25',
            'monto_pagado' => 113.58,
        ]);

        $antes = Activity::count();

        $this->actingAs($admin)->get(route('rutas.salidas.show', $salida))->assertOk();

        $this->assertSame($antes, Activity::count(), 'Mirar una pantalla no es un acto que se audite.');
    }

    // ================================================ contadores del resumen

    public function test_el_resumen_cuenta_en_ppq_y_pagados_por_separado(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $sala = $this->sala($ruta);
        $lote = $this->lote();

        // 1) pagado, 2) en PPQ pendiente, 3) fuera de PPQ.
        $pagado = $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001'));
        $pendiente = $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000002'));
        $fuera = $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000003'));

        $this->item($lote, [
            'numero_control' => 'DTE-03-M001P002-000000000000001',
            'conciliacion_estado' => 'pagado',
            'fecha_pago' => '2026-08-25',
            'monto_pagado' => 113.58,
        ]);
        $this->item($lote, ['numero_control' => 'DTE-03-M001P002-000000000000002']);

        $seguimiento = $this->seguimiento();
        $documentos = $seguimiento->documentosDe($salida->fresh());
        $resumen = $seguimiento->resumen($documentos);

        $this->assertSame(3, $resumen['total']);
        $this->assertSame(2, $resumen['en_ppq']);
        $this->assertSame(1, $resumen['sin_ppq']);
        $this->assertSame(1, $resumen['pagados']);

        // El contador cuenta las MISMAS filas que se listan.
        $this->assertSame(
            $resumen['pagados'],
            $documentos->filter(fn ($d) => $d->pagado())->count(),
        );
        unset($pagado, $pendiente, $fuera);
    }

    public function test_un_documento_en_varios_lotes_muestra_el_conciliado(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001'));

        // Mismo CCF en dos lotes: en uno quedó pendiente, en el otro se cobró. Un
        // pago registrado no desaparece porque otro lote lo tenga pendiente.
        $this->item($this->lote('PPQ viejo'), ['conciliacion_estado' => null]);
        $this->item($this->lote('PPQ que se cobró'), [
            'conciliacion_estado' => 'pagado',
            'fecha_pago' => '2026-08-25',
            'monto_pagado' => 113.58,
        ]);

        $this->assertTrue($documento->pagado());
        $this->assertSame('PPQ que se cobró', $documento->ppqItem()->lote->referencia);
    }

    // ====================================================== la pantalla real

    public function test_la_pantalla_muestra_el_bloque_de_cobro(): void
    {
        $admin = $this->admin();
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $this->documento($salida, $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001'));

        $this->actingAs($admin)->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->assertSee('Cobro / PPQ')
            ->assertSee('No está en PPQ')
            ->assertSee('0 pagados');
    }
}
