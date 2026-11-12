<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Dte;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * La copia de un CCF rechazado que Hacienda después aceptó.
 *
 * Un CCF rechazado se «corrige» duplicándolo: la copia es la misma venta, vuelta a hacer.
 * Si más tarde llega el sello de Hacienda para el ORIGINAL (una aceptación tardía, ver
 * {@see DteTransmisionService}), el original vale y la copia pasa a ser un segundo
 * documento por esa venta. Emitirla la factura dos veces.
 *
 * Por eso la copia queda FRENADA —no se genera, no se firma, no se transmite, ni entra en
 * un lote de contingencia— hasta que alguien con permiso de emitir diga, con un motivo,
 * que es otra venta. Acá no se invalida nada ni se mueve ningún pedido: eso lo decide
 * una persona.
 *
 * DÓNDE SE GUARDA, sin columna nueva: en la bitácora (`activity_log`), con nombre propio.
 *   · al duplicar: «se creó como copia de…», con el original y el estado que tenía;
 *   · al resolver: «Es otra venta: se emite igual.», con quién y por qué.
 * Los documentos no tienen un campo donde quepa sin mezclar significados (`respuesta_mh`
 * es lo que contestó Hacienda). La consecuencia: el freno alcanza a las copias hechas
 * desde que existe esta anotación; de una copia anterior no queda rastro que leer.
 */
class CopiaDeAceptadoTardio
{
    public const BITACORA = 'dte_copia';

    public const COPIADO = 'Se creó como copia de otro documento.';

    public const RESUELTO = 'Es otra venta: se emite igual.';

    /** Deja escrito de qué documento es copia, y cómo estaba ese documento al copiarlo. */
    public function anotarCopia(Dte $copia, Dte $original, ?User $usuario = null): void
    {
        $anotacion = activity(self::BITACORA)->performedOn($copia)->withProperties([
            'original_id' => $original->id,
            'original_estado' => $original->estado->value,
        ]);

        if ($usuario !== null) {
            $anotacion->causedBy($usuario);
        }

        $anotacion->log(self::COPIADO);
    }

    /**
     * El original que frena a este documento, o null si no hay conflicto: es copia de un
     * documento que al copiarlo figuraba RECHAZADO, que hoy está aceptado por una
     * aceptación tardía, y nadie dijo todavía que es otra venta.
     *
     * Duplicar un documento ya aceptado para repetir una venta no es un conflicto.
     */
    public function originalEnConflicto(Dte $dte): ?Dte
    {
        // Solo el CCF tiene duplicador.
        if (! $dte->exists || $dte->tipo_dte !== TipoDte::CreditoFiscal) {
            return null;
        }

        $anotaciones = $this->anotaciones($dte);
        $copiado = $anotaciones->firstWhere('description', self::COPIADO);

        if ($copiado === null || $anotaciones->contains('description', self::RESUELTO)
            || ($copiado->properties['original_estado'] ?? null) !== EstadoDte::Rechazado->value) {
            return null;
        }

        $original = Dte::whereKey((int) ($copiado->properties['original_id'] ?? 0))->first();

        return $original !== null && $this->aceptadoTarde($original) ? $original : null;
    }

    /**
     * Por qué este documento no se puede generar, firmar ni transmitir, o null si se puede.
     * Es el freno del servidor: lo llaman los tres servicios de la emisión y el armado de
     * lotes de contingencia, así que vale por pantalla, por comando y por lote.
     */
    public function motivoParaNoEmitir(Dte $dte): ?string
    {
        $original = $this->originalEnConflicto($dte);

        if ($original === null) {
            return null;
        }

        return 'Este documento es copia del '.$this->nombre($original).', que figuraba rechazado y Hacienda después aceptó. '
            .'Para no facturar dos veces la misma venta, no se puede emitir. '
            .'Si es otra venta, confirmalo con un motivo en la ficha de este documento.';
    }

    /**
     * Las copias hechas de este original mientras figuraba rechazado, con o sin resolver.
     *
     * @return Collection<int, Dte>
     */
    public function copiasDe(Dte $original): Collection
    {
        if (! $this->aceptadoTarde($original)) {
            return collect();
        }

        $ids = Activity::query()
            ->where('log_name', self::BITACORA)
            ->where('description', self::COPIADO)
            ->where('subject_type', $original->getMorphClass())
            ->where('properties->original_id', $original->id)
            ->where('properties->original_estado', EstadoDte::Rechazado->value)
            ->pluck('subject_id');

        return $ids->isEmpty() ? collect() : Dte::whereKey($ids->all())->orderBy('id')->get();
    }

    /** Quien puede emitir dice que la copia es otra venta. Queda en la bitácora, con el motivo. */
    public function resolver(Dte $copia, Dte $original, User $usuario, string $motivo): void
    {
        activity(self::BITACORA)->performedOn($copia)->causedBy($usuario)->withProperties([
            'original_id' => $original->id,
            'motivo' => $motivo,
        ])->log(self::RESUELTO);
    }

    public function nombre(Dte $dte): string
    {
        return $dte->tipo_dte->label().' '.($dte->numero_control ?? $dte->numero_interno ?? '#'.$dte->id);
    }

    private function aceptadoTarde(Dte $dte): bool
    {
        return $dte->estado === EstadoDte::Aceptado && (bool) data_get($dte->respuesta_mh, 'aceptacion_tardia', false);
    }

    /** @return Collection<int, Activity> */
    private function anotaciones(Dte $dte): Collection
    {
        return Activity::query()
            ->where('log_name', self::BITACORA)
            ->where('subject_type', $dte->getMorphClass())
            ->where('subject_id', $dte->id)
            ->orderBy('id')
            ->get(['id', 'description', 'properties']);
    }
}
