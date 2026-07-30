<?php

namespace App\Support\Planta;

use App\Enums\Planta\TipoDiferenciaReconciliacion;

/**
 * Una discrepancia concreta entre el libro mayor y la proyección de saldos.
 *
 * Guarda los DOS valores —lo que dice el mayor y lo que dice la proyección—
 * porque el informe tiene que poder explicarse solo: «este bucket debería tener
 * X y tiene Y» es accionable; «hay una diferencia» no.
 */
final readonly class DiferenciaReconciliacion
{
    /**
     * @param  string|null  $saldoMayor  Suma del mayor, o null si el bucket no tiene movimientos.
     * @param  string|null  $saldoProyectado  Saldo en existencias, o null si no hay fila.
     * @param  int  $movimientos  Cuántas filas del mayor sostienen el bucket.
     */
    public function __construct(
        public TipoDiferenciaReconciliacion $tipo,
        public BucketInventario $bucket,
        public ?string $saldoMayor,
        public ?string $saldoProyectado,
        public int $movimientos = 0,
        public string $detalle = '',
    ) {}

    public function esCorregible(): bool
    {
        return $this->tipo->esCorregible();
    }

    /** Línea legible para el informe del comando y para el log de actividad. */
    public function describir(): string
    {
        $texto = sprintf(
            '[%s] %s · mayor=%s · proyectado=%s · movimientos=%d',
            $this->tipo->value,
            $this->bucket->descripcion(),
            $this->saldoMayor ?? '—',
            $this->saldoProyectado ?? '—',
            $this->movimientos,
        );

        return $this->detalle === '' ? $texto : $texto.' · '.$this->detalle;
    }

    /** @return array<string, mixed> Forma serializable, para Activitylog. */
    public function aArreglo(): array
    {
        return [
            'tipo' => $this->tipo->value,
            'bucket' => $this->bucket->aColumnas(),
            'saldo_mayor' => $this->saldoMayor,
            'saldo_proyectado' => $this->saldoProyectado,
            'movimientos' => $this->movimientos,
            'detalle' => $this->detalle,
            'corregible' => $this->esCorregible(),
        ];
    }
}
