<?php

namespace App\Exceptions\Planta;

use RuntimeException;

/**
 * La recepción no está en condiciones de hacer lo que se le pide.
 *
 * Cubre lo que ningún Form Request puede garantizar, porque depende del estado
 * de la base EN EL MOMENTO de la operación y no de la forma del formulario: el
 * estado del documento, que la ubicación siga admitiendo operación manual, que
 * los insumos sigan activos, o que quien confirma tenga permiso para el destino
 * que eligió. Se comprueba dentro de la transacción y con la fila bloqueada.
 */
class RecepcionInvalidaException extends RuntimeException
{
    public static function estadoNoPermite(string $estado, string $accion): self
    {
        return new self("Una recepción en estado «{$estado}» no admite {$accion}.");
    }

    public static function sinDetalles(int $numero): self
    {
        return new self("La recepción #{$numero} no tiene líneas: no hay nada que confirmar.");
    }

    public static function ubicacionInactiva(string $nombre): self
    {
        return new self("La ubicación «{$nombre}» está inactiva: no puede recibir mercancía.");
    }

    public static function ubicacionNoAdmiteOperacion(string $nombre): self
    {
        return new self(
            "La ubicación «{$nombre}» no admite operación manual: su saldo solo lo mueven los "
            .'traslados, así que no puede ser el destino de una recepción.'
        );
    }

    public static function proveedorInactivo(string $nombre): self
    {
        return new self("El proveedor «{$nombre}» está inactivo: no puede entregar mercancía.");
    }

    public static function insumoInactivo(string $nombre): self
    {
        return new self("El insumo «{$nombre}» está inactivo: no puede recibirse.");
    }

    public static function cantidadNoPositiva(string $campo, string $valor): self
    {
        return new self("«{$campo}» debe ser mayor que cero (recibido: {$valor}).");
    }

    public static function destinoNoPermitido(string $destino): self
    {
        return new self(
            "«{$destino}» no es un destino válido de recepción: la mercancía entra disponible o "
            .'retenida. El rechazo es una decisión de calidad posterior, no una forma de recibir.'
        );
    }

    public static function retenidoSinPermisoDeCalidad(): self
    {
        return new self(
            'Recibir mercancía como RETENIDA es una decisión de calidad y exige el permiso '
            .'planta.calidad.gestionar. Sin él, las líneas solo pueden entrar como disponibles.'
        );
    }

    public static function loteRequerido(string $insumo): self
    {
        return new self("El insumo «{$insumo}» controla lotes: la línea debe indicar su trazabilidad.");
    }

    public static function loteAjeno(int $loteId, string $insumo): self
    {
        return new self("El lote #{$loteId} no pertenece al insumo «{$insumo}».");
    }

    public static function loteNoReutilizable(int $loteId, string $motivo): self
    {
        return new self("El lote #{$loteId} no puede reutilizarse: {$motivo}.");
    }

    public static function motivoRequerido(): self
    {
        return new self('Reversar una recepción confirmada exige un motivo: sin él no queda constancia de por qué.');
    }
}
