<?php

namespace Tests\Feature\Fabrica;

use App\Ajustes\CatalogoAjustes;
use App\Ajustes\Correo\ConfiguracionCorreoRuntime;
use App\Ajustes\Definicion\NivelConfirmacion;
use App\Enums\EstadoPedidoSala;
use App\Mail\Pedidos\ResumenDiarioPedidosCorreo;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\PedidoSala;
use App\Models\PersonalRuta;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Rutas\PedidosFabrica;
use App\Services\Rutas\ResumenDiarioPedidos;
use App\Support\Correo\CandadoCorreoReal;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * El correo de las 17:00 con lo que hizo la fábrica en el día (punto G). Datos inventados y
 * correos de ejemplo: los de verdad se cargan en Configuración, nunca en el repositorio.
 */
class CorreoDiarioPedidosTest extends TestCase
{
    use RefreshDatabase;

    private User $fabrica;

    private PersonalRuta $persona;

    private Ruta $ruta;

    private Producto $galleta;

    private Producto $canilla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09 17:00:00');
        config()->set('pedidos.correo_resumen_diario', 'oficina@ejemplo.test, fabrica@ejemplo.test');

        $this->fabrica = User::factory()->create(['activo' => true])->assignRole('fabrica');
        $vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Carlos Inventado', 'user_id' => $vendedor->id]);
        $this->ruta = Ruta::create(['nombre' => 'Ruta Inventada']);

        UnidadMedida::create(['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u', 'activo' => true]);
        $this->galleta = Producto::factory()->create(['nombre' => 'Galleta de prueba', 'codigo' => 'G01', 'precio_unitario' => 7.77]);
        $this->canilla = Producto::factory()->create(['nombre' => 'Canilla de prueba', 'codigo' => 'C01']);
    }

    /** Se sustituye el candado, no el entorno: la suite no puede mandar correo real. */
    private function correoReal(): void
    {
        $this->app->instance(CandadoCorreoReal::class, new class extends CandadoCorreoReal
        {
            public function debeSimular(): bool
            {
                return false;
            }
        });
    }

    private function sala(string $nombre): ClienteSucursal
    {
        return Cliente::factory()->create(['nombre' => $nombre])
            ->sucursales()->create(['nombre' => $nombre, 'codigo' => (string) random_int(1000, 9999), 'ruta_id' => $this->ruta->id]);
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function pedido(ClienteSucursal $sala, array $cantidades, string $fecha = '2026-11-09'): PedidoSala
    {
        $pedido = PedidoSala::create([
            'cliente_sucursal_id' => $sala->id, 'ruta_id' => $this->ruta->id,
            'sacado_por_id' => $this->persona->id, 'registrado_por' => $this->persona->user_id,
            'fecha' => $fecha, 'estado' => EstadoPedidoSala::Registrado,
        ]);
        foreach ($cantidades as $producto => $cantidad) {
            $pedido->lineas()->create(['producto_id' => $producto, 'cantidad' => $cantidad]);
        }

        return $pedido;
    }

    private function version(PedidoSala $pedido): string
    {
        return PedidoSala::query()->with(app(PedidosFabrica::class)->relaciones())->findOrFail($pedido->id)->versionProduccion();
    }

    /** @param  array<int, string>  $cantidades  [producto_id => cantidad] */
    private function mandar(PedidoSala $pedido, array $cantidades, array $motivos = [], bool $sigue = false): void
    {
        $porLinea = fn (array $datos) => collect($datos)->mapWithKeys(
            fn ($valor, $producto) => [$pedido->lineas()->where('producto_id', $producto)->value('id') => $valor],
        )->all();

        app(PedidosFabrica::class)->mandar($pedido, [
            'clave' => (string) Str::uuid(), 'version' => $this->version($pedido),
            'cantidades' => $porLinea($cantidades), 'motivos' => $porLinea($motivos), 'sigue' => $sigue,
        ], $this->fabrica);
    }

    private function enviado(): ResumenDiarioPedidosCorreo
    {
        $correos = Mail::sent(ResumenDiarioPedidosCorreo::class);
        $this->assertCount(1, $correos);

        return $correos->first();
    }

    // ══════════════════════════════ contenido

    public function test_el_correo_dice_por_sala_lo_que_salio_lo_que_falto_lo_que_no_se_trae_y_lo_que_sigue(): void
    {
        Mail::fake();
        $this->correoReal();

        $centro = $this->pedido($this->sala('Súper Ficticio Centro'), [$this->galleta->id => '24', $this->canilla->id => '6']);
        $this->mandar($centro, [$this->galleta->id => '24', $this->canilla->id => '0'], [$this->canilla->id => 'Sin harina']);

        $norte = $this->pedido($this->sala('Tienda Inventada Norte'), [$this->galleta->id => '3']);
        app(PedidosFabrica::class)->cerrar($norte, $this->version($norte), $this->fabrica);

        $this->pedido($this->sala('Abarrotes Inventados'), [$this->canilla->id => '2.5'], '2026-11-06');

        $this->artisan('pedidos:correo-diario')->expectsOutput('Resumen enviado.')->assertSuccessful();

        $correo = $this->enviado();
        $this->assertTrue($correo->hasTo('oficina@ejemplo.test') && $correo->hasTo('fabrica@ejemplo.test'));
        $this->assertSame('Pedidos del 09/11/2026', $correo->envelope()->subject);

        $html = $correo->render();
        $this->assertStringNotContainsString('Hoy no salió ningún pedido', $html);
        $this->assertStringNotContainsString('7.77', $html, 'el resumen no lleva precios');
        // Las salas, por nombre; en cada una lo que pasó.
        $this->assertMatchesRegularExpression('/Abarrotes Inventados.*Sigue por mandar.*Pedido del 06\/11\/2026 \(3 días de atraso\): Canilla de prueba 2\.5/s', $html);
        $this->assertMatchesRegularExpression('/Súper Ficticio Centro.*Salió.*Envío 1 \(17:00\): Galleta de prueba 24.*Faltó.*Canilla de prueba: 6 — Sin harina/s', $html);
        $this->assertMatchesRegularExpression('/Tienda Inventada Norte.*No se trae.*Galleta de prueba: 3/s', $html);
        $this->assertTrue(strpos($html, 'Abarrotes Inventados') < strpos($html, 'Súper Ficticio Centro'));
        $this->assertTrue(strpos($html, 'Súper Ficticio Centro') < strpos($html, 'Tienda Inventada Norte'));
    }

    public function test_lo_que_falta_y_se_da_por_perdido_otro_dia_sale_en_falto_ese_dia(): void
    {
        Mail::fake();
        $this->correoReal();

        // Lunes: sale una parte y lo que falta iba a ir en otro envío.
        $this->travelTo('2026-11-09 10:00:00');
        $pedido = $this->pedido($this->sala('Súper Ficticio Centro'), [$this->galleta->id => '24', $this->canilla->id => '6']);
        $this->mandar($pedido, [$this->galleta->id => '24', $this->canilla->id => '0'], [$this->canilla->id => 'Sin harina'], sigue: true);

        // Martes: «Lo que falta no se trae», sin envío ese día.
        $this->travelTo('2026-11-10 10:00:00');
        app(PedidosFabrica::class)->cerrar($pedido, $this->version($pedido), $this->fabrica);

        $this->travelTo('2026-11-10 17:00:00');
        $this->artisan('pedidos:correo-diario')->assertSuccessful();

        $html = $this->enviado()->render();
        $this->assertMatchesRegularExpression('/Súper Ficticio Centro.*Faltó.*Canilla de prueba: 6 — Sin harina/s', $html);
        $this->assertStringNotContainsString('Sigue por mandar', $html);
    }

    public function test_lo_que_pasa_despues_de_las_cinco_sale_en_el_correo_del_dia_siguiente(): void
    {
        Mail::fake();
        $this->correoReal();
        $centro = $this->pedido($this->sala('Súper Ficticio Centro'), [$this->galleta->id => '5']);
        $norte = $this->pedido($this->sala('Tienda Inventada Norte'), [$this->galleta->id => '3']);
        $sur = $this->pedido($this->sala('Abarrotes Inventados Sur'), [$this->canilla->id => '2']);

        // Lunes: lo de las 16:00 sale en el correo de las 17:00.
        $this->travelTo('2026-11-09 16:00:00');
        $this->mandar($centro, [$this->galleta->id => '5']);
        $this->travelTo('2026-11-09 17:00:00');
        $this->artisan('pedidos:correo-diario')->assertSuccessful();

        // Después de que salió el correo.
        $this->travelTo('2026-11-09 17:20:00');
        $this->mandar($norte, [$this->galleta->id => '3']);
        $this->travelTo('2026-11-09 17:30:00');
        app(PedidosFabrica::class)->anularEnvio($centro, $centro->envios()->sole(), $this->fabrica);
        $this->travelTo('2026-11-09 17:40:00');
        app(PedidosFabrica::class)->cerrar($sur, $this->version($sur), $this->fabrica);

        $this->travelTo('2026-11-10 17:00:00');
        $this->artisan('pedidos:correo-diario')->assertSuccessful();

        $correos = Mail::sent(ResumenDiarioPedidosCorreo::class);
        $this->assertCount(2, $correos);
        $html = $correos->last()->render();
        $this->assertMatchesRegularExpression('/Tienda Inventada Norte.*Salió.*Envío 1 \(09\/11 17:20\): Galleta de prueba 3/s', $html);
        $this->assertMatchesRegularExpression('/Abarrotes Inventados Sur.*No se trae.*Canilla de prueba: 2/s', $html);
        // El envío que el correo del lunes dio por salido se deshizo: se avisa y vuelve a pendiente.
        $this->assertMatchesRegularExpression('/Súper Ficticio Centro.*Se deshizo.*Envío 1 \(09\/11 16:00\): Galleta de prueba 5.*Sigue por mandar/s', $html);
        $this->assertStringNotContainsString('Hoy no salió ningún pedido', $html);
    }

    public function test_lo_escrito_en_la_fabrica_no_mete_enlaces_ni_imagenes_en_el_correo(): void
    {
        Mail::fake();
        $this->correoReal();
        $trampa = '![](https://rastreo.example/p.gif) [Ver detalle](https://phishing.example/login)';

        $pedido = $this->pedido($this->sala('Súper [Ficticio](https://phishing.example/sala) Centro'), [$this->galleta->id => '4', $this->canilla->id => '2']);
        $this->mandar($pedido, [$this->galleta->id => '4', $this->canilla->id => '0'], [$this->canilla->id => $trampa]);

        $this->artisan('pedidos:correo-diario')->assertSuccessful();

        $html = $this->enviado()->render();
        $this->assertStringNotContainsString('src="https://rastreo.example', $html);
        $this->assertStringNotContainsString('href="https://phishing.example', $html);
        // Se lee tal cual se escribió.
        $this->assertStringContainsString('[Ver detalle](https://phishing.example/login)', $html);
        $this->assertStringContainsString('Súper [Ficticio](https://phishing.example/sala) Centro', $html);
    }

    public function test_si_no_salio_nada_dice_hoy_no_salio_ningun_pedido_con_los_pendientes(): void
    {
        Mail::fake();
        $this->correoReal();
        $this->pedido($this->sala('Súper Ficticio Centro'), [$this->galleta->id => '5'], '2026-11-08');

        $this->artisan('pedidos:correo-diario')->assertSuccessful();

        $html = $this->enviado()->render();
        $this->assertStringContainsString('Hoy no salió ningún pedido', $html);
        $this->assertMatchesRegularExpression('/Súper Ficticio Centro.*Sigue por mandar.*Pedido del 08\/11\/2026 \(1 día de atraso\): Galleta de prueba 5/s', $html);
    }

    public function test_lo_que_falta_y_va_en_otro_envio_sale_con_lo_que_sigue_por_mandar(): void
    {
        $pedido = $this->pedido($this->sala('Súper Ficticio Centro'), [$this->galleta->id => '12'], '2026-11-09');
        app(PedidosFabrica::class)->mandar($pedido, [
            'clave' => (string) Str::uuid(), 'version' => $this->version($pedido), 'sigue' => true,
            'cantidades' => [$pedido->lineas()->value('id') => '8'],
        ], $this->fabrica);

        $sala = app(ResumenDiarioPedidos::class)->contenido(Carbon::parse('2026-11-09'), now())['salas']['Súper Ficticio Centro'];

        $this->assertSame([], $sala['falto']);
        $this->assertSame([['fecha' => '09/11/2026', 'atraso' => 0, 'productos' => [['nombre' => 'Galleta de prueba', 'cantidad' => '4']]]], $sala['por_mandar']);
    }

    // ══════════════════════════════ cuándo no se manda

    public function test_sin_correo_cargado_no_manda_nada_y_lo_deja_en_el_log(): void
    {
        Mail::fake();
        $this->correoReal();
        Log::spy();
        config()->set('pedidos.correo_resumen_diario', null);

        $this->artisan('pedidos:correo-diario')
            ->expectsOutput('No se mandó: falta el correo en Configuración › Rutas.')
            ->assertSuccessful();

        Mail::assertNothingSent();
        Log::shouldHaveReceived('info')->withArgs(fn ($mensaje) => str_contains($mensaje, 'resumen diario NO enviado'))->once();
        $this->assertSame(0, Activity::where('description', ResumenDiarioPedidos::AUDITORIA)->count());
    }

    public function test_no_se_repite_el_mismo_dia_aunque_se_borre_la_cache_y_al_otro_dia_si(): void
    {
        Mail::fake();
        $this->correoReal();

        $this->artisan('pedidos:correo-diario')->assertSuccessful();
        Cache::flush(); // como un cache:clear del despliegue: la auditoría sigue marcando
        $this->artisan('pedidos:correo-diario')->expectsOutput('El resumen de hoy ya se había mandado.')->assertSuccessful();

        Mail::assertSentCount(1);
        $auditoria = Activity::where('log_name', 'pedido_sala')->where('description', ResumenDiarioPedidos::AUDITORIA)->sole();
        $this->assertSame(['fecha' => '2026-11-09', 'estado' => 'enviado', 'destinatarios' => 2, 'hasta' => '2026-11-09 17:00:00'], $auditoria->properties->all());
        $this->assertStringNotContainsString('ejemplo.test', $auditoria->properties->toJson(), 'los correos son datos personales');

        $this->travelTo('2026-11-10 17:00:00');
        $this->artisan('pedidos:correo-diario')->assertSuccessful();
        Mail::assertSentCount(2);
    }

    public function test_si_el_envio_falla_no_queda_marcado_y_se_puede_reintentar(): void
    {
        Mail::fake();
        $this->correoReal();
        $this->mock(ConfiguracionCorreoRuntime::class)->shouldReceive('aplicar')->once()
            ->andThrow(new \RuntimeException('El servidor de correo no responde'));

        $this->artisan('pedidos:correo-diario')->assertFailed();
        Mail::assertNothingSent();
        $this->assertSame(0, Activity::where('description', ResumenDiarioPedidos::AUDITORIA)->count());

        $this->app->forgetInstance(ConfiguracionCorreoRuntime::class);
        $this->artisan('pedidos:correo-diario')->expectsOutput('Resumen enviado.')->assertSuccessful();
        Mail::assertSentCount(1);
    }

    public function test_fuera_de_produccion_no_sale_y_queda_como_simulado(): void
    {
        Mail::fake();

        $this->artisan('pedidos:correo-diario')->expectsOutput('Resumen SIMULADO: fuera de producción el correo no sale.')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame('simulado', Activity::where('description', ResumenDiarioPedidos::AUDITORIA)->sole()->properties['estado']);
    }

    // ══════════════════════════════ programación y ajuste

    public function test_se_programa_todos_los_dias_a_las_cinco_de_la_tarde_hora_de_el_salvador(): void
    {
        ScheduleFacade::swap(new Schedule(config('app.timezone')));
        require base_path('routes/console.php');

        $tareas = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $evento) => str_contains((string) $evento->command, 'pedidos:correo-diario'),
        ));

        $this->assertCount(1, $tareas);
        $this->assertSame('0 17 * * *', $tareas[0]->expression);
        $this->assertSame('America/El_Salvador', (string) $tareas[0]->timezone);
        $this->assertTrue($tareas[0]->withoutOverlapping);
    }

    public function test_el_ajuste_es_una_lista_de_correos_n2_y_dato_personal(): void
    {
        $definicion = app(CatalogoAjustes::class)->definicion('pedidos.correo_resumen_diario');

        $this->assertTrue($definicion->esDatoPersonal());
        $this->assertFalse($definicion->valorAuditable());
        $this->assertSame(NivelConfirmacion::N2, $definicion->nivel);
        $this->assertSame('rutas', $definicion->seccion);
        $this->assertContains('correos', $definicion->reglas);
    }
}
