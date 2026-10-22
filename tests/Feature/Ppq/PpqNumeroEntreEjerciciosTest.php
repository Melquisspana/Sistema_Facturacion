<?php

namespace Tests\Feature\Ppq;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\PpqSala;
use App\Models\User;
use App\Services\Cobros\ElegibilidadPpqSeguimiento;
use App\Services\Ppq\ActualizarPpqConTxt;
use App\Services\Ppq\ArchivoConciliacion;
use App\Services\Ppq\ConciliacionTxtParser;
use App\Services\Ppq\EstadoRealLotePpq;
use App\Support\IdentidadPpq;
use App\Support\Sala;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * #121 · En PPQ un CCF de 2026 y otro de 2027 pueden compartir número de control (el
 * correlativo vuelve a 1 cada ejercicio, #42). La identidad del renglón pasa a ser número +
 * ejercicio: los dos caben en el mismo lote, y nada de lo que se sabe de uno —que ya está
 * en un PPQ, que se cobró— se le atribuye al otro.
 */
class PpqNumeroEntreEjerciciosTest extends TestCase
{
    use RefreshDatabase;

    private const NUMERO = 'DTE-03-M001P002-000000000000015';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sala::olvidarCache();
        PpqSala::olvidarCache();
        $this->seed(DatosInicialesNegritaSeeder::class);
        Carbon::setTestNow('2027-02-10 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function calleja(): Cliente
    {
        return Cliente::where('nombre', 'like', '%Calleja%')->firstOrFail();
    }

    private function lote(string $referencia = 'PPQ'): PpqLote
    {
        return PpqLote::create(['cliente_id' => $this->calleja()->id, 'referencia' => $referencia, 'fecha' => now(), 'estado' => 'borrador']);
    }

    private function item(PpqLote $lote, ?string $fecha, array $extra = []): PpqItem
    {
        return PpqItem::create($extra + [
            'ppq_lote_id' => $lote->id,
            'origen' => 'gmail',
            'numero_control' => self::NUMERO,
            'tipo_dte' => '03',
            'fecha_documento' => $fecha,
            'monto_dte' => 100,
            'sin_albaran' => true,
        ]);
    }

    private function documento(?string $fecha, array $extra = []): CobroDocumento
    {
        return CobroDocumento::create($extra + [
            'cliente_id' => $this->calleja()->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => '03',
            'numero_control' => self::NUMERO,
            'fecha_emision' => $fecha,
            'monto' => '100.00',
        ]);
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole('facturacion');
    }

    private function agregarGmail(PpqLote $lote, array $datos)
    {
        return $this->actingAs($this->usuario())
            ->from(route('ppq.index'))
            ->post(route('ppq.lotes.items.store', $lote), $datos + [
                'origen' => 'gmail',
                'numero_control' => self::NUMERO,
                'tipo_dte' => '03',
                'monto_dte' => '100.00',
                'sin_albaran' => '1',
            ]);
    }

    // ------------------------------------------------------------------ identidad del renglón

    public function test_el_mismo_numero_de_dos_ejercicios_cabe_en_el_mismo_lote(): void
    {
        $lote = $this->lote();
        $this->assertSame(2026, $this->item($lote, '2026-12-20')->ejercicio_control);
        $this->assertSame(2027, $this->item($lote, '2027-01-15')->ejercicio_control);

        $this->expectException(QueryException::class);
        $this->item($lote, '2027-01-20');
    }

    public function test_sin_fecha_propia_el_renglon_toma_el_ejercicio_del_dte(): void
    {
        $dte = Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'cliente_id' => $this->calleja()->id,
            'numero_control' => self::NUMERO,
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2027SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2027-01-15', 'hora_emision' => '08:00:00', 'total_pagar' => 100,
        ]);

        $item = PpqItem::create(['ppq_lote_id' => $this->lote()->id, 'dte_id' => $dte->id, 'origen' => 'local',
            'tipo_dte' => '03', 'monto_dte' => 100, 'sin_albaran' => true]);

        $this->assertSame(2027, $item->ejercicio_control);
    }

    public function test_por_gmail_se_agrega_el_mismo_numero_de_otro_anio_pero_no_dos_veces_el_mismo(): void
    {
        $lote = $this->lote();
        $this->item($lote, '2026-12-20');

        $this->agregarGmail($lote, ['fecha_documento' => '2027-01-15'])->assertSessionHas('status');
        $this->assertSame(2, PpqItem::where('ppq_lote_id', $lote->id)->count());

        $this->agregarGmail($lote, ['fecha_documento' => '2026-12-20'])
            ->assertSessionHas('error', fn ($m) => str_contains((string) $m, 'ya está en este lote'));
        $this->assertSame(2, PpqItem::where('ppq_lote_id', $lote->id)->count());
    }

    public function test_por_gmail_sin_fecha_un_numero_que_ya_existe_en_otro_anio_pide_la_fecha(): void
    {
        $this->item($this->lote('PPQ dic'), '2026-12-20');
        $lote = $this->lote('PPQ feb');

        $this->agregarGmail($lote, [])->assertSessionHasErrors('fecha_documento');
        $this->assertSame(0, PpqItem::where('ppq_lote_id', $lote->id)->count());
    }

    public function test_el_doc_local_por_numero_respeta_el_ejercicio(): void
    {
        Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'cliente_id' => $this->calleja()->id,
            'numero_control' => self::NUMERO,
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-12-20', 'hora_emision' => '08:00:00', 'total_pagar' => 100,
        ]);

        $this->assertNotNull(IdentidadPpq::dteLocal(self::NUMERO, null, 2026));
        $this->assertNull(IdentidadPpq::dteLocal(self::NUMERO, null, 2027), 'El de 2026 no responde por el de 2027.');
    }

    public function test_sin_ejercicio_el_doc_local_no_elige_entre_dos_anios(): void
    {
        if (! Schema::hasColumn('dtes', 'ejercicio')) {
            $this->markTestSkipped('Necesita #125: hasta entonces `dtes` no admite el mismo número en dos ejercicios.');
        }
        foreach (['2026-12-20', '2027-01-15'] as $fecha) {
            Dte::create([
                'establecimiento_id' => Establecimiento::firstOrFail()->id,
                'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
                'cliente_id' => $this->calleja()->id,
                'numero_control' => self::NUMERO,
                'ejercicio' => (int) substr($fecha, 0, 4),
                'codigo_generacion' => strtoupper(Str::uuid()->toString()),
                'sello_recepcion' => 'SELLOREAL'.Str::random(8),
                'fecha_procesamiento_mh' => now(),
                'fecha_emision' => $fecha, 'hora_emision' => '08:00:00', 'total_pagar' => 100,
            ]);
        }

        $this->assertNull(IdentidadPpq::dteLocal(self::NUMERO));
        $this->assertSame('2027-01-15', IdentidadPpq::dteLocal(self::NUMERO, null, 2027)?->fecha_emision?->toDateString());
    }

    // ------------------------------------------------------------------ cruces con el seguimiento

    public function test_estar_en_un_ppq_el_de_2026_no_bloquea_al_de_2027(): void
    {
        $this->item($this->lote(), '2026-12-20');
        $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0017/00/5131', 'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2027-01-16', 'monto_albaran' => '100.00', 'numero_orden_compra' => '27010017003463', 'sala_codigo' => '0017']);
        $de2026 = $this->documento('2026-12-20', ['ppq_albaran_id' => $albaran->id]);
        $de2027 = $this->documento('2027-01-15', ['ppq_albaran_id' => $albaran->id]);

        $elegibilidad = app(ElegibilidadPpqSeguimiento::class);
        $claves = $elegibilidad->claves();

        $this->assertSame('ya está en un PPQ', $elegibilidad->motivo($de2026, $claves));
        $this->assertNull($elegibilidad->motivo($de2027, $claves));
        $this->assertSame([$de2027->id], $elegibilidad->listos(CobroDocumento::query(), $claves)->keys()->all());
    }

    public function test_el_estado_real_del_lote_lee_el_documento_de_su_anio(): void
    {
        $lote = $this->lote();
        $this->item($lote, '2027-01-15');
        $de2026 = $this->documento('2026-12-20');
        $de2026->forceFill(['pago_estado' => EstadoPagoCobro::Pagado->value, 'monto_pagado' => '100.00'])->saveQuietly();
        $this->documento('2027-01-15');

        $estado = app(EstadoRealLotePpq::class)->calcular(collect([$lote]))->get($lote->id);

        $this->assertSame(1, $estado['ccf_total']);
        $this->assertSame(0, $estado['sin_seguimiento'], 'Hay un seguimiento de su año: no es ambiguo.');
        $this->assertSame(0, $estado['pagados'], 'El pagado es el de 2026.');
    }

    public function test_el_txt_concilia_solo_el_renglon_del_anio_de_su_fecha(): void
    {
        $de2026 = $this->item($this->lote('PPQ dic'), '2026-12-20');
        $de2027 = $this->item($this->lote('PPQ feb'), '2027-01-15');
        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            ."000123;TITULAR DE EJEMPLO;CF;DTE03M001P002000000000000015;15-ENE-27;100.00\n";

        app(ActualizarPpqConTxt::class)->aplicar(app(ConciliacionTxtParser::class)->parse($txt), ArchivoConciliacion::desdeContenido($txt, 'pagos.txt'));

        $this->assertTrue($de2027->refresh()->estaConciliado());
        $this->assertFalse($de2026->refresh()->estaConciliado());
    }
}
