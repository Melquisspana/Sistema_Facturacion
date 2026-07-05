<?php

namespace App\Http\Requests\Clientes;

use App\Enums\TamanioContribuyente;
use App\Enums\TipoCliente;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoPersona;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de clientes (crear y actualizar).
 *
 * La obligatoriedad de los campos depende de `tipo_cliente`:
 * - Contribuyente (nacional, CCF): NRC, NIT, actividad económica, país El
 *   Salvador, departamento y municipio (municipio dentro del departamento).
 * - Consumidor final (nacional, Factura): país El Salvador, departamento y
 *   municipio; NRC NO requerido; documento opcional (DUI/NIT/otro).
 * - Exportación (FEX): país extranjero; departamento/municipio no aplican;
 *   dirección o complemento obligatorio; actividad económica obligatoria (el
 *   esquema oficial exige descActividad del receptor); NRC NO requerido.
 *
 * Código de país de El Salvador en CAT-020: SV.
 */
class ClienteRequest extends FormRequest
{
    private const PAIS_EL_SALVADOR = 'SV';

    public function authorize(): bool
    {
        // El acceso por rol/permiso se impondrá en las rutas (PASO 3).
        return true;
    }

    public function rules(): array
    {
        $tipo = TipoCliente::tryFrom((string) $this->input('tipo_cliente'));
        $esNacional = $tipo?->esNacional() ?? false;
        $esExportacion = $tipo?->esExportacion() ?? false;
        $esContribuyente = $tipo === TipoCliente::Contribuyente;

        $clienteId = $this->route('cliente')?->id;
        $documento = $this->input('tipo_documento');

        $reglas = [
            'codigo' => [
                'nullable', 'string', 'max:50',
                Rule::unique('clientes', 'codigo')->whereNull('deleted_at')->ignore($clienteId),
            ],
            'tipo_cliente' => ['required', Rule::in(array_column(TipoCliente::cases(), 'value'))],
            'tipo_persona' => [
                $esExportacion ? 'required' : 'nullable',
                Rule::in(array_column(TipoPersona::cases(), 'value')),
            ],
            'tipo_documento' => [
                ($esContribuyente || $esExportacion) ? 'required' : 'nullable',
                Rule::in(array_column(TipoDocumentoCliente::cases(), 'value')),
            ],
            'num_documento' => [
                'nullable', 'string', 'max:25',
                'required_with:tipo_documento',
                Rule::unique('clientes', 'num_documento')
                    ->where(fn ($q) => $q->where('tipo_documento', $documento))
                    ->whereNull('deleted_at')
                    ->ignore($clienteId),
            ],
            'nrc' => [
                $esContribuyente ? 'required' : 'nullable',
                'string', 'max:20', 'regex:/^[0-9]{1,8}-?[0-9]?$/',
            ],
            'nombre' => ['required', 'string', 'max:255'],
            'nombre_comercial' => ['nullable', 'string', 'max:255'],
            // La actividad económica es obligatoria para contribuyente (CCF) y para
            // exportación (el esquema FEX exige descActividad del receptor, CAT-019).
            'actividad_economica_id' => [
                ($esContribuyente || $esExportacion) ? 'required' : 'nullable',
                'exists:actividades_economicas,id',
            ],
            // Distrito (división 2024). Nullable para no bloquear clientes ya cargados;
            // si se indica, debe pertenecer al departamento elegido.
            'distrito_id' => [
                'nullable',
                Rule::exists('distritos', 'id')->where(
                    fn ($q) => $q->where('departamento_id', $this->input('departamento_id'))
                ),
            ],
            'direccion' => ['nullable', 'string', 'max:255'],
            'complemento_direccion' => ['nullable', 'string', 'max:255'],
            'correo' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30', 'regex:/^[0-9()+\-\s]{7,30}$/'],
            'contacto_principal' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            // Configuración para CCF (preparación; sin lógica de DTE todavía).
            'requiere_orden_compra' => ['boolean'],
            'etiqueta_orden_compra' => ['nullable', 'string', 'max:100'],
            'observaciones_facturacion' => ['nullable', 'string', 'max:1000'],
            // Datos del cliente (no del documento).
            'tamanio_contribuyente' => ['nullable', Rule::in(array_column(TamanioContribuyente::cases(), 'value'))],
            // Porcentaje de descuento global (0–100), no monto.
            'descuento_global_default' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Calculado automáticamente desde el tamaño (ver prepareForValidation);
            // el request no puede forzar un valor distinto al tamaño.
            'es_agente_retencion' => ['boolean'],
            'activo' => ['required', 'boolean'],
        ];

        // El número de documento también es obligatorio para contribuyente y exportación.
        if ($esContribuyente || $esExportacion) {
            $reglas['num_documento'][] = 'required';
        }

        // Formato/validez del documento según su tipo.
        if ($documento === TipoDocumentoCliente::Nit->value) {
            $reglas['num_documento'][] = 'regex:/^(\d{4}-\d{6}-\d{3}-\d|\d{14})$/';
        }
        if ($documento === TipoDocumentoCliente::Dui->value) {
            $reglas['num_documento'][] = function ($attr, $value, $fail) {
                if (! $this->duiEsValido((string) $value)) {
                    $fail('El DUI no es válido (formato o dígito verificador).');
                }
            };
        }

        // País / ubicación según tipo de cliente.
        if ($esNacional) {
            $reglas['pais_id'] = ['required', Rule::exists('paises', 'id')->where('codigo', self::PAIS_EL_SALVADOR)];
            $reglas['departamento_id'] = ['required', 'exists:departamentos,id'];
            $reglas['municipio_id'] = [
                'required',
                Rule::exists('municipios', 'id')->where(
                    fn ($q) => $q->where('departamento_id', $this->input('departamento_id'))
                ),
            ];
        } elseif ($esExportacion) {
            $reglas['pais_id'] = ['required', Rule::exists('paises', 'id')->where(fn ($q) => $q->where('codigo', '!=', self::PAIS_EL_SALVADOR))];
            $reglas['departamento_id'] = ['nullable', 'exists:departamentos,id'];
            $reglas['municipio_id'] = ['nullable', 'exists:municipios,id'];
        } else {
            $reglas['pais_id'] = ['nullable', 'exists:paises,id'];
            $reglas['departamento_id'] = ['nullable', 'exists:departamentos,id'];
            $reglas['municipio_id'] = ['nullable', 'exists:municipios,id'];
        }

        return $reglas;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $tipo = TipoCliente::tryFrom((string) $this->input('tipo_cliente'));

            // Exportación: exigir dirección o complemento de dirección.
            if ($tipo === TipoCliente::Exportacion
                && blank($this->input('direccion'))
                && blank($this->input('complemento_direccion'))) {
                $validator->errors()->add(
                    'direccion',
                    'Para exportación debe indicar dirección o complemento de dirección.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'pais_id.exists' => 'El país no es válido para el tipo de cliente seleccionado.',
            'municipio_id.exists' => 'El municipio seleccionado no pertenece al departamento elegido.',
            'distrito_id.exists' => 'El distrito seleccionado no pertenece al departamento elegido.',
            'nrc.required' => 'El NRC es obligatorio para un cliente contribuyente.',
            'tipo_documento.required' => 'El tipo de documento es obligatorio para este tipo de cliente.',
            'num_documento.required' => 'El número de documento es obligatorio.',
            'num_documento.required_with' => 'El número de documento es obligatorio cuando se indica un tipo de documento.',
            'num_documento.unique' => 'Ya existe un cliente con ese número de documento.',
            'codigo.unique' => 'Ya existe un cliente con ese código interno.',
            'actividad_economica_id.required' => 'La actividad económica es obligatoria para clientes contribuyentes y de exportación.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Sanitización básica: recortar espacios y normalizar vacíos a null.
        $limpio = [];
        foreach ($this->all() as $clave => $valor) {
            if (is_string($valor)) {
                $valor = trim($valor);
                if ($valor === '') {
                    $valor = null;
                }
            }
            $limpio[$clave] = $valor;
        }

        $limpio['activo'] = $this->boolean('activo');

        // Configuración de orden de compra: si la requiere y no se indicó
        // etiqueta, usar "Orden de compra" por defecto. Si no la requiere, no
        // se conserva etiqueta.
        $requiereOc = $this->boolean('requiere_orden_compra');
        $limpio['requiere_orden_compra'] = $requiereOc;
        if ($requiereOc) {
            $etiqueta = trim((string) ($limpio['etiqueta_orden_compra'] ?? ''));
            $limpio['etiqueta_orden_compra'] = $etiqueta !== '' ? $etiqueta : 'Orden de compra';
        } else {
            $limpio['etiqueta_orden_compra'] = null;
        }

        // El descuento del cliente nunca es negativo; vacío equivale a 0.00.
        $descuento = $limpio['descuento_global_default'] ?? null;
        $limpio['descuento_global_default'] = ($descuento === null || $descuento === '') ? '0.00' : $descuento;

        // La retención NO se marca manualmente: se deriva del tamaño de
        // contribuyente. Solo el "grande" es agente de retención. Se ignora
        // cualquier valor de es_agente_retencion enviado por el cliente.
        $tamanio = $limpio['tamanio_contribuyente'] ?? null;
        $limpio['es_agente_retencion'] = ($tamanio === TamanioContribuyente::Grande->value);

        $this->merge($limpio);
    }

    /**
     * Valida el DUI salvadoreño: 8 dígitos + dígito verificador.
     */
    private function duiEsValido(string $dui): bool
    {
        $dui = preg_replace('/[^0-9]/', '', $dui);

        if (strlen($dui) !== 9) {
            return false;
        }

        $digitos = str_split($dui);
        $verificador = (int) array_pop($digitos);

        $suma = 0;
        foreach ($digitos as $i => $digito) {
            $suma += ((int) $digito) * (9 - $i);
        }

        $calculado = 10 - ($suma % 10);
        if ($calculado === 10) {
            $calculado = 0;
        }

        return $calculado === $verificador;
    }
}
