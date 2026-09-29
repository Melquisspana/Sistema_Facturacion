<?php

namespace App\Enums\Cobros;

/**
 * Si un evento CUENTA para los acumulados del documento, o está esperando que una persona
 * decida.
 *
 * ═══════════════ El problema que resuelve: el mismo pago en dos archivos ═══════════════
 *
 * La llave de evidencia `(documento, tipo, huella del archivo, línea)` impide que la MISMA
 * línea del MISMO archivo entre dos veces. No impide lo otro: que Calleja mande el lunes un
 * archivo con la factura 119 y el miércoles otro archivo —huella distinta— que la vuelve a
 * traer. Como los acumulados se suman desde los eventos, eso cobraba la factura dos veces.
 *
 * Y no se puede resolver descartando todo pago repetido del mismo importe, porque esas dos
 * situaciones son indistinguibles desde el archivo:
 *
 *   · el cliente REPITIÓ en la segunda remesa un pago que ya había informado;
 *   · el cliente pagó a MEDIAS y completó en la segunda remesa (dos abonos reales).
 *
 * Un archivo que dice «CF 119: 77.74» no dice cuál de las dos cosas es. Elegir por el
 * sistema significa, la mitad de las veces, o cobrar dos veces lo mismo o perder un abono.
 * Por eso el segundo pago entra `EnRevision`: se registra entero, con su evidencia, NO
 * suma, y queda a la vista con el motivo para que alguien lo resuelva en diez segundos.
 *
 * Es el mismo criterio que el resto del módulo: cuando los datos no alcanzan para afirmar,
 * el sistema lo dice en vez de elegir.
 */
enum EstadoEventoCobro: string
{
    /** Cuenta para los acumulados del documento. */
    case Aplicado = 'aplicado';

    /** Registrado con su evidencia, pero NO suma: hay que decidir qué es. */
    case EnRevision = 'en_revision';

    /** Alguien decidió que no corresponde (una repetición, un error del cliente). */
    case Descartado = 'descartado';

    public function label(): string
    {
        return match ($this) {
            self::Aplicado => 'Aplicado',
            self::EnRevision => 'En revisión',
            self::Descartado => 'Descartado',
        };
    }

    public function detalle(): string
    {
        return match ($this) {
            self::Aplicado => 'Cuenta en el importe cobrado del documento.',
            self::EnRevision => 'Registrado, pero todavía no cuenta: hay que decidir si es una repetición o un abono nuevo.',
            self::Descartado => 'Se decidió que no corresponde. No cuenta y queda como constancia.',
        };
    }

    public function clase(): string
    {
        return match ($this) {
            self::Aplicado => 'bg-green-100 text-green-700',
            self::EnRevision => 'bg-amber-100 text-amber-800',
            self::Descartado => 'bg-gray-100 text-gray-500',
        };
    }

    /** ¿Este evento suma en los acumulados? */
    public function cuenta(): bool
    {
        return $this === self::Aplicado;
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function opciones(): array
    {
        return array_map(fn (self $e) => ['value' => $e->value, 'label' => $e->label()], self::cases());
    }
}
