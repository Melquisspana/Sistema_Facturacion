<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoAjuste;
use App\Models\Planta\PlantaAjuste;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaUbicacion;
use App\Services\Planta\PlantaAjusteService;
use App\Support\Planta\BucketInventario;

/**
 * Andamiaje de las pruebas de ajuste.
 *
 * Se apoya en {@see RecepcionPlantaFixtures} porque el saldo con el que se juega
 * debe entrar como entra en la operación real —por recepción confirmada—, salvo
 * en la carga inicial, que es precisamente el caso de un bucket sin historial.
 */
trait AjustePlantaFixtures
{
    use RecepcionPlantaFixtures;

    protected function servicioAjuste(): PlantaAjusteService
    {
        return app(PlantaAjusteService::class);
    }

    /**
     * Escenario mínimo: bodega, insumo trazable y su lote, SIN ningún movimiento.
     * Es el punto de partida de la carga inicial.
     *
     * @return array{ubicacion: PlantaUbicacion, insumo: PlantaInsumo, lote: PlantaLote}
     */
    protected function escenarioVirgen(): array
    {
        $insumo = $this->insumoConLotes();

        return [
            'ubicacion' => $this->bodega(),
            'insumo' => $insumo,
            'lote' => PlantaLote::factory()->create(['planta_insumo_id' => $insumo->id]),
        ];
    }

    /**
     * Escenario con saldo real: 500 disponibles entrados por recepción.
     *
     * @return array{ubicacion: PlantaUbicacion, insumo: PlantaInsumo, lote: PlantaLote}
     */
    protected function escenarioConSaldo(
        string $cantidad = '5',
        EstadoDisponibilidad $destino = EstadoDisponibilidad::Disponible,
    ): array {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $admin = $this->admin();

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($insumo, ['cantidad_recibida' => $cantidad, 'estado_destino' => $destino->value]),
        ]), $admin);
        $this->servicioRecepcion()->confirmar($recepcion, $admin);

        return [
            'ubicacion' => $ubicacion,
            'insumo' => $insumo,
            'lote' => $recepcion->refresh()->detalles->first()->lote,
        ];
    }

    /** Bucket del escenario, en el estado indicado. */
    protected function bucketDeAjuste(
        array $e,
        EstadoDisponibilidad $estado = EstadoDisponibilidad::Disponible,
    ): BucketInventario {
        return new BucketInventario(
            insumoId: $e['insumo']->id,
            loteId: $e['lote']->id,
            ubicacionId: $e['ubicacion']->id,
            estado: $estado,
            trasladoId: 0,
        );
    }

    /**
     * Payload de un ajuste sobre el escenario.
     *
     * @return array<string, mixed>
     */
    protected function payloadAjuste(
        array $e,
        TipoAjuste $tipo,
        string $cantidad = '100',
        EstadoDisponibilidad $estado = EstadoDisponibilidad::Disponible,
        array $extraLinea = [],
        array $extraCabecera = [],
    ): array {
        return array_merge([
            'tipo' => $tipo->value,
            'fecha' => '2026-07-30',
            'motivo' => 'Diferencia constatada en revisión de bodega',
            'responsable_nombre' => 'Quien constató',
            'detalles' => [array_merge([
                'planta_insumo_id' => $e['insumo']->id,
                'planta_lote_id' => $e['lote']->id,
                'planta_ubicacion_id' => $e['ubicacion']->id,
                'estado_disponibilidad' => $estado->value,
                'cantidad' => $cantidad,
            ], $extraLinea)],
        ], $extraCabecera);
    }

    /** Borrador de ajuste ya creado. */
    protected function borradorAjuste(
        array $e,
        TipoAjuste $tipo = TipoAjuste::Positivo,
        string $cantidad = '100',
        EstadoDisponibilidad $estado = EstadoDisponibilidad::Disponible,
        array $extraLinea = [],
    ): PlantaAjuste {
        return $this->servicioAjuste()->crearBorrador(
            $this->payloadAjuste($e, $tipo, $cantidad, $estado, $extraLinea),
            $this->admin(),
        );
    }

    /** Ajuste ya confirmado. */
    protected function ajusteConfirmado(
        array $e,
        TipoAjuste $tipo = TipoAjuste::Positivo,
        string $cantidad = '100',
        EstadoDisponibilidad $estado = EstadoDisponibilidad::Disponible,
        array $extraLinea = [],
    ): PlantaAjuste {
        $ajuste = $this->borradorAjuste($e, $tipo, $cantidad, $estado, $extraLinea);

        return $this->servicioAjuste()->confirmar($ajuste, $this->admin());
    }
}
