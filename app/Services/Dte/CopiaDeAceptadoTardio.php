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
 * ╔══════════════════════════════════════════════════════════════════════════════════╗
 * ║ LA BITÁCORA `dte_copia` ES ESTADO OPERATIVO: NO SE DEPURA.                       ║
 * ║ Si se borra «se creó como copia…», el freno desaparece y la copia se puede       ║
 * ║ emitir. Si se borra «Es otra venta…», vuelve a frenarse una que ya se resolvió.  ║
 * ║ Hoy nada depura `activity_log` (ni tarea programada ni `activitylog:clean`).     ║
 * ║ Quien agregue una limpieza tiene que excluir `log_name = 'dte_copia'`.           ║
 * ║ Ver docs/TRANSMISION_DTE.md, «Aceptación tardía».                                ║
 * ╚══════════════════════════════════════════════════════════════════════════════════╝
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

    /** Hasta cuántas copias encadenadas se sube buscando el original. De sobra, y sin lazos. */
    private const NIVELES = 10;

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
     * El original que frena a este documento, o null si no hay conflicto: es copia —o
     * copia de una copia— de un documento que al copiarlo figuraba RECHAZADO, que hoy está
     * aceptado por una aceptación tardía, y nadie dijo todavía que es otra venta.
     *
     * Sube por los padres: A rechazado → B lo corrige → C copia a B. Si después Hacienda
     * acepta A, C es la misma venta que B. La subida se corta donde deja de ser la misma
     * venta: en una copia que alguien ya resolvió como «otra venta», o en una copia sacada
     * de un documento que ya estaba aceptado (duplicar un aceptado es repetir una venta, no
     * corregirla).
     */
    public function originalEnConflicto(Dte $dte): ?Dte
    {
        // Solo el CCF tiene duplicador.
        if (! $dte->exists || $dte->tipo_dte !== TipoDte::CreditoFiscal) {
            return null;
        }

        $nodo = $dte;

        for ($nivel = 0; $nivel < self::NIVELES; $nivel++) {
            $anotaciones = $this->anotaciones($nodo);
            $copiado = $anotaciones->firstWhere('description', self::COPIADO);
            $estadoAlCopiar = $copiado?->properties['original_estado'] ?? null;

            if ($copiado === null || $anotaciones->contains('description', self::RESUELTO)
                || $estadoAlCopiar === EstadoDte::Aceptado->value) {
                return null;
            }

            $padre = Dte::whereKey((int) ($copiado->properties['original_id'] ?? 0))->first();

            if ($padre === null) {
                return null;
            }

            if ($estadoAlCopiar === EstadoDte::Rechazado->value && $this->aceptadoTarde($padre)) {
                return $padre;
            }

            $nodo = $padre;
        }

        return null;
    }

    /**
     * Por qué este documento no se puede DUPLICAR, o null si se puede. Mientras su conflicto
     * esté pendiente, una copia nueva sería la misma venta otra vez, y ya sin el aviso.
     */
    public function motivoParaNoDuplicar(Dte $dte): ?string
    {
        $original = $this->originalEnConflicto($dte);

        if ($original === null) {
            return null;
        }

        return 'Este documento es copia del '.$this->nombre($original).', que figuraba rechazado y Hacienda después aceptó. '
            .'Resolvé ese aviso en su ficha antes de duplicarlo: una copia nueva sería la misma venta otra vez.';
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
