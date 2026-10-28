<?php

namespace Tests\Feature\Cobros;

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
use App\Services\Cobros\EstadoCobroDesdeInicio;
use App\Services\Dte\PerfilDocumentoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\Concerns\RevisaYAplicaTxt;
use Tests\TestCase;

/**
 * Lo que ya no se cobra no se mezcla con lo «No pagado»: va aparte, en «No aplican», con su
 * motivo, y no se ofrece para volver a presentar. Pedido del usuario (25/10/2026): «esos ya
 * no sirven, o sea sí pero no».
 *
 *  · CCF invalidado ante Hacienda y sin cobro;
 *  · CCF que su NC deja en saldo 0 (anulado con nota);
 *  · CCF del ambiente de pruebas cuando se opera en producción;
 *  · NC de un CCF que no aplica.
 */
class CobrosNoAplicanTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;
    use RevisaYAplicaTxt;

    private Cliente $cliente;

    private $estab = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('dte.storage.disk', 'local'));
        config(['cobros.inicio_seguimiento' => '2026-07-01', 'dte.ambiente' => '01']);

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

    private function ccf(array $extraDte = []): CobroDocumento
    {
        $dte = $this->dte('03', $extraDte + ['numero_orden_compra' => '2609001700'.str_pad((string) ($this->n + 1), 4, '0', STR_PAD_LEFT)]);
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

    private function nc(CobroDocumento $ccf, string $total): Dte
    {
        return $this->dte('05', ['dte_relacionado_id' => $ccf->dte_id, 'total_pagar' => $total,
            'tipo_nota_credito' => TipoNotaCredito::DevolucionProducto->value]);
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole(RolSistema::Administrador->value);
    }

    /**
     * Un PPQ presentado con: V (pagado por el TXT), W (no vino, se cobra), X (después invalidado,
     * con su NC m), Y (anulado con su NC n por el total). Z es del ambiente de pruebas.
     */
    private function escenario(): array
    {
        [$v, $w, $x, $y] = [$this->ccf(), $this->ccf(), $this->ccf(), $this->ccf()];
        $m = $this->nc($x, '10.00');
        $n = $this->nc($y, '100.00');
        $z = $this->ccf(['ambiente' => '00']);
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$v->id, $w->id, $x->id, $y->id]);
        foreach ([$v, $w, $x, $y] as $doc) {
            $doc->refresh()->update(['presentacion_estado' => 'recibida']);
        }
        $x->dte->update(['estado' => 'invalidado', 'sello_invalidacion' => 'SELLOINV'.Str::random(8)]);

        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            .'000123;TITULAR DE EJEMPLO;CF;'.str_replace('-', '', $v->numero_control).";10-SEP-26;100.00\n";

        return compact('v', 'w', 'x', 'y', 'z', 'm', 'n', 'lote', 'txt');
    }

    public function test_el_estado_de_cobro_separa_lo_que_no_aplica_con_su_motivo(): void
    {
        $e = $this->escenario();

        $reporte = app(EstadoCobroDesdeInicio::class)->generar($this->cliente);

        $ccf = collect($reporte['ccf'])->mapWithKeys(fn ($f) => [$f['documento']->id => $f['estado']]);
        $this->assertSame('no_pagado', $ccf[$e['w']->id]);
        foreach (['x', 'y', 'z'] as $clave) {
            $this->assertArrayNotHasKey($e[$clave]->id, $ccf->all(), "{$clave} no se mezcla con lo que se cobra.");
        }
        $nc = collect($reporte['nc'])->pluck('nc.id')->all();
        $this->assertNotContains($e['m']->id, $nc);
        $this->assertNotContains($e['n']->id, $nc);

        $noAplican = collect($reporte['no_aplican'])->mapWithKeys(fn ($f) => [$f['numero'] => $f['motivo']]);
        $this->assertStringContainsString('nvalidad', $noAplican[$e['x']->numero_control]);
        $this->assertStringContainsString('nota de crédito', $noAplican[$e['y']->numero_control]);
        $this->assertStringContainsString('pruebas', $noAplican[$e['z']->numero_control]);
        $this->assertArrayHasKey($e['m']->numero_control, $noAplican->all());
        $this->assertArrayHasKey($e['n']->numero_control, $noAplican->all());
        $this->assertSame(5, $reporte['totales']['no_aplican']);
    }

    public function test_un_ccf_invalidado_que_si_tiene_cobro_sigue_a_la_vista(): void
    {
        $doc = $this->ccf();
        $doc->dte->update(['estado' => 'invalidado', 'sello_invalidacion' => 'SELLOINV'.Str::random(8)]);
        $doc->forceFill(['monto_pagado' => '100.00', 'pago_estado' => 'pagado'])->save();

        $reporte = app(EstadoCobroDesdeInicio::class)->generar($this->cliente);

        // Cobrado e invalidado es algo que hay que mirar, no algo que «no aplica».
        $this->assertContains($doc->id, collect($reporte['ccf'])->pluck('documento.id')->all());
        $this->assertNotContains($doc->numero_control, collect($reporte['no_aplican'])->pluck('numero')->all());
    }

    public function test_la_pantalla_y_el_excel_los_muestran_aparte(): void
    {
        $e = $this->escenario();
        $usuario = $this->usuario();

        $respuesta = $this->actingAs($usuario)->post(route('cobros.pagos.revisar', $this->cliente), [
            'archivos' => [UploadedFile::fake()->createWithContent('pagos.txt', $e['txt'])],
        ])->assertOk();
        $respuesta->assertSeeText('No aplican')->assertSee($e['x']->numero_control)->assertSeeText('Invalidado');

        $excel = $this->actingAs($usuario)->get(route('cobros.estado-cobro', $this->cliente))->assertOk();
        $libro = IOFactory::load($excel->baseResponse->getFile()->getPathname());
        $ccf = json_encode($libro->getSheetByName('CCF')->toArray());
        $noAplican = json_encode($libro->getSheetByName('No aplican')->toArray(), JSON_UNESCAPED_UNICODE);
        foreach (['x', 'y', 'z'] as $clave) {
            $this->assertStringNotContainsString($e[$clave]->numero_control, $ccf);
            $this->assertStringContainsString($e[$clave]->numero_control, $noAplican);
        }
        $this->assertStringContainsString($e['w']->numero_control, $ccf);
        $this->assertStringContainsString($e['n']->numero_control, $noAplican);
    }

    public function test_el_txt_no_los_ofrece_para_volver_a_presentar(): void
    {
        $e = $this->escenario();
        $usuario = $this->usuario();

        $respuesta = $this->actingAs($usuario)->revisarYAplicarTxt($this->cliente, [
            'archivo' => UploadedFile::fake()->createWithContent('pagos.txt', $e['txt']),
        ])->assertOk();

        $pendientes = $respuesta->viewData('archivos')[0]['pendientes'];
        $item = fn (int $dteId) => $e['lote']->items()->where('dte_id', $dteId)->sole()->id;
        $this->assertSame([$item($e['w']->dte_id)], collect($pendientes['no_pagados'])->pluck('item.id')->all());
        $this->assertSame([], $pendientes['nc_no_descontadas']);
        $this->assertEqualsCanonicalizing(
            [$item($e['x']->dte_id), $item($e['y']->dte_id), $item($e['m']->id), $item($e['n']->id)],
            collect($pendientes['no_aplican'])->pluck('item.id')->all());
        $respuesta->assertSeeText('No aplican');
        // Sin casilla: no se pueden marcar.
        $this->assertDoesNotMatchRegularExpression('/name="items\[\]" value="'.$item($e['x']->dte_id).'"/', $respuesta->getContent());

        $hash = $respuesta->viewData('archivos')[0]['informe']['hash'];
        $this->actingAs($usuario)->post(route('cobros.pagos.reenviar', $this->cliente), ['hash' => $hash, 'items' => [$item($e['x']->dte_id)]])
            ->assertSessionHasErrors('items');
        $this->assertSame(1, PpqLote::sole()->items()->where('dte_id', $e['x']->dte_id)->count());
    }
}
