<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\EstadoPpq;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Enums\TipoNotaCredito;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\QuedanCallejaExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
 * Al subir el TXT del último PPQ, la pantalla devuelve lo de ESE lote que no vino pagado,
 * lo pagado de menos y las NC no descontadas, para dejarlos listos para el próximo PPQ.
 * Reglas del usuario (25/10/2026): un TXT por PPQ; lo pagado de menos no se vuelve a
 * presentar solo (puede ser una devolución AC04); la NC no descontada vuelve a ir.
 */
class CobrosTxtNoPagadosTest extends TestCase
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

    private function numero(string $control): string
    {
        return str_replace('-', '', $control);
    }

    /**
     * PPQ presentado con cuatro CCF y dos NC, y su TXT:
     *  · A pagado; su NC n1 (devolución AC04) NO vino → NC no descontada.
     *  · B pagado de menos (90 de 100) → pagado de menos; hay un AC04 con su OC.
     *  · C no vino → no pagado.
     *  · D pagado con su NC n2 descontada en línea aparte → nada que hacer.
     */
    private function escenario(): array
    {
        [$a, $b, $c, $d] = [$this->ccf(), $this->ccf(), $this->ccf(), $this->ccf()];
        $n1 = $this->dte('05', ['dte_relacionado_id' => $a->dte_id, 'total_pagar' => '3.39',
            'tipo_nota_credito' => TipoNotaCredito::DevolucionProducto->value]);
        DteAlbaran::create(['dte_id' => $n1->id, 'numero_canonico' => 'AC04/0017/00/3874', 'tipo_codigo' => 'AC04',
            'sala_codigo' => '0017', 'numero' => '3874', 'fecha' => '2026-09-01', 'total' => '3.39']);
        $n2 = $this->dte('05', ['dte_relacionado_id' => $d->dte_id, 'total_pagar' => '4.75',
            'tipo_nota_credito' => TipoNotaCredito::DevolucionProducto->value]);
        // Devolución que el sistema conoce por la OC del albarán de entrega de B.
        PpqAlbaran::create(['numero_albaran' => 'AC04/0017/00/9911', 'tipo_codigo' => 'AC04', 'fecha_albaran' => '2026-09-08',
            'monto_albaran' => '10.00', 'sala_codigo' => '0017', 'numero_orden_compra' => $b->albaran->numero_orden_compra]);

        $lote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$a->id, $b->id, $c->id, $d->id]);
        foreach ([$a, $b, $c, $d] as $doc) {
            $doc->refresh()->update(['presentacion_estado' => EstadoPresentacionCobro::Recibida->value]);
        }
        $lote->update(['estado' => EstadoPpq::Enviado->value]);

        $txt = "CODIGO_PROVEEDOR;NOMBRE;TIPO_DOCUMENTO;NUMERO_DOCUMENTO;FECHA_DOCUMENTO;VALOR\n"
            .'000123;TITULAR DE EJEMPLO;CF;'.$this->numero($a->numero_control).";10-SEP-26;100.00\n"
            .'000123;TITULAR DE EJEMPLO;CF;'.$this->numero($b->numero_control).";10-SEP-26;90.00\n"
            .'000123;TITULAR DE EJEMPLO;CF;'.$this->numero($d->numero_control).";10-SEP-26;100.00\n"
            .'000123;TITULAR DE EJEMPLO;NC;'.$this->numero($n2->numero_control).";10-SEP-26;-4.75\n";

        return compact('a', 'b', 'c', 'd', 'n1', 'n2', 'lote', 'txt');
    }

    private function subir(string $txt, ?User $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->usuario())->post(route('cobros.pagos', $this->cliente), [
            'archivo' => UploadedFile::fake()->createWithContent('pagos.txt', $txt),
        ]);
    }

    private function item(PpqLote $lote, int $dteId)
    {
        return $lote->items()->where('dte_id', $dteId)->sole();
    }

    public function test_la_pantalla_del_txt_devuelve_lo_no_pagado_lo_pagado_de_menos_y_las_nc_no_descontadas(): void
    {
        $e = $this->escenario();

        $respuesta = $this->subir($e['txt'])->assertOk();

        $pendientes = $respuesta->viewData('pendientes');
        $this->assertSame([$this->item($e['lote'], $e['c']->dte_id)->id], collect($pendientes['no_pagados'])->pluck('item.id')->all());
        $this->assertSame([$this->item($e['lote'], $e['n1']->id)->id], collect($pendientes['nc_no_descontadas'])->pluck('item.id')->all());
        $this->assertSame([$this->item($e['lote'], $e['b']->dte_id)->id], collect($pendientes['pagados_de_menos'])->pluck('item.id')->all());
        $this->assertSame('10.00', $pendientes['pagados_de_menos'][0]['diferencia']);
        $this->assertSame(EstadoPagoCobro::Parcial, $e['b']->refresh()->pago_estado);

        $respuesta->assertSeeText('Para volver a presentar')
            ->assertSeeText('No vinieron pagados')
            ->assertSeeText('NC no descontadas')
            ->assertSeeText('Pagados de menos')
            ->assertSeeText('Posible devolución AC04')
            ->assertSeeText('AC04/0017/00/9911')
            ->assertSeeText('Dejar listos para el próximo PPQ');

        // Lo no pagado y las NC vienen marcados; lo pagado de menos, no.
        $html = $respuesta->getContent();
        $marcado = fn (int $id) => preg_match('/<input[^>]*value="'.$id.'"[^>]*checked/', $html) === 1
            || preg_match('/<input[^>]*checked[^>]*value="'.$id.'"/', $html) === 1;
        $this->assertTrue($marcado($this->item($e['lote'], $e['c']->dte_id)->id));
        $this->assertTrue($marcado($this->item($e['lote'], $e['n1']->id)->id));
        $this->assertFalse($marcado($this->item($e['lote'], $e['b']->dte_id)->id));
    }

    public function test_subir_otra_vez_el_mismo_txt_devuelve_la_misma_lista_sin_duplicar_pagos(): void
    {
        $e = $this->escenario();
        $this->subir($e['txt'])->assertOk();

        $pendientes = $this->subir($e['txt'])->assertOk()->viewData('pendientes');

        $this->assertCount(1, $pendientes['no_pagados']);
        $this->assertCount(1, $pendientes['nc_no_descontadas']);
        $this->assertCount(1, $pendientes['pagados_de_menos']);
        $this->assertSame('90.00', (string) $e['b']->refresh()->monto_pagado);
    }

    public function test_dejar_listos_los_devuelve_al_seguimiento_y_el_proximo_ppq_los_lleva(): void
    {
        $e = $this->escenario();
        $usuario = $this->usuario();
        $hash = $this->subir($e['txt'], $usuario)->viewData('informe')['hash'];
        $items = [$this->item($e['lote'], $e['c']->dte_id)->id, $this->item($e['lote'], $e['n1']->id)->id,
            $this->item($e['lote'], $e['b']->dte_id)->id];

        $this->actingAs($usuario)->post(route('cobros.pagos.reenviar', $this->cliente), ['hash' => $hash, 'items' => $items])
            ->assertRedirect()->assertSessionHas('status');

        // C y n1 salen del PPQ pagado; B (cobrado de menos, conciliado) se queda con su historia.
        $this->assertSame(0, $e['lote']->items()->where('dte_id', $e['c']->dte_id)->count());
        $this->assertSame(0, $e['lote']->items()->where('dte_id', $e['n1']->id)->count());
        $this->assertSame(1, $e['lote']->items()->where('dte_id', $e['b']->dte_id)->count());
        $this->assertSame(EstadoPpq::Pagado, $e['lote']->refresh()->estado);
        $this->assertStringContainsString($e['c']->correlativoCorto(), (string) $e['lote']->observaciones);
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $e['c']->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $e['b']->refresh()->presentacion_estado);
        $this->assertTrue($e['c']->eventos()->where('referencia_linea', 'like', 'txt-%-fuera')->exists());
        $this->assertTrue(Activity::where('subject_id', $e['lote']->id)->exists());

        // En el Seguimiento se pueden marcar, y el aviso cuenta la NC no descontada.
        $index = $this->actingAs($usuario)->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))->assertOk();
        $this->assertTrue($index->viewData('listosPpq')->has($e['c']->id));
        $this->assertTrue($index->viewData('listosPpq')->has($e['b']->id));
        $index->assertSeeText('Se agregarán también 1 NC posteriores a una presentación (−$3.39)');

        $nuevoDoc = $this->ccf();
        $this->actingAs($usuario)->post(route('cobros.ppq.crear', $this->cliente), ['documentos' => [$e['c']->id, $e['b']->id, $nuevoDoc->id]])
            ->assertSessionHasNoErrors();
        $nuevo = PpqLote::latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing([$e['c']->dte_id, $e['b']->dte_id, $nuevoDoc->dte_id, $e['n1']->id],
            $nuevo->items()->pluck('dte_id')->all());

        // Va en el PPQ, pero no se repite en su quedan: el portal la rechaza por estar ya
        // asociada a un quedan (confirmado por el usuario, 25/10/2026).
        $this->assertNotContains(['0017', '3874', 'AC04'], $this->filasQuedan($nuevo));

        // La NC no se repite en un tercer PPQ.
        $tercero = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$this->ccf()->id]);
        $this->assertSame(0, $tercero->items()->where('dte_id', $e['n1']->id)->count());
    }

    public function test_la_nc_devuelta_que_queda_en_el_lote_viejo_por_su_historia_igual_va_en_el_proximo_ppq_sin_repetirse_en_el_quedan(): void
    {
        $e = $this->escenario();
        $usuario = $this->usuario();
        $hash = $this->subir($e['txt'], $usuario)->viewData('informe')['hash'];
        // Conciliación revertida: ya no está aplicada, pero su historia impide quitarla del lote.
        $itemNc = $this->item($e['lote'], $e['n1']->id);
        $corrida = DB::table('ppq_conciliaciones')->insertGetId(['ppq_lote_id' => $e['lote']->id, 'created_at' => now()]);
        DB::table('ppq_conciliacion_movimientos')->insert(['ppq_conciliacion_id' => $corrida, 'ppq_item_id' => $itemNc->id,
            'estado_anterior' => 'aplicada', 'estado_nuevo' => null, 'created_at' => now()]);

        $this->actingAs($usuario)->post(route('cobros.pagos.reenviar', $this->cliente), ['hash' => $hash, 'items' => [$itemNc->id]])
            ->assertSessionHas('status');

        $this->assertSame(1, $e['lote']->items()->where('dte_id', $e['n1']->id)->count());
        $nuevo = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$this->ccf()->id]);
        $this->assertSame(1, $nuevo->items()->where('dte_id', $e['n1']->id)->count());
        $this->assertNotContains(['0017', '3874', 'AC04'], $this->filasQuedan($nuevo));
    }

    /** Sala, número y tipo de cada fila del archivo de quedan del lote. */
    private function filasQuedan(PpqLote $lote): array
    {
        $ruta = app(QuedanCallejaExporter::class)->generar($lote->fresh());
        try {
            return array_map(fn ($fila) => [$fila[0], $fila[1], $fila[4]],
                array_slice(IOFactory::load($ruta)->getActiveSheet()->toArray(), 1));
        } finally {
            @unlink($ruta);
        }
    }

    public function test_lo_pagado_de_menos_no_marcado_sigue_sin_poder_entrar_en_un_ppq(): void
    {
        $e = $this->escenario();
        $usuario = $this->usuario();
        $hash = $this->subir($e['txt'], $usuario)->viewData('informe')['hash'];

        $this->actingAs($usuario)->post(route('cobros.pagos.reenviar', $this->cliente),
            ['hash' => $hash, 'items' => [$this->item($e['lote'], $e['c']->dte_id)->id]])->assertSessionHas('status');

        $this->actingAs($usuario)->post(route('cobros.ppq.crear', $this->cliente), ['documentos' => [$e['b']->id]])
            ->assertSessionHasErrors('documentos');
        $this->assertSame(EstadoPresentacionCobro::Recibida, $e['b']->refresh()->presentacion_estado);
    }

    public function test_reenviar_valida_en_el_servidor_y_no_cambia_nada_si_algo_no_corresponde(): void
    {
        $e = $this->escenario();
        $usuario = $this->usuario();
        $hash = $this->subir($e['txt'], $usuario)->viewData('informe')['hash'];
        $c = $this->item($e['lote'], $e['c']->dte_id)->id;
        $otroLote = app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, [$this->ccf()->id]);
        $ajeno = $otroLote->items()->sole()->id;

        $casos = [
            'pagado' => ['hash' => $hash, 'items' => [$c, $this->item($e['lote'], $e['a']->dte_id)->id]],
            'nc descontada' => ['hash' => $hash, 'items' => [$c, $this->item($e['lote'], $e['n2']->id)->id]],
            'lote que el TXT no tocó' => ['hash' => $hash, 'items' => [$c, $ajeno]],
            'otro archivo' => ['hash' => str_repeat('a', 64), 'items' => [$c]],
            'vacío' => ['hash' => $hash, 'items' => []],
        ];
        foreach ($casos as $caso => $datos) {
            $this->actingAs($usuario)->post(route('cobros.pagos.reenviar', $this->cliente), $datos)
                ->assertSessionHasErrors();
        }

        $this->assertSame(1, $e['lote']->items()->where('dte_id', $e['c']->dte_id)->count());
        $this->assertSame(EstadoPresentacionCobro::Recibida, $e['c']->refresh()->presentacion_estado);
        $this->assertFalse($e['c']->eventos()->where('referencia_linea', 'like', 'txt-%-fuera')->exists());
    }

    public function test_reenviar_exige_permiso_de_gestion(): void
    {
        $e = $this->escenario();
        $hash = $this->subir($e['txt'])->viewData('informe')['hash'];
        $lector = User::factory()->create();
        $lector->givePermissionTo('ppq.ver');

        $this->actingAs($lector)->post(route('cobros.pagos.reenviar', $this->cliente),
            ['hash' => $hash, 'items' => [$this->item($e['lote'], $e['c']->dte_id)->id]])->assertForbidden();
        $this->assertSame(1, $e['lote']->items()->where('dte_id', $e['c']->dte_id)->count());
    }
}
