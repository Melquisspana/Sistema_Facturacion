<?php

namespace App\Http\Requests\Gastos;

use App\Services\Gastos\Dinero;
use App\Services\Gastos\Recurrencia\CalendarioRecurrencia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegistrarGastoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->activo && $this->user()->can('gastos.ver') && $this->user()->can('gastos.registrar')
            && ($this->input('ambito') !== 'personal' || $this->user()->can('gastos.personales'))
            && (! $this->boolean('ya_pagado') || $this->user()->can('gastos.pagos.registrar'));
    }

    public function rules(): array
    {
        $dinero = ['regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'gt:0'];
        $archivo = ['file', 'mimes:'.implode(',', config('gastos.mimes')), 'max:'.config('gastos.max_archivo_kb')];

        return [
            'clave' => ['required', 'uuid'],

            // El documento de Compras del que salió este gasto, si vino de ahí. Viaja
            // de vuelta para que quede el vínculo `deuda`: es ESA fila la que el índice
            // único vigila para que el mismo papel no origine una segunda deuda. Sin
            // este campo el formulario prellenaba y se olvidaba, y el candado nunca
            // llegaba a tener nada que proteger.
            'documento' => ['nullable', 'integer', Rule::exists('documentos_recibidos', 'id')],

            'beneficiario' => ['required', 'string', 'max:180'], 'concepto' => ['required', 'string', 'max:200'],
            'categoria' => ['required', 'string', 'max:100'], 'ambito' => ['required', Rule::in(['empresarial', 'personal'])],
            'persona' => ['exclude_unless:ambito,personal', 'nullable', 'string', 'max:180'],
            'naturaleza' => ['required', Rule::in(['operativo', 'compra', 'tributo', 'retiro'])],
            'moneda' => ['required', Rule::in(config('gastos.monedas'))],
            'monto_pendiente' => ['required', 'boolean'],
            'importe' => ['exclude_if:monto_pendiente,1', 'required', ...$dinero],
            'periodo_desde' => ['nullable', 'date_format:Y-m-d'],
            'periodo_hasta' => ['nullable', 'required_with:periodo_desde', 'date_format:Y-m-d', 'after_or_equal:periodo_desde'],
            'responsable_id' => ['required', Rule::exists('users', 'id')->where('activo', true)],
            'documentacion' => ['required', Rule::in(['adjunto', 'pendiente', 'no_entregaron'])],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'cuotas' => ['exclude_if:monto_pendiente,1', 'required', 'array', 'min:1', 'max:'.config('gastos.max_cuotas')],
            'cuotas.*.importe' => ['required', ...$dinero],
            'cuotas.*.vence' => ['nullable', 'date_format:Y-m-d'],
            'cuotas.*.aplicar' => ['nullable', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'],

            // «Este gasto se repite». Se valida ACÁ, antes de escribir nada, para que
            // un error de frecuencia no deje el gasto creado y la repetición a medias.
            // El importe NO viaja: la repetición lo toma del gasto.
            'se_repite' => ['nullable', 'boolean'],
            'repeticion.frecuencia' => ['exclude_unless:se_repite,1', 'required', Rule::in(array_keys(CalendarioRecurrencia::FRECUENCIAS))],
            'repeticion.monto_modo' => ['exclude_unless:se_repite,1', 'required', Rule::in(['fijo', 'variable'])],
            'repeticion.dia_semana' => ['exclude_unless:se_repite,1', 'nullable', 'integer', 'between:1,7'],
            'repeticion.dia_mes' => ['exclude_unless:se_repite,1', 'nullable', 'integer', 'between:1,31'],
            'repeticion.dia_mes_2' => ['exclude_unless:se_repite,1', 'nullable', 'integer', 'between:1,31'],
            'repeticion.mes' => ['exclude_unless:se_repite,1', 'nullable', 'integer', 'between:1,12'],
            'repeticion.dias_generar_antes' => ['exclude_unless:se_repite,1', 'required', 'integer', 'between:0,60'],
            'repeticion.vigente_hasta' => ['exclude_unless:se_repite,1', 'nullable', 'date_format:Y-m-d'],
            'ya_pagado' => ['required', 'boolean'],
            'pago_importe' => ['exclude_unless:ya_pagado,1', 'required', ...$dinero],
            'pago_fecha' => ['exclude_unless:ya_pagado,1', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'pago_metodo' => ['exclude_unless:ya_pagado,1', 'required', Rule::in(array_keys(config('gastos.metodos')))],
            'pagado_por' => ['exclude_unless:ya_pagado,1', 'required', Rule::exists('users', 'id')->where('activo', true)],
            'pago_referencia' => ['exclude_unless:ya_pagado,1', 'nullable', 'string', 'max:180'],
            'sin_comprobante' => ['exclude_unless:ya_pagado,1', 'nullable', 'required_without:comprobantes', 'string', 'max:250'],
            'documentos' => ['nullable', 'required_if:documentacion,adjunto', 'array', 'max:'.config('gastos.max_archivos')],
            'documentos.*' => $archivo,
            'comprobantes' => ['exclude_unless:ya_pagado,1', 'nullable', 'array', 'max:'.config('gastos.max_archivos')],
            'comprobantes.*' => $archivo,
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            if ($this->boolean('monto_pendiente')) {
                if ($this->boolean('ya_pagado')) {
                    $v->errors()->add('importe', 'Ingresá el importe del gasto antes de registrar su pago.');
                }

                return;
            }
            $suma = $aplicado = 0;
            foreach ($this->input('cuotas') as $i => $fila) {
                $importe = Dinero::centavos((string) $fila['importe']);
                $suma += $importe;
                if ($this->boolean('ya_pagado')) {
                    $pago = Dinero::centavos((string) ($fila['aplicar'] ?: '0'));
                    $aplicado += $pago;
                    if ($pago > $importe) {
                        $v->errors()->add("cuotas.$i.aplicar", 'El pago supera el importe de esta cuota.');
                    }
                }
            }
            if ($suma !== Dinero::centavos((string) $this->input('importe'))) {
                $v->errors()->add('cuotas', 'Las cuotas deben sumar el importe total del gasto.');
            }
            if ($this->boolean('ya_pagado') && $aplicado !== Dinero::centavos((string) $this->input('pago_importe'))) {
                $v->errors()->add('pago_importe', 'El reparto por cuotas debe sumar el importe pagado.');
            }
        }];
    }

    public function attributes(): array
    {
        return ['beneficiario' => 'a quién se paga', 'pago_fecha' => 'fecha del pago', 'pago_importe' => 'importe pagado',
            'sin_comprobante' => 'motivo sin comprobante', 'responsable_id' => 'responsable', 'pagado_por' => 'quién pagó'];
    }
}
