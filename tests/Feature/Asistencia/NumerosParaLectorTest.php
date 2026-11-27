<?php

namespace Tests\Feature\Asistencia;

use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Services\Asistencia\NumeroParaMarcar;
use Database\Factories\Asistencia\AsistenciaDispositivoFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GET /api/asistencia/numeros y `numeros_version` del ping.
 *
 * El lector se lleva la lista para reconocer números sin red. Nunca va el número en
 * claro: va sha256(sal . número), con una sal que cambia con la versión.
 */
class NumerosParaLectorTest extends TestCase
{
    use RefreshDatabase;

    private AsistenciaDispositivo $lector;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('asistencia.enabled', true);
        $this->lector = AsistenciaDispositivo::factory()->create();
    }

    /** @return array<string, string> */
    private function credenciales(): array
    {
        return ['X-Dispositivo' => $this->lector->codigo, 'X-Dispositivo-Token' => AsistenciaDispositivoFactory::TOKEN_DE_PRUEBA];
    }

    private function lista(): TestResponse
    {
        return $this->getJson('/api/asistencia/numeros', $this->credenciales());
    }

    private function conNumero(string $nombres, string $numero, bool $activo = true): AsistenciaEmpleado
    {
        $empleado = AsistenciaEmpleado::factory()->create(['nombres' => $nombres, 'apellidos' => 'de prueba', 'activo' => $activo]);
        app(NumeroParaMarcar::class)->asignar($empleado, $numero);

        return $empleado;
    }

    public function test_lista_solo_activos_con_numero_y_nunca_el_numero_en_claro(): void
    {
        $ana = $this->conNumero('Empleado Uno', '4821');
        $beto = $this->conNumero('Empleado Dos', '0007');
        $this->conNumero('Empleado Inactivo', '5930', activo: false);
        AsistenciaEmpleado::factory()->create(['nombres' => 'Empleado Sin', 'apellidos' => 'número']);

        $respuesta = $this->lista()->assertOk();
        $datos = $respuesta->json();

        $this->assertSame(['version', 'sal', 'numeros'], array_keys($datos));
        $this->assertIsString($datos['version']);
        $this->assertNotSame('', $datos['version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $datos['sal']);
        $this->assertCount(2, $datos['numeros']);

        $porId = collect($datos['numeros'])->keyBy('e');
        $this->assertSame([$ana->id, $beto->id], $porId->keys()->sort()->values()->all());
        foreach ($datos['numeros'] as $fila) {
            $this->assertSame(['e', 'h', 'n'], array_keys($fila));
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $fila['h']);
        }

        // h = sha256(sal . número), calculado aparte con los números que conoce la prueba.
        $this->assertSame(hash('sha256', $datos['sal'].'4821'), $porId[$ana->id]['h']);
        $this->assertSame(hash('sha256', $datos['sal'].'0007'), $porId[$beto->id]['h']);
        $this->assertSame('Empleado de', $porId[$ana->id]['n']);

        $crudo = $respuesta->getContent();
        foreach (['4821', '0007', '5930'] as $numero) {
            $this->assertStringNotContainsString('"'.$numero.'"', $crudo);
        }
        foreach ($datos['numeros'] as $fila) {
            $this->assertNotContains($fila['h'], ['4821', '0007', 4821, 7]);
            $this->assertStringNotContainsString('4821', $fila['n']);
        }
        $this->assertStringNotContainsString(NumeroParaMarcar::indice('4821'), $crudo, 'Tampoco el índice de la base.');
    }

    public function test_sin_nadie_con_numero_la_lista_va_vacia(): void
    {
        AsistenciaEmpleado::factory()->create();

        $this->lista()->assertOk()->assertJsonPath('numeros', [])->assertJsonStructure(['version', 'sal', 'numeros']);
    }

    public function test_sin_credencial_de_lector_no_hay_lista(): void
    {
        $this->conNumero('Empleado', '4821');

        $this->getJson('/api/asistencia/numeros')->assertUnauthorized();
        $this->getJson('/api/asistencia/numeros', ['X-Dispositivo' => $this->lector->codigo, 'X-Dispositivo-Token' => 'otro'])->assertUnauthorized();
    }

    public function test_con_el_modulo_apagado_no_existe(): void
    {
        config()->set('asistencia.enabled', false);

        $this->getJson('/api/asistencia/numeros', $this->credenciales())->assertNotFound();
    }

    public function test_la_version_es_estable_y_cambia_con_lo_que_ve_el_lector(): void
    {
        $empleado = $this->conNumero('Empleado', '4821');

        $v1 = $this->lista()->json();
        $this->assertSame($v1, $this->lista()->json(), 'Sin cambios, misma versión y misma sal.');

        app(NumeroParaMarcar::class)->asignar($empleado, '5930');
        $v2 = $this->lista()->json();
        $this->assertNotSame($v1['version'], $v2['version']);
        $this->assertNotSame($v1['sal'], $v2['sal'], 'La sal cambia con la versión.');

        $empleado->update(['nombres' => 'Renombrado']);
        $v3 = $this->lista()->json('version');
        $this->assertNotSame($v2['version'], $v3);

        $empleado->update(['activo' => false]);
        $v4 = $this->lista()->json('version');
        $this->assertNotSame($v3, $v4);

        $empleado->update(['activo' => true]);
        app(NumeroParaMarcar::class)->quitar($empleado);
        $this->assertNotSame($v3, $this->lista()->json('version'));
    }

    public function test_el_nombre_para_la_pantalla_no_pasa_de_24_caracteres(): void
    {
        $empleado = AsistenciaEmpleado::factory()->create(['nombres' => 'Maximilianoooooooooo Segundo', 'apellidos' => 'Hernandezzzzzzzzzz Otro']);
        app(NumeroParaMarcar::class)->asignar($empleado, '4821');

        $nombre = $this->lista()->json('numeros.0.n');
        $this->assertSame(24, mb_strlen($nombre));
        $this->assertSame('Maximilianoooooooooo Her', $nombre);
    }

    public function test_el_ping_con_credencial_trae_la_misma_version_que_la_lista(): void
    {
        $this->conNumero('Empleado', '4821');

        $ping = $this->getJson('/api/asistencia/ping', $this->credenciales())->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('dispositivo.reconocido', true)
            ->assertJsonStructure(['ok', 'mensaje', 'servidor', 'dispositivo', 'numeros_version']);

        $this->assertSame($this->lista()->json('version'), $ping->json('numeros_version'));
    }

    public function test_el_ping_sin_credencial_valida_no_cuenta_la_version(): void
    {
        $this->conNumero('Empleado', '4821');

        $this->getJson('/api/asistencia/ping')->assertOk()->assertJsonMissingPath('numeros_version');
        $this->getJson('/api/asistencia/ping', ['X-Dispositivo' => $this->lector->codigo, 'X-Dispositivo-Token' => 'otro'])
            ->assertOk()->assertJsonPath('dispositivo.reconocido', false)->assertJsonMissingPath('numeros_version');
    }
}
