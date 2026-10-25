<?php

namespace Tests\Feature\Ppq;

use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Enums\TipoNotaCredito;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\NcPosterioresLotePpq;
use App\Services\Ppq\QuedanCallejaExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Las NC de PRONTO PAGO (las que nacen del ajuste QD del TXT) no van en el quedan ni en
 * el PPQ: Calleja ya las descontó al pagar. Regla del usuario (25/10/2026).
 */
class NcProntoPagoFueraDelQuedanTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private Cliente $cliente;

    private $estab = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();
        Storage::fake((string) config('dte.storage.disk', 'local'));

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
        ClientePerfilDocumento::create(['cliente_id' => $this->cliente->id, 'activo' => true, 'codigo_proveedor' => '000123',
            'formato_export' => 'carga_masiva_nc_v1', 'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0]);
        app(PerfilDocumentoResolver::class)->olvidar();
    }

    private function dte(string $tipo, array $extra = []): Dte
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $this->n++;

        return Dte::create($extra + [
            'establecimiento_id' => $this->estab->id, 'tipo_dte' => $tipo, 'estado' => 'aceptado', 'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'numero_control' => 'DTE-'.$tipo.'-M001P002-'.str_pad((string) $this->n, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8), 'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-09-10', 'hora_emision' => '08:00:00', 'total_pagar' => '100.00',
        ]);
    }

    private function prontoPago(CobroDocumento $doc, array $extra = []): Dte
    {
        return $this->dte('05', $extra + ['dte_relacionado_id' => $doc->dte_id, 'total_pagar' => '2.50',
            'tipo_nota_credito' => TipoNotaCredito::ProntoPago->value]);
    }

    private function devolucion(CobroDocumento $doc, array $extra = []): Dte
    {
        return $this->dte('05', $extra + ['dte_relacionado_id' => $doc->dte_id, 'total_pagar' => '4.75',
            'tipo_nota_credito' => TipoNotaCredito::DevolucionProducto->value]);
    }

    private function ccf(): CobroDocumento
    {
        $dte = $this->dte('03', ['numero_orden_compra' => '2609001700'.str_pad((string) ($this->n + 1), 4, '0', STR_PAD_LEFT)]);
        $doc = CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id,
            'tipo_dte' => '03', 'numero_control' => $dte->numero_control, 'fecha_emision' => '2026-09-10', 'monto' => '100.00',
        ]);
        $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0017/00/'.(5000 + $doc->id), 'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-09-05', 'monto_albaran' => '100.00', 'sala_codigo' => '0017',
            'numero_orden_compra' => '2609001700'.str_pad((string) $doc->id, 4, '0', STR_PAD_LEFT)]);
        $doc->forceFill(['ppq_albaran_id' => $albaran->id, 'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value])->save();

        return $doc;
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole(RolSistema::Administrador->value);
    }

    /** CCF en un lote ya presentado, con sus NC aceptadas DESPUÉS del armado. */
    private function presentadoConNotas(): array
    {
        $doc = $this->ccf();
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$doc->id]);
        $doc->update(['presentacion_estado' => 'recibida']);
        $this->travel(1)->minutes();

        return [$doc, $lote, $this->prontoPago($doc), $this->devolucion($doc)];
    }

    public function test_la_nc_de_pronto_pago_no_es_posterior_ni_se_recomienda_en_cobros(): void
    {
        [, , $pronto, $devolucion] = $this->presentadoConNotas();

        $sueltas = app(NcPosterioresLotePpq::class)->sueltasDelCliente($this->cliente)->pluck('id')->all();
        $this->assertSame([$devolucion->id], $sueltas);

        $this->actingAs($this->usuario())->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->assertOk()->assertSeeText('Se agregarán también 1 NC posteriores a una presentación (−$4.75)')
            ->assertDontSee($pronto->numero_control);

        $nuevo = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$this->ccf()->id]);
        $this->assertSame(0, $nuevo->items()->where('dte_id', $pronto->id)->count());
        $this->assertSame(1, $nuevo->items()->where('dte_id', $devolucion->id)->count());
    }

    public function test_al_armar_el_ppq_no_entra_la_nc_de_pronto_pago_del_ccf_marcado(): void
    {
        $doc = $this->ccf();
        $pronto = $this->prontoPago($doc);
        $devolucion = $this->devolucion($doc);

        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$doc->id]);

        $this->assertSame(0, $lote->items()->where('dte_id', $pronto->id)->count());
        $this->assertSame(1, $lote->items()->where('dte_id', $devolucion->id)->count());
    }

    public function test_un_lote_editable_no_suma_la_nc_de_pronto_pago_ni_al_aceptarse_ni_a_mano(): void
    {
        $doc = $this->ccf();
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$doc->id]);

        $pronto = $this->prontoPago($doc, ['estado' => 'borrador']);
        DB::transaction(fn () => $pronto->update(['estado' => 'aceptado']));
        $this->assertSame(0, $lote->items()->where('dte_id', $pronto->id)->count());

        $servicio = app(NcPosterioresLotePpq::class);
        $this->assertCount(0, $servicio->notas($lote));
        $this->assertSame(0, $servicio->agregar($lote));
        $this->actingAs($this->usuario())->get(route('ppq.lotes.show', $lote))->assertOk()
            ->assertDontSeeText('Agregar NC nuevas');
    }

    public function test_el_quedan_salta_la_nc_de_pronto_pago_que_ya_estaba_en_el_lote(): void
    {
        $doc = $this->ccf();
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$doc->id]);
        // Lote armado antes de la regla: la NC sin albarán quedó adentro.
        $pronto = $this->prontoPago($doc);
        $lote->items()->create(NcPosterioresLotePpq::datosItemNc($pronto));

        $ruta = app(QuedanCallejaExporter::class)->generar($lote->fresh());
        try {
            $filas = array_values(array_filter(IOFactory::load($ruta)->getActiveSheet()->toArray(),
                fn ($fila) => array_filter($fila, fn ($v) => $v !== null && $v !== '') !== []));
            $this->assertCount(2, $filas);   // encabezados + el CCF
            $this->assertSame('AC01', $filas[1][4]);
        } finally {
            @unlink($ruta);
        }
    }

    public function test_la_previa_del_quedan_en_cobros_no_cuenta_la_nc_de_pronto_pago(): void
    {
        $doc = $this->ccf();
        $pronto = $this->prontoPago($doc);

        $respuesta = $this->actingAs($this->usuario())
            ->post(route('cobros.solicitudes.previa', $this->cliente), ['documentos' => [$doc->id]])->assertOk();

        $respuesta->assertDontSee($pronto->numero_control);
        $notas = $respuesta->viewData('notas');
        $this->assertSame([], $notas['por_exportar']);
        $this->assertSame([], $notas['bloqueadas']);
        $this->assertSame([], $respuesta->viewData('bloqueos'));
    }

    public function test_el_comando_quita_las_de_pronto_pago_de_los_lotes_y_conserva_las_conciliadas(): void
    {
        $doc = $this->ccf();
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$doc->id]);
        $lote->update(['observaciones' => 'Conservar.']);
        $pronto = $this->prontoPago($doc);
        $conciliada = $this->prontoPago($doc);
        $devolucion = $this->devolucion($doc);
        $lote->items()->create(NcPosterioresLotePpq::datosItemNc($pronto));
        $lote->items()->create(NcPosterioresLotePpq::datosItemNc($conciliada) + ['conciliacion_estado' => 'aplicada']);
        $lote->items()->create(NcPosterioresLotePpq::datosItemNc($devolucion));
        // Revertida: ya no está conciliada, pero tiene historia de conciliación y se conserva.
        $conHistoria = $this->prontoPago($doc);
        $itemHistoria = $lote->items()->create(NcPosterioresLotePpq::datosItemNc($conHistoria));
        $corrida = DB::table('ppq_conciliaciones')->insertGetId(['ppq_lote_id' => $lote->id, 'created_at' => now()]);
        DB::table('ppq_conciliacion_movimientos')->insert(['ppq_conciliacion_id' => $corrida, 'ppq_item_id' => $itemHistoria->id,
            'estado_anterior' => 'aplicada', 'estado_nuevo' => null, 'created_at' => now()]);
        $borrado = PpqLote::create(['referencia' => 'Borrado', 'fecha' => today(), 'estado' => 'borrador']);
        $borrado->items()->create(NcPosterioresLotePpq::datosItemNc($pronto));
        $borrado->delete();

        $this->artisan('ppq:quitar-nc-pronto-pago', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(1, $lote->items()->where('dte_id', $pronto->id)->count());
        $this->assertSame(0, Activity::where('description', 'ppq.nc_pronto_pago_quitada')->count());

        $this->artisan('ppq:quitar-nc-pronto-pago')->assertSuccessful();

        $this->assertSame(0, $lote->items()->where('dte_id', $pronto->id)->count());
        $this->assertSame(1, $lote->items()->where('dte_id', $conciliada->id)->count());
        $this->assertSame(1, $lote->items()->where('dte_id', $conHistoria->id)->count());
        $this->assertSame(1, $lote->items()->where('dte_id', $devolucion->id)->count());
        $this->assertSame(1, $lote->items()->where('dte_id', $doc->dte_id)->count());
        $this->assertSame(1, $borrado->items()->where('dte_id', $pronto->id)->count());
        $observaciones = $lote->refresh()->observaciones;
        $this->assertStringContainsString('Conservar.', $observaciones);
        $this->assertStringContainsString($pronto->numero_control, $observaciones);
        $this->assertStringNotContainsString($conciliada->numero_control, $observaciones);
        $actividad = Activity::where('description', 'ppq.nc_pronto_pago_quitada')->sole();
        $this->assertSame($lote->id, $actividad->subject_id);
        $this->assertSame($pronto->id, $actividad->properties['dte_id']);

        // Idempotente: la segunda corrida no cambia nada.
        $this->artisan('ppq:quitar-nc-pronto-pago')->assertSuccessful();
        $this->assertSame(1, Activity::where('description', 'ppq.nc_pronto_pago_quitada')->count());
        $this->assertSame($observaciones, $lote->refresh()->observaciones);
    }
}
