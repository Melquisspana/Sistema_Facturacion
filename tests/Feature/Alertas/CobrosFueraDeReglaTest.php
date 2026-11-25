<?php

namespace Tests\Feature\Alertas;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\Cobros\TipoEventoCobro;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Services\Alertas\Avisador;
use App\Services\Alertas\Detectores\CobrosFueraDeRegla;
use App\Services\Alertas\Hallazgo;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\Cobros\ElegibilidadPpqSeguimiento;
use App\Services\Dte\PerfilDocumentoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/** CCF que quedaron fuera de la regla de PPQ. Datos inventados. */
class CobrosFueraDeReglaTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private Cliente $cliente;

    private $estab = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-19 10:00', 'America/El_Salvador'));
        Storage::fake((string) config('dte.storage.disk', 'local'));
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente Secreto PPQ']);
        ClientePerfilDocumento::create(['cliente_id' => $this->cliente->id, 'activo' => true, 'codigo_proveedor' => '000123',
            'formato_export' => 'carga_masiva_nc_v1', 'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0]);
        app(PerfilDocumentoResolver::class)->olvidar();
    }

    private function ccf(): CobroDocumento
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $this->n++;
        $dte = Dte::create([
            'establecimiento_id' => $this->estab->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) (200 + $this->n), 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8), 'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-11-10', 'hora_emision' => '08:00:00', 'total_pagar' => '100.00',
        ]);
        $doc = CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id,
            'tipo_dte' => '03', 'numero_control' => $dte->numero_control, 'fecha_emision' => '2026-11-10', 'monto' => '100.00',
        ]);
        $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0017/00/'.(5000 + $doc->id), 'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-11-05', 'monto_albaran' => '98.95', 'sala_codigo' => '0017',
            'numero_orden_compra' => '2611001700'.str_pad((string) $doc->id, 4, '0', STR_PAD_LEFT)]);
        $doc->forceFill(['ppq_albaran_id' => $albaran->id, 'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value])->save();

        return $doc;
    }

    private function lote(array $docs): PpqLote
    {
        return app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, array_map(fn ($d) => $d->id, $docs));
    }

    /** Otro lote vivo con el mismo renglón: el caso de un CCF metido dos veces. */
    private function copiarEnOtroLote(PpqLote $lote): PpqLote
    {
        $otro = PpqLote::create(['referencia' => 'PPQ inventado '.Str::random(4), 'fecha' => now()->toDateString(),
            'estado' => $lote->estado, 'cliente_id' => $this->cliente->id]);
        $lote->items()->each(fn ($item) => $item->replicate()->fill(['ppq_lote_id' => $otro->id])->save());

        return $otro;
    }

    private function revisar(): ?Hallazgo
    {
        Cache::flush();

        return app(CobrosFueraDeRegla::class)->revisar();
    }

    private function assertSinDatosDelCliente(Hallazgo $h): void
    {
        $texto = $h->titulo.$h->detalle.$h->queHacer;
        $this->assertStringNotContainsString('Secreto', $texto);
        $this->assertStringNotContainsString('$', $texto);
    }

    public function test_sin_nada_fuera_de_regla_no_avisa_y_esta_en_el_avisador(): void
    {
        $doc = $this->ccf();
        $this->lote([$doc]);
        $this->travel(11)->minutes();

        $detector = app(CobrosFueraDeRegla::class);
        $this->assertTrue($detector->aplica());
        $this->assertSame('cobros_fuera_de_regla', $detector->tipo());
        $this->assertNull($this->revisar(), 'Una preparada en un lote vivo está bien.');
        $this->assertContains('cobros_fuera_de_regla', array_map(fn ($d) => $d->tipo(), app(Avisador::class)->detectores()));
    }

    public function test_una_preparada_cuyo_lote_se_borro_avisa_pasados_10_minutos(): void
    {
        $doc = $this->ccf();
        $this->lote([$doc])->delete();
        $this->travel(5)->minutes();
        $this->assertNull($this->revisar(), 'Recién tocada: puede estar entrando a un PPQ.');

        $this->travel(6)->minutes();
        $h = $this->revisar();

        $this->assertSame(1, $h->conteo);
        $this->assertStringContainsString('201', $h->detalle);
        $this->assertStringContainsString('Entregados, por presentar', $h->queHacer);
        $this->assertSame('cobros.index', $h->enlace);
        $this->assertSinDatosDelCliente($h);
    }

    public function test_una_preparada_sin_lote_con_solicitud_o_invalidada_no_cuenta_por_esta_regla(): void
    {
        $conSolicitud = $this->ccf();
        $conSolicitud->forceFill(['presentacion_estado' => EstadoPresentacionCobro::Preparada->value])->save();
        $conSolicitud->forceFill(['cobro_solicitud_id' => CobroSolicitud::create([
            'cliente_id' => $this->cliente->id, 'referencia' => 'SOL-FICTICIA-01', 'formato' => 'prueba', 'archivo_nombre' => 'ficticio.xlsx',
        ])->id])->save();
        $this->travel(11)->minutes();

        $this->assertNull($this->revisar());
    }

    public function test_un_parcial_devuelto_por_el_txt_que_quedo_preparado_sin_lote_avisa(): void
    {
        $doc = $this->ccf();
        $doc->forceFill(['presentacion_estado' => EstadoPresentacionCobro::Preparada->value, 'pago_estado' => EstadoPagoCobro::Parcial->value])->saveQuietly();
        CobroEvento::create(['cobro_documento_id' => $doc->id, 'tipo' => TipoEventoCobro::Nota->value, 'origen' => 'manual',
            'referencia_linea' => 'txt-1-fuera', 'fecha' => today()]);
        $this->travel(11)->minutes();

        $this->assertSame(1, $this->revisar()?->conteo);
    }

    public function test_el_mismo_ccf_en_dos_ppq_vivos_avisa_con_los_dos_numeros(): void
    {
        $doc = $this->ccf();
        $lote = $this->lote([$doc]);
        $otro = $this->copiarEnOtroLote($lote);
        $this->travel(11)->minutes();

        $h = $this->revisar();

        $this->assertStringContainsString('CCF 201: PPQ #'.$lote->id.' y #'.$otro->id, $h->detalle);
        $this->assertStringContainsString('quitá ese CCF', $h->queHacer);
        $this->assertSinDatosDelCliente($h);

        // Con los dos renglones conciliados ya no hay nada que hacer.
        $lote->items()->update(['conciliacion_estado' => 'pagado']);
        $otro->items()->update(['conciliacion_estado' => 'pagado']);
        $this->assertNull($this->revisar());
    }

    public function test_un_renglon_liberado_por_caso_no_cuenta_como_doble(): void
    {
        $doc = $this->ccf();
        $lote = $this->lote([$doc]);
        $this->travel(1)->minutes();
        CobroEvento::create(['cobro_documento_id' => $doc->id, 'tipo' => TipoEventoCobro::Nota->value, 'origen' => 'manual',
            'referencia_linea' => 'caso-9-fuera', 'fecha' => today(), 'datos' => ['ppq_lote_id' => $lote->id]]);
        $this->travel(1)->minutes();
        $doc->refresh()->forceFill(['presentacion_estado' => EstadoPresentacionCobro::SinPresentar->value])->save();
        $this->lote([$doc]);
        $this->travel(11)->minutes();

        $this->assertNull($this->revisar());
    }

    public function test_un_invalidado_reciente_que_sigue_en_cobro_avisa_y_uno_viejo_no(): void
    {
        $doc = $this->ccf();
        $this->lote([$doc]);
        $doc->refresh()->forceFill(['presentacion_estado' => EstadoPresentacionCobro::Presentada->value])->save();
        $doc->dte->forceFill(['estado' => 'invalidado', 'fecha_invalidacion' => now()->subDays(10)])->save();
        $this->travel(11)->minutes();

        $h = $this->revisar();
        $this->assertStringContainsString('201', $h->detalle);
        $this->assertStringContainsString('avisale al cliente que no lo pague', $h->queHacer);

        $doc->dte->forceFill(['fecha_invalidacion' => now()->subDays(40)])->save();
        $this->assertNull($this->revisar());
    }

    public function test_el_resultado_se_guarda_una_hora(): void
    {
        $doc = $this->ccf();
        $this->lote([$doc])->delete();
        $this->travel(11)->minutes();
        $detector = app(CobrosFueraDeRegla::class);
        $this->assertNotNull($detector->revisar());

        $doc->refresh()->forceFill(['presentacion_estado' => EstadoPresentacionCobro::SinPresentar->value])->save();
        $this->assertNotNull($detector->revisar(), 'Dentro de la hora sigue el resultado guardado.');
        $this->travel(61)->minutes();
        $this->assertNull($detector->revisar());
    }

    public function test_claves_da_lo_mismo_con_los_renglones_vigentes(): void
    {
        $uno = $this->ccf();
        $dos = $this->ccf();
        $lote = $this->lote([$uno, $dos]);
        $elegibilidad = app(ElegibilidadPpqSeguimiento::class);

        $vigentes = $elegibilidad->itemsVigentes();

        $this->assertSame($lote->items()->pluck('id')->sort()->values()->all(), $vigentes->pluck('id')->sort()->values()->all());
        $this->assertTrue($vigentes->every(fn ($i) => array_key_exists('conciliacion_estado', $i->getAttributes())));
        $this->assertSame([], $elegibilidad->claves($lote->id)->all());
        $this->assertCount(4, $elegibilidad->claves());
    }
}
