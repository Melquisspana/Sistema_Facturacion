<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\Cobros\ElegibilidadPpqSeguimiento;
use App\Services\Cobros\ReporteCasoCalleja;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\EstadoRealLotePpq;
use App\Support\EjercicioControl;
use App\Support\IdentidadPpq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/** Regresiones de selección entre páginas y liberación de CCF de lotes borrados. */
class PpqSeleccionYLotesBorradosTest extends TestCase
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

        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente ficticio PPQ']);
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

    private function crear(array $docs): PpqLote
    {
        return app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, array_map(fn ($doc) => $doc->id, $docs));
    }

    /** El caso de Melqui: arma un PPQ, lo borra y vuelve a armar uno solo con todas las pendientes. */
    public function test_borrar_el_ppq_devuelve_sus_ccf_y_se_pueden_meter_en_uno_nuevo(): void
    {
        $docs = [$this->ccf(), $this->ccf()];
        $user = $this->usuario();
        $lote = $this->crear([$docs[0]]);

        $this->actingAs($user)->delete(route('ppq.lotes.destroy', $lote))->assertRedirect(route('ppq.lotes.index'));

        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $docs[0]->refresh()->presentacion_estado);
        $this->actingAs($user)->get(route('cobros.index', ['cliente_id' => $this->cliente->id, 'etapa' => 'listos']))
            ->assertOk()->assertSeeText('Seleccionar todas las pendientes (2 · $200.00)');
        $this->actingAs($user)->post(route('cobros.ppq.crear', $this->cliente), ['documentos' => [$docs[0]->id, $docs[1]->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, PpqLote::count());
        $this->assertSame(2, PpqLote::sole()->items()->where('tipo_dte', '03')->count());
    }

    public function test_un_lote_borrado_ya_no_bloquea_aunque_queden_sus_items(): void
    {
        $doc = $this->ccf();
        $lote = $this->crear([$doc]);
        $lote->delete();
        $doc->refresh()->forceFill(['presentacion_estado' => 'sin_presentar'])->save();

        $this->assertSame(1, $this->crear([$doc])->items()->count());
        $this->assertSame(1, PpqLote::count());
    }

    public function test_un_devuelto_puede_reingresar_y_el_nuevo_lote_bloquea(): void
    {
        $this->travelTo(now()->startOfSecond());
        $doc = $this->ccf();
        $devueltoDe = $this->crear([$doc]);
        $devueltoDe->update(['observaciones' => 'Caso 999 de Calleja.']);
        $this->travel(1)->seconds();
        $doc->refresh()->forceFill(['presentacion_estado' => 'sin_presentar'])->save();
        CobroEvento::create([
            'cobro_documento_id' => $doc->id, 'tipo' => 'nota', 'origen' => 'manual',
            'referencia_linea' => 'caso-999-fuera', 'fecha' => today(),
            'datos' => ['caso' => '999'],
        ]);
        $this->assertFalse(app(ElegibilidadPpqSeguimiento::class)->claves()->has(EjercicioControl::llave($doc->numero_control_norm, $doc->ejercicio_control)));
        $this->travel(1)->seconds();
        $this->crear([$doc]);
        $this->assertTrue(app(ElegibilidadPpqSeguimiento::class)->claves()->has(EjercicioControl::llave($doc->numero_control_norm, $doc->ejercicio_control)));
        try {
            $this->crear([$doc]);
            $this->fail('Debió rechazar el tercer intento.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya está en un PPQ', $e->errors()['documentos'][0]);
        }
        $this->assertSame(2, PpqLote::count());
    }

    public function test_rechaza_revision_historica_y_pago_en_revision_en_servidor(): void
    {
        $historico = $this->ccf();
        $historico->update(['revisar_historico' => true]);
        $pago = $this->ccf();
        CobroEvento::create([
            'cobro_documento_id' => $pago->id, 'tipo' => 'pago', 'origen' => 'manual',
            'estado' => 'en_revision', 'monto' => '100.00', 'fecha' => today(),
        ]);
        $user = $this->usuario();
        foreach ([$historico, $pago] as $doc) {
            $this->actingAs($user)->post(route('cobros.ppq.crear', $this->cliente), ['documentos' => [$doc->id]])
                ->assertSessionHasErrors('documentos');
        }
        $this->assertSame(0, PpqLote::count());
        $this->assertCount(0, app(ElegibilidadPpqSeguimiento::class)->listos(CobroDocumento::deCliente($this->cliente->id)));
    }

    public function test_fuera_de_otro_caso_no_libera_el_lote_armado(): void
    {
        $this->travelTo(now()->startOfSecond());
        $doc = $this->ccf();
        $anterior = PpqLote::forceCreate(['id' => 12, 'referencia' => 'Anterior', 'fecha' => today(), 'estado' => 'enviado',
            'cliente_id' => $this->cliente->id, 'observaciones' => 'Caso 123 de Calleja.']);
        $actual = $this->crear([$doc]);
        $this->assertSame(13, $actual->id);
        $actual->update(['observaciones' => 'Caso 12 de Calleja.']);
        $this->travel(1)->seconds();
        $doc->refresh()->update(['presentacion_estado' => 'sin_presentar']);
        $evento = CobroEvento::create(['cobro_documento_id' => $doc->id, 'tipo' => 'nota', 'origen' => 'manual',
            'referencia_linea' => 'caso-123-fuera', 'fecha' => today(), 'datos' => ['caso' => '123']]);
        $elegibilidad = app(ElegibilidadPpqSeguimiento::class);
        $this->assertSame('ya está en un PPQ', $elegibilidad->motivo($doc->fresh(), $elegibilidad->claves()));
        $this->assertCount(0, $elegibilidad->listos(CobroDocumento::deCliente($this->cliente->id)));
        $evento->update(['datos' => ['caso' => '12', 'ppq_lote_id' => $anterior->id]]);
        $this->assertTrue($elegibilidad->claves()->has(EjercicioControl::llave($doc->numero_control_norm, $doc->ejercicio_control)), 'El id del lote prevalece sobre las observaciones.');
        $this->actingAs($this->usuario())->post(route('cobros.ppq.crear', $this->cliente), ['documentos' => [$doc->id]])
            ->assertSessionHasErrors('documentos');
        $this->assertSame(2, PpqLote::count());
    }

    public function test_item_con_solo_dte_id_bloquea_y_se_conserva_al_borrar_otro(): void
    {
        $doc = $this->ccf();
        $borrado = $this->crear([$doc]);
        $otro = PpqLote::create(['referencia' => 'Solo id', 'fecha' => today(), 'estado' => 'borrador']);
        $otro->items()->create(['tipo_dte' => '03', 'dte_id' => $doc->dte_id, 'numero_control' => null, 'monto_dte' => 100]);
        $otro->items()->update(['numero_control' => null]);
        $doc->refresh()->update(['presentacion_estado' => 'sin_presentar']);
        $elegibilidad = app(ElegibilidadPpqSeguimiento::class);
        $claves = $elegibilidad->claves($borrado->id);
        $this->assertSame($otro->id, $claves->get('dte:'.$doc->dte_id));
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $doc->fresh()->presentacion_estado);
        $this->assertSame('ya está en un PPQ', $elegibilidad->motivo($doc->fresh(), $claves));
        $this->assertCount(0, $elegibilidad->listos(CobroDocumento::deCliente($this->cliente->id), $claves));
        $otro->items()->update(['numero_control' => 'DTE-03-CONTROL-DISTINTO']);
        $this->assertSame('ya está en un PPQ', $elegibilidad->motivo($doc->fresh(), $elegibilidad->claves($borrado->id)));
        $doc->update(['presentacion_estado' => 'preparada']);
        $this->actingAs($this->usuario())->delete(route('ppq.lotes.destroy', $borrado))
            ->assertSessionHas('status', fn ($mensaje) => str_contains($mensaje, "sigue en el PPQ #{$otro->id}"));
        $this->assertSame(EstadoPresentacionCobro::Preparada, $doc->refresh()->presentacion_estado);
    }

    public function test_fuera_del_mismo_lote_se_guarda_con_id_y_libera(): void
    {
        $doc = $this->ccf();
        $registrado = $this->ccf();
        $lote = $this->crear([$doc, $registrado]);
        $this->travel(1)->seconds();
        app(ReporteCasoCalleja::class)->aplicar($this->cliente,
            ['caso' => '999', 'documentos' => [$registrado->numero_control_norm => 'REGISTRADO']], null, $lote);
        $evento = CobroEvento::where('referencia_linea', 'caso-999-fuera')->sole();
        $this->assertSame($lote->id, $evento->datos['ppq_lote_id']);
        $this->assertFalse(app(ElegibilidadPpqSeguimiento::class)->claves()->has(EjercicioControl::llave($doc->numero_control_norm, $doc->ejercicio_control)));
        $this->assertSame(1, $this->crear([$doc->fresh()])->items()->count());
    }

    public function test_doble_envio_crea_un_solo_lote(): void
    {
        $doc = $this->ccf();
        $lecturas = [];
        $nivelInicial = DB::transactionLevel();
        DB::listen(function ($consulta) use (&$lecturas) {
            if (str_starts_with(strtolower($consulta->sql), 'select') && str_contains($consulta->sql, 'cobro_documentos')) {
                $lecturas[] = $consulta->connection->transactionLevel();
            }
        });
        $this->crear([$doc]);
        $this->assertNotEmpty($lecturas);
        $this->assertGreaterThan($nivelInicial, min($lecturas), 'La lectura y validación deben estar dentro de la transacción de creación.');
        try {
            $this->crear([$doc]);
            $this->fail('Debió rechazar el segundo envío.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('No se creó el PPQ.', $e->errors()['documentos'][0]);
        }
        $this->assertSame(1, PpqLote::count());
    }

    public function test_borrar_libera_solo_lo_que_estaba_por_ese_lote(): void
    {
        $libre = $this->ccf();
        $presentadoSinEvidencia = $this->ccf();
        $pagado = $this->ccf();
        $registradoPorCalleja = $this->ccf();
        $duplicado = $this->ccf();
        $conSolicitud = $this->ccf();
        $conDiferencia = $this->ccf();
        $lote = $this->crear([$libre, $presentadoSinEvidencia, $pagado, $registradoPorCalleja, $duplicado, $conSolicitud, $conDiferencia]);
        $presentadoSinEvidencia->refresh()->forceFill(['presentacion_estado' => 'presentada'])->save();
        $pagado->refresh()->forceFill(['pago_estado' => 'pagado'])->save();
        $conDiferencia->refresh()->forceFill(['pago_estado' => 'diferencia'])->save();
        $registradoPorCalleja->refresh()->forceFill(['presentacion_estado' => 'recibida'])->save();
        CobroEvento::create([
            'cobro_documento_id' => $registradoPorCalleja->id, 'tipo' => 'recibido', 'origen' => 'manual',
            'referencia_linea' => 'caso-12027', 'fecha' => today(), 'datos' => ['caso' => '12027'],
        ]);
        $solicitud = CobroSolicitud::create([
            'cliente_id' => $this->cliente->id, 'referencia' => 'SOL-FICTICIA',
            'formato' => 'carga_masiva_nc_v1', 'archivo_nombre' => 'ficticio.xlsx',
        ]);
        $conSolicitud->refresh()->update(['cobro_solicitud_id' => $solicitud->id]);
        $otro = PpqLote::create(['referencia' => 'Otro lote ficticio', 'fecha' => today(), 'estado' => 'borrador', 'cliente_id' => $this->cliente->id]);
        $otro->items()->create(['tipo_dte' => '03', 'numero_control' => $duplicado->numero_control, 'monto_dte' => '100.00']);

        $user = $this->usuario();
        $this->actingAs($user)->delete(route('ppq.lotes.destroy', $lote))->assertRedirect(route('ppq.lotes.index'))
            ->assertSessionHas('status', fn ($mensaje) => str_contains($mensaje, '2 CCF volvieron a por presentar')
                && str_contains($mensaje, 'No se tocaron 5') && str_contains($mensaje, 'caso 12027')
                && str_contains($mensaje, "PPQ #{$otro->id}") && str_contains($mensaje, 'SOL-FICTICIA'));

        $this->assertSoftDeleted($lote);
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $libre->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $presentadoSinEvidencia->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $pagado->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::Recibida, $registradoPorCalleja->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $duplicado->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $conSolicitud->refresh()->presentacion_estado);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $conDiferencia->refresh()->presentacion_estado);
        $this->assertDatabaseHas('cobro_eventos', ['cobro_documento_id' => $libre->id, 'tipo' => 'nota', 'referencia_linea' => "ppq-{$lote->id}-borrado", 'user_id' => $user->id]);
    }

    public function test_comando_simula_libera_y_es_idempotente(): void
    {
        $doc = $this->ccf();
        $pagado = $this->ccf();
        $lote = $this->crear([$doc, $pagado]);
        $pagado->refresh()->forceFill(['pago_estado' => 'pagado'])->save();
        $lote->delete();   // como en producción: borrado ANTES del arreglo, sin liberar

        $this->artisan('ppq:liberar-lotes-borrados', ['--dry-run' => true, '--lote' => [$lote->id]])
            ->expectsOutputToContain('Volverían a por presentar: 1')
            ->expectsOutputToContain('No se tocan: 1')
            ->expectsOutput('Total: 1 liberados; 1 no se tocan; 0 ya estaban por presentar.')
            ->expectsOutput('Simulación: no se cambió nada.')->assertSuccessful();
        $this->assertSame(EstadoPresentacionCobro::Preparada, $doc->refresh()->presentacion_estado);
        $this->assertDatabaseCount('cobro_eventos', 0);

        $this->artisan('ppq:liberar-lotes-borrados')
            ->expectsOutput('Total: 1 liberados; 1 no se tocan; 0 ya estaban por presentar.')->assertSuccessful();
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $doc->refresh()->presentacion_estado);

        $this->artisan('ppq:liberar-lotes-borrados')
            ->expectsOutput('Total: 0 liberados; 1 no se tocan; 1 ya estaban por presentar.')->assertSuccessful();
        $this->assertDatabaseCount('cobro_eventos', 1);

        $this->artisan('ppq:liberar-lotes-borrados', ['--lote' => [999]])->assertFailed();
    }

    public function test_seleccion_de_todo_el_cliente_supera_la_pagina_y_crea_treinta_ccf(): void
    {
        $docs = collect(range(1, 30))->map(fn () => $this->ccf());
        $user = $this->usuario();
        $this->actingAs($user)->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->assertOk()->assertSeeText('Seleccionar todas las pendientes (30 · $3,000.00)')
            ->assertSee('ppq-seleccion-'.$this->cliente->id, false)
            ->assertSee('x-for="id in fuera"', false)
            ->assertViewHas('listosPpq', fn ($listos) => $listos->count() === 30)
            ->assertViewHas('documentos', fn ($pagina) => $pagina->count() === 25);
        $this->actingAs($user)->get(route('cobros.index', ['cliente_id' => $this->cliente->id, 'page' => 2]))
            ->assertOk()->assertViewHas('listosEnFiltro', fn ($listos) => $listos->count() === 30);
        $this->post(route('cobros.ppq.crear', $this->cliente), ['documentos' => $docs->pluck('id')->all()])
            ->assertSessionHasNoErrors()->assertSessionHas('status', fn ($mensaje) => str_contains($mensaje, '30 CCF por $3,000.00'));
        $this->assertSame(30, PpqLote::sole()->items()->where('tipo_dte', '03')->count());
    }

    public function test_rechaza_mas_de_quinientos_ids(): void
    {
        $this->actingAs($this->usuario())->post(route('cobros.ppq.crear', $this->cliente), ['documentos' => range(1, 501)])
            ->assertSessionHasErrors(['documentos' => 'El máximo por PPQ es de 500 CCF. Quite algunos de la selección.']);
        $this->assertSame(0, PpqLote::count());
    }

    /** Un CCF que sus NC dejan en 0 no tiene nada que presentar ni cobrar: no se muestra ni se cuenta. */
    public function test_un_ccf_saldado_con_nc_no_aparece_ni_cuenta(): void
    {
        $saldado = $this->ccf();
        $saldado->refresh()->forceFill(['presentacion_estado' => 'presentada'])->save();
        $this->dte('05', ['dte_relacionado_id' => $saldado->dte_id, 'total_pagar' => '100.00']);
        $conNcParcial = $this->ccf();
        $this->dte('05', ['dte_relacionado_id' => $conNcParcial->dte_id, 'total_pagar' => '30.00']);
        $invalidada = $this->ccf();   // su NC por el total fue invalidada: sigue debiéndose
        $this->dte('05', ['dte_relacionado_id' => $invalidada->dte_id, 'total_pagar' => '100.00', 'estado' => 'invalidado']);

        $respuesta = $this->actingAs($this->usuario())->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))->assertOk();

        $ids = $respuesta->viewData('documentos')->getCollection()->pluck('id')->all();
        $this->assertNotContains($saldado->id, $ids);
        $this->assertContains($conNcParcial->id, $ids);
        $this->assertContains($invalidada->id, $ids);
        $this->assertSame(2, $respuesta->viewData('etapas')['']);
        $this->assertSame(0, $respuesta->viewData('etapas')['presentados']);
        $this->assertSame(2, $respuesta->viewData('contadores')['total']);
    }

    public function test_devueltos_salen_con_sus_nc_y_pueden_armarse_de_nuevo(): void
    {
        $tomado = $this->ccf();
        $devuelto = $this->ccf();
        $ncTomado = $this->dte('05', ['dte_relacionado_id' => $tomado->dte_id, 'total_pagar' => 10]);
        $ncDevuelto = $this->dte('05', ['dte_relacionado_id' => $devuelto->dte_id, 'total_pagar' => 15]);
        $docNcTomado = CobroDocumento::create(['cliente_id' => $this->cliente->id, 'origen' => 'dte', 'dte_id' => $ncTomado->id,
            'tipo_dte' => '05', 'numero_control' => $ncTomado->numero_control, 'fecha_emision' => '2026-09-10', 'monto' => 10]);
        $docNcTomado->forceFill(['presentacion_estado' => 'presentada'])->save();
        $docNc = CobroDocumento::create(['cliente_id' => $this->cliente->id, 'origen' => 'dte', 'dte_id' => $ncDevuelto->id,
            'tipo_dte' => '05', 'numero_control' => $ncDevuelto->numero_control, 'fecha_emision' => '2026-09-10', 'monto' => 15]);
        $lote = $this->crear([$tomado, $devuelto]);
        // El vínculo también se resuelve en snapshots de correo.
        $lote->items()->where('dte_id', $ncDevuelto->id)->update(['dte_id' => null]);
        $reporte = ['caso' => '555', 'documentos' => [IdentidadPpq::normalizar($tomado->numero_control) => 'REGISTRADO',
            IdentidadPpq::normalizar($ncDevuelto->numero_control) => 'REGISTRADO']];
        $servicio = app(ReporteCasoCalleja::class);
        $r = $servicio->aplicar($this->cliente, $reporte, lote: $lote);
        $this->assertCount(2, $r['items_quitados']);
        $this->assertSame(2, $lote->items()->count());
        $this->assertEquals(90, $lote->refresh()->totalMontoDte());
        $this->assertTrue($lote->items()->where('dte_id', $ncTomado->id)->exists());
        $this->assertSame(EstadoPresentacionCobro::Presentada, $docNcTomado->refresh()->presentacion_estado);
        $this->assertStringContainsString('CCF '.$devuelto->correlativoCorto().' · $100.00 · 10/09/2026', $lote->observaciones);
        $this->assertStringContainsString('NC '.$docNc->correlativoCorto().' · -$15.00', $lote->observaciones);
        $antes = $lote->observaciones;
        $this->assertCount(0, $servicio->aplicar($this->cliente, $reporte, lote: $lote)['items_quitados']);
        $this->assertSame($antes, $lote->refresh()->observaciones);
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $docNc->refresh()->presentacion_estado);
        $this->assertSame(1, DB::table('activity_log')->where('description', 'ppq.devueltos_caso')->count());
        $this->assertSame(1, $devuelto->eventos()->where('referencia_linea', 'caso-555-fuera')->count());
        $this->assertSame(1, $docNc->eventos()->where('referencia_linea', 'caso-555-fuera')->count());
        $this->assertSame(1, app(EstadoRealLotePpq::class)->calcular(collect([$lote]))[$lote->id]['devueltos']);
        $elegibilidad = app(ElegibilidadPpqSeguimiento::class);
        $this->assertNull($elegibilidad->motivo($devuelto->refresh(), $elegibilidad->claves()));
        $nuevo = $this->crear([$devuelto]);
        $this->assertTrue($nuevo->items()->where('dte_id', $ncDevuelto->id)->exists());
        $servicio->aplicar($this->cliente, $reporte, lote: $lote);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $devuelto->refresh()->presentacion_estado);
    }

    public function test_nc_del_ccf_que_permanece_se_queda_y_si_vino_registrada_queda_recibida(): void
    {
        $doc = $this->ccf();
        $nc = $this->dte('05', ['dte_relacionado_id' => $doc->dte_id, 'total_pagar' => 10]);
        $docNc = CobroDocumento::create(['cliente_id' => $this->cliente->id, 'origen' => 'dte', 'dte_id' => $nc->id,
            'tipo_dte' => '05', 'numero_control' => $nc->numero_control, 'fecha_emision' => '2026-09-10', 'monto' => 10]);
        $sinReporte = $this->ccf();
        $ncSinReporte = $this->dte('05', ['dte_relacionado_id' => $sinReporte->dte_id, 'total_pagar' => 5]);
        $docNcSinReporte = CobroDocumento::create(['cliente_id' => $this->cliente->id, 'origen' => 'dte', 'dte_id' => $ncSinReporte->id,
            'tipo_dte' => '05', 'numero_control' => $ncSinReporte->numero_control, 'fecha_emision' => '2026-09-10', 'monto' => 5]);
        $lote = $this->crear([$doc, $sinReporte]);
        $r = app(ReporteCasoCalleja::class)->aplicar($this->cliente, ['caso' => '556', 'documentos' => [
            IdentidadPpq::normalizar($doc->numero_control) => 'REGISTRADO', IdentidadPpq::normalizar($nc->numero_control) => 'REGISTRADO',
            IdentidadPpq::normalizar($sinReporte->numero_control) => 'REGISTRADO',
        ]], lote: $lote);
        $this->assertSame(3, $r['recibidos']);
        $this->assertSame(EstadoPresentacionCobro::Recibida, $docNc->refresh()->presentacion_estado);
        // La NC que el portal no listó sigue a su CCF, que quedó registrado: no sale ni cambia.
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $docNcSinReporte->refresh()->presentacion_estado);
        $this->assertSame(0, $docNcSinReporte->eventos()->count());
        $this->assertSame(4, $lote->items()->count());
        $this->assertCount(0, $r['items_quitados']);
    }

    /** Lote #20 de producción: el reporte se cargó con el código anterior y los devueltos quedaron dentro. */
    public function test_volver_a_subir_el_reporte_corrige_un_lote_cargado_antes(): void
    {
        $tomado = $this->ccf();
        $devuelto = $this->ccf();
        $nc = $this->dte('05', ['dte_relacionado_id' => $devuelto->dte_id, 'total_pagar' => 10]);
        $lote = $this->crear([$tomado, $devuelto]);
        $this->assertSame(3, $lote->items()->count());
        $reporte = ['caso' => '777', 'documentos' => [IdentidadPpq::normalizar($tomado->numero_control) => 'REGISTRADO']];
        // Estado que dejó el código anterior: devuelto por presentar, con su evento, pero todavía en el lote.
        $this->travel(1)->seconds();
        $lote->update(['estado' => 'enviado', 'observaciones' => 'Caso 777 de Calleja.']);
        $tomado->refresh()->forceFill(['presentacion_estado' => 'recibida'])->save();
        $devuelto->refresh()->forceFill(['presentacion_estado' => 'sin_presentar'])->save();
        CobroEvento::create(['cobro_documento_id' => $devuelto->id, 'tipo' => 'nota', 'origen' => 'manual',
            'referencia_linea' => 'caso-777-fuera', 'fecha' => today(), 'datos' => ['caso' => '777', 'ppq_lote_id' => $lote->id]]);

        $r = app(ReporteCasoCalleja::class)->aplicar($this->cliente, $reporte, lote: $lote);

        $this->assertCount(2, $r['items_quitados'], 'Sale el CCF devuelto con su NC.');
        $this->assertSame([$tomado->dte_id], $lote->items()->pluck('dte_id')->all());
        $this->assertEquals(100, $lote->refresh()->totalMontoDte(), 'El lote queda igual al caso.');
        $this->assertStringContainsString('Devueltos del caso 777', (string) $lote->observaciones);
        $this->assertSame(1, $devuelto->eventos()->where('referencia_linea', 'caso-777-fuera')->count());
        $this->assertSame(1, app(EstadoRealLotePpq::class)->calcular(collect([$lote]))[$lote->id]['devueltos']);
        $this->assertTrue($this->crear([$devuelto->refresh()])->items()->where('dte_id', $nc->id)->exists(), 'Vuelve a armarse con su NC.');
    }

    public function test_reaplicar_con_item_viejo_y_otro_lote_no_cambia_el_estado(): void
    {
        $doc = $this->ccf();
        $lote = $this->crear([$doc]);
        $lote->update(['observaciones' => 'Caso 888 de Calleja.']);
        $this->travel(1)->seconds();
        $doc->refresh()->forceFill(['presentacion_estado' => 'sin_presentar'])->save();
        CobroEvento::create(['cobro_documento_id' => $doc->id, 'tipo' => 'nota', 'origen' => 'manual',
            'referencia_linea' => 'caso-888-fuera', 'fecha' => today(), 'datos' => ['caso' => '888', 'ppq_lote_id' => $lote->id]]);
        $this->travel(1)->seconds();
        $nuevo = $this->crear([$doc]);
        $r = app(ReporteCasoCalleja::class)->aplicar($this->cliente, ['caso' => '888', 'documentos' => []], lote: $lote);
        $this->assertCount(1, $r['items_quitados']);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $doc->refresh()->presentacion_estado);
        $this->assertSame(1, $nuevo->items()->count());
        $this->assertSame(1, $doc->eventos()->where('referencia_linea', 'caso-888-fuera')->count());
    }

    public function test_retiro_conserva_items_conciliados(): void
    {
        $doc = $this->ccf();
        $lote = $this->crear([$doc]);
        $lote->items()->update(['conciliacion_estado' => 'pagado']);
        $r = app(ReporteCasoCalleja::class)->sacarDelLote($lote, '999', collect([$doc]));
        $this->assertCount(0, $r['items_quitados']);
        $this->assertCount(1, $r['conservados']);
        $this->assertSame(1, $lote->items()->count());
    }

    public function test_retiro_conserva_movimientos_aunque_el_item_ya_no_este_conciliado(): void
    {
        $doc = $this->ccf();
        $lote = $this->crear([$doc]);
        $item = $lote->items()->sole();
        $conciliacion = DB::table('ppq_conciliaciones')->insertGetId(['ppq_lote_id' => $lote->id]);
        DB::table('ppq_conciliacion_movimientos')->insert(['ppq_conciliacion_id' => $conciliacion, 'ppq_item_id' => $item->id]);
        $r = app(ReporteCasoCalleja::class)->sacarDelLote($lote, '999', collect([$doc]));
        $this->assertCount(0, $r['items_quitados']);
        $this->assertCount(1, $r['conservados']);
        $this->assertDatabaseHas('ppq_conciliacion_movimientos', ['ppq_item_id' => $item->id]);
    }
}
