<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoCambioDisponibilidad;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\MercadoPlanta;
use App\Models\Planta\PlantaCambioDisponibilidad;
use App\Models\Planta\PlantaEmpaqueConfig;
use App\Models\Planta\PlantaPresentacion;
use App\Models\Planta\PlantaRecepcion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Probador de la tanda 5 en Producción: se usan los botones como la planta, uno detrás
 * del otro, y se intenta romperlos (doble clic, editar lo ya confirmado, empujar un
 * saldo que no alcanza, mover un empaque a otra presentación).
 *
 * Todo por las rutas, como el navegador. Datos inventados.
 */
class PlantaFlujosProbadorTest extends TestCase
{
    use CambioDisponibilidadFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encenderModulo();
    }

    /** @return array{retenido: ?string, disponible: ?string, rechazado: ?string} */
    private function saldos(PlantaRecepcion $recepcion): array
    {
        return [
            'retenido' => $this->saldo($this->bucketEn($recepcion, EstadoDisponibilidad::Retenido)),
            'disponible' => $this->saldo($this->bucketEn($recepcion, EstadoDisponibilidad::Disponible)),
            'rechazado' => $this->saldo($this->bucketEn($recepcion, EstadoDisponibilidad::Rechazado)),
        ];
    }

    // ------------------------------------------------- cambios de disponibilidad

    /**
     * Crear → editar → confirmar (con doble clic) → intentar editar otra vez. Solo la
     * primera confirmación mueve saldo; lo demás no cambia ningún estado de calidad.
     */
    public function test_borrador_editado_confirmado_con_doble_clic_y_reeditado_mueve_el_saldo_una_sola_vez(): void
    {
        $recepcion = $this->saldoRetenido();
        $admin = $this->admin();
        $this->assertSame(['retenido' => '500.0000', 'disponible' => null, 'rechazado' => null], $this->saldos($recepcion));

        $this->actingAs($admin)
            ->post(route('planta.disponibilidad.store'), $this->payloadCambio($recepcion, '100'))
            ->assertSessionHasNoErrors();
        $cambio = PlantaCambioDisponibilidad::query()->sole();

        // Editar el borrador: otra cantidad y otro destino. No mueve nada todavía.
        $this->actingAs($admin)
            ->put(route('planta.disponibilidad.update', $cambio), $this->payloadCambio($recepcion, '120', EstadoDisponibilidad::Rechazado))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('planta.disponibilidad.show', $cambio));
        $this->assertSame(['retenido' => '500.0000', 'disponible' => null, 'rechazado' => null], $this->saldos($recepcion));

        // Confirmar, y el segundo clic llega detrás.
        $this->actingAs($admin)
            ->from(route('planta.disponibilidad.show', $cambio))
            ->patch(route('planta.disponibilidad.confirmar', $cambio))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('planta.disponibilidad.show', $cambio));
        $despuesDeConfirmar = $this->saldos($recepcion);
        $huella = $this->huellaMayor();
        $this->assertSame(['retenido' => '380.0000', 'disponible' => null, 'rechazado' => '120.0000'], $despuesDeConfirmar);

        $this->actingAs($admin)
            ->from(route('planta.disponibilidad.show', $cambio))
            ->patch(route('planta.disponibilidad.confirmar', $cambio))
            ->assertRedirect(route('planta.disponibilidad.show', $cambio))
            ->assertSessionHasErrors('cambio');

        // Y alguien con la pantalla de edición vieja abierta intenta guardar.
        $this->actingAs($admin)
            ->from(route('planta.disponibilidad.edit', $cambio))
            ->put(route('planta.disponibilidad.update', $cambio), $this->payloadCambio($recepcion, '300', EstadoDisponibilidad::Disponible))
            ->assertSessionHasErrors('cambio');
        $this->actingAs($admin)
            ->get(route('planta.disponibilidad.edit', $cambio))
            ->assertRedirect(route('planta.disponibilidad.show', $cambio))
            ->assertSessionHasErrors('cambio');

        $this->assertSame($despuesDeConfirmar, $this->saldos($recepcion));
        $this->assertSame($huella, $this->huellaMayor());

        $cambio->refresh();
        $this->assertSame(EstadoCambioDisponibilidad::Confirmado, $cambio->estado);
        $this->assertSame(EstadoDisponibilidad::Rechazado, $cambio->estado_destino);
        $this->assertSame('120.0000', bcadd((string) $cambio->cantidad, '0', 4));
        $this->assertSame(1, PlantaCambioDisponibilidad::count());
    }

    /** Un borrador puede pedir más de lo retenido, pero confirmarlo no deja nada a medias. */
    public function test_un_borrador_editado_por_encima_del_saldo_no_se_confirma_ni_mueve_nada(): void
    {
        $recepcion = $this->saldoRetenido();
        $admin = $this->admin();
        $cambio = $this->borradorCambio($recepcion, '100');

        $this->actingAs($admin)
            ->put(route('planta.disponibilidad.update', $cambio), $this->payloadCambio($recepcion, '600'))
            ->assertSessionHasNoErrors();

        $saldos = $this->saldos($recepcion);
        $huella = $this->huellaMayor();

        $this->actingAs($admin)
            ->from(route('planta.disponibilidad.show', $cambio))
            ->patch(route('planta.disponibilidad.confirmar', $cambio))
            ->assertRedirect(route('planta.disponibilidad.show', $cambio))
            ->assertSessionHasErrors('cambio');

        $this->assertSame(EstadoCambioDisponibilidad::Borrador, $cambio->refresh()->estado);
        $this->assertSame($saldos, $this->saldos($recepcion));
        $this->assertSame($huella, $this->huellaMayor());
    }

    /** Dos borradores sobre el mismo saldo: el segundo ya no alcanza y no toca nada. */
    public function test_dos_borradores_sobre_el_mismo_saldo_no_lo_gastan_dos_veces(): void
    {
        $recepcion = $this->saldoRetenido();
        $admin = $this->admin();
        $primero = $this->borradorCambio($recepcion, '300');
        $segundo = $this->borradorCambio($recepcion, '300', EstadoDisponibilidad::Rechazado);

        $this->actingAs($admin)->patch(route('planta.disponibilidad.confirmar', $primero))->assertSessionHasNoErrors();
        $saldos = $this->saldos($recepcion);
        $this->assertSame(['retenido' => '200.0000', 'disponible' => '300.0000', 'rechazado' => null], $saldos);

        $this->actingAs($admin)
            ->from(route('planta.disponibilidad.show', $segundo))
            ->patch(route('planta.disponibilidad.confirmar', $segundo))
            ->assertSessionHasErrors('cambio');

        $this->assertSame(EstadoCambioDisponibilidad::Borrador, $segundo->refresh()->estado);
        $this->assertSame($saldos, $this->saldos($recepcion));
    }

    /** Un borrador editado a mano con el lote de OTRO insumo no se confirma ni mueve nada. */
    public function test_un_borrador_con_el_lote_de_otro_insumo_no_se_confirma(): void
    {
        $recepcion = $this->saldoRetenido();
        $otra = $this->saldoRetenido();
        $admin = $this->admin();
        $cambio = $this->borradorCambio($recepcion, '100');

        $this->actingAs($admin)
            ->put(route('planta.disponibilidad.update', $cambio), $this->payloadCambio($recepcion, '100', EstadoDisponibilidad::Disponible, [
                'planta_lote_id' => $this->bucketDe($otra)->loteId,
            ]));

        $saldos = [$this->saldos($recepcion), $this->saldos($otra)];
        $huella = $this->huellaMayor();

        $this->actingAs($admin)
            ->from(route('planta.disponibilidad.show', $cambio))
            ->patch(route('planta.disponibilidad.confirmar', $cambio))
            ->assertSessionHasErrors('cambio');

        $this->assertSame(EstadoCambioDisponibilidad::Borrador, $cambio->refresh()->estado);
        $this->assertSame($saldos, [$this->saldos($recepcion), $this->saldos($otra)]);
        $this->assertSame($huella, $this->huellaMayor());
    }

    public function test_produccion_no_puede_confirmar_y_el_saldo_no_se_mueve(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');
        $saldos = $this->saldos($recepcion);

        $this->actingAs($this->usuarioConRol('produccion'))
            ->patch(route('planta.disponibilidad.confirmar', $cambio))
            ->assertForbidden();

        $this->assertSame(EstadoCambioDisponibilidad::Borrador, $cambio->refresh()->estado);
        $this->assertSame($saldos, $this->saldos($recepcion));
    }

    // ------------------------------------------------------------------ empaques

    /** @return array<string, mixed> */
    private function formularioEmpaque(PlantaEmpaqueConfig $config, array $cambios = []): array
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

    /**
     * Mover un empaque a OTRA presentación y marcarlo predeterminado le quita la marca a
     * la predeterminada de esa presentación, no a la de la presentación de donde viene,
     * y nunca quedan dos predeterminadas para el mismo mercado.
     */
    public function test_moverlo_a_otra_presentacion_como_predeterminado_deja_una_sola_por_mercado(): void
    {
        $destino = PlantaPresentacion::factory()->create();
        $delDestino = PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $destino->id,
            'mercado' => MercadoPlanta::Nacional->value,
            'marca' => 'Marca del destino',
            'es_predeterminada' => true,
        ]);
        $config = PlantaEmpaqueConfig::factory()->create([
            'mercado' => MercadoPlanta::Nacional->value,
            'marca' => 'Marca de prueba',
            'es_predeterminada' => true,
        ]);
        $hermanaDeOrigen = PlantaEmpaqueConfig::factory()->create([
            'planta_presentacion_id' => $config->planta_presentacion_id,
            'mercado' => MercadoPlanta::Nacional->value,
            'marca' => 'Otra marca',
        ]);

        $this->actingAs($this->admin())
            ->put(route('planta.empaques.update', $config), $this->formularioEmpaque($config, [
                'planta_presentacion_id' => $destino->id,
                'es_predeterminada' => '1',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($destino->id, $config->refresh()->planta_presentacion_id);
        $this->assertTrue($config->es_predeterminada);
        $this->assertFalse($delDestino->refresh()->es_predeterminada);
        $this->assertFalse($hermanaDeOrigen->refresh()->es_predeterminada);
        $this->assertSame(1, PlantaEmpaqueConfig::query()
            ->where('planta_presentacion_id', $destino->id)
            ->where('mercado', MercadoPlanta::Nacional->value)
            ->where('es_predeterminada', true)
            ->count());
    }

    /** Quien no gestiona catálogos no abre ni guarda la edición de un empaque. */
    public function test_sin_permiso_de_catalogos_no_abre_ni_guarda_la_edicion(): void
    {
        $config = PlantaEmpaqueConfig::factory()->create(['referencia_cliente' => 'REF-ORIGINAL']);
        $antes = (array) DB::table('planta_empaque_configs')->where('id', $config->id)->first();

        foreach (['produccion', 'facturacion'] as $rol) {
            $usuario = User::factory()->create()->assignRole($rol);

            $this->actingAs($usuario)->get(route('planta.empaques.edit', $config))->assertForbidden();
            $this->actingAs($usuario)
                ->put(route('planta.empaques.update', $config), $this->formularioEmpaque($config, ['referencia_cliente' => 'REF-AJENA']))
                ->assertForbidden();
        }

        $this->assertEquals($antes, (array) DB::table('planta_empaque_configs')->where('id', $config->id)->first());
    }

    /**
     * Desactivar desde «Editar» (desmarcando las dos casillas) y reactivar desde
     * «Editar» deja el empaque como lo deja el botón: activo y sin la marca vieja.
     */
    public function test_desactivar_y_reactivar_desde_editar_no_le_devuelve_la_marca_sola(): void
    {
        $config = PlantaEmpaqueConfig::factory()->create(['es_predeterminada' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('planta.empaques.update', $config), $this->formularioEmpaque($config, ['activo' => '0', 'es_predeterminada' => '0']))
            ->assertSessionHasNoErrors();
        $this->assertFalse($config->refresh()->activo);
        $this->assertFalse($config->es_predeterminada);

        $this->actingAs($admin)
            ->put(route('planta.empaques.update', $config), $this->formularioEmpaque($config, ['activo' => '1']))
            ->assertSessionHasNoErrors();
        $this->assertTrue($config->refresh()->activo);
        $this->assertFalse($config->es_predeterminada);
    }
}
