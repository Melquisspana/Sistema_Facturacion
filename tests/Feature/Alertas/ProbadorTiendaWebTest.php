<?php

namespace Tests\Feature\Alertas;

use App\Enums\EstadoPedidoWeb;
use App\Facades\Ajustes;
use App\Mail\AlertaSistemaCorreo;
use App\Models\PedidoWeb;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Services\Alertas\Avisador;
use App\Services\Alertas\Detectores\TiendaWebFallas;
use App\Services\Alertas\Detectores\TiendaWebSinRespuesta;
use App\Services\TiendaWeb\FacturarPedidoWeb;
use App\Support\Correo\CandadoCorreoReal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El probador de los avisos de la tienda web: usa el sistema como pasaría de verdad.
 * Cada minuto corre `tienda:traer-pedidos --aplicar` contra una página falsa y cada 5
 * minutos `alertas:revisar`, y cuenta los correos que salen por cada aviso. Datos
 * inventados; ni la página ni Wompi existen.
 */
class ProbadorTiendaWebTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-inventado-solo-para-pruebas-123456';

    private const SIN_TRAER = 'Pedidos de la tienda sin traer';

    private const PAGOS = 'Pagos en la tienda web';

    private const OTRAS = 'Otras fallas de la tienda web';

    /** Cómo está la página: bien, caida (sin conexión), clave (401 a todo) o vieja (sin /errores). */
    private string $pagina = 'bien';

    /** @var list<array<string, mixed>> */
    private array $pagados = [];

    /** @var list<array<string, mixed>> */
    private array $errores = [];

    private bool $cobra = true;

    private int $pedidasDeErrores = 0;

    /** @var list<array<string, mixed>> lo que contesta /pedidos-a-revisar */
    private array $enRevision = [];

    /** @var array<string, list<string>> a qué hora se pidió cada ruta, aunque la página esté caída */
    private array $pedidas = [];

    /** Si se pone, se llama una vez en medio de la próxima pasada (después de leer los pedidos). */
    private ?\Closure $aMitad = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-19 10:00', 'America/El_Salvador'));
        Ajustes::guardarComoSistema('tienda.url', 'https://tienda.example.test');
        Ajustes::guardarComoSistema('tienda.secreto', self::SECRETO);
        Ajustes::guardarComoSistema('tienda.pedidos.automatica', true);
        config(['alertas.enabled' => true, 'alertas.correo' => 'avisos@example.test']);
        $this->app->instance(CandadoCorreoReal::class, new class extends CandadoCorreoReal
        {
            public function debeSimular(): bool
            {
                return false;
            }
        });
        Mail::fake();
        UnidadMedida::firstOrCreate(['codigo' => '59'], ['nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        Producto::factory()->create(['codigo' => 'WEB01', 'nombre' => 'Dulce inventado', 'codigo_barra' => '740000000001']);
        // La factura de los pedidos web es otro tema: acá los pedidos solo entran.
        $this->mock(FacturarPedidoWeb::class, fn ($m) => $m->shouldReceive('pasada')->andReturn([]));
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => $this->contestar($r));
    }

    private function contestar(Request $r)
    {
        $ruta = (string) parse_url($r->url(), PHP_URL_PATH);
        $this->pedidas[basename($ruta)][] = now()->format('H:i');
        if ($this->pagina === 'caida') {
            return Http::failedConnection()($r);
        }
        if ($this->pagina === 'clave') {
            return Http::response(['error' => 'no_autorizado'], 401);
        }
        if (str_ends_with($ruta, '/pedidos-pagados')) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return Http::response(['pedidos' => array_values(array_filter($this->pagados, fn (array $p) => $p['id'] > (int) ($q['despues'] ?? 0)))]);
        }
        if ($this->aMitad !== null) {
            [$aMitad, $this->aMitad] = [$this->aMitad, null];
            $aMitad();
        }
        if (str_ends_with($ruta, '/errores')) {
            $this->pedidasDeErrores++;

            return $this->pagina === 'vieja'
                ? Http::response(['error' => 'no_existe'], 404)
                : Http::response(['cobra' => $this->cobra, 'errores' => $this->errores]);
        }
        if (str_ends_with($ruta, '/pedidos-a-revisar')) {
            return Http::response(['pedidos' => $this->enRevision]);
        }

        return Http::response([], 200);
    }

    private function datos(int $id): array
    {
        return [
            'id' => $id, 'codigo' => 'LN-TEST-0000-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'idioma' => 'es', 'creado' => now()->utc()->toIso8601ZuluString(), 'pagado' => now()->utc()->toIso8601ZuluString(),
            'alerta' => '', 'unidades' => 3, 'total' => 345, 'moneda' => 'USD',
            'cliente' => ['nombre' => 'Elena Inventada', 'correo' => 'elena@example.test', 'telefono' => '70000000'],
            'entrega' => ['tipo' => 'recoger', 'departamento' => null, 'municipio' => null, 'direccion' => null, 'notas' => null],
            'cajitas_detalle' => [], 'regalo' => null,
            'lineas' => [['barra' => '740000000001', 'cantidad' => 3]],
            'pasarela' => 'wompi', 'referencia' => 'pago-inventado-'.$id,
        ];
    }

    /** Una fila de la lista de errores de la página, con fechas ISO como las manda. */
    private function fila(string $tipo, string $detalle, int $cantidad, int $primeraHace = 0, int $ultimaHace = 0): array
    {
        $iso = fn (int $hace) => now()->subMinutes($hace)->utc()->format('Y-m-d\TH:i:s.v\Z');

        return ['tipo' => $tipo, 'detalle' => $detalle, 'cantidad' => $cantidad, 'primera' => $iso($primeraHace), 'ultima' => $iso($ultimaHace)];
    }

    /** Pasa el tiempo como en el servidor: la tarea de la tienda cada minuto y los avisos cada 5. */
    private function pasan(int $minutos, int $paso = 1): void
    {
        for ($i = 0; $i < $minutos; $i += $paso) {
            Artisan::call('tienda:traer-pedidos', ['--aplicar' => true]);
            if (now()->minute % 5 === 0) {
                Artisan::call('alertas:revisar');
            }
            $this->travel($paso)->minutes();
        }
    }

    /** @return list<string> las acciones de los correos de ese aviso, en orden */
    private function correos(string $nombre): array
    {
        return Mail::sent(AlertaSistemaCorreo::class, fn (AlertaSistemaCorreo $m) => $m->nombre === $nombre)
            ->map(fn (AlertaSistemaCorreo $m) => $m->accion)->values()->all();
    }

    private function ultimo(string $nombre): AlertaSistemaCorreo
    {
        return Mail::sent(AlertaSistemaCorreo::class, fn (AlertaSistemaCorreo $m) => $m->nombre === $nombre)->last();
    }

    private function assertSinDatosSensibles(AlertaSistemaCorreo $correo): void
    {
        $texto = $correo->render().($correo->hallazgo === null ? '' : $correo->hallazgo->titulo.$correo->hallazgo->detalle.$correo->hallazgo->queHacer);
        foreach (['Elena', 'elena@', '70000000', 'LN-TEST', self::SECRETO, 'tienda.example.test/api'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $texto);
        }
        if ($correo->hallazgo !== null) {
            $this->assertStringNotContainsString('$', $correo->hallazgo->titulo.$correo->hallazgo->detalle.$correo->hallazgo->queHacer, 'Sin montos.');
        }
    }

    // ───────────── La página cae y vuelve ─────────────

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function caidas(): array
    {
        return [
            'sin conexión' => ['caida', 'El sistema no logra conectarse con la página.', 'Abrí la tienda en el celular'],
            'clave cambiada' => ['clave', 'La página no acepta la clave del sistema.', 'igual a SECRETO_SISTEMA en Cloudflare'],
        ];
    }

    #[DataProvider('caidas')]
    public function test_la_pagina_falla_20_minutos_y_vuelve_un_correo_de_falla_uno_de_normalizado_y_los_pedidos_entran_solos(string $como, string $motivo, string $queHacer): void
    {
        $this->pasan(3); // 10:00 a 10:02, bien
        $this->pagina = $como;
        $this->pasan(5);
        $this->pagados[] = $this->datos(1); // alguien paga mientras tanto
        $this->pasan(15); // hasta las 10:22

        $this->assertSame(['falla'], $this->correos(self::SIN_TRAER));
        $falla = $this->ultimo(self::SIN_TRAER);
        $this->assertStringContainsString('Última lectura buena: 19/11 10:02', $falla->hallazgo->detalle);
        $this->assertStringContainsString($motivo, $falla->hallazgo->detalle);
        $this->assertStringContainsString($queHacer, $falla->hallazgo->queHacer);
        $this->assertSame('19/11/2026 10:02', $falla->desde);
        $this->assertStringContainsString('/configuracion/integraciones/tienda', $falla->enlace);
        $this->assertSinDatosSensibles($falla);
        $this->assertSame(0, PedidoWeb::count());

        $this->pagados[] = $this->datos(2);
        $this->pagina = 'bien';
        $this->pasan(40); // vuelve a las 10:23 y sigue bien hasta las 11:02

        $this->assertSame(['falla', 'normalizado'], $this->correos(self::SIN_TRAER));
        $this->assertSame([], $this->correos(self::PAGOS), 'Un solo correo por el mismo problema.');
        $this->assertSame([], $this->correos(self::OTRAS), 'Ni «No se pudo leer la lista» por la caída.');
        $this->assertSame([1, 2], PedidoWeb::orderBy('web_id')->pluck('web_id')->all(), 'Los pedidos pagados entran solos.');
    }

    public function test_una_caida_de_14_minutos_no_manda_nada(): void
    {
        $this->pasan(1);
        $this->pagina = 'caida';
        $this->pasan(14);
        $this->pagina = 'bien';
        $this->pasan(30);

        $this->assertSame([], $this->correos(self::SIN_TRAER));
        $this->assertSame([], $this->correos(self::OTRAS));
    }

    /**
     * La lista de pedidos a revisar y la de errores se piden cada 5 minutos: cuando la página
     * se cae pueden tener 4 minutos más que la última lectura buena. Esa diferencia no manda
     * «otras».
     */
    public function test_una_caida_4_minutos_despues_de_pedir_las_listas_avisa_solo_sin_traer(): void
    {
        $this->travel(1)->minutes();
        $this->pasan(5); // las listas se piden a las 10:01; la última lectura buena es de las 10:05
        $this->assertSame(['10:01'], $this->pedidas['errores']);
        $this->pagina = 'caida';
        $this->pasan(20);
        $this->pagina = 'bien';
        $this->pasan(10);

        $this->assertSame(['falla', 'normalizado'], $this->correos(self::SIN_TRAER));
        $this->assertSame([], $this->correos(self::OTRAS), 'Ni «No se pudo leer la lista» de revisión o de errores por la caída.');
    }

    /**
     * En el servidor la pasada tarda unos segundos: si la lista de revisión se anotó a las
     * 10:00:50, a las 10:05:00 todavía no tiene 5 minutos y se pide a las 10:06. Si la página
     * se cae justo a las 10:06, igual llega un solo correo.
     */
    public function test_una_pasada_lenta_corre_la_lista_de_revision_un_minuto_y_la_caida_igual_avisa_una_vez(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 10:00:50', 'America/El_Salvador'));
        Artisan::call('tienda:traer-pedidos', ['--aplicar' => true]);
        $this->travelTo(Carbon::parse('2026-11-19 10:01:00', 'America/El_Salvador'));
        $this->pasan(5); // 10:01 a 10:05
        $this->assertSame(['10:00'], $this->pedidas['pedidos-a-revisar'], 'La de las 10:05 todavía no toca.');
        $this->assertSame(['10:00'], $this->pedidas['errores'], 'La de errores tampoco.');
        $this->pagina = 'caida';
        $this->pasan(30); // 10:06 a 10:35

        $this->assertSame(['falla'], $this->correos(self::SIN_TRAER));
        $this->assertSame([], $this->correos(self::OTRAS), 'Ni «No se pudo leer la lista» de revisión o de errores por la caída.');
    }

    // ───────────── Tres horas de pasadas: lo que se le pide a la página ─────────────

    /**
     * Tres horas con la tarea cada minuto, como en el servidor. La limpieza relee tablas
     * enteras en la página (Cloudflare cobra por fila leída): sale una vez por hora. La
     * lista de pedidos a revisar y la de errores también releen (la de errores, cada aviso
     * falso de las últimas 24 horas): cada 5 minutos. Los pedidos pagados, en cada pasada.
     */
    public function test_tres_horas_de_pasadas_piden_la_limpieza_una_vez_por_hora_y_las_listas_cada_5_minutos(): void
    {
        $this->pasan(180); // 10:00 a 12:59

        $this->assertSame(['10:00', '11:00', '12:00'], $this->pedidas['limpieza']);
        $this->assertCount(36, $this->pedidas['pedidos-a-revisar']);
        $this->assertSame(['10:00', '10:05', '10:10'], array_slice($this->pedidas['pedidos-a-revisar'], 0, 3));
        $this->assertCount(180, $this->pedidas['pedidos-pagados']);
        $this->assertCount(36, $this->pedidas['errores']);
        $this->assertSame(['10:00', '10:05', '10:10'], array_slice($this->pedidas['errores'], 0, 3));
        $this->assertSame([], $this->correos(self::SIN_TRAER));
        $this->assertSame([], $this->correos(self::PAGOS));
        $this->assertSame([], $this->correos(self::OTRAS));
    }

    /**
     * Tres horas con la página caída una hora en medio y un pedido pagado mientras tanto.
     * La limpieza que tocaba con la página caída no se repite cada minuto; los avisos abren
     * y cierran igual que cuando todo se pedía cada minuto.
     */
    public function test_tres_horas_con_la_pagina_caida_una_hora_los_avisos_abren_y_cierran_igual(): void
    {
        // La factura deja el pedido con su borrador en la misma pasada, como en el servidor.
        $this->mock(FacturarPedidoWeb::class, fn ($m) => $m->shouldReceive('pasada')->andReturnUsing(function () {
            PedidoWeb::where('estado', EstadoPedidoWeb::Importado)->update(['estado' => EstadoPedidoWeb::Facturando]);

            return [];
        }));

        $this->pasan(40); // 10:00 a 10:39, bien
        $this->pagina = 'caida';
        $this->pasan(30); // 10:40 a 11:09
        $this->pagados[] = $this->datos(1); // alguien paga con la página caída para el sistema
        $this->pasan(30); // hasta las 11:39
        $this->assertSame(['falla'], $this->correos(self::SIN_TRAER));
        $this->assertStringContainsString('Última lectura buena: 19/11 10:39', $this->ultimo(self::SIN_TRAER)->hallazgo->detalle);

        $this->pagina = 'bien';
        $this->pasan(30); // vuelve a las 11:40
        $this->assertSame(['falla', 'normalizado'], $this->correos(self::SIN_TRAER));
        $this->assertSame([1], PedidoWeb::pluck('web_id')->all(), 'El pedido pagado entra al volver.');

        // Un pedido queda en revisión en la página y después se resuelve.
        $this->enRevision = [['id' => 50, 'codigo' => 'LN-REVI-0000-0050', 'estado' => 'para_revisar', 'alerta' => '', 'pasarela' => 'wompi']];
        $this->pasan(20); // 12:10 a 12:29
        $this->assertSame(['falla'], $this->correos(self::OTRAS));
        $this->assertSame('1 pedido quedó en revisión en la página: LN-REVI-0000-0050', $this->ultimo(self::OTRAS)->hallazgo->titulo);
        $this->enRevision = [];
        $this->pasan(30); // 12:30 a 12:59

        $this->assertSame(['falla', 'normalizado'], $this->correos(self::SIN_TRAER));
        $this->assertSame(['falla', 'normalizado'], $this->correos(self::OTRAS), 'Ni «No se pudo leer la lista de pedidos en revisión» por la caída.');
        $this->assertSame([], $this->correos(self::PAGOS));
        // La de las 11:00 no llegó y no se reintenta cada minuto: la siguiente, a la hora.
        $this->assertSame(['10:00', '11:00', '12:00'], $this->pedidas['limpieza']);
        // Al volver se pide enseguida y de ahí cada 5 minutos.
        $despues = array_values(array_filter($this->pedidas['pedidos-a-revisar'], fn (string $h) => $h >= '11:40'));
        $this->assertSame(array_map(fn (int $m) => Carbon::parse('2026-11-19 11:40')->addMinutes($m)->format('H:i'), range(0, 75, 5)), $despues);
    }

    public function test_si_sigue_caida_recuerda_cada_4_horas_y_no_mas(): void
    {
        $this->pasan(5); // última lectura buena a las 10:04
        $this->pagina = 'caida';
        $this->pasan(9 * 60, paso: 5);

        // Falla a las 10:20; 14:05 y 18:05 pasan las 4 y las 8 horas.
        $this->assertSame(['falla', 'sigue', 'sigue'], $this->correos(self::SIN_TRAER));
    }

    // ───────────── Página vieja, sin /api/sistema/errores ─────────────

    public function test_con_la_pagina_vieja_los_pedidos_entran_y_llega_un_solo_correo_de_que_falta_la_lista(): void
    {
        $this->pagina = 'vieja';
        $this->pasan(10);
        $this->pagados[] = $this->datos(1);
        $this->pasan(110);

        $this->assertSame(['falla'], $this->correos(self::OTRAS));
        $this->assertSame('No se pudo leer la lista de errores de la página', $this->ultimo(self::OTRAS)->hallazgo->titulo);
        $this->assertStringContainsString('falta subir la página', $this->ultimo(self::OTRAS)->hallazgo->queHacer);
        $this->assertSame([], $this->correos(self::PAGOS), 'Sin la lista, «Pagos» no opina.');
        $this->assertSame([], $this->correos(self::SIN_TRAER), 'La importación no se marca como caída.');
        $this->assertSame(1, PedidoWeb::count());

        $this->pagina = 'bien'; // se sube la página nueva
        $this->pasan(10);
        $this->assertSame(['falla', 'normalizado'], $this->correos(self::OTRAS));
    }

    // ───────────── Pagos ─────────────

    public function test_un_pago_que_no_abre_avisa_y_se_normaliza_cuando_otro_comprador_consigue_su_enlace(): void
    {
        $this->pasan(2);
        $this->errores = [$this->fila('error_cobro', 'token HTTP 401', 1)];
        $this->pasan(10);

        $this->assertSame(['falla'], $this->correos(self::PAGOS));
        $falla = $this->ultimo(self::PAGOS);
        $this->assertSame('No se pudo abrir el pago: Wompi no aceptó las llaves de la tienda', $falla->hallazgo->titulo);
        $this->assertStringContainsString('App ID y la API Secret', $falla->hallazgo->queHacer);
        $this->assertSame('19/11/2026 10:02', $falla->desde);
        $this->assertSinDatosSensibles($falla);

        // Una hora después falla otro comprador: «Sigue la falla», una sola vez.
        $this->pasan(60);
        $this->errores = [$this->fila('error_cobro', 'token HTTP 401', 2, primeraHace: 70)];
        $this->pasan(30);
        $this->assertSame(['falla', 'sigue'], $this->correos(self::PAGOS));

        // Otro comprador consigue su enlace: la página deja de mandar la fila.
        $this->errores = [];
        $this->pasan(10);
        $this->assertSame(['falla', 'sigue', 'normalizado'], $this->correos(self::PAGOS));
        $this->assertSame([], $this->correos(self::OTRAS));
    }

    public function test_si_la_tienda_no_puede_cobrar_avisa_una_vez_y_se_normaliza_al_cargar_las_llaves(): void
    {
        $this->cobra = false;
        $this->pasan(30);
        $this->cobra = true;
        $this->pasan(10);

        $this->assertSame(['falla', 'normalizado'], $this->correos(self::PAGOS));
    }

    // ───────────── Caché vaciada ─────────────

    public function test_la_cache_vaciada_a_mitad_de_una_pasada_no_manda_avisos_falsos(): void
    {
        $this->pasan(10);
        $this->aMitad = fn () => Cache::flush();
        $this->pasan(60);

        $this->assertSame([], $this->correos(self::SIN_TRAER));
        $this->assertSame([], $this->correos(self::PAGOS));
        $this->assertSame([], $this->correos(self::OTRAS));
    }

    public function test_la_cache_vaciada_con_la_pagina_caida_no_da_un_normalizado_falso(): void
    {
        $this->pasan(1);
        $this->pagina = 'caida';
        $this->pasan(20);
        $this->assertSame(['falla'], $this->correos(self::SIN_TRAER));

        Cache::flush();
        $this->pasan(10);
        $this->assertSame(['falla'], $this->correos(self::SIN_TRAER), 'Sin el estado no hay «Ya se normalizó» falso.');

        // Vuelta la página antes de los 15 minutos: el «Ya se normalizó» se pierde con la
        // caché (riesgo aceptado en el diseño), pero no queda nada abierto en el Inicio.
        $this->pagina = 'bien';
        $this->pasan(10);
        $this->assertSame(['falla'], $this->correos(self::SIN_TRAER));
        $this->assertNull(Cache::get('alertas.abiertas.tienda_web_sin_respuesta'));
    }

    // ───────────── Una sola lectura ─────────────

    public function test_cada_pasada_pide_la_lista_de_errores_una_sola_vez_y_ni_los_avisos_ni_el_inicio_llaman_a_la_pagina(): void
    {
        Artisan::call('tienda:traer-pedidos', ['--aplicar' => true]);
        $this->assertSame(1, $this->pedidasDeErrores);

        $aLaPagina = fn () => Http::recorded(fn (Request $r) => str_contains($r->url(), 'tienda.example.test'))->count();
        $llamadas = $aLaPagina();
        Artisan::call('alertas:revisar');
        $this->assertSame($llamadas, $aLaPagina(), 'Los avisos solo leen la caché: no le preguntan nada a la página.');
        $antes = count(Http::recorded());
        app(Avisador::class)->abiertos();
        $this->assertCount($antes, Http::recorded(), 'El Inicio no llama a nadie.');
        $this->assertSame(1, $this->pedidasDeErrores);

        Artisan::call('tienda:traer-pedidos');
        $this->assertSame(1, $this->pedidasDeErrores, 'Sin --aplicar no pregunta por errores.');
    }

    // ───────────── Datos raros ─────────────

    public function test_fechas_y_textos_raros_de_la_pagina_no_salen_en_el_correo(): void
    {
        $this->pasan(2);
        $this->errores = [
            ['tipo' => 'error_interno', 'detalle' => 'hacer_pedido · <script>alert(1)</script>', 'cantidad' => 1,
                'primera' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'ultima' => now()->utc()->format('Y-m-d\TH:i:s\Z')],
            ['tipo' => 'error_interno', 'detalle' => 'seguir_pedido · Error', 'cantidad' => 1,
                'primera' => '2026-02-30T10:00:00Z', 'ultima' => '2026-11-19T99:00:00Z'],
            $this->fila('error_cobro', 'llamá al 70000000 elena@example.test', 1),
        ];
        $this->pasan(5);

        $otras = $this->ultimo(self::OTRAS);
        $this->assertStringNotContainsString('script', $otras->render());
        $this->assertStringNotContainsString('seguir un pedido', $otras->render(), 'Una fecha imposible no cuenta.');
        $this->assertSinDatosSensibles($otras);
        $this->assertSinDatosSensibles($this->ultimo(self::PAGOS));
        $this->assertSame('No se pudo abrir el pago: otra falla', $this->ultimo(self::PAGOS)->hallazgo->titulo);
    }

    // ───────────── Horas límite ─────────────

    public function test_a_los_15_minutos_justos_de_la_ultima_lectura_buena_avisa_y_un_segundo_antes_no(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00', 'America/El_Salvador'));
        Artisan::call('tienda:traer-pedidos', ['--aplicar' => true]);
        $this->pagina = 'caida';
        $this->travelTo(Carbon::parse('2026-11-19 10:14:59', 'America/El_Salvador'));
        Artisan::call('tienda:traer-pedidos', ['--aplicar' => true]);
        $this->assertNull(app(TiendaWebSinRespuesta::class)->revisar());
        $this->assertTrue(TiendaWebFallas::otras()->aplica(), 'Todavía opinan los de la página.');

        $this->travelTo(Carbon::parse('2026-11-19 10:15:00', 'America/El_Salvador'));
        $this->assertNotNull(app(TiendaWebSinRespuesta::class)->revisar());
        $this->assertNull(TiendaWebFallas::otras()->revisar(), 'En el mismo segundo, la lista no se da por perdida: no hay dos correos.');

        $this->travelTo(Carbon::parse('2026-11-19 10:15:01', 'America/El_Salvador'));
        $this->assertFalse(TiendaWebFallas::otras()->aplica());
        $this->assertFalse(TiendaWebFallas::pagos()->aplica());
    }

    public function test_un_error_de_justo_antes_de_medianoche_sale_con_su_dia(): void
    {
        $this->travelTo(Carbon::parse('2026-11-19 23:58', 'America/El_Salvador'));
        $this->errores = [$this->fila('error_interno', 'hacer_pedido · Error', 1)];
        $this->travelTo(Carbon::parse('2026-11-20 00:03', 'America/El_Salvador'));
        $this->pasan(3);

        $h = $this->ultimo(self::OTRAS)->hallazgo;
        $this->assertStringContainsString('la última el 19/11 a las 23:58', $h->detalle);
    }

    // ───────────── Tope de 6 correos por día ─────────────

    public function test_otras_fallas_llegan_6_por_dia_como_mucho_sin_gastarle_el_tope_a_pagos(): void
    {
        $this->pasan(1);
        for ($ciclo = 0; $ciclo < 5; $ciclo++) {
            $this->errores = [$this->fila('error_interno', 'hacer_pedido · Error', 1)];
            $this->pasan(5);
            $this->errores = [];
            $this->pasan(5);
        }

        $otras = $this->correos(self::OTRAS);
        $this->assertCount(6, $otras);
        $this->assertSame(['falla', 'normalizado', 'falla', 'normalizado', 'falla', 'normalizado'], $otras);
        $this->assertTrue($this->ultimo(self::OTRAS)->ultimoDelDia);

        // Lo que impide vender tiene su propio tope.
        $this->errores = [$this->fila('error_cobro', 'token HTTP 401', 1)];
        $this->pasan(5);
        $this->assertSame(['falla'], $this->correos(self::PAGOS));

        // El aviso sigue en el Inicio aunque el correo no salga.
        $this->errores[] = $this->fila('error_interno', 'hacer_pedido · Error', 1);
        $this->pasan(5);
        $abiertos = collect(app(Avisador::class)->abiertos()['avisos'])->keyBy('tipo');
        $this->assertFalse($abiertos['tienda_web_fallas']['correo_salio']);
        $this->assertCount(6, $this->correos(self::OTRAS));

        // Al día siguiente (hora de El Salvador) vuelve a escribir.
        $this->travelTo(Carbon::parse('2026-11-20 00:05', 'America/El_Salvador'));
        $this->errores = [$this->fila('error_interno', 'hacer_pedido · Error', 1)];
        $this->pasan(1);
        $this->assertCount(7, $this->correos(self::OTRAS));
        $this->assertSame('falla', $this->correos(self::OTRAS)[6]);
    }
}
