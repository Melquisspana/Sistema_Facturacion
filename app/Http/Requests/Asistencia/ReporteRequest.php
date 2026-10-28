<?php

namespace App\Http\Requests\Asistencia;

use App\Services\Asistencia\HoraOficial;
use App\Support\Asistencia\FiltroAsistencia;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReporteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            // Solo se compara con `desde` si vino: sin él, `after_or_equal` toma la
            // palabra «desde» como fecha y rechaza un `hasta` válido.
            'hasta' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('desde'), 'after_or_equal:desde')],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            [$desde, $hasta] = $this->rango();
            if ($desde->diffInDays($hasta) > 365) {
                $validator->errors()->add('hasta', 'El rango no puede superar 366 días, incluyendo ambas fechas.');
            }
        }];
    }

    public function filtro(): FiltroAsistencia
    {
        return FiltroAsistencia::vacio()->conRango(...$this->rango());
    }

    /** Con un solo extremo se completa el mes de ese extremo. */
    private function rango(): array
    {
        $hora = app(HoraOficial::class);
        $hoy = CarbonImmutable::parse($hora->fechaLocal($hora->instante()), $hora->zona());
        $desde = $this->filled('desde') ? CarbonImmutable::parse($this->input('desde'), $hora->zona()) : null;
        $hasta = $this->filled('hasta') ? CarbonImmutable::parse($this->input('hasta'), $hora->zona()) : null;

        return [$desde ?? ($hasta ?? $hoy)->startOfMonth(), $hasta ?? ($desde ?? $hoy)->endOfMonth()->startOfDay()];
    }
}
