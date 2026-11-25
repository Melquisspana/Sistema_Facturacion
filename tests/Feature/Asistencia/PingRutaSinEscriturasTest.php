<?php

namespace Tests\Feature\Asistencia;

use App\Models\Asistencia\AsistenciaDispositivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El ping del lector de huella por su NOMBRE de ruta (`api.asistencia.ping`), con y
 * sin credenciales, por GET y por POST: no escribe NADA en la base.
 *
 * {@see DispositivoPingTest} ya lo demuestra sin credenciales y sin base. Acá hay un
 * lector dado de alta, así que se mira cada consulta: con credenciales el ping lee el
 * lector para decir si lo reconoce, pero no le anota la última conexión ni crea nada.
 * Token inventado.
 */
class PingRutaSinEscriturasTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-prueba-del-lector';

    private AsistenciaDispositivo $dispositivo;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('asistencia.enabled', true);

        $this->dispositivo = AsistenciaDispositivo::create([
            'codigo' => 'lector-prueba',
            'nombre' => 'Lector de prueba',
            'token_hash' => AsistenciaDispositivo::hashDeToken(self::TOKEN),
            'activo' => true,
        ]);
    }

    /**
     * Corre la petición y devuelve las consultas que escriben.
     *
     * @return list<string>
     */
    private function escriturasDurante(callable $peticion): array
    {
        $escrituras = [];
        DB::listen(function ($consulta) use (&$escrituras) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $consulta->sql) === 1) {
                $escrituras[] = $consulta->sql;
            }
        });

        $peticion();

        return $escrituras;
    }

    /** @return array<string, string> */
    private function fila(): array
    {
        return (array) DB::table('asistencia_dispositivos')->where('id', $this->dispositivo->id)->first();
    }

    public function test_el_ping_no_escribe_nada_con_o_sin_credenciales(): void
    {
        $antes = $this->fila();

        $casos = [
            'GET sin credenciales' => ['getJson', []],
            'POST sin credenciales' => ['postJson', []],
            'GET con credencial válida' => ['getJson', ['X-Dispositivo' => 'lector-prueba', 'X-Dispositivo-Token' => self::TOKEN]],
            'POST con credencial válida' => ['postJson', ['X-Dispositivo' => 'lector-prueba', 'X-Dispositivo-Token' => self::TOKEN]],
            'GET con credencial mala' => ['getJson', ['X-Dispositivo' => 'lector-prueba', 'X-Dispositivo-Token' => 'otro-token']],
        ];

        foreach ($casos as $caso => [$verbo, $cabeceras]) {
            $escrituras = $this->escriturasDurante(function () use ($verbo, $cabeceras) {
                $respuesta = $verbo === 'getJson'
                    ? $this->getJson(route('api.asistencia.ping'), $cabeceras)
                    : $this->postJson(route('api.asistencia.ping'), [], $cabeceras);

                $respuesta->assertOk()->assertJsonPath('ok', true);
            });

            $this->assertSame([], $escrituras, "«{$caso}» no debió escribir en la base.");
        }

        // El lector queda igual: ni última conexión ni IP ni fecha de cambio.
        $this->assertEquals($antes, $this->fila());
        $this->assertDatabaseCount('asistencia_dispositivos', 1);
    }

    public function test_con_el_modulo_apagado_da_404_y_tampoco_escribe(): void
    {
        config()->set('asistencia.enabled', false);

        $escrituras = $this->escriturasDurante(function () {
            $this->getJson(route('api.asistencia.ping'), [
                'X-Dispositivo' => 'lector-prueba',
                'X-Dispositivo-Token' => self::TOKEN,
            ])->assertNotFound();
        });

        $this->assertSame([], $escrituras);
    }
}
