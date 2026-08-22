<?php

namespace App\Http\Requests\Asistencia;

use App\Enums\Asistencia\EstadoJornada;
use App\Support\Asistencia\FiltroAsistencia;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Los filtros del reporte de jornadas.
 *
 * ─────────────── Por qué el rango tiene valor por defecto ───────────────
 *
 * Una jornada no es una fila de la base: es el resultado de agrupar y emparejar
 * marcaciones, así que la paginación ocurre en memoria y NO hay `LIMIT` que
 * proteja de un «traeme todo». Sin un rango por defecto, la primera visita a la
 * pantalla intentaría armar las jornadas de toda la historia.
 *
 * El mes en curso es la respuesta más útil y la más barata: es lo que alguien
 * quiere ver al entrar, y acota la consulta sin esconder nada —el rango queda
 * escrito en el formulario y se puede ampliar—.
 */
class JornadasRequest extends FormRequest
{
    /** La autorización la resuelve el middleware `permission:asistencia.ver`. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'empleado_id' => ['nullable', 'integer', 'exists:asistencia_empleados,id'],
            'dispositivo_id' => ['nullable', 'integer', 'exists:asistencia_dispositivos,id'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'estado' => ['nullable', 'in:completa,abierta,irregular'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'empleado_id' => 'empleado',
            'dispositivo_id' => 'lector',
            'desde' => 'fecha inicial',
            'hasta' => 'fecha final',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ];
    }

    /** Los criterios, con el mes en curso si no se pidió otro rango. */
    public function filtro(): FiltroAsistencia
    {
        $datos = $this->validated();

        [$desde, $hasta] = $this->rango($datos);

        return FiltroAsistencia::desdeArray($datos)->conRango($desde, $hasta);
    }

    /** El estado es DERIVADO: no es columna y por eso viaja aparte del filtro. */
    public function estado(): ?EstadoJornada
    {
        return EstadoJornada::tryFrom((string) $this->validated('estado', ''));
    }

    /**
     * El rango efectivo. Si falta alguno de los dos extremos se completa con el
     * mes en curso; nunca queda abierto por los dos lados.
     *
     * @param  array<string, mixed>  $datos
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function rango(array $datos): array
    {
        $hoy = CarbonImmutable::now(config('asistencia.zona_horaria'));

        $desde = ($datos['desde'] ?? null) ? CarbonImmutable::parse($datos['desde']) : null;
        $hasta = ($datos['hasta'] ?? null) ? CarbonImmutable::parse($datos['hasta']) : null;

        return match (true) {
            $desde !== null && $hasta !== null => [$desde, $hasta],
            // Con un solo extremo se respeta el que vino y se completa el otro con
            // el mes de ESE extremo, no con el de hoy: quien escribe «desde el 1 de
            // enero» está mirando enero.
            $desde !== null => [$desde, $desde->endOfMonth()],
            $hasta !== null => [$hasta->startOfMonth(), $hasta],
            default => [$hoy->startOfMonth(), $hoy->endOfMonth()],
        };
    }
}
