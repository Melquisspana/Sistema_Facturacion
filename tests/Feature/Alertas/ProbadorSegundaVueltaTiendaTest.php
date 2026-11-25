<?php

namespace Tests\Feature\Alertas;

use App\Facades\Ajustes;
use App\Mail\AlertaSistemaCorreo;
use App\Models\PedidoWeb;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\TiendaWeb\FacturarPedidoWeb;
use App\Services\TiendaWeb\ImportarPedidosWeb;
use App\Support\Correo\CandadoCorreoReal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Throwable;

/**
 * Segunda vuelta del probador de la tienda web: casos que la primera no cubrió. Otras
 * formas de caerse (apagada, dirección, una página que contesta su portada), la lista de
 * errores que se cae sola con un aviso de pagos abierto, dos pasadas a la vez, un pago
 * que no abre mientras el sistema no puede leer, la caché vaciada justo antes de revisar,
 * el tope diario de «Pedidos de la tienda sin traer» y el Inicio. Cada minuto corre
 * `tienda:traer-pedidos --aplicar` contra una página falsa y cada 5 `alertas:revisar`.
 * Datos inventados.
 */
class ProbadorSegundaVueltaTiendaTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-inventado-solo-para-pruebas-123456';

    private const SIN_TRAER = 'Pedidos de la tienda sin traer';

    private const PAGOS = 'Pagos en la tienda web';

    private const OTRAS = 'Otras fallas de la tienda web';

    /** bien, caida (sin conexión), clave (401), apagada (503), direccion (302) o portada (200 con HTML). */
    private string $pagina = 'bien';

    /** Solo la ruta de errores: null (contesta), 500, o portada (una página vieja que devuelve su HTML). */
    private ?string $rutaErrores = null;

    /** @var list<array<string, mixed>> */
    private array $pagados = [];

    /** @var list<array<string, mixed>> */
    private array $revision = [];

    /** @var list<array<string, mixed>> */
    private array $errores = [];

    private int $pedidasDeErrores = 0;

    /**
     * Si se pone, se llama una vez cuando la próxima pasada pide su primera página de
     * pedidos pagados: antes de que esa pasada importe o anote nada.
     */
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
        $this->mock(FacturarPedidoWeb::class, fn ($m) => $m->shouldReceive('pasada')->andReturn([]));
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => $this->contestar($r));
    }

    private function contestar(Request $r)
    {
        $ruta = (string) parse_url($r->url(), PHP_URL_PATH);
        if (str_ends_with($ruta, '/pedidos-pagados') && $this->aMitad !== null) {
            [$aMitad, $this->aMitad] = [$this->aMitad, null];
            $aMitad();
        }
        $caida = match ($this->pagina) {
            'caida' => Http::failedConnection()($r),
            'clave' => Http::response(['error' => 'no_autorizado'], 401),
            'apagada' => Http::response(['error' => 'tienda_apagada'], 503),
            'direccion' => Http::response('', 302, ['Location' => 'https://otra.example.test/']),
            'portada' => Http::response('<!doctype html><title>Tienda</title>', 200, ['Content-Type' => 'text/html']),
            default => null,
        };
        if ($caida !== null) {
            return $caida;
        }
        if (str_ends_with($ruta, '/pedidos-pagados')) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return Http::response(['pedidos' => array_values(array_filter($this->pagados, fn (array $p) => $p['id'] > (int) ($q['despues'] ?? 0)))]);
        }
        if (str_ends_with($ruta, '/pedidos-a-revisar')) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return Http::response(['pedidos' => array_values(array_filter($this->revision, fn (array $p) => $p['id'] > (int) ($q['despues'] ?? 0)))]);
        }
        if (str_ends_with($ruta, '/errores')) {
            $this->pedidasDeErrores++;

            return match ($this->rutaErrores) {
                '500' => Http::response(['error' => 'interno'], 500),
                'portada' => Http::response('<!doctype html><title>Tienda</title>', 200, ['Content-Type' => 'text/html']),
                default => Http::response(['cobra' => true, 'errores' => $this->errores]),
            };
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

    private function fila(string $tipo, string $detalle, int $cantidad, int $primeraHace = 0, int $ultimaHace = 0): array
    {
        $iso = fn (int $hace) => now()->subMinutes($hace)->utc()->format('Y-m-d\TH:i:s.v\Z');

        return ['tipo' => $tipo, 'detalle' => $detalle, 'cantidad' => $cantidad, 'primera' => $iso($primeraHace), 'ultima' => $iso($ultimaHace)];
    }

    private function pasan(int $minutos): void
    {
        for ($i = 0; $i < $minutos; $i++) {
            Artisan::call('tienda:traer-pedidos', ['--aplicar' => true]);
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

    // ───────────── Otras formas de caerse ─────────────

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function caidas(): array
    {
        return [
            'apagada (503)' => ['apagada', 'La página contesta que está apagada.', 'A la página le falta su base o su clave'],
            'dirección que redirige (302)' => ['direccion', 'La dirección guardada no lleva a la tienda.', 'Revisá la dirección en Configuración › Tienda web.'],
            'contesta su portada (200 con HTML)' => ['portada', 'La página contestó algo que el sistema no entiende.', 'Avisá a soporte.'],
        ];
    }

    #[DataProvider('caidas')]
    public function test_otras_caidas_de_20_minutos_mandan_un_correo_de_falla_con_su_motivo_y_uno_de_normalizado(string $como, string $motivo, string $queHacer): void
    {
        $this->pasan(3);
        $this->pagina = $como;
        $this->pagados[] = $this->datos(1);
        $this->pasan(20);

        $this->assertSame(['falla'], $this->correos(self::SIN_TRAER));
        $falla = $this->ultimo(self::SIN_TRAER);
        $this->assertStringContainsString($motivo, $falla->hallazgo->detalle);
        $this->assertStringContainsString($queHacer, $falla->hallazgo->queHacer);
        $this->assertStringNotContainsString(self::SECRETO, $falla->render());
        $this->assertStringNotContainsString('otra.example.test', $falla->render(), 'Ni la dirección a la que redirige.');
        $this->assertSame(0, PedidoWeb::count());

        $this->pagina = 'bien';
        $this->pasan(30);

        $this->assertSame(['falla', 'normalizado'], $this->correos(self::SIN_TRAER));
        $this->assertSame([], $this->correos(self::PAGOS));
        $this->assertSame([], $this->correos(self::OTRAS));
        $this->assertSame(1, PedidoWeb::count(), 'El pedido pagado entra solo cuando la página vuelve.');
    }

    // ───────────── Solo la lista de errores se cae ─────────────

    public function test_si_solo_la_lista_de_errores_se_cae_el_aviso_de_pagos_no_se_da_por_normalizado(): void
    {
        $this->pasan(2);
        $this->errores = [$this->fila('error_cobro', 'token HTTP 401', 1)];
        $this->pasan(9);
        $this->assertSame(['falla'], $this->correos(self::PAGOS));

        // La ruta de errores empieza a dar 500; los pedidos se siguen leyendo bien.
        $this->rutaErrores = '500';
        $this->pagados[] = $this->datos(1);
        $this->pasan(40);

        $this->assertSame(['falla'], $this->correos(self::PAGOS), 'Sin la lista no hay un «Ya se normalizó» falso.');
        $this->assertSame(['falla'], $this->correos(self::OTRAS));
        $this->assertSame('No se pudo leer la lista de errores de la página', $this->ultimo(self::OTRAS)->hallazgo->titulo);
        $this->assertSame([], $this->correos(self::SIN_TRAER), 'La importación anda: no es «sin traer».');
        $this->assertSame(1, PedidoWeb::count());

        // La lista guardada vence (dura 24 horas) o se borra con la ruta todavía caída: ya no
        // queda nada que revisar y aun así no se da por normalizado.
        Cache::forget(ImportarPedidosWeb::CLAVE_ERRORES);
        $this->pasan(6);
        $this->assertSame(['falla'], $this->correos(self::PAGOS), 'Sin ninguna lista no hay un «Ya se normalizó» falso.');
        $this->assertSame(['falla'], $this->correos(self::OTRAS), 'La lista que falta sigue avisando, sin cerrarse y volver a abrirse.');
        $this->actingAs($this->administrador())->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Avisos abiertos (', 'No se pudo abrir el pago: Wompi no aceptó las llaves de la tienda']);

        // Vuelve la lista y otro comprador ya consiguió su enlace.
        $this->rutaErrores = null;
        $this->errores = [];
        $this->pasan(10);
        $this->assertSame(['falla', 'normalizado'], $this->correos(self::PAGOS));
        $this->assertSame(['falla', 'normalizado'], $this->correos(self::OTRAS));
    }

    public function test_una_pagina_vieja_que_contesta_su_portada_en_la_ruta_de_errores_no_rompe_nada(): void
    {
        $this->rutaErrores = 'portada';
        $this->pagados[] = $this->datos(1);
        $this->pasan(40);

        $this->assertSame(['falla'], $this->correos(self::OTRAS));
        $this->assertSame('No se pudo leer la lista de errores de la página', $this->ultimo(self::OTRAS)->hallazgo->titulo);
        $this->assertSame([], $this->correos(self::PAGOS));
        $this->assertSame([], $this->correos(self::SIN_TRAER));
        $this->assertNull(Cache::get(ImportarPedidosWeb::CLAVE_ERRORES));
        $this->assertSame(1, PedidoWeb::count());
    }

    // ───────────── Dos pasadas a la vez ─────────────

    public function test_dos_pasadas_buenas_a_la_vez_importan_el_pedido_una_sola_vez(): void
    {
        $this->pagados[] = $this->datos(1);
        // Mientras la primera espera su página de pedidos, otra lee e importa el mismo pedido.
        $antesDeLaSegunda = null;
        $this->aMitad = function () use (&$antesDeLaSegunda) {
            $antesDeLaSegunda = PedidoWeb::count();
            app(ImportarPedidosWeb::class)->ejecutar(true);
        };
        $this->pasan(1);

        $this->assertSame(0, $antesDeLaSegunda, 'La segunda arrancó antes de que la primera importara.');
        $this->assertSame(1, PedidoWeb::count(), 'Las dos traen el pedido 1 y entra una sola vez.');
        $this->assertNull(Cache::get(ImportarPedidosWeb::CLAVE_LECTURA)['fallo']);

        $this->pasan(40);
        $this->assertSame([], $this->correos(self::SIN_TRAER));
        $this->assertSame([], $this->correos(self::PAGOS));
        $this->assertSame([], $this->correos(self::OTRAS));
        $this->assertSame(1, PedidoWeb::count());
    }

    public function test_una_pasada_que_falla_en_medio_de_otra_buena_no_manda_avisos(): void
    {
        $this->pagados[] = $this->datos(1);
        // Mientras la primera espera su página de pedidos, arranca otra y la página se corta:
        // la segunda anota su falla y después la primera guarda su lectura buena.
        $this->aMitad = function () {
            $this->pagina = 'caida';
            try {
                app(ImportarPedidosWeb::class)->ejecutar(true);
            } catch (Throwable) {
            }
            $this->pagina = 'bien';
        };
        $this->pasan(1);

        $lectura = Cache::get(ImportarPedidosWeb::CLAVE_LECTURA);
        $this->assertSame(now()->subMinute()->getTimestamp(), $lectura['ok'], 'La lectura buena de la primera no se pierde.');
        $this->assertNull($lectura['fallo'], 'La primera terminó última: la falla de la segunda no queda colgada.');

        $this->pasan(40);
        $this->assertSame([], $this->correos(self::SIN_TRAER));
        $this->assertSame([], $this->correos(self::PAGOS));
        $this->assertSame([], $this->correos(self::OTRAS));
        $this->assertSame(1, PedidoWeb::count());
    }

    public function test_una_pasada_buena_en_medio_de_otra_que_falla_conserva_la_lectura_buena(): void
    {
        $this->pasan(1);
        $this->pagina = 'clave';
        $this->pasan(20);
        $this->assertSame(['falla'], $this->correos(self::SIN_TRAER));

        // Se arregla la clave mientras una pasada vieja espera su respuesta: la nueva lee bien
        // y termina primero; la vieja recibe después su 401 y anota la falla.
        $this->pagados[] = $this->datos(1);
        $this->aMitad = function () {
            $this->pagina = 'bien';
            app(ImportarPedidosWeb::class)->ejecutar(true);
            $this->pagina = 'clave';
        };
        $this->pasan(1);
        $lectura = Cache::get(ImportarPedidosWeb::CLAVE_LECTURA);
        $this->assertSame(now()->subMinute()->getTimestamp(), $lectura['ok'], 'La falla que llega después no borra la lectura buena.');
        $this->assertSame('clave', $lectura['fallo'], 'La última en terminar fue la que falló.');

        $this->pagina = 'bien';
        $this->pasan(10);
        $this->assertSame(['falla', 'normalizado'], $this->correos(self::SIN_TRAER));
        $this->assertSame(1, PedidoWeb::count());
    }

    // ───────────── Un pago que no abre mientras el sistema no puede leer ─────────────

    public function test_un_pago_que_no_abre_mientras_la_clave_esta_mal_avisa_cuando_la_clave_se_arregla(): void
    {
        $this->pasan(3);
        $this->pagina = 'clave';
        $this->pasan(20);
        $this->assertSame(['falla'], $this->correos(self::SIN_TRAER));

        // En ese rato un comprador no pudo abrir su pago; la página lo anotó.
        $this->errores = [$this->fila('error_cobro', 'EnlacePago TypeError', 1, primeraHace: 5, ultimaHace: 5)];
        $this->pagina = 'bien';
        $this->pasan(10);

        $this->assertSame(['falla', 'normalizado'], $this->correos(self::SIN_TRAER));
        $this->assertSame(['falla'], $this->correos(self::PAGOS), 'El pago que no abrió no se pierde por la caída.');
        $this->assertSame('No se pudo abrir el pago: la página no logró llamar a Wompi', $this->ultimo(self::PAGOS)->hallazgo->titulo);
    }

    // ───────────── Datos de la tienda perdidos justo antes de revisar ─────────────

    /** Un error de cobro y un pedido en revisión, con su correo de falla ya mandado. */
    private function pagosYOtrasAbiertos(): void
    {
        $this->pasan(2);
        $this->errores = [$this->fila('error_cobro', 'token HTTP 401', 1)];
        $this->revision = [['id' => 50, 'codigo' => 'LN-REVI-0000-0050', 'estado' => 'para_revisar', 'alerta' => '', 'pasarela' => 'wompi']];
        $this->pasan(9);
        $this->assertSame(['falla'], $this->correos(self::PAGOS));
        $this->assertSame(['falla'], $this->correos(self::OTRAS));
    }

    public function test_sin_los_datos_de_la_tienda_los_avisos_abiertos_no_se_dan_por_normalizados(): void
    {
        $this->pagosYOtrasAbiertos();

        // Se pierde solo lo que trajo la tienda; los avisos abiertos siguen guardados. Sin
        // datos recientes, pagos y otras no opinan: si opinaran, no verían nada y cerrarían.
        foreach ([ImportarPedidosWeb::CLAVE_LECTURA, ImportarPedidosWeb::CLAVE_ERRORES, ImportarPedidosWeb::CLAVE_REVISION] as $clave) {
            Cache::forget($clave);
        }
        Artisan::call('alertas:revisar');
        $this->pasan(30);

        $this->assertSame(['falla'], $this->correos(self::PAGOS), 'El problema sigue: ni «Ya se normalizó» ni un correo repetido.');
        $this->assertSame(['falla'], $this->correos(self::OTRAS));
        $this->assertSame([], $this->correos(self::SIN_TRAER));
    }

    public function test_con_la_cache_vaciada_entera_el_aviso_se_repite_una_vez_y_no_sale_normalizado(): void
    {
        $this->pagosYOtrasAbiertos();

        // Riesgo aceptado en el diseño: se borran también el aviso abierto y el tope del día.
        Cache::flush();
        Artisan::call('alertas:revisar');
        $this->pasan(30);

        $this->assertSame(['falla', 'falla'], $this->correos(self::PAGOS), 'Sin el aviso guardado, se vuelve a mandar una vez.');
        $this->assertSame(['falla', 'falla'], $this->correos(self::OTRAS));
        $this->assertSame([], $this->correos(self::SIN_TRAER));
    }

    // ───────────── Tope de 6 correos por día ─────────────

    public function test_una_pagina_que_va_y_viene_todo_el_dia_manda_6_correos_como_mucho(): void
    {
        $this->pasan(1);
        for ($ciclo = 0; $ciclo < 5; $ciclo++) {
            $this->pagina = 'caida';
            $this->pasan(20);
            $this->pagina = 'bien';
            $this->pasan(5);
        }

        $this->assertSame(['falla', 'normalizado', 'falla', 'normalizado', 'falla', 'normalizado'], $this->correos(self::SIN_TRAER));
        $this->assertTrue($this->ultimo(self::SIN_TRAER)->ultimoDelDia);
        // El último es un «Ya se normalizó»: tiene que decir dónde se ve lo que siga hoy.
        $this->assertStringContainsString('lo ves en el Inicio', $this->ultimo(self::SIN_TRAER)->render());
        $this->assertSame([], $this->correos(self::OTRAS), 'La caída no gasta el tope de las otras.');
    }

    // ───────────── El Inicio ─────────────

    public function test_el_inicio_muestra_la_tienda_caida_con_su_boton_y_la_quita_cuando_vuelve(): void
    {
        $admin = $this->administrador();
        $this->pasan(3);
        $this->pagina = 'clave';
        $this->errores = [$this->fila('error_cobro', 'token HTTP 401', 1)];
        $this->pasan(20);

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Avisos abiertos (', 'Los pedidos de la tienda no están entrando', 'La página no acepta la clave del sistema.',
                'Desde 19/11/2026 10:02', 'SECRETO_SISTEMA', 'Revisar'])
            ->assertSee(route('configuracion.integraciones.tienda'))
            ->assertDontSee(self::SECRETO)->assertDontSee('No se pudo abrir el pago');

        $this->pagina = 'bien';
        $this->pasan(10);

        $respuesta = $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Los pedidos de la tienda no están entrando')
            ->assertSeeInOrder(['Avisos abiertos (', 'No se pudo abrir el pago: Wompi no aceptó las llaves de la tienda']);
        // El aviso de pagos no tiene pantalla donde arreglarse: sin botón «Revisar».
        $this->assertStringNotContainsString('sr-only">: No se pudo abrir el pago', $respuesta->getContent());
    }
}
