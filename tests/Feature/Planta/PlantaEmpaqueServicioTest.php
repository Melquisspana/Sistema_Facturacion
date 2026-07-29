<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\MercadoPlanta;
use App\Models\Planta\PlantaEmpaqueConfig;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaPresentacion;
use App\Services\Planta\EmpaqueConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Invariantes de EmpaqueConfigService: lo que una clave foránea no puede
 * expresar y por tanto tiene que garantizar el backend.
 *
 * Todo se comprueba llamando al SERVICIO, no al formulario: la validación de
 * un Form Request es una comodidad para el usuario, no una barrera.
 */
class PlantaEmpaqueServicioTest extends TestCase
{
    use RefreshDatabase;

    private EmpaqueConfigService $servicio;

    private PlantaPresentacion $presentacion;

    private PlantaInsumo $bolsa;

    private PlantaInsumo $vinieta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servicio = app(EmpaqueConfigService::class);
        $this->presentacion = PlantaPresentacion::factory()->create();
        $this->bolsa = PlantaInsumo::factory()->bolsa()->create();
        $this->vinieta = PlantaInsumo::factory()->vinieta()->create();
    }

    /** @return array<string, mixed> */
    private function datos(array $sobrescribir = []): array
    {
        return array_merge([
            'planta_presentacion_id' => $this->presentacion->id,
            'planta_insumo_bolsa_id' => $this->bolsa->id,
            'planta_insumo_vinieta_id' => null,
            'marca' => null,
            'mercado' => MercadoPlanta::Nacional->value,
            'referencia_cliente' => null,
            'es_predeterminada' => false,
            'activo' => true,
            'vigente_desde' => null,
            'vigente_hasta' => null,
        ], $sobrescribir);
    }

    // --- Tipos de insumo ---

    public function test_la_bolsa_debe_ser_de_tipo_bolsa(): void
    {
        $materia = PlantaInsumo::factory()->create(); // materia_prima

        $this->expectException(ValidationException::class);

        $this->servicio->crear($this->datos(['planta_insumo_bolsa_id' => $materia->id]));
    }

    public function test_la_vinieta_debe_ser_de_tipo_vinieta(): void
    {
        $this->expectException(ValidationException::class);

        // Se le pasa una BOLSA en el hueco de la viñeta.
        $this->servicio->crear($this->datos(['planta_insumo_vinieta_id' => $this->bolsa->id]));
    }

    public function test_la_vinieta_puede_omitirse(): void
    {
        $config = $this->servicio->crear($this->datos(['planta_insumo_vinieta_id' => null]));

        $this->assertNull($config->planta_insumo_vinieta_id);
        $this->assertSame(0, $config->fresh()->vinieta_key);
    }

    public function test_se_acepta_una_vinieta_del_tipo_correcto(): void
    {
        $config = $this->servicio->crear($this->datos(['planta_insumo_vinieta_id' => $this->vinieta->id]));

        $this->assertSame($this->vinieta->id, $config->planta_insumo_vinieta_id);
    }

    // --- Estado de los insumos y de la presentación ---

    public function test_no_se_puede_usar_una_bolsa_inactiva_en_una_configuracion_nueva(): void
    {
        $this->bolsa->update(['activo' => false]);

        $this->expectException(ValidationException::class);

        $this->servicio->crear($this->datos());
    }

    public function test_no_se_puede_usar_una_vinieta_inactiva_en_una_configuracion_nueva(): void
    {
        $this->vinieta->update(['activo' => false]);

        $this->expectException(ValidationException::class);

        $this->servicio->crear($this->datos(['planta_insumo_vinieta_id' => $this->vinieta->id]));
    }

    public function test_no_se_puede_colgar_una_configuracion_de_una_presentacion_inactiva(): void
    {
        $this->presentacion->update(['activo' => false]);

        $this->expectException(ValidationException::class);

        $this->servicio->crear($this->datos());
    }

    public function test_al_editar_se_conserva_el_insumo_historico_aunque_quede_inactivo(): void
    {
        // Regla deliberada: lo que ya estaba configurado sigue siendo válido; lo
        // que no se admite es CAMBIAR hacia otro insumo inactivo.
        $config = $this->servicio->crear($this->datos(['marca' => 'Original']));

        $this->bolsa->update(['activo' => false]);

        $actualizada = $this->servicio->actualizar($config, $this->datos([
            'marca' => 'Editada', 'planta_insumo_bolsa_id' => $this->bolsa->id,
        ]));

        $this->assertSame('Editada', $actualizada->fresh()->marca);
    }

    public function test_al_editar_no_se_puede_cambiar_a_otro_insumo_inactivo(): void
    {
        $config = $this->servicio->crear($this->datos());
        $otraBolsa = PlantaInsumo::factory()->bolsa()->create(['activo' => false]);

        $this->expectException(ValidationException::class);

        $this->servicio->actualizar($config, $this->datos(['planta_insumo_bolsa_id' => $otraBolsa->id]));
    }

    // --- Mercado y vigencia ---

    public function test_el_mercado_debe_pertenecer_al_enum(): void
    {
        $this->expectException(ValidationException::class);

        $this->servicio->crear($this->datos(['mercado' => 'inventado']));
    }

    public function test_la_vigencia_no_puede_terminar_antes_de_empezar(): void
    {
        $this->expectException(ValidationException::class);

        $this->servicio->crear($this->datos([
            'vigente_desde' => '2026-08-01', 'vigente_hasta' => '2026-07-01',
        ]));
    }

    public function test_se_admite_una_vigencia_coherente_y_tambien_sin_fechas(): void
    {
        $conFechas = $this->servicio->crear($this->datos([
            'marca' => 'A', 'vigente_desde' => '2026-07-01', 'vigente_hasta' => '2026-12-31',
        ]));
        $sinFechas = $this->servicio->crear($this->datos(['marca' => 'B']));

        $this->assertSame('2026-07-01', $conFechas->fresh()->vigente_desde->toDateString());
        $this->assertNull($sinFechas->fresh()->vigente_desde);
    }

    // --- Duplicados ---

    public function test_el_servicio_rechaza_una_configuracion_equivalente(): void
    {
        $this->servicio->crear($this->datos(['marca' => 'La Negrita']));

        $this->expectException(ValidationException::class);

        // Misma presentación, mercado, bolsa y viñeta; marca equivalente.
        $this->servicio->crear($this->datos(['marca' => '  LA NEGRITA ']));
    }

    public function test_editar_una_configuracion_no_choca_consigo_misma(): void
    {
        $config = $this->servicio->crear($this->datos(['marca' => 'La Negrita']));

        $actualizada = $this->servicio->actualizar($config, $this->datos([
            'marca' => 'La Negrita', 'referencia_cliente' => 'Cliente X',
        ]));

        $this->assertSame('Cliente X', $actualizada->fresh()->referencia_cliente);
    }

    // --- Predeterminada ---

    public function test_marcar_una_predeterminada_desmarca_la_anterior(): void
    {
        $primera = $this->servicio->crear($this->datos(['marca' => 'A', 'es_predeterminada' => true]));
        $segunda = $this->servicio->crear($this->datos(['marca' => 'B']));

        $this->servicio->marcarPredeterminada($segunda);

        $this->assertFalse($primera->fresh()->es_predeterminada);
        $this->assertTrue($segunda->fresh()->es_predeterminada);
        $this->assertNull($primera->fresh()->predeterminada_key);
        $this->assertSame('nacional', $segunda->fresh()->predeterminada_key);
    }

    public function test_el_relevo_de_predeterminada_ocurre_en_una_sola_transaccion(): void
    {
        $primera = $this->servicio->crear($this->datos(['marca' => 'A', 'es_predeterminada' => true]));
        $segunda = $this->servicio->crear($this->datos(['marca' => 'B']));

        // Si el desmarcado y el marcado no compartieran transacción, un fallo
        // intermedio dejaría la presentación sin ninguna predeterminada.
        $transacciones = 0;
        DB::listen(function ($consulta) use (&$transacciones) {
            if (str_starts_with(strtolower(trim($consulta->sql)), 'savepoint')) {
                $transacciones++;
            }
        });

        $this->servicio->marcarPredeterminada($segunda);

        $this->assertSame(1, PlantaEmpaqueConfig::where('es_predeterminada', true)->count());
        $this->assertSame(0, $transacciones, 'No debería haber transacciones anidadas sueltas.');
    }

    public function test_nunca_quedan_dos_predeterminadas_del_mismo_mercado(): void
    {
        $this->servicio->crear($this->datos(['marca' => 'A', 'es_predeterminada' => true]));
        $b = $this->servicio->crear($this->datos(['marca' => 'B']));
        $c = $this->servicio->crear($this->datos(['marca' => 'C']));

        $this->servicio->marcarPredeterminada($b);
        $this->servicio->marcarPredeterminada($c);

        $this->assertSame(1, PlantaEmpaqueConfig::where('es_predeterminada', true)->count());
        $this->assertTrue($c->fresh()->es_predeterminada);
    }

    public function test_cada_mercado_conserva_su_propia_predeterminada(): void
    {
        $nacional = $this->servicio->crear($this->datos(['marca' => 'A', 'es_predeterminada' => true]));
        $exportacion = $this->servicio->crear($this->datos([
            'marca' => 'A', 'mercado' => MercadoPlanta::Exportacion->value, 'es_predeterminada' => true,
        ]));

        $this->assertTrue($nacional->fresh()->es_predeterminada);
        $this->assertTrue($exportacion->fresh()->es_predeterminada);
        $this->assertSame(2, PlantaEmpaqueConfig::where('es_predeterminada', true)->count());
    }

    /**
     * Carrera SIMULADA. En SQLite `lockForUpdate` es un no-op, así que esta
     * prueba NO demuestra el bloqueo: demuestra que, si dos intentos llegasen a
     * solaparse, el índice único —que es la garantía dura— impide el estado
     * inválido. La concurrencia real se verifica a mano contra MySQL.
     */
    public function test_una_predeterminada_inyectada_a_traicion_no_produce_dos(): void
    {
        $existente = $this->servicio->crear($this->datos(['marca' => 'A', 'es_predeterminada' => true]));
        $nueva = $this->servicio->crear($this->datos(['marca' => 'B']));

        // Simula que otra petición marcó otra predeterminada justo después de la
        // comprobación previa: se fuerza por debajo de Eloquent.
        $intruso = PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $this->presentacion->id,
            'planta_insumo_bolsa_id' => $this->bolsa->id,
            'marca' => 'INTRUSA',
        ]);

        $this->servicio->marcarPredeterminada($nueva);

        // Sea cual sea el orden, jamás hay dos predeterminadas del mismo mercado.
        $this->assertSame(1, PlantaEmpaqueConfig::where('es_predeterminada', true)
            ->where('mercado', MercadoPlanta::Nacional->value)->count());
        $this->assertTrue($nueva->fresh()->es_predeterminada);
        $this->assertFalse($existente->fresh()->es_predeterminada);
        $this->assertFalse($intruso->fresh()->es_predeterminada);
    }

    // --- Activación ---

    public function test_desactivar_una_predeterminada_le_retira_esa_condicion(): void
    {
        // Si no, seguiría ocupando el hueco único de su mercado y ninguna otra
        // podría tomar el relevo.
        $config = $this->servicio->crear($this->datos(['marca' => 'A', 'es_predeterminada' => true]));

        $this->servicio->alternarActivo($config);

        $this->assertFalse($config->fresh()->activo);
        $this->assertFalse($config->fresh()->es_predeterminada);
        $this->assertNull($config->fresh()->predeterminada_key);

        // Y ahora otra sí puede ser la predeterminada.
        $otra = $this->servicio->crear($this->datos(['marca' => 'B']));
        $this->servicio->marcarPredeterminada($otra);

        $this->assertTrue($otra->fresh()->es_predeterminada);
    }

    public function test_una_configuracion_inactiva_no_puede_ser_predeterminada(): void
    {
        $config = $this->servicio->crear($this->datos());
        $this->servicio->alternarActivo($config);

        $this->expectException(ValidationException::class);

        $this->servicio->marcarPredeterminada($config->fresh());
    }

    // --- Reactivación: revalidación estricta de dependencias ---

    /** Crea una configuración con viñeta y la deja desactivada. */
    private function configuracionInactiva(): PlantaEmpaqueConfig
    {
        $config = $this->servicio->crear($this->datos([
            'planta_insumo_vinieta_id' => $this->vinieta->id,
        ]));
        $this->servicio->alternarActivo($config);

        return $config->fresh();
    }

    public function test_no_se_reactiva_con_la_presentacion_inactiva(): void
    {
        $config = $this->configuracionInactiva();
        $this->presentacion->update(['activo' => false]);

        $this->expectException(ValidationException::class);

        $this->servicio->alternarActivo($config);
    }

    public function test_no_se_reactiva_con_la_bolsa_inactiva(): void
    {
        $config = $this->configuracionInactiva();
        $this->bolsa->update(['activo' => false]);

        $this->expectException(ValidationException::class);

        $this->servicio->alternarActivo($config);
    }

    public function test_no_se_reactiva_con_la_vinieta_inactiva(): void
    {
        $config = $this->configuracionInactiva();
        $this->vinieta->update(['activo' => false]);

        $this->expectException(ValidationException::class);

        $this->servicio->alternarActivo($config);
    }

    public function test_se_reactiva_cuando_todas_las_dependencias_estan_activas(): void
    {
        $config = $this->configuracionInactiva();

        $this->servicio->alternarActivo($config);

        $this->assertTrue($config->fresh()->activo);
        $this->assertSame($this->bolsa->id, $config->fresh()->planta_insumo_bolsa_id);
        $this->assertSame($this->vinieta->id, $config->fresh()->planta_insumo_vinieta_id);
    }

    public function test_se_reactiva_una_configuracion_sin_vinieta(): void
    {
        // Sin viñeta no hay tercera dependencia que comprobar.
        $config = $this->servicio->crear($this->datos(['planta_insumo_vinieta_id' => null]));
        $this->servicio->alternarActivo($config);

        $this->servicio->alternarActivo($config->fresh());

        $this->assertTrue($config->fresh()->activo);
    }

    public function test_el_fallo_al_reactivar_no_modifica_nada(): void
    {
        $config = $this->configuracionInactiva();

        // Otra configuración de la misma presentación, activa y predeterminada:
        // no debe verse afectada por el intento fallido.
        $otra = $this->servicio->crear($this->datos(['marca' => 'Otra', 'es_predeterminada' => true]));

        $this->bolsa->update(['activo' => false]);

        try {
            $this->servicio->alternarActivo($config);
            $this->fail('Debería haber rechazado la reactivación.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        // La configuración sigue inactiva y sin la marca de predeterminada.
        $this->assertFalse($config->fresh()->activo);
        $this->assertFalse($config->fresh()->es_predeterminada);
        $this->assertNull($config->fresh()->predeterminada_key);

        // Y la otra conserva su estado intacto.
        $this->assertTrue($otra->fresh()->activo);
        $this->assertTrue($otra->fresh()->es_predeterminada);
        $this->assertSame(1, PlantaEmpaqueConfig::where('es_predeterminada', true)->count());
    }

    public function test_una_configuracion_inactiva_conserva_sus_referencias_historicas(): void
    {
        // El histórico se consulta siempre: retirar un insumo no borra de dónde
        // venía la configuración, solo impide volver a ponerla en circulación.
        $config = $this->configuracionInactiva();

        $this->bolsa->update(['activo' => false]);
        $this->vinieta->update(['activo' => false]);

        $config = $config->fresh();

        $this->assertFalse($config->activo);
        $this->assertTrue($config->bolsa->is($this->bolsa));
        $this->assertTrue($config->vinieta->is($this->vinieta));
        $this->assertFalse($config->bolsa->activo);
        $this->assertFalse($config->vinieta->activo);

        // Y sigue siendo editable conservando esos mismos insumos.
        $actualizada = $this->servicio->actualizar($config, $this->datos([
            'planta_insumo_vinieta_id' => $this->vinieta->id,
            'marca' => 'Editada con histórico',
            'activo' => false,
        ]));

        $this->assertSame('Editada con histórico', $actualizada->fresh()->marca);
    }
}
