<?php

namespace Tests\Feature\Ppq;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoSolicitudCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\EstadoPpq;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\NcExportacion;
use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\EstadoRealLotePpq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Recorrido de la oficina, de punta a punta y por las pantallas: armar un PPQ con CCF y su
 * NC desde Cobros, cargar las NC al portal, preparar y presentar el quedan en Cobros, y
 * después sacar del PPQ lo que el cliente no aceptó.
 *
 * El lote presentado desde Cobros sigue en «borrador» en su columna: no se edita (lo decide
 * su estado real), pero «quitar» sí sigue disponible, porque así se saca lo no aceptado.
 * Datos inventados.
 */
class PpqRecorridoPresentadoDesdeCobrosTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private Cliente $cliente;

    private $estab = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('dte.storage.disk', 'local'));

        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba']);
        ClientePerfilDocumento::create(['cliente_id' => $this->cliente->id, 'activo' => true, 'codigo_proveedor' => '000123',
            'formato_export' => 'carga_masiva_nc_v1', 'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0]);
        app(PerfilDocumentoResolver::class)->olvidar();
        config(['ppq.cliente_default_id' => (string) $this->cliente->id]);
    }

    private function usuario(RolSistema $rol = RolSistema::Facturacion): User
    {
        return User::factory()->create()->assignRole($rol->value);
    }

    private function dte(string $tipo, array $extra = []): Dte
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $this->n++;

        return Dte::create($extra + [
            'establecimiento_id' => $this->estab->id, 'tipo_dte' => $tipo, 'estado' => 'aceptado', 'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'numero_control' => 'DTE-'.$tipo.'-M001P002-'.str_pad((string) (700 + $this->n), 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOPRUEBA'.Str::random(8), 'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-09-10', 'hora_emision' => '08:00:00', 'total_pagar' => '100.00',
            'numero_orden_compra' => '2609001700'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT),
        ]);
    }

    /** CCF en el Seguimiento, entregado (AC01 vinculado): listo para PPQ y para quedan. */
    private function ccf(): CobroDocumento
    {
        $dte = $this->dte('03');
        $doc = CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id,
            'tipo_dte' => '03', 'numero_control' => $dte->numero_control, 'fecha_emision' => '2026-09-10', 'monto' => '100.00',
        ]);
        $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0017/00/'.(6000 + $doc->id), 'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-09-05', 'monto_albaran' => '100.00', 'sala_codigo' => '0017',
            'numero_orden_compra' => $dte->numero_orden_compra]);
        $doc->forceFill(['ppq_albaran_id' => $albaran->id, 'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value])->save();

        return $doc;
    }

    /** NC aceptada contra el CCF, con su albarán de crédito propio. */
    private function nc(CobroDocumento $ccf): Dte
    {
        $nc = $this->dte('05', ['dte_relacionado_id' => $ccf->dte_id, 'total_pagar' => '12.34']);
        DteAlbaran::create(['dte_id' => $nc->id, 'numero_canonico' => 'AC04/0017/00/'.(3000 + $this->n), 'tipo_codigo' => 'AC04',
            'sala_codigo' => '0017', 'numero' => (string) (3000 + $this->n), 'fecha' => '2026-09-01', 'total' => '12.34']);

        return $nc;
    }

    /** «Crear PPQ» en Facturas por cobrar con los CCF marcados. */
    private function crearPpq(User $usuario, CobroDocumento ...$docs)
    {
        return $this->actingAs($usuario)->from(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->post(route('cobros.ppq.crear', $this->cliente), ['documentos' => array_map(fn ($d) => $d->id, $docs)]);
    }

    /** Archivo de NC desde la ficha del PPQ y su carga al portal registrada. */
    private function cargarNcAlPortal(User $usuario, PpqLote $lote): NcExportacion
    {
        $this->actingAs($usuario)->post(route('ppq.lotes.archivo-nc', $lote))->assertOk();
        $formato = NcExportacion::latest('id')->firstOrFail();
        $this->actingAs($usuario)->post(route('ppq.nc-exportaciones.presentar', $formato), ['presentada_en' => today()->toDateString()])
            ->assertSessionHas('status');

        return $formato->refresh();
    }

    /** Vista previa del quedan, confirmación y «Registrar presentación» en Cobros. */
    private function presentarQuedan(User $usuario, CobroDocumento ...$docs): CobroSolicitud
    {
        $previa = $this->actingAs($usuario)->post(route('cobros.solicitudes.previa', $this->cliente),
            ['documentos' => array_map(fn ($d) => $d->id, $docs)])->assertOk();
        $this->actingAs($usuario)->post(route('cobros.solicitudes.store', $this->cliente), ['previa' => $previa->viewData('token')])
            ->assertSessionHasNoErrors()->assertSessionHas('status');
        $solicitud = CobroSolicitud::latest('id')->firstOrFail();
        $this->actingAs($usuario)->from(route('cobros.solicitudes.show', $solicitud))
            ->post(route('cobros.solicitudes.presentar', $solicitud), ['presentada_en' => today()->toDateString()])
            ->assertSessionHas('status');

        return $solicitud->refresh();
    }

    private function estadoReal(PpqLote $lote): string
    {
        return app(EstadoRealLotePpq::class)->calcular(collect([$lote->fresh()]))[$lote->id]['estado']['key'];
    }

    private function item(PpqLote $lote, Dte|CobroDocumento $de): PpqItem
    {
        $dteId = $de instanceof Dte ? $de->id : $de->dte_id;

        return $lote->items()->where('dte_id', $dteId)->sole();
    }

    private function quitar(User $usuario, PpqLote $lote, PpqItem $item)
    {
        return $this->actingAs($usuario)->from(route('ppq.lotes.show', $lote))
            ->delete(route('ppq.lotes.items.destroy', [$lote, $item]));
    }

    /**
     * Lo que la oficina hace cuando el cliente no acepta un CCF de un PPQ cuyo quedan ya se
     * presentó en Cobros.
     */
    public function test_ppq_presentado_desde_cobros_no_se_edita_pero_deja_quitar_el_ccf_y_su_nc(): void
    {
        $oficina = $this->usuario();
        $a = $this->ccf();
        $b = $this->ccf();
        $ncA = $this->nc($a);

        // 1. Armar el PPQ desde Cobros: entran los dos CCF y la NC de A.
        $this->crearPpq($oficina, $a, $b)->assertSessionHasNoErrors()->assertSessionHas('status');
        $lote = PpqLote::sole();
        $this->assertSame(3, $lote->items()->count());
        $this->assertSame(EstadoPresentacionCobro::Preparada, $a->refresh()->presentacion_estado);
        $this->assertSame('armado', $this->estadoReal($lote));

        // 2. NC al portal y quedan presentado desde Cobros.
        $this->cargarNcAlPortal($oficina, $lote);
        $this->travel(1)->seconds(); // el quedan se arma después del lote, como en la oficina
        $solicitud = $this->presentarQuedan($oficina, $a, $b);
        $this->assertSame(EstadoSolicitudCobro::Presentada, $solicitud->estado);
        $this->assertSame(EstadoPresentacionCobro::Presentada, $a->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::Presentada, $b->refresh()->presentacion_estado);
        $this->assertSame(EstadoPpq::Borrador, $lote->refresh()->estado, 'La columna del lote no la mueve Cobros.');
        $this->assertSame('presentado', $this->estadoReal($lote));

        // Doble clic en «Registrar presentación»: error claro y nada cambia.
        $this->actingAs($oficina)->from(route('cobros.solicitudes.show', $solicitud))
            ->post(route('cobros.solicitudes.presentar', $solicitud), ['presentada_en' => today()->toDateString()])
            ->assertSessionHasErrors('solicitud');
        $this->assertSame(2, $a->eventos()->where('tipo', TipoEventoCobro::Presentacion->value)->count()
            + $b->eventos()->where('tipo', TipoEventoCobro::Presentacion->value)->count());

        // 3. Ya no se edita: la ficha no ofrece «Editar» y el PUT se niega sin cambiar nada.
        $this->actingAs($oficina)->get(route('ppq.lotes.show', $lote))->assertOk()
            ->assertSee('Presentado')->assertDontSee(route('ppq.lotes.edit', $lote), false);
        $referencia = $lote->referencia;
        $this->actingAs($oficina)->from(route('ppq.lotes.show', $lote))->put(route('ppq.lotes.update', $lote), [
            'referencia' => 'Cambiada', 'fecha' => '2026-09-01',
        ])->assertSessionHas('error', 'Este PPQ está Presentado y ya no se edita.');
        $this->assertSame($referencia, $lote->refresh()->referencia);
        // Abrir «/editar» a mano (un enlace guardado) tampoco muestra el formulario.
        $this->actingAs($oficina)->get(route('ppq.lotes.edit', $lote))
            ->assertRedirect(route('ppq.lotes.show', $lote))->assertSessionHas('error', 'Este PPQ está Presentado y ya no se edita.');

        // Tampoco se vuelve a marcar presentado a mano.
        $this->actingAs($oficina)->post(route('ppq.lotes.marcar-presentado', $lote), ['fecha' => today()->toDateString()])
            ->assertSessionHas('error', 'Este PPQ ya figura como presentado.');
        $this->assertSame(EstadoPpq::Borrador, $lote->refresh()->estado);

        // 4. Quitar el CCF no aceptado: sale del lote, con constancia, y avisa de su NC.
        $itemA = $this->item($lote, $a);
        $numeroA = $itemA->correlativoNumero();
        $this->quitar($oficina, $lote, $itemA)->assertSessionHas('status', fn (string $m) => str_contains($m, "El CCF {$numeroA} salió del lote")
            && str_contains($m, 'quitala también'));
        $this->assertFalse(PpqItem::whereKey($itemA->id)->exists());
        // Doble clic: el renglón ya no existe y no cambia nada más.
        $this->quitar($oficina, $lote, $itemA)->assertNotFound();

        // 5. Y su NC.
        $this->quitar($oficina, $lote, $this->item($lote, $ncA))->assertSessionHas('status', fn (string $m) => str_contains($m, 'salió del lote'));

        $lote->refresh();
        $this->assertSame([$b->dte_id], $lote->items()->pluck('dte_id')->all(), 'En el lote queda solo B.');
        $this->assertSame('presentado', $this->estadoReal($lote));
        $this->assertStringContainsString('Quitado a mano el', (string) $lote->observaciones);
        $this->assertSame(2, DB::table('activity_log')->where('description', 'ppq.item_quitado')
            ->where('subject_id', $lote->id)->where('causer_id', $oficina->id)->count());

        // 6. A sale del quedan presentado (que no tenía acuse) y vuelve a «por presentar»; el
        //    quedan conserva sus renglones. Entra en otro PPQ con su NC
        //    (detalle en PpqQuitarCcfDelQuedanPresentadoTest).
        $a->refresh();
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $a->presentacion_estado);
        $this->assertNull($a->cobro_solicitud_id);
        $this->assertSame(2, $solicitud->items()->count());
        $this->crearPpq($oficina, $a)->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->assertSame(2, PpqLote::count());

        // 7. Bandeja e historial siguen abriendo y muestran lo que quedó.
        $this->actingAs($oficina)->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))->assertOk();
        $this->actingAs($oficina)->get(route('ppq.lotes.index'))->assertOk()->assertSee($lote->referencia);
        $this->actingAs($oficina)->get(route('ppq.lotes.show', $lote))->assertOk()->assertDontSee($a->numero_control);
    }

    /**
     * Cuando el CCF no aceptado todavía no estaba en el quedan presentado (solo «en PPQ»),
     * quitarlo lo devuelve a «por presentar», con nota en su historial, y entra en el
     * siguiente PPQ junto con su NC.
     */
    public function test_el_ccf_quitado_que_estaba_en_ppq_vuelve_a_por_presentar_y_entra_en_otro_lote_con_su_nc(): void
    {
        $oficina = $this->usuario();
        $a = $this->ccf();
        $b = $this->ccf();
        $ncA = $this->nc($a);
        $this->crearPpq($oficina, $a, $b)->assertSessionHas('status');
        $lote = PpqLote::sole();
        $this->cargarNcAlPortal($oficina, $lote);
        $this->presentarQuedan($oficina, $b); // en el quedan va solo B
        $this->assertSame('presentado', $this->estadoReal($lote));

        $this->quitar($oficina, $lote, $this->item($lote, $a))
            ->assertSessionHas('status', fn (string $m) => str_contains($m, 'volvió a por presentar'));
        $this->quitar($oficina, $lote, $this->item($lote, $ncA))->assertSessionHas('status');

        $a->refresh();
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $a->presentacion_estado);
        $this->assertNull($a->cobro_solicitud_id);
        $this->assertSame(1, $a->eventos()->where('tipo', TipoEventoCobro::Nota->value)
            ->where('referencia_linea', "ppq-{$lote->id}-quitado")->count(), 'Queda en el historial del CCF.');
        $this->actingAs($oficina)->get(route('cobros.documentos.show', $a))->assertOk()
            ->assertSee('vuelve a por presentar');

        // Entra en un PPQ nuevo, con su NC.
        $this->crearPpq($oficina, $a)->assertSessionHasNoErrors()->assertSessionHas('status');
        $nuevo = PpqLote::latest('id')->firstOrFail();
        $this->assertNotSame($lote->id, $nuevo->id);
        $this->assertEqualsCanonicalizing([$a->dte_id, $ncA->id], $nuevo->items()->pluck('dte_id')->all());
        $this->assertSame(EstadoPresentacionCobro::Preparada, $a->refresh()->presentacion_estado);
        $this->assertSame('armado', $this->estadoReal($nuevo));
        $this->assertSame('presentado', $this->estadoReal($lote), 'El PPQ anterior sigue presentado con B.');

        // Y no se puede meter dos veces: otro «Crear PPQ» con A se niega.
        $this->crearPpq($oficina, $a)->assertSessionHasErrors('documentos');
        $this->assertSame(2, PpqLote::count());
    }

    public function test_quien_solo_ve_ppq_no_quita_ni_edita_un_lote_presentado_y_nada_cambia(): void
    {
        $oficina = $this->usuario();
        $a = $this->ccf();
        $this->crearPpq($oficina, $a)->assertSessionHas('status');
        $lote = PpqLote::sole();
        $this->presentarQuedan($oficina, $a);
        $item = $this->item($lote, $a);

        foreach ([RolSistema::Jefatura, RolSistema::Contabilidad] as $rol) {
            $quien = $this->usuario($rol);
            $this->quitar($quien, $lote, $item)->assertForbidden();
            $this->actingAs($quien)->put(route('ppq.lotes.update', $lote), ['referencia' => 'No va', 'fecha' => '2026-09-01'])
                ->assertForbidden();
            $this->actingAs($quien)->post(route('cobros.ppq.crear', $this->cliente), ['documentos' => [$a->id]])->assertForbidden();
        }

        $this->assertTrue(PpqItem::whereKey($item->id)->exists());
        $this->assertNotSame('No va', $lote->refresh()->referencia);
        $this->assertSame(EstadoPresentacionCobro::Presentada, $a->refresh()->presentacion_estado);
        $this->assertSame(1, PpqLote::count());
    }
}
