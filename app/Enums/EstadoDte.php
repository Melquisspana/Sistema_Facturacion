<?php

namespace App\Enums;

/**
 * Estados del ciclo de vida de un DTE.
 *
 * Flujo: borrador -> generado -> firmado -> enviado -> aceptado | rechazado
 * y desde aceptado -> invalidado (vía evento aceptado por Hacienda).
 *
 * La máquina de transiciones (validaciones de qué estado puede ir a cuál) se
 * implementará como servicio en la fase del motor DTE. Aquí solo se define el
 * vocabulario de estados.
 */
enum EstadoDte: string
{
    case Borrador = 'borrador';
    case Generado = 'generado';
    case Firmado = 'firmado';
    case Enviado = 'enviado';
    case Aceptado = 'aceptado';
    case Rechazado = 'rechazado';
    case Invalidado = 'invalidado';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Generado => 'Generado',
            self::Firmado => 'Firmado',
            self::Enviado => 'Enviado',
            self::Aceptado => 'Aceptado',
            self::Rechazado => 'Rechazado',
            self::Invalidado => 'Invalidado',
        };
    }

    /** Único estado en el que el documento puede editarse o eliminarse. */
    public function esEditable(): bool
    {
        return $this === self::Borrador;
    }

    /**
     * Estados a los que se puede transicionar desde el actual.
     *
     * @return array<int, self>
     */
    public function siguientesEstados(): array
    {
        return match ($this) {
            self::Borrador => [self::Generado],
            // Invalidado: anulación interna/preliminar de un documento generado.
            self::Generado => [self::Firmado, self::Rechazado, self::Invalidado],
            self::Firmado => [self::Enviado],
            self::Enviado => [self::Aceptado, self::Rechazado],
            self::Aceptado => [self::Invalidado],
            self::Rechazado => [],
            self::Invalidado => [],
        };
    }

    /** ¿Es válida la transición de este estado al destino? */
    public function puedeTransicionarA(self $destino): bool
    {
        return in_array($destino, $this->siguientesEstados(), true);
    }

    /** Estados a partir de los cuales el documento es inmutable (emitido). */
    public function esEmitido(): bool
    {
        return in_array($this, [
            self::Firmado,
            self::Enviado,
            self::Aceptado,
            self::Rechazado,
            self::Invalidado,
        ], true);
    }

    /** Color sugerido para badges en la interfaz (Tailwind). */
    public function color(): string
    {
        return match ($this) {
            self::Borrador => 'gray',
            self::Generado => 'blue',
            self::Firmado => 'indigo',
            self::Enviado => 'amber',
            self::Aceptado => 'green',
            self::Rechazado => 'red',
            self::Invalidado => 'rose',
        };
    }
}
