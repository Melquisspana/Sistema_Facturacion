<?php

namespace Tests\Feature\Alertas;

use App\Facades\Ajustes;
use App\Mail\AlertaSistemaCorreo;
use App\Services\Alertas\Avisador;
use App\Services\Alertas\Detectores\TiendaWebFallas;
use App\Services\Alertas\Detectores\TiendaWebSinRespuesta;
use App\Services\Alertas\Hallazgo;
use App\Services\Alertas\RegistroEventos;
use App\Services\TiendaWeb\FacturarPedidoWeb;
use App\Services\TiendaWeb\ImportarPedidosWeb;
use App\Support\Correo\CandadoCorreoReal;
use App\Support\HoraNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Los avisos de la tienda web: la página que no contesta y lo que la página anotó
 * que falló. El estado se pone en la caché como lo deja ImportarPedidosWeb.
 */
class TiendaWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-19 10:00', 'America/El_Salvador'));
        Ajustes::guardarComoSistema('tienda.pedidos.automatica', true);
    }

    private function lectura(?int $okHace, ?string $fallo = null, ?int $falloHace = null, int $invalidos = 0, array $ids = []): void
    {
        Cache::forever(ImportarPedidosWeb::CLAVE_LECTURA, [
            'ok' => $okHace === null ? null : now()->subMinutes($okHace)->getTimestamp(),
            'fallo' => $fallo,
            'fallo_desde' => $falloHace === null ? null : now()->subMinutes($falloHace)->getTimestamp(),
            'invalidos' => $invalidos, 'invalidos_ids' => $ids,
        ]);
    }

    /** @param  list<array{0: string, 1: string, 2: int, 3: int}>  $filas  tipo, detalle, cantidad, hace cuántos minutos la última */
    private function errores(array $filas, bool $cobra = true, int $hace = 1): void
    {
        Cache::put(ImportarPedidosWeb::CLAVE_ERRORES, [
            'en' => now()->subMinutes($hace)->getTimestamp(), 'cobra' => $cobra,
            'errores' => array_map(fn (array $f) => [
                'tipo' => $f[0], 'detalle' => $f[1], 'cantidad' => $f[2],
                'primera' => now()->subMinutes($f[3] + 60)->getTimestamp(), 'ultima' => now()->subMinutes($f[3])->getTimestamp(),
            ], $filas),
        ], now()->addDay());
    }

    /** @param  list<string>  $codigos */
    private function revision(int $hace = 0, int $n = 0, array $codigos = []): void
    {
        Cache::forever(ImportarPedidosWeb::CLAVE_REVISION, ['en' => now()->subMinutes($hace)->getTimestamp(), 'n' => $n, 'codigos' => $codigos]);
    }

    private function assertSinDatosSensibles(Hallazgo $h): void
    {
        $texto = $h->titulo.$h->detalle.$h->queHacer;
        foreach (['$', '@', 'LN-', '7000', 'tienda.example.test', 'secreto'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $texto);
        }
    }

    // ───────────── Pedidos de la tienda sin traer ─────────────

    public function test_sin_respuesta_avisa_a_los_15_minutos_de_la_ultima_lectura_buena(): void
    {
        $detector = app(TiendaWebSinRespuesta::class);
        $this->assertSame('tienda_web_sin_respuesta', $detector->tipo());
        $this->assertSame('Pedidos de la tienda sin traer', $detector->nombre());
        $this->lectura(okHace: 14, fallo: 'sin_conexion', falloHace: 13);
        $this->assertTrue($detector->aplica());
        $this->assertNull($detector->revisar());

        $this->travel(2)->minutes();
        $h = $detector->revisar();

        $this->assertSame('Los pedidos de la tienda no están entrando', $h->titulo);
        $this->assertStringContainsString('Última lectura buena: 19/11 09:46', $h->detalle);
        $this->assertStringContainsString('Abrí la tienda en el celular', $h->queHacer);
        $this->assertStringContainsString('Los pedidos pagados entran solos cuando vuelva', $h->queHacer);
        $this->assertStringContainsString('2 días', $h->queHacer);
        $this->assertSame(1, $h->conteo);
        $this->assertSame('2026-11-19 09:46', HoraNegocio::aLocal($h->desde)->format('Y-m-d H:i'));
        $this->assertSame('configuracion.integraciones.tienda', $h->enlace);
        $this->assertSinDatosSensibles($h);
    }

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function motivos(): array
    {
        return [
            'sin conexión' => ['sin_conexion', 'si abre, es el internet del servidor'],
            'clave' => ['clave', 'igual a SECRETO_SISTEMA en Cloudflare'],
            'apagada' => ['apagada', 'le falta su base o su clave'],
            'dirección' => ['direccion', 'Revisá la dirección en Configuración › Tienda web'],
            'respuesta rara' => ['respuesta', 'Avisá a soporte'],
            'error' => ['error', 'Avisá a soporte'],
            'sistema' => ['sistema', 'Avisá a soporte'],
            'configuración' => ['configuracion', 'Completá Configuración › Tienda web'],
            'sin falla anotada' => [null, 'La tarea que trae los pedidos no está corriendo'],
        ];
    }

    #[DataProvider('motivos')]
    public function test_sin_respuesta_dice_que_hacer_segun_el_motivo(?string $motivo, string $queHacer): void
    {
        $this->lectura(okHace: 20, fallo: $motivo, falloHace: $motivo === null ? null : 19);

        $h = app(TiendaWebSinRespuesta::class)->revisar();

        $this->assertStringContainsString($queHacer, $h->queHacer);
        $this->assertNotSame('', $h->detalle);
        $this->assertSinDatosSensibles($h);
    }

    public function test_sin_ninguna_lectura_cuenta_desde_que_empezo_a_vigilar(): void
    {
        $detector = app(TiendaWebSinRespuesta::class);
        $this->assertNull($detector->revisar(), 'Tras vaciar la caché hay 15 minutos de gracia.');
        $this->travel(14)->minutes();
        $this->assertNull($detector->revisar());

        $this->travel(2)->minutes();
        $h = $detector->revisar();
        $this->assertStringContainsString('Última lectura buena: nunca', $h->detalle);
        $this->assertStringContainsString('no está corriendo', $h->queHacer);
    }

    public function test_sin_lectura_buena_cuenta_desde_la_primera_falla(): void
    {
        $this->lectura(okHace: null, fallo: 'clave', falloHace: 16);

        $h = app(TiendaWebSinRespuesta::class)->revisar();

        $this->assertStringContainsString('Última lectura buena: nunca', $h->detalle);
        $this->assertSame(now()->subMinutes(16)->getTimestamp(), $h->desde->getTimestamp());
    }

    public function test_sin_respuesta_recuerda_cada_4_horas_como_mucho(): void
    {
        $this->lectura(okHace: 239);
        $this->assertSame(1, app(TiendaWebSinRespuesta::class)->revisar()->conteo);
        $this->travel(2)->minutes();
        $this->assertSame(2, app(TiendaWebSinRespuesta::class)->revisar()->conteo);
        $this->travel(4)->hours();
        $this->assertSame(3, app(TiendaWebSinRespuesta::class)->revisar()->conteo);
    }

    public function test_sin_respuesta_no_opina_con_la_automatica_apagada(): void
    {
        Ajustes::guardarComoSistema('tienda.pedidos.automatica', false);

        $this->assertFalse(app(TiendaWebSinRespuesta::class)->aplica());
    }

    // ───────────── Pagos en la tienda web ─────────────

    public function test_pagos_avisa_que_la_tienda_no_puede_cobrar(): void
    {
        $this->lectura(okHace: 1);
        $this->errores([], cobra: false);
        $detector = TiendaWebFallas::pagos();
        $this->assertSame('tienda_web_pagos', $detector->tipo());
        $this->assertSame('Pagos en la tienda web', $detector->nombre());
        $this->assertTrue($detector->aplica());

        $h = $detector->revisar();

        $this->assertSame('La tienda no puede cobrar: los compradores ven “tienda cerrada”', $h->titulo);
        $this->assertStringContainsString('avisá a soporte', mb_strtolower($h->queHacer));
        $this->assertSame(1, $h->conteo);
        $this->assertNull($h->enlace);
        $this->assertSinDatosSensibles($h);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function fallasDeCobro(): array
    {
        return [
            'llaves' => ['token HTTP 401', 'Wompi no aceptó las llaves de la tienda', 'App ID y la API Secret'],
            'modo' => ['EnlacePago modo equivocado', 'modo', 'En Vivo'],
            'wompi caído' => ['EnlacePago HTTP 502', 'Wompi está fallando o lento', 'Wompi'],
            'sin respuesta' => ['token sin respuesta', 'Wompi está fallando o lento', 'Wompi'],
            'fetch que revienta' => ['EnlacePago TypeError', 'la página no logró llamar a Wompi', 'soporte'],
            'guardar' => ['guardar el enlace', 'no se pudo guardar el enlace', 'soporte'],
            'desconocido' => ['algo nuevo', 'otra falla', 'soporte'],
            'vacío' => ['', 'otra falla', 'soporte'],
        ];
    }

    #[DataProvider('fallasDeCobro')]
    public function test_pagos_avisa_que_no_se_pudo_abrir_el_pago_con_el_motivo_en_palabras(string $detalle, string $motivo, string $queHacer): void
    {
        $this->lectura(okHace: 1);
        $this->errores([['error_cobro', $detalle, 2, 120]]);

        $h = TiendaWebFallas::pagos()->revisar();

        $this->assertStringStartsWith('No se pudo abrir el pago', $h->titulo);
        $this->assertStringContainsString($motivo, $h->titulo.$h->detalle);
        $this->assertStringContainsString($queHacer, $h->queHacer);
        $this->assertStringContainsString('2 veces', $h->detalle);
        $this->assertStringContainsString('la última a las 08:00', $h->detalle, 'Sigue abierto aunque sea viejo: hasta que otro pago abra.');
        $this->assertSame(2, $h->conteo);
        $this->assertSinDatosSensibles($h);
    }

    public function test_pagos_avisa_que_el_fusible_cerro_la_tienda_un_rato(): void
    {
        // La página lo anota como error_cobro, una fila por hora, cuando ya hay 30 pedidos sin pagar en la última hora.
        $this->lectura(okHace: 1);
        $this->errores([['error_cobro', 'demasiados pedidos sin pagar', 1, 120]]);

        $h = TiendaWebFallas::pagos()->revisar();

        $this->assertSame('Se cerró la tienda un rato porque entraron demasiados pedidos sin pagar: puede ser un robot', $h->titulo);
        $this->assertSame('1 vez, la última a las 08:00.', $h->detalle, 'Sigue abierto hasta que alguien consiga su enlace, como los otros cobros.');
        $this->assertStringContainsString('Se vuelve a abrir sola en menos de una hora', $h->queHacer);
        $this->assertStringContainsString('Turnstile', $h->queHacer);
        $this->assertSame(1, $h->conteo);
        $this->assertNull(TiendaWebFallas::otras()->revisar());
        $this->assertSinDatosSensibles($h);
    }

    public function test_pagos_solo_cuenta_las_consultas_y_vencidos_de_la_ultima_media_hora(): void
    {
        $this->lectura(okHace: 1);
        $this->errores([['error_consulta', '', 1, 31], ['vencido_sin_confirmar', '', 2, 40]]);
        $this->assertNull(TiendaWebFallas::pagos()->revisar());

        $this->errores([['error_consulta', '', 1, 5], ['vencido_sin_confirmar', '', 2, 10]]);
        $h = TiendaWebFallas::pagos()->revisar();

        $this->assertSame('La tienda web tiene 2 problemas', $h->titulo);
        $this->assertStringContainsString('Llegó un pago que no se pudo comprobar con Wompi', $h->detalle);
        $this->assertStringContainsString('2 pedidos se cancelaron solos sin que Wompi confirmara si estaban pagados', $h->detalle);
        $this->assertStringContainsString('en las últimas 24 horas', $h->detalle);
        $this->assertStringContainsString('panel de Wompi', $h->queHacer);
        $this->assertSame(3, $h->conteo);
        $this->assertSinDatosSensibles($h);
    }

    public function test_pagos_no_opina_sin_lectura_reciente_ni_con_la_lista_vieja(): void
    {
        $this->errores([], cobra: false);
        $this->lectura(okHace: 16);
        $this->assertFalse(TiendaWebFallas::pagos()->aplica(), 'Si la página no contesta, avisa el otro.');

        $this->lectura(okHace: 1);
        $this->errores([], cobra: false, hace: 21);
        $this->assertFalse(TiendaWebFallas::pagos()->aplica(), 'Con la lista vieja no da un normalizado falso.');
        // Se pide cada 5 minutos: a los 15 de gracia se suman esos 5.
        $this->errores([], cobra: false, hace: 20);
        $this->assertTrue(TiendaWebFallas::pagos()->aplica(), 'Con 20 minutos todavía vale.');

        Ajustes::guardarComoSistema('tienda.pedidos.automatica', false);
        $this->errores([], cobra: false);
        $this->assertFalse(TiendaWebFallas::pagos()->aplica());
    }

    // ───────────── Comprobación anti-robots ─────────────

    /** @return array<string, array{0: string, 1: bool, 2: string, 3: string}> */
    public static function rechazosDeVerificacion(): array
    {
        return [
            // Cloudflare no contestó: no deja pagar a nadie, y un robot no lo puede provocar.
            'Turnstile sin respuesta' => ['turnstile_sin_respuesta', true,
                'La tienda no dejó pagar: la comprobación anti-robots de Cloudflare no contestó', 'esperá unos minutos y probá una compra'],
            // Lo provoca cualquiera que mande un pedido sin token: va con las otras, que tienen su propio tope.
            'Turnstile rechazó' => ['turnstile_invalido', false,
                'La comprobación anti-robots rechazó pedidos: pueden ser robots o una llave mal puesta', 'revisá las dos llaves de Turnstile en Cloudflare'],
            'campo trampa' => ['campo_trampa', false,
                'La tienda rechazó pedidos que llenaron la trampa para robots', 'si te rechaza, avisá a soporte'],
            'motivo que el sistema no conoce' => ['otro_motivo', false,
                'La comprobación anti-robots rechazó pedidos: pueden ser robots o una llave mal puesta', 'Probá una compra desde tu celular'],
        ];
    }

    #[DataProvider('rechazosDeVerificacion')]
    public function test_los_rechazos_de_la_comprobacion_anti_robots_avisan_en_un_solo_aviso(string $motivo, bool $enPagos, string $titulo, string $queHacer): void
    {
        $this->lectura(okHace: 1);
        // La página anota como mucho uno por motivo cada 15 minutos: la cantidad son esos ratos.
        $this->errores([['rechazo_verificacion', $motivo, 2, 5]]);
        [$aviso, $otro] = $enPagos ? [TiendaWebFallas::pagos(), TiendaWebFallas::otras()] : [TiendaWebFallas::otras(), TiendaWebFallas::pagos()];

        $h = $aviso->revisar();

        $this->assertSame($titulo, $h->titulo);
        $this->assertStringContainsString($queHacer, $h->queHacer);
        $this->assertSame('2 veces en las últimas 24 horas, la última a las 09:55.', $h->detalle);
        $this->assertSame(2, $h->conteo);
        $this->assertNull($otro->revisar(), 'El mismo rechazo no avisa dos veces.');
        $this->assertSinDatosSensibles($h);

        $this->errores([['rechazo_verificacion', $motivo, 2, 31]]);
        $this->assertNull($aviso->revisar(), 'Lo de hace más de media hora ya pasó.');
    }

    // ───────────── Otras fallas de la tienda web ─────────────

    public function test_otras_avisa_errores_internos_firmas_y_consultas_fallidas(): void
    {
        $this->lectura(okHace: 1);
        $this->errores([
            ['error_interno', 'seguir_pedido · TypeError', 1, 5],
            ['error_interno', 'seguir_pedido · Error', 2, 6],
            ['firma_invalida', 'no_cuadra', 1, 3],
            ['firma_invalida', 'sin_cabecera', 1, 4],
            ['consulta_fallida', '', 2, 2],
        ]);
        $detector = TiendaWebFallas::otras();
        $this->assertSame('tienda_web_fallas', $detector->tipo());
        $this->assertSame('Otras fallas de la tienda web', $detector->nombre());

        $h = $detector->revisar();

        $this->assertSame('La tienda web tiene 3 problemas', $h->titulo);
        $this->assertStringContainsString('La página tuvo un error interno al seguir un pedido: 3 veces', $h->detalle);
        $this->assertStringContainsString('Llegó un aviso de pago con una firma que no coincide', $h->detalle);
        $this->assertStringContainsString('Llegó un aviso de pago sin la firma de Wompi', $h->detalle);
        $this->assertStringNotContainsString('Wompi falló', $h->detalle, 'Con 2 consultas fallidas no avisa.');
        $this->assertStringContainsString('Reenviá este correo a soporte', $h->queHacer);
        $this->assertSame(5, $h->conteo);
        $this->assertSinDatosSensibles($h);

        $this->errores([['consulta_fallida', '', 3, 2]]);
        $h = $detector->revisar();
        $this->assertSame('La consulta de reserva a Wompi falló 3 veces', $h->titulo);
    }

    public function test_otras_no_cuenta_eventos_de_hace_mas_de_media_hora(): void
    {
        $this->lectura(okHace: 1);
        $this->errores([['error_interno', 'hacer_pedido · Error', 4, 31], ['firma_invalida', 'no_cuadra', 1, 45]]);

        $this->assertNull(TiendaWebFallas::otras()->revisar());
    }
    public function test_otras_avisa_los_pedidos_pagados_que_no_se_pudieron_leer_y_los_que_quedaron_en_revision(): void
    {
        $this->lectura(okHace: 1, invalidos: 4, ids: [41, 57, 60]);
        $this->errores([]);
        $this->revision(n: 1, codigos: ['LN-TEST-0000-0009']);

        $h = TiendaWebFallas::otras()->revisar();

        $this->assertStringContainsString('4 pedidos pagados que el sistema no pudo leer (n.º 41, 57, 60 y 1 más); esos no entran solos', $h->detalle);
        $this->assertStringContainsString('1 pedido quedó en revisión en la página: LN-TEST-0000-0009', $h->detalle);
        $this->assertStringContainsString('El cliente ya pagó y el pedido no entra', $h->queHacer);
        $this->assertSame(5, $h->conteo);
    }

    public function test_otras_no_usa_una_lista_de_revision_vieja_y_avisa_que_no_se_pudo_leer(): void
    {
        $this->lectura(okHace: 1);
        $this->errores([]);

        // La ruta de revisión dejó de contestar hace 21 minutos: ese pedido pudo resolverse.
        $this->revision(hace: 21, n: 1, codigos: ['LN-TEST-0000-0009']);
        $h = TiendaWebFallas::otras()->revisar();
        $this->assertSame('No se pudo leer la lista de pedidos en revisión de la página', $h->titulo);
        $this->assertStringContainsString('soporte', $h->queHacer);
        $this->assertSame(1, $h->conteo);
        $this->assertSinDatosSensibles($h);

        // Guardada en cero: un pedido que quede en revisión después no se pierde callado.
        $this->revision(hace: 21);
        $this->assertSame('No se pudo leer la lista de pedidos en revisión de la página', TiendaWebFallas::otras()->revisar()->titulo);

        // Sin la lista, cuenta desde que se vigila, como la de errores.
        Cache::forget(ImportarPedidosWeb::CLAVE_REVISION);
        $this->assertNull(TiendaWebFallas::otras()->revisar(), 'Recién empieza a vigilar: todavía no.');
        $this->travel(21)->minutes();
        $this->lectura(okHace: 1);
        $this->errores([]);
        $this->assertSame('No se pudo leer la lista de pedidos en revisión de la página', TiendaWebFallas::otras()->revisar()->titulo);

        // Se pide cada 5 minutos: a los 15 de gracia se suman esos 5.
        $this->revision(hace: 20);
        $this->assertNull(TiendaWebFallas::otras()->revisar(), 'Con 20 minutos todavía vale.');
    }

    public function test_otras_avisa_que_no_pudo_leer_la_lista_de_errores_de_la_pagina(): void
    {
        $this->lectura(okHace: 1);
        $detector = TiendaWebFallas::otras();
        $this->assertTrue($detector->aplica());
        $this->assertNull($detector->revisar(), 'Recién empieza a vigilar: todavía no.');

        // Se pide cada 5 minutos: a los 15 de gracia se suman esos 5.
        $this->travel(20)->minutes();
        $this->lectura(okHace: 1);
        $this->revision();
        $this->assertNull($detector->revisar(), 'Con 20 minutos todavía no.');
        $this->travel(1)->minutes();
        $this->lectura(okHace: 1);
        $this->revision();
        $h = $detector->revisar();
        $this->assertSame('No se pudo leer la lista de errores de la página', $h->titulo);
        $this->assertStringContainsString('falta subir la página', $h->queHacer);
        $this->assertSame(1, $h->conteo);

        $this->errores([], hace: 21);
        $this->assertSame('No se pudo leer la lista de errores de la página', $detector->revisar()->titulo);
        $this->errores([], hace: 20);
        $this->assertNull($detector->revisar(), 'Con 20 minutos todavía vale.');
        $this->errores([]);
        $this->assertNull($detector->revisar());
    }

    public function test_otras_no_opina_si_la_pagina_no_contesta(): void
    {
        $this->lectura(okHace: 16, fallo: 'clave', falloHace: 15);

        $this->assertFalse(TiendaWebFallas::otras()->aplica());
    }

    // ───────────── Con el Avisador ─────────────

    public function test_los_tres_estan_en_el_avisador_y_se_normalizan_cuando_la_pagina_vuelve(): void
    {
        $tipos = array_map(fn ($d) => $d->tipo(), app(Avisador::class)->detectores());
        foreach (['tienda_web_sin_respuesta', 'tienda_web_pagos', 'tienda_web_fallas'] as $tipo) {
            $this->assertContains($tipo, $tipos);
        }

        config(['alertas.enabled' => true, 'alertas.correo' => 'avisos@example.test']);
        $this->app->instance(CandadoCorreoReal::class, new class extends CandadoCorreoReal
        {
            public function debeSimular(): bool
            {
                return false;
            }
        });
        Mail::fake();
        $avisador = app(Avisador::class);

        $this->lectura(okHace: 20, fallo: 'apagada', falloHace: 19);
        $this->assertSame('falla', $avisador->procesar(app(TiendaWebSinRespuesta::class)));
        $this->lectura(okHace: 0);
        $this->assertSame('normalizado', $avisador->procesar(app(TiendaWebSinRespuesta::class)));

        $this->errores([['error_cobro', 'token HTTP 401', 1, 3]]);
        $this->assertSame('falla', $avisador->procesar(TiendaWebFallas::pagos()));
        $this->errores([]);
        $this->assertSame('normalizado', $avisador->procesar(TiendaWebFallas::pagos()));

        Mail::assertSent(AlertaSistemaCorreo::class, 4);
    }

    public function test_si_la_facturacion_de_pedidos_web_falla_queda_anotada_como_tarea(): void
    {
        config(['alertas.enabled' => true]);
        Ajustes::guardarComoSistema('tienda.url', '');
        $this->mock(FacturarPedidoWeb::class, fn ($m) => $m->shouldReceive('pasada')->andThrow(new RuntimeException('falla con datos')));

        $this->artisan('tienda:traer-pedidos', ['--aplicar' => true])->assertFailed();

        $evento = RegistroEventos::leer(RegistroEventos::TAREAS);
        $this->assertSame(['tienda:traer-pedidos (facturación)' => 1], $evento['etiquetas'],
            'Solo la facturación: la lectura caída la avisa «Pedidos de la tienda sin traer».');
    }
}
