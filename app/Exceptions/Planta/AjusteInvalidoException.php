<?php

namespace App\Exceptions\Planta;

use RuntimeException;

/**
 * El ajuste no está en condiciones de hacer lo que se le pide.
 *
 * Cubre lo que ningún Form Request puede garantizar porque depende del estado de
 * la base EN EL MOMENTO de la operación: el estado del documento, que la
 * ubicación siga siendo operable, que el lote siga siendo del insumo, que haya
 * saldo suficiente, y las reglas propias de cada tipo.
 */
class AjusteInvalidoException extends RuntimeException
{
    public static function estadoNoPermite(string $estado, string $accion): self
    {
        return new self("Un ajuste en estado «{$estado}» no admite {$accion}.");
    }

    public static function sinDetalles(int $numero): self
    {
        return new self("El ajuste #{$numero} no tiene líneas: no hay nada que ajustar.");
    }

    public static function motivoRequerido(): self
    {
        return new self(
            'Un ajuste exige motivo, sin excepción: es el único documento del módulo que altera la '
            .'cantidad física sin nada externo que lo respalde. Sin motivo, dentro de un mes nadie '
            .'sabrá por qué el saldo cambió.'
        );
    }

    public static function cantidadNoPositiva(string $cantidad): self
    {
        return new self("La cantidad debe ser mayor que cero (recibida: {$cantidad}).");
    }

    public static function conteoNegativo(string $cantidad): self
    {
        return new self("La cantidad contada no puede ser negativa (recibida: {$cantidad}).");
    }

    public static function ubicacionInactiva(string $nombre): self
    {
        return new self("La ubicación «{$nombre}» está inactiva: no se puede ajustar su saldo.");
    }

    public static function ubicacionNoOperable(string $nombre): self
    {
        return new self(
            "La ubicación «{$nombre}» no admite operación manual. El saldo en TRÁNSITO no se ajusta: "
            .'pertenece a un traslado concreto y solo se mueve recibiéndolo o reversándolo.'
        );
    }

    public static function insumoInactivo(string $nombre): self
    {
        return new self("El insumo «{$nombre}» está inactivo: no puede ajustarse.");
    }

    public static function loteAjeno(int $loteId, string $insumo): self
    {
        return new self("El lote #{$loteId} no pertenece al insumo «{$insumo}».");
    }

    public static function loteGenericoNoSeleccionable(string $insumo): self
    {
        return new self(
            "El insumo «{$insumo}» controla lotes: exige un lote real trazable, no el genérico del "
            .'sistema.'
        );
    }

    public static function saldoInsuficiente(string $descripcionBucket, string $solicitado, string $disponible): self
    {
        return new self(sprintf(
            'No hay saldo suficiente para restar: se piden %s de %s y solo hay %s. El inventario no '
            .'admite saldo negativo.',
            $solicitado,
            $descripcionBucket,
            $disponible,
        ));
    }

    // --- Reglas propias de cada tipo ---

    /**
     * La regla que da sentido a la carga inicial: es el ARRANQUE de un bucket, no
     * una corrección. Basta un movimiento histórico —aunque el saldo hoy sea
     * cero— para que ya no sea un arranque.
     */
    public static function cargaInicialSobreBucketConHistorial(string $descripcionBucket, int $movimientos): self
    {
        return new self(sprintf(
            'No se puede hacer carga inicial en %s: ese bucket ya tiene %d movimiento(s) en el libro '
            .'mayor. La carga inicial es el arranque del inventario, no una corrección; que el saldo '
            .'esté hoy en cero no lo convierte en un bucket nuevo. Usa un ajuste positivo.',
            $descripcionBucket,
            $movimientos,
        ));
    }

    public static function vencimientoSinFecha(string $codigoLote): self
    {
        return new self(
            "El lote «{$codigoLote}» no tiene fecha de vencimiento registrada, así que no puede darse "
            .'de baja por vencido. Si de verdad venció, corrige la fecha del lote o usa otro tipo de '
            .'ajuste con su motivo.'
        );
    }

    public static function vencimientoAnticipado(string $codigoLote, string $vence, string $fechaAjuste): self
    {
        return new self(sprintf(
            'El lote «%s» vence el %s, que es posterior a la fecha del ajuste (%s): todavía no está '
            .'vencido. Dar de baja por vencimiento algo que aún no venció haría que el histórico de '
            .'mermas por caducidad dejara de significar nada. Si hay que retirarlo igualmente, usa '
            .'daño o ajuste negativo con su motivo.',
            $codigoLote,
            $vence,
            $fechaAjuste,
        ));
    }

    public static function correccionSinConteo(): self
    {
        return new self('Una corrección de conteo exige la cantidad contada en cada línea.');
    }

    /**
     * Un documento entero sin efecto no es un ajuste: es ruido en el histórico.
     * Que el conteo cuadre es una buena noticia, pero no un hecho que registrar
     * en el libro mayor.
     */
    public static function correccionSinDiferencias(int $numero): self
    {
        return new self(
            "El ajuste #{$numero} no tiene ninguna diferencia: todo lo contado coincide con el "
            .'sistema. Un documento que no cambia nada no se confirma; si querías dejar constancia '
            .'del conteo, anúlalo y anota el resultado donde corresponda.'
        );
    }
}
