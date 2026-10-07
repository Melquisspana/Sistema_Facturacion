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
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\Cobros\ElegibilidadPpqSeguimiento;
use App\Services\Dte\PerfilDocumentoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/** Avisos de albaranes al seleccionar CCF para un PPQ. */
class PpqAvisosAlbaranTest extends TestCase
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

    public function test_reciente_caduca_y_respeta_config(): void
    {
        $this->travelTo(now()->startOfSecond());
        $doc = $this->ccf();
        $this->actingAs($this->usuario());
        $url = route('cobros.index', ['cliente_id' => $this->cliente->id]);
        $this->get($url)->assertOk()->assertSeeText('Albarán reciente');
        $this->travel(6)->days();
        $this->get($url)->assertOk()->assertDontSeeText('Albarán reciente');
        config()->set('cobros.dias_albaran_reciente', 7);
        $this->get($url)->assertOk()->assertSeeText('Albarán reciente');
        config()->set('cobros.dias_albaran_reciente', 5);
        $this->assertFalse(app(ElegibilidadPpqSeguimiento::class)->avisos(collect([$doc->id]))[$doc->id]['reciente']);
    }

    private function duplicadoEnLote(CobroDocumento $doc): PpqLote
    {
        $otro = $this->ccf();
        $otro->update(['ppq_albaran_id' => $doc->ppq_albaran_id]);

        return $this->crear([$otro]);
    }

    public function test_lote_vigente_avisa_y_todas_excluye_conteo_y_monto(): void
    {
        $doc = $this->ccf();
        $libre = $this->ccf();
        $libre->update(['monto' => '250.00']);
        $lote = $this->duplicadoEnLote($doc);
        $this->actingAs($this->usuario())->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->assertOk()->assertSeeText('Posible duplicado')->assertSee('en el PPQ #'.$lote->id)
            ->assertSeeText('Seleccionar todas las pendientes (1 · $250.00); 1 posibles duplicados no incluidos')
            ->assertSee('id="doc_'.$doc->id.'"', false)
            ->assertViewHas('listosEnFiltro', fn ($ids) => $ids->keys()->all() === [$libre->id]);
        $lote->delete();
        $this->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))->assertOk()->assertDontSeeText('Posible duplicado');
    }

    public function test_otro_documento_pagado_o_presentado_avisa(): void
    {
        $doc = $this->ccf();
        $otro = $this->ccf();
        $otro->forceFill(['ppq_albaran_id' => $doc->ppq_albaran_id, 'pago_estado' => 'pagado'])->save();
        $this->actingAs($this->usuario());
        $url = route('cobros.index', ['cliente_id' => $this->cliente->id]);
        $this->get($url)->assertOk()->assertSeeText('Posible duplicado')->assertSee(', pagado');
        $otro->forceFill(['pago_estado' => 'pendiente', 'presentacion_estado' => 'presentada'])->save();
        $this->get($url)->assertOk()->assertSeeText('Posible duplicado')->assertSee(', presentado');
    }

    public function test_post_manual_crea_lote_y_avisa_duplicado(): void
    {
        $doc = $this->ccf();
        $this->duplicadoEnLote($doc);
        $this->actingAs($this->usuario())->post(route('cobros.ppq.crear', $this->cliente), ['documentos' => [$doc->id]])
            ->assertSessionHasNoErrors()->assertSessionHas('status', fn ($texto) => str_contains($texto, '1 posibles duplicados'));
        $this->assertSame(2, PpqLote::count());
        $this->assertSame(EstadoPresentacionCobro::Preparada, $doc->refresh()->presentacion_estado);
    }

    public function test_html_mapa_y_acciones_de_todas_las_paginas(): void
    {
        $docs = collect(range(1, 26))->map(fn () => $this->ccf());
        $this->actingAs($this->usuario())->get(route('cobros.index', ['cliente_id' => $this->cliente->id, 'page' => 2]))
            ->assertOk()->assertSee('avisos:', false)->assertSee('quitarRecientes()', false)
            ->assertSeeText('Quitar los de albarán reciente')->assertSee('x-show="recientes > 0"', false)
            ->assertSee('this.seleccion.filter(id => this.avisos[id]?.r)', false)
            ->assertViewHas('avisosPpq', fn ($avisos) => count($avisos) === $docs->count() && $avisos[$docs->first()->id]['reciente']);
    }

    public function test_mismo_dte_no_es_duplicado(): void
    {
        $doc = $this->ccf();
        $lote = PpqLote::create(['referencia' => 'Ficticio', 'fecha' => today(), 'estado' => 'borrador', 'cliente_id' => $this->cliente->id]);
        $lote->items()->create(['tipo_dte' => '03', 'dte_id' => $doc->dte_id, 'numero_control' => 'OTRO-999', 'ppq_albaran_id' => $doc->ppq_albaran_id, 'monto_dte' => '100.00']);
        $this->assertNull(app(ElegibilidadPpqSeguimiento::class)->avisos(collect([$doc->id]))[$doc->id]['duplicado'] ?? null);
    }

    /** La tarjeta cuenta 3 entregados por presentar, el botón 1: la vista dice cuáles faltan y por qué. */
    public function test_explica_los_entregados_que_no_se_pueden_seleccionar(): void
    {
        $this->ccf();
        $enOtroLote = $this->ccf();   // como el CCF 200: sin presentar, pero su item sigue en un lote vigente
        $lote = PpqLote::create(['referencia' => 'Lote viejo', 'fecha' => today(), 'estado' => 'borrador', 'cliente_id' => $this->cliente->id]);
        $lote->items()->create(['tipo_dte' => '03', 'dte_id' => $enOtroLote->dte_id, 'numero_control' => $enOtroLote->numero_control, 'monto_dte' => '100.00']);
        $historico = $this->ccf();
        $historico->refresh()->forceFill(['revisar_historico' => true])->save();

        $respuesta = $this->actingAs($this->usuario())->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))->assertOk();

        $this->assertSame(3, $respuesta->viewData('etapas')['listos']);
        $respuesta->assertSeeText('Seleccionar todas las pendientes (1 ·')
            ->assertSeeText('2 no se pueden seleccionar')
            ->assertSeeText('CCF '.$enOtroLote->correlativoCorto().": sigue en el PPQ #{$lote->id}")
            ->assertSeeText('CCF '.$historico->correlativoCorto().': falta la revisión histórica');
    }
}
