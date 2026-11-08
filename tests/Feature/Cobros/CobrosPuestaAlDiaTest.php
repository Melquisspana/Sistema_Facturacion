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
use App\Models\Cobros\CobroAjuste;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\PpqConciliacion;
use App\Models\User;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\Cobros\EstadoCobroDesdeInicio;
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
 * no duplica pagos. Revisar conserva la copia privada, sin escribir pagos ni despachar colas o correos.
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

        // A se cobra por su total: su NC de pronto pago (1.50) es el QD, no se resta del CCF.
        $txt1 = $this->txt([$this->linea('CF', $a->numero_control, '100.00'), $this->linea('CF', $b->numero_control, '100.00')]);
        $txt2 = $this->txt([$this->linea('CF', $c->numero_control, '100.00')]);

        return compact('a', 'b', 'c', 'd', 'e', 'n1', 'pronto', 'txt1', 'txt2');
    }

    private function subirVarios(array $archivos, bool $soloRevisar, ?User $usuario = null)
    {
        $subidas = [];
        foreach ($archivos as $nombre => $contenido) {
            $subidas[] = UploadedFile::fake()->createWithContent($nombre, $contenido);
        }

        $revision = $this->actingAs($usuario ?? $this->usuario())->post(route('cobros.pagos.revisar', $this->cliente), ['archivos' => $subidas]);
        if ($soloRevisar || $revision->status() !== 200) {
            return $revision;
        }
        $hashes = collect($revision->viewData('archivos'))->where('estado', 'aplicado')->pluck('hash')->unique()->values()->all();
        $respuesta = $this->post(route('cobros.pagos.aplicar', $this->cliente), ['hashes' => $hashes]);

        return $respuesta;
    }

    public function test_solo_revisar_muestra_el_resultado_y_no_deja_rastro(): void
    {
        $e = $this->escenario();
        Queue::fake();
        Mail::fake();
        $disco = Storage::disk((string) config('dte.storage.disk', 'local'));
        $antes = $disco->allFiles();

        $respuesta = $this->subirVarios(['pagos-1.txt' => $e['txt1'], 'pagos-2.txt' => $e['txt2']], soloRevisar: true)->assertOk();

        $respuesta->assertSeeText('Revisión: todavía no se aplicó nada');
        $archivos = collect($respuesta->viewData('archivos'))->keyBy('nombre');
        $this->assertCount(2, $archivos);
        // La revisión muestra el resultado simulado de cada PPQ: el primero queda pagado y del
        // segundo D no vino, en solo lectura.
        $this->assertTrue($archivos['pagos-1.txt']['lotes'][0]['pagado']);
        $this->assertSame([$e['d']->dte_id], collect($archivos['pagos-2.txt']['pendientes']['no_pagados'])->pluck('item.dte_id')->all());
        $respuesta->assertSeeText('Este TXT paga el PPQ')->assertSeeText('No vinieron pagados')->assertSee($e['d']->numero_control)
            ->assertDontSeeText('Dejar listos para el próximo PPQ');

        $this->assertSame(0, CobroEvento::where('tipo', TipoEventoCobro::Pago->value)->count());
        $this->assertSame(0, PpqConciliacion::count());
        $this->assertSame(EstadoPagoCobro::Pendiente, $e['a']->refresh()->pago_estado);
        $this->assertCount(count($antes) + 2, $disco->allFiles());
        $disco->assertExists('ppq/conciliaciones/'.hash('sha256', $e['txt1']).'.txt');
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

        $reporte = app(EstadoCobroDesdeInicio::class)->generar($this->cliente);
        $ccf = collect($reporte['ccf'])->mapWithKeys(fn ($f) => [$f['documento']->id => $f['estado']]);
        $this->assertSame(['pagado', 'pagado', 'pagado', 'no_pagado'],
            [$ccf[$e['a']->id], $ccf[$e['b']->id], $ccf[$e['c']->id], $ccf[$e['d']->id]]);
        $this->assertArrayNotHasKey($e['e']->id, $ccf->all(), 'Lo emitido antes del inicio del seguimiento no se informa.');
        $nc = collect($reporte['nc'])->mapWithKeys(fn ($f) => [$f['nc']->id => $f['estado']]);
        $this->assertSame('no_descontada', $nc[$e['n1']->id]);
        $this->assertSame('pronto_pago', $nc[$e['pronto']->id]);

        // La pantalla ya no repite el estado de todo el historial: muestra lo de cada PPQ y
        // deja el estado completo en el Excel.
        $respuesta->assertSeeText('copia.txt')->assertSeeText('Este TXT paga el PPQ')
            ->assertSeeText('No vinieron pagados')->assertSee($e['d']->numero_control)
            ->assertDontSeeText('Estado de cobro de')->assertDontSeeText('Archivos procesados')
            ->assertSee(route('cobros.estado-cobro', $this->cliente), false);
    }

    public function test_un_archivo_que_falla_no_frena_a_los_demas(): void
    {
        $e = $this->escenario();
        $ajeno = str_replace('000123;', '999999;', $e['txt2']);

        $respuesta = $this->subirVarios(['pagos-1.txt' => $e['txt1'], 'ajeno.txt' => $ajeno], soloRevisar: true)->assertOk();

        $archivos = collect($respuesta->viewData('archivos'))->keyBy('nombre');
        $this->assertSame('aplicado', $archivos['pagos-1.txt']['estado']);
        $this->assertSame('error', $archivos['ajeno.txt']['estado']);
        $this->assertNotEmpty($archivos['ajeno.txt']['mensaje']);
        $this->post(route('cobros.pagos.aplicar', $this->cliente), ['hashes' => [hash('sha256', $e['txt1'])]])->assertOk();
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
        $this->actingAs($this->usuario())->post(route('cobros.pagos.revisar', $this->cliente), ['archivos' => []])
            ->assertSessionHasErrors('archivos');
        $this->assertSame(0, CobroEvento::count());
    }

    public function test_fecha_de_pago_se_conserva_en_sesion_y_solo_se_aplica_al_confirmar(): void
    {
        $doc = $this->ccf();
        $txt = $this->txt([$this->linea('CF', $doc->numero_control, '100.00')]);
        $hash = hash('sha256', $txt);
        $revision = $this->actingAs($this->usuario())->post(route('cobros.pagos.revisar', $this->cliente), [
            'archivos' => [UploadedFile::fake()->createWithContent('pago.txt', $txt)], 'fecha_pago' => '2026-10-20',
        ])->assertOk()->assertSessionHas('cobros.txt_revisados.'.$this->cliente->id.'.'.$hash.'.fecha_pago', '2026-10-20');
        $this->assertSame('2026-10-20', $revision->viewData('archivos')[0]['informe']['fecha_pago']->toDateString());
        $this->assertSame(0, CobroEvento::count());
        $this->post(route('cobros.pagos.aplicar', $this->cliente), ['hashes' => [$hash], 'fecha_pago' => '2000-01-01'])->assertOk();
        $this->assertSame('2026-10-20', $doc->eventos()->where('tipo', TipoEventoCobro::Pago->value)->sole()->fecha->toDateString());
        $this->assertSame('2026-10-20', $doc->refresh()->fecha_pago->toDateString());
    }

    public function test_fecha_con_varios_archivos_se_rechaza_antes_de_guardar_copias(): void
    {
        $doc = $this->ccf();
        $txt = $this->txt([$this->linea('CF', $doc->numero_control, '100.00')]);
        $this->actingAs($this->usuario())->post(route('cobros.pagos.revisar', $this->cliente), [
            'archivos' => [UploadedFile::fake()->createWithContent('a.txt', $txt), UploadedFile::fake()->createWithContent('b.txt', $txt)],
            'fecha_pago' => '2026-10-20',
        ])->assertSessionHasErrors('fecha_pago');
        $this->assertSame([], Storage::disk((string) config('dte.storage.disk', 'local'))->allFiles());
        $this->assertSame(0, CobroEvento::count());
    }

    public function test_huella_exige_revision_del_mismo_cliente_y_se_consume_una_sola_vez(): void
    {
        $doc = $this->ccf();
        app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$doc->id]);
        $txt = $this->txt([$this->linea('CF', $doc->numero_control, '100.00')]);
        $hash = hash('sha256', $txt);
        $this->actingAs($this->usuario())->post(route('cobros.pagos.aplicar', $this->cliente), ['hashes' => [$hash]])
            ->assertOk()->assertSeeText('Este TXT no se revisó en esta sesión');
        $this->assertSame(0, CobroEvento::count());
        $this->subirVarios(['a.txt' => $txt], soloRevisar: true)->assertOk();
        $otro = Cliente::factory()->contribuyente()->create();
        ClientePerfilDocumento::create(['cliente_id' => $otro->id, 'activo' => true, 'codigo_proveedor' => '000123',
            'formato_export' => 'carga_masiva_nc_v1', 'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0]);
        app(PerfilDocumentoResolver::class)->olvidar();
        $this->post(route('cobros.pagos.aplicar', $otro), ['hashes' => [$hash]])->assertOk()->assertSeeText('Este TXT no se revisó en esta sesión');
        $this->assertSame(0, CobroEvento::count());
        $this->post(route('cobros.pagos.aplicar', $this->cliente), ['hashes' => [$hash]])->assertOk();
        $this->post(route('cobros.pagos.aplicar', $this->cliente), ['hashes' => [$hash]])->assertOk()->assertSeeText('Este TXT no se revisó en esta sesión');
        $this->assertSame(1, $doc->eventos()->where('tipo', TipoEventoCobro::Pago->value)->count());
        $this->assertSame(1, PpqConciliacion::count());
    }

    public function test_revision_nueva_reemplaza_las_huellas_y_los_errores_no_se_autorizan(): void
    {
        $doc = $this->ccf();
        $txt = $this->txt([$this->linea('CF', $doc->numero_control, '100.00')]);
        $this->subirVarios(['a.txt' => $txt], soloRevisar: true)->assertOk();
        $ajeno = str_replace('000123;', '999999;', $txt);
        $this->subirVarios(['ajeno.txt' => $ajeno], soloRevisar: true)->assertOk()
            ->assertSessionHas('cobros.txt_revisados.'.$this->cliente->id, []);
        foreach ([$txt, $ajeno] as $contenido) {
            $this->post(route('cobros.pagos.aplicar', $this->cliente), ['hashes' => [hash('sha256', $contenido)]])
                ->assertOk()->assertSeeText('Este TXT no se revisó en esta sesión');
        }
        $this->assertSame(0, CobroEvento::count());
    }

    public function test_cliente_sin_perfil_activo_no_revisa_ni_aplica(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $this->actingAs($this->usuario())->post(route('cobros.pagos.revisar', $cliente), [
            'archivos' => [UploadedFile::fake()->createWithContent('a.txt', 'contenido')],
        ])->assertSessionHasErrors('documentos');
        $this->post(route('cobros.pagos.aplicar', $cliente), ['hashes' => [str_repeat('a', 64)]])->assertSessionHasErrors('documentos');
        $this->assertSame(0, CobroEvento::count());
    }

    public function test_detalle_en_revision_muestra_datos_sin_acciones_de_ajustes(): void
    {
        $doc = $this->ccf();
        $primero = $this->txt([$this->linea('CF', $doc->numero_control, '50.00')]);
        $this->subirVarios(['primero.txt' => $primero], soloRevisar: false)->assertOk();
        $segundo = $this->txt([$this->linea('CF', $doc->numero_control, '40.00'), $this->linea('QD', 'PPQ/33762', '-1.50')]);
        $revision = $this->subirVarios(['segundo.txt' => $segundo], soloRevisar: true)->assertOk();
        $revision->assertSeeText('segundo.txt')->assertSeeText('EN REVISIÓN')->assertSeeText('PPQ/33762')
            ->assertDontSeeText('Vincular NC existente')->assertDontSeeText('Crear nota de crédito');
        $this->assertSame(0, CobroAjuste::count());
        $this->assertSame(1, $doc->eventos()->where('tipo', TipoEventoCobro::Pago->value)->count());
        $this->post(route('cobros.pagos.aplicar', $this->cliente), ['hashes' => [hash('sha256', $segundo)]])
            ->assertOk()->assertSeeText('EN REVISIÓN')->assertSeeText('Vincular NC existente')->assertSeeText('Crear nota de crédito');
    }

    public function test_el_txt_con_qd_muestra_el_ppq_que_paga_y_la_nota_de_credito_al_aplicar(): void
    {
        $doc = $this->ccf();
        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$doc->id]);
        $txt = $this->txt([$this->linea('CF', $doc->numero_control, '100.00'), $this->linea('QD', 'PPQ/33762', '-1.50')]);

        // Al revisar: el PPQ que paga y el ajuste, con «Hacer nota de crédito» sin aplicar.
        $this->subirVarios(['quedan.txt' => $txt], soloRevisar: true)->assertOk()
            ->assertSeeText('Este TXT paga el PPQ '.$lote->referencia)
            ->assertSeeText('Ajuste de pronto pago (QD)')->assertSeeText('PPQ/33762')
            ->assertSeeText('Hacer nota de crédito')->assertSee(route('cobros.pagos.ajuste-nc', $this->cliente), false)
            ->assertDontSeeText('Crear nota de crédito')->assertDontSeeText('Estado de cobro de');

        // Al aplicar: el botón de la nota queda a la vista, sin abrir ningún detalle.
        $aplicado = $this->post(route('cobros.pagos.aplicar', $this->cliente), ['hashes' => [hash('sha256', $txt)]])->assertOk()
            ->assertSeeText('Este TXT paga el PPQ '.$lote->referencia.' (queda pagado)')
            ->assertSeeText('Crear nota de crédito')->assertSeeText('Vincular NC existente')
            ->assertDontSeeText('Hacer nota de crédito')
            ->assertSeeText('Todo el PPQ vino pagado como se presentó.');
        $ajuste = CobroAjuste::sole();
        $this->assertStringNotContainsString('<details', explode('Crear nota de crédito', $aplicado->getContent())[0]);
        $aplicado->assertSee(route('facturacion.create-nota-credito', ['cobro_ajuste' => $ajuste->id]), false);
    }

    public function test_la_nota_del_pronto_pago_se_hace_desde_la_revision_sin_aplicar_y_aplicar_despues_la_encuentra(): void
    {
        $doc = $this->ccf();
        $txt = $this->txt([$this->linea('CF', $doc->numero_control, '100.00'), $this->linea('QD', 'PPQ/33762', '-1.50')]);
        $hash = hash('sha256', $txt);
        $this->subirVarios(['quedan.txt' => $txt], soloRevisar: true)->assertOk();
        $this->assertSame(0, CobroAjuste::count(), 'Revisar no deja el ajuste.');

        // «Hacer nota de crédito»: registra SOLO el ajuste de esa línea y abre la nota.
        $ajuste = null;
        $this->post(route('cobros.pagos.ajuste-nc', $this->cliente), ['hash' => $hash, 'linea' => 3])
            ->assertRedirect(route('facturacion.create-nota-credito', ['cobro_ajuste' => ($ajuste = CobroAjuste::sole())->id]));
        $this->assertSame('PPQ/33762', $ajuste->referencia);
        $this->assertSame('3', $ajuste->referencia_linea);
        $this->assertSame(0, CobroEvento::count(), 'No se aplicó ningún pago.');

        // La nota queda hecha y enlazada (como la deja NotaCreditoAjuste::crear()).
        $nc = $this->dte('05', ['dte_relacionado_id' => $doc->dte_id, 'estado' => 'borrador', 'total_pagar' => '1.50',
            'tipo_nota_credito' => TipoNotaCredito::ProntoPago->value]);
        $ajuste->update(['nc_dte_id' => $nc->id]);

        // Otra vez el botón: no hace otra nota, va a la que ya está.
        $this->post(route('cobros.pagos.ajuste-nc', $this->cliente), ['hash' => $hash, 'linea' => 3])
            ->assertRedirect(route('facturacion.edit', $nc->id));

        // Al aplicar el TXT, el mismo ajuste (huella + línea) ya tiene su nota: no se duplica.
        $this->post(route('cobros.pagos.aplicar', $this->cliente), ['hashes' => [$hash]])->assertOk()
            ->assertDontSeeText('Crear nota de crédito');
        $this->assertSame($nc->id, CobroAjuste::sole()->nc_dte_id);
    }

    public function test_si_la_nota_del_ajuste_se_borro_el_boton_deja_hacer_la_nueva(): void
    {
        $doc = $this->ccf();
        $txt = $this->txt([$this->linea('CF', $doc->numero_control, '100.00'), $this->linea('QD', 'PPQ/33762', '-1.50')]);
        $hash = hash('sha256', $txt);
        $this->subirVarios(['quedan.txt' => $txt], soloRevisar: true)->assertOk();
        $this->post(route('cobros.pagos.ajuste-nc', $this->cliente), ['hash' => $hash, 'linea' => 3]);
        $ajuste = CobroAjuste::sole();
        $nc = $this->dte('05', ['dte_relacionado_id' => $doc->dte_id, 'estado' => 'borrador', 'total_pagar' => '1.50',
            'tipo_nota_credito' => TipoNotaCredito::ProntoPago->value]);
        $ajuste->update(['nc_dte_id' => $nc->id]);

        // El borrador se borra: el ajuste vuelve a estar pendiente y el botón abre la nota nueva.
        $nc->delete();
        $this->post(route('cobros.pagos.ajuste-nc', $this->cliente), ['hash' => $hash, 'linea' => 3])
            ->assertRedirect(route('facturacion.create-nota-credito', ['cobro_ajuste' => $ajuste->id]));
    }

    public function test_hacer_la_nota_desde_la_revision_exige_un_txt_revisado_una_linea_qd_y_permiso(): void
    {
        $doc = $this->ccf();
        $txt = $this->txt([$this->linea('CF', $doc->numero_control, '100.00'), $this->linea('QD', 'PPQ/33762', '-1.50')]);
        $hash = hash('sha256', $txt);

        // Sin revisar en esta sesión: no hay de dónde sacar la línea.
        $this->actingAs($this->usuario())->post(route('cobros.pagos.ajuste-nc', $this->cliente), ['hash' => $hash, 'linea' => 3])
            ->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains($m, 'no se revisó'));

        $this->subirVarios(['quedan.txt' => $txt], soloRevisar: true)->assertOk();
        // La línea 2 es un CF, no un ajuste.
        $this->post(route('cobros.pagos.ajuste-nc', $this->cliente), ['hash' => $hash, 'linea' => 2])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no es un ajuste'));
        $this->post(route('cobros.pagos.ajuste-nc', $this->cliente), ['hash' => 'no-es-huella', 'linea' => 3])
            ->assertSessionHasErrors('hash');
        $this->assertSame(0, CobroAjuste::count());

        // Contabilidad no gestiona cobros: 403.
        $conta = User::factory()->create()->assignRole(RolSistema::Contabilidad->value);
        $this->actingAs($conta)->post(route('cobros.pagos.ajuste-nc', $this->cliente), ['hash' => $hash, 'linea' => 3])->assertForbidden();
    }
}
