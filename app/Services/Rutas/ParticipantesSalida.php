<?php

namespace App\Services\Rutas;

use App\Enums\RolEnSalida;
use App\Models\PersonalRuta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaParticipante;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quiénes van en una salida y quién queda a cargo. Punto único de escritura.
 *
 * ─────────────────────── Por qué un servicio y no un `sync()` suelto ───────────────────────
 *
 * Un `sync()` de pivote no puede sostener las reglas que esto tiene: que haya como mucho un
 * responsable, que el responsable esté entre los participantes, y que quitar a alguien que
 * todavía tiene documentos en la mano no pase inadvertido. Repartidas por el controlador,
 * esas reglas se aplicarían en el alta y se olvidarían en la edición.
 *
 * ─────────────────────── Qué se comprueba y qué no ───────────────────────
 *
 * SÍ se exige: al menos una persona, que estén activas, y que el responsable —si se
 * designa— vaya en la salida.
 *
 * NO se exige que el responsable tenga declarada la función `responsable_salida`. Esa
 * función es una SUGERENCIA para ordenar el selector, no un requisito: la operación real
 * designa a quien está disponible, y bloquear el formulario porque a alguien no le marcaron
 * una casilla lleva a que se marque cualquier cosa con tal de seguir.
 *
 * Tampoco se impide quitar a alguien que tiene documentos: eso se AVISA. Quitarlo de la
 * lista no le saca el papel de la mano, y borrar el rastro de quién lo tiene sería peor que
 * dejar la inconsistencia visible.
 */
class ParticipantesSalida
{
    /**
     * Deja la salida exactamente con estas personas y este responsable.
     *
     * @param  array<int, int>  $personalIds
     * @return array{agregados: array<int, string>, quitados: array<int, string>, responsable: ?string, advertencias: array<int, string>}
     *
     * @throws ValidationException
     */
    public function sincronizar(SalidaRuta $salida, array $personalIds, ?int $responsableId): array
    {
        $personalIds = array_values(array_unique(array_filter($personalIds)));

        if ($personalIds === []) {
            throw ValidationException::withMessages([
                'personal' => 'Elegí al menos una persona para la salida.',
            ]);
        }

        $personas = PersonalRuta::whereIn('id', $personalIds)->get()->keyBy('id');

        $inactivas = $personas->filter(fn (PersonalRuta $p) => ! $p->activo);
        if ($inactivas->isNotEmpty()) {
            throw ValidationException::withMessages([
                'personal' => 'No se puede asignar a una salida a: '.$inactivas->pluck('nombre')->implode(', ')
                    .'. Están inactivos; activalos primero en Personal.',
            ]);
        }

        if ($responsableId !== null && ! in_array($responsableId, $personalIds, true)) {
            throw ValidationException::withMessages([
                'responsable_id' => 'El responsable tiene que ir en la salida. Agregalo a la lista o elegí a otro.',
            ]);
        }

        return DB::transaction(function () use ($salida, $personalIds, $responsableId, $personas) {
            $actuales = $salida->participantes()->with('personal:id,nombre')->get()->keyBy('rutas_personal_id');

            $quitar = $actuales->keys()->diff($personalIds);
            $advertencias = $this->advertirPorDocumentosEnMano($salida, $quitar->all());

            $salida->participantes()->whereIn('rutas_personal_id', $quitar->all())->delete();

            // El responsable se limpia ANTES de asignar el nuevo: el índice único de la
            // base no admite dos, y bajar al anterior a acompañante en el mismo paso que
            // sube al nuevo dispararía la violación según el orden de las filas.
            $salida->participantes()->where('rol', RolEnSalida::Responsable->value)->update([
                'rol' => RolEnSalida::Acompanante->value,
                'responsable_unico' => null,
            ]);

            $agregados = [];

            foreach ($personalIds as $id) {
                $esResponsable = $responsableId === $id;

                $participante = SalidaRutaParticipante::firstOrNew([
                    'salida_ruta_id' => $salida->id,
                    'rutas_personal_id' => $id,
                ]);

                $nuevo = ! $participante->exists;
                $participante->rol = $esResponsable ? RolEnSalida::Responsable : RolEnSalida::Acompanante;
                $participante->save();

                if ($nuevo) {
                    $agregados[] = $personas[$id]->nombre;
                }
            }

            return [
                'agregados' => $agregados,
                'quitados' => $actuales->only($quitar->all())->map(fn ($p) => $p->personal?->nombre ?? '—')->values()->all(),
                'responsable' => $responsableId !== null ? $personas[$responsableId]->nombre : null,
                'advertencias' => $advertencias,
            ];
        });
    }

    /**
     * Avisa —no impide— si alguien que se está quitando de la salida todavía figura con
     * documentos en la mano.
     *
     * No se bloquea a propósito: la persona pudo haberse bajado del viaje de verdad, y lo
     * que hay que hacer es transferir esos papeles, no impedir que se corrija la lista. El
     * aviso es lo que manda a alguien a hacerlo.
     *
     * @param  array<int, int>  $personalIds
     * @return array<int, string>
     */
    private function advertirPorDocumentosEnMano(SalidaRuta $salida, array $personalIds): array
    {
        if ($personalIds === []) {
            return [];
        }

        $documentos = $salida->documentos()->get();

        if ($documentos->isEmpty()) {
            return [];
        }

        $ultimos = app(Custodia::class)->ultimosVigentesDe($documentos->pluck('id')->all());

        $porPersona = collect($ultimos)
            ->filter(fn ($evento) => $evento->tipo->dejaEnPersonal() && in_array($evento->destino_personal_id, $personalIds, true))
            ->groupBy('destino_personal_id');

        return $porPersona
            ->map(fn (Collection $eventos, $personalId) => sprintf(
                '%s sigue figurando con %d documento(s) en la mano. Transferilos o registrá su recepción.',
                $eventos->first()->destino?->nombre ?? 'Esa persona',
                $eventos->count(),
            ))
            ->values()
            ->all();
    }
}
