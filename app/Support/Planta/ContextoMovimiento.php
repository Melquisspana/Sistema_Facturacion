<?php

namespace App\Support\Planta;

use App\Enums\Planta\TipoMovimientoPlanta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * El POR QUÉ de un movimiento: qué documento lo provoca, en qué transición, con
 * qué fecha operativa y bajo la responsabilidad de quién.
 *
 * Va separado de {@see BucketInventario} —el DÓNDE— y de la cantidad —el
 * CUÁNTO— porque tienen ciclos de vida distintos: una sola operación produce
 * varios efectos sobre buckets diferentes compartiendo exactamente este
 * contexto. Un cambio de disponibilidad, por ejemplo, resta de `retenido` y
 * suma a `disponible` con el mismo documento, la misma transición y el mismo
 * `grupo_uuid`; lo único que cambia entre los dos efectos es el bucket, el signo
 * y la {@see $secuencia}.
 *
 * `grupo_uuid` AGRUPA, no identifica. Que dos movimientos compartan grupo no
 * dice nada sobre si están duplicados: eso lo decide `efecto_uid`. Reutilizar el
 * grupo como candado de idempotencia haría imposible el caso normal de varios
 * efectos por operación.
 *
 * `fecha_efectiva` es la fecha OPERATIVA del documento —cuándo ocurrió el hecho
 * físico—, no `now()`. El `created_at` del mayor ya registra cuándo se escribió;
 * confundirlas hace que una recepción capturada con dos días de retraso aparezca
 * en el día equivocado del reporte.
 */
final readonly class ContextoMovimiento
{
    /**
     * @param  string  $documentoType  Clase del documento origen (polimórfico suelto).
     * @param  int  $secuencia  Distingue efectos del MISMO documento sobre el MISMO
     *                          bucket dentro de la misma transición. Es el «lado» de
     *                          una operación compensada, o el número de línea cuando
     *                          un documento repite bucket. Entra en `efecto_uid`.
     * @param  array<string, mixed>  $metadata  Contexto adicional del documento. El
     *                                          servicio le añade saldo_antes/saldo_despues.
     */
    public function __construct(
        public TipoMovimientoPlanta $tipo,
        public string $documentoType,
        public int $documentoId,
        public ?int $documentoDetalleId,
        public string $transicion,
        public string $fechaEfectiva,
        public string $grupoUuid,
        public int $secuencia = 0,
        public ?int $userId = null,
        public ?string $responsableNombre = null,
        public ?int $movimientoRevertidoId = null,
        public array $metadata = [],
    ) {}

    /**
     * Constructor de conveniencia: genera el `grupo_uuid` si no se pasa uno y
     * normaliza la fecha operativa a `Y-m-d`.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function para(
        TipoMovimientoPlanta $tipo,
        string $documentoType,
        int $documentoId,
        string $transicion,
        Carbon|string $fechaEfectiva,
        ?int $documentoDetalleId = null,
        ?string $grupoUuid = null,
        int $secuencia = 0,
        ?int $userId = null,
        ?string $responsableNombre = null,
        ?int $movimientoRevertidoId = null,
        array $metadata = [],
    ): self {
        return new self(
            tipo: $tipo,
            documentoType: $documentoType,
            documentoId: $documentoId,
            documentoDetalleId: $documentoDetalleId,
            transicion: $transicion,
            fechaEfectiva: $fechaEfectiva instanceof Carbon
                ? $fechaEfectiva->toDateString()
                : Carbon::parse($fechaEfectiva)->toDateString(),
            grupoUuid: $grupoUuid ?? (string) Str::uuid(),
            secuencia: $secuencia,
            userId: $userId,
            responsableNombre: $responsableNombre,
            movimientoRevertidoId: $movimientoRevertidoId,
            metadata: $metadata,
        );
    }

    /**
     * El otro lado de una operación compensada: mismo documento, misma
     * transición, mismo grupo, distinta secuencia. Necesario cuando los dos
     * efectos caen sobre el MISMO bucket, donde sin secuencia distinta
     * compartirían `efecto_uid` y el segundo sería rechazado como duplicado.
     */
    public function conSecuencia(int $secuencia): self
    {
        return new self(
            tipo: $this->tipo,
            documentoType: $this->documentoType,
            documentoId: $this->documentoId,
            documentoDetalleId: $this->documentoDetalleId,
            transicion: $this->transicion,
            fechaEfectiva: $this->fechaEfectiva,
            grupoUuid: $this->grupoUuid,
            secuencia: $secuencia,
            userId: $this->userId,
            responsableNombre: $this->responsableNombre,
            movimientoRevertidoId: $this->movimientoRevertidoId,
            metadata: $this->metadata,
        );
    }

    /** Descripción legible del origen, para mensajes de error. */
    public function descripcion(): string
    {
        $detalle = $this->documentoDetalleId !== null ? "#{$this->documentoDetalleId}" : 'sin detalle';

        return sprintf(
            '%s #%d (%s) · transición %s · tipo %s',
            class_basename($this->documentoType),
            $this->documentoId,
            $detalle,
            $this->transicion,
            $this->tipo->value,
        );
    }
}
