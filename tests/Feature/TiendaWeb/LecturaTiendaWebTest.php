<?php

namespace Tests\Feature\TiendaWeb;

use App\Enums\EstadoPedidoWeb;
use App\Facades\Ajustes;
use App\Models\PedidoWeb;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Services\TiendaWeb\ImportarPedidosWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Lo que la lectura de la tienda deja anotado para los avisos: cómo terminó, los
 * pedidos pagados que no se pudieron leer, los que la página dejó en revisión y la
 * lista de errores de la página. Datos inventados; HTTP falso.
 */
class LecturaTiendaWebTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-inventado-solo-para-pruebas-123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-19 15:00:00');
        Ajustes::guardarComoSistema('tienda.url', 'https://tienda.example.test');
        Ajustes::guardarComoSistema('tienda.secreto', self::SECRETO);
        UnidadMedida::firstOrCreate(['codigo' => '59'], ['nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        Producto::factory()->create(['codigo' => 'WEB01', 'nombre' => 'Dulce inventado', 'codigo_barra' => '740000000001']);
    }

    private function datos(int $id): array
    {
        return [
            'id' => $id, 'codigo' => 'LN-TEST-0000-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'idioma' => 'es', 'creado' => '2026-11-18T22:00:00Z', 'pagado' => '2026-11-19T02:00:00Z',
            'alerta' => '', 'unidades' => 3, 'total' => 345, 'moneda' => 'USD',
            'cliente' => ['nombre' => 'Elena Inventada', 'correo' => 'elena@example.test', 'telefono' => '70000000'],
            'entrega' => ['tipo' => 'recoger', 'departamento' => null, 'municipio' => null, 'direccion' => null, 'notas' => null],
            'cajitas_detalle' => [], 'regalo' => null,
            'lineas' => [['barra' => '740000000001', 'cantidad' => 3]],
            'pasarela' => 'wompi', 'referencia' => 'pago-inventado',
        ];
    }

    /** @param  array<string, mixed>  $extra  respuestas que reemplazan a las de siempre */
    private function remoto(array $extra = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($extra + [
            '*/pedidos-pagados*' => Http::response(['pedidos' => []]),
            '*/pedidos-a-revisar*' => Http::response(['pedidos' => []]),
            '*/errores' => Http::response(['cobra' => true, 'errores' => []]),
            '*/estado' => Http::response([], 200),
            '*/limpieza' => Http::response([], 200),
        ]);
    }

    private function pasar(bool $aplicar = true): bool
    {
        try {
            app(ImportarPedidosWeb::class)->ejecutar($aplicar);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function test_una_lectura_buena_guarda_la_hora_y_los_pedidos_que_no_se_pudieron_leer(): void
    {
        $raros = array_map(fn (int $id) => ['id' => $id, 'codigo' => 'roto'], [3, 5, 7, 9]);
        $this->remoto(['*/pedidos-pagados*' => Http::response(['pedidos' => [$this->datos(1), ...$raros]])]);

        $this->assertTrue($this->pasar());

        $this->assertSame([
            'ok' => now()->getTimestamp(), 'fallo' => null, 'fallo_desde' => null,
            'invalidos' => 4, 'invalidos_ids' => [3, 5, 7],
        ], Cache::get(ImportarPedidosWeb::CLAVE_LECTURA));
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function fallasDeLectura(): array
    {
        return [
            'clave equivocada' => [401, 'clave'],
            'página sin base o sin clave' => [503, 'apagada'],
            'redirección' => [302, 'direccion'],
            'no existe' => [404, 'direccion'],
            'método no permitido' => [405, 'direccion'],
            'otro código' => [500, 'error'],
            'prohibido' => [403, 'error'],
            'forma rara' => ['rara', 'respuesta'],
            'paginación que no avanza' => ['paginacion', 'respuesta'],
            'sin conexión' => ['conexion', 'sin_conexion'],
        ];
    }

    #[DataProvider('fallasDeLectura')]
    public function test_cada_falla_de_lectura_anota_su_motivo_y_conserva_la_ultima_buena(mixed $caso, string $motivo): void
    {
        $this->remoto(['*/pedidos-pagados*' => Http::response(['pedidos' => [['id' => 4, 'codigo' => 'roto']]])]);
        $this->assertTrue($this->pasar());
        $buena = now()->getTimestamp();
        $this->travel(3)->minutes();

        $this->remoto(['*/pedidos-pagados*' => match ($caso) {
            'rara' => Http::response(['otra' => 'cosa']),
            'paginacion' => Http::response(['pedidos' => [['id' => 0]]]),
            'conexion' => Http::failedConnection('sin red '.self::SECRETO),
            default => Http::response(['error' => 'x'], $caso),
        }]);

        $this->assertFalse($this->pasar());

        $this->assertSame([
            'ok' => $buena, 'fallo' => $motivo, 'fallo_desde' => now()->getTimestamp(),
            'invalidos' => 1, 'invalidos_ids' => [4],
        ], Cache::get(ImportarPedidosWeb::CLAVE_LECTURA));
    }

    public function test_una_falla_propia_del_sistema_se_anota_como_sistema(): void
    {
        $this->remoto(['*/pedidos-pagados*' => fn () => throw new RuntimeException('SQL con datos '.self::SECRETO)]);

        $this->assertFalse($this->pasar());

        $this->assertSame('sistema', Cache::get(ImportarPedidosWeb::CLAVE_LECTURA)['fallo']);
        $this->assertNull(Cache::get(ImportarPedidosWeb::CLAVE_LECTURA)['ok']);
        $this->assertStringNotContainsString(self::SECRETO, json_encode(Cache::get(ImportarPedidosWeb::CLAVE_LECTURA)));
    }

    public function test_configuracion_incompleta_se_anota_como_configuracion(): void
    {
        Ajustes::guardarComoSistema('tienda.url', '');
        $this->remoto();

        $this->assertFalse($this->pasar());

        $this->assertSame('configuracion', Cache::get(ImportarPedidosWeb::CLAVE_LECTURA)['fallo']);
        Http::assertNothingSent();
    }

    public function test_dos_fallos_seguidos_conservan_el_desde_del_primero_y_la_lectura_buena_lo_borra(): void
    {
        $this->remoto(['*/pedidos-pagados*' => Http::response([], 503)]);
        $this->assertFalse($this->pasar());
        $primero = now()->getTimestamp();

        $this->travel(5)->minutes();
        $this->remoto(['*/pedidos-pagados*' => Http::response([], 401)]);
        $this->assertFalse($this->pasar());
        $lectura = Cache::get(ImportarPedidosWeb::CLAVE_LECTURA);
        $this->assertSame('clave', $lectura['fallo'], 'El motivo es el de la última falla.');
        $this->assertSame($primero, $lectura['fallo_desde'], 'Desde cuándo falla no se corre.');

        $this->travel(1)->minutes();
        $this->remoto();
        $this->assertTrue($this->pasar());
        $this->assertSame(['ok' => now()->getTimestamp(), 'fallo' => null, 'fallo_desde' => null, 'invalidos' => 0, 'invalidos_ids' => []],
            Cache::get(ImportarPedidosWeb::CLAVE_LECTURA));
    }

    public function test_un_corte_en_la_segunda_pagina_no_baja_los_pedidos_que_no_se_pudieron_leer(): void
    {
        $this->remoto(['*/pedidos-pagados*' => Http::response(['pedidos' => [['id' => 8, 'codigo' => 'roto'], ['id' => 9, 'codigo' => 'roto']]])]);
        $this->assertTrue($this->pasar());

        // Primera página de 100 sin raros y la segunda que se cae.
        $pagina = array_map(fn (int $id) => $this->datos($id), range(1, 100));
        $this->remoto(['*/pedidos-pagados*' => Http::sequence()->push(['pedidos' => $pagina])->push([], 500)]);
        $this->assertFalse($this->pasar());

        $lectura = Cache::get(ImportarPedidosWeb::CLAVE_LECTURA);
        $this->assertSame(2, $lectura['invalidos']);
        $this->assertSame([8, 9], $lectura['invalidos_ids']);
        $this->assertSame('error', $lectura['fallo']);
    }

    public function test_sin_aplicar_no_escribe_ninguna_clave_ni_pregunta_por_errores(): void
    {
        $this->remoto();
        $this->assertTrue($this->pasar(false));
        $this->remoto(['*/pedidos-pagados*' => Http::response([], 401)]);
        $this->assertFalse($this->pasar(false));

        foreach ([ImportarPedidosWeb::CLAVE_LECTURA, ImportarPedidosWeb::CLAVE_ERRORES, ImportarPedidosWeb::CLAVE_REVISION, ImportarPedidosWeb::CLAVE_VIGILANDO, ImportarPedidosWeb::CLAVE_LIMPIEZA] as $clave) {
            $this->assertNull(Cache::get($clave), $clave);
        }
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/errores'));
    }

    public function test_la_lista_de_errores_se_guarda_filtrada_y_sin_datos_raros(): void
    {
        $fila = fn (array $cambios = []) => $cambios + [
            'tipo' => 'error_cobro', 'detalle' => 'token HTTP 401', 'cantidad' => 2,
            'primera' => '2026-11-19T20:00:00.000Z', 'ultima' => '2026-11-19T20:30:00.000Z',
        ];
        $errores = [
            $fila(),
            $fila(['tipo' => 'error_interno', 'detalle' => 'seguir_pedido · TypeError']),
            $fila(['tipo' => 'firma_invalida', 'detalle' => 'no_cuadra']),
            $fila(['tipo' => 'consulta_fallida', 'detalle' => '']),
            $fila(['tipo' => 'vencido_sin_confirmar', 'detalle' => '']),
            $fila(['tipo' => 'error_consulta', 'detalle' => 'monto:12.5 $']),
            $fila(['tipo' => 'error_cobro', 'detalle' => 'pedido LN-TEST-0000-0001']),
            $fila(['tipo' => 'error_cobro', 'detalle' => 'elena@example.test']),
            $fila(['tipo' => 'error_cobro', 'detalle' => str_repeat('a', 121)]),
            $fila(['tipo' => 'error_cobro', 'detalle' => ['no', 'texto']]),
            $fila(['tipo' => 'inventado']),
            $fila(['cantidad' => 0]),
            $fila(['cantidad' => '3']),
            $fila(['primera' => 'mañana']),
            $fila(['ultima' => null]),
            'no es una fila',
        ];
        $this->remoto(['*/errores' => Http::response(['cobra' => false, 'errores' => $errores])]);

        $this->assertTrue($this->pasar());

        $guardado = Cache::get(ImportarPedidosWeb::CLAVE_ERRORES);
        $this->assertSame(now()->getTimestamp(), $guardado['en']);
        $this->assertFalse($guardado['cobra']);
        $this->assertSame(
            ['token HTTP 401', 'seguir_pedido · TypeError', 'no_cuadra', '', '', '', '', '', '', ''],
            array_column($guardado['errores'], 'detalle'),
        );
        $this->assertSame(
            ['error_cobro', 'error_interno', 'firma_invalida', 'consulta_fallida', 'vencido_sin_confirmar', 'error_consulta', 'error_cobro', 'error_cobro', 'error_cobro', 'error_cobro'],
            array_column($guardado['errores'], 'tipo'),
        );
        $this->assertSame(['tipo' => 'error_cobro', 'detalle' => 'token HTTP 401', 'cantidad' => 2,
            'primera' => strtotime('2026-11-19T20:00:00Z'), 'ultima' => strtotime('2026-11-19T20:30:00Z')], $guardado['errores'][0]);
        $this->assertStringNotContainsString('LN-', json_encode($guardado));
        $this->assertStringNotContainsString('elena', json_encode($guardado));
    }

    public function test_de_la_lista_de_errores_se_guardan_20_como_mucho(): void
    {
        $errores = array_map(fn (int $i) => ['tipo' => 'error_interno', 'detalle' => 'otra · Error'.chr(65 + $i), 'cantidad' => 1,
            'primera' => '2026-11-19T20:00:00Z', 'ultima' => '2026-11-19T20:00:00Z'], range(0, 24));
        $this->remoto(['*/errores' => Http::response(['cobra' => true, 'errores' => $errores])]);

        $this->assertTrue($this->pasar());

        $this->assertCount(20, Cache::get(ImportarPedidosWeb::CLAVE_ERRORES)['errores']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function erroresQueNoSirven(): array
    {
        return [
            'página vieja sin la ruta' => [['error' => 'no_existe'], 404],
            'error de la página' => [['error' => 'interno'], 500],
            'sin cobra' => [['errores' => []], 200],
            'cobra que no es booleano' => [['cobra' => 'si', 'errores' => []], 200],
            'errores que no son lista' => [['cobra' => true, 'errores' => ['a' => 1]], 200],
        ];
    }

    #[DataProvider('erroresQueNoSirven')]
    public function test_si_la_lista_de_errores_no_sirve_la_cache_no_cambia_y_la_importacion_sale_bien(array $cuerpo, int $estado): void
    {
        // De hace más de 5 minutos: si no, la lista ni se pide.
        $antes = ['en' => now()->subMinutes(10)->getTimestamp(), 'cobra' => true, 'errores' => []];
        Cache::put(ImportarPedidosWeb::CLAVE_ERRORES, $antes, now()->addDay());
        $this->remoto(['*/pedidos-pagados*' => Http::response(['pedidos' => [$this->datos(1)]]), '*/errores' => Http::response($cuerpo, $estado)]);

        $this->assertTrue($this->pasar());

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/errores'));
        $this->assertSame($antes, Cache::get(ImportarPedidosWeb::CLAVE_ERRORES));
        $this->assertNull(Cache::get(ImportarPedidosWeb::CLAVE_LECTURA)['fallo'], 'La importación no se marca como caída.');
        $this->assertDatabaseCount('pedidos_web', 1);
    }

    public function test_la_lista_de_errores_se_pide_aunque_la_lectura_falle_y_sin_dejar_nada_en_el_log(): void
    {
        Log::spy();
        $pedida = null;
        $this->remoto([
            '*/pedidos-pagados*' => Http::response([], 500),
            '*/errores' => function (Request $r) use (&$pedida) {
                $pedida = $r;
                throw new RuntimeException('Error remoto '.self::SECRETO.' elena@example.test');
            },
        ]);

        $this->assertFalse($this->pasar());

        $this->assertNotNull($pedida, 'La lista de errores se pide aunque la lectura de pedidos falle.');
        $this->assertSame('GET', $pedida->method());
        $this->assertTrue($pedida->hasHeader('Authorization', 'Bearer '.self::SECRETO));
        $this->assertStringEndsWith('/api/sistema/errores', $pedida->url());
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_cuenta_los_pedidos_que_la_pagina_dejo_en_revision_sin_los_importados_ni_los_de_prueba(): void
    {
        PedidoWeb::create([
            'web_id' => 11, 'codigo' => 'LN-TEST-0000-0011', 'idioma' => 'es', 'pagado_en' => now(), 'alerta' => '',
            'total_centavos' => 345, 'moneda' => 'USD', 'cliente_nombre' => 'Elena Inventada', 'cliente_correo' => 'elena@example.test',
            'cliente_telefono' => '70000000', 'entrega_tipo' => 'recoger', 'cajitas_detalle' => [], 'lineas' => [],
            'pasarela' => 'wompi', 'estado' => EstadoPedidoWeb::ParaRevisar,
        ]);
        $revision = fn (int $id, string $estado = 'para_revisar', string $pasarela = 'wompi', ?string $codigo = null) => [
            'id' => $id, 'codigo' => $codigo ?? 'LN-REVI-0000-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'estado' => $estado, 'alerta' => '', 'pasarela' => $pasarela,
            'cliente' => ['nombre' => 'Elena Inventada', 'correo' => 'elena@example.test', 'telefono' => '70000000'],
        ];
        $this->remoto(['*/pedidos-a-revisar*' => Http::response(['pedidos' => [
            $revision(10), $revision(11), $revision(12, 'pagado'), $revision(13, pasarela: 'demo'),
            $revision(14, pasarela: 'wompi-prueba'), $revision(15), $revision(16, codigo: 'LN-roto elena@example.test'),
            $revision(17), $revision(18),
        ]])]);

        $this->assertTrue($this->pasar());

        $guardado = Cache::get(ImportarPedidosWeb::CLAVE_REVISION);
        $this->assertSame(['en' => now()->getTimestamp(), 'n' => 5, 'codigos' => ['LN-REVI-0000-0010', 'LN-REVI-0000-0015', 'LN-REVI-0000-0017']], $guardado);
        $this->assertStringNotContainsString('elena', json_encode($guardado));
    }

    public function test_si_la_lista_de_revision_se_corta_a_mitad_no_se_escribe(): void
    {
        $antes = ['en' => now()->subMinutes(10)->getTimestamp(), 'n' => 1, 'codigos' => ['LN-REVI-0000-0001']];
        Cache::forever(ImportarPedidosWeb::CLAVE_REVISION, $antes);
        $pagina = array_map(fn (int $id) => ['id' => $id, 'codigo' => 'LN-REVI-0000-0001', 'estado' => 'para_revisar', 'alerta' => '', 'pasarela' => 'wompi'], range(1, 100));
        $this->remoto(['*/pedidos-a-revisar*' => Http::sequence()->push(['pedidos' => $pagina])->push([], 503)]);

        $this->assertTrue($this->pasar());

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/pedidos-a-revisar') && (int) $r['despues'] === 100);
        $this->assertSame($antes, Cache::get(ImportarPedidosWeb::CLAVE_REVISION));
    }

    /**
     * La lista de pedidos a revisar recorre la tabla entera de la página (Cloudflare cobra
     * por fila leída): se pide cada 5 minutos y, si la última no llegó, en la pasada siguiente.
     */
    public function test_la_lista_de_revision_se_pide_cada_5_minutos_y_enseguida_si_la_ultima_no_llego(): void
    {
        $pedidas = [];
        $this->remoto(['*/pedidos-a-revisar*' => function () use (&$pedidas) {
            $pedidas[] = now()->format('H:i');

            return count($pedidas) === 2 ? Http::response([], 503) : Http::response(['pedidos' => []]);
        }]);

        for ($minuto = 0; $minuto < 12; $minuto++) {
            $this->assertTrue($this->pasar());
            $this->travel(1)->minutes();
        }

        // La de las 15:05 no llegó: se vuelve a pedir a las 15:06 y de ahí cada 5 minutos.
        $this->assertSame(['15:00', '15:05', '15:06', '15:11'], $pedidas);
        $this->assertCount(12, Http::recorded(fn (Request $r) => str_contains($r->url(), '/pedidos-pagados')));
    }

    /**
     * La lista de errores relee en la página cada aviso de las últimas 24 horas, también los
     * falsos que deja guardar el tope de 50 por hora (Cloudflare cobra por fila leída): se
     * pide cada 5 minutos y, si la última no llegó o no servía, en la pasada siguiente.
     */
    public function test_la_lista_de_errores_se_pide_cada_5_minutos_y_enseguida_si_la_ultima_no_llego(): void
    {
        $pedidas = [];
        $this->remoto(['*/errores' => function () use (&$pedidas) {
            $pedidas[] = now()->format('H:i');

            return match (count($pedidas)) {
                2 => Http::response([], 503),
                3 => Http::response(['errores' => []]), // sin «cobra»: no sirve
                default => Http::response(['cobra' => true, 'errores' => []]),
            };
        }]);

        for ($minuto = 0; $minuto < 13; $minuto++) {
            $this->assertTrue($this->pasar());
            $this->travel(1)->minutes();
        }

        // Las de las 15:05 y 15:06 no sirvieron: se pide otra vez a las 15:07 y de ahí cada 5 minutos.
        $this->assertSame(['15:00', '15:05', '15:06', '15:07', '15:12'], $pedidas);
        $this->assertCount(13, Http::recorded(fn (Request $r) => str_contains($r->url(), '/pedidos-pagados')));
    }
}
