<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Models\Planta\PlantaRecepcion;
use App\Models\Planta\PlantaTraslado;
use App\Models\Planta\PlantaUbicacion;
use App\Services\Planta\PlantaTrasladoService;
use App\Support\Planta\BucketInventario;

/**
 * Andamiaje de las pruebas de traslado.
 *
 * Se apoya en {@see RecepcionPlantaFixtures} porque el saldo DISPONIBLE en el
 * origen no se puede inventar: entra por una recepción confirmada, que es como
 * llega en la operación real. Fabricarlo escribiendo en `planta_existencias`
 * produciría un escenario que el dominio no genera.
 *
 * La ubicación de TRÁNSITO se crea aquí con la factory: el servicio exige que
 * exista exactamente una de sistema, activa y sin operación manual, y en la
 * suite cada prueba parte de una base limpia.
 */
trait TrasladoPlantaFixtures
{
    use RecepcionPlantaFixtures;

    protected function servicioTraslado(): PlantaTrasladoService
    {
        return app(PlantaTrasladoService::class);
    }

    /** LA ubicación de tránsito del sistema, tal como la exige el servicio. */
    protected function transitoDelSistema(): PlantaUbicacion
    {
        return PlantaUbicacion::factory()->transito()->create([
            'es_sistema' => true,
            'activo' => true,
            'permite_operacion_manual' => false,
        ]);
    }

    /**
     * Recepción confirmada con destino DISPONIBLE: deja saldo listo para
     * trasladar desde `$ubicacion`.
     */
    protected function saldoDisponibleEn(PlantaUbicacion $ubicacion, string $cantidadRecibida = '5'): PlantaRecepcion
    {
        $insumo = $this->insumoConLotes();
        $admin = $this->admin();

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($insumo, [
                'cantidad_recibida' => $cantidadRecibida,
                'estado_destino' => EstadoDisponibilidad::Disponible->value,
            ]),
        ]), $admin);

        return $this->servicioRecepcion()->confirmar($recepcion, $admin);
    }

    /**
     * Escenario completo: origen con 500 disponibles, destino vacío y tránsito.
     *
     * @return array{origen: PlantaUbicacion, destino: PlantaUbicacion, transito: PlantaUbicacion, recepcion: PlantaRecepcion, insumo_id: int, lote_id: int}
     */
    protected function escenarioTraslado(string $cantidadRecibida = '5'): array
    {
        $origen = $this->bodega();
        $destino = $this->bodega();
        $transito = $this->transitoDelSistema();

        $recepcion = $this->saldoDisponibleEn($origen, $cantidadRecibida);
        $detalle = $recepcion->refresh()->detalles->first();

        return [
            'origen' => $origen,
            'destino' => $destino,
            'transito' => $transito,
            'recepcion' => $recepcion,
            'insumo_id' => (int) $detalle->planta_insumo_id,
            'lote_id' => (int) $detalle->planta_lote_id,
        ];
    }

    /**
     * Payload de un traslado sobre el escenario.
     *
     * @param  array{origen: PlantaUbicacion, destino: PlantaUbicacion, insumo_id: int, lote_id: int}  $e
     * @return array<string, mixed>
     */
    protected function payloadTraslado(array $e, string $cantidad = '200', array $extra = []): array
    {
        return array_merge([
            'fecha' => '2026-07-30',
            'planta_ubicacion_origen_id' => $e['origen']->id,
            'planta_ubicacion_destino_id' => $e['destino']->id,
            'responsable_nombre' => 'Quien transporta',
            'detalles' => [[
                'planta_insumo_id' => $e['insumo_id'],
                'planta_lote_id' => $e['lote_id'],
                'cantidad' => $cantidad,
            ]],
        ], $extra);
    }

    /** Borrador de traslado ya creado sobre el escenario. */
    protected function borradorTraslado(array $e, string $cantidad = '200'): PlantaTraslado
    {
        return $this->servicioTraslado()->crearBorrador($this->payloadTraslado($e, $cantidad), $this->admin());
    }

    // --- Buckets del recorrido ---

    /** @param  array{origen: PlantaUbicacion, insumo_id: int, lote_id: int}  $e */
    protected function bucketOrigen(array $e): BucketInventario
    {
        return new BucketInventario($e['insumo_id'], $e['lote_id'], $e['origen']->id, EstadoDisponibilidad::Disponible, 0);
    }

    /** @param  array{destino: PlantaUbicacion, insumo_id: int, lote_id: int}  $e */
    protected function bucketDestino(array $e): BucketInventario
    {
        return new BucketInventario($e['insumo_id'], $e['lote_id'], $e['destino']->id, EstadoDisponibilidad::Disponible, 0);
    }

    /** Tránsito ATADO a un traslado concreto: es lo que separa dos viajes. */
    protected function bucketTransito(array $e, PlantaTraslado $traslado): BucketInventario
    {
        return new BucketInventario(
            $e['insumo_id'],
            $e['lote_id'],
            $e['transito']->id,
            EstadoDisponibilidad::Disponible,
            $traslado->id,
        );
    }
}
