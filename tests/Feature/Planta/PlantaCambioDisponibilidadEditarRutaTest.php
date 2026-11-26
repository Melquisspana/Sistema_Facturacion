<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoCambioDisponibilidad;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Models\Planta\PlantaCambioDisponibilidad;
use App\Models\Planta\PlantaRecepcion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Producción › Cambios de disponibilidad › «Guardar» del borrador, por su RUTA
 * (`planta.disponibilidad.update`).
 *
 * Lo que tiene que pasar: un borrador guarda TODO lo que se editó y no mueve nada del
 * inventario (eso solo lo hace «Confirmar»). Un documento que ya no es borrador
 * —confirmado, anulado o reversado— se rechaza sin tocar el documento ni las
 * existencias de ningún estado de calidad. Datos ficticios.
 */
class PlantaCambioDisponibilidadEditarRutaTest extends TestCase
{
    use CambioDisponibilidadFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encenderModulo();
    }

    /** Los tres saldos del bucket de la recepción, uno por estado de calidad. */
    private function saldos(PlantaRecepcion $recepcion): array
    {
        return [
            'retenido' => $this->saldo($this->bucketEn($recepcion, EstadoDisponibilidad::Retenido)),
            'disponible' => $this->saldo($this->bucketEn($recepcion, EstadoDisponibilidad::Disponible)),
            'rechazado' => $this->saldo($this->bucketEn($recepcion, EstadoDisponibilidad::Rechazado)),
        ];
    }

    /** Columnas del documento que el formulario puede cambiar, más estado y número. */
    private function columnas(PlantaCambioDisponibilidad $cambio): array
    {
        return (array) DB::table('planta_cambios_disponibilidad')->where('id', $cambio->id)->first([
            'numero', 'estado', 'estado_origen', 'estado_destino', 'cantidad', 'fecha', 'motivo',
            'responsable_user_id', 'responsable_nombre', 'planta_insumo_id', 'planta_lote_id', 'planta_ubicacion_id',
        ]);
    }

    public function test_un_borrador_guarda_todo_lo_editado_y_no_mueve_el_inventario(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');
        $admin = $this->admin();
        $responsable = User::factory()->create(['name' => 'Responsable de prueba']);
        $huella = $this->huellaMayor();
        $saldos = $this->saldos($recepcion);

        $this->actingAs($admin)
            ->from(route('planta.disponibilidad.edit', $cambio))
            ->put(route('planta.disponibilidad.update', $cambio), $this->payloadCambio($recepcion, '250.5', EstadoDisponibilidad::Rechazado, [
                'fecha' => '2026-08-02',
                'motivo' => 'Muestra de laboratorio fuera de especificación',
                'responsable_user_id' => $responsable->id,
                'responsable_nombre' => 'Encargada de calidad',
                // Ni el origen ni el estado los elige la petición.
                'estado_origen' => EstadoDisponibilidad::Disponible->value,
                'estado' => EstadoCambioDisponibilidad::Confirmado->value,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('planta.disponibilidad.show', $cambio))
            ->assertSessionHas('status', "Cambio #{$cambio->numero} actualizado.");

        $cambio->refresh();
        $this->assertSame(EstadoCambioDisponibilidad::Borrador, $cambio->estado);
        $this->assertSame(EstadoDisponibilidad::Retenido, $cambio->estado_origen);
        $this->assertSame(EstadoDisponibilidad::Rechazado, $cambio->estado_destino);
        $this->assertSame('250.5000', bcadd((string) $cambio->cantidad, '0', 4));
        $this->assertSame('2026-08-02', $cambio->fecha->toDateString());
        $this->assertSame('Muestra de laboratorio fuera de especificación', $cambio->motivo);
        $this->assertSame($responsable->id, (int) $cambio->responsable_user_id);
        $this->assertSame('Encargada de calidad', $cambio->responsable_nombre);

        // Guardar un borrador no escribe en el mayor ni en las existencias.
        $this->assertSame($huella, $this->huellaMayor());
        $this->assertSame($saldos, $this->saldos($recepcion));

        // La edición queda en la bitácora del documento, como la de los demás borradores.
        $actividad = Activity::query()
            ->where('log_name', 'planta_cambio_disponibilidad')
            ->where('subject_id', $cambio->id)
            ->where('event', 'updated')
            ->sole();
        $this->assertSame($admin->id, (int) $actividad->causer_id);
        $this->assertSame('Muestra de laboratorio fuera de especificación', $actividad->properties['attributes']['motivo']);
    }

    /** El formulario manda el saldo elegido en un solo campo: también así se guarda. */
    public function test_el_borrador_acepta_el_saldo_elegido_en_el_selector(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');
        $bucket = $this->bucketRetenido($recepcion);
        $datos = $this->payloadCambio($recepcion, '120');
        unset($datos['planta_insumo_id'], $datos['planta_lote_id'], $datos['planta_ubicacion_id']);

        $this->actingAs($this->admin())
            ->put(route('planta.disponibilidad.update', $cambio), $datos + [
                'bucket' => $bucket->insumoId.'|'.$bucket->loteId.'|'.$bucket->ubicacionId,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('planta.disponibilidad.show', $cambio));

        $this->assertSame('120.0000', bcadd((string) $cambio->refresh()->cantidad, '0', 4));
    }

    public function test_un_confirmado_se_rechaza_sin_tocar_el_documento_ni_las_existencias(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');
        $admin = $this->admin();
        $this->servicioCambio()->confirmar($cambio, $admin);

        $antes = $this->columnas($cambio);
        $huella = $this->huellaMayor();
        $saldos = $this->saldos($recepcion);
        $this->assertSame(['retenido' => '400.0000', 'disponible' => '100.0000', 'rechazado' => null], $saldos);

        $this->actingAs($admin)
            ->from(route('planta.disponibilidad.show', $cambio))
            ->put(route('planta.disponibilidad.update', $cambio), $this->payloadCambio($recepcion, '300', EstadoDisponibilidad::Rechazado, [
                'motivo' => 'Intento de cambiar un documento ya confirmado',
            ]))
            ->assertRedirect(route('planta.disponibilidad.show', $cambio))
            ->assertSessionHasErrors('cambio');

        $this->assertEquals($antes, $this->columnas($cambio));
        $this->assertSame($huella, $this->huellaMayor());
        $this->assertSame($saldos, $this->saldos($recepcion));
    }

    public function test_un_anulado_o_un_reversado_tampoco_se_editan(): void
    {
        $admin = $this->admin();

        $anulado = $this->borradorCambio();
        $this->servicioCambio()->anular($anulado);

        $recepcion = $this->saldoRetenido();
        $reversado = $this->borradorCambio($recepcion, '100');
        $this->servicioCambio()->confirmar($reversado, $admin);
        $reversion = $this->servicioCambio()->reversar($reversado->refresh(), 'Se confirmó por error en la revisión', $admin);

        $huella = $this->huellaMayor();
        $saldos = $this->saldos($recepcion);

        foreach ([$anulado, $reversado, $reversion] as $documento) {
            $antes = $this->columnas($documento);

            $this->actingAs($admin)
                ->from(route('planta.disponibilidad.show', $documento))
                ->put(route('planta.disponibilidad.update', $documento), $this->payloadCambio($recepcion, '50', EstadoDisponibilidad::Disponible, [
                    'motivo' => 'Intento de editar un documento cerrado',
                ]))
                ->assertSessionHasErrors('cambio');

            $this->assertEquals($antes, $this->columnas($documento), "El documento #{$documento->numero} no debió cambiar.");
        }

        $this->assertSame($huella, $this->huellaMayor());
        $this->assertSame($saldos, $this->saldos($recepcion));
    }

    public function test_una_cantidad_en_cero_o_sin_motivo_no_se_guarda(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');
        $antes = $this->columnas($cambio);
        $admin = $this->admin();

        $this->actingAs($admin)->from(route('planta.disponibilidad.edit', $cambio))
            ->put(route('planta.disponibilidad.update', $cambio), $this->payloadCambio($recepcion, '0'))
            ->assertSessionHasErrors('cantidad');

        $this->actingAs($admin)->from(route('planta.disponibilidad.edit', $cambio))
            ->put(route('planta.disponibilidad.update', $cambio), $this->payloadCambio($recepcion, '100', EstadoDisponibilidad::Disponible, ['motivo' => 'corto']))
            ->assertSessionHasErrors('motivo');

        // «retenido» es el origen, no un destino posible.
        $this->actingAs($admin)->from(route('planta.disponibilidad.edit', $cambio))
            ->put(route('planta.disponibilidad.update', $cambio), $this->payloadCambio($recepcion, '100', EstadoDisponibilidad::Retenido))
            ->assertSessionHasErrors('estado_destino');

        $this->assertEquals($antes, $this->columnas($cambio));
    }

    public function test_guardar_dos_veces_lo_mismo_no_crea_otro_documento_ni_gasta_numero(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');
        $admin = $this->admin();
        $datos = $this->payloadCambio($recepcion, '180', EstadoDisponibilidad::Rechazado);

        $this->actingAs($admin)->put(route('planta.disponibilidad.update', $cambio), $datos)->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('planta.disponibilidad.update', $cambio), $datos)->assertSessionHasNoErrors();

        $this->assertSame(1, PlantaCambioDisponibilidad::count());
        $this->assertSame($cambio->numero, $cambio->refresh()->numero);
        $this->assertSame('180.0000', bcadd((string) $cambio->cantidad, '0', 4));

        // El siguiente borrador sigue con el número que le tocaba.
        $siguiente = $this->borradorCambio($recepcion, '10');
        $this->assertSame($cambio->numero + 1, $siguiente->numero);
    }

    public function test_produccion_recibe_403_y_el_borrador_no_cambia(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');
        $antes = $this->columnas($cambio);

        $this->actingAs($this->usuarioConRol('produccion'))
            ->put(route('planta.disponibilidad.update', $cambio), $this->payloadCambio($recepcion, '300', EstadoDisponibilidad::Rechazado))
            ->assertForbidden();

        $this->assertEquals($antes, $this->columnas($cambio));
    }

    public function test_un_documento_que_no_existe_da_404_y_con_el_modulo_apagado_tambien(): void
    {
        $recepcion = $this->saldoRetenido();
        $cambio = $this->borradorCambio($recepcion, '100');
        $antes = $this->columnas($cambio);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('planta.disponibilidad.update', $cambio->id + 999), $this->payloadCambio($recepcion, '300'))
            ->assertNotFound();

        config()->set('planta.enabled', false);
        $this->actingAs($admin)
            ->put(route('planta.disponibilidad.update', $cambio), $this->payloadCambio($recepcion, '300'))
            ->assertNotFound();

        $this->assertEquals($antes, $this->columnas($cambio));
    }
}
