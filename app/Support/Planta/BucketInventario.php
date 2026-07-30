<?php

namespace App\Support\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Exceptions\Planta\BucketInvalidoException;

/**
 * Las CINCO dimensiones que identifican un saldo de inventario, juntas y en un
 * solo objeto.
 *
 * Existe para que las cinco no puedan separarse por accidente. Mientras el
 * bucket sea «cinco parámetros sueltos», tarde o temprano alguien escribe una
 * consulta que agrupa por cuatro —normalmente olvidando `estado` o
 * `planta_traslado_id`— y suma saldos que no son el mismo saldo. Con un objeto
 * de valor, {@see COLUMNAS} y {@see aColumnas()} son el único orden posible y
 * lo comparten la escritura, el único de la tabla y la reconciliación.
 *
 * Es inmutable: un bucket no «cambia de estado». Mover saldo de `retenido` a
 * `disponible` es mover cantidad de UN bucket a OTRO con dos movimientos
 * compensados, nunca un UPDATE de la columna.
 *
 * La validación que hace aquí es solo la que puede hacerse SIN tocar la base:
 * que el traslado no sea negativo. La regla condicional —0 fuera de tránsito,
 * >0 solo en TRANSITO— necesita conocer el TIPO de la ubicación y la comprueba
 * PlantaInventarioService, que sí la carga.
 */
final readonly class BucketInventario
{
    /**
     * Orden canónico de las cinco dimensiones. Es el mismo en
     * `planta_movimientos`, en el único de `planta_existencias`, en el índice
     * del mayor y en el GROUP BY de la reconciliación. No se reordena.
     */
    public const COLUMNAS = [
        'planta_insumo_id',
        'planta_lote_id',
        'planta_ubicacion_id',
        'estado',
        'planta_traslado_id',
    ];

    public function __construct(
        public int $insumoId,
        public int $loteId,
        public int $ubicacionId,
        public EstadoDisponibilidad $estado,
        public int $trasladoId = 0,
    ) {
        if ($trasladoId < 0) {
            throw BucketInvalidoException::trasladoNegativo($trasladoId);
        }
    }

    /**
     * Reconstruye el bucket desde una fila del mayor o de las existencias.
     *
     * @param  object|array<string, mixed>  $fila
     */
    public static function desdeFila(object|array $fila): self
    {
        $datos = (array) $fila;

        return new self(
            insumoId: (int) $datos['planta_insumo_id'],
            loteId: (int) $datos['planta_lote_id'],
            ubicacionId: (int) $datos['planta_ubicacion_id'],
            estado: $datos['estado'] instanceof EstadoDisponibilidad
                ? $datos['estado']
                : EstadoDisponibilidad::from((string) $datos['estado']),
            trasladoId: (int) ($datos['planta_traslado_id'] ?? 0),
        );
    }

    /**
     * Las cinco dimensiones como columnas, listas para un where, un insert o un
     * group by. Siempre en el orden de {@see COLUMNAS}.
     *
     * @return array<string, int|string>
     */
    public function aColumnas(): array
    {
        return [
            'planta_insumo_id' => $this->insumoId,
            'planta_lote_id' => $this->loteId,
            'planta_ubicacion_id' => $this->ubicacionId,
            'estado' => $this->estado->value,
            'planta_traslado_id' => $this->trasladoId,
        ];
    }

    /**
     * Representación textual estable del bucket, para el hash de `efecto_uid` y
     * para comparar buckets como claves de arreglo en la reconciliación.
     *
     * El separador es `|`, que no puede aparecer en ninguna de las cinco: tres
     * son enteros y `estado` es un enum cerrado.
     */
    public function claveCanonica(): string
    {
        return implode('|', array_values($this->aColumnas()));
    }

    /** ¿Es un bucket de tránsito? Se deriva del traslado, no de la ubicación. */
    public function enTransito(): bool
    {
        return $this->trasladoId > 0;
    }

    /** Descripción legible para mensajes de error. */
    public function descripcion(): string
    {
        $texto = sprintf(
            'insumo #%d / lote #%d / ubicación #%d / %s',
            $this->insumoId,
            $this->loteId,
            $this->ubicacionId,
            $this->estado->value,
        );

        return $this->enTransito()
            ? $texto." / traslado #{$this->trasladoId}"
            : $texto;
    }

    public function esIgualA(self $otro): bool
    {
        return $this->claveCanonica() === $otro->claveCanonica();
    }
}
