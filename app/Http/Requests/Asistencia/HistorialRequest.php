<?php

namespace App\Http\Requests\Asistencia;

use App\Support\Asistencia\FiltroAsistencia;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Los filtros del historial, validados ANTES de convertirse en un
 * {@see FiltroAsistencia}.
 *
 * El DTO no valida: acepta lo que se le da y descarta lo ilegible, porque también
 * lo construyen definiciones de formato guardadas hace meses y un criterio viejo
 * no debería tumbar un documento. Acá, en cambio, hay una persona delante que
 * puede corregir, así que un rango al revés se le DICE en vez de devolverle una
 * lista vacía que parece un error del sistema.
 */
class HistorialRequest extends FormRequest
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
            // Inclusivo y en este orden: «del 5 al 5» es un día válido.
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'tipo' => ['nullable', 'in:entrada,salida'],
            'origen' => ['nullable', 'in:dispositivo,manual'],
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

    /** Los criterios ya validados, listos para la capa de consulta. */
    public function filtro(): FiltroAsistencia
    {
        return FiltroAsistencia::desdeArray($this->validated());
    }
}
