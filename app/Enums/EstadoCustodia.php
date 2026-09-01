<?php

namespace App\Enums;

/**
 * Dónde está el CCF FÍSICO ahora mismo. Se DERIVA del último evento vigente
 * ({@see TipoEventoCustodia}); no se guarda en ninguna columna.
 *
 * ─────────────────── Qué NO es este estado, y por qué importa ───────────────────
 *
 * No es la entrega del pedido. Que el cliente haya recibido la mercadería —lo prueba el
 * albarán AC01, que llega solo al correo— no dice nada sobre dónde está el papel firmado.
 * Entre los dos hechos pasan días, a veces meses, y a veces el papel no vuelve nunca. Ese
 * hueco es justamente lo que este módulo existe para hacer visible, así que los dos
 * estados viven separados y se muestran en columnas distintas.
 *
 * Tampoco es el cobro. Un documento recibido está listo para cobrarse; no está cobrado.
 */
enum EstadoCustodia: string
{
    /** Todavía no salió: el papel está en la empresa y nadie se lo llevó. */
    case EnBodega = 'en_bodega';

    /** Lo tiene una persona concreta. Se sabe quién y desde cuándo. */
    case ConPersonal = 'con_personal';

    /** Volvió firmado y sellado. Es el hecho que habilita el cobro. */
    case Recibido = 'recibido';

    /** Se reportó un problema: perdido, dañado, quedó en la sala. */
    case Incidencia = 'incidencia';

    public function label(): string
    {
        return match ($this) {
            self::EnBodega => 'En bodega',
            self::ConPersonal => 'Con personal',
            self::Recibido => 'Recibido',
            self::Incidencia => 'Con incidencia',
        };
    }

    /** Qué significa, para el pie de la insignia. */
    public function detalle(): string
    {
        return match ($this) {
            self::EnBodega => 'El documento impreso no ha salido de la empresa.',
            self::ConPersonal => 'Lo lleva una persona; todavía no volvió a la oficina.',
            self::Recibido => 'Volvió firmado y sellado.',
            self::Incidencia => 'Hay un problema reportado con el documento físico.',
        };
    }

    /** ¿El papel está de vuelta en la empresa? Es lo único que habilita el cobro. */
    public function estaEnLaEmpresa(): bool
    {
        return $this === self::Recibido;
    }

    /** ¿Alguien tiene que hacer algo con esto? */
    public function esExcepcion(): bool
    {
        return $this === self::Incidencia;
    }

    /** Clases del badge. Sin variantes `dark:`: ver la nota en {@see FuncionPersonalRuta::clase()}. */
    public function clase(): string
    {
        return match ($this) {
            self::EnBodega => 'bg-gray-100 text-gray-600',
            self::ConPersonal => 'bg-amber-100 text-amber-800',
            self::Recibido => 'bg-green-100 text-green-700',
            self::Incidencia => 'bg-red-100 text-red-700',
        };
    }

    /** Símbolo para la insignia, en el mismo vocabulario que ya usa el módulo. */
    public function icono(): string
    {
        return match ($this) {
            self::EnBodega => '○',
            self::ConPersonal => '◐',
            self::Recibido => '✓',
            self::Incidencia => '⚠',
        };
    }

    /** @return array<string, string> */
    public static function opciones(): array
    {
        $opciones = [];
        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->label();
        }

        return $opciones;
    }
}
