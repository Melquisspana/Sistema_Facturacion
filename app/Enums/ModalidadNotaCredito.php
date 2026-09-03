<?php

namespace App\Enums;

use App\Models\ClientePerfilTipoNc;
use App\Services\Dte\DteBorradorService;

/**
 * MODALIDAD OPERATIVA de una nota de crédito: las cuatro cosas que la gente de sala
 * realmente hace. Es una capa de PRESENTACIÓN sobre {@see TipoNotaCredito}, no un
 * catálogo del MH y no un valor que se guarde en `dtes`.
 *
 * Existe porque el selector de «Nueva nota de crédito» ofrecía las siete modalidades
 * internas en una lista plana —devolución, faltante, avería, pronto pago, descuento
 * posterior, ajuste comercial y otro—, y quien emite la nota no distingue esas siete:
 * distingue cuatro situaciones. Devolución y faltante son EL MISMO tratamiento fiscal,
 * y «descuento posterior» y «ajuste comercial» nunca fueron otra cosa que el ajuste
 * excepcional.
 *
 * Lo que NO hace esta enum:
 *  - No cambia ningún cálculo. El descuento sigue saliendo del perfil del cliente
 *    ({@see ClientePerfilTipoNc}) y la retención de
 *    {@see DteBorradorService::decidirRetencionAutomatica()}.
 *  - No cambia lo que se guarda: la columna `dtes.tipo_nota_credito` sigue llevando el
 *    valor de TipoNotaCredito, así que los documentos ya emitidos se leen igual.
 *  - No sabe nada de códigos de albarán. Un cliente puede mapear cada modalidad a un
 *    código propio, pero eso lo declara SU perfil documental
 *    ({@see ClientePerfilTipoNc}) y solo existe para los clientes que lo
 *    hayan configurado. La enum no puede traer códigos de ejemplo adentro: los volvería
 *    la regla de todos, y para la mayoría de los clientes no hay ningún código.
 *
 * La INVALIDACIÓN oficial de un DTE no está acá a propósito: no es una modalidad de
 * nota de crédito sino un proceso aparte (ver `x-dte.invalidacion-oficial`).
 */
enum ModalidadNotaCredito: string
{
    /** Devolución de producto o faltante de entrega: mismo tratamiento fiscal (AC04). */
    case DevolucionFaltante = 'devolucion_faltante';

    /** Producto averiado (AC02). Pide además el origen operativo. */
    case Averia = 'averia';

    /** Descuento por pronto pago; alimenta el flujo PPQ. */
    case ProntoPago = 'pronto_pago';

    /** Ajuste excepcional que no encaja en las tres anteriores. */
    case OtroAjuste = 'otro_ajuste';

    public function label(): string
    {
        return match ($this) {
            self::DevolucionFaltante => 'Devolución o faltante de entrega',
            self::Averia => 'Avería',
            self::ProntoPago => 'Pronto pago',
            self::OtroAjuste => 'Otro ajuste',
        };
    }

    /** Una línea de ayuda para la tarjeta de la modalidad en el formulario. */
    public function descripcion(): string
    {
        return match ($this) {
            self::DevolucionFaltante => 'El cliente devolvió producto o no recibió todo lo facturado. Se acreditan líneas del CCF relacionado.',
            self::Averia => 'Producto dañado. Se acredita cualquier producto del catálogo, con su precio para este cliente.',
            self::ProntoPago => 'Descuento por pagar antes del plazo. Se captura como concepto por monto.',
            self::OtroAjuste => 'Ajuste excepcional que no es devolución, faltante, avería ni pronto pago.',
        };
    }

    /**
     * Modalidades internas que esta modalidad operativa agrupa. La primera es la que se
     * usa al crear si no se elige un submotivo.
     *
     * @return array<int, TipoNotaCredito>
     */
    public function tiposInternos(): array
    {
        return match ($this) {
            self::DevolucionFaltante => [TipoNotaCredito::DevolucionProducto, TipoNotaCredito::FaltanteEntrega],
            self::Averia => [TipoNotaCredito::Averia],
            self::ProntoPago => [TipoNotaCredito::ProntoPago],
            // `descuento_posterior` y `ajuste_comercial` siguen siendo válidos para los
            // documentos que ya los tienen, pero el formulario ya no los ofrece: quien
            // necesita uno de esos elige «Otro ajuste» y lo explica en el motivo.
            self::OtroAjuste => [TipoNotaCredito::Otro, TipoNotaCredito::DescuentoPosterior, TipoNotaCredito::AjusteComercial],
        };
    }

    /** Modalidad interna con la que se crea la NC cuando no hay submotivo elegido. */
    public function tipoPorDefecto(): TipoNotaCredito
    {
        return $this->tiposInternos()[0];
    }

    /**
     * Submotivos que el formulario SÍ ofrece dentro de la modalidad. Solo devolución y
     * faltante: son dos hechos distintos con idéntico tratamiento fiscal, y perder la
     * distinción borraría información que el listado y el archivo del cliente ya usan.
     *
     * @return array<string, string> [valor de TipoNotaCredito => label]
     */
    public function submotivos(): array
    {
        if ($this !== self::DevolucionFaltante) {
            return [];
        }

        return [
            TipoNotaCredito::DevolucionProducto->value => TipoNotaCredito::DevolucionProducto->label(),
            TipoNotaCredito::FaltanteEntrega->value => TipoNotaCredito::FaltanteEntrega->label(),
        ];
    }

    /** ¿Este tipo interno pertenece a esta modalidad? */
    public function admiteTipo(TipoNotaCredito $tipo): bool
    {
        return in_array($tipo, $this->tiposInternos(), true);
    }

    /**
     * Modalidad operativa a la que pertenece un tipo interno. Es la traducción que
     * necesita cualquier pantalla que muestre una NC ya creada (incluidas las viejas
     * con `descuento_posterior` o `ajuste_comercial`).
     */
    public static function desdeTipo(?TipoNotaCredito $tipo): ?self
    {
        if ($tipo === null) {
            return null;
        }

        foreach (self::cases() as $modalidad) {
            if ($modalidad->admiteTipo($tipo)) {
                return $modalidad;
            }
        }

        return null;
    }

    /** La avería exige declarar dónde se detectó ({@see OrigenAveria}). */
    public function requiereOrigenAveria(): bool
    {
        return $this === self::Averia;
    }

    /**
     * ¿Admite emitirse a una sala distinta a la del CCF relacionado? Delega en el tipo
     * interno para no duplicar la regla de
     * {@see DteBorradorService::resolverSalaNotaCredito()}.
     */
    public function permiteOtraSalaReceptora(): bool
    {
        return $this->tipoPorDefecto()->esPorMonto();
    }

    /** @return array<string, string> [valor => label] para selects. */
    public static function opciones(): array
    {
        $opciones = [];
        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->label();
        }

        return $opciones;
    }
}
