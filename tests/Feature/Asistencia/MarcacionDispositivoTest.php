<?php

namespace Tests\Feature\Asistencia;

use App\Enums\Asistencia\TipoMarcacion;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaMarcacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * LA MARCACIÓN REAL (POST /api/asistencia/marcar).
 *
 * El contrato que estas pruebas fijan, y que es la razón de ser del módulo: el
 * ESP32 manda UN número —la ranura del sensor— y nada más de lo que diga cuenta.
 * Quién es, qué hora es y si es entrada o salida lo decide el servidor. Si alguna
 * de estas pruebas se pone roja por un cambio «pequeño», lo que se rompió es esa
 * regla.
 */
class MarcacionDispositivoTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/asistencia/marcar';

    /** Token FICTICIO de la suite. No abre nada: el lector real tiene el suyo. */
    private const TOKEN = 'token-ficticio-de-pruebas-del-lector';

    private const RANURA = 1;

    private AsistenciaDispositivo $dispositivo;

    private AsistenciaEmpleado $empleado;

    private AsistenciaHuella $huella;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('asistencia.enabled', true);
        config()->set('asistencia.zona_horaria', 'America/El_Salvador');
        config()->set('asistencia.cooldown_segundos', 90);

        $this->dispositivo = AsistenciaDispositivo::create([
            'codigo' => 'lector-entrada',
            'nombre' => 'Entrada principal',
            'token_hash' => AsistenciaDispositivo::hashDeToken(self::TOKEN),
            'activo' => true,
        ]);

        $this->empleado = AsistenciaEmpleado::create([
            'nombres' => 'Ana María',
            'apellidos' => 'Pérez Rivas',
            'activo' => true,
        ]);

        $this->huella = AsistenciaHuella::create([
            'asistencia_empleado_id' => $this->empleado->id,
            'asistencia_dispositivo_id' => $this->dispositivo->id,
            'fingerprint_id' => self::RANURA,
            'activo' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, string> */
    private function credenciales(?string $token = null): array
    {
        return [
            'X-Dispositivo' => $this->dispositivo->codigo,
            'X-Dispositivo-Token' => $token ?? self::TOKEN,
        ];
    }

    /**
     * @param  array<string, string>|null  $cabeceras
     * @param  array<string, mixed>  $extra
     */
    private function marcar(int $ranura = self::RANURA, ?array $cabeceras = null, array $extra = []): TestResponse
    {
        return $this->postJson(
            self::RUTA,
            array_merge(['fingerprint_id' => $ranura], $extra),
            $cabeceras ?? $this->credenciales(),
        );
    }

    // -----------------------------------------------------------------
    // 1. Autenticación del dispositivo.
    // -----------------------------------------------------------------

    public function test_sin_credencial_no_se_puede_marcar(): void
    {
        $this->postJson(self::RUTA, ['fingerprint_id' => self::RANURA])
            ->assertUnauthorized()
            ->assertJson(['ok' => false, 'estado' => 'dispositivo_no_autorizado']);

        $this->assertDatabaseCount('asistencia_marcaciones', 0);
    }

    public function test_con_token_incorrecto_no_se_puede_marcar(): void
    {
        $this->marcar(cabeceras: $this->credenciales('token-equivocado'))
            ->assertUnauthorized()
            ->assertJsonPath('estado', 'dispositivo_no_autorizado');

        $this->assertDatabaseCount('asistencia_marcaciones', 0);
    }

    /** Revocar un lector es poner `activo = false`: su token deja de servir. */
    public function test_un_lector_desactivado_no_puede_marcar(): void
    {
        $this->dispositivo->update(['activo' => false]);

        $this->marcar()->assertUnauthorized();

        $this->assertDatabaseCount('asistencia_marcaciones', 0);
    }

    /**
     * El 401 no distingue entre «no mandaste código», «ese lector no existe» y «el
     * token está mal»: quien pruebe desde la red no debe poder deducir qué códigos
     * de lector están dados de alta.
     */
    public function test_el_401_no_revela_por_que_fallo(): void
    {
        $sinCodigo = $this->postJson(self::RUTA, ['fingerprint_id' => 1], [
            'X-Dispositivo-Token' => self::TOKEN,
        ]);

        $codigoInexistente = $this->postJson(self::RUTA, ['fingerprint_id' => 1], [
            'X-Dispositivo' => 'lector-que-no-existe',
            'X-Dispositivo-Token' => self::TOKEN,
        ]);

        $tokenMalo = $this->marcar(cabeceras: $this->credenciales('otro'));

        foreach ([$sinCodigo, $codigoInexistente, $tokenMalo] as $respuesta) {
            $respuesta->assertUnauthorized()->assertExactJson([
                'ok' => false,
                'estado' => 'dispositivo_no_autorizado',
                'mensaje' => 'Dispositivo no autorizado',
            ]);
        }
    }

    /** El token nunca vuelve en una respuesta, ni en claro ni hasheado. */
    public function test_la_respuesta_nunca_contiene_el_token(): void
    {
        $respuesta = $this->marcar()->assertOk();

        $this->assertStringNotContainsString(self::TOKEN, $respuesta->getContent());
        $this->assertStringNotContainsString($this->dispositivo->token_hash, $respuesta->getContent());
    }

    // -----------------------------------------------------------------
    // 2. Huella desconocida y empleado inactivo.
    // -----------------------------------------------------------------

    public function test_una_ranura_sin_asociar_no_marca_nada(): void
    {
        $this->marcar(ranura: 99)
            ->assertNotFound()
            ->assertJson([
                'ok' => false,
                'estado' => 'huella_desconocida',
                'fingerprint_id' => 99,
            ]);

        $this->assertDatabaseCount('asistencia_marcaciones', 0);
    }

    /** Dar de baja una plantilla la vuelve desconocida, sin borrar la fila. */
    public function test_una_huella_dada_de_baja_se_trata_como_desconocida(): void
    {
        $this->huella->update(['activo' => false]);

        $this->marcar()->assertNotFound()->assertJsonPath('estado', 'huella_desconocida');

        $this->assertDatabaseCount('asistencia_marcaciones', 0);
    }

    /**
     * La ranura 1 del lector A y la ranura 1 del lector B son dos personas
     * distintas: el lector que pregunta es parte de la identidad de la huella.
     * Es la prueba de que el esquema aguanta un segundo dispositivo.
     */
    public function test_la_ranura_pertenece_al_lector_que_pregunta(): void
    {
        $otroLector = AsistenciaDispositivo::create([
            'codigo' => 'lector-bodega',
            'nombre' => 'Bodega',
            'token_hash' => AsistenciaDispositivo::hashDeToken('otro-token-ficticio'),
            'activo' => true,
        ]);

        $this->postJson(self::RUTA, ['fingerprint_id' => self::RANURA], [
            'X-Dispositivo' => $otroLector->codigo,
            'X-Dispositivo-Token' => 'otro-token-ficticio',
        ])->assertNotFound()->assertJsonPath('estado', 'huella_desconocida');

        $this->assertDatabaseCount('asistencia_marcaciones', 0);
    }

    public function test_un_empleado_inactivo_no_marca(): void
    {
        $this->empleado->update(['activo' => false]);

        $this->marcar()
            ->assertForbidden()
            ->assertJsonPath('estado', 'empleado_inactivo');

        $this->assertDatabaseCount('asistencia_marcaciones', 0);
    }

    // -----------------------------------------------------------------
    // 3. Payload.
    // -----------------------------------------------------------------

    public function test_un_payload_sin_fingerprint_id_es_rechazado(): void
    {
        $this->postJson(self::RUTA, [], $this->credenciales())
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'estado' => 'payload_invalido'])
            ->assertJsonStructure(['ok', 'estado', 'mensaje', 'errores' => ['fingerprint_id']]);

        $this->assertDatabaseCount('asistencia_marcaciones', 0);
    }

    public function test_un_fingerprint_id_no_numerico_es_rechazado(): void
    {
        $this->postJson(self::RUTA, ['fingerprint_id' => 'uno'], $this->credenciales())
            ->assertStatus(422)
            ->assertJsonPath('estado', 'payload_invalido');

        $this->assertDatabaseCount('asistencia_marcaciones', 0);
    }

    // -----------------------------------------------------------------
    // 4. Marcación válida: entrada.
    // -----------------------------------------------------------------

    public function test_la_primera_marcacion_del_dia_es_entrada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 13:02:10', 'UTC')); // 07:02:10 en SV

        $this->marcar()->assertOk()->assertJson([
            'ok' => true,
            'estado' => 'registrada',
            'mensaje' => 'Entrada registrada',
            'empleado' => [
                'id' => $this->empleado->id,
                'nombre' => 'Ana María Pérez Rivas',
                'nombre_corto' => 'Ana Pérez',
            ],
            'marcacion' => [
                'tipo' => 'entrada',
                'tipo_label' => 'Entrada',
                'fecha' => '2026-08-20',
                'hora' => '07:02:10',
                'fecha_hora' => '2026-08-20 07:02:10',
                'zona' => 'America/El_Salvador',
            ],
        ]);

        $this->assertDatabaseCount('asistencia_marcaciones', 1);

        $marcacion = AsistenciaMarcacion::first();
        $this->assertSame(TipoMarcacion::Entrada, $marcacion->tipo);
        $this->assertSame($this->empleado->id, $marcacion->asistencia_empleado_id);
        $this->assertSame($this->dispositivo->id, $marcacion->asistencia_dispositivo_id);
        $this->assertSame($this->huella->id, $marcacion->asistencia_huella_id);
        $this->assertSame('dispositivo', $marcacion->origen);
        // Se guarda el instante en UTC; el día es el LOCAL de la zona oficial.
        $this->assertSame('2026-08-20 13:02:10', $marcacion->marcado_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-20', $marcacion->fecha_local->format('Y-m-d'));
    }

    /**
     * LA REGLA DEL MÓDULO: la hora la pone el servidor. El dispositivo puede
     * mandar lo que quiera —su reloj desfasado, otro empleado, otro tipo— y nada
     * de eso se lee. Es la prueba que impide que un firmware con el reloj perdido
     * (o alguien en la red con el token) fabrique una marcación a su conveniencia.
     */
    public function test_la_hora_y_el_tipo_los_pone_el_servidor_no_el_dispositivo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 13:02:10', 'UTC'));

        $otro = AsistenciaEmpleado::create([
            'nombres' => 'Otro',
            'apellidos' => 'Empleado',
            'activo' => true,
        ]);

        $this->marcar(extra: [
            'fecha' => '2001-01-01',
            'hora' => '23:59:59',
            'fecha_hora' => '2001-01-01 23:59:59',
            'marcado_at' => '2001-01-01 23:59:59',
            'tipo' => 'salida',
            'empleado_id' => $otro->id,
            'asistencia_empleado_id' => $otro->id,
        ])->assertOk()
            ->assertJsonPath('marcacion.fecha', '2026-08-20')
            ->assertJsonPath('marcacion.hora', '07:02:10')
            ->assertJsonPath('marcacion.tipo', 'entrada')
            ->assertJsonPath('empleado.id', $this->empleado->id);

        $marcacion = AsistenciaMarcacion::first();
        $this->assertSame($this->empleado->id, $marcacion->asistencia_empleado_id);
        $this->assertSame(TipoMarcacion::Entrada, $marcacion->tipo);
        $this->assertSame('2026-08-20 13:02:10', $marcacion->marcado_at->utc()->format('Y-m-d H:i:s'));
    }

    // -----------------------------------------------------------------
    // 5. Doble marcación accidental (ventana de cortesía).
    // -----------------------------------------------------------------

    public function test_el_dedo_repetido_no_genera_una_segunda_marcacion(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 13:02:10', 'UTC'));
        $this->marcar()->assertOk();

        // Dos segundos después: el mismo dedo, sin querer.
        Carbon::setTestNow(Carbon::parse('2026-08-20 13:02:12', 'UTC'));

        $this->marcar()->assertStatus(409)->assertJson([
            'ok' => false,
            'estado' => 'cooldown',
            'mensaje' => 'Ya marcaste Entrada a las 07:02:10',
            'empleado' => ['nombre_corto' => 'Ana Pérez'],
            'espera_segundos' => 88,
            'marcacion_previa' => ['tipo' => 'entrada', 'hora' => '07:02:10'],
        ]);

        // Lo que de verdad importa: NO existe una jornada de dos segundos.
        $this->assertDatabaseCount('asistencia_marcaciones', 1);
    }

    /** El límite exacto: dentro de la ventana no cuenta; al cumplirse, sí. */
    public function test_la_ventana_de_cortesia_termina_en_el_segundo_configurado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 13:00:00', 'UTC'));
        $this->marcar()->assertOk();

        // Segundo 89: todavía dentro.
        Carbon::setTestNow(Carbon::parse('2026-08-20 13:01:29', 'UTC'));
        $this->marcar()->assertStatus(409)->assertJsonPath('espera_segundos', 1);
        $this->assertDatabaseCount('asistencia_marcaciones', 1);

        // Segundo 90: la marcación cuenta.
        Carbon::setTestNow(Carbon::parse('2026-08-20 13:01:30', 'UTC'));
        $this->marcar()->assertOk()->assertJsonPath('marcacion.tipo', 'salida');
        $this->assertDatabaseCount('asistencia_marcaciones', 2);
    }

    /**
     * La ventana mira la última marcación de la PERSONA, no la del día: dos toques
     * separados por diez segundos son un dedo repetido aunque caigan a un lado y
     * otro de la medianoche.
     */
    public function test_la_ventana_de_cortesia_cruza_la_medianoche(): void
    {
        // 23:59:55 del 20 en El Salvador = 05:59:55 UTC del 21.
        Carbon::setTestNow(Carbon::parse('2026-08-21 05:59:55', 'UTC'));
        $this->marcar()->assertOk()->assertJsonPath('marcacion.fecha', '2026-08-20');

        // Diez segundos después ya es otro día local, pero sigue siendo el mismo dedo.
        Carbon::setTestNow(Carbon::parse('2026-08-21 06:00:05', 'UTC'));
        $this->marcar()->assertStatus(409);

        $this->assertDatabaseCount('asistencia_marcaciones', 1);
    }

    // -----------------------------------------------------------------
    // 6. La siguiente marcación válida.
    // -----------------------------------------------------------------

    public function test_la_siguiente_marcacion_valida_del_dia_es_salida(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 13:02:10', 'UTC')); // 07:02
        $this->marcar()->assertOk()->assertJsonPath('marcacion.tipo', 'entrada');

        Carbon::setTestNow(Carbon::parse('2026-08-20 22:30:00', 'UTC')); // 16:30
        $this->marcar()->assertOk()
            ->assertJsonPath('marcacion.tipo', 'salida')
            ->assertJsonPath('marcacion.hora', '16:30:00')
            ->assertJsonPath('mensaje', 'Salida registrada');

        $this->assertDatabaseCount('asistencia_marcaciones', 2);
    }

    /** Almuerzo: se ALTERNA, no se cuenta «la primera y la segunda». */
    public function test_las_marcaciones_del_dia_se_alternan(): void
    {
        $jornada = [
            '2026-08-20 13:00:00' => 'entrada', // 07:00 llega
            '2026-08-20 18:00:00' => 'salida',  // 12:00 almuerzo
            '2026-08-20 19:00:00' => 'entrada', // 13:00 regresa
            '2026-08-20 23:00:00' => 'salida',  // 17:00 se va
        ];

        foreach ($jornada as $utc => $tipoEsperado) {
            Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
            $this->marcar()->assertOk()->assertJsonPath('marcacion.tipo', $tipoEsperado);
        }

        $this->assertDatabaseCount('asistencia_marcaciones', 4);
    }

    /** Cada día local vuelve a empezar en entrada, aunque el anterior cerrara mal. */
    public function test_el_dia_siguiente_vuelve_a_empezar_en_entrada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 13:00:00', 'UTC'));
        $this->marcar()->assertOk()->assertJsonPath('marcacion.tipo', 'entrada');

        // Ayer quedó una entrada sin salida; hoy igual entra.
        Carbon::setTestNow(Carbon::parse('2026-08-21 13:00:00', 'UTC'));
        $this->marcar()->assertOk()
            ->assertJsonPath('marcacion.tipo', 'entrada')
            ->assertJsonPath('marcacion.fecha', '2026-08-21');
    }

    // -----------------------------------------------------------------
    // 7. Interruptor del módulo y telemetría del lector.
    // -----------------------------------------------------------------

    public function test_con_el_modulo_apagado_marcar_responde_404(): void
    {
        config()->set('asistencia.enabled', false);

        $this->marcar()->assertNotFound();

        $this->assertDatabaseCount('asistencia_marcaciones', 0);
    }

    public function test_una_marcacion_deja_constancia_de_cuando_se_vio_al_lector(): void
    {
        $this->assertNull($this->dispositivo->ultima_conexion_at);

        Carbon::setTestNow(Carbon::parse('2026-08-20 13:02:10', 'UTC'));
        $this->marcar()->assertOk();

        $this->assertNotNull($this->dispositivo->refresh()->ultima_conexion_at);
    }
}
