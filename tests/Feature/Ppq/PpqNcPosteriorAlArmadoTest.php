<?php

namespace Tests\Feature\Ppq;

use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\EstadoDte;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\NcExportacion;
use App\Models\NcExportacionItem;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\EstadoRealLotePpq;
use App\Services\Ppq\ExcelCallejaExporter;
use App\Services\Ppq\NcPosterioresLotePpq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/** NC posteriores al armado: incorporación automática y recuperación manual. */
class PpqNcPosteriorAlArmadoTest extends TestCase
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

    private function ccf(bool $entregado = true): CobroDocumento
    {
        // La OC trae la sala (0017) aunque el albarán todavía no haya llegado.
        $dte = $this->dte('03', ['numero_orden_compra' => '2609001700'.str_pad((string) ($this->n + 1), 4, '0', STR_PAD_LEFT)]);
        $doc = CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id,
            'tipo_dte' => '03', 'numero_control' => $dte->numero_control, 'fecha_emision' => '2026-09-10', 'monto' => '100.00',
        ]);
        if ($entregado) {
            $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0017/00/'.(5000 + $doc->id), 'tipo_codigo' => 'AC01',
                'fecha_albaran' => '2026-09-05', 'monto_albaran' => '98.95', 'sala_codigo' => '0017',
                'numero_orden_compra' => '2609001700'.str_pad((string) $doc->id, 4, '0', STR_PAD_LEFT)]);
            $doc->forceFill(['ppq_albaran_id' => $albaran->id, 'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value])->save();
        }

        return $doc;
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole(RolSistema::Administrador->value);
    }

    private function armar(): array
    {
        $doc = $this->ccf();
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$doc->id]);

        return [$doc, $lote];
    }

    private function posterior(): array
    {
        [$doc, $lote] = $this->armar();
        $doc->update(['presentacion_estado' => 'recibida']);
        $this->travel(1)->minutes();   // la NC se acepta DESPUÉS de armado el lote
        $nc = $this->dte('05', ['dte_relacionado_id' => $doc->dte_id, 'total_pagar' => '4.75']);

        return [$doc, $lote, $nc];
    }

    /** Historia vieja: una NC aceptada antes de armar el lote, o ya enviada en un archivo de NC, no se barre. */
    public function test_nc_anterior_al_armado_o_ya_exportada_no_es_suelta(): void
    {
        [$doc] = $this->armar();
        $doc->update(['presentacion_estado' => 'recibida']);
        $vieja = $this->dte('05', ['dte_relacionado_id' => $doc->dte_id, 'total_pagar' => '3.00',
            'fecha_procesamiento_mh' => now()->subDay()]);
        $this->travel(1)->minutes();
        $exportada = $this->dte('05', ['dte_relacionado_id' => $doc->dte_id, 'total_pagar' => '2.00']);
        $exportacion = NcExportacion::create(['cliente_id' => $this->cliente->id, 'referencia' => 'NC-FICTICIA-1',
            'formato' => 'carga_masiva_nc_v1', 'archivo_nombre' => 'ficticio.xlsx']);
        NcExportacionItem::create(['nc_exportacion_id' => $exportacion->id, 'dte_id' => $exportada->id, 'orden' => 1]);
        $nueva = $this->dte('05', ['dte_relacionado_id' => $doc->dte_id, 'total_pagar' => '1.00']);

        $sueltas = app(NcPosterioresLotePpq::class)->sueltasDelCliente($this->cliente)->pluck('id')->all();

        $this->assertSame([$nueva->id], $sueltas);
        $this->assertNotContains($vieja->id, $sueltas);
    }

    public function test_nc_posterior_viaja_en_el_siguiente_lote_y_no_en_un_tercero(): void
    {
        [$doc, $anterior, $nc] = $this->posterior();
        $nuevoDoc = $this->ccf();
        $this->actingAs($this->usuario())->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->assertOk()->assertSeeText('Se agregarán también 1 NC posteriores a una presentación (−$4.75)')
            ->assertSee($nc->numero_control)->assertSee('ncPosteriores: 1', false)->assertSee('montoNcPosteriores: 4.75', false);
        $respuesta = $this->post(route('cobros.ppq.crear', $this->cliente), ['documentos' => [$nuevoDoc->id]]);
        $nuevo = PpqLote::latest('id')->firstOrFail();
        $respuesta->assertSessionHas('status', fn ($mensaje) => str_contains($mensaje, 'Incluye 1 NC posteriores a una presentación ($4.75).'));
        $this->assertSame(1, $nuevo->items()->where('dte_id', $nc->id)->count());
        $this->assertSame(0, $nuevo->items()->where('dte_id', $doc->dte_id)->count());
        $this->get(route('ppq.lotes.show', $nuevo))->assertOk()->assertSeeText('NC posterior');
        $terceroDoc = $this->ccf();
        $tercero = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$terceroDoc->id]);
        $this->assertSame(0, $tercero->items()->where('dte_id', $nc->id)->count());
    }

    public function test_nc_posterior_ocupada_no_se_repite_por_id_o_control(): void
    {
        [$doc, $anterior, $nc] = $this->posterior();
        $ocupado = PpqLote::create(['referencia' => 'Ocupado', 'fecha' => today(), 'estado' => 'borrador']);
        $ocupado->items()->create(NcPosterioresLotePpq::datosItemNc($nc));
        $servicio = app(NcPosterioresLotePpq::class);
        $this->assertCount(0, $servicio->sueltasDelCliente($this->cliente));
        $ocupado->items()->update(['dte_id' => null, 'numero_control' => str_replace('-', '', $nc->numero_control)]);
        $nuevoDoc = $this->ccf();
        $nuevo = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$nuevoDoc->id]);
        $this->assertSame(0, $nuevo->items()->where('dte_id', $nc->id)->count());
    }

    public function test_nc_posterior_de_otro_cliente_no_entra(): void
    {
        [$doc, $anterior, $nc] = $this->posterior();
        $otro = Cliente::factory()->contribuyente()->create();
        $ajena = $this->dte('05', ['cliente_id' => $otro->id, 'dte_relacionado_id' => $doc->dte_id]);
        $nuevoDoc = $this->ccf();
        $nuevo = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$nuevoDoc->id]);
        $this->assertSame(0, $nuevo->items()->where('dte_id', $ajena->id)->count());
        $this->assertSame(1, $nuevo->items()->where('dte_id', $nc->id)->count());
    }

    public function test_nc_del_armado_o_sin_lote_no_es_suelta(): void
    {
        [$doc, $armado] = $this->armar();
        $nc = $this->dte('05', ['dte_relacionado_id' => $doc->dte_id]);
        $sinLote = $this->ccf();
        $otraNc = $this->dte('05', ['dte_relacionado_id' => $sinLote->dte_id]);
        $nuevoDoc = $this->ccf();
        $nuevo = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$nuevoDoc->id]);
        $this->assertSame(0, $nuevo->items()->whereIn('dte_id', [$nc->id, $otraNc->id])->count());
        $this->assertCount(0, app(NcPosterioresLotePpq::class)->sueltasDelCliente($this->cliente));
        $this->assertSame(1, app(NcPosterioresLotePpq::class)->agregar($armado));
    }

    public function test_nc_posterior_sin_ccf_en_lote_sale_en_excel_y_archivo_nc(): void
    {
        [$doc, $anterior, $nc] = $this->posterior();
        DteAlbaran::create(['dte_id' => $nc->id, 'numero_canonico' => 'AC04/0017/00/3874',
            'tipo_codigo' => 'AC04', 'sala_codigo' => '0017', 'numero' => '3874', 'fecha' => '2026-09-01', 'total' => '4.75']);
        $nuevoDoc = $this->ccf();
        $nuevo = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$nuevoDoc->id]);
        $ruta = app(ExcelCallejaExporter::class)->generar($nuevo->fresh());
        try {
            $hoja = IOFactory::load($ruta)->getActiveSheet();
            $this->assertSame($nc->numero_control, $hoja->getCell('F3')->getValue());
            $this->assertEquals(-4.75, $hoja->getCell('G3')->getValue());
        } finally {
            @unlink($ruta);
        }
        $respuesta = $this->actingAs($this->usuario())->post(route('ppq.lotes.archivo-nc', $nuevo))->assertOk();
        $archivo = $respuesta->baseResponse->getFile()->getPathname();
        $filas = IOFactory::load($archivo)->getActiveSheet()->toArray();
        $this->assertStringContainsString($nc->codigo_generacion, json_encode($filas));
        $this->assertCount(2, $filas);
        $this->assertSame([$nc->id], NcExportacionItem::pluck('dte_id')->all());
    }

    public function test_aceptacion_agrega_una_vez_y_reduce_total_del_armado(): void
    {
        [$doc, $lote] = $this->armar();
        $nc = $this->dte('05', ['estado' => 'borrador', 'dte_relacionado_id' => $doc->dte_id, 'total_pagar' => '4.75']);
        DB::transaction(fn () => $nc->update(['estado' => 'aceptado']));
        $this->assertSame(1, $lote->items()->where('dte_id', $nc->id)->count());
        app(NcPosterioresLotePpq::class)->alAceptarse($nc);
        $this->assertSame(1, $lote->items()->where('dte_id', $nc->id)->count());
        $real = app(EstadoRealLotePpq::class)->calcular(collect([$lote->fresh()]))[$lote->id];
        $this->assertSame('armado', $real['estado']['key']);
        $this->assertSame(95.25, $real['total_neto']);
    }

    public function test_presentado_no_agrega_y_ficha_informa(): void
    {
        [$doc, $lote] = $this->armar();
        $doc->update(['presentacion_estado' => 'recibida']);
        $nc = $this->dte('05', ['estado' => 'borrador', 'dte_relacionado_id' => $doc->dte_id]);
        DB::transaction(fn () => $nc->update(['estado' => 'aceptado']));
        $this->assertSame(1, $lote->items()->count());
        $this->actingAs($this->usuario())->get(route('ppq.lotes.show', $lote))->assertOk()
            ->assertSeeText('NC posterior a la presentación')->assertSeeText($nc->numero_control)
            ->assertDontSeeText('Agregar NC nuevas');
        $this->post(route('ppq.lotes.agregar-nc', $lote))
            ->assertSessionHas('error', 'Este PPQ ya se presentó: las NC nuevas no se agregan.');
    }

    public function test_nc_preexistente_se_agrega_por_post_sin_duplicarse(): void
    {
        [$doc, $lote] = $this->armar();
        $nc = $this->dte('05', ['dte_relacionado_id' => $doc->dte_id]);
        $this->actingAs($this->usuario())->get(route('ppq.lotes.show', $lote))->assertOk()->assertSeeText('Agregar NC nuevas (1)');
        $this->post(route('ppq.lotes.agregar-nc', $lote))->assertRedirect(route('ppq.lotes.show', $lote))
            ->assertSessionHas('status', 'Se agregaron 1 NC al PPQ.');
        $this->post(route('ppq.lotes.agregar-nc', $lote))->assertSessionHas('status', 'Se agregaron 0 NC al PPQ.');
        $this->assertSame(1, $lote->items()->where('dte_id', $nc->id)->count());
    }

    public function test_nc_ocupada_o_invalidada_no_se_agrega_y_borrado_libera(): void
    {
        [$doc, $lote] = $this->armar();
        $nc = $this->dte('05', ['dte_relacionado_id' => $doc->dte_id]);
        $this->dte('05', ['dte_relacionado_id' => $doc->dte_id, 'estado' => 'invalidado']);
        $otro = PpqLote::create(['referencia' => 'Otro', 'fecha' => today(), 'estado' => 'borrador']);
        $otro->items()->create(NcPosterioresLotePpq::datosItemNc($nc));
        $servicio = app(NcPosterioresLotePpq::class);
        $this->assertSame(0, $servicio->agregar($lote));
        $otro->items()->update(['dte_id' => null, 'numero_control' => str_replace('-', '', $nc->numero_control)]);
        $this->assertSame(0, $servicio->agregar($lote));
        $otro->delete();
        $this->assertSame(1, $servicio->agregar($lote));
    }

    public function test_error_automatico_no_revierte_aceptacion(): void
    {
        [$doc, $lote] = $this->armar();
        $this->mock(NcPosterioresLotePpq::class, function ($mock) {
            $mock->shouldReceive('alAceptarse')->once()->andThrow(new \RuntimeException('Fallo simulado'));
        });
        $nc = $this->dte('05', ['estado' => 'borrador', 'dte_relacionado_id' => $doc->dte_id]);
        DB::transaction(fn () => $nc->update(['estado' => 'aceptado']));
        $this->assertSame(EstadoDte::Aceptado, $nc->refresh()->estado);
        $this->assertSame(1, $lote->items()->count());
    }

    public function test_excel_incluye_nc_agregada(): void
    {
        [$doc, $lote] = $this->armar();
        $nc = $this->dte('05', ['dte_relacionado_id' => $doc->dte_id, 'total_pagar' => '4.75']);
        app(NcPosterioresLotePpq::class)->agregar($lote);
        $ruta = app(ExcelCallejaExporter::class)->generar($lote->fresh());
        try {
            $hoja = IOFactory::load($ruta)->getActiveSheet();
            $filas = $hoja->toArray();
            $this->assertSame($nc->numero_control, $hoja->getCell('F3')->getValue());
            $this->assertEquals(-4.75, $hoja->getCell('G3')->getValue());
            $this->assertCount(3, $filas);
        } finally {
            @unlink($ruta);
        }
    }

    public function test_post_sin_permiso_devuelve_403(): void
    {
        [$doc, $lote] = $this->armar();
        $usuario = User::factory()->create();
        $usuario->givePermissionTo('ppq.ver');
        $this->actingAs($usuario)->post(route('ppq.lotes.agregar-nc', $lote))->assertForbidden();
    }
}
