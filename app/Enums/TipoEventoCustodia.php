<?php

namespace App\Enums;

/**
 * Qué le pasó al CCF FÍSICO. Cada caso es un hecho que alguien presenció, no un estado.
 *
 * ─────────────────────── Por qué eventos y no un «responsable actual» ───────────────────────
 *
 * Una columna «quién lo tiene» contesta el presente y borra el pasado: al sobrescribirla se
 * pierde que el papel pasó por tres manos antes de volver, y con él la única forma de
 * preguntarle a alguien concreto por un documento que no aparece. El estado actual se
 * DERIVA del último evento vigente; el historial es lo que se guarda.
 *
 * ─────────────────────────── Los cinco hechos ───────────────────────────
 *
 * Son pocos a propósito. Cada uno responde algo que de verdad ocurre en la operación, y
 * ninguno se inventa para completar una simetría.
 */
enum TipoEventoCustodia: string
{
    /** Bodega le da los CCF impresos a quien sale. Es el primer evento de la cadena. */
    case EntregaAPersonal = 'entrega_a_personal';

    /**
     * El papel cambia de manos SIN volver a la empresa: un vendedor se lo pasa al
     * responsable de la salida para que él lo entregue todo junto.
     */
    case Transferencia = 'transferencia';

    /**
     * El documento firmado y sellado volvió a la oficina. Es el hecho que habilita el
     * cobro, y el único que puede registrar quien recibe —nunca quien lo llevaba—.
     */
    case RecepcionOficina = 'recepcion_oficina';

    /** Se perdió, se mojó, quedó en la sala. No dice dónde está: dice que hay un problema. */
    case Incidencia = 'incidencia';

    /**
     * Deja sin efecto un evento anterior que se registró mal. NO lo borra: lo compensa,
     * apuntando al evento anulado y exigiendo motivo. Una bitácora que se puede editar no
     * prueba nada.
     */
    case Anulacion = 'anulacion';

    public function label(): string
    {
        return match ($this) {
            self::EntregaAPersonal => 'Entrega a personal',
            self::Transferencia => 'Transferencia',
            self::RecepcionOficina => 'Recepción en oficina',
            self::Incidencia => 'Incidencia',
            self::Anulacion => 'Anulación',
        };
    }

    /** Frase para la línea de tiempo, en pasado y con sujeto implícito. */
    public function descripcion(): string
    {
        return match ($this) {
            self::EntregaAPersonal => 'bodega entregó el documento',
            self::Transferencia => 'el documento cambió de manos',
            self::RecepcionOficina => 'el documento firmado volvió a la oficina',
            self::Incidencia => 'se reportó una incidencia con el documento',
            self::Anulacion => 'se anuló un registro anterior',
        };
    }

    /** ¿Este evento deja el papel en manos de una persona? */
    public function dejaEnPersonal(): bool
    {
        return in_array($this, [self::EntregaAPersonal, self::Transferencia], true);
    }

    /** ¿Exige indicar a quién queda el documento? */
    public function requiereDestino(): bool
    {
        return $this->dejaEnPersonal();
    }

    /** ¿Exige motivo escrito? Solo lo que contradice algo ya registrado. */
    public function requiereMotivo(): bool
    {
        return $this === self::Anulacion;
    }

    /** Clases del badge. Sin variantes `dark:`: ver la nota en {@see FuncionPersonalRuta::clase()}. */
    public function clase(): string
    {
        return match ($this) {
            self::EntregaAPersonal => 'bg-sky-100 text-sky-700',
            self::Transferencia => 'bg-indigo-100 text-indigo-700',
            self::RecepcionOficina => 'bg-green-100 text-green-700',
            self::Incidencia => 'bg-red-100 text-red-700',
            self::Anulacion => 'bg-gray-100 text-gray-600',
        };
    }

    /** @return array<int, string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
