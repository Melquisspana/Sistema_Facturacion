<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\MercadoPlanta;
use App\Models\Planta\PlantaEmpaqueConfig;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaPresentacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Producción › Catálogos › Empaques › «Guardar» de la edición, por su RUTA
 * (`planta.empaques.update`).
 *
 * Lo que tiene que pasar: quedan guardados TODOS los campos que se editaron —la
 * referencia del cliente incluida—, no solo la redirección. Y el formulario no puede
 * dejar una configuración en un estado que el resto del módulo no admite: inactiva y
 * predeterminada a la vez, o reactivada con una bolsa que ya no se usa. Datos
 * ficticios.
 */
class PlantaEmpaqueEditarRutaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('planta.enabled', true);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    /** Configuración sencilla, activa, sin viñeta ni marca. */
    private function configuracion(array $atributos = []): PlantaEmpaqueConfig
    {
        return PlantaEmpaqueConfig::factory()->create($atributos);
    }

    /** Lo que manda el formulario para dejar la configuración como está. */
    private function formulario(PlantaEmpaqueConfig $config, array $cambios = []): array
    {
        return array_replace([
            'planta_presentacion_id' => $config->planta_presentacion_id,
            'planta_insumo_bolsa_id' => $config->planta_insumo_bolsa_id,
            'planta_insumo_vinieta_id' => $config->planta_insumo_vinieta_id ?? '',
            'marca' => $config->marca,
            'mercado' => $config->mercado->value,
            'referencia_cliente' => $config->referencia_cliente,
            'es_predeterminada' => $config->es_predeterminada ? '1' : '0',
            'activo' => $config->activo ? '1' : '0',
            'vigente_desde' => $config->vigente_desde?->toDateString(),
            'vigente_hasta' => $config->vigente_hasta?->toDateString(),
        ], $cambios);
    }

    /** Las columnas que edita el formulario, tal como quedaron en la base. */
    private function columnas(PlantaEmpaqueConfig $config): array
    {
        return (array) DB::table('planta_empaque_configs')->where('id', $config->id)->first([
            'planta_presentacion_id', 'planta_insumo_bolsa_id', 'planta_insumo_vinieta_id', 'marca', 'mercado',
            'referencia_cliente', 'es_predeterminada', 'activo', 'vigente_desde', 'vigente_hasta',
        ]);
    }

    public function test_guarda_todos_los_campos_editados_con_la_referencia_del_cliente(): void
    {
        $config = $this->configuracion();
        $admin = $this->admin();
        $otraPresentacion = PlantaPresentacion::factory()->create();
        $otraBolsa = PlantaInsumo::factory()->bolsa()->create();
        $vinieta = PlantaInsumo::factory()->vinieta()->create();

        $this->actingAs($admin)
            ->from(route('planta.empaques.edit', $config))
            ->put(route('planta.empaques.update', $config), $this->formulario($config, [
                'planta_presentacion_id' => $otraPresentacion->id,
                'planta_insumo_bolsa_id' => $otraBolsa->id,
                'planta_insumo_vinieta_id' => $vinieta->id,
                'marca' => 'Marca de prueba',
                'mercado' => MercadoPlanta::Exportacion->value,
                'referencia_cliente' => 'REF-PRUEBA-01',
                'es_predeterminada' => '1',
                'vigente_desde' => '2026-09-01',
                'vigente_hasta' => '2026-12-31',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('planta.empaques.index'))
            ->assertSessionHas('status', "Configuración de empaque #{$config->id} actualizada.");

        $config->refresh();
        $this->assertSame($otraPresentacion->id, $config->planta_presentacion_id);
        $this->assertSame($otraBolsa->id, $config->planta_insumo_bolsa_id);
        $this->assertSame($vinieta->id, $config->planta_insumo_vinieta_id);
        $this->assertSame('Marca de prueba', $config->marca);
        $this->assertSame('MARCA DE PRUEBA', $config->marca_norm);
        $this->assertSame(MercadoPlanta::Exportacion, $config->mercado);
        $this->assertSame('REF-PRUEBA-01', $config->referencia_cliente);
        $this->assertTrue($config->es_predeterminada);
        $this->assertTrue($config->activo);
        $this->assertSame('2026-09-01', $config->vigente_desde->toDateString());
        $this->assertSame('2026-12-31', $config->vigente_hasta->toDateString());

        // La bitácora del catálogo dice quién cambió la referencia y a qué.
        $actividad = Activity::query()
            ->where('log_name', 'planta_empaque')
            ->where('subject_id', $config->id)
            ->where('event', 'updated')
            ->sole();
        $this->assertSame($admin->id, (int) $actividad->causer_id);
        $this->assertSame('REF-PRUEBA-01', $actividad->properties['attributes']['referencia_cliente']);
        $this->assertNull($actividad->properties['old']['referencia_cliente']);
    }

    /** Vaciar los campos opcionales los deja vacíos de verdad, no con el valor viejo. */
    public function test_vaciar_la_referencia_la_marca_y_la_vinieta_las_borra(): void
    {
        $config = $this->configuracion([
            'planta_insumo_vinieta_id' => PlantaInsumo::factory()->vinieta()->create()->id,
            'marca' => 'Marca de prueba',
            'referencia_cliente' => 'REF-PRUEBA-02',
            'vigente_desde' => '2026-09-01',
        ]);

        $this->actingAs($this->admin())
            ->put(route('planta.empaques.update', $config), $this->formulario($config, [
                'planta_insumo_vinieta_id' => '',
                'marca' => '',
                'referencia_cliente' => '',
                'vigente_desde' => '',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('planta.empaques.index'));

        $config->refresh();
        $this->assertNull($config->planta_insumo_vinieta_id);
        $this->assertNull($config->marca);
        $this->assertNull($config->referencia_cliente);
        $this->assertNull($config->vigente_desde);
    }

    public function test_marcarla_predeterminada_al_editar_le_quita_la_condicion_a_la_anterior(): void
    {
        $anterior = $this->configuracion(['marca' => 'Marca A', 'es_predeterminada' => true]);
        $config = $this->configuracion([
            'planta_presentacion_id' => $anterior->planta_presentacion_id,
            'marca' => 'Marca B',
        ]);

        $this->actingAs($this->admin())
            ->put(route('planta.empaques.update', $config), $this->formulario($config, ['es_predeterminada' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertTrue($config->refresh()->es_predeterminada);
        $this->assertFalse($anterior->refresh()->es_predeterminada);
    }

    /**
     * Desmarcar «Activa» en la predeterminada hace lo mismo que el botón «Desactivar» del
     * listado: le quita «Predeterminada» sola, guarda lo demás y el aviso lo dice. Nunca
     * queda inactiva ocupando el lugar de la predeterminada.
     */
    public function test_desactivar_la_predeterminada_al_editar_le_quita_la_marca_y_lo_avisa(): void
    {
        $config = $this->configuracion(['es_predeterminada' => true]);

        $this->actingAs($this->admin())
            ->from(route('planta.empaques.edit', $config))
            ->put(route('planta.empaques.update', $config), $this->formulario($config, [
                'activo' => '0',
                'es_predeterminada' => '1',
                'referencia_cliente' => 'REF-PRUEBA-03',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('planta.empaques.index'))
            ->assertSessionHas('status', "Configuración de empaque #{$config->id} actualizada. Se le quitó «Predeterminada» porque quedó inactiva.");

        $config->refresh();
        $this->assertFalse($config->activo);
        $this->assertFalse($config->es_predeterminada);
        $this->assertNull($config->predeterminada_key);
        $this->assertSame('REF-PRUEBA-03', $config->referencia_cliente);

        // Y otra del mismo mercado puede tomar el relevo.
        $otra = $this->configuracion(['planta_presentacion_id' => $config->planta_presentacion_id, 'marca' => 'Marca B']);
        $this->actingAs($this->admin())
            ->patch(route('planta.empaques.predeterminada', $otra))
            ->assertSessionHasNoErrors();
        $this->assertTrue($otra->refresh()->es_predeterminada);
    }

    /**
     * La regla sigue: una configuración que NO era la predeterminada no puede quedar
     * inactiva y predeterminada a la vez desde el formulario. No cambia nada.
     */
    public function test_una_que_no_era_predeterminada_no_queda_inactiva_y_predeterminada(): void
    {
        $config = $this->configuracion();
        $antes = $this->columnas($config);

        $this->actingAs($this->admin())
            ->from(route('planta.empaques.edit', $config))
            ->put(route('planta.empaques.update', $config), $this->formulario($config, [
                'activo' => '0',
                'es_predeterminada' => '1',
                'referencia_cliente' => 'REF-PRUEBA-06',
            ]))
            ->assertRedirect(route('planta.empaques.edit', $config))
            ->assertSessionHasErrors('es_predeterminada');

        $this->assertEquals($antes, $this->columnas($config));
    }

    /**
     * Reactivarla desde el formulario exige lo mismo que el botón «Activar»: que la
     * presentación, la bolsa y la viñeta sigan en uso.
     */
    public function test_reactivarla_al_editar_exige_que_la_bolsa_siga_activa(): void
    {
        $config = $this->configuracion(['activo' => false]);
        PlantaInsumo::whereKey($config->planta_insumo_bolsa_id)->update(['activo' => false]);
        $antes = $this->columnas($config);

        $this->actingAs($this->admin())
            ->from(route('planta.empaques.edit', $config))
            ->put(route('planta.empaques.update', $config), $this->formulario($config, ['activo' => '1']))
            ->assertRedirect(route('planta.empaques.edit', $config))
            ->assertSessionHasErrors('activo');

        $this->assertEquals($antes, $this->columnas($config));
    }

    /** Una configuración que ya estaba activa conserva su bolsa vieja al editar otra cosa. */
    public function test_editar_otra_cosa_conserva_la_bolsa_historica_inactiva(): void
    {
        $config = $this->configuracion();
        PlantaInsumo::whereKey($config->planta_insumo_bolsa_id)->update(['activo' => false]);

        $this->actingAs($this->admin())
            ->put(route('planta.empaques.update', $config), $this->formulario($config, ['referencia_cliente' => 'REF-PRUEBA-04']))
            ->assertSessionHasNoErrors();

        $this->assertSame('REF-PRUEBA-04', $config->refresh()->referencia_cliente);
        $this->assertTrue($config->activo);
    }

    public function test_datos_que_no_corresponden_no_cambian_nada(): void
    {
        $config = $this->configuracion(['marca' => 'Marca A']);
        $gemela = $this->configuracion([
            'planta_presentacion_id' => $config->planta_presentacion_id,
            'planta_insumo_bolsa_id' => $config->planta_insumo_bolsa_id,
            'marca' => 'Marca B',
        ]);
        $antes = $this->columnas($config);
        $admin = $this->admin();
        $materiaPrima = PlantaInsumo::factory()->create();

        $casos = [
            'vigencia invertida' => [['vigente_desde' => '2026-12-01', 'vigente_hasta' => '2026-11-01'], 'vigente_hasta'],
            'bolsa que no es bolsa' => [['planta_insumo_bolsa_id' => $materiaPrima->id], 'planta_insumo_bolsa_id'],
            'mercado inventado' => [['mercado' => 'luna'], 'mercado'],
            'igual a otra configuración' => [['marca' => ' marca b '], 'planta_insumo_bolsa_id'],
        ];

        foreach ($casos as $caso => [$cambios, $campo]) {
            $this->actingAs($admin)
                ->from(route('planta.empaques.edit', $config))
                ->put(route('planta.empaques.update', $config), $this->formulario($config, $cambios + ['referencia_cliente' => 'NO-DEBE-QUEDAR']))
                ->assertSessionHasErrors($campo);

            $this->assertEquals($antes, $this->columnas($config), "Con «{$caso}» no debió cambiar nada.");
        }

        $this->assertSame('Marca B', $gemela->refresh()->marca);
    }

    public function test_guardar_dos_veces_lo_mismo_no_duplica_ni_ensucia_la_bitacora(): void
    {
        $config = $this->configuracion();
        $admin = $this->admin();
        $datos = $this->formulario($config, ['referencia_cliente' => 'REF-PRUEBA-05']);

        $this->actingAs($admin)->put(route('planta.empaques.update', $config), $datos)->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('planta.empaques.update', $config), $datos)->assertSessionHasNoErrors();

        $this->assertSame(1, PlantaEmpaqueConfig::count());
        $this->assertSame('REF-PRUEBA-05', $config->refresh()->referencia_cliente);
        $this->assertSame(1, Activity::query()
            ->where('log_name', 'planta_empaque')->where('subject_id', $config->id)->where('event', 'updated')->count());
    }

    public function test_produccion_recibe_403_y_no_cambia_nada(): void
    {
        $config = $this->configuracion();
        $antes = $this->columnas($config);

        $this->actingAs(User::factory()->create()->assignRole('produccion'))
            ->put(route('planta.empaques.update', $config), $this->formulario($config, ['referencia_cliente' => 'REF-PRUEBA-06']))
            ->assertForbidden();

        $this->assertEquals($antes, $this->columnas($config));
    }

    public function test_una_configuracion_borrada_o_inexistente_da_404_y_con_el_modulo_apagado_tambien(): void
    {
        $config = $this->configuracion();
        $borrada = $this->configuracion(['marca' => 'Marca borrada']);
        $borrada->delete();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('planta.empaques.update', $borrada), $this->formulario($borrada, ['referencia_cliente' => 'REF-PRUEBA-07']))
            ->assertNotFound();
        $this->actingAs($admin)
            ->put(route('planta.empaques.update', $config->id + 999), $this->formulario($config))
            ->assertNotFound();

        config()->set('planta.enabled', false);
        $this->actingAs($admin)
            ->put(route('planta.empaques.update', $config), $this->formulario($config, ['referencia_cliente' => 'REF-PRUEBA-07']))
            ->assertNotFound();

        $this->assertNull($config->refresh()->referencia_cliente);
        $this->assertNull(PlantaEmpaqueConfig::withTrashed()->find($borrada->id)->referencia_cliente);
    }
}
