<?php

namespace Tests\Feature\Asistencia;

use App\Exceptions\Asistencia\EnrolamientoImposibleException;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Services\Asistencia\AsignarHuella;
use App\Services\Asistencia\SelectorRanura;
use App\Services\Asistencia\SincronizarIndiceSensor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LAS FRONTERAS DEL RANGO DE RANURAS: la primera, la última, la de más allá, el
 * sensor vacío y el sensor lleno.
 *
 * ─────────────────────── Por qué existe este archivo ───────────────────────
 *
 * Porque el resto de la suite ejercita el centro del rango y da por buenos los
 * extremos. Concretamente, faltaba **toda** cobertura de la última ranura válida:
 * había pruebas de capacidad (127, 300), de sensor lleno y de una ranura manual
 * absurda (500 sobre 300), pero ninguna tocaba `capacidad - 1`.
 *
 * ──────────────── El defecto que estas pruebas vigilan ────────────────
 *
 * {@see SelectorRanura::RANURA_MINIMA} promete ser el único número que hay que
 * cambiar si algún día se instala un sensor que numere desde 1. No lo era: el tope
 * superior estaba escrito aparte como `< $capacidad` en tres sitios, asumiendo por
 * su cuenta que la base es 0. Con `RANURA_MINIMA = 1` el rango efectivo habría
 * pasado a 1..capacidad-1 — una ranura menos de las que tiene el sensor, y la
 * última inalcanzable **en silencio**.
 *
 * Por eso ninguna prueba de acá escribe `0` ni `299` a mano: todas derivan sus
 * fronteras de la constante. Si alguien la cambia y deja un tope suelto sin
 * mover, estas pruebas se caen; si escribieran los números literales, seguirían
 * pasando mientras el hardware fallaba.
 *
 * ─────────────────────────── Lo que NO pueden probar ───────────────────────────
 *
 * Dónde empieza y termina de verdad el rango del AS608. Eso es físico: se
 * comprueba con `loadModel()`, distinguiendo `DBRANGEFAIL` (existe y está vacía)
 * de `BADLOCATION` (no existe) — que es lo que hace el firmware, y lo que este
 * lector ya confirmó al grabar y releer la ranura 0.
 */
class FronteraRanurasTest extends TestCase
{
    use RefreshDatabase;

    /** Capacidad de juguete: hace legibles los arreglos de ranuras ocupadas. */
    private const CAPACIDAD = 5;

    private AsistenciaDispositivo $lector;

    private SelectorRanura $selector;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('asistencia.enabled', true);

        $this->lector = AsistenciaDispositivo::factory()->create(['codigo' => 'lector-entrada']);
        $this->selector = app(SelectorRanura::class);
    }

    // ------------------------------------------------------------------
    // Las fronteras, derivadas de la constante
    // ------------------------------------------------------------------

    private function primera(): int
    {
        return SelectorRanura::RANURA_MINIMA;
    }

    private function ultima(int $capacidad = self::CAPACIDAD): int
    {
        return SelectorRanura::ranuraMaxima($capacidad);
    }

    private function masAllaDelTope(int $capacidad = self::CAPACIDAD): int
    {
        return $this->ultima($capacidad) + 1;
    }

    /** @return array<int, int> todas las ranuras del sensor, de la primera a la última */
    private function todasLasRanuras(int $capacidad = self::CAPACIDAD): array
    {
        return range($this->primera(), $this->ultima($capacidad));
    }

    private function conSensor(int $capacidad, array $ocupadas): void
    {
        $this->lector->sincronizarIndice($capacidad, $ocupadas);
        $this->lector->refresh();
    }

    // ------------------------------------------------------------------
    // SENSOR VACÍO -> primera ranura válida
    // ------------------------------------------------------------------

    public function test_un_sensor_vacio_entrega_la_primera_ranura_del_rango(): void
    {
        $this->conSensor(self::CAPACIDAD, []);

        $this->assertSame($this->primera(), $this->selector->siguienteLibre($this->lector));
    }

    /** Vacío es «sincronizó y no tiene nada», que NO es lo mismo que no haber sincronizado. */
    public function test_sensor_vacio_no_es_lo_mismo_que_sensor_sin_sincronizar(): void
    {
        $this->assertFalse($this->lector->tieneIndiceSincronizado());

        $this->expectException(EnrolamientoImposibleException::class);
        $this->selector->siguienteLibre($this->lector);
    }

    public function test_la_primera_ranura_se_acepta_en_el_escape_manual(): void
    {
        $this->conSensor(self::CAPACIDAD, []);

        $this->assertNull($this->selector->motivoParaNoUsar($this->lector, $this->primera()));
    }

    // ------------------------------------------------------------------
    // ÚLTIMA RANURA VÁLIDA
    // ------------------------------------------------------------------

    /**
     * La que faltaba. Con todo ocupado menos la última, el selector tiene que
     * llegar hasta ella en vez de declarar el sensor lleno una ranura antes.
     */
    public function test_la_ultima_ranura_del_rango_se_puede_elegir(): void
    {
        $ocupadas = $this->todasLasRanuras();
        array_pop($ocupadas);

        $this->conSensor(self::CAPACIDAD, $ocupadas);

        $this->assertSame($this->ultima(), $this->selector->siguienteLibre($this->lector));
    }

    public function test_la_ultima_ranura_se_acepta_en_el_escape_manual(): void
    {
        $this->conSensor(self::CAPACIDAD, []);

        $this->assertNull($this->selector->motivoParaNoUsar($this->lector, $this->ultima()));
    }

    /** Y también con una capacidad real de AS608, no solo con la de juguete. */
    public function test_la_ultima_ranura_de_un_sensor_de_300_se_puede_elegir(): void
    {
        $ocupadas = $this->todasLasRanuras(300);
        array_pop($ocupadas);

        $this->conSensor(300, $ocupadas);

        $this->assertSame($this->ultima(300), $this->selector->siguienteLibre($this->lector));
        $this->assertNull($this->selector->motivoParaNoUsar($this->lector, $this->ultima(300)));
    }

    /** La plantilla se asocia igual en la última ranura que en cualquier otra. */
    public function test_la_ultima_ranura_admite_una_asignacion(): void
    {
        $this->conSensor(self::CAPACIDAD, []);
        $empleado = AsistenciaEmpleado::factory()->create();

        $huella = app(AsignarHuella::class)($empleado, $this->lector, $this->ultima());

        $this->assertSame($this->ultima(), $huella->fingerprint_id);
    }

    // ------------------------------------------------------------------
    // UNA RANURA MÁS ALLÁ DEL TOPE
    // ------------------------------------------------------------------

    public function test_la_ranura_siguiente_al_tope_se_rechaza_en_el_escape_manual(): void
    {
        $this->conSensor(self::CAPACIDAD, []);

        $motivo = $this->selector->motivoParaNoUsar($this->lector, $this->masAllaDelTope());

        $this->assertNotNull($motivo);
        $this->assertStringContainsString((string) self::CAPACIDAD, $motivo);
    }

    public function test_una_ranura_por_debajo_del_minimo_se_rechaza(): void
    {
        $this->conSensor(self::CAPACIDAD, []);

        $this->assertNotNull($this->selector->motivoParaNoUsar($this->lector, $this->primera() - 1));
    }

    /**
     * El índice que reporta el lector se filtra por el MISMO rango: la última
     * válida entra, la de más allá se descarta.
     */
    public function test_la_sincronizacion_conserva_la_ultima_y_descarta_la_de_mas_alla(): void
    {
        app(SincronizarIndiceSensor::class)(
            $this->lector,
            self::CAPACIDAD,
            [$this->primera(), $this->ultima(), $this->masAllaDelTope()],
        );

        $ocupadas = $this->lector->refresh()->ranurasOcupadasEnSensor();

        $this->assertContains($this->primera(), $ocupadas);
        $this->assertContains($this->ultima(), $ocupadas);
        $this->assertNotContains($this->masAllaDelTope(), $ocupadas);
    }

    // ------------------------------------------------------------------
    // SENSOR LLENO
    // ------------------------------------------------------------------

    /**
     * Lleno = ocupadas TODAS, incluida la última. Antes solo se probaba con un
     * rango que no llegaba al tope, así que un tope corrido de uno se habría visto
     * igual de «lleno» y nadie lo habría notado.
     */
    public function test_el_sensor_lleno_hasta_la_ultima_ranura_no_entrega_ninguna(): void
    {
        $this->conSensor(self::CAPACIDAD, $this->todasLasRanuras());

        $this->expectException(EnrolamientoImposibleException::class);
        $this->selector->siguienteLibre($this->lector);
    }

    /** Con la última libre NO está lleno. Es el otro lado de la misma frontera. */
    public function test_no_esta_lleno_mientras_quede_la_ultima(): void
    {
        $ocupadas = $this->todasLasRanuras();
        array_pop($ocupadas);

        $this->conSensor(self::CAPACIDAD, $ocupadas);

        $this->assertSame($this->ultima(), $this->selector->siguienteLibre($this->lector));
    }

    // ------------------------------------------------------------------
    // EL INVARIANTE: el rango mide exactamente `capacidad`
    // ------------------------------------------------------------------

    /**
     * La prueba que habría cazado el defecto.
     *
     * No comprueba un número concreto: comprueba que el rango que el sistema usa
     * de verdad **empieza en `RANURA_MINIMA` y tiene exactamente `capacidad`
     * ranuras**. Con el tope escrito aparte como `< $capacidad`, esto se rompe en
     * cuanto la constante deja de ser 0.
     */
    public function test_el_rango_cubre_exactamente_la_capacidad_del_sensor(): void
    {
        $capacidad = 7;
        $entregadas = [];

        for ($i = 0; $i < $capacidad; $i++) {
            $this->conSensor($capacidad, $entregadas);
            $entregadas[] = $this->selector->siguienteLibre($this->lector);
        }

        $this->assertCount($capacidad, $entregadas, 'El sensor tiene que entregar tantas ranuras como capacidad declara.');
        $this->assertSame($this->primera(), $entregadas[0]);
        $this->assertSame($this->ultima($capacidad), end($entregadas));
        $this->assertSame(range($this->primera(), $this->ultima($capacidad)), $entregadas);
    }

    /** El tope se deriva de la constante, no de suponer que la base es cero. */
    public function test_la_ranura_maxima_se_deriva_de_la_minima(): void
    {
        foreach ([1, 5, 127, 162, 300, 1000] as $capacidad) {
            $this->assertSame(
                SelectorRanura::RANURA_MINIMA + $capacidad - 1,
                SelectorRanura::ranuraMaxima($capacidad),
                "El tope de un sensor de {$capacidad} ranuras tiene que salir de RANURA_MINIMA."
            );

            $this->assertTrue(SelectorRanura::dentroDelRango(SelectorRanura::RANURA_MINIMA, $capacidad));
            $this->assertTrue(SelectorRanura::dentroDelRango(SelectorRanura::ranuraMaxima($capacidad), $capacidad));
            $this->assertFalse(SelectorRanura::dentroDelRango(SelectorRanura::ranuraMaxima($capacidad) + 1, $capacidad));
            $this->assertFalse(SelectorRanura::dentroDelRango(SelectorRanura::RANURA_MINIMA - 1, $capacidad));
        }
    }

    /** Un sensor de una sola ranura: primera y última son la misma. */
    public function test_un_sensor_de_una_sola_ranura(): void
    {
        $this->conSensor(1, []);

        $this->assertSame($this->primera(), $this->ultima(1));
        $this->assertSame($this->primera(), $this->selector->siguienteLibre($this->lector));

        $this->conSensor(1, [$this->primera()]);

        $this->expectException(EnrolamientoImposibleException::class);
        $this->selector->siguienteLibre($this->lector);
    }
}
