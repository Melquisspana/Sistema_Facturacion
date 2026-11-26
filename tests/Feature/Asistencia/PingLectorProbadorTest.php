<?php

namespace Tests\Feature\Asistencia;

use App\Models\Asistencia\AsistenciaDispositivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Probador de la tanda 5: el ping del lector con credenciales a medias, de un lector
 * apagado o de otro lector. Nunca escribe y nunca reconoce lo que no corresponde.
 * Tokens inventados.
 */
class PingLectorProbadorTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-prueba-del-lector';

    private const TOKEN_OTRO = 'token-de-prueba-del-otro-lector';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('asistencia.enabled', true);

        AsistenciaDispositivo::create([
            'codigo' => 'lector-prueba',
            'nombre' => 'Lector de prueba',
            'token_hash' => AsistenciaDispositivo::hashDeToken(self::TOKEN),
            'activo' => true,
        ]);
        AsistenciaDispositivo::create([
            'codigo' => 'lector-apagado',
            'nombre' => 'Lector apagado de prueba',
            'token_hash' => AsistenciaDispositivo::hashDeToken(self::TOKEN_OTRO),
            'activo' => false,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function lectores(): array
    {
        return DB::table('asistencia_dispositivos')->orderBy('id')->get()->map(fn ($f) => (array) $f)->all();
    }

    public function test_credenciales_a_medias_cruzadas_o_de_un_lector_apagado_no_se_reconocen_ni_escriben(): void
    {
        $antes = $this->lectores();
        $escrituras = [];
        DB::listen(function ($consulta) use (&$escrituras) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $consulta->sql) === 1) {
                $escrituras[] = $consulta->sql;
            }
        });

        $casos = [
            'solo el código' => ['X-Dispositivo' => 'lector-prueba'],
            'solo el token' => ['X-Dispositivo-Token' => self::TOKEN],
            'token de otro lector' => ['X-Dispositivo' => 'lector-prueba', 'X-Dispositivo-Token' => self::TOKEN_OTRO],
            'lector apagado con su token' => ['X-Dispositivo' => 'lector-apagado', 'X-Dispositivo-Token' => self::TOKEN_OTRO],
            'lector que no existe' => ['X-Dispositivo' => 'lector-inventado', 'X-Dispositivo-Token' => self::TOKEN],
        ];

        foreach ($casos as $caso => $cabeceras) {
            foreach (['getJson', 'postJson'] as $verbo) {
                $respuesta = $verbo === 'getJson'
                    ? $this->getJson(route('api.asistencia.ping'), $cabeceras)
                    : $this->postJson(route('api.asistencia.ping'), [], $cabeceras);

                $respuesta->assertOk()
                    ->assertJsonPath('ok', true)
                    ->assertJsonPath('dispositivo.reconocido', false)
                    ->assertJsonPath('dispositivo.nombre', null);
                $this->assertStringNotContainsString('token-de-prueba', $respuesta->getContent(), "«{$caso}» no debió devolver el token.");
            }
        }

        $this->assertSame([], $escrituras);
        $this->assertEquals($antes, $this->lectores());
    }
}
