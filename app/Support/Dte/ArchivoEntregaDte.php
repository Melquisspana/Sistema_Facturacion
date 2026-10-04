<?php

namespace App\Support\Dte;

/** Resultado de armar la entrega, sin alterar la evidencia fiscal almacenada. */
readonly class ArchivoEntregaDte
{
    public const COMPLETO = 'completo';

    public const INCOMPLETO = 'incompleto';

    public const NO_FISCAL = 'no_fiscal';

    public function __construct(
        public string $estado,
        public ?string $contenido,
        public string $nombre,
        public array $faltantes = [],
        public array $recuperacion = [],
        public ?string $motivo = null,
    ) {}

    public function completo(): bool
    {
        return $this->estado === self::COMPLETO;
    }

    public function explicacion(): string
    {
        return match ($this->estado) {
            self::COMPLETO => 'Archivo DTE con firma y sello disponible.',
            self::NO_FISCAL => 'JSON fiscal no adjuntado: documento '.$this->motivo.'.',
            default => 'Entrega fiscal incompleta: falta '.implode('; ', $this->faltantes).'.',
        };
    }
}
