<?php

namespace App\Enums\Planta;

/**
 * Por qué se registra un ajuste de inventario.
 *
 * La carga inicial NO tiene tabla propia: comparte el 100 % de la mecánica del
 * ajuste positivo y se distingue por este tipo, que es indexado y filtrable.
 * Duplicar el flujo transaccional para ella solo añadiría superficie de error.
 *
 * El motivo es obligatorio en todos los tipos, sin excepción. En Fase 2, crear
 * y confirmar ajustes es admin-only: la auditoría acompaña a la autorización,
 * no la sustituye.
 */
enum TipoAjuste: string
{
    case CargaInicial = 'carga_inicial';
    case Positivo = 'positivo';
    case Negativo = 'negativo';
    case Merma = 'merma';
    case Dano = 'dano';
    case Vencimiento = 'vencimiento';
    case CorreccionConteo = 'correccion_conteo';

    public function label(): string
    {
        return match ($this) {
            self::CargaInicial => 'Carga inicial',
            self::Positivo => 'Ajuste positivo',
            self::Negativo => 'Ajuste negativo',
            self::Merma => 'Merma',
            self::Dano => 'Daño',
            self::Vencimiento => 'Vencimiento',
            self::CorreccionConteo => 'Corrección de conteo',
        };
    }

    /** ¿Es la carga inicial del inventario? Se audita y se filtra aparte. */
    public function esCargaInicial(): bool
    {
        return $this === self::CargaInicial;
    }

    /**
     * Tipo de movimiento que emite este ajuste en el libro mayor. La carga
     * inicial se distingue del resto para poder aislar en el historial lo que
     * es arranque del sistema de lo que es operación real.
     */
    public function tipoMovimiento(): TipoMovimientoPlanta
    {
        return $this->esCargaInicial()
            ? TipoMovimientoPlanta::CargaInicial
            : TipoMovimientoPlanta::Ajuste;
    }

    /** Color sugerido para badges en la interfaz (Tailwind). */
    public function color(): string
    {
        return match ($this) {
            self::CargaInicial => 'gray',
            self::Positivo => 'green',
            self::Negativo => 'amber',
            self::Merma, self::Dano, self::Vencimiento => 'red',
            self::CorreccionConteo => 'blue',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
