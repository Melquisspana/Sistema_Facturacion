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
use App\Services\Dte\PerfilDocumentoResolver;
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
        $this->crear([$doc]);
        $this->travel(1)->seconds();
        $doc->refresh()->forceFill(['presentacion_estado' => 'sin_presentar'])->save();
        CobroEvento::create([
            'cobro_documento_id' => $doc->id, 'tipo' => 'nota', 'origen' => 'manual',
            'referencia_linea' => 'caso-999-fuera', 'fecha' => today(),
        ]);
        $this->assertFalse(app(ElegibilidadPpqSeguimiento::class)->claves()->has($doc->numero_control_norm));
        $this->travel(1)->seconds();
        $this->crear([$doc]);
        $this->assertTrue(app(ElegibilidadPpqSeguimiento::class)->claves()->has($doc->numero_control_norm));
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
}
