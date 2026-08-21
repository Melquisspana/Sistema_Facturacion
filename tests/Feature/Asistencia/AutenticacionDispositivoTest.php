<?php

namespace Tests\Feature\Asistencia;

use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Services\Asistencia\AutenticadorDispositivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EL CONTRATO DE AUTENTICACIÓN DEL LECTOR, aislado del resto del módulo.
 *
 * Nace de un 401 real: el ESP32 mandaba sus cabeceras y el servidor lo rechazaba.
 * El servidor estaba bien —el token guardado no coincidía con el del firmware—,
 * pero el episodio dejó claro que la forma EXACTA en que se guarda y se compara el
 * token no estaba fijada por ninguna prueba, y es justo lo que rompe el login sin
 * dar ninguna pista.
 *
 * Lo que se fija acá:
 *   1. token correcto  -> el lector queda autorizado (no hay 401);
 *   2. token incorrecto -> 401 `dispositivo_no_autorizado`;
 *   3. el token se guarda como SHA-256 hex de 64 caracteres, NUNCA en claro;
 *   4. un token guardado en claro NO autentica —el modo de fallo más probable si
 *      alguien alguna vez llena esa columna a mano—;
 *   5. rotar el token invalida el anterior.
 */
class AutenticacionDispositivoTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/asistencia/marcar';

    /** Token FICTICIO de la suite. No abre nada. */
    private const TOKEN = 'token-ficticio-de-pruebas-del-lector';

    private AsistenciaDispositivo $dispositivo;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('asistencia.enabled', true);

        $this->dispositivo = AsistenciaDispositivo::create([
            'codigo' => 'lector-entrada',
            'nombre' => 'Entrada principal',
            'token_hash' => AsistenciaDispositivo::hashDeToken(self::TOKEN),
            'activo' => true,
        ]);

        $empleado = AsistenciaEmpleado::create([
            'nombres' => 'Ana',
            'apellidos' => 'Pérez',
            'activo' => true,
        ]);

        AsistenciaHuella::create([
            'asistencia_empleado_id' => $empleado->id,
            'asistencia_dispositivo_id' => $this->dispositivo->id,
            'fingerprint_id' => 1,
            'activo' => true,
        ]);
    }

    /** Las dos cabeceras EXACTAS que el firmware tiene que mandar. */
    private function cabeceras(string $token): array
    {
        return [
            AutenticadorDispositivo::CABECERA_CODIGO => 'lector-entrada',
            AutenticadorDispositivo::CABECERA_TOKEN => $token,
        ];
    }

    /** 1. Token correcto: el lector queda autorizado. */
    public function test_con_el_token_correcto_el_dispositivo_queda_autorizado(): void
    {
        $respuesta = $this->postJson(self::RUTA, ['fingerprint_id' => 1], $this->cabeceras(self::TOKEN));

        $respuesta->assertOk();
        $this->assertNotSame(401, $respuesta->status(), 'El token correcto no puede dar 401.');
    }

    /** 2. Token incorrecto: 401 y ni una fila escrita. */
    public function test_con_el_token_incorrecto_responde_401(): void
    {
        $this->postJson(self::RUTA, ['fingerprint_id' => 1], $this->cabeceras('token-que-no-es'))
            ->assertUnauthorized()
            ->assertExactJson([
                'ok' => false,
                'estado' => 'dispositivo_no_autorizado',
                'mensaje' => 'Dispositivo no autorizado',
            ]);

        $this->assertDatabaseCount('asistencia_marcaciones', 0);
    }

    /**
     * 3. Formato de almacenamiento. Si esto cambia sin querer, el firmware deja de
     * autenticar y el 401 no dice por qué.
     */
    public function test_el_token_se_guarda_como_sha256_y_nunca_en_claro(): void
    {
        $guardado = $this->dispositivo->fresh()->token_hash;

        $this->assertSame(hash('sha256', self::TOKEN), $guardado);
        $this->assertSame(64, strlen($guardado));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $guardado);
        $this->assertNotSame(self::TOKEN, $guardado, 'El token en claro NUNCA se guarda.');
    }

    /**
     * 4. El modo de fallo por DATOS: si alguien llenara `token_hash` con el token
     * en claro, la autenticación falla igual. Documentado para que un 401 futuro
     * se busque acá y no en el firmware.
     */
    public function test_un_token_guardado_en_claro_no_autentica(): void
    {
        $this->dispositivo->update(['token_hash' => self::TOKEN]);

        $this->postJson(self::RUTA, ['fingerprint_id' => 1], $this->cabeceras(self::TOKEN))
            ->assertUnauthorized()
            ->assertJsonPath('estado', 'dispositivo_no_autorizado');
    }

    /** 5. Rotar el token deja fuera al firmware anterior, y solo a ese lector. */
    public function test_rotar_el_token_invalida_el_anterior(): void
    {
        $nuevo = AsistenciaDispositivo::generarToken();
        $this->dispositivo->update(['token_hash' => AsistenciaDispositivo::hashDeToken($nuevo)]);

        $this->postJson(self::RUTA, ['fingerprint_id' => 1], $this->cabeceras(self::TOKEN))
            ->assertUnauthorized();

        $this->postJson(self::RUTA, ['fingerprint_id' => 1], $this->cabeceras($nuevo))
            ->assertOk();
    }

    /** Un token generado tiene 64 caracteres alfanuméricos: seguro de copiar. */
    public function test_el_token_generado_es_de_64_caracteres_alfanumericos(): void
    {
        $token = AsistenciaDispositivo::generarToken();

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $token,
            'Sin símbolos: el token se copia a mano a un firmware en C.');
    }
}
