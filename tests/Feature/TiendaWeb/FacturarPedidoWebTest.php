<?php

namespace Tests\Feature\TiendaWeb;

use App\Enums\CondicionPago;
use App\Enums\EstadoDte;
use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoPedidoWeb;
use App\Enums\OrigenPedidoSala;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Facades\Ajustes;
use App\Jobs\EnviarDteCorreo;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Models\PedidoSala;
use App\Models\PedidoWeb;
use App\Models\Producto;
use App\Services\Dte\DteBorradorService;
use App\Services\TiendaWeb\FacturarPedidoWeb;
use App\Support\Dinero;
use App\Support\Dte\CandadoEmisionDte;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Factura 01 automática de cada pedido web (ADR 0011). Datos inventados y SQLite en
 * memoria. Hacienda se simula como en las pruebas de emisión: firma y transmisión MOCK,
 * o candados abiertos contra APITEST con `Http::fake`. Nada sale a la red.
 */
class FacturarPedidoWebTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private const SECRETO = 'secreto-inventado-solo-para-pruebas-123456';

    private const RECEPCION = '*/fesv/recepciondte*';

    private Producto $producto;

    private int $correlativoId;

    private array $emisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 09:00:00');
        Storage::fake('local');
        Queue::fake();
        Ajustes::guardarComoSistema('tienda.url', 'https://tienda.example.test');
        Ajustes::guardarComoSistema('tienda.secreto', self::SECRETO);
        $this->seedCatalogosDte();
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        $this->emisor = ['establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id];
        $this->correlativoId = Correlativo::create([
            'tipo_dte' => '01', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
        ])->id;
        $this->producto = Producto::factory()->create([
            'codigo' => 'WEB01', 'nombre' => 'Dulce inventado', 'codigo_barra' => '740000000001',
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
        $this->tienda();
    }

    // ---------- ayudas ----------

    /** Firma y transmisión simuladas, igual que GenerarTransmitirProduccionTest. */
    private function haciendaSimulada(): void
    {
        config(['dte.firma.enabled' => true, 'dte.firma.mock' => true, 'dte.transmision.mock' => true]);
    }

    /** Candados abiertos contra APITEST con firma simulada, igual que EmisionCandadoPorDocumentoTest. */
    private function apitestAbierto(): void
    {
        config([
            'dte.transmision.enabled' => true, 'dte.transmision.real_confirmation' => true,
            'dte.transmision.dry_run' => false, 'dte.transmision.sistema_actual_activo' => false,
            'dte.transmision.modo_operacion' => 'principal', 'dte.transmision.allow_production' => false,
            'dte.transmision.ambiente' => 'testing', 'dte.transmision.token' => 'Bearer TOKEN_FAKE_NO_REAL',
            'dte.firma.enabled' => true, 'dte.firma.mock' => true,
        ]);
    }

    /** La página de la tienda (y, si hace falta, Hacienda) simuladas en una sola tabla. */
    private function tienda(int $estado = 200, array $pedidos = [], array $hacienda = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($hacienda + [
            '*/api/sistema/pedidos-pagados*' => Http::response(['pedidos' => $pedidos]),
            '*/api/sistema/pedidos/*/estado' => Http::response([], $estado),
            '*/api/sistema/limpieza' => Http::response([], 200),
        ]);
    }

    private function linea(array $cambios = []): array
    {
        return array_replace(['producto' => 'dulce', 'nombre' => 'Dulce inventado', 'barra' => '740000000001', 'cantidad' => 3, 'precio' => 115], $cambios);
    }

    /** Un pedido como lo deja la etapa 1: importado y con su pedido de fábrica. */
    private function pedido(array $cambios = []): PedidoWeb
    {
        $sala = PedidoSala::create([
            'origen' => OrigenPedidoSala::Web, 'cliente_sucursal_id' => null, 'registrado_por' => null,
            'sacado_por_id' => null, 'fecha' => '2026-11-15', 'estado' => EstadoPedidoSala::Registrado, 'ruta_id' => null,
        ]);
        $sala->lineas()->create(['producto_id' => $this->producto->id, 'cantidad' => 3]);
        $n = PedidoWeb::count() + 1;

        return PedidoWeb::create(array_replace([
            'web_id' => $n, 'codigo' => 'LN-TEST-0000-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'idioma' => 'es',
            'pagado_en' => '2026-11-15 20:00:00', 'alerta' => '', 'total_centavos' => 345, 'moneda' => 'USD',
            'cliente_nombre' => 'Elena Inventada', 'cliente_correo' => 'elena@example.test', 'cliente_telefono' => '70000000',
            'entrega_tipo' => 'recoger', 'cajitas_detalle' => [], 'lineas' => [$this->linea()],
            'pasarela' => 'prueba', 'referencia' => 'pago-inventado', 'estado' => EstadoPedidoWeb::Importado,
            'pedido_sala_id' => $sala->id, 'informado_preparando_en' => now(),
        ], $cambios));
    }

    /** Un pedido pagado tal como lo entrega la página. */
    private function datosTienda(): array
    {
        return [
            'id' => 1, 'codigo' => 'LN-TEST-0000-0001', 'idioma' => 'es', 'pagado' => '2026-11-16T02:00:00Z',
            'alerta' => '', 'unidades' => 3, 'total' => 345, 'moneda' => 'USD',
            'cliente' => ['nombre' => 'Elena Inventada', 'correo' => 'elena@example.test', 'telefono' => '70000000'],
            'entrega' => ['tipo' => 'recoger', 'departamento' => null, 'municipio' => null, 'direccion' => null, 'notas' => null],
            'cajitas_detalle' => [], 'regalo' => null, 'lineas' => [$this->linea()],
            'pasarela' => 'prueba', 'referencia' => 'pago-inventado',
        ];
    }

    private function pasada(): array
    {
        return app(FacturarPedidoWeb::class)->pasada(true);
    }

    private function avisosDeFactura()
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && isset($r->data()['factura']));
    }

    private function envios()
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'recepciondte'));
    }

    /** Todo lo que se escriba al registro durante la prueba, para revisar que no lleve datos del cliente. */
    private function registro(): \ArrayObject
    {
        $lineas = new \ArrayObject;
        Event::listen(MessageLogged::class, fn (MessageLogged $m) => $lineas->append($m->message.' '.json_encode($m->context)));

        return $lineas;
    }

    private function assertRegistroSinDatosDelCliente(\ArrayObject $lineas): void
    {
        $texto = implode("\n", $lineas->getArrayCopy());
        foreach (['Elena', 'elena@example.test', '70000000', self::SECRETO, '"receptor"'] as $dato) {
            $this->assertStringNotContainsString($dato, $texto);
        }
    }

    // ---------- 1. camino feliz ----------

    public function test_camino_feliz_de_la_tienda_a_la_factura_aceptada_con_correo_y_aviso_una_vez(): void
    {
        $this->haciendaSimulada();
        $this->tienda(pedidos: [$this->datosTienda()]);
        $registro = $this->registro();

        $this->artisan('tienda:traer-pedidos', ['--aplicar' => true])->assertSuccessful();
        $this->assertRegistroSinDatosDelCliente($registro);

        $web = PedidoWeb::firstOrFail();
        $this->assertSame(EstadoPedidoWeb::Facturado, $web->estado);
        $dte = Dte::findOrFail($web->dte_id);
        $this->assertSame(TipoDte::Factura, $dte->tipo_dte);
        $this->assertSame(EstadoDte::Aceptado, $dte->estado);
        $this->assertSame(CondicionPago::Contado, $dte->condicion_operacion);
        $this->assertSame('03', $dte->forma_pago);
        $this->assertSame(FacturarPedidoWeb::FORMA_PAGO, $dte->forma_pago);
        $this->assertNull($dte->cliente_id);
        $this->assertNull($dte->created_by);
        $this->assertSame('Elena Inventada', $dte->receptor_nombre);
        $this->assertSame('elena@example.test', $dte->receptor_correo);
        $linea = $dte->lineas()->sole();
        $this->assertSame($this->producto->id, $linea->producto_id);
        $this->assertSame(0, Dinero::comparar($linea->cantidad, '3'));
        $this->assertSame(0, Dinero::comparar($linea->precio_unitario, '1.15'), 'Precio con IVA que pagó el cliente.');
        $this->assertSame('3.45', $dte->total_pagar);

        $json = json_decode(Storage::disk('local')->get($dte->json_generado_path), true);
        $this->assertSame('Elena Inventada', $json['receptor']['nombre']);
        $this->assertSame('elena@example.test', $json['receptor']['correo']);
        $this->assertNull($json['receptor']['numDocumento']);
        $this->assertSame(1, $json['resumen']['condicionOperacion']);
        $this->assertSame('03', $json['resumen']['pagos'][0]['codigo']);

        $envio = DteEnvio::sole();
        $this->assertSame($dte->id, $envio->dte_id);
        $this->assertSame(['elena@example.test'], $envio->destinatarios);
        $this->assertSame(DteEnvio::CANAL_CLIENTE, $envio->canal);
        $this->assertNull($envio->user_id);
        Queue::assertPushed(EnviarDteCorreo::class, 1);
        $this->assertNotNull($web->factura_correo_en);

        $avisos = $this->avisosDeFactura();
        $this->assertCount(1, $avisos);
        [$aviso] = $avisos->first();
        $this->assertStringEndsWith('/api/sistema/pedidos/1/estado', $aviso->url());
        $this->assertTrue($aviso->hasHeader('Authorization', 'Bearer '.self::SECRETO));
        $this->assertSame(['factura' => [
            'numero_control' => $dte->numero_control, 'codigo_generacion' => $dte->codigo_generacion, 'fecha' => '2026-11-16',
        ]], $aviso->data());
        $this->assertNotNull($web->informado_factura_en);

        $auditoria = Activity::where('log_name', 'pedido_web')->get();
        $this->assertNull($auditoria->firstWhere('causer_id', '!=', null));
        $facturado = $auditoria->first(fn (Activity $a) => ($a->properties['resultado'] ?? null) === 'facturado');
        $this->assertNotNull($facturado);
        // MySQL reordena las claves de una columna JSON: se compara sin importar el orden.
        $this->assertSame(
            collect(['web_id' => 1, 'codigo' => 'LN-TEST-0000-0001', 'dte_id' => $dte->id, 'origen' => 'tienda_web', 'resultado' => 'facturado'])->sortKeys()->all(),
            $facturado->properties->sortKeys()->all(),
        );
        $this->assertStringNotContainsString('Elena', $auditoria->toJson());
        $this->assertStringNotContainsString('elena@example.test', $auditoria->toJson());
    }

    // ---------- 2. candados ----------

    public static function candados(): array
    {
        $linea = ['producto' => 'dulce', 'nombre' => 'Dulce inventado', 'barra' => '740000000001', 'cantidad' => 3, 'precio' => 115];

        return [
            'pasarela de demostración' => [['pasarela' => 'demo']],
            'alerta de la tienda' => [['alerta' => 'pago_doble']],
            'suma que no cuadra' => [['total_centavos' => 400]],
            'precio en cero' => [['lineas' => [array_replace($linea, ['precio' => 0])], 'total_centavos' => 0]],
            'precio que no es entero' => [['lineas' => [array_replace($linea, ['precio' => '115'])]]],
            'correo inválido' => [['cliente_correo' => 'sin-arroba']],
            'nombre vacío' => [['cliente_nombre' => '   ']],
            'sin pedido de fábrica' => [['pedido_sala_id' => null]],
            'producto que ya no aparece' => [['lineas' => [array_replace($linea, ['barra' => 'NO-EXISTE'])]]],
        ];
    }

    #[DataProvider('candados')]
    public function test_cada_candado_deja_el_pedido_para_revisar_sin_dte_ni_hacienda(array $cambios): void
    {
        $this->haciendaSimulada();
        $pedido = $this->pedido($cambios);

        $this->assertSame(['facturados' => 0, 'facturando' => 0, 'facturas_para_revisar' => 1, 'facturas_con_error' => 0], $this->pasada());
        $this->pasada();

        $pedido->refresh();
        $this->assertSame(EstadoPedidoWeb::ParaRevisar, $pedido->estado);
        $this->assertNotEmpty($pedido->motivo_revision);
        $this->assertNull($pedido->dte_id);
        $this->assertDatabaseCount('dtes', 0);
        $this->assertSame(0, (int) Correlativo::findOrFail($this->correlativoId)->ultimo_numero);
        $this->assertSame(0, DteEnvio::count());
        Http::assertNothingSent();
        $auditoria = Activity::where('log_name', 'pedido_web')->sole();
        $this->assertSame('para_revisar', $auditoria->properties['resultado']);
        $this->assertNull($auditoria->causer_id);
    }

    public function test_pedido_que_ya_tiene_factura_no_crea_otra(): void
    {
        $this->haciendaSimulada();
        $otra = app(DteBorradorService::class)->crearBorrador(['tipo_dte' => TipoDte::Factura] + $this->emisor);
        $pedido = $this->pedido(['dte_id' => $otra->id]);

        $this->pasada();

        $this->assertSame(EstadoPedidoWeb::ParaRevisar, $pedido->refresh()->estado);
        $this->assertDatabaseCount('dtes', 1);
        $this->assertSame(EstadoDte::Borrador, $otra->refresh()->estado);
        Http::assertNothingSent();
    }

    public function test_si_el_total_del_documento_no_es_el_del_pedido_se_revierte_todo(): void
    {
        $this->haciendaSimulada();
        // Un cálculo que se desvía un centavo: el documento ya no cuadra con lo cobrado.
        Dte::saving(function (Dte $dte) {
            if ($dte->esEditable() && $dte->isDirty('total_pagar') && Dinero::comparar($dte->total_pagar, '0') > 0) {
                $dte->total_pagar = Dinero::redondear(Dinero::sumar($dte->total_pagar, '0.01'));
            }
        });
        $pedido = $this->pedido();

        $this->pasada();

        $pedido->refresh();
        $this->assertSame(EstadoPedidoWeb::ParaRevisar, $pedido->estado);
        $this->assertStringContainsString('total', $pedido->motivo_revision);
        $this->assertNull($pedido->dte_id);
        $this->assertDatabaseCount('dtes', 0);
        $this->assertDatabaseCount('dte_lineas', 0);
        Http::assertNothingSent();
    }

    // ---------- 3. idempotencia ----------

    public function test_dos_pasadas_no_crean_dos_dte_ni_repiten_correo_ni_aviso(): void
    {
        $this->haciendaSimulada();
        $pedido = $this->pedido();

        $this->assertSame(['facturados' => 1, 'facturando' => 0, 'facturas_para_revisar' => 0, 'facturas_con_error' => 0], $this->pasada());
        $this->pasada();

        $this->assertSame(EstadoPedidoWeb::Facturado, $pedido->refresh()->estado);
        $this->assertDatabaseCount('dtes', 1);
        $this->assertSame(1, (int) Correlativo::findOrFail($this->correlativoId)->ultimo_numero);
        $this->assertSame(1, DteEnvio::count());
        Queue::assertPushed(EnviarDteCorreo::class, 1);
        $this->assertCount(1, $this->avisosDeFactura());
    }

    public function test_si_se_cae_despues_del_borrador_la_pasada_siguiente_retoma_el_mismo_documento(): void
    {
        $this->haciendaSimulada();
        // Sin correlativo la generación falla después de guardar el borrador.
        Correlativo::whereKey($this->correlativoId)->update(['activo' => false]);
        $pedido = $this->pedido();
        $registro = $this->registro();

        $this->assertSame(['facturados' => 0, 'facturando' => 1, 'facturas_para_revisar' => 0, 'facturas_con_error' => 0], $this->pasada());
        $this->assertStringContainsString('GeneracionException', implode("\n", $registro->getArrayCopy()), 'Queda rastro de por qué no se emitió.');
        $this->assertRegistroSinDatosDelCliente($registro);

        $pedido->refresh();
        $this->assertSame(EstadoPedidoWeb::Facturando, $pedido->estado);
        $dteId = $pedido->dte_id;
        $this->assertNotNull($dteId);
        $this->assertSame(EstadoDte::Borrador, Dte::findOrFail($dteId)->estado);

        Correlativo::whereKey($this->correlativoId)->update(['activo' => true]);
        // Un minuto después todavía no reintenta: espera MINUTOS_ENTRE_INTENTOS.
        $this->travel(1)->minutes();
        $this->pasada();
        $this->assertSame(EstadoPedidoWeb::Facturando, $pedido->refresh()->estado);
        $this->travel(FacturarPedidoWeb::MINUTOS_ENTRE_INTENTOS)->minutes();
        $this->pasada();

        $pedido->refresh();
        $this->assertSame(EstadoPedidoWeb::Facturado, $pedido->estado);
        $this->assertSame($dteId, $pedido->dte_id);
        $this->assertDatabaseCount('dtes', 1);
        $this->assertSame(EstadoDte::Aceptado, Dte::findOrFail($dteId)->estado);
    }

    public function test_con_el_candado_del_documento_tomado_no_hace_nada(): void
    {
        // Primera pasada con la transmisión cerrada por el entorno: queda firmado.
        config(['dte.firma.enabled' => true, 'dte.firma.mock' => true]);
        $pedido = $this->pedido();
        $this->pasada();
        $dte = Dte::findOrFail($pedido->refresh()->dte_id);
        $this->assertSame(EstadoDte::Firmado, $dte->estado);

        // Pasada la espera entre intentos, otro proceso está emitiendo este documento
        // (el candado se toma después de viajar en el tiempo, si no vencería).
        $this->travel(FacturarPedidoWeb::MINUTOS_ENTRE_INTENTOS + 1)->minutes();
        $candado = Cache::lock(CandadoEmisionDte::clave($dte), 60);
        $this->assertTrue($candado->get());
        config(['dte.transmision.mock' => true]);

        $this->pasada();

        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
        $this->assertSame(EstadoPedidoWeb::Facturando, $pedido->refresh()->estado);
        $this->assertSame(0, DteEnvio::count());
        Http::assertNothingSent();

        $candado->release();
        $this->travel(FacturarPedidoWeb::MINUTOS_ENTRE_INTENTOS + 1)->minutes();
        $this->pasada();

        $this->assertSame(EstadoPedidoWeb::Facturado, $pedido->refresh()->estado);
        $this->assertSame(EstadoDte::Aceptado, $dte->refresh()->estado);
    }

    // ---------- 4. rechazo ----------

    public function test_rechazo_de_hacienda_deja_el_pedido_para_revisar_con_el_motivo(): void
    {
        $this->apitestAbierto();
        $this->tienda(hacienda: [self::RECEPCION => Http::response([
            'estado' => 'RECHAZADO', 'descripcionMsg' => 'Motivo inventado de rechazo',
            'observaciones' => ['Observación inventada uno', 'Observación inventada dos'],
        ], 400)]);
        $pedido = $this->pedido();

        $this->assertSame(['facturados' => 0, 'facturando' => 0, 'facturas_para_revisar' => 1, 'facturas_con_error' => 0], $this->pasada());
        $this->pasada();

        $pedido->refresh();
        $this->assertSame(EstadoPedidoWeb::ParaRevisar, $pedido->estado);
        $this->assertStringContainsString('Motivo inventado de rechazo', $pedido->motivo_revision);
        $this->assertStringContainsString('Observación inventada dos', $pedido->motivo_revision);
        $this->assertStringNotContainsString("\n", $pedido->motivo_revision);
        $this->assertSame(EstadoDte::Rechazado, Dte::findOrFail($pedido->dte_id)->estado);
        $this->assertCount(1, $this->envios(), 'Un rechazo no se retransmite.');
        $this->assertSame(0, DteEnvio::count());
        $this->assertCount(0, $this->avisosDeFactura());
        $this->assertSame('rechazado', Activity::where('log_name', 'pedido_web')->get()->last()->properties['resultado']);
    }

    // ---------- 5. transmisión bloqueada o con fallo ----------

    public function test_transmision_bloqueada_por_los_candados_del_entorno_sigue_facturando_y_reintenta(): void
    {
        // phpunit.xml deja la transmisión cerrada: firma simulada sí, envío no.
        config(['dte.firma.enabled' => true, 'dte.firma.mock' => true]);
        $pedido = $this->pedido();

        $this->assertSame(['facturados' => 0, 'facturando' => 1, 'facturas_para_revisar' => 0, 'facturas_con_error' => 0], $this->pasada());

        $this->assertSame(EstadoPedidoWeb::Facturando, $pedido->refresh()->estado);
        $this->assertSame(EstadoDte::Firmado, Dte::findOrFail($pedido->dte_id)->estado);
        $this->assertSame(0, DteEnvio::count());
        Http::assertNothingSent();

        config(['dte.transmision.mock' => true]);
        $this->travel(FacturarPedidoWeb::MINUTOS_ENTRE_INTENTOS + 1)->minutes();
        $this->pasada();

        $this->assertSame(EstadoPedidoWeb::Facturado, $pedido->refresh()->estado);
        $this->assertDatabaseCount('dtes', 1);
    }

    public function test_fallo_de_transmision_sigue_facturando_y_la_pasada_siguiente_reintenta(): void
    {
        $this->apitestAbierto();
        $this->tienda(hacienda: [self::RECEPCION => Http::response('Error inventado', 500)]);
        $pedido = $this->pedido();
        $registro = $this->registro();

        $this->pasada();

        $this->assertRegistroSinDatosDelCliente($registro);
        $this->assertSame(EstadoPedidoWeb::Facturando, $pedido->refresh()->estado);
        $this->assertSame(EstadoDte::Firmado, Dte::findOrFail($pedido->dte_id)->estado);
        $this->assertCount(0, $this->avisosDeFactura());

        // Antes de MINUTOS_ENTRE_INTENTOS no se le vuelve a mandar a Hacienda.
        config(['dte.transmision.mock' => true]);
        $enviosAntes = count($this->envios());
        $this->travel(1)->minutes();
        $this->pasada();
        $this->assertSame(EstadoPedidoWeb::Facturando, $pedido->refresh()->estado);
        $this->assertCount($enviosAntes, $this->envios(), 'Un minuto después no se insiste.');
        $this->travel(FacturarPedidoWeb::MINUTOS_ENTRE_INTENTOS)->minutes();
        $this->pasada();

        $this->assertSame(EstadoPedidoWeb::Facturado, $pedido->refresh()->estado);
        $this->assertDatabaseCount('dtes', 1);
    }

    // ---------- 6. aviso a la página ----------

    public function test_aviso_a_la_pagina_fallido_se_reintenta_sin_repetir_factura_ni_correo(): void
    {
        $this->haciendaSimulada();
        $this->tienda(estado: 500);
        $pedido = $this->pedido();

        $this->pasada();

        $pedido->refresh();
        $this->assertSame(EstadoPedidoWeb::Facturado, $pedido->estado);
        $this->assertNull($pedido->informado_factura_en);
        $this->assertNotNull($pedido->factura_correo_en);
        $this->assertCount(1, $this->avisosDeFactura());

        $this->tienda();
        $this->pasada();

        $this->assertNotNull($pedido->refresh()->informado_factura_en);
        $this->assertCount(1, $this->avisosDeFactura());
        $this->assertDatabaseCount('dtes', 1);
        $this->assertSame(1, DteEnvio::count());
    }

    public function test_aviso_preparando_pendiente_se_reintenta_aunque_el_pedido_ya_este_facturado(): void
    {
        $this->haciendaSimulada();
        $this->tienda(estado: 500, pedidos: [$this->datosTienda()]);
        $this->artisan('tienda:traer-pedidos', ['--aplicar' => true])->assertSuccessful();
        $web = PedidoWeb::firstOrFail();
        $this->assertSame(EstadoPedidoWeb::Facturado, $web->estado);
        $this->assertNull($web->informado_preparando_en);

        $this->tienda();
        $this->artisan('tienda:traer-pedidos', ['--aplicar' => true])->assertSuccessful();

        $web->refresh();
        $this->assertNotNull($web->informado_preparando_en);
        $this->assertNotNull($web->informado_factura_en);
        Http::assertSent(fn (Request $r) => ($r->data()['estado'] ?? null) === 'preparando');
    }

    // ---------- dónde corre ----------

    public function test_sin_aplicar_solo_cuenta_sin_emitir_escribir_ni_llamar(): void
    {
        $this->haciendaSimulada();
        $pedido = $this->pedido();
        $antes = Activity::count();

        $this->artisan('tienda:traer-pedidos')->expectsOutputToContain('por_facturar=1')->assertSuccessful();

        $this->assertSame(EstadoPedidoWeb::Importado, $pedido->refresh()->estado);
        $this->assertDatabaseCount('dtes', 0);
        $this->assertSame($antes, Activity::count());
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_la_tarea_corre_en_segundo_plano_sin_solaparse(): void
    {
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'tienda:traer-pedidos'));

        $this->assertTrue($evento->runInBackground);
        $this->assertTrue($evento->withoutOverlapping);
        $this->assertSame(5, $evento->expiresAt);
    }

    public function test_migracion_va_y_vuelve_y_aborta_si_perderia_facturas(): void
    {
        $archivo = database_path('migrations/2026_11_16_200000_factura_automatica_tienda_web.php');
        (require $archivo)->down();
        $this->assertFalse(Schema::hasColumn('dtes', 'receptor_nombre'));
        $this->assertFalse(Schema::hasColumn('pedidos_web', 'dte_id'));
        (require $archivo)->up();
        $this->realinearTransaccionTrasDdl();
        $this->assertTrue(Schema::hasColumns('dtes', ['receptor_nombre', 'receptor_correo']));
        $this->assertTrue(Schema::hasColumns('pedidos_web', ['dte_id', 'factura_correo_en', 'informado_factura_en']));
        $this->assertTrue(collect(Schema::getForeignKeys('pedidos_web'))->contains(fn ($fk) => $fk['columns'] === ['dte_id'] && $fk['foreign_table'] === 'dtes'));

        $this->haciendaSimulada();
        $this->pedido();
        $this->pasada();
        try {
            (require $archivo)->down();
            $this->fail('La reversión con facturas debió abortar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('facturad', $e->getMessage());
        }
        $this->assertNotNull(PedidoWeb::firstOrFail()->dte_id);
    }
}
