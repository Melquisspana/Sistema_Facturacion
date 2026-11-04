<?php

namespace App\Services\Planilla;

use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Un solo nombre por persona: el de su ficha de Personal (`planilla_empleados.nombre`).
 *
 * El nombre vive en cuatro tablas —Personal, Asistencia, Rutas y el usuario— porque cada
 * módulo nació por su cuenta, y cada pantalla lo lee de la suya. En vez de cambiar todas
 * las pantallas, las tres copias se mantienen IGUALES a la ficha: este es el único lugar
 * que las escribe. Cada vez que se enlaza algo que ya existía (un usuario, una ficha de
 * Asistencia o de Rutas de antes), la copia toma el nombre de la ficha.
 *
 * Asistencia guarda nombres y apellidos por separado. Por eso, a quien marca se le
 * corrige el nombre en su ficha con los dos campos; al copiar el nombre entero se parte
 * con {@see partir()}.
 *
 * Las quincenas ya confirmadas no cambian: su nombre es una foto del día que se pagó. Un
 * borrador lo toma de nuevo al guardarse.
 */
final class NombreUnico
{
    /** Después de corregir nombres y apellidos de quien marca: la ficha y las copias toman ese nombre. */
    public function desdeAsistencia(AsistenciaEmpleado $asistencia): void
    {
        $nombre = $asistencia->nombreCompleto();

        PlanillaEmpleado::where('asistencia_empleado_id', $asistencia->id)->get()
            ->each(fn (PlanillaEmpleado $empleado) => $this->copiar($empleado, $nombre));
    }

    /**
     * Corrige el nombre de quien no marca: la ficha y sus copias.
     *
     * @param  array{nombre?: mixed}  $datos
     */
    public function cambiar(User $usuario, PlanillaEmpleado $empleado, array $datos): void
    {
        abort_unless($usuario->activo && $usuario->can('planilla.gestionar'), 403);

        if ($empleado->asistencia_empleado_id !== null) {
            throw ValidationException::withMessages([
                'nombre' => 'Esta persona marca: su nombre se corrige con nombres y apellidos en «Editar datos».',
            ]);
        }

        $nombre = Validator::make($datos, [
            'nombre' => ['required', 'string', 'max:120'],
        ], [
            'nombre.required' => 'Escribí el nombre completo.',
        ])->validate()['nombre'];

        DB::transaction(fn () => $this->copiar($empleado, $nombre));
    }

    /** Al enlazar algo que ya existía (un usuario, Asistencia o Rutas): toma el nombre de la ficha. */
    public function repartir(PlanillaEmpleado $empleado): void
    {
        $this->copiar($empleado, $empleado->nombre);
    }

    /**
     * Las copias de esta persona que no dicen lo mismo que su ficha.
     *
     * @return array<string, array{actual: string, ficha: string}> 'asistencia', 'rutas' o 'usuario'
     */
    public function diferencias(PlanillaEmpleado $empleado): array
    {
        $ficha = self::normalizar($empleado->nombre);
        $copias = [
            'asistencia' => [$empleado->asistencia?->nombreCompleto(), $ficha],
            'rutas' => [$empleado->personalRuta?->nombre, mb_substr($ficha, 0, 120)],
            'usuario' => [$empleado->usuario?->name, $ficha],
        ];
        $distintas = [];
        foreach ($copias as $donde => [$actual, $esperado]) {
            if ($actual !== null && $actual !== $esperado) {
                $distintas[$donde] = ['actual' => $actual, 'ficha' => $esperado];
            }
        }

        return $distintas;
    }

    /**
     * Parte un nombre entero en nombres y apellidos, como los guarda Asistencia. Si la
     * ficha de Asistencia ya tenía un corte que sirve («Ana María» + el resto), lo
     * conserva; si no, toma dos nombres cuando hay cuatro palabras o más, y uno si hay menos.
     *
     * @return array{0: string, 1: string}
     */
    public static function partir(string $nombre, ?AsistenciaEmpleado $actual = null): array
    {
        $palabras = preg_split('/\s+/u', self::normalizar($nombre), -1, PREG_SPLIT_NO_EMPTY) ?: [''];
        $previos = $actual ? preg_split('/\s+/u', self::normalizar((string) $actual->nombres), -1, PREG_SPLIT_NO_EMPTY) : [];
        $corte = $previos !== [] && count($previos) < count($palabras) && array_slice($palabras, 0, count($previos)) === $previos
            ? count($previos)
            : (count($palabras) >= 4 ? 2 : 1);

        return [
            mb_substr(implode(' ', array_slice($palabras, 0, $corte)), 0, 80),
            mb_substr(implode(' ', array_slice($palabras, $corte)), 0, 80),
        ];
    }

    private static function normalizar(string $nombre): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $nombre));
    }

    private function copiar(PlanillaEmpleado $empleado, string $nombre): void
    {
        $nombre = self::normalizar($nombre);

        $empleado->update(['nombre' => $nombre]);
        $asistencia = $empleado->asistencia;
        if ($asistencia && $asistencia->nombreCompleto() !== $nombre) {
            [$nombres, $apellidos] = self::partir($nombre, $asistencia);
            $asistencia->update(['nombres' => $nombres, 'apellidos' => $apellidos]);
        }
        // Rutas guarda hasta 120 caracteres.
        $empleado->personalRuta?->update(['nombre' => mb_substr($nombre, 0, 120)]);
        $empleado->usuario?->update(['name' => $nombre]);
    }
}
