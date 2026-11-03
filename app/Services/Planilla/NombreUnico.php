<?php

namespace App\Services\Planilla;

use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Un solo nombre por persona.
 *
 * El nombre vive en tres tablas —planilla, Asistencia y Rutas— porque cada módulo nació
 * por su cuenta. Corregirlo en una sola dejaba a la misma persona con dos nombres. Acá se
 * corrige una vez y se copia a las fichas enlazadas.
 *
 * Asistencia guarda nombres y apellidos por separado; las otras dos, el nombre entero. Por
 * eso quien marca se corrige desde su ficha de Asistencia, y el resto desde planilla.
 *
 * Las quincenas ya confirmadas no cambian: su nombre es una foto del día que se pagó. Un
 * borrador lo toma de nuevo al guardarse.
 */
final class NombreUnico
{
    /** Después de editar la ficha de Asistencia: copia el nombre a planilla y Rutas. */
    public function desdeAsistencia(AsistenciaEmpleado $asistencia): void
    {
        $nombre = $asistencia->nombreCompleto();

        PlanillaEmpleado::where('asistencia_empleado_id', $asistencia->id)->get()
            ->each(fn (PlanillaEmpleado $empleado) => $this->copiar($empleado, $nombre));
    }

    /**
     * Corrige el nombre de quien no marca: planilla y su ficha de Rutas.
     *
     * @param  array{nombre?: mixed}  $datos
     */
    public function cambiar(User $usuario, PlanillaEmpleado $empleado, array $datos): void
    {
        abort_unless($usuario->activo && $usuario->can('planilla.gestionar'), 403);

        if ($empleado->asistencia_empleado_id !== null) {
            throw ValidationException::withMessages([
                'nombre' => 'Esta persona marca: su nombre se corrige con «Editar», arriba, y llega solo a Planilla y Rutas.',
            ]);
        }

        $nombre = Validator::make($datos, [
            'nombre' => ['required', 'string', 'max:120'],
        ], [
            'nombre.required' => 'Escribí el nombre completo.',
        ])->validate()['nombre'];

        DB::transaction(fn () => $this->copiar($empleado, $nombre));
    }

    private function copiar(PlanillaEmpleado $empleado, string $nombre): void
    {
        $nombre = trim((string) preg_replace('/\s+/u', ' ', $nombre));

        $empleado->update(['nombre' => $nombre]);
        // Rutas guarda hasta 120 caracteres; Asistencia admite nombres más largos.
        $empleado->personalRuta?->update(['nombre' => mb_substr($nombre, 0, 120)]);
    }
}
