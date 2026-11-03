<?php

namespace Tests\Feature\Alertas;

use App\Services\Alertas\Avisador;
use App\Support\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * /salud (issue #163): pública, 200 solo si anda todo, y si algo cae dice QUÉ, sin
 * filtrar hosts, puertos, rutas ni configuración.
 */
class SaludPublicaTest extends TestCase
{
    use RefreshDatabase;

    private const FIRMADOR_1 = 'http://127.0.0.1:8080/firmardocumento/status';

    private const FIRMADOR_2 = 'http://127.0.0.1:8119/firmardocumento/status';

    private const SONDA = 'https://sonda.example.test/generate_204';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'database',
            'alertas.salud.firmadores' => self::FIRMADOR_1.','.self::FIRMADOR_2,
            'alertas.salud.disco_minimo_mb' => 1,
            'dte.contingencia.sonda_internet_url' => self::SONDA,
        ]);

        WorkerHeartbeat::olvidar();
        WorkerHeartbeat::pulse();
    }

    protected function tearDown(): void
    {
        WorkerHeartbeat::olvidar();

        parent::tearDown();
    }

    public function test_responde_200_cuando_todo_anda(): void
    {
        $this->fakeRed();

        $this->get('/salud')
            ->assertOk()
            ->assertJson(['estado' => 'ok', 'fallan' => []])
            ->assertJsonPath('componentes.base_de_datos.ok', true)
            ->assertJsonPath('componentes.cola.ok', true)
            ->assertJsonPath('componentes.firmadores.ok', true)
            ->assertJsonPath('componentes.disco.ok', true)
            ->assertJsonPath('componentes.hora.ok', true);
    }

    public function test_es_publica_y_no_abre_sesion(): void
    {
        $this->fakeRed();

        $respuesta = $this->get('/salud')->assertOk();

        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
        $this->assertGuest();
        $this->assertEmpty($respuesta->headers->getCookies());
    }

    public function test_si_cae_un_firmador_dice_cual_sin_dar_su_direccion(): void
    {
        $this->fakeRed(firmador2: 500);

        $respuesta = $this->get('/salud')
            ->assertStatus(503)
            ->assertJson(['estado' => 'falla', 'fallan' => ['firmadores']])
            ->assertJsonPath('componentes.firmadores.motivo', 'No responde el firmador n.º 2.');

        $this->assertNoFiltraDatos($respuesta->getContent());
    }

    public function test_el_worker_sin_latido_hace_caer_la_cola(): void
    {
        $this->fakeRed();
        WorkerHeartbeat::olvidar();

        $this->get('/salud')->assertStatus(503)->assertJson(['fallan' => ['cola']]);
    }

    public function test_un_trabajo_esperando_demasiado_hace_caer_la_cola(): void
    {
        $this->fakeRed();
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{"displayName":"App\\\\Jobs\\\\Algo","datos":"cliente-secreto"}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->subMinutes(30)->getTimestamp(), 'created_at' => now()->subMinutes(30)->getTimestamp(),
        ]);

        $respuesta = $this->get('/salud')->assertStatus(503)->assertJson(['fallan' => ['cola']]);

        $this->assertStringNotContainsString('cliente-secreto', $respuesta->getContent());
    }

    public function test_el_reloj_corrido_o_la_zona_equivocada_hacen_caer_la_hora(): void
    {
        $this->fakeRed(desfaseSegundos: 600);
        $this->get('/salud')->assertStatus(503)->assertJson(['fallan' => ['hora']]);

        $this->fakeRed();
        config(['app.timezone' => 'UTC']);
        $this->get('/salud')->assertStatus(503)
            ->assertJsonPath('componentes.hora.motivo', 'La zona horaria del sistema no es la de El Salvador.');
    }

    public function test_sin_internet_no_culpa_al_reloj(): void
    {
        Http::fake([
            self::SONDA => Http::failedConnection(),
            '*' => Http::response('{"status":"OK"}'),
        ]);

        $this->get('/salud')->assertOk()->assertJsonPath('componentes.hora.ok', true);
    }

    public function test_poco_disco_hace_caer_el_disco(): void
    {
        $this->fakeRed();
        config(['alertas.salud.disco_minimo_mb' => PHP_INT_MAX >> 21]);

        $this->get('/salud')->assertStatus(503)->assertJson(['fallan' => ['disco']]);
    }

    public function test_sin_lista_de_firmadores_y_con_la_firma_apagada_no_los_revisa(): void
    {
        $this->fakeRed(firmador1: 500, firmador2: 500);
        config(['alertas.salud.firmadores' => '', 'dte.firma.enabled' => false]);

        $this->get('/salud')->assertOk();
    }

    public function test_con_avisos_encendidos_revisa_que_el_planificador_corra(): void
    {
        $this->fakeRed();
        config(['alertas.enabled' => true]);

        $this->get('/salud')->assertStatus(503)->assertJson(['fallan' => ['avisos']]);

        Cache::put(Avisador::CLAVE_ULTIMA_REVISION, now()->getTimestamp());
        $this->get('/salud')->assertOk();
    }

    public function test_la_respuesta_no_trae_mas_que_el_estado(): void
    {
        $this->fakeRed(firmador1: 500);
        WorkerHeartbeat::olvidar();

        $respuesta = $this->get('/salud')->assertStatus(503);

        $this->assertSame(['estado', 'fallan', 'componentes', 'revisado'], array_keys($respuesta->json()));
        foreach ($respuesta->json('componentes') as $componente) {
            $this->assertSame([], array_diff(array_keys($componente), ['ok', 'motivo']));
        }
        $this->assertNoFiltraDatos($respuesta->getContent());
    }

    public function test_tiene_limite_de_pedidos(): void
    {
        $this->fakeRed();

        for ($i = 0; $i < 30; $i++) {
            $this->get('/salud');
        }

        $this->get('/salud')->assertStatus(429);
    }

    private function fakeRed(int $firmador1 = 200, int $firmador2 = 200, int $desfaseSegundos = 0): void
    {
        Http::fake([
            self::FIRMADOR_1 => Http::response('{"status":"OK"}', $firmador1),
            self::FIRMADOR_2 => Http::response('{"status":"OK"}', $firmador2),
            self::SONDA => Http::response('', 204, ['Date' => now()->addSeconds($desfaseSegundos)->toRfc7231String()]),
        ]);
    }

    private function assertNoFiltraDatos(string $cuerpo): void
    {
        // Sin los escapes de JSON, para comparar contra las rutas y URL tal cual.
        $cuerpo = json_encode(json_decode($cuerpo, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach (['127.0.0.1', '8080', '8119', 'firmardocumento', 'sonda.example', (string) config('database.default'),
            (string) config('app.url'), storage_path(), base_path()] as $dato) {
            $this->assertStringNotContainsString($dato, $cuerpo);
        }
    }
}
