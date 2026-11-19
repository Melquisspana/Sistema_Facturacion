<?php

namespace Tests\Feature\TiendaWeb;

use App\Enums\EstadoDte;
use App\Enums\EstadoPedidoWeb;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Facades\Ajustes;
use App\Jobs\EnviarDteCorreo;
use App\Mail\DteCorreo;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Models\PedidoWeb;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DtePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\PreparaEmisorDte;
use Tests\Concerns\RepresentacionPdfDte;
use Tests\TestCase;

/**
 * Recorrido de la oficina, con datos inventados, de lo que cambió con la primera Factura
 * (01) de la tienda web: el pedido entra de la página y se factura solo; el cliente recibe
 * el PDF por correo; la oficina lo ve, lo imprime y lo descarga; el CCF y la nota de
 * crédito siguen saliendo bien; con descuento real sí se imprime; y la lista de Pedidos web
 * muestra lo que requiere atención y los últimos 30 días, con sus permisos.
 */
class RecorridoOficinaPedidoWebTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;
    use RepresentacionPdfDte;

    private const BARRA_WEB = '740000000001';

    private array $emisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-19 09:00:00');
        Storage::fake('local');
        Queue::fake();
        Ajustes::guardarComoSistema('tienda.url', 'https://tienda.example.test');
        Ajustes::guardarComoSistema('tienda.secreto', 'secreto-inventado-solo-para-pruebas-123456');
        $this->seedCatalogosDte();
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        $this->emisor = ['establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id];
        foreach (['01', '03', '05'] as $tipo) {
            Correlativo::create($this->emisor + ['tipo_dte' => $tipo, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        }
    }

    // ---------- ayudas ----------

    /** Las representaciones de `facturacion.pdf` que se rindan desde ahora. */
    private function vistasDelPdf(): \ArrayObject
    {
        $vistas = new \ArrayObject;
        View::composer('facturacion.pdf', fn ($vista) => $vistas->append($vista));

        return $vistas;
    }

    private function oficina(string $rol = 'facturacion'): User
    {
        $usuario = User::factory()->create(['activo' => true])->assignRole($rol);
        $this->actingAs($usuario);

        return $usuario;
    }

    private function assertEsPdf(TestResponse $respuesta, string $disposicion): void
    {
        $respuesta->assertOk();
        $this->assertSame('application/pdf', strtok((string) $respuesta->headers->get('content-type'), ';'));
        $this->assertStringStartsWith($disposicion, (string) $respuesta->headers->get('content-disposition'));
    }

    /** Un pedido pagado tal como lo entrega la página. */
    private function pedidoDeLaPagina(): array
    {
        $linea = ['producto' => 'dulce', 'nombre' => 'Dulce inventado', 'barra' => self::BARRA_WEB, 'cantidad' => 3, 'precio' => 115];

        return [
            'id' => 1, 'codigo' => 'LN-TEST-0000-0001', 'idioma' => 'es', 'pagado' => '2026-11-19T14:00:00Z',
            'alerta' => '', 'unidades' => 3, 'total' => 345, 'moneda' => 'USD',
            'cliente' => ['nombre' => 'Elena Inventada', 'correo' => 'elena@example.test', 'telefono' => '70000000'],
            'entrega' => ['tipo' => 'recoger', 'departamento' => null, 'municipio' => null, 'direccion' => null, 'notas' => null],
            'cajitas_detalle' => [], 'regalo' => null, 'lineas' => [$linea],
            'pasarela' => 'prueba', 'referencia' => 'pago-inventado',
        ];
    }

    /** CCF generado y aceptado, con un producto que tiene código y barras distintos. */
    private function ccfAceptado(?Cliente $cliente = null, int $cantidad = 3): Dte
    {
        $borradores = app(DteBorradorService::class);
        $ccf = $borradores->crearBorrador($this->emisor + [
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => ($cliente ?? Cliente::factory()->contribuyente()->create())->id,
        ]);
        $borradores->agregarLineaDesdeProducto($ccf, Producto::factory()->create([
            'codigo' => 'CCF-001', 'codigo_barra' => '7400000000024',
            'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]), cantidad: $cantidad);
        app(DteGeneracionService::class)->generar($ccf);

        return $this->aceptarCcf($ccf->refresh());
    }

    /** Nota de crédito por devolución de una unidad del CCF, ya generada. */
    private function ncGenerada(Dte $ccf): Dte
    {
        $borradores = app(DteBorradorService::class);
        $nc = $borradores->crearNotaCredito($ccf);
        $borradores->acreditarLinea($nc, $ccf->lineas()->firstOrFail(), cantidad: 1);
        app(DteGeneracionService::class)->generar($nc->refresh());

        return $nc->refresh();
    }

    private function codigoDelJson(Dte $dte): mixed
    {
        return json_decode(Storage::disk('local')->get($dte->json_generado_path), true)['cuerpoDocumento'][0]['codigo'];
    }

    private function pedidoWeb(string $codigo, array $datos): PedidoWeb
    {
        $n = PedidoWeb::count() + 1;

        return PedidoWeb::create($datos + [
            'web_id' => $n, 'codigo' => $codigo, 'idioma' => 'es', 'pagado_en' => now(),
            'alerta' => '', 'total_centavos' => 345, 'moneda' => 'USD',
            'cliente_nombre' => 'Cliente Inventado '.$n, 'cliente_correo' => 'cliente'.$n.'@example.test',
            'cliente_telefono' => 'telefono-secreto', 'direccion' => 'direccion-secreta',
            'entrega_tipo' => 'recoger', 'cajitas_detalle' => [], 'lineas' => [], 'pasarela' => 'prueba',
            'estado' => EstadoPedidoWeb::Facturado,
        ]);
    }

    // ---------- 1. Factura (01) de un pedido web ----------

    public function test_pedido_web_facturado_llega_al_cliente_con_el_codigo_del_json_y_sin_estado_interno(): void
    {
        Producto::factory()->create([
            'codigo' => 'WEB01', 'nombre' => 'Dulce inventado', 'codigo_barra' => self::BARRA_WEB,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
        config(['dte.firma.enabled' => true, 'dte.firma.mock' => true, 'dte.transmision.mock' => true]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/api/sistema/pedidos-pagados*' => Http::response(['pedidos' => [$this->pedidoDeLaPagina()]]),
            '*/api/sistema/pedidos/*/estado' => Http::response([], 200),
            '*/api/sistema/limpieza' => Http::response([], 200),
        ]);

        $this->artisan('tienda:traer-pedidos', ['--aplicar' => true])->assertSuccessful();

        $web = PedidoWeb::sole();
        $this->assertSame(EstadoPedidoWeb::Facturado, $web->estado);
        $dte = Dte::findOrFail($web->dte_id);
        $this->assertSame(TipoDte::Factura, $dte->tipo_dte);
        $this->assertSame(EstadoDte::Aceptado, $dte->estado);
        $this->assertSame('WEB01', $this->codigoDelJson($dte), 'El JSON lleva el código del producto, no las barras.');
        $this->assertSame(
            'generó el JSON oficial preliminar (Factura, sin firma ni transmisión)',
            Activity::where('log_name', 'dte_json')->where('subject_id', $dte->id)->sole()->description,
        );

        // El correo al cliente: el PDF adjunto es la representación sin el estado interno.
        $this->simularProduccionCorreo();
        Mail::fake();
        $vistas = $this->vistasDelPdf();
        (new EnviarDteCorreo(DteEnvio::sole()->id))->handle(app(DtePdfService::class));
        $this->assertSame('enviado', DteEnvio::sole()->estado);
        Mail::assertSent(DteCorreo::class, fn (DteCorreo $m) => $m->hasTo('elena@example.test'));
        $this->assertCount(1, $vistas);
        $delCliente = $vistas[0]->render();
        $this->assertStringContainsString($dte->numero_control, $delCliente);
        $this->assertStringContainsString('<span class="cod">WEB01</span>', $delCliente);
        $this->assertStringNotContainsString(self::BARRA_WEB, $delCliente);
        $this->assertStringNotContainsString('Estado técnico (interno):', $delCliente);
        $this->assertStringNotContainsString('JSON generado:', $delCliente);
        $this->assertStringNotContainsString('Descuento global', $delCliente);
        $this->assertStringNotContainsString('-$0.00', $delCliente);
        $this->assertStringContainsString('$3.45', $delCliente);

        // La copia de la oficina: ver, imprimir y descargar llevan el estado interno.
        $this->oficina();
        foreach (['facturacion.pdf' => 'inline', 'facturacion.imprimir' => 'inline', 'facturacion.pdf.descargar' => 'attachment'] as $ruta => $disposicion) {
            $vistas = $this->vistasDelPdf();
            $this->assertEsPdf($this->get(route($ruta, $dte)), $disposicion);
            $this->assertCount(1, $vistas, "La ruta {$ruta} no rindió la representación.");
            $deLaOficina = $vistas[0]->render();
            $this->assertStringContainsString('Estado técnico (interno):', $deLaOficina, $ruta);
            $this->assertStringContainsString('Sello de recepción: <strong>sí</strong>', $deLaOficina, $ruta);
            $this->assertStringContainsString('<span class="cod">WEB01</span>', $deLaOficina, $ruta);
            $this->assertStringNotContainsString(self::BARRA_WEB, $deLaOficina, $ruta);
            $this->assertStringNotContainsString('Descuento global', $deLaOficina, $ruta);
        }
        $this->assertStringContainsString('dte-01-'.$dte->id.'.pdf', (string) $this->get(route('facturacion.pdf.descargar', $dte))->headers->get('content-disposition'));
    }

    // ---------- 2. CCF (03) y nota de crédito (05) ----------

    public function test_ccf_y_nota_de_credito_siguen_saliendo_con_el_codigo_del_json(): void
    {
        $ccf = $this->ccfAceptado();
        $nc = $this->ncGenerada($ccf);
        $this->assertSame(TipoDte::NotaCredito, $nc->tipo_dte);
        $this->assertSame('CCF-001', $this->codigoDelJson($nc), 'La NC lleva el código de la línea del CCF.');
        $this->assertSame(
            'generó el JSON oficial preliminar (Nota de Crédito, sin firma ni transmisión)',
            Activity::where('log_name', 'dte_json')->where('subject_id', $nc->id)->sole()->description,
        );

        foreach ([$ccf, $nc] as $dte) {
            $html = $this->htmlDelPdf($dte);
            $this->assertStringContainsString('<span class="cod">CCF-001</span>', $html, $dte->tipo_dte->label());
            $this->assertStringNotContainsString('7400000000024', $html, $dte->tipo_dte->label());
            $this->assertStringNotContainsString('Estado técnico (interno):', $html, $dte->tipo_dte->label());
            $this->assertStringNotContainsString('Descuento global', $html, $dte->tipo_dte->label());
            // El PDF de verdad (Dompdf) sale sin errores.
            $this->assertStringStartsWith('%PDF', app(DtePdfService::class)->bytes($dte));
        }
        $this->assertStringContainsString('Total a acreditar', $this->htmlDelPdf($nc));
        $this->assertStringContainsString($ccf->numero_control, $this->htmlDelPdf($nc), 'La NC muestra el CCF que acredita.');

        $this->oficina();
        $vistas = $this->vistasDelPdf();
        $this->assertEsPdf($this->get(route('facturacion.pdf.descargar', $nc)), 'attachment');
        $this->assertStringContainsString('Estado técnico (interno):', $vistas[0]->render());
    }

    // ---------- 3. Con descuento real ----------

    public function test_con_descuento_real_el_ccf_y_su_nota_de_credito_lo_imprimen(): void
    {
        $ccf = $this->ccfAceptado(Cliente::factory()->contribuyente()->create(['descuento_global_default' => 5]), cantidad: 4);
        $nc = $this->ncGenerada($ccf);

        foreach ([$ccf, $nc] as $dte) {
            $this->assertGreaterThan(0, (float) $dte->total_descuento, $dte->tipo_dte->label().' sin descuento.');
            $html = $this->htmlDelPdf($dte);
            $this->assertStringContainsString('Descuento global (5%)', $html, $dte->tipo_dte->label());
            $this->assertStringContainsString('-$'.number_format((float) $dte->total_descuento, 2), $html, $dte->tipo_dte->label());
            $this->assertStringNotContainsString('-$0.00', $html, $dte->tipo_dte->label());
        }
    }

    // ---------- 4. La lista de Pedidos web ----------

    public function test_lista_de_pedidos_web_con_pedidos_de_hoy_viejos_para_revisar_y_trabados(): void
    {
        $revisarViejo = $this->pedidoWeb('LN-REVISAR-VIEJO', ['estado' => EstadoPedidoWeb::ParaRevisar, 'motivo_revision' => 'Motivo inventado viejo', 'pagado_en' => now()->subDays(40)]);
        $revisarHoy = $this->pedidoWeb('LN-REVISAR-HOY', ['estado' => EstadoPedidoWeb::ParaRevisar, 'motivo_revision' => 'Motivo inventado de hoy', 'pagado_en' => now()->subHours(3)]);
        $alertaVieja = $this->pedidoWeb('LN-ALERTA-VIEJA', ['alerta' => 'pago_doble', 'pagado_en' => now()->subDays(40)->addHour()]);
        $trabado = $this->pedidoWeb('LN-TRABADO', ['estado' => EstadoPedidoWeb::Facturando, 'pagado_en' => now()->subHours(2)]);
        $trabado->forceFill(['updated_at' => now()->subHours(2)])->save();
        $facturandoAhora = $this->pedidoWeb('LN-FACTURANDO-AHORA', ['estado' => EstadoPedidoWeb::Facturando, 'pagado_en' => now()->subMinutes(5)]);
        $hoy = $this->pedidoWeb('LN-HOY', ['pagado_en' => now()->subHour()]);
        $importado = $this->pedidoWeb('LN-IMPORTADO', ['estado' => EstadoPedidoWeb::Importado, 'pagado_en' => now()->subDays(29)]);
        $viejo = $this->pedidoWeb('LN-VIEJO', ['pagado_en' => now()->subDays(40)]);

        $this->oficina();
        $this->get(route('facturacion.pedidos-web.index'))->assertOk()
            ->assertViewHas('atencion', fn ($lista) => $lista->pluck('id')->all() === [$revisarViejo->id, $revisarHoy->id, $alertaVieja->id, $trabado->id])
            ->assertViewHas('pedidos', fn ($lista) => $lista->pluck('id')->all() === [$facturandoAhora->id, $hoy->id, $importado->id])
            ->assertSeeInOrder(['Requieren atención', 'LN-REVISAR-VIEJO', 'LN-REVISAR-HOY', 'LN-ALERTA-VIEJA', 'LN-TRABADO',
                'Últimos 30 días', 'LN-FACTURANDO-AHORA', 'LN-HOY', 'LN-IMPORTADO'])
            ->assertSee('Motivo inventado viejo')->assertSee('Pago doble: revisar si hay que devolver el segundo cobro')
            ->assertSee('Atención y últimos 30 días')
            ->assertDontSee('LN-VIEJO')->assertDontSee('telefono-secreto')->assertDontSee('direccion-secreta');

        // El de hace 40 días no se perdió: está en Todos y en su estado.
        $this->get(route('facturacion.pedidos-web.index', ['estado' => 'todos']))->assertOk()
            ->assertViewHas('atencion', fn ($a) => $a === null)->assertDontSee('Requieren atención')
            ->assertSee('LN-VIEJO')->assertDontSee('Últimos 30 días')
            ->assertViewHas('pedidos', fn ($lista) => $lista->total() === 8);
        $this->get(route('facturacion.pedidos-web.index', ['estado' => 'facturado']))->assertOk()
            ->assertSee('LN-VIEJO')->assertSee('LN-HOY')->assertDontSee('LN-TRABADO');

        // Cuando lo trabado sale, deja de requerir atención y baja a los últimos 30 días.
        $trabado->update(['estado' => EstadoPedidoWeb::Facturado]);
        $this->get(route('facturacion.pedidos-web.index'))->assertOk()
            ->assertViewHas('atencion', fn ($lista) => ! $lista->contains('id', $trabado->id))
            ->assertViewHas('pedidos', fn ($lista) => $lista->contains('id', $trabado->id));
    }

    public function test_cada_parte_de_la_lista_pagina_por_separado(): void
    {
        for ($i = 1; $i <= 26; $i++) {
            $this->pedidoWeb(sprintf('LN-REV-%02d', $i), ['estado' => EstadoPedidoWeb::ParaRevisar, 'motivo_revision' => 'Motivo inventado', 'pagado_en' => now()->subDays(60)->addMinutes($i)]);
        }
        for ($i = 1; $i <= 26; $i++) {
            $this->pedidoWeb(sprintf('LN-OK-%02d', $i), ['pagado_en' => now()->subDays(1)->addMinutes($i)]);
        }
        $this->oficina();

        $primera = $this->get(route('facturacion.pedidos-web.index'))->assertOk()
            ->assertViewHas('atencion', fn ($lista) => $lista->count() === 25 && $lista->total() === 26)
            ->assertViewHas('pedidos', fn ($lista) => $lista->count() === 25 && $lista->total() === 26)
            ->assertSee('LN-REV-01')->assertDontSee('LN-REV-26')->assertSee('LN-OK-26')->assertDontSee('LN-OK-01');
        $primera->assertSee('pagina_atencion=2', false)->assertSee('page=2', false);

        // Pasar de página arriba no mueve la de abajo, y al revés.
        $this->get(route('facturacion.pedidos-web.index', ['pagina_atencion' => 2]))->assertOk()
            ->assertViewHas('atencion', fn ($lista) => $lista->pluck('codigo')->all() === ['LN-REV-26'])
            ->assertViewHas('pedidos', fn ($lista) => $lista->currentPage() === 1 && $lista->count() === 25);
        $this->get(route('facturacion.pedidos-web.index', ['page' => 2]))->assertOk()
            ->assertViewHas('atencion', fn ($lista) => $lista->currentPage() === 1 && $lista->count() === 25)
            ->assertViewHas('pedidos', fn ($lista) => $lista->pluck('codigo')->all() === ['LN-OK-01']);
        $this->get(route('facturacion.pedidos-web.index', ['pagina_atencion' => 2, 'page' => 2]))->assertOk()
            ->assertSee('pagina_atencion=2', false)
            ->assertViewHas('atencion', fn ($lista) => $lista->currentPage() === 2)
            ->assertViewHas('pedidos', fn ($lista) => $lista->currentPage() === 2);
    }

    // ---------- 5. Permisos de la lista ----------

    public function test_permisos_de_la_lista_de_pedidos_web(): void
    {
        $revisar = $this->pedidoWeb('LN-REVISAR', ['estado' => EstadoPedidoWeb::ParaRevisar, 'motivo_revision' => 'Motivo inventado']);
        $alerta = $this->pedidoWeb('LN-ALERTA', ['alerta' => 'pago_doble']);
        $ruta = route('facturacion.pedidos-web.index');

        $this->get($ruta)->assertRedirect(route('login'));

        foreach (['vendedor', 'jefe_vendedores', 'produccion', 'fabrica'] as $rol) {
            $this->oficina($rol);
            $this->get($ruta)->assertForbidden();
        }

        // Quien solo lee ve la lista, pero sin botones y sin poder actuar.
        foreach (['jefatura', 'contabilidad'] as $rol) {
            $this->oficina($rol);
            $this->get($ruta)->assertOk()->assertSee('LN-REVISAR')->assertSee('LN-ALERTA')
                ->assertDontSee('reintentar-'.$revisar->id)->assertDontSee('revisado-'.$alerta->id);
            $this->post(route('facturacion.pedidos-web.reintentar', $revisar))->assertForbidden();
            $this->post(route('facturacion.pedidos-web.revisado', $alerta), ['nota' => 'Nota inventada', 'confirmacion' => '1'])->assertForbidden();
        }
        $this->assertSame(EstadoPedidoWeb::ParaRevisar, $revisar->refresh()->estado);
        $this->assertSame('pago_doble', $alerta->refresh()->alerta);

        foreach (['facturacion', 'administrador'] as $rol) {
            $this->oficina($rol);
            $this->get($ruta)->assertOk()->assertSee('reintentar-'.$revisar->id)->assertSee('revisado-'.$alerta->id);
        }
    }
}
