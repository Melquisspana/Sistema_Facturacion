<?php

namespace Tests\Feature\Alertas;

use App\Ajustes\Correo\ConfiguracionCorreoRuntime;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\EstadoPedidoWeb;
use App\Mail\AlertaSistemaCorreo;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoWeb;
use App\Models\PpqAlbaran;
use App\Models\PpqLote;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Alertas\Avisador;
use App\Services\Cobros\CrearPpqDesdeSeguimiento;
use App\Services\Cobros\ElegibilidadPpqSeguimiento;
use App\Services\Cobros\LiberarCcfDeLoteBorrado;
use App\Services\Cobros\QuitarItemPpq;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\TiendaWeb\FacturarPedidoWeb;
use App\Support\Correo\CandadoCorreoReal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;
use Throwable;

/**
 * El probador de los vigilantes de datos raros: pedidos web sin factura, CCF fuera de
 * regla en PPQ, documentos sin sello y el Inicio, como pasaría de verdad: los datos se
 * dañan por el camino real, `alertas:revisar` corre cada 5 minutos y se cuentan los
 * correos. Datos inventados.
 */
class ProbadorVigilantesTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private const PEDIDOS = 'Pedidos web para revisar';

    private const COBROS = 'CCF fuera de regla en Cobros';

    private const SELLOS = 'Documentos sin el sello de Hacienda';

    private ?Cliente $cliente = null;

    private $estab = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-19 10:00', 'America/El_Salvador'));
        config(['alertas.enabled' => true, 'alertas.correo' => 'avisos@example.test']);
        $this->app->instance(CandadoCorreoReal::class, new class extends CandadoCorreoReal
        {
            public function debeSimular(): bool
            {
                return false;
            }
        });
        Mail::fake();
    }

    /** Pasa el tiempo: la facturación de pedidos web cada minuto y los avisos cada 5. */
    private function pasan(int $minutos, bool $facturar = false): void
    {
        for ($i = 0; $i < $minutos; $i++) {
            if ($facturar) {
                app(FacturarPedidoWeb::class)->pasada(true);
            }
            if (now()->minute % 5 === 0) {
                Artisan::call('alertas:revisar');
            }
            $this->travel(1)->minutes();
        }
    }

    /** @return list<string> */
    private function correos(string $nombre): array
    {
        return Mail::sent(AlertaSistemaCorreo::class, fn (AlertaSistemaCorreo $m) => $m->nombre === $nombre)
            ->map(fn (AlertaSistemaCorreo $m) => $m->accion)->values()->all();
    }

    private function ultimo(string $nombre): AlertaSistemaCorreo
    {
        return Mail::sent(AlertaSistemaCorreo::class, fn (AlertaSistemaCorreo $m) => $m->nombre === $nombre)->last();
    }

    private function administrador(): User
    {
        Permission::findOrCreate('sistema.salud', 'web');

        return User::factory()->create(['activo' => true])->assignRole('administrador')->givePermissionTo('sistema.salud');
    }

    // ───────────── Pedidos web sin factura ─────────────

    private function pedido(EstadoPedidoWeb $estado): PedidoWeb
    {
        return PedidoWeb::create([
            'web_id' => 7, 'codigo' => 'LN-TEST-0000-0007', 'idioma' => 'es', 'pagado_en' => now(), 'alerta' => '',
            'total_centavos' => 345, 'moneda' => 'USD', 'cliente_nombre' => 'Elena Inventada', 'cliente_correo' => 'elena@example.test',
            'cliente_telefono' => '70000000', 'entrega_tipo' => 'recoger', 'cajitas_detalle' => [], 'lineas' => [],
            'pasarela' => 'wompi', 'estado' => $estado,
            'motivo_revision' => $estado === EstadoPedidoWeb::ParaRevisar ? 'No se encontró un producto por su código de barras.' : null,
        ]);
    }

    /** La factura revienta cada minuto (firmador caído): se revierte y el pedido queda como estaba. */
    private function facturaQueRevienta(): void
    {
        $this->partialMock(FacturarPedidoWeb::class, fn ($m) => $m->shouldReceive('procesar')->andThrow(new RuntimeException('firmador caído')));
    }

    public function test_un_pedido_pagado_que_no_se_factura_avisa_una_vez_aunque_se_reintente_cada_minuto(): void
    {
        $this->facturaQueRevienta();
        $pedido = $this->pedido(EstadoPedidoWeb::Importado);

        $this->pasan(29, facturar: true);
        $this->assertSame([], $this->correos(self::PEDIDOS), 'La factura todavía puede salir.');

        $this->pasan(60, facturar: true);
        $this->assertSame(['falla'], $this->correos(self::PEDIDOS), 'El reintento de cada minuto no abre ni cierra el aviso.');
        $falla = $this->ultimo(self::PEDIDOS);
        $this->assertSame('Pedidos: LN-TEST-0000-0007.', $falla->hallazgo->detalle);
        $this->assertStringContainsString('/facturacion/pedidos-web', $falla->enlace);
        $this->assertSame(EstadoPedidoWeb::Importado, $pedido->refresh()->estado);

        // Vuelve el firmador y la factura sale.
        $pedido->forceFill(['estado' => EstadoPedidoWeb::Facturado])->save();
        $this->pasan(5);
        $this->assertSame(['falla', 'normalizado'], $this->correos(self::PEDIDOS));
    }

    public function test_reintentar_un_pedido_viejo_no_abre_un_aviso_nuevo_y_si_sigue_sin_factura_vuelve_a_avisar(): void
    {
        $this->facturaQueRevienta();
        $pedido = $this->pedido(EstadoPedidoWeb::ParaRevisar);
        $this->travel(2)->days();
        $this->travelTo(now()->startOfHour());
        $this->pasan(5);
        $this->assertSame(['falla'], $this->correos(self::PEDIDOS));

        $oficina = User::factory()->create(['activo' => true]);
        $oficina->givePermissionTo(['dte.ver', 'dte.gestionar']);
        $this->actingAs($oficina)->post(route('facturacion.pedidos-web.reintentar', $pedido))->assertRedirect();
        $this->assertSame(EstadoPedidoWeb::Importado, $pedido->refresh()->estado);

        $this->pasan(29, facturar: true);
        $this->assertNotContains('falla', array_slice($this->correos(self::PEDIDOS), 1), 'Reintentar no abre un aviso.');

        $this->pasan(10, facturar: true);
        $this->assertSame('falla', array_slice($this->correos(self::PEDIDOS), -1)[0], 'Pasada media hora sin factura, vuelve a avisar.');
        $this->assertNotNull(Cache::get('alertas.abiertas.pedidos_web_para_revisar'));
    }

    // ───────────── CCF fuera de regla en PPQ ─────────────

    private function prepararCobros(): void
    {
        Storage::fake((string) config('dte.storage.disk', 'local'));
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente Secreto PPQ']);
        ClientePerfilDocumento::create(['cliente_id' => $this->cliente->id, 'activo' => true, 'codigo_proveedor' => '000123',
            'formato_export' => 'carga_masiva_nc_v1', 'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0]);
        app(PerfilDocumentoResolver::class)->olvidar();
    }

    private function ccf(): CobroDocumento
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $this->n++;
        $dte = Dte::create([
            'establecimiento_id' => $this->estab->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) (200 + $this->n), 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8), 'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-11-10', 'hora_emision' => '08:00:00', 'total_pagar' => '100.00',
        ]);
        $doc = CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'origen' => OrigenCobroDocumento::Dte->value, 'dte_id' => $dte->id,
            'tipo_dte' => '03', 'numero_control' => $dte->numero_control, 'fecha_emision' => '2026-11-10', 'monto' => '100.00',
        ]);
        $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0017/00/'.(5000 + $doc->id), 'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-11-05', 'monto_albaran' => '98.95', 'sala_codigo' => '0017',
            'numero_orden_compra' => '2611001700'.str_pad((string) $doc->id, 4, '0', STR_PAD_LEFT)]);
        $doc->forceFill(['ppq_albaran_id' => $albaran->id, 'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value])->save();

        return $doc;
    }

    private function lote(array $docs): PpqLote
    {
        return app(CrearPpqDesdeSeguimiento::class)->crear($this->cliente, array_map(fn ($d) => $d->id, $docs));
    }

    public function test_el_ejemplo_de_melqui_un_ccf_en_ppq_sin_lote_vivo_avisa_se_ve_en_el_inicio_y_se_normaliza_al_devolverlo(): void
    {
        $this->prepararCobros();
        $doc = $this->ccf();
        $lote = $this->lote([$doc]);
        $lote->delete(); // borrado por fuera de la ficha: el CCF quedó «preparado»
        $this->assertSame('ya está en un PPQ', app(ElegibilidadPpqSeguimiento::class)
            ->motivo($doc->refresh(), app(ElegibilidadPpqSeguimiento::class)->claves()), 'Así lo ve la oficina.');

        // La pasada de las 10:00 lo ve recién tocado y guarda «nada» una hora: avisa en la de las 11:05.
        $this->pasan(60);
        $this->assertSame([], $this->correos(self::COBROS));
        $this->pasan(6);
        $this->assertSame(['falla'], $this->correos(self::COBROS));
        $falla = $this->ultimo(self::COBROS);
        $this->assertSame('1 CCF preparado no está en ningún PPQ', $falla->hallazgo->titulo);
        $this->assertSame('Preparados sin PPQ: 201.', $falla->hallazgo->detalle);
        $this->assertStringNotContainsString('Secreto', $falla->render());

        $html = $this->actingAs($this->administrador())->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Avisos abiertos', '1 CCF preparado no está en ningún PPQ', 'Preparados sin PPQ: 201.', 'Revisar'])
            ->getContent();
        // Cobros también está en los accesos y en «Por cobrar»: el botón se busca dentro de la caja.
        $this->assertSame(1, preg_match('/<section aria-labelledby="titulo-avisos".*?<\/section>/s', $html, $caja));
        $this->assertStringContainsString('href="'.route('cobros.index').'"', $caja[0]);

        // Quien ayuda con el sistema lo devuelve a «por presentar».
        app(LiberarCcfDeLoteBorrado::class)->liberar($lote);
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $doc->refresh()->presentacion_estado);
        $this->pasan(50);
        $this->assertSame(['falla'], $this->correos(self::COBROS), 'El resultado se guarda una hora.');
        $this->pasan(10);
        $this->assertSame(['falla', 'normalizado'], $this->correos(self::COBROS));
    }

    public function test_borrar_el_lote_desde_su_ficha_devuelve_los_ccf_y_no_avisa(): void
    {
        $this->prepararCobros();
        $doc = $this->ccf();
        $lote = $this->lote([$doc]);

        app(LiberarCcfDeLoteBorrado::class)->liberar($lote);
        $lote->delete();
        $this->pasan(20);

        $this->assertSame([], $this->correos(self::COBROS));
    }

    public function test_el_mismo_ccf_en_dos_ppq_avisa_y_se_normaliza_al_quitarlo_de_uno(): void
    {
        $this->prepararCobros();
        $doc = $this->ccf();
        $lote = $this->lote([$doc]);
        try {
            $this->lote([$doc->refresh()]);
            $this->fail('El sistema no deja meter el mismo CCF en otro PPQ.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('ya está en un PPQ', $e->getMessage());
        }
        // Llegó igual a dos PPQ (datos de antes de la regla, una carga por fuera).
        $otro = PpqLote::create(['referencia' => 'PPQ inventado', 'fecha' => now()->toDateString(),
            'estado' => $lote->estado, 'cliente_id' => $this->cliente->id]);
        $lote->items()->each(fn ($item) => $item->replicate()->fill(['ppq_lote_id' => $otro->id])->save());

        $this->pasan(16);
        $this->assertSame(['falla'], $this->correos(self::COBROS));
        $this->assertSame('1 CCF está en dos PPQ a la vez', $this->ultimo(self::COBROS)->hallazgo->titulo);
        $this->assertStringContainsString('CCF 201: PPQ #'.$lote->id.' y #'.$otro->id, $this->ultimo(self::COBROS)->hallazgo->detalle);

        $resultado = app(QuitarItemPpq::class)->quitar($otro, $otro->items()->first());
        $this->assertTrue($resultado['quitado']);
        $this->pasan(65);
        $this->assertSame(['falla', 'normalizado'], $this->correos(self::COBROS));
    }

    public function test_un_ccf_invalidado_que_sigue_en_cobro_avisa_y_se_ve_en_el_inicio(): void
    {
        $this->prepararCobros();
        $doc = $this->ccf();
        $this->lote([$doc]);
        $doc->refresh()->forceFill(['presentacion_estado' => EstadoPresentacionCobro::Presentada->value])->save();
        $doc->dte->forceFill(['estado' => 'invalidado', 'fecha_invalidacion' => now()->subDay()])->save();

        $this->pasan(16);

        $this->assertSame(['falla'], $this->correos(self::COBROS));
        $this->assertSame('1 CCF invalidado sigue en cobro', $this->ultimo(self::COBROS)->hallazgo->titulo);
        $this->assertStringContainsString('avisale al cliente que no lo pague', $this->ultimo(self::COBROS)->hallazgo->queHacer);
    }

    // ───────────── Documentos sin sello ─────────────

    private function dte(array $override): Dte
    {
        $this->n++;
        $empresa = Empresa::create(['razon_social' => 'Empresa '.$this->n, 'ambiente' => '01', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M'.str_pad((string) $this->n, 3, '0', STR_PAD_LEFT), 'nombre' => 'Casa', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P'.str_pad((string) $this->n, 3, '0', STR_PAD_LEFT), 'nombre' => 'Caja', 'activo' => true]);
        $dte = (new Dte)->forceFill(array_merge([
            'tipo_dte' => '03', 'ambiente' => '01', 'estado' => 'aceptado',
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'cliente_id' => Cliente::factory()->contribuyente()->create(['nombre' => 'CLIENTE SECRETO SA'])->id,
            'numero_control' => 'DTE-03-M001P001-'.str_pad((string) (300 + $this->n), 15, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(), 'hora_emision' => now()->toTimeString(), 'total_pagar' => 100,
            'sello_recepcion' => '2026SELLOREAL'.$this->n, 'fecha_procesamiento_mh' => now(),
        ], $override));
        $dte->save();

        return $dte;
    }

    public function test_un_aceptado_sin_sello_avisa_y_se_normaliza_cuando_se_carga_el_sello(): void
    {
        $this->dte(['ambiente' => '00', 'sello_recepcion' => null]); // pruebas: no cuenta
        $dte = $this->dte(['sello_recepcion' => '']);

        $this->pasan(6);
        $this->assertSame(['falla'], $this->correos(self::SELLOS));
        $falla = $this->ultimo(self::SELLOS);
        $this->assertSame('1 documento aceptado no tiene el sello de Hacienda', $falla->hallazgo->titulo);
        $this->assertSame('Sin sello: 302.', $falla->hallazgo->detalle);
        $this->assertStringNotContainsString('SECRETO', $falla->render());

        $dte->forceFill(['sello_recepcion' => '2026SELLOREALCARGADO'])->save();
        $this->pasan(65);
        $this->assertSame(['falla', 'normalizado'], $this->correos(self::SELLOS));
    }

    // ───────────── El Inicio ─────────────

    public function test_quien_no_tiene_salud_del_sistema_no_ve_la_caja_ni_se_leen_los_avisos(): void
    {
        $this->pedido(EstadoPedidoWeb::ParaRevisar);
        $this->pasan(1);
        $this->assertNotNull(Cache::get('alertas.abiertas.pedidos_web_para_revisar'));
        $this->mock(Avisador::class, fn ($m) => $m->shouldNotReceive('abiertos'));

        $usuario = User::factory()->create(['activo' => true]);
        $usuario->assignRole(['facturacion', 'contabilidad', 'jefatura']);
        $this->assertFalse($usuario->can('sistema.salud'));

        $this->actingAs($usuario)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Avisos abiertos')->assertDontSee('pedido web requiere atención')
            ->assertDontSee('Los avisos están apagados')->assertDontSee('Los avisos no se revisaron');
    }

    public function test_el_inicio_dice_si_el_correo_no_salio_y_la_linea_gris_si_los_avisos_estan_apagados_o_sin_correo(): void
    {
        // El correo no sale (SMTP caído): el aviso queda abierto igual.
        $this->mock(ConfiguracionCorreoRuntime::class, fn ($m) => $m->shouldReceive('aplicar')->andThrow(new RuntimeException('smtp caído')));
        $this->pedido(EstadoPedidoWeb::ParaRevisar);
        $this->pasan(1);
        $this->assertSame([], $this->correos(self::PEDIDOS));
        $admin = $this->administrador();

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Avisos abiertos (', '1 pedido web requiere atención', 'Pedidos: LN-TEST-0000-0007.',
                'El correo de este aviso todavía no salió.', 'Revisar'])
            ->assertSee(route('facturacion.pedidos-web.index'))
            ->assertDontSee('Elena')->assertDontSee('elena@example.test');

        config(['alertas.enabled' => false]);
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Los avisos están apagados: Configuración › Módulos › Avisos de fallas.')
            ->assertDontSee('1 pedido web requiere atención');

        config(['alertas.enabled' => true, 'alertas.correo' => '']);
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Los avisos no tienen a quién escribir')
            ->assertDontSee('1 pedido web requiere atención');

        // Se arregla el pedido: su aviso se va solo (el del respaldo, que en esta base no
        // existe, sigue abierto).
        config(['alertas.correo' => 'avisos@example.test']);
        PedidoWeb::query()->update(['estado' => EstadoPedidoWeb::Facturado, 'motivo_revision' => null]);
        $this->travelTo(now()->addMinutes(5 - now()->minute % 5));
        $this->pasan(1);
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertDontSee('1 pedido web requiere atención')->assertDontSee('Los avisos no se revisaron');
    }

    public function test_cada_boton_revisar_abre_su_pantalla_para_el_administrador(): void
    {
        $admin = $this->administrador();
        foreach (['configuracion.integraciones.tienda', 'facturacion.pedidos-web.index', 'cobros.index', 'facturacion.index', 'admin.salud-sistema'] as $ruta) {
            $this->actingAs($admin)->get(route($ruta))->assertOk();
        }
    }
}
