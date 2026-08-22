<?php

namespace Tests\Feature\Asistencia;

use App\Enums\Asistencia\EstadoOrdenEnrolamiento;
use App\Enums\Asistencia\MotivoFalloEnrolamiento;
use App\Exceptions\Asistencia\EnrolamientoImposibleException;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaOrdenEnrolamiento;
use App\Services\Asistencia\AsignarHuella;
use App\Services\Asistencia\CrearOrdenEnrolamiento;
use App\Services\Asistencia\LiberarHuella;
use Database\Factories\Asistencia\AsistenciaDispositivoFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EL CONTRATO Laravel ↔ ESP32 del enrolamiento, ejercitado de extremo a extremo
 * con peticiones simuladas.
 *
 * Todavía no hay firmware, así que estas pruebas SON el lector: sondean, reportan
 * progreso y confirman resultados exactamente como lo hará el ESP32. Lo que no
 * pueden comprobar es que el AS608 grabe de verdad; todo lo demás sí.
 *
 * ────────────────── Las tres garantías que vigilan ──────────────────
 *
 *  1. NO nace una `asistencia_huella` hasta que el sensor confirma. Ni al crear la
 *     orden, ni al sondearla, ni al reportar progreso, ni si falla.
 *  2. IDEMPOTENCIA. El lector puede grabar, reportar y perder la respuesta; al
 *     reintentar obtiene el mismo desenlace y NO una segunda asignación.
 *  3. Ningún lector toca lo que no es suyo, y ningún navegador puede hacerse pasar
 *     por un lector.
 */
class EnrolamientoContratoTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = AsistenciaDispositivoFactory::TOKEN_DE_PRUEBA;

    private AsistenciaDispositivo $lector;

    private AsistenciaEmpleado $ana;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('asistencia.enabled', true);

        $this->lector = AsistenciaDispositivo::factory()->create(['codigo' => 'lector-entrada', 'nombre' => 'Entrada principal']);
        $this->ana = AsistenciaEmpleado::factory()->create(['nombres' => 'Ana', 'apellidos' => 'Pérez']);

        // Punto de partida realista: el lector ya reportó su sensor, vacío.
        $this->lector->sincronizarIndice(162, []);
        $this->lector->refresh();
    }

    /** Cabeceras del lector, tal como las manda el firmware. */
    private function comoElLector(?AsistenciaDispositivo $lector = null, ?string $token = null): static
    {
        return $this->withHeaders([
            'X-Dispositivo' => ($lector ?? $this->lector)->codigo,
            'X-Dispositivo-Token' => $token ?? self::TOKEN,
        ]);
    }

    private function crearOrden(?AsistenciaEmpleado $empleado = null, ?int $ranuraManual = null): AsistenciaOrdenEnrolamiento
    {
        return app(CrearOrdenEnrolamiento::class)($empleado ?? $this->ana, $this->lector->refresh(), $ranuraManual);
    }

    /** El sondeo completo: devuelve [orden, token] como lo recibe el firmware. */
    private function sondear(?AsistenciaDispositivo $lector = null): array
    {
        $r = $this->comoElLector($lector)->getJson('/api/asistencia/enrolamiento/pendiente');
        $r->assertOk();

        return $r->json();
    }

    // ══════════════════════════ FLUJO FELIZ ══════════════════════════

    public function test_el_flujo_completo_termina_en_una_asignacion(): void
    {
        $orden = $this->crearOrden();

        // NADA de huella todavía: la orden solo aparta la ranura.
        $this->assertSame(0, AsistenciaHuella::count());
        $this->assertSame(EstadoOrdenEnrolamiento::Pendiente, $orden->estado);

        // 1. El lector sondea y se lleva la orden con su token.
        $sondeo = $this->sondear();
        $this->assertTrue($sondeo['hay_orden']);
        $this->assertSame(0, $sondeo['orden']['ranura']);
        $this->assertSame(162, $sondeo['orden']['capacidad']);
        $this->assertSame('Ana Pérez', $sondeo['orden']['empleado']['nombre_corto']);
        $token = $sondeo['orden']['token'];
        $this->assertSame(EstadoOrdenEnrolamiento::Tomada, $orden->refresh()->estado);
        $this->assertSame(0, AsistenciaHuella::count());

        // 2. Reporta progreso mientras el AS608 captura.
        foreach (['esperando_dedo', 'primera_captura', 'retire_dedo', 'segunda_captura', 'guardando'] as $etapa) {
            $this->comoElLector()
                ->postJson("/api/asistencia/enrolamiento/{$orden->id}/progreso", ['token' => $token, 'etapa' => $etapa])
                ->assertOk()
                ->assertJsonPath('estado', 'en_curso');
        }
        $this->assertSame(0, AsistenciaHuella::count(), 'El progreso no puede crear la asignación.');

        // 3. Confirma que grabó. AHORA sí nace la huella.
        $this->comoElLector()
            ->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", [
                'token' => $token, 'exito' => true, 'fingerprint_id' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('estado', 'completada');

        $huella = AsistenciaHuella::sole();
        $this->assertSame($this->ana->id, $huella->asistencia_empleado_id);
        $this->assertSame($this->lector->id, $huella->asistencia_dispositivo_id);
        $this->assertSame(0, $huella->fingerprint_id);
        $this->assertTrue($huella->activo);

        $orden->refresh();
        $this->assertSame(EstadoOrdenEnrolamiento::Completada, $orden->estado);
        $this->assertSame($huella->id, $orden->asistencia_huella_id);
        $this->assertNotNull($orden->finalizada_at);
    }

    /** Sin orden, el sondeo lo dice y no cuesta nada. */
    public function test_sin_orden_el_sondeo_responde_que_no_hay_nada(): void
    {
        $this->assertSame(
            ['ok' => true, 'hay_orden' => false, 'sincronizar_indice' => false],
            $this->sondear(),
        );
    }

    // ══════════════════ SELECCIÓN Y RESERVA DE RANURA ══════════════════

    /** La menor libre, excluyendo asignaciones, reservas e índice del sensor. */
    public function test_la_ranura_se_elige_excluyendo_las_tres_fuentes(): void
    {
        // Asignada.
        app(AsignarHuella::class)($this->ana, $this->lector, 0);
        // Ocupada físicamente, sin que la base lo supiera.
        $this->lector->sincronizarIndice(162, [1, 2]);
        // Reservada por otra orden viva (de otro empleado, en otro lector no vale).
        $otroLector = AsistenciaDispositivo::factory()->create(['codigo' => 'bodega']);
        $otroLector->sincronizarIndice(162, []);

        $beto = AsistenciaEmpleado::factory()->create(['nombres' => 'Beto', 'apellidos' => 'Ramos']);
        $orden = app(CrearOrdenEnrolamiento::class)($beto, $this->lector->refresh());

        $this->assertSame(3, $orden->ranura_reservada, 'La 0 está asignada y la 1 y la 2 grabadas en el sensor.');
    }

    /** Sin sincronizar NO se reserva a ciegas: se dice que no. */
    public function test_sin_indice_sincronizado_no_se_puede_iniciar(): void
    {
        $virgen = AsistenciaDispositivo::factory()->create(['codigo' => 'nuevo', 'nombre' => 'Recién instalado']);

        $this->expectException(EnrolamientoImposibleException::class);
        $this->expectExceptionMessageMatches('/todavía no ha sincronizado sus ranuras/');

        app(CrearOrdenEnrolamiento::class)($this->ana, $virgen);
    }

    /** Y el sondeo se lo pide al lector, para que se arregle solo. */
    public function test_el_sondeo_le_pide_al_lector_que_sincronice(): void
    {
        $virgen = AsistenciaDispositivo::factory()->create(['codigo' => 'nuevo']);

        $this->assertTrue($this->sondear($virgen)['sincronizar_indice']);
    }

    public function test_el_lector_reporta_su_capacidad_e_indice_reales(): void
    {
        $this->comoElLector()
            ->postJson('/api/asistencia/enrolamiento/indice-sensor', ['capacidad' => 300, 'ocupadas' => [4, 9, 9, 400]])
            ->assertOk()
            ->assertJsonPath('capacidad', 300)
            ->assertJsonPath('ocupadas', 2);

        $this->lector->refresh();
        $this->assertSame(300, $this->lector->capacidad_sensor);
        // La 400 se descarta: no cabe en un sensor de 300. La 9 no se repite.
        $this->assertSame([4, 9], $this->lector->ranurasOcupadasEnSensor());
        $this->assertNotNull($this->lector->indice_sincronizado_at);
    }

    /** La capacidad viene del hardware, no de una constante del sistema. */
    public function test_la_capacidad_la_manda_el_sensor_y_reemplaza_a_la_anterior(): void
    {
        $this->comoElLector()->postJson('/api/asistencia/enrolamiento/indice-sensor', ['capacidad' => 127, 'ocupadas' => []]);

        $this->assertSame(127, $this->lector->refresh()->capacidad_sensor);
    }

    public function test_el_sensor_lleno_se_dice_claramente(): void
    {
        $this->lector->sincronizarIndice(2, [0, 1]);

        $this->expectException(EnrolamientoImposibleException::class);
        $this->expectExceptionMessageMatches('/no tiene ranuras libres/');

        app(CrearOrdenEnrolamiento::class)($this->ana, $this->lector->refresh());
    }

    // ══════════════════════ CONCURRENCIA ══════════════════════

    /** Un lector no puede tener dos órdenes vivas: no puede enrolar dos a la vez. */
    public function test_un_lector_solo_admite_una_orden_viva(): void
    {
        $this->crearOrden();
        $beto = AsistenciaEmpleado::factory()->create(['nombres' => 'Beto', 'apellidos' => 'Ramos']);

        $this->expectException(EnrolamientoImposibleException::class);
        $this->expectExceptionMessageMatches('/ya está registrando la huella de Ana Pérez/');

        app(CrearOrdenEnrolamiento::class)($beto, $this->lector->refresh());
    }

    /** La reserva la impone la BASE, no una comprobación de PHP. */
    public function test_la_base_rechaza_dos_reservas_vivas_de_la_misma_ranura(): void
    {
        $this->crearOrden();

        $this->expectException(UniqueConstraintViolationException::class);

        // Sin pasar por el servicio: es la carrera que el servicio no puede evitar
        // por sí solo entre elegir y escribir.
        AsistenciaOrdenEnrolamiento::create([
            'asistencia_dispositivo_id' => $this->lector->id,
            'asistencia_empleado_id' => AsistenciaEmpleado::factory()->create()->id,
            'estado' => EstadoOrdenEnrolamiento::Pendiente,
            'ranura_reservada' => 0,
            'expira_at' => Carbon::now()->addMinutes(3),
        ]);
    }

    /** Cada lector tiene su propio buzón: dos pueden enrolar a la vez. */
    public function test_dos_lectores_pueden_tener_su_propia_orden(): void
    {
        $bodega = AsistenciaDispositivo::factory()->create(['codigo' => 'bodega', 'nombre' => 'Bodega']);
        $bodega->sincronizarIndice(162, []);
        $beto = AsistenciaEmpleado::factory()->create(['nombres' => 'Beto', 'apellidos' => 'Ramos']);

        $this->crearOrden();
        $enBodega = app(CrearOrdenEnrolamiento::class)($beto, $bodega->refresh());

        $this->assertSame(2, AsistenciaOrdenEnrolamiento::query()->vivas()->count());
        $this->assertSame(0, $enBodega->ranura_reservada, 'Los sensores numeran por separado.');
    }

    /** Y ninguno ve la orden del otro. */
    public function test_un_lector_no_recibe_la_orden_de_otro(): void
    {
        $bodega = AsistenciaDispositivo::factory()->conToken('token-bodega')->create(['codigo' => 'bodega']);
        $bodega->sincronizarIndice(162, []);
        $this->crearOrden();   // es del lector de entrada

        $sondeo = $this->comoElLector($bodega, 'token-bodega')
            ->getJson('/api/asistencia/enrolamiento/pendiente')->assertOk()->json();

        $this->assertFalse($sondeo['hay_orden']);
    }

    /** Ni puede resolverla, aunque conozca su id. */
    public function test_un_lector_no_puede_resolver_la_orden_de_otro(): void
    {
        $bodega = AsistenciaDispositivo::factory()->conToken('token-bodega')->create(['codigo' => 'bodega']);
        $orden = $this->crearOrden();
        $token = $this->sondear()['orden']['token'];

        $this->comoElLector($bodega, 'token-bodega')
            ->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", [
                'token' => $token, 'exito' => true, 'fingerprint_id' => 0,
            ])
            ->assertNotFound()
            ->assertJsonPath('motivo', 'orden_no_valida');

        $this->assertSame(0, AsistenciaHuella::count());
        $this->assertSame(EstadoOrdenEnrolamiento::Tomada, $orden->refresh()->estado);
    }

    // ══════════════════════ EXPIRACIÓN ══════════════════════

    /** Una orden vencida NO se entrega: no puede ejecutarse horas después. */
    public function test_una_orden_vencida_no_se_entrega_ni_revive(): void
    {
        $orden = $this->crearOrden();
        $orden->forceFill(['expira_at' => Carbon::now()->subMinute()])->save();

        $this->assertFalse($this->sondear()['hay_orden']);

        $orden->refresh();
        $this->assertSame(EstadoOrdenEnrolamiento::Expirada, $orden->estado);
        $this->assertSame(MotivoFalloEnrolamiento::Expirada, $orden->motivo_fallo);
        $this->assertSame(0, AsistenciaHuella::count());
    }

    /** Y su ranura queda libre para la siguiente. */
    public function test_al_expirar_se_libera_la_ranura_y_el_buzon(): void
    {
        $orden = $this->crearOrden();
        $orden->forceFill(['expira_at' => Carbon::now()->subMinute()])->save();

        $beto = AsistenciaEmpleado::factory()->create(['nombres' => 'Beto', 'apellidos' => 'Ramos']);
        $nueva = app(CrearOrdenEnrolamiento::class)($beto, $this->lector->refresh());

        $this->assertSame(0, $nueva->ranura_reservada, 'La reserva vencida ya no estorba.');
        $this->assertSame(EstadoOrdenEnrolamiento::Expirada, $orden->refresh()->estado);
    }

    /** Un resultado que llega tarde no crea nada. */
    public function test_un_resultado_sobre_una_orden_vencida_no_asigna(): void
    {
        $orden = $this->crearOrden();
        $token = $this->sondear()['orden']['token'];
        $orden->forceFill(['expira_at' => Carbon::now()->subMinute()])->save();

        $this->comoElLector()
            ->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", [
                'token' => $token, 'exito' => true, 'fingerprint_id' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('estado', 'expirada');

        $this->assertSame(0, AsistenciaHuella::count());
    }

    /** El progreso estira la ventana: capturar con alguien lento no debe expirar. */
    public function test_el_progreso_refresca_el_vencimiento(): void
    {
        $orden = $this->crearOrden();
        $token = $this->sondear()['orden']['token'];
        $orden->forceFill(['expira_at' => Carbon::now()->addSeconds(5)])->save();

        $this->comoElLector()
            ->postJson("/api/asistencia/enrolamiento/{$orden->id}/progreso", ['token' => $token, 'etapa' => 'primera_captura'])
            ->assertOk();

        $this->assertGreaterThan(60, $orden->refresh()->segundosParaExpirar());
    }

    // ══════════════════════ IDEMPOTENCIA Y REINTENTOS ══════════════════════

    /** Reportar el mismo éxito dos veces NO crea dos huellas. */
    public function test_un_resultado_repetido_devuelve_lo_mismo_y_no_duplica(): void
    {
        $orden = $this->crearOrden();
        $token = $this->sondear()['orden']['token'];
        $cuerpo = ['token' => $token, 'exito' => true, 'fingerprint_id' => 0];

        $primera = $this->comoElLector()->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", $cuerpo)->assertOk();
        $segunda = $this->comoElLector()->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", $cuerpo)->assertOk();

        $this->assertSame($primera->json(), $segunda->json(), 'El reintento tiene que dar el mismo desenlace.');
        $this->assertSame(1, AsistenciaHuella::count(), 'Y NO una segunda asignación.');
    }

    /** Volver a sondear una orden ya tomada la reentrega con un token nuevo. */
    public function test_resondear_reemite_el_token_e_invalida_el_anterior(): void
    {
        $orden = $this->crearOrden();
        $primero = $this->sondear()['orden']['token'];
        $segundo = $this->sondear()['orden']['token'];

        $this->assertNotSame($primero, $segundo);

        // El viejo ya no sirve: cierra la ventana en que dos copias responderían.
        $this->comoElLector()
            ->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", [
                'token' => $primero, 'exito' => true, 'fingerprint_id' => 0,
            ])
            ->assertNotFound();

        $this->assertSame(0, AsistenciaHuella::count());
    }

    public function test_un_token_de_otra_orden_no_sirve(): void
    {
        $orden = $this->crearOrden();
        $this->sondear();

        $this->comoElLector()
            ->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", [
                'token' => 'token-inventado', 'exito' => true, 'fingerprint_id' => 0,
            ])
            ->assertNotFound();

        $this->assertSame(0, AsistenciaHuella::count());
    }

    // ══════════════════════ FALLOS DEL SENSOR ══════════════════════

    #[DataProvider('fallosDelLector')]
    public function test_los_fallos_del_lector_no_crean_nada(string $motivo): void
    {
        $orden = $this->crearOrden();
        $token = $this->sondear()['orden']['token'];

        $this->comoElLector()
            ->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", [
                'token' => $token, 'exito' => false, 'motivo' => $motivo,
            ])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('motivo', $motivo);

        $this->assertSame(0, AsistenciaHuella::count());
        $this->assertSame(EstadoOrdenEnrolamiento::Fallida, $orden->refresh()->estado);
    }

    /** @return array<string, array{0: string}> */
    public static function fallosDelLector(): array
    {
        return [
            'sin sensor' => ['sin_sensor'],
            'nadie puso el dedo' => ['timeout_dedo'],
            'lectura defectuosa' => ['captura_defectuosa'],
            'dedos distintos' => ['dedos_no_coinciden'],
            'no compuso el modelo' => ['fallo_modelo'],
            'no pudo guardar' => ['fallo_guardado'],
            'cancelada en el lector' => ['cancelada_en_dispositivo'],
        ];
    }

    /** El lector no puede alegar motivos que solo el servidor puede observar. */
    public function test_el_lector_no_puede_alegar_un_motivo_del_servidor(): void
    {
        $orden = $this->crearOrden();
        $token = $this->sondear()['orden']['token'];

        $this->comoElLector()
            ->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", [
                'token' => $token, 'exito' => false, 'motivo' => 'ranura_ya_asignada',
            ])
            ->assertStatus(422)
            ->assertJsonPath('motivo', 'payload_invalido');

        $this->assertSame(EstadoOrdenEnrolamiento::Tomada, $orden->refresh()->estado);
    }

    // ══════════════ RANURA CON PLANTILLA HEREDADA ══════════════

    /**
     * EL CASO QUE PIDIÓ LA DECISIÓN 5. El sensor tiene una plantilla que la base no
     * conocía: NO se sobrescribe. Se guarda el índice real y se reserva otra ranura
     * en una orden NUEVA.
     */
    public function test_una_ranura_heredada_no_se_sobrescribe_y_se_reserva_otra(): void
    {
        $orden = $this->crearOrden();
        $token = $this->sondear()['orden']['token'];
        $this->assertSame(0, $orden->ranura_reservada);

        $respuesta = $this->comoElLector()
            ->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", [
                'token' => $token,
                'exito' => false,
                'motivo' => 'ranura_ocupada_en_sensor',
                // El lector reporta lo que el AS608 tiene de verdad.
                'indice' => ['capacidad' => 162, 'ocupadas' => [0, 1]],
            ])
            ->assertOk()
            ->assertJsonPath('motivo', 'ranura_ocupada_en_sensor');

        // Nada grabado, nada asignado.
        $this->assertSame(0, AsistenciaHuella::count());
        $this->assertSame(EstadoOrdenEnrolamiento::Fallida, $orden->refresh()->estado);

        // El índice quedó guardado…
        $this->assertSame([0, 1], $this->lector->refresh()->ranurasOcupadasEnSensor());

        // …y hay una orden NUEVA con otra ranura, ya excluyendo las heredadas.
        $nuevaId = $respuesta->json('reintento.orden_id');
        $this->assertNotNull($nuevaId);

        $nueva = AsistenciaOrdenEnrolamiento::findOrFail($nuevaId);
        $this->assertSame(2, $nueva->ranura_reservada);
        $this->assertSame(2, $nueva->intento);
        $this->assertSame($orden->id, $nueva->orden_origen_id);
        $this->assertSame($this->ana->id, $nueva->asistencia_empleado_id);
    }

    /** La cadena de reintentos está acotada: un sensor lleno no la hace infinita. */
    public function test_los_reintentos_por_ranura_heredada_estan_acotados(): void
    {
        $orden = $this->crearOrden();

        for ($vuelta = 1; $vuelta <= AsistenciaOrdenEnrolamiento::MAX_INTENTOS + 1; $vuelta++) {
            $sondeo = $this->sondear();

            if (! $sondeo['hay_orden']) {
                break;
            }

            $actual = $sondeo['orden']['id'];
            $respuesta = $this->comoElLector()->postJson("/api/asistencia/enrolamiento/{$actual}/resultado", [
                'token' => $sondeo['orden']['token'],
                'exito' => false,
                'motivo' => 'ranura_ocupada_en_sensor',
            ])->assertOk();

            if ($respuesta->json('reintento.orden_id') === null) {
                break;
            }
        }

        $this->assertLessThanOrEqual(
            AsistenciaOrdenEnrolamiento::MAX_INTENTOS,
            AsistenciaOrdenEnrolamiento::query()->max('intento'),
        );
        $this->assertSame(0, AsistenciaOrdenEnrolamiento::query()->vivas()->count(), 'La cadena se detiene.');
    }

    /** El lector no puede grabar donde le parezca: se le dijo dónde. */
    public function test_una_ranura_distinta_de_la_reservada_no_se_asocia(): void
    {
        $orden = $this->crearOrden();
        $token = $this->sondear()['orden']['token'];

        $this->comoElLector()
            ->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", [
                'token' => $token, 'exito' => true, 'fingerprint_id' => 77,
            ])
            ->assertOk()
            ->assertJsonPath('motivo', 'ranura_no_coincide');

        $this->assertSame(0, AsistenciaHuella::count());
    }

    // ══════════════ INTEGRACIÓN CON LA FASE 1 ══════════════

    /**
     * Reutilizar una ranura liberada crea una asignación NUEVA. La histórica no se
     * toca: es la garantía de la Fase 1 y el enrolamiento no la puede romper porque
     * pasa por el mismo servicio.
     */
    public function test_enrolar_en_una_ranura_liberada_crea_una_asignacion_nueva(): void
    {
        $vieja = app(AsignarHuella::class)($this->ana, $this->lector, 0);
        app(LiberarHuella::class)($vieja);
        // El sensor también se limpió: la ranura vuelve a estar libre físicamente.
        $this->lector->sincronizarIndice(162, []);

        $beto = AsistenciaEmpleado::factory()->create(['nombres' => 'Beto', 'apellidos' => 'Ramos']);
        $orden = app(CrearOrdenEnrolamiento::class)($beto, $this->lector->refresh());
        $this->assertSame(0, $orden->ranura_reservada);

        $token = $this->sondear()['orden']['token'];
        $this->comoElLector()->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", [
            'token' => $token, 'exito' => true, 'fingerprint_id' => 0,
        ])->assertOk();

        $this->assertSame(2, AsistenciaHuella::count(), 'Dos filas: la histórica de Ana y la nueva de Beto.');

        $vieja->refresh();
        $this->assertSame($this->ana->id, $vieja->asistencia_empleado_id, 'La asignación de Ana no cambió de dueño.');
        $this->assertFalse($vieja->activo);

        $nueva = AsistenciaHuella::query()->where('activo', true)->sole();
        $this->assertSame($beto->id, $nueva->asistencia_empleado_id);
        $this->assertNotSame($vieja->id, $nueva->id);
    }

    /** Si alguien asigna la ranura entre la reserva y la confirmación, no se pisa. */
    public function test_si_la_ranura_se_asigna_mientras_tanto_el_enrolamiento_falla(): void
    {
        $orden = $this->crearOrden();
        $token = $this->sondear()['orden']['token'];

        $beto = AsistenciaEmpleado::factory()->create(['nombres' => 'Beto', 'apellidos' => 'Ramos']);
        app(AsignarHuella::class)($beto, $this->lector, 0);

        $this->comoElLector()
            ->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", [
                'token' => $token, 'exito' => true, 'fingerprint_id' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('motivo', 'ranura_ya_asignada');

        $this->assertSame(1, AsistenciaHuella::count(), 'La de Beto, y solo esa.');
        $this->assertSame($beto->id, AsistenciaHuella::sole()->asistencia_empleado_id);
    }

    // ══════════════ EMPLEADO Y LECTOR NO ELEGIBLES ══════════════

    public function test_no_se_enrola_a_un_empleado_inactivo(): void
    {
        $this->ana->update(['activo' => false]);

        $this->expectException(EnrolamientoImposibleException::class);
        $this->expectExceptionMessageMatches('/está desactivada/');

        app(CrearOrdenEnrolamiento::class)($this->ana->refresh(), $this->lector);
    }

    public function test_no_se_enrola_con_un_lector_desactivado(): void
    {
        $this->lector->update(['activo' => false]);

        $this->expectException(EnrolamientoImposibleException::class);
        $this->expectExceptionMessageMatches('/está desactivado/');

        app(CrearOrdenEnrolamiento::class)($this->ana, $this->lector->refresh());
    }

    /** Si se desactiva DESPUÉS de crear la orden, el sondeo no la entrega. */
    public function test_si_el_empleado_se_desactiva_la_orden_falla_al_sondear(): void
    {
        $orden = $this->crearOrden();
        $this->ana->update(['activo' => false]);

        $this->assertFalse($this->sondear()['hay_orden']);

        $orden->refresh();
        $this->assertSame(EstadoOrdenEnrolamiento::Fallida, $orden->estado);
        $this->assertSame(MotivoFalloEnrolamiento::EmpleadoNoElegible, $orden->motivo_fallo);
    }

    // ══════════════════════ SEGURIDAD ══════════════════════

    /** Sin token de lector no se puede tocar nada del enrolamiento. */
    public function test_sin_credencial_de_lector_ningun_endpoint_responde(): void
    {
        $orden = $this->crearOrden();

        $this->getJson('/api/asistencia/enrolamiento/pendiente')->assertUnauthorized();
        $this->postJson("/api/asistencia/enrolamiento/{$orden->id}/progreso", ['token' => 'x', 'etapa' => 'guardando'])->assertUnauthorized();
        $this->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", ['token' => 'x', 'exito' => true, 'fingerprint_id' => 0])->assertUnauthorized();
        $this->postJson('/api/asistencia/enrolamiento/indice-sensor', ['capacidad' => 10, 'ocupadas' => []])->assertUnauthorized();

        $this->assertSame(0, AsistenciaHuella::count());
    }

    /** Ni el token del lector ni el de la orden salen en ninguna respuesta indebida. */
    public function test_ninguna_respuesta_filtra_el_token_del_lector(): void
    {
        $orden = $this->crearOrden();
        $respuesta = $this->comoElLector()->getJson('/api/asistencia/enrolamiento/pendiente')->assertOk();

        $respuesta->assertDontSee(self::TOKEN);
        $respuesta->assertDontSee($this->lector->token_hash);
        // El token de la ORDEN sí viaja: es su única entrega. Pero su hash, no.
        $respuesta->assertDontSee($orden->refresh()->token_hash);
    }

    public function test_con_el_modulo_apagado_el_enrolamiento_no_existe(): void
    {
        $orden = $this->crearOrden();
        config()->set('asistencia.enabled', false);

        $this->comoElLector()->getJson('/api/asistencia/enrolamiento/pendiente')->assertNotFound();
        $this->comoElLector()->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", [
            'token' => 'x', 'exito' => true, 'fingerprint_id' => 0,
        ])->assertNotFound();
    }
}
