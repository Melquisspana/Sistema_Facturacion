<?php

namespace Tests\Feature\TiendaWeb;

use App\Ajustes\Excepciones\ValorAjusteInvalidoException;
use App\Ajustes\Integraciones\ConfiguracionTiendaWeb;
use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoPedidoWeb;
use App\Enums\OrigenPedidoSala;
use App\Facades\Ajustes;
use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\PedidoSala;
use App\Models\PedidoSalaLinea;
use App\Models\PedidoWeb;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Policies\PedidoSalaPolicy;
use App\Services\Rutas\FacturarPedido;
use App\Services\Rutas\PedidosFabrica;
use App\Services\Rutas\PedidosSala;
use App\Services\Rutas\ResumenDiarioPedidos;
use App\Services\TiendaWeb\ImportarPedidosWeb;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Datos inventados; HTTP falso y únicamente SQLite en memoria. */
class ImportarPedidosWebTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-inventado-solo-para-pruebas-123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 09:00:00');
        Ajustes::guardarComoSistema('tienda.url', 'https://tienda.example.test');
        Ajustes::guardarComoSistema('tienda.secreto', self::SECRETO);
        UnidadMedida::firstOrCreate(['codigo' => '59'], ['nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        Producto::factory()->create(['codigo' => 'WEB01', 'nombre' => 'Dulce inventado', 'codigo_barra' => '740000000001']);
    }

    private function datos(int $id = 1, string $tipo = 'ruta'): array
    {
        return [
            'id' => $id, 'codigo' => 'LN-TEST-0000-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'idioma' => 'es', 'creado' => '2026-11-15T22:00:00Z', 'pagado' => '2026-11-16T02:00:00Z',
            'alerta' => '', 'tam' => 'pequena', 'unidades' => 3, 'cajitas' => 1, 'total' => 345, 'moneda' => 'USD',
            'cliente' => ['nombre' => 'Elena Inventada', 'correo' => 'elena@example.test', 'telefono' => '70000000'],
            'entrega' => ['tipo' => $tipo, 'departamento' => '04', 'municipio' => 'Villa Ficticia', 'direccion' => 'Calle inventada 123', 'notas' => 'Portón azul inventado'],
            'cajitas_detalle' => [['clase' => 'variada', 'cantidad' => 1]],
            'regalo' => ['nombre' => 'Persona Ficticia', 'telefono' => '70000001', 'mensaje' => 'Regalo inventado', 'sin_precios' => true],
            'lineas' => [['producto' => 'dulce', 'nombre' => 'Dulce inventado', 'barra' => '740000000001', 'cantidad' => 3, 'precio' => 115]],
            'pasarela' => 'prueba', 'referencia' => 'pago-inventado',
        ];
    }

    private function remoto(array $pedidos, int $estado = 200, string $error = 'estado'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/pedidos-a-revisar*' => Http::response(['pedidos' => []]),
            '*/pedidos-pagados*' => Http::response(['pedidos' => $pedidos]),
            '*/estado' => Http::response(['error' => $error, 'actual' => 'entregado'], $estado),
            '*/limpieza' => Http::response([], 200),
        ]);
    }

    private function pasar(bool $aplicar = true): array
    {
        return app(ImportarPedidosWeb::class)->ejecutar($aplicar);
    }

    public function test_importa_ruta_y_recoger_con_nombre_fecha_lineas_zona_y_auditoria(): void
    {
        $depto = Departamento::firstOrCreate(['codigo' => '04'], ['nombre' => 'Departamento inventado', 'activo' => true]);
        $distrito = Distrito::create(['departamento_id' => $depto->id, 'codigo' => '99', 'municipio' => 'Municipio inventado', 'nombre' => 'Villa Fictícia', 'activo' => true]);
        $ruta = Ruta::create(['nombre' => 'Zona inventada', 'activa' => true]);
        $ruta->coberturas()->create(['distrito_id' => $distrito->id]);
        $rutaWeb = $this->datos();
        $rutaWeb['entrega']['municipio'] = 'VILLA FICTICIA';
        $this->remoto([$rutaWeb, $this->datos(2, 'recoger')]);
        $this->pasar();

        $web = PedidoWeb::where('web_id', 1)->firstOrFail();
        $pedido = $web->pedidoSala;
        $this->assertSame(OrigenPedidoSala::Web, $pedido->origen);
        $this->assertSame(EstadoPedidoSala::Registrado, $pedido->estado);
        $this->assertSame('2026-11-15', $pedido->fecha->toDateString());
        $this->assertSame($ruta->id, $pedido->ruta_id);
        $this->assertSame('Zona inventada', $pedido->zonaNombre());
        $this->assertSame('Pedido web · Elena I.', $pedido->nombreSala());
        $this->assertNull($pedido->registrado_por);
        $this->assertNull($pedido->sacado_por_id);
        $this->assertNull($pedido->cliente_sucursal_id);
        $this->assertSame('3.0000', $pedido->lineas->sole()->cantidad);
        $recoger = PedidoWeb::where('web_id', 2)->firstOrFail()->pedidoSala;
        $this->assertNull($recoger->ruta_id);
        $this->assertSame('Para recoger', $recoger->zonaNombre());
        $otro = app(PedidosFabrica::class)->otrosPorMandar($pedido)->sole();
        $this->assertFalse($otro->esTiendaNueva());
        $this->assertSame('Pedido web · Elena I.', $otro->nombreSala());
        $this->assertNotNull($web->informado_preparando_en);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/estado') && $r['estado'] === 'preparando' && $r->hasHeader('Authorization', 'Bearer '.self::SECRETO));
        $actividad = Activity::where('log_name', 'pedido_web')->firstOrFail();
        $this->assertNull($actividad->causer_id);
        // MySQL reordena las claves de una columna JSON: se compara sin importar el orden.
        $this->assertSame(
            collect(['web_id' => 1, 'codigo' => $web->codigo, 'origen' => 'tienda_web'])->sortKeys()->all(),
            $actividad->properties->sortKeys()->all(),
        );
    }

    public function test_dos_pasadas_no_duplican_y_suman_lineas_del_mismo_producto(): void
    {
        $datos = $this->datos();
        $datos['lineas'][0]['cantidad'] = 1;
        $datos['lineas'][] = array_replace($datos['lineas'][0], ['cantidad' => 2]);
        $this->remoto([$datos]);
        $this->pasar();
        $this->pasar();
        $this->assertDatabaseCount('pedidos_web', 1);
        $this->assertDatabaseCount('pedidos_sala', 1);
        $this->assertDatabaseCount('pedido_sala_lineas', 1);
        $this->assertSame('3.0000', PedidoSala::firstOrFail()->lineas->sole()->cantidad);
        $this->assertSame(1, Activity::where('log_name', 'pedido_web')->count());
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/estado')));
        $gets = Http::recorded(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/pedidos-pagados'));
        $this->assertCount(2, $gets);
        foreach ($gets as [$request]) {
            $this->assertSame(0, $request['despues']);
            $this->assertStringNotContainsString('demo', $request->url());
        }
    }

    public function test_aviso_fallido_se_reintenta_y_409_estado_cuenta_como_hecho(): void
    {
        $this->remoto([$this->datos()], 500);
        $this->pasar();
        $this->assertNull(PedidoWeb::firstOrFail()->informado_preparando_en);
        $this->remoto([$this->datos()], 409);
        $this->pasar();
        $this->assertNotNull(PedidoWeb::firstOrFail()->informado_preparando_en);
        $this->assertDatabaseCount('pedidos_sala', 1);
        $this->assertDatabaseCount('pedido_sala_lineas', 1);
    }

    public function test_recupera_aviso_aunque_la_pagina_ya_no_devuelva_el_pedido(): void
    {
        $this->remoto([$this->datos()], 500);
        $this->pasar();
        $this->remoto([], 409);
        $this->pasar();
        $this->assertNotNull(PedidoWeb::firstOrFail()->informado_preparando_en);
        $this->assertDatabaseCount('pedidos_sala', 1);
    }

    public function test_409_distinto_de_estado_no_se_marca_informado(): void
    {
        $this->remoto([$this->datos()], 409, 'factura');
        $this->pasar();
        $this->assertNull(PedidoWeb::firstOrFail()->informado_preparando_en);
    }

    public function test_pedido_con_formato_raro_se_salta_y_no_frena_a_los_siguientes(): void
    {
        // Si cortara la pasada, como siempre se lee desde el principio, el pedido 2 nunca entraría.
        $raro = $this->datos();
        $raro['cliente']['correo'] = 'sin-arroba';
        $this->remoto([$raro, $this->datos(2)]);
        $conteo = $this->pasar();

        $this->assertSame(1, $conteo['invalidos']);
        $this->assertSame(1, $conteo['importados']);
        $this->assertNull(PedidoWeb::where('web_id', 1)->first());
        $this->assertNotNull(PedidoWeb::where('web_id', 2)->firstOrFail()->pedido_sala_id);
    }

    public function test_id_que_no_avanza_corta_la_pasada_sin_importar_nada(): void
    {
        $this->remoto([$this->datos(2), $this->datos(2)]);
        try {
            $this->pasar();
            $this->fail('Debía cortar la pasada');
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('pedidos_web', 1);
    }

    public function test_producto_desconocido_queda_para_revisar_sin_pedido_ni_aviso(): void
    {
        $datos = $this->datos();
        $datos['lineas'][0]['barra'] = 'NO-EXISTE';
        $this->revision($datos);
    }

    public function test_suma_incorrecta_queda_para_revisar(): void
    {
        $datos = $this->datos();
        $datos['unidades'] = 4;
        $this->revision($datos);
    }

    public function test_cantidades_fraccionarias_cero_negativas_y_texto_no_se_importan(): void
    {
        foreach ([1.5, 0, -1, '3'] as $i => $cantidad) {
            $datos = $this->datos($i + 1);
            $datos['lineas'][0]['cantidad'] = $cantidad;
            $this->remoto([$datos]);
            $this->pasar();
        }
        $this->assertSame(4, PedidoWeb::where('estado', EstadoPedidoWeb::ParaRevisar)->count());
        $this->assertDatabaseCount('pedidos_sala', 0);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/estado'));
    }

    private function revision(array $datos): void
    {
        $this->remoto([$datos]);
        $this->pasar();
        $this->pasar();
        $web = PedidoWeb::firstOrFail();
        $this->assertSame(EstadoPedidoWeb::ParaRevisar, $web->estado);
        $this->assertNotEmpty($web->motivo_revision);
        $this->assertNull($web->pedido_sala_id);
        $this->assertDatabaseCount('pedidos_web', 1);
        $this->assertDatabaseCount('pedidos_sala', 0);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/estado'));
    }

    public function test_distrito_desconocido_usa_cobertura_departamental(): void
    {
        $depto = Departamento::firstOrCreate(['codigo' => '04'], ['nombre' => 'Inventado', 'activo' => true]);
        $ruta = Ruta::create(['nombre' => 'Zona inventada', 'activa' => true]);
        $ruta->coberturas()->create(['departamento_id' => $depto->id]);
        $this->remoto([$this->datos()]);
        $this->pasar();
        $this->assertSame(EstadoPedidoWeb::Importado, PedidoWeb::firstOrFail()->estado);
        $this->assertSame($ruta->id, PedidoSala::firstOrFail()->ruta_id);
        $this->assertSame('Zona inventada', PedidoSala::firstOrFail()->zonaNombre());
    }

    public function test_sin_aplicar_cuenta_sin_escrituras_ni_post(): void
    {
        $this->remoto([$this->datos()]);
        $antes = Activity::count();
        $this->artisan('tienda:traer-pedidos')->expectsOutputToContain('Simulación: importados=1')->assertSuccessful();
        $this->assertDatabaseCount('pedidos_web', 0);
        $this->assertDatabaseCount('pedidos_sala', 0);
        $this->assertSame($antes, Activity::count());
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_programador_cada_minuto_no_corre_con_interruptor_apagado(): void
    {
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'tienda:traer-pedidos'));
        $this->assertNotNull($evento);
        $this->assertSame('* * * * *', $evento->expression);
        $this->assertTrue($evento->withoutOverlapping);
        $this->assertFalse($evento->filtersPass($this->app));
        Ajustes::guardarComoSistema('tienda.pedidos.automatica', true);
        $this->assertTrue($evento->filtersPass($this->app));
    }

    public function test_cifrado_en_base_y_secreto_ausente_en_pantalla_auditoria_y_excepciones(): void
    {
        $this->remoto([$this->datos()]);
        $this->pasar();
        $fila = DB::table('pedidos_web')->first();
        foreach (['cliente_telefono' => '70000000', 'direccion' => 'Calle inventada 123', 'notas' => 'Portón azul inventado'] as $campo => $valor) {
            $this->assertNotSame($valor, $fila->$campo);
            $this->assertSame($valor, Crypt::decryptString($fila->$campo));
        }
        $this->assertStringNotContainsString('Persona Ficticia', $fila->regalo);
        $this->assertSame('Persona Ficticia', PedidoWeb::firstOrFail()->regalo['nombre']);
        $this->assertNull(Ajustes::estadoParaPantalla('tienda.secreto')->valor);
        $secreto = DB::table('ajustes_sistema')->where('clave', 'tienda.secreto')->first();
        $this->assertNotSame(self::SECRETO, $secreto->valor);
        $this->assertStringNotContainsString(self::SECRETO, Activity::all()->toJson());

        Log::spy();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \RuntimeException('Error remoto '.self::SECRETO.' elena@example.test'));
        try {
            $this->pasar();
            $this->fail('Debió fallar la lectura.');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(self::SECRETO, (string) $e);
            $this->assertStringNotContainsString('elena@example.test', (string) $e);
            $this->assertNull($e->getPrevious());
        }
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        $this->artisan('tienda:traer-pedidos', ['--aplicar' => true])->assertFailed();
    }

    public function test_web_no_es_tienda_nueva_no_se_enlaza_y_no_se_ofrece_ccf(): void
    {
        $this->remoto([$this->datos()]);
        $this->pasar();
        $pedido = PedidoSala::firstOrFail();
        $this->assertFalse($pedido->esTiendaNueva());
        $this->assertTrue(PedidosSala::mismaTiendaNueva($pedido)->isEmpty());
        $this->assertSame([], PedidosSala::cuantosMasDeLaTienda([$pedido]));
        $usuario = User::factory()->create(['activo' => true])->assignRole('facturacion');
        $pedido->envios()->create(['numero' => 1, 'clave' => 'envio-web-inventado', 'fecha' => '2026-11-16', 'registrado_por' => $usuario->id]);
        $this->assertFalse(app(PedidoSalaPolicy::class)->enlazarSala($usuario, $pedido));
        $this->assertContains(FacturarPedido::PEDIDO_WEB, app(FacturarPedido::class)->propuesta($pedido)['problemas']);
        $this->actingAs($usuario)->get(route('facturacion.llego-de-fabrica'))->assertOk()->assertViewHas('pedidos', fn ($p) => $p->isEmpty());
        $this->get(route('facturacion.llego-de-fabrica', ['sin_facturar' => 1]))->assertOk()->assertViewHas('pedidos', fn ($p) => $p->isEmpty());
        $this->get(route('facturacion.llego-de-fabrica', ['fecha' => '2026-11-15']))->assertOk()->assertViewHas('otrosDiasSinFacturar', 0);
        $contenido = app(ResumenDiarioPedidos::class)->contenido(Carbon::parse('2026-11-15'), now());
        $this->assertArrayHasKey('Pedido web · Elena I. (web)', $contenido['salas']);
        $this->assertStringNotContainsString('tienda nueva', json_encode($contenido));
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(FacturarPedido::PEDIDO_WEB);
        app(FacturarPedido::class)->crear($pedido, [], $usuario);
    }

    public function test_pagina_cien_pedidos_y_reinicia_el_cursor_en_cada_pasada(): void
    {
        Http::fake(function (Request $r) {
            $this->assertArrayNotHasKey('demo', $r->data());
            if ($r->method() !== 'GET') {
                return Http::response([], 200);
            }
            if (str_contains($r->url(), '/pedidos-a-revisar')) {
                return Http::response(['pedidos' => []]);
            }
            $this->assertSame(100, $r['limite']);

            return Http::response(['pedidos' => $r['despues'] === 0
                ? array_map(fn ($id) => $this->datos($id), range(1, 100))
                : [$this->datos(101)]]);
        });
        $this->assertSame(101, $this->pasar()['importados']);
        $this->assertSame(101, $this->pasar()['existentes']);
        $this->assertDatabaseCount('pedidos_sala', 101);
        $this->assertSame([0, 100, 0, 100], Http::recorded(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/pedidos-pagados'))->map(fn ($par) => $par[0]['despues'])->values()->all());
        $this->assertCount(2, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/limpieza')));
    }

    public function test_avisa_solo_despues_de_confirmar_la_transaccion_local(): void
    {
        Http::fake(function (Request $r) {
            if ($r->method() === 'GET' && str_contains($r->url(), '/pedidos-pagados')) {
                return Http::response(['pedidos' => [$this->datos()]]);
            }
            if (str_ends_with($r->url(), '/estado')) {
                // RefreshDatabase conserva una transacción exterior; la del servicio ya terminó.
                $this->assertSame(1, DB::transactionLevel());
                $web = PedidoWeb::firstOrFail();
                $this->assertNotNull($web->pedido_sala_id);
                $this->assertSame(1, $web->pedidoSala->lineas()->count());
            }

            return Http::response([], 200);
        });
        $this->pasar();
        $this->assertNotNull(PedidoWeb::firstOrFail()->informado_preparando_en);
    }

    public function test_error_al_guardar_lineas_revierte_todo_y_no_avisa(): void
    {
        $this->remoto([$this->datos()]);
        PedidoSalaLinea::creating(fn () => throw new \RuntimeException('Falla inventada'));
        $this->artisan('tienda:traer-pedidos', ['--aplicar' => true])->assertFailed();
        $this->assertDatabaseCount('pedidos_web', 0);
        $this->assertDatabaseCount('pedidos_sala', 0);
        $this->assertDatabaseCount('pedido_sala_lineas', 0);
        $this->assertSame(0, Activity::where('log_name', 'pedido_web')->count());
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/estado'));
    }

    public function test_configuracion_estado_url_y_secreto_sin_exposicion_con_permisos(): void
    {
        $usuario = User::factory()->create(['activo' => true])->assignRole('administrador');
        $this->actingAs($usuario)->get(route('configuracion.integraciones.tienda'))
            ->assertOk()->assertSee('Configuración completa')->assertSee('https://tienda.example.test')->assertDontSee(self::SECRETO);
        $this->get(route('configuracion.integraciones.tienda.secreto.edit'))->assertOk()->assertDontSee(self::SECRETO);
        $this->put(route('configuracion.integraciones.tienda.update'), ['url' => 'http://inseguro.example.test'])->assertSessionHasErrors('url');
        $this->put(route('configuracion.integraciones.tienda.secreto.update'), ['password' => 'nuevo-secreto-inventado-123456789012', 'confirmacion' => '1'])->assertRedirect();
        $this->assertSame('nuevo-secreto-inventado-123456789012', app(ConfiguracionTiendaWeb::class)->secreto());
        $this->actingAs(User::factory()->create(['activo' => true])->assignRole('vendedor'))
            ->get(route('configuracion.integraciones.tienda'))->assertForbidden();
        $this->put(route('configuracion.integraciones.tienda.secreto.update'), ['password' => 'prohibido', 'confirmacion' => '1'])->assertForbidden();
    }

    public function test_catalogo_rechaza_url_sin_https(): void
    {
        $this->expectException(ValorAjusteInvalidoException::class);
        Ajustes::guardarComoSistema('tienda.url', 'http://tienda.example.test');
    }

    public function test_migraciones_van_y_vuelven_sin_perder_fk_y_abortan_con_datos(): void
    {
        $archivos = glob(database_path('migrations/2026_11_16_10000*.php'));
        $this->assertCount(3, $archivos);
        foreach (array_reverse($archivos) as $archivo) {
            (require $archivo)->down();
        }
        $this->assertFalse(Schema::hasTable('pedidos_web'));
        $this->assertFalse(Schema::hasColumn('pedidos_sala', 'origen'));
        foreach ($archivos as $archivo) {
            (require $archivo)->up();
        }
        $this->assertTrue(Schema::hasTable('pedidos_web'));
        $this->assertTrue(collect(Schema::getForeignKeys('pedidos_sala'))->contains(fn ($fk) => $fk['columns'] === ['registrado_por'] && $fk['foreign_table'] === 'users'));
        $this->remoto([$this->datos()]);
        $this->pasar();
        foreach ($archivos as $archivo) {
            try {
                (require $archivo)->down();
                $this->fail('La reversión con datos debió abortar.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Hay pedidos', $e->getMessage());
            }
        }
        $this->assertDatabaseCount('pedidos_web', 1);
        $this->assertDatabaseCount('pedidos_sala', 1);
    }

    public function test_limpieza_fallida_no_deshace_importacion(): void
    {
        Http::fake([
            '*/pedidos-a-revisar*' => Http::response(['pedidos' => []]),
            '*/pedidos-pagados*' => Http::response(['pedidos' => [$this->datos()]]),
            '*/estado' => Http::response([], 200),
            '*/limpieza' => Http::response([], 500),
        ]);
        $this->artisan('tienda:traer-pedidos', ['--aplicar' => true])->assertSuccessful();
        $this->assertDatabaseCount('pedidos_sala', 1);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/limpieza') && $r->body() === '');
    }

    public function test_pago_doble_tardio_se_anota_sin_cambiar_estado_y_consulta_fallida_no_frena(): void
    {
        $this->remoto([$this->datos()]);
        $this->pasar();
        $web = PedidoWeb::firstOrFail();
        $web->update(['estado' => EstadoPedidoWeb::Facturado]);
        Http::swap(new Factory);
        Http::fake([
            '*/pedidos-pagados*' => Http::response(['pedidos' => []]),
            '*/pedidos-a-revisar*' => Http::sequence()->push([], 503)->push(['pedidos' => [
                ['id' => 1, 'alerta' => 'pago_doble'], ['id' => 99, 'alerta' => 'pago_doble'],
            ]])->push(['pedidos' => [['id' => 1, 'alerta' => 'pago_doble']]]),
            '*/limpieza' => Http::response(),
        ]);
        $this->pasar();
        $this->assertSame('', $web->refresh()->alerta);
        $this->pasar();
        $actualizado = $web->refresh()->updated_at;
        $this->travel(1)->minutes();
        $this->pasar();
        $this->assertSame('pago_doble', $web->refresh()->alerta);
        $this->assertTrue($actualizado->eq($web->updated_at));
        $this->assertSame(EstadoPedidoWeb::Facturado, $web->estado);
        $this->assertDatabaseCount('pedidos_web', 1);
    }

    public function test_preparando_rechazado_no_reintenta_ni_cambia_estado(): void
    {
        foreach ([409, 422, 404] as $i => $status) {
            $this->remoto([$this->datos($i + 1)], $status);
            if ($status === 409) {
                Http::swap(new Factory);
                Http::fake([
                    '*/pedidos-pagados*' => Http::response(['pedidos' => [$this->datos($i + 1)]]),
                    '*/pedidos-a-revisar*' => Http::response(['pedidos' => []]),
                    '*/estado' => Http::response(['error' => 'estado'], 409),
                    '*/limpieza' => Http::response(),
                ]);
            }
            $this->pasar();
            $this->pasar();
            $web = PedidoWeb::where('web_id', $i + 1)->firstOrFail();
            $this->assertNull($web->informado_preparando_en);
            $this->assertSame(EstadoPedidoWeb::Importado, $web->estado);
            $this->assertStringContainsString('La página rechazó el aviso: preparando (HTTP '.$status.')', $web->motivo_revision);
            $this->assertCount(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/estado')));
        }
    }

    public function test_nombre_web_salta_particulas_y_usa_mayuscula(): void
    {
        $this->remoto([$this->datos()]);
        $this->pasar();
        $web = PedidoWeb::firstOrFail();
        foreach (['Alba de la nube' => 'Alba N.', 'Alba del río' => 'Alba R.', 'Alba las los y soles' => 'Alba S.', 'Alba' => 'Alba'] as $nombre => $visible) {
            $web->update(['cliente_nombre' => $nombre]);
            $this->assertSame('Pedido web · '.$visible, $web->pedidoSala->fresh()->nombreSala());
        }
    }
}
