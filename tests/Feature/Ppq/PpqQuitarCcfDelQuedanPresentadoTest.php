<?php

namespace Tests\Feature\Ppq;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoSolicitudCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\NcExportacion;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\EstadoRealLotePpq;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * El cliente no acepta un CCF de un quedan que ya se presentó desde Cobros: la oficina lo
 * quita del PPQ con su NC. Ese CCF tiene que volver a «por presentar» y poder entrar en otro
 * PPQ y en otro quedan. Antes se quedaba «presentado» colgando del quedan viejo y ya no
 * entraba en ningún lote. Datos inventados.
 */
class PpqQuitarCcfDelQuedanPresentadoTest extends TestCase
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

    private function usuario(): User
    {
        return User::factory()->create()->assignRole(RolSistema::Facturacion->value);
    }

    private function dte(string $tipo, array $extra = []): Dte
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $this->n++;

        return Dte::create($extra + [
            'establecimiento_id' => $this->estab->id, 'tipo_dte' => $tipo, 'estado' => 'aceptado', 'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'numero_control' => 'DTE-'.$tipo.'-M001P002-'.str_pad((string) (900 + $this->n), 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOPRUEBA'.Str::random(8), 'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-09-10', 'hora_emision' => '08:00:00', 'total_pagar' => '100.00',
            'numero_orden_compra' => '2609001900'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT),
        ]);
    }

    private function ccf(): CobroDocumento
    {
        $dte = $this->dte('03');
        $doc = CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id,
            'tipo_dte' => '03', 'numero_control' => $dte->numero_control, 'fecha_emision' => '2026-09-10', 'monto' => '100.00',
        ]);
        $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0017/00/'.(8000 + $doc->id), 'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-09-05', 'monto_albaran' => '100.00', 'sala_codigo' => '0017',
            'numero_orden_compra' => $dte->numero_orden_compra]);
        $doc->forceFill(['ppq_albaran_id' => $albaran->id, 'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value])->save();

        return $doc;
    }

    private function nc(CobroDocumento $ccf): Dte
    {
        $nc = $this->dte('05', ['dte_relacionado_id' => $ccf->dte_id, 'total_pagar' => '12.34']);
        DteAlbaran::create(['dte_id' => $nc->id, 'numero_canonico' => 'AC04/0017/00/'.(4000 + $this->n), 'tipo_codigo' => 'AC04',
            'sala_codigo' => '0017', 'numero' => (string) (4000 + $this->n), 'fecha' => '2026-09-01', 'total' => '12.34']);

        return $nc;
    }

    private function crearPpq(User $usuario, CobroDocumento ...$docs)
    {
        return $this->actingAs($usuario)->from(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->post(route('cobros.ppq.crear', $this->cliente), ['documentos' => array_map(fn ($d) => $d->id, $docs)]);
    }

    private function cargarNcAlPortal(User $usuario, PpqLote $lote): void
    {
        $this->actingAs($usuario)->post(route('ppq.lotes.archivo-nc', $lote))->assertOk();
        $formato = NcExportacion::latest('id')->firstOrFail();
        $this->actingAs($usuario)->post(route('ppq.nc-exportaciones.presentar', $formato), ['presentada_en' => today()->toDateString()])
            ->assertSessionHas('status');
    }

    /** Vista previa y confirmación del quedan en Cobros, sin registrar su presentación. */
    private function generarQuedan(User $usuario, CobroDocumento ...$docs): CobroSolicitud
    {
        $previa = $this->actingAs($usuario)->post(route('cobros.solicitudes.previa', $this->cliente),
            ['documentos' => array_map(fn ($d) => $d->id, $docs)])->assertOk();
        $this->actingAs($usuario)->post(route('cobros.solicitudes.store', $this->cliente), ['previa' => $previa->viewData('token')])
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        return CobroSolicitud::latest('id')->firstOrFail();
    }

    private function registrarPresentacion(User $usuario, CobroSolicitud $solicitud): CobroSolicitud
    {
        $this->actingAs($usuario)->from(route('cobros.solicitudes.show', $solicitud))
            ->post(route('cobros.solicitudes.presentar', $solicitud), ['presentada_en' => today()->toDateString()])
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        return $solicitud->refresh();
    }

    private function presentarQuedan(User $usuario, CobroDocumento ...$docs): CobroSolicitud
    {
        return $this->registrarPresentacion($usuario, $this->generarQuedan($usuario, ...$docs));
    }

    /** El reporte del caso como lo da el portal: «CASO n» en A1 y una fila por documento registrado. */
    private function reporteCaso(string $caso, CobroDocumento ...$registrados): UploadedFile
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setCellValue('A1', "CASO {$caso} \nTitular de Ejemplo (000123)");
        $hoja->fromArray(['SALA', 'NUMERO ALBARAN', 'TIPO', 'AÑO', 'MES', 'FECHA', 'MONTO', 'CODIGO', 'DTE', 'MONTO DOC', 'ESTADO', 'PLAZO'], null, 'A2');
        foreach (array_values($registrados) as $i => $doc) {
            $hoja->fromArray(['0017', '5131', 'AC01', '26', '09', '10/09/2026', '1', 'X', str_replace('-', '', $doc->numero_control), '1', 'REGISTRADO', 'CREDITO'], null, 'A'.($i + 3));
        }
        $ruta = tempnam(sys_get_temp_dir(), 'caso').'.xls';
        (new Xls($libro))->save($ruta);

        return new UploadedFile($ruta, 'caso.xls', null, null, true);
    }

    private function cargarReporte(User $usuario, PpqLote $lote, string $caso, CobroDocumento ...$registrados)
    {
        return $this->actingAs($usuario)->from(route('ppq.lotes.show', $lote))
            ->post(route('ppq.lotes.reporte-caso', $lote), ['reporte' => $this->reporteCaso($caso, ...$registrados)]);
    }

    private function acuse(User $usuario, CobroSolicitud $solicitud, string $referencia)
    {
        return $this->actingAs($usuario)->from(route('cobros.solicitudes.show', $solicitud))
            ->post(route('cobros.solicitudes.recibido', $solicitud), ['referencia_calleja' => $referencia]);
    }

    private function estadoReal(PpqLote $lote): string
    {
        return app(EstadoRealLotePpq::class)->calcular(collect([$lote->fresh()]))[$lote->id]['estado']['key'];
    }

    private function quitar(User $usuario, PpqLote $lote, int $dteId)
    {
        $item = $lote->items()->where('dte_id', $dteId)->sole();

        return $this->actingAs($usuario)->from(route('ppq.lotes.show', $lote))
            ->delete(route('ppq.lotes.items.destroy', [$lote, $item]));
    }

    public function test_el_ccf_no_aceptado_sale_del_quedan_presentado_vuelve_a_por_presentar_y_entra_en_otro_ppq(): void
    {
        $oficina = $this->usuario();
        $a = $this->ccf();
        $b = $this->ccf();
        $ncA = $this->nc($a);
        $this->crearPpq($oficina, $a, $b)->assertSessionHasNoErrors();
        $lote = PpqLote::sole();
        $this->cargarNcAlPortal($oficina, $lote);
        $this->travel(1)->seconds(); // el quedan se arma después del lote, como en la oficina
        $quedan = $this->presentarQuedan($oficina, $a, $b);

        // Quitar el CCF no aceptado y su NC. El aviso dice de qué quedan salió.
        $this->quitar($oficina, $lote, $a->dte_id)->assertSessionHas('status',
            fn (string $m) => str_contains($m, "salió del lote y del quedan {$quedan->referencia}, y volvió a por presentar."));
        $this->quitar($oficina, $lote, $ncA->id)->assertSessionHas('status');

        // A vuelve a «por presentar», suelto del quedan viejo, con constancia en su historial.
        $a->refresh();
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $a->presentacion_estado);
        $this->assertNull($a->cobro_solicitud_id);
        $nota = $a->eventos()->where('tipo', TipoEventoCobro::Nota->value)->where('referencia_linea', "ppq-{$lote->id}-quitado")->sole();
        $this->assertStringContainsString($quedan->referencia, $nota->detalle);
        $this->assertSame($quedan->id, $nota->datos['cobro_solicitud_id']);

        // Del lado del quedan, la bitácora dice qué CCF salió, desde qué lote y quién lo sacó:
        // la ficha del quedan no muestra por qué un renglón «ya no cuelga».
        $bitacora = Activity::where('log_name', 'cobros_solicitud')->sole();
        $this->assertTrue($bitacora->subject->is($quedan));
        $this->assertTrue($bitacora->causer->is($oficina));
        $this->assertSame($a->id, $bitacora->properties['cobro_documento_id']);
        $this->assertSame($lote->id, $bitacora->properties['ppq_lote_id']);

        // El quedan viejo no cambia: sigue presentado, con los dos renglones como constancia,
        // y su ficha avisa que A ya no cuelga de él. B sigue en él.
        $quedan->refresh();
        $this->assertSame(EstadoSolicitudCobro::Presentada, $quedan->estado);
        $this->assertSame(2, $quedan->items()->count());
        $this->assertSame(EstadoPresentacionCobro::Presentada, $b->refresh()->presentacion_estado);
        $this->assertSame($quedan->id, $b->cobro_solicitud_id);
        $this->actingAs($oficina)->get(route('cobros.solicitudes.show', $quedan))->assertOk()
            ->assertSee('ya no cuelga de esta solicitud');
        $this->assertSame('presentado', $this->estadoReal($lote));

        // A entra en otro PPQ, con su NC.
        $this->crearPpq($oficina, $a)->assertSessionHasNoErrors()->assertSessionHas('status');
        $nuevo = PpqLote::latest('id')->firstOrFail();
        $this->assertNotSame($lote->id, $nuevo->id);
        $this->assertEqualsCanonicalizing([$a->dte_id, $ncA->id], $nuevo->items()->pluck('dte_id')->all());
        $this->assertSame('armado', $this->estadoReal($nuevo));

        // El acuse del quedan viejo llega después: marca a B, no a A.
        $this->acuse($oficina, $quedan, '41001')->assertSessionHasNoErrors();
        $this->assertSame(EstadoPresentacionCobro::Recibida, $b->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $a->refresh()->presentacion_estado);
        $this->assertSame(0, $a->eventos()->where('tipo', TipoEventoCobro::Recibido->value)->count());

        // Y A va en un quedan nuevo.
        $otroQuedan = $this->presentarQuedan($oficina, $a);
        $this->assertNotSame($quedan->id, $otroQuedan->id);
        $this->assertSame(EstadoPresentacionCobro::Presentada, $a->refresh()->presentacion_estado);
        $this->assertSame($otroQuedan->id, $a->cobro_solicitud_id);
    }

    /** Con el acuse del cliente ya registrado, el CCF se quita del lote pero no se suelta del quedan. */
    public function test_un_ccf_de_un_quedan_con_acuse_no_vuelve_a_por_presentar(): void
    {
        $oficina = $this->usuario();
        $a = $this->ccf();
        $b = $this->ccf();
        $this->crearPpq($oficina, $a, $b)->assertSessionHasNoErrors();
        $lote = PpqLote::sole();
        $quedan = $this->presentarQuedan($oficina, $a, $b);
        $this->acuse($oficina, $quedan, '41002')->assertSessionHasNoErrors();

        $this->quitar($oficina, $lote, $a->dte_id)->assertSessionHas('status',
            fn (string $m) => str_contains($m, "no volvió a por presentar: el cliente ya dio el acuse del quedan {$quedan->referencia}."));

        $a->refresh();
        $this->assertSame(EstadoPresentacionCobro::Recibida, $a->presentacion_estado);
        $this->assertSame($quedan->id, $a->cobro_solicitud_id);
        $this->assertSame(0, Activity::where('log_name', 'cobros_solicitud')->count(), 'No salió nada del quedan.');
        $this->crearPpq($oficina, $a)->assertSessionHasErrors('documentos');
        $this->assertSame(1, PpqLote::count());
    }

    /**
     * El acuse que entra por correo bloquea el quedan y después sus CCF. El «quitar» tiene que
     * bloquear en el mismo orden y decidir con el estado del quedan releído bajo candado: si lo
     * lee sin candado, puede soltar el CCF mientras el acuse se registra y el CCF queda «por
     * presentar» con un acuse en su historial. Acá (SQLite) se comprueba el orden de las
     * lecturas; el FOR UPDATE lo comprueba la CI con MySQL.
     */
    public function test_quitar_relee_el_quedan_con_candado_antes_de_tocar_sus_ccf(): void
    {
        $oficina = $this->usuario();
        $a = $this->ccf();
        $this->crearPpq($oficina, $a)->assertSessionHasNoErrors();
        $lote = PpqLote::sole();
        $this->travel(1)->seconds();
        $this->presentarQuedan($oficina, $a);
        $item = $lote->items()->where('dte_id', $a->dte_id)->sole();

        $consultas = [];
        DB::listen(function (QueryExecuted $q) use (&$consultas) {
            $consultas[] = $q->sql;
        });
        $this->actingAs($oficina)->from(route('ppq.lotes.show', $lote))
            ->delete(route('ppq.lotes.items.destroy', [$lote, $item]))->assertSessionHas('status');

        $tabla = fn (string $nombre) => '[`"]'.$nombre.'[`"]';
        $indice = fn (string $patron) => collect($consultas)->search(fn (string $sql) => preg_match($patron, $sql) === 1);
        $candadoQuedan = $indice('/^select \* from '.$tabla('cobro_solicitudes').' where /i');
        $primerCambio = $indice('/^update '.$tabla('cobro_documentos').' /i');
        $this->assertIsInt($candadoQuedan, 'El quedan no se releyó antes de decidir.');
        $this->assertIsInt($primerCambio);
        $lecturaCcf = collect($consultas)->take($primerCambio)
            ->filter(fn (string $sql) => preg_match('/^select \* from '.$tabla('cobro_documentos').' where /i', $sql) === 1)->keys()->last();
        $this->assertGreaterThan($candadoQuedan, $lecturaCcf, 'Los CCF se bloquearon antes que el quedan.');
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertStringContainsStringIgnoringCase('for update', $consultas[$candadoQuedan]);
            $this->assertStringContainsStringIgnoringCase('for update', $consultas[$lecturaCcf]);
        }
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $a->refresh()->presentacion_estado);
    }

    /**
     * El quedan se presentó directo desde Cobros y después alguien agregó ese CCF a mano a un
     * lote. Quitarlo de ese lote no lo saca del quedan: el quedan no salió de este lote. Antes
     * volvía a «por presentar» y se podía presentar dos veces.
     */
    public function test_un_ccf_agregado_al_lote_despues_de_presentar_su_quedan_no_sale_del_quedan_al_quitarlo(): void
    {
        $oficina = $this->usuario();
        $x = $this->ccf();
        $y = $this->ccf();
        $quedan = $this->presentarQuedan($oficina, $x);
        $this->crearPpq($oficina, $y)->assertSessionHasNoErrors();
        $lote = PpqLote::sole();

        $this->travel(5)->minutes();
        $this->actingAs($oficina)->from(route('ppq.lotes.show', $lote))
            ->post(route('ppq.lotes.items.store', $lote), ['dte_id' => $x->dte_id, 'sin_albaran' => '1'])
            ->assertSessionMissing('error');
        $this->assertSame(2, $lote->items()->count());

        $this->quitar($oficina, $lote, $x->dte_id)->assertSessionHas('status',
            fn (string $m) => str_contains($m, "no volvió a por presentar: va en la solicitud {$quedan->referencia}"));

        $x->refresh();
        $this->assertSame(EstadoPresentacionCobro::Presentada, $x->presentacion_estado);
        $this->assertSame($quedan->id, $x->cobro_solicitud_id);
        $this->assertSame(0, Activity::where('log_name', 'cobros_solicitud')->count());
    }

    /**
     * El quedan se armó en Cobros y se subió al portal, pero todavía no se registró su
     * presentación. Quitar el CCF no borra el renglón y dice qué hacer. Antes se borraba igual:
     * el CCF seguía en el quedan y, al registrar la presentación, quedaba presentado sin
     * lote del cual quitarlo. Después de registrarla, quitar sí lo suelta.
     */
    public function test_con_el_quedan_sin_presentacion_registrada_quitar_no_borra_el_renglon_y_despues_si_suelta_el_ccf(): void
    {
        $oficina = $this->usuario();
        $a = $this->ccf();
        $b = $this->ccf();
        $this->crearPpq($oficina, $a, $b)->assertSessionHasNoErrors();
        $lote = PpqLote::sole();
        $observaciones = $lote->observaciones;
        $this->travel(1)->seconds();
        $quedan = $this->generarQuedan($oficina, $a, $b);
        $this->assertSame(EstadoSolicitudCobro::Generada, $quedan->estado);
        $numeroA = $lote->items()->where('dte_id', $a->dte_id)->sole()->correlativoNumero();

        $this->quitar($oficina, $lote, $a->dte_id)->assertSessionHas('error',
            "No se quitó el CCF {$numeroA}: registrá primero la presentación del quedan {$quedan->referencia} y después quitalo.");

        $this->assertSame(2, $lote->items()->count(), 'El renglón sigue en el lote.');
        $this->assertSame($observaciones, $lote->refresh()->observaciones);
        $this->assertSame(0, Activity::where('description', 'ppq.item_quitado')->count());
        $a->refresh();
        $this->assertSame(EstadoPresentacionCobro::Preparada, $a->presentacion_estado);
        $this->assertSame($quedan->id, $a->cobro_solicitud_id);

        // Registrada la presentación, el mismo «quitar» lo saca del lote y del quedan.
        $this->registrarPresentacion($oficina, $quedan);
        $this->quitar($oficina, $lote, $a->dte_id)->assertSessionHas('status',
            fn (string $m) => str_contains($m, "salió del lote y del quedan {$quedan->referencia}, y volvió a por presentar."));
        $a->refresh();
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $a->presentacion_estado);
        $this->assertNull($a->cobro_solicitud_id);
        $this->assertSame(1, $lote->items()->count());
        $this->assertSame(EstadoPresentacionCobro::Presentada, $b->refresh()->presentacion_estado);
    }

    /**
     * El quedan se presentó desde Cobros y el reporte del caso no trae A: el cliente no lo
     * tomó. A sale del lote y también del quedan, vuelve a «por presentar», entra en otro PPQ
     * y en otro quedan, y el acuse posterior del quedan viejo no lo toca. Antes volvía a «por
     * presentar» colgado del quedan viejo: Cobros no armaba otro quedan con él y el acuse lo
     * marcaba recibido.
     */
    public function test_el_ccf_que_no_trae_el_reporte_del_caso_sale_del_quedan_presentado_y_entra_en_otro(): void
    {
        $oficina = $this->usuario();
        $a = $this->ccf();
        $b = $this->ccf();
        $this->crearPpq($oficina, $a, $b)->assertSessionHasNoErrors();
        $lote = PpqLote::sole();
        $this->travel(1)->seconds();
        $quedan = $this->presentarQuedan($oficina, $a, $b);

        $this->cargarReporte($oficina, $lote, '12040', $b)->assertSessionHas('status',
            fn (string $m) => str_contains($m, 'vuelven a por presentar: '.$a->correlativoCorto()));

        $a->refresh();
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $a->presentacion_estado);
        $this->assertNull($a->cobro_solicitud_id);
        $nota = $a->eventos()->where('referencia_linea', 'caso-12040-fuera')->sole();
        $this->assertStringContainsString("Salió del quedan presentado {$quedan->referencia}", $nota->detalle);
        $this->assertSame($quedan->id, $nota->datos['cobro_solicitud_id']);
        $bitacora = Activity::where('log_name', 'cobros_solicitud')->sole();
        $this->assertTrue($bitacora->subject->is($quedan));
        $this->assertSame($a->id, $bitacora->properties['cobro_documento_id']);
        $this->assertSame([$b->dte_id], $lote->items()->pluck('dte_id')->all());
        $this->assertSame($quedan->id, $b->refresh()->cobro_solicitud_id);

        // A entra en otro PPQ; el acuse del quedan viejo no lo toca; y va en otro quedan.
        $this->crearPpq($oficina, $a)->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->acuse($oficina, $quedan, '41020')->assertSessionHasNoErrors();
        $this->assertSame(EstadoPresentacionCobro::Preparada, $a->refresh()->presentacion_estado);
        $this->assertSame(0, $a->eventos()->where('tipo', TipoEventoCobro::Recibido->value)->count());
        $otroQuedan = $this->presentarQuedan($oficina, $a);
        $this->assertSame($otroQuedan->id, $a->refresh()->cobro_solicitud_id);
    }

    /**
     * El reporte llega antes de registrar la presentación del quedan: A no sale del lote ni
     * vuelve a «por presentar» (no podría ir en otro quedan) y el aviso dice por qué.
     * Registrada la presentación, volver a cargar el mismo reporte sí lo suelta.
     */
    public function test_con_el_quedan_sin_presentacion_registrada_el_reporte_no_suelta_el_ccf_hasta_registrarla(): void
    {
        $oficina = $this->usuario();
        $a = $this->ccf();
        $b = $this->ccf();
        $this->crearPpq($oficina, $a, $b)->assertSessionHasNoErrors();
        $lote = PpqLote::sole();
        $this->travel(1)->seconds();
        $quedan = $this->generarQuedan($oficina, $a, $b);

        $this->cargarReporte($oficina, $lote, '12041', $b)->assertSessionHas('status',
            fn (string $m) => str_contains($m, "No salieron del PPQ: {$a->correlativoCorto()} (va en el quedan {$quedan->referencia}, que todavía no tiene la presentación registrada")
                && ! str_contains($m, 'vuelven a por presentar'));
        $a->refresh();
        $this->assertSame(EstadoPresentacionCobro::Preparada, $a->presentacion_estado);
        $this->assertSame($quedan->id, $a->cobro_solicitud_id);
        $this->assertSame(0, $a->eventos()->where('referencia_linea', 'caso-12041-fuera')->count());
        $this->assertEqualsCanonicalizing([$a->dte_id, $b->dte_id], $lote->items()->pluck('dte_id')->all());

        $this->registrarPresentacion($oficina, $quedan);
        $this->cargarReporte($oficina, $lote, '12041', $b)->assertSessionHas('status',
            fn (string $m) => str_contains($m, 'vuelven a por presentar: '.$a->correlativoCorto()));
        $a->refresh();
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $a->presentacion_estado);
        $this->assertNull($a->cobro_solicitud_id);
        $this->assertSame([$b->dte_id], $lote->items()->pluck('dte_id')->all());
    }

    /**
     * Con el acuse del quedan ya registrado, el CCF que el reporte no trae no vuelve a «por
     * presentar»: no podría ir en otro quedan. Queda en el lote y en el quedan, y el aviso lo
     * dice. Qué hacer con él queda por decidir.
     */
    public function test_si_el_quedan_ya_tiene_el_acuse_el_ccf_que_no_trae_el_reporte_no_queda_por_presentar(): void
    {
        $oficina = $this->usuario();
        $a = $this->ccf();
        $b = $this->ccf();
        $this->crearPpq($oficina, $a, $b)->assertSessionHasNoErrors();
        $lote = PpqLote::sole();
        $this->travel(1)->seconds();
        $quedan = $this->presentarQuedan($oficina, $a, $b);
        $this->acuse($oficina, $quedan, '41021')->assertSessionHasNoErrors();

        $this->cargarReporte($oficina, $lote, '12042', $b)->assertSessionHas('status',
            fn (string $m) => str_contains($m, "{$a->correlativoCorto()} (va en el quedan {$quedan->referencia}, que ya tiene el acuse del cliente)"));

        $a->refresh();
        $this->assertSame(EstadoPresentacionCobro::Recibida, $a->presentacion_estado);
        $this->assertSame($quedan->id, $a->cobro_solicitud_id);
        $this->assertSame(0, $a->eventos()->where('referencia_linea', 'caso-12042-fuera')->count());
        $this->assertSame(0, Activity::where('log_name', 'cobros_solicitud')->count());
        $this->assertEqualsCanonicalizing([$a->dte_id, $b->dte_id], $lote->items()->pluck('dte_id')->all());
    }

    /**
     * El aviso de «quitar» dice que el CCF también sale del quedan presentado: no se deshace,
     * porque volver a agregarlo al lote no lo une otra vez al quedan. Antes solo preguntaba
     * «¿Quitar este documento del PPQ?». Con el acuse del cliente ya no sale, y no lo dice.
     */
    public function test_el_aviso_de_quitar_dice_que_el_ccf_tambien_sale_del_quedan_presentado(): void
    {
        $oficina = $this->usuario();
        $a = $this->ccf();
        $ncA = $this->nc($a);
        $this->crearPpq($oficina, $a)->assertSessionHasNoErrors();
        $lote = PpqLote::sole();
        $this->cargarNcAlPortal($oficina, $lote);
        $this->travel(1)->seconds();
        $quedan = $this->presentarQuedan($oficina, $a);
        $aviso = "sale del quedan {$quedan->referencia} y vuelve a por presentar. Hacelo solo si Cliente de prueba no lo acept";

        $ficha = $this->actingAs($oficina)->get(route('ppq.lotes.show', $lote))->assertOk()->assertSee($aviso, false);
        $this->assertSame(1, substr_count($ficha->getContent(), $aviso), 'Solo en el CCF, no en su NC.');
        $this->assertSame([$a->dte_id, $ncA->id], $lote->items()->orderBy('id')->pluck('dte_id')->all(), 'Mostrar el aviso no quita nada.');

        $this->acuse($oficina, $quedan, '41003')->assertSessionHasNoErrors();
        $this->actingAs($oficina)->get(route('ppq.lotes.show', $lote))->assertOk()
            ->assertSee('Quitar este documento del PPQ', false)->assertDontSee("sale del quedan {$quedan->referencia}", false);
    }

    /**
     * Las horas se guardan sin fracciones de segundo. Si el renglón y el quedan nacen en el
     * mismo segundo no se sabe cuál fue primero: ante la duda, el CCF se queda en el quedan.
     * Antes salía y podía presentarse dos veces.
     */
    public function test_si_el_renglon_y_el_quedan_nacen_en_el_mismo_segundo_el_ccf_no_sale_del_quedan(): void
    {
        $oficina = $this->usuario();
        $x = $this->ccf();
        $this->freezeSecond();
        $this->crearPpq($oficina, $x)->assertSessionHasNoErrors();
        $lote = PpqLote::sole();
        $quedan = $this->presentarQuedan($oficina, $x);
        $this->assertTrue($lote->items()->sole()->created_at->eq($quedan->created_at));

        $this->quitar($oficina, $lote, $x->dte_id)->assertSessionHas('status',
            fn (string $m) => str_contains($m, "no volvió a por presentar: va en la solicitud {$quedan->referencia}"));

        $x->refresh();
        $this->assertSame(EstadoPresentacionCobro::Presentada, $x->presentacion_estado);
        $this->assertSame($quedan->id, $x->cobro_solicitud_id);
        $this->assertSame(0, Activity::where('log_name', 'cobros_solicitud')->count());
    }

    /**
     * El quedan se armó en Cobros con los CCF del lote y todavía no tiene la presentación
     * registrada. Eliminar el lote es sacar todos sus renglones: tampoco se deja, por lo mismo
     * que el «quitar». Antes se eliminaba, los CCF seguían en el quedan y, al registrar la
     * presentación, quedaban presentados sin lote del cual quitarlos.
     */
    public function test_un_lote_cuyo_quedan_se_armo_en_cobros_sin_presentacion_registrada_no_se_elimina(): void
    {
        $oficina = $this->usuario();
        $a = $this->ccf();
        $b = $this->ccf();
        $this->crearPpq($oficina, $a, $b)->assertSessionHasNoErrors();
        $lote = PpqLote::sole();
        $this->travel(1)->seconds();
        $quedan = $this->generarQuedan($oficina, $a, $b);

        $this->actingAs($oficina)->get(route('ppq.lotes.show', $lote))->assertOk()->assertDontSee('Eliminar lote');
        $this->actingAs($oficina)->from(route('ppq.lotes.show', $lote))->delete(route('ppq.lotes.destroy', $lote))
            ->assertRedirect(route('ppq.lotes.show', $lote))
            ->assertSessionHas('error', "Este PPQ ya no se elimina: sus CCF van en el quedan {$quedan->referencia}, que se armó en Cobros.");

        $this->assertNotSoftDeleted($lote);
        $this->assertSame(2, $lote->items()->count());
        $this->assertSame(EstadoPresentacionCobro::Preparada, $a->refresh()->presentacion_estado);
        $this->assertSame($quedan->id, $a->cobro_solicitud_id);

        // Registrada la presentación, el CCF que el cliente no aceptó todavía se puede soltar.
        $this->registrarPresentacion($oficina, $quedan);
        $this->quitar($oficina, $lote, $a->dte_id)->assertSessionHas('status',
            fn (string $m) => str_contains($m, "salió del lote y del quedan {$quedan->referencia}, y volvió a por presentar."));
    }

    /**
     * X va en un quedan armado directo en Cobros, sin la presentación registrada, y alguien lo
     * agregó por error a otro lote. Ese quedan no salió de este lote: quitar X nunca lo suelta,
     * así que no hay que esperar a registrar la presentación. Antes el «quitar» pedía
     * registrarla primero y el renglón equivocado no se podía sacar.
     */
    public function test_un_ccf_agregado_por_error_despues_de_armar_su_quedan_se_quita_sin_registrar_la_presentacion(): void
    {
        $oficina = $this->usuario();
        $x = $this->ccf();
        $y = $this->ccf();
        $quedan = $this->generarQuedan($oficina, $x);
        $this->crearPpq($oficina, $y)->assertSessionHasNoErrors();
        $lote = PpqLote::sole();
        $this->travel(5)->minutes();
        $this->actingAs($oficina)->from(route('ppq.lotes.show', $lote))
            ->post(route('ppq.lotes.items.store', $lote), ['dte_id' => $x->dte_id, 'sin_albaran' => '1'])
            ->assertSessionMissing('error');

        $this->quitar($oficina, $lote, $x->dte_id)->assertSessionHas('status',
            fn (string $m) => str_contains($m, "no volvió a por presentar: va en la solicitud {$quedan->referencia}"));

        $this->assertSame([$y->dte_id], $lote->items()->pluck('dte_id')->all());
        $x->refresh();
        $this->assertSame(EstadoPresentacionCobro::Preparada, $x->presentacion_estado);
        $this->assertSame($quedan->id, $x->cobro_solicitud_id);
        $this->assertSame(0, Activity::where('log_name', 'cobros_solicitud')->count());
    }
}
