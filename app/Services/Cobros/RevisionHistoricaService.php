<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\TipoEventoCobro;
use App\Models\Cobros\CobroDocumento;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevisionHistoricaService
{
    public function resolver(CobroDocumento $documento, User $usuario, string $decision, string $nota, string $evidencia): void
    {
        if (! in_array($decision, ['habilitar_presentacion', 'mantener_bloqueo'], true) || trim($nota) === '' || trim($evidencia) === '') {
            throw ValidationException::withMessages(['nota' => 'Indique la conclusión y el documento, correo o comprobación que la respalda.']);
        }

        DB::transaction(function () use ($documento, $usuario, $decision, $nota, $evidencia) {
            $actual = CobroDocumento::lockForUpdate()->findOrFail($documento->id);
            if (! $actual->revisar_historico) {
                throw ValidationException::withMessages(['nota' => 'Esta revisión ya fue resuelta. Vuelva a cargar la ficha.']);
            }
            if ($decision === 'habilitar_presentacion' && ($actual->pago_estado !== EstadoPagoCobro::Pendiente || $actual->tienePagosEnRevision() || $actual->cobro_solicitud_id !== null)) {
                throw ValidationException::withMessages(['nota' => 'Hay pagos o una solicitud registrados. No se puede declarar que nunca se presentó ni se cobró.']);
            }
            $actual->eventos()->create([
                'tipo' => TipoEventoCobro::Nota->value,
                'origen' => 'manual',
                'fecha' => today()->toDateString(),
                'detalle' => 'Revisión histórica: '.$nota.' Evidencia: '.$evidencia,
                'user_id' => $usuario->id,
                'datos' => ['revision_historica' => true, 'decision' => $decision, 'motivo_anterior' => $actual->revisar_historico_motivo, 'evidencia' => $evidencia],
            ]);
            $actual->forceFill([
                'revisar_historico' => $decision !== 'habilitar_presentacion',
                'observaciones' => trim(($actual->observaciones ? $actual->observaciones."\n" : '')
                    .'Revisión histórica ('.now()->format('d/m/Y').', '.$usuario->name.'): '.$nota.' Evidencia: '.$evidencia),
            ])->save();
        });
    }
}
