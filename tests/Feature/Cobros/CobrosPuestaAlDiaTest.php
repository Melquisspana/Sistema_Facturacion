<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Enums\TipoNotaCredito;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\PpqConciliacion;
use App\Models\User;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\Dte\PerfilDocumentoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Puesta al día, una sola vez: se suben juntos todos los TXT de pago desde que empezó el
 * seguimiento y sale el estado de cobro de cada CCF y NC. Idempotente: un TXT ya aplicado
 * no duplica pagos. «Solo revisar» no deja rastro: ni base, ni archivos, ni colas, ni correos.
 */
class CobrosPuestaAlDiaTest extends TestCase
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
        config(['cobros.inicio_seguimiento' => '2026-07-01']);

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

    private function ccf(string $fecha = '2026-09-10'): CobroDocumento
    {
        $dte = $this->dte('03', ['fecha_emision' => $fecha,
            'numero_orden_compra' => '2609001700'.str_pad((string) ($this->n + 1), 4, '0', STR_PAD_LEFT)]);
        $doc = CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id,
            'tipo_dte' => '03', 'numero_control' => $dte->numero_control, 'fecha_emision' => $fecha, 'monto' => '100.00',
        ]);
        $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0017/00/'.(5000 + $doc->id), 'tipo_codigo' => 'AC01',
            'fecha_albaran' => $fecha, 'monto_albaran' => '100.00', 'sala_codigo' => '0017',
            'numero_orden_compra' => '2609001700'.str_pad((string) $doc->id, 4, '0', STR_PAD_LEFT)]);
        $doc->forceFill(['ppq_albaran_id' => $albaran->id, 'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value])->save();

        return $doc;
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole(RolSistema::Administrador->value);
    }

    private function linea(string $tipo, string $control, string $valor): string
    {
        return '000123;TITULAR DE EJEMPLO;'.$tipo.';'.str_replace('-', '', $control).';10-SEP-26;'.$valor."\n";
    }

    private function txt(array $lineas): string
    {
        return "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n".implode('', $lineas);
    }

    /**
     * Dos PPQ y sus dos TXT. A, B y C se pagan; D no vino; n1 (NC de D) no se descontó; la NC
     * de pronto pago no se presenta. E es de antes del inicio del seguimiento: no se informa.
     */
    private function escenario(): array
    {
        [$a, $b, $c, $d] = [$this->ccf(), $this->ccf(), $this->ccf(), $this->ccf()];
        $e = $this->ccf('2026-06-15');
        $n1 = $this->dte('05', ['dte_relacionado_id' => $d->dte_id, 'total_pagar' => '3.39',
            'tipo_nota_credito' => TipoNotaCredito::DevolucionProducto->value]);
        $pronto = $this->dte('05', ['dte_relacionado_id' => $a->dte_id, 'total_pagar' => '1.50',
            'tipo_nota_credito' => TipoNotaCredito::ProntoPago->value]);
        app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$a->id, $b->id]);
        app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$c->id, $d->id]);

        // A se cobra neto de su NC aceptada de 1.50.
        $txt1 = $this->txt([$this->linea('CF', $a->numero_control, '98.50'), $this->linea('CF', $b->numero_control, '100.00')]);
        $txt2 = $this->txt([$this->linea('CF', $c->numero_control, '100.00')]);

        return compact('a', 'b', 'c', 'd', 'e', 'n1', 'pronto', 'txt1', 'txt2');
    }

    private function subirVarios(array $archivos, bool $soloRevisar, ?User $usuario = null)
    {
        $subidas = [];
        foreach ($archivos as $nombre => $contenido) {
            $subidas[] = UploadedFile::fake()->createWithContent($nombre, $contenido);
        }

        return $this->actingAs($usuario ?? $this->usuario())->post(route('cobros.pagos.varios', $this->cliente),
            ['archivos' => $subidas] + ($soloRevisar ? ['solo_revisar' => '1'] : []));
    }

    public function test_solo_revisar_muestra_el_resultado_y_no_deja_rastro(): void
    {
        $e = $this->escenario();
        Queue::fake();
        Mail::fake();
        $disco = Storage::disk((string) config('dte.storage.disk', 'local'));
        $antes = $disco->allFiles();

        $respuesta = $this->subirVarios(['pagos-1.txt' => $e['txt1'], 'pagos-2.txt' => $e['txt2']], soloRevisar: true)->assertOk();

        $respuesta->assertSeeText('Solo revisión: no se guardó nada');
        $this->assertCount(2, $respuesta->viewData('archivos'));
        $estados = collect($respuesta->viewData('reporte')['ccf'])->mapWithKeys(fn ($f) => [$f['documento']->id => $f['estado']]);
        $this->assertSame('pagado', $estados[$e['a']->id]);
        $this->assertSame('no_pagado', $estados[$e['d']->id]);

        $this->assertSame(0, CobroEvento::where('tipo', TipoEventoCobro::Pago->value)->count());
        $this->assertSame(0, PpqConciliacion::count());
        $this->assertSame(EstadoPagoCobro::Pendiente, $e['a']->refresh()->pago_estado);
        $this->assertSame($antes, $disco->allFiles());
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_aplica_varios_txt_sin_duplicar_y_da_el_estado_de_cada_documento(): void
    {
        $e = $this->escenario();
        $usuario = $this->usuario();

        // El primero va repetido: un TXT ya aplicado no cuenta dos veces.
        $respuesta = $this->subirVarios(['pagos-1.txt' => $e['txt1'], 'pagos-2.txt' => $e['txt2'], 'copia.txt' => $e['txt1']],
            soloRevisar: false, usuario: $usuario)->assertOk();

        foreach (['a', 'b', 'c'] as $clave) {
            $this->assertSame(EstadoPagoCobro::Pagado, $e[$clave]->refresh()->pago_estado);
            $this->assertSame(1, $e[$clave]->eventos()->where('tipo', TipoEventoCobro::Pago->value)->count());
        }
        $this->assertSame(EstadoPagoCobro::Pendiente, $e['d']->refresh()->pago_estado);

        $reporte = $respuesta->viewData('reporte');
        $ccf = collect($reporte['ccf'])->mapWithKeys(fn ($f) => [$f['documento']->id => $f['estado']]);
        $this->assertSame(['pagado', 'pagado', 'pagado', 'no_pagado'],
            [$ccf[$e['a']->id], $ccf[$e['b']->id], $ccf[$e['c']->id], $ccf[$e['d']->id]]);
        $this->assertArrayNotHasKey($e['e']->id, $ccf->all(), 'Lo emitido antes del inicio del seguimiento no se informa.');
        $nc = collect($reporte['nc'])->mapWithKeys(fn ($f) => [$f['nc']->id => $f['estado']]);
        $this->assertSame('no_descontada', $nc[$e['n1']->id]);
        $this->assertSame('pronto_pago', $nc[$e['pronto']->id]);

        $respuesta->assertSeeText('pagos-1.txt')->assertSeeText('copia.txt')
            ->assertSeeText('No pagados')->assertSee($e['d']->numero_control)
            ->assertSee(route('cobros.estado-cobro', $this->cliente), false);
    }

    public function test_un_archivo_que_falla_no_frena_a_los_demas(): void
    {
        $e = $this->escenario();
        $ajeno = str_replace('000123;', '999999;', $e['txt2']);

        $respuesta = $this->subirVarios(['pagos-1.txt' => $e['txt1'], 'ajeno.txt' => $ajeno], soloRevisar: false)->assertOk();

        $archivos = collect($respuesta->viewData('archivos'))->keyBy('nombre');
        $this->assertSame('aplicado', $archivos['pagos-1.txt']['estado']);
        $this->assertSame('error', $archivos['ajeno.txt']['estado']);
        $this->assertNotEmpty($archivos['ajeno.txt']['mensaje']);
        $this->assertSame(EstadoPagoCobro::Pagado, $e['a']->refresh()->pago_estado);
        $this->assertSame(EstadoPagoCobro::Pendiente, $e['c']->refresh()->pago_estado);
    }

    public function test_el_excel_trae_ccf_y_nc_desde_el_inicio_del_seguimiento(): void
    {
        $e = $this->escenario();
        $usuario = $this->usuario();
        $this->subirVarios(['pagos-1.txt' => $e['txt1'], 'pagos-2.txt' => $e['txt2']], soloRevisar: false, usuario: $usuario)->assertOk();

        $respuesta = $this->actingAs($usuario)->get(route('cobros.estado-cobro', $this->cliente))->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $respuesta->headers->get('content-type'));

        $libro = IOFactory::load($respuesta->baseResponse->getFile()->getPathname());
        $ccf = json_encode($libro->getSheetByName('CCF')->toArray());
        $nc = json_encode($libro->getSheetByName('NC')->toArray());
        foreach (['a', 'b', 'c', 'd'] as $clave) {
            $this->assertStringContainsString($e[$clave]->numero_control, $ccf);
        }
        $this->assertStringNotContainsString($e['e']->numero_control, $ccf);
        $this->assertStringContainsString('No pagado', $ccf);
        $this->assertStringContainsString($e['n1']->numero_control, $nc);
    }

    public function test_valida_y_exige_permisos(): void
    {
        $e = $this->escenario();
        $lector = User::factory()->create();
        $lector->givePermissionTo('ppq.ver');

        $this->subirVarios(['pagos-1.txt' => $e['txt1']], soloRevisar: false, usuario: $lector)->assertForbidden();
        $this->actingAs($lector)->get(route('cobros.estado-cobro', $this->cliente))->assertOk();
        $this->actingAs($this->usuario())->post(route('cobros.pagos.varios', $this->cliente), ['archivos' => []])
            ->assertSessionHasErrors('archivos');
        $this->assertSame(0, CobroEvento::count());
    }
}
