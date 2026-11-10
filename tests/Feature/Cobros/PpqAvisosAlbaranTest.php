<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Facades\Ajustes;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\Cobros\DevolucionesAlbaran;
use App\Services\Cobros\ElegibilidadPpqSeguimiento;
use App\Services\Cobros\VinculadorAlbaranes;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\AlbaranParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'total_gravado' => '100.00', 'descuento_porcentaje_aplicado' => '0.00', 'iva_retenido' => '0.00',
        ]);
    }

    private function ccf(bool $entregado = true, array $datosDte = []): CobroDocumento
    {
        // La OC trae la sala (0017) aunque el albarán todavía no haya llegado.
        $oc = '2609001700'.str_pad((string) ($this->n + 1), 4, '0', STR_PAD_LEFT);
        $dte = $this->dte('03', $datosDte + ['numero_orden_compra' => $oc]);
        $doc = CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id,
            'tipo_dte' => '03', 'numero_control' => $dte->numero_control, 'fecha_emision' => '2026-09-10', 'monto' => $dte->total_pagar,
        ]);
        if ($entregado) {
            $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0017/00/'.(5000 + $this->n), 'tipo_codigo' => 'AC01',
                'fecha_albaran' => today()->toDateString(), 'monto_albaran' => '98.95', 'sala_codigo' => '0017',
                'numero_orden_compra' => $oc]);
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

    public static function conciliacionesAc04(): array
    {
        return [
            'retencion y descuento 1' => ['400.00', '5.00', '3.80', ['20.00'], '404.32'],
            'retencion y descuento 2' => ['600.12', '5.00', '5.70', ['12.34'], '625.40'],
            'retencion y descuento 3' => ['250.75', '5.00', '2.38', ['8.25'], '258.03'],
            'sin retencion' => ['400.00', '5.00', '0.00', ['20.00'], '407.93'],
            'sin descuento' => ['400.00', '0.00', '4.00', ['20.00'], '425.60'],
            'dos devoluciones' => ['400.00', '5.00', '3.80', ['20.00', '10.00'], '393.68'],
        ];
    }

    #[DataProvider('conciliacionesAc04')]
    public function test_concilia_base_menos_ac04_con_descuento_iva_y_retencion(string $base, string $descuento, string $retenido, array $montos, string $esperado): void
    {
        $doc = $this->ccf(datosDte: ['total_gravado' => $base, 'descuento_porcentaje_aplicado' => $descuento, 'iva_retenido' => $retenido]);
        $doc->albaran->update(['monto_albaran' => $esperado]);
        $notas = collect();
        foreach ($montos as $i => $monto) {
            $notas->push($this->devolucion($doc, $monto, ['numero_albaran' => 'AC04/0017/00/'.(8200 + $i), 'dte_id' => $doc->dte_id]));
        }
        $this->assertSame('0.00', app(DevolucionesAlbaran::class)->diferencia($doc, $doc->albaran, $notas));
        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, app(VinculadorAlbaranes::class)->auditar($doc)['estado']);
    }

    public function test_tolerancia_de_un_centavo_solo_con_ac04_y_pedir_nota_se_conserva(): void
    {
        $doc = $this->ccf(datosDte: ['total_gravado' => '400.00', 'descuento_porcentaje_aplicado' => '5.00', 'iva_retenido' => '3.80']);
        $nota = $this->devolucion($doc, '20.00', ['dte_id' => $doc->dte_id]);
        foreach (['404.31', '404.33'] as $monto) {
            $doc->albaran->update(['monto_albaran' => $monto]);
            $this->assertSame(EstadoVinculacionAlbaran::Vinculado, app(VinculadorAlbaranes::class)->auditar($doc)['estado']);
        }
        $doc->albaran->update(['monto_albaran' => '404.34']);
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, app(VinculadorAlbaranes::class)->auditar($doc)['estado']);
        $nota->delete();
        $doc->albaran->update(['monto_albaran' => '100.01', 'fecha_albaran' => today()->subDays(5)]);
        $this->assertSame('-0.01', app(DevolucionesAlbaran::class)->diferencia($doc, $doc->albaran, collect()));
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, app(VinculadorAlbaranes::class)->auditar($doc)['estado']);
        $this->assertTrue(app(DevolucionesAlbaran::class)->avisos($doc)['pedir_nota']);
    }

    public function test_con_ac04_sin_datos_del_dte_o_de_la_nota_no_inventa_una_diferencia(): void
    {
        $doc = $this->ccf();
        $nota = $this->devolucion($doc, '20.00');
        $servicio = app(DevolucionesAlbaran::class);
        foreach (['total_gravado', 'descuento_porcentaje_aplicado', 'iva_retenido'] as $campo) {
            $dte = $doc->dte;
            $valor = $dte->$campo;
            $dte->$campo = null;
            $this->assertNull($servicio->diferencia($doc, $doc->albaran, collect([$nota])));
            $dte->$campo = $valor;
        }
        $nota->monto_albaran = null;
        $this->assertNull($servicio->diferencia($doc, $doc->albaran, collect([$nota])));
        $doc->setRelation('dte', null);
        $this->assertNull($servicio->diferencia($doc, $doc->albaran, collect([$nota])));
    }

    public function test_reciente_caduca_y_respeta_config(): void
    {
        $this->travelTo(now()->startOfSecond());
        $doc = $this->ccf();
        $doc->albaran->update(['fecha_albaran' => today()]);
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

    private function devolucion(CobroDocumento $doc, string $monto = '13.95', array $extra = []): PpqAlbaran
    {
        return PpqAlbaran::create($extra + [
            'numero_albaran' => 'AC04/0017/00/'.(7000 + $this->n),
            'numero_orden_compra' => $doc->albaran->numero_orden_compra,
            'sala_codigo' => '0017', 'monto_albaran' => $monto,
            'fecha_albaran' => '2026-09-11', 'origen' => 'gmail',
        ]);
    }

    public function test_devolucion_explica_toda_la_diferencia_y_se_vincula_automaticamente(): void
    {
        $doc = $this->ccf();
        $doc->albaran->update(['monto_albaran' => '97.24']);
        $nota = $this->devolucion($doc, '10.00');
        $segunda = $this->devolucion($doc, '3.95', ['numero_albaran' => 'AC04/0017/00/7201']);
        $vinculador = app(VinculadorAlbaranes::class);
        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, $vinculador->aplicar($doc));
        $this->assertSame($doc->dte_id, $nota->refresh()->dte_id);
        $this->assertSame($doc->dte_id, $segunda->refresh()->dte_id);
        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, $vinculador->auditar($doc->fresh())['estado']);
    }

    public function test_devolucion_parcial_muestra_la_diferencia_restante(): void
    {
        $doc = $this->ccf();
        $doc->albaran->update(['monto_albaran' => '126.00']);
        $this->devolucion($doc, '13.95', ['dte_id' => $doc->dte_id]);
        $veredicto = app(VinculadorAlbaranes::class)->auditar($doc);
        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertStringContainsString('28.76', $veredicto['motivo']);
        $this->actingAs($this->usuario())->get(route('cobros.documentos.show', $doc))
            ->assertOk()->assertSeeText('Diferencia contra lo esperado: $28.76');
    }

    public function test_ficha_vinculada_automaticamente_o_a_mano_no_pide_vincular_otra_vez(): void
    {
        $doc = $this->ccf();
        $this->devolucion($doc, '13.95', ['dte_id' => $doc->dte_id]);
        $this->actingAs($this->usuario());
        $url = route('cobros.documentos.show', $doc);
        $this->get($url)->assertOk()->assertDontSeeText('Revisar vinculación')
            ->assertSeeText('Diferencia contra lo esperado')->assertSeeText('Falta NC')->assertSeeText('Hacer NC');
        app(VinculadorAlbaranes::class)->vincularAMano($doc, $doc->albaran, $this->usuario(), 'Entrega confirmada');
        $this->get($url)->assertOk()->assertDontSeeText('Revisar vinculación')->assertSeeText('Diferencia contra lo esperado');
        $this->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))->assertOk()
            ->assertSeeText('Devolución sin NC (1)');
    }

    public function test_devolucion_de_otra_sala_o_cliente_no_se_vincula_y_se_explica(): void
    {
        $doc = $this->ccf();
        $otraSala = $this->devolucion($doc, '12.00', ['numero_albaran' => 'AC04/0033/00/7501', 'sala_codigo' => '0033']);
        $otro = Cliente::factory()->create(['nombre' => 'Distribuidora El Roble']);
        $sucursal = $otro->sucursales()->create(['nombre' => 'Sucursal Centro', 'codigo' => '0017']);
        $otroCliente = $this->devolucion($doc, '8.50', ['numero_albaran' => 'AC04/0017/00/7502', 'cliente_sucursal_id' => $sucursal->id]);
        $doc->albaran->update(['monto_albaran' => '100.00']);
        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, app(VinculadorAlbaranes::class)->aplicar($doc));
        $this->assertNull($otraSala->refresh()->dte_id);
        $this->assertNull($otroCliente->refresh()->dte_id);
        app(VinculadorAlbaranes::class)->vincularAMano($doc, $doc->albaran, $this->usuario(), 'Entrega confirmada por la sala');
        $this->assertNull($otraSala->refresh()->dte_id);
        $this->assertNull($otroCliente->refresh()->dte_id);
        $this->actingAs($this->usuario())->get(route('cobros.documentos.show', $doc))->assertOk()
            ->assertSeeText('otra sala')->assertSeeText('otro cliente');
    }

    public function test_vincular_a_mano_incluye_ac04_de_la_oc_elegida_aunque_el_ccf_tenga_oc_erronea(): void
    {
        $doc = $this->ccf(datosDte: ['numero_orden_compra' => '26090620003463']);
        $nota = $this->devolucion($doc);
        app(VinculadorAlbaranes::class)->vincularAMano($doc, $doc->albaran, $this->usuario(), 'OC digitada con error, entrega confirmada');
        $this->assertSame($doc->dte_id, $nota->refresh()->dte_id);
    }

    public function test_ficha_muestra_devolucion_sin_nc_y_despues_su_nc_emitida(): void
    {
        $doc = $this->ccf();
        $nota = $this->devolucion($doc, '13.95', ['dte_id' => $doc->dte_id, 'numero_albaran' => 'AC04/0017/00/007001']);
        $this->actingAs($this->usuario());
        $url = route('cobros.documentos.show', $doc);
        $this->get($url)->assertOk()->assertSeeText($nota->numero_albaran)->assertSeeText('13.95')->assertSeeText('Falta NC');
        $nc = $this->dte('05', ['estado' => 'borrador', 'dte_relacionado_id' => $doc->dte_id,
            'tipo_nota_credito' => 'devolucion_producto']);
        DteAlbaran::create(['dte_id' => $nc->id, 'numero_canonico' => 'AC04/0017/00/7001', 'tipo_codigo' => 'AC04',
            'numero' => '7001', 'fecha' => '2026-09-11', 'total' => '13.95', 'sala_codigo' => '0017']);
        $this->get($url)->assertOk()->assertSeeText($nc->numero_control)->assertDontSeeText('Falta NC')->assertDontSeeText('Hacer NC');
        $nc->update(['estado' => 'aceptado']);
        $this->get($url)->assertOk()->assertSeeText($nc->numero_control)->assertDontSeeText('Falta NC');
    }

    public function test_devolucion_total_compensa_la_entrega(): void
    {
        $doc = $this->ccf(datosDte: ['total_gravado' => '113.00']);
        $doc->albaran->update(['monto_albaran' => '0.00']);
        $this->devolucion($doc, '113.00');
        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, app(VinculadorAlbaranes::class)->aplicar($doc));
    }

    public function test_ac04_que_llega_despues_se_agrega_sin_cambiar_la_entrega_elegida(): void
    {
        $doc = $this->ccf();
        $entregaId = $doc->ppq_albaran_id;
        $nota = $this->devolucion($doc);
        $vinculador = app(VinculadorAlbaranes::class);
        $vinculador->actualizarDevoluciones($doc);
        $vinculador->actualizarDevoluciones($doc);
        $this->assertSame($doc->dte_id, $nota->refresh()->dte_id);
        $this->assertSame($entregaId, $doc->refresh()->ppq_albaran_id);
    }

    public function test_ac04_sin_ccf_aparece_en_seguimiento(): void
    {
        $doc = $this->ccf();
        $nota = $this->devolucion($doc);
        $this->actingAs($this->usuario())->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->assertOk()->assertSeeText('Notas de devolución sin vincular')->assertSeeText($nota->numero_albaran);
    }

    public function test_seguimiento_explica_una_referencia_a_ccf_de_otro_cliente(): void
    {
        $this->comprobarAc04DeCcfDeOtroCliente(fn () => config(['ppq.cliente_default_id' => $this->cliente->id]));
    }

    /**
     * Con el cliente guardado en Configuración › Módulos › Pronto pago, manda ese y no el
     * .env: el .env apunta al cliente del CCF (no habría motivo), lo guardado al otro.
     */
    public function test_la_ac04_usa_el_cliente_guardado_en_pantalla(): void
    {
        $this->comprobarAc04DeCcfDeOtroCliente(function (Cliente $delCcf) {
            config(['ppq.cliente_default_id' => $delCcf->id]);
            Ajustes::guardarComoSistema('ppq.cliente_default_id', $this->cliente->id);
        });
    }

    private function comprobarAc04DeCcfDeOtroCliente(\Closure $clienteDelModulo): void
    {
        $doc = $this->ccf();
        $otro = Cliente::factory()->create(['nombre' => 'Distribuidora El Bosque']);
        $this->dte('03', ['cliente_id' => $otro->id, 'numero_control' => 'DTE-03-M001P002-000000000004826',
            'numero_orden_compra' => '26110148008765']);
        $clienteDelModulo($otro);
        config(['dte.ambiente' => '01']);
        $nota = $this->devolucion($doc, '31.87', ['numero_albaran' => 'AC04/0148/00/8427',
            'numero_orden_compra' => null, 'sala_codigo' => '0148', 'archivo_path' => 'ppq/albaranes/ac04-ficticia.pdf']);
        Storage::disk((string) config('dte.storage.disk', 'local'))->put($nota->archivo_path,
            file_get_contents(base_path('tests/Fixtures/Ppq/ac04-ficticia.txt')));
        $this->app->instance(AlbaranParser::class, new class extends AlbaranParser
        {
            public function desdePdf(string $pdfBytes): array
            {
                return $this->desdeTexto($pdfBytes);
            }
        });
        $this->actingAs($this->usuario())->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))
            ->assertOk()->assertSeeText($nota->numero_albaran)->assertSeeText('el CCF pertenece a otro cliente');
        $this->assertNull($nota->refresh()->dte_id);
    }

    public function test_tres_dias_corren_desde_la_llegada_de_la_ultima_ac04(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));
        $doc = $this->ccf();
        $doc->albaran->update(['fecha_albaran' => '2026-10-20']);
        $this->travel(1)->days();
        $this->devolucion($doc, '13.95', ['dte_id' => $doc->dte_id]);
        $this->travel(2)->days();
        $avisos = app(ElegibilidadPpqSeguimiento::class);
        $this->assertTrue($avisos->avisos(collect([$doc->id]))[$doc->id]['reciente']);
        $this->travel(2)->days();
        $this->assertFalse($avisos->avisos(collect([$doc->id]))[$doc->id]['reciente']);
    }

    public function test_pedir_nota_desde_el_dia_siguiente_solo_con_diferencia_y_sin_ac04(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));
        $doc = $this->ccf();
        $doc->albaran->update(['fecha_albaran' => '2026-10-20']);
        $this->actingAs($this->usuario());
        $url = route('cobros.documentos.show', $doc);
        $this->get($url)->assertOk()->assertDontSeeText('Pedir la nota de devolución a Cliente ficticio PPQ');
        $this->travel(1)->days();
        $this->get($url)->assertOk()->assertSeeText('Pedir la nota de devolución a Cliente ficticio PPQ')
            ->assertSeeText($doc->numero_control)->assertSeeText($doc->albaran->numero_orden_compra)->assertSeeText('0017');
        $doc->albaran->update(['monto_albaran' => '100.00']);
        $this->get($url)->assertOk()->assertDontSeeText('Pedir la nota de devolución a Cliente ficticio PPQ');
        $doc->albaran->update(['monto_albaran' => '98.95']);
        $this->devolucion($doc, '13.95', ['dte_id' => $doc->dte_id]);
        $this->get($url)->assertOk()->assertDontSeeText('Pedir la nota de devolución a Cliente ficticio PPQ');
    }

    public function test_no_pide_una_ac04_que_ya_llego_aunque_aun_este_sin_vincular(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));
        $doc = $this->ccf();
        $this->travel(1)->days();
        $this->devolucion($doc);
        $this->assertFalse(app(ElegibilidadPpqSeguimiento::class)->avisos(collect([$doc->id]))[$doc->id]['pedir_nota']);
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

    public function test_la_ficha_nombra_el_portal_del_cliente_del_documento(): void
    {
        $this->travelTo(now()->startOfSecond());
        $doc = $this->ccf();
        $doc->albaran->update(['fecha_albaran' => today()]);

        $this->actingAs($this->usuario())->get(route('cobros.documentos.show', $doc))->assertOk()
            ->assertSeeText('puede no estar en el portal de Cliente ficticio PPQ todavía');
    }
}
