<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Models\Planta\PlantaCambioDisponibilidad;
use App\Models\Planta\PlantaRecepcion;
use App\Services\Planta\PlantaCambioDisponibilidadService;
use App\Support\Planta\BucketInventario;

/**
 * Andamiaje de las pruebas de cambio de disponibilidad.
 *
 * Se apoya en {@see RecepcionPlantaFixtures} porque el saldo RETENIDO no se
 * puede inventar: entra por una recepción confirmada con destino `retenido`, que
 * es exactamente como llega en la operación real. Fabricarlo escribiendo
 * directamente en `planta_existencias` produciría un escenario que el dominio no
 * puede generar, y las pruebas dejarían de decir nada sobre el sistema.
 */
trait CambioDisponibilidadFixtures
{
    use RecepcionPlantaFixtures;

    protected function servicioCambio(): PlantaCambioDisponibilidadService
    {
        return app(PlantaCambioDisponibilidadService::class);
    }

    /**
     * Recepción confirmada con destino RETENIDO: deja saldo listo para liberar o
     * rechazar. Devuelve la recepción; su bucket se obtiene con
     * {@see bucketRetenido()}.
     */
    protected function saldoRetenido(string $cantidadRecibida = '5'): PlantaRecepcion
    {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $admin = $this->admin();

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($insumo, [
                'cantidad_recibida' => $cantidadRecibida,
                'estado_destino' => EstadoDisponibilidad::Retenido->value,
            ]),
        ]), $admin);

        return $this->servicioRecepcion()->confirmar($recepcion, $admin);
    }

    /** Bucket retenido que dejó una recepción. */
    protected function bucketRetenido(PlantaRecepcion $recepcion): BucketInventario
    {
        return $this->bucketDe($recepcion);
    }

    /** El mismo bucket en otro estado de disponibilidad. */
    protected function bucketEn(PlantaRecepcion $recepcion, EstadoDisponibilidad $estado): BucketInventario
    {
        $origen = $this->bucketDe($recepcion);

        return new BucketInventario(
            insumoId: $origen->insumoId,
            loteId: $origen->loteId,
            ubicacionId: $origen->ubicacionId,
            estado: $estado,
            trasladoId: 0,
        );
    }

    /**
     * Payload de un cambio sobre el bucket de una recepción retenida.
     *
     * @return array<string, mixed>
     */
    protected function payloadCambio(
        PlantaRecepcion $recepcion,
        string $cantidad = '100',
        EstadoDisponibilidad $destino = EstadoDisponibilidad::Disponible,
        array $extra = [],
    ): array {
        $bucket = $this->bucketDe($recepcion);

        return array_merge([
            'planta_insumo_id' => $bucket->insumoId,
            'planta_lote_id' => $bucket->loteId,
            'planta_ubicacion_id' => $bucket->ubicacionId,
            'estado_destino' => $destino->value,
            'cantidad' => $cantidad,
            'fecha' => '2026-07-30',
            'motivo' => 'Revisión de calidad superada sin observaciones',
        ], $extra);
    }

    /** Borrador de cambio ya creado sobre saldo retenido real. */
    protected function borradorCambio(
        ?PlantaRecepcion $recepcion = null,
        string $cantidad = '100',
        EstadoDisponibilidad $destino = EstadoDisponibilidad::Disponible,
    ): PlantaCambioDisponibilidad {
        $recepcion ??= $this->saldoRetenido();

        return $this->servicioCambio()->crearBorrador(
            $this->payloadCambio($recepcion, $cantidad, $destino),
            $this->admin(),
        );
    }
}
