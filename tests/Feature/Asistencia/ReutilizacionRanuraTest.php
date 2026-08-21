<?php

namespace Tests\Feature\Asistencia;

use App\Enums\Asistencia\TipoMarcacion;
use App\Exceptions\Asistencia\RanuraOcupadaException;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaMarcacion;
use App\Services\Asistencia\AsignarHuella;
use App\Services\Asistencia\LiberarHuella;
use Database\Factories\Asistencia\AsistenciaDispositivoFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * REUTILIZAR UNA RANURA DEL SENSOR SIN REESCRIBIR EL PASADO.
 *
 * Una ranura del AS608 es un recurso finito (~162) y la gente entra y sale de la
 * empresa. Cuando alguien se va, su plantilla se borra del sensor y ese número
 * tiene que poder ser de otra persona. Lo que NO puede pasar es que, al
 * reutilizarlo, las marcaciones de quien se fue cambien de dueño.
 *
 * La garantía tiene dos mitades y las dos se prueban acá:
 *
 *   1. A LA VEZ, NO. Como mucho una asignación VIGENTE por (lector, ranura), y lo
 *      impone la BASE DE DATOS —no una comprobación de PHP que dos peticiones
 *      simultáneas se saltan—.
 *   2. ALGUNA VEZ, SÍ. Cuantas asignaciones históricas haga falta para esa misma
 *      ranura, cada una con su empleado y sus marcaciones intactas.
 */
class ReutilizacionRanuraTest extends TestCase
{
    use RefreshDatabase;

    private const RANURA = 7;

    private AsistenciaDispositivo $lector;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('asistencia.enabled', true);

        $this->lector = AsistenciaDispositivo::factory()->create(['codigo' => 'lector-entrada']);
    }

    private function asignar(): AsignarHuella
    {
        return app(AsignarHuella::class);
    }

    private function liberar(): LiberarHuella
    {
        return app(LiberarHuella::class);
    }

    private function empleado(string $nombres, string $apellidos): AsistenciaEmpleado
    {
        return AsistenciaEmpleado::factory()->create(['nombres' => $nombres, 'apellidos' => $apellidos]);
    }

    // ─────────────────────────── 1. Una activa bloquea ───────────────────────────

    public function test_una_asignacion_vigente_bloquea_otra_en_la_misma_ranura(): void
    {
        $ana = $this->empleado('Ana', 'Pérez');
        $beto = $this->empleado('Beto', 'Ramos');

        $this->asignar()($ana, $this->lector, self::RANURA);

        $this->expectException(RanuraOcupadaException::class);

        $this->asignar()($beto, $this->lector, self::RANURA);
    }

    /** El error dice de QUIÉN es la ranura: es la información que hace falta para resolverlo. */
    public function test_el_error_nombra_a_quien_ocupa_la_ranura(): void
    {
        $ana = $this->empleado('Ana', 'Pérez');
        $this->asignar()($ana, $this->lector, self::RANURA);

        try {
            $this->asignar()($this->empleado('Beto', 'Ramos'), $this->lector, self::RANURA);
            $this->fail('Debería haber rechazado la ranura ocupada.');
        } catch (RanuraOcupadaException $e) {
            $this->assertStringContainsString('Ana Pérez', $e->getMessage());
            $this->assertStringContainsString('lector-entrada', $e->getMessage());
            $this->assertTrue($e->ocupante->is(AsistenciaHuella::query()->activas()->sole()));
        }
    }

    /**
     * LA GARANTÍA DE VERDAD. El servicio comprueba antes de insertar, pero entre
     * la comprobación y el INSERT hay una ventana: dos peticiones simultáneas
     * pueden leer las dos «está libre». Este test se salta el servicio a propósito
     * —escribe directo, como haría la segunda petición de esa carrera— para
     * demostrar que quien rechaza es la BASE y no el PHP.
     *
     * Si esta prueba se pone verde borrando el índice único, la protección era
     * imaginaria.
     */
    public function test_la_base_de_datos_rechaza_dos_activas_aunque_php_no_mire(): void
    {
        $ana = $this->empleado('Ana', 'Pérez');
        $beto = $this->empleado('Beto', 'Ramos');

        $this->asignar()($ana, $this->lector, self::RANURA);

        $this->expectException(UniqueConstraintViolationException::class);

        // Sin pasar por AsignarHuella: es la interleaving que el servicio no puede
        // evitar por sí solo.
        AsistenciaHuella::create([
            'asistencia_empleado_id' => $beto->id,
            'asistencia_dispositivo_id' => $this->lector->id,
            'fingerprint_id' => self::RANURA,
            'activo' => true,
        ]);
    }

    public function test_la_misma_ranura_en_otro_lector_no_estorba(): void
    {
        $otro = AsistenciaDispositivo::factory()->create(['codigo' => 'lector-bodega']);

        $this->asignar()($this->empleado('Ana', 'Pérez'), $this->lector, self::RANURA);
        $this->asignar()($this->empleado('Beto', 'Ramos'), $otro, self::RANURA);

        // La ranura 1 de la entrada y la ranura 1 de la bodega son dos plantillas
        // distintas dentro de dos sensores distintos.
        $this->assertSame(2, AsistenciaHuella::query()->activas()->count());
    }

    // ─────────────────────── 2. Liberada → se puede reutilizar ───────────────────────

    public function test_tras_liberarla_la_ranura_admite_una_asignacion_nueva(): void
    {
        $ana = $this->empleado('Ana', 'Pérez');
        $beto = $this->empleado('Beto', 'Ramos');

        $deAna = $this->asignar()($ana, $this->lector, self::RANURA);
        $this->liberar()($deAna);

        $deBeto = $this->asignar()($beto, $this->lector, self::RANURA);

        $this->assertTrue($deBeto->activo);
        $this->assertNotSame($deAna->id, $deBeto->id, 'Tiene que ser una fila NUEVA, no la de Ana reciclada.');
        $this->assertSame($beto->id, $deBeto->asistencia_empleado_id);
    }

    public function test_liberar_marca_cuando_se_libero_y_no_borra_la_fila(): void
    {
        $huella = $this->asignar()($this->empleado('Ana', 'Pérez'), $this->lector, self::RANURA);

        $this->assertNull($huella->liberada_at);
        $this->assertTrue($this->liberar()($huella));

        $huella->refresh();
        $this->assertFalse($huella->activo);
        $this->assertNotNull($huella->liberada_at);
        $this->assertDatabaseHas('asistencia_huellas', ['id' => $huella->id]);
    }

    /** Liberar dos veces no mueve la fecha del cierre: el período lo cierra la primera. */
    public function test_liberar_es_idempotente_y_no_pisa_la_fecha_original(): void
    {
        $huella = $this->asignar()($this->empleado('Ana', 'Pérez'), $this->lector, self::RANURA);

        $this->liberar()($huella, Carbon::parse('2026-03-05 10:00:00'));
        $primera = $huella->fresh()->liberada_at;

        $this->assertFalse($this->liberar()($huella->fresh(), Carbon::parse('2026-09-09 18:00:00')));
        $this->assertTrue($primera->equalTo($huella->fresh()->liberada_at));
    }

    // ───────────────────────── 3. El historial no se toca ─────────────────────────

    public function test_la_asignacion_historica_sigue_existiendo_con_su_empleado(): void
    {
        $ana = $this->empleado('Ana', 'Pérez');
        $beto = $this->empleado('Beto', 'Ramos');

        $deAna = $this->asignar()($ana, $this->lector, self::RANURA);
        $this->liberar()($deAna);
        $this->asignar()($beto, $this->lector, self::RANURA);

        $deAna->refresh();

        $this->assertSame($ana->id, $deAna->asistencia_empleado_id, 'La asignación de Ana cambió de dueño.');
        $this->assertSame(self::RANURA, $deAna->fingerprint_id);
        $this->assertFalse($deAna->activo);
    }

    /**
     * EL CORAZÓN DEL ASUNTO. Las marcaciones de Ana se hicieron con la asignación
     * de Ana; que Beto herede la ranura no puede moverlas.
     */
    public function test_las_marcaciones_historicas_siguen_ligadas_a_su_asignacion_original(): void
    {
        $ana = $this->empleado('Ana', 'Pérez');
        $beto = $this->empleado('Beto', 'Ramos');

        $deAna = $this->asignar()($ana, $this->lector, self::RANURA);

        $marcacionDeAna = AsistenciaMarcacion::factory()->create([
            'asistencia_empleado_id' => $ana->id,
            'asistencia_dispositivo_id' => $this->lector->id,
            'asistencia_huella_id' => $deAna->id,
            'tipo' => TipoMarcacion::Entrada,
        ]);

        $this->liberar()($deAna);
        $deBeto = $this->asignar()($beto, $this->lector, self::RANURA);

        $marcacionDeBeto = AsistenciaMarcacion::factory()->create([
            'asistencia_empleado_id' => $beto->id,
            'asistencia_dispositivo_id' => $this->lector->id,
            'asistencia_huella_id' => $deBeto->id,
            'tipo' => TipoMarcacion::Entrada,
        ]);

        $marcacionDeAna->refresh();

        $this->assertSame($deAna->id, $marcacionDeAna->asistencia_huella_id);
        $this->assertSame($ana->id, $marcacionDeAna->asistencia_empleado_id);
        $this->assertSame('Ana Pérez', $marcacionDeAna->huella->empleado->nombreCompleto());

        // Y la de Beto apunta a la suya, no a la de Ana.
        $this->assertSame($deBeto->id, $marcacionDeBeto->fresh()->asistencia_huella_id);
        $this->assertSame('Beto Ramos', $marcacionDeBeto->huella->empleado->nombreCompleto());
    }

    /** La ranura puede rotar tantas veces como haga falta a lo largo del tiempo. */
    public function test_la_ranura_se_puede_reutilizar_muchas_veces(): void
    {
        $nombres = [['Ana', 'Pérez'], ['Beto', 'Ramos'], ['Carla', 'Solís'], ['Dani', 'Ortiz']];
        $asignaciones = [];

        foreach ($nombres as $i => [$n, $a]) {
            $huella = $this->asignar()($this->empleado($n, $a), $this->lector, self::RANURA);
            $asignaciones[] = $huella;

            // Todas menos la última se liberan.
            if ($i < count($nombres) - 1) {
                $this->liberar()($huella);
            }
        }

        $enLaRanura = AsistenciaHuella::query()->deRanura($this->lector->id, self::RANURA)->get();

        $this->assertCount(4, $enLaRanura, 'Cada período tiene que ser su propia fila.');
        $this->assertSame(1, $enLaRanura->where('activo', true)->count(), 'Solo una puede estar vigente.');
        $this->assertSame(3, $enLaRanura->where('activo', false)->count());

        // Las cuatro son de personas distintas y ninguna se pisó.
        $this->assertSame(4, $enLaRanura->pluck('asistencia_empleado_id')->unique()->count());
        $this->assertTrue($enLaRanura->last()->is($asignaciones[3]));
    }

    /** El lector solo resuelve la asignación VIGENTE: la histórica ya no reconoce a nadie. */
    public function test_al_marcar_la_ranura_resuelve_a_su_titular_actual(): void
    {
        $ana = $this->empleado('Ana', 'Pérez');
        $beto = $this->empleado('Beto', 'Ramos');

        $this->liberar()($this->asignar()($ana, $this->lector, self::RANURA));
        $this->asignar()($beto, $this->lector, self::RANURA);

        $respuesta = $this->withHeaders([
            'X-Dispositivo' => $this->lector->codigo,
            'X-Dispositivo-Token' => AsistenciaDispositivoFactory::TOKEN_DE_PRUEBA,
        ])->postJson('/api/asistencia/marcar', ['fingerprint_id' => self::RANURA]);

        $respuesta->assertOk()
            ->assertJsonPath('estado', 'registrada')
            ->assertJsonPath('empleado.nombre', 'Beto Ramos');

        // Y a Ana no le apareció ninguna marcación.
        $this->assertSame(0, AsistenciaMarcacion::query()->where('asistencia_empleado_id', $ana->id)->count());
        $this->assertSame(1, AsistenciaMarcacion::query()->where('asistencia_empleado_id', $beto->id)->count());
    }

    // ────────────────────────────── 4. Auditoría ──────────────────────────────

    public function test_asignar_y_liberar_quedan_auditados(): void
    {
        $huella = $this->asignar()($this->empleado('Ana', 'Pérez'), $this->lector, self::RANURA);
        $this->liberar()($huella);

        $actividad = Activity::query()
            ->where('log_name', 'asistencia')
            ->where('subject_type', AsistenciaHuella::class)
            ->where('subject_id', $huella->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $actividad, 'La asignación y la liberación tienen que dejar rastro.');
        $this->assertSame('asignó una huella a un empleado', $actividad->first()->description);
        $this->assertSame('modificó la asignación de una huella', $actividad->last()->description);
    }
}
