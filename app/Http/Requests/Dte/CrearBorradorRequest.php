<?php

namespace App\Http\Requests\Dte;

use App\Enums\CondicionPago;
use App\Enums\TipoCliente;
use App\Enums\TipoDte;
use App\Enums\TipoItemExportacion;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Support\Dte\ResuelveEmisorUnico;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de entrada para crear un borrador de DTE.
 *
 * Las reglas de campo viven en {@see reglasBase()} y la coherencia cruzada
 * (cliente requerido/compatible con el tipo) en {@see validarCoherencia()}, de
 * modo que DteBorradorService pueda reutilizar exactamente las mismas reglas sin
 * depender del ciclo HTTP.
 *
 * La exigencia de número de orden de compra NO se valida aquí como dominio: la
 * impone el servicio (OrdenCompraRequeridaException). En la UI se añade además
 * como error de campo en {@see withValidator()}.
 */
class CrearBorradorRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El control por rol se aplicará en las rutas cuando exista la UI.
        return true;
    }

    /**
     * Si no llega establecimiento_id/punto_venta_id y solo existe UNA opción activa
     * válida, la resuelve ANTES de validar (ResuelveEmisorUnico). Si hay más de una
     * opción y no se envió valor, no hace nada: `required` sigue exigiéndolo.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(ResuelveEmisorUnico::resolver(
            $this->input('establecimiento_id'),
            $this->input('punto_venta_id'),
        ));
    }

    /**
     * Reglas de campo reutilizables (servicio + HTTP).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function reglasBase(): array
    {
        $tiposHabilitados = array_map(fn (TipoDte $t) => $t->value, TipoDte::habilitados());
        $condiciones = array_map(fn (CondicionPago $c) => $c->value, CondicionPago::cases());

        return [
            'tipo_dte' => ['required', Rule::in($tiposHabilitados)],
            // La presencia/compatibilidad real del cliente la decide validarCoherencia().
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
            'cliente_sucursal_id' => ['nullable', 'integer', 'exists:cliente_sucursales,id'],
            'establecimiento_id' => ['required', 'integer', 'exists:establecimientos,id'],
            'punto_venta_id' => ['required', 'integer', 'exists:puntos_venta,id'],
            'correlativo_id' => ['nullable', 'integer', 'exists:correlativos,id'],
            'condicion_operacion' => ['nullable', Rule::in($condiciones)],
            'aplica_retencion' => ['sometimes', 'boolean'],
            'descuento_global' => ['nullable', 'numeric', 'min:0'],
            'flete' => ['nullable', 'numeric', 'min:0'],
            'seguro' => ['nullable', 'numeric', 'min:0'],
            'numero_orden_compra' => ['nullable', 'string', 'max:50'],
            // Factura de exportación (11): la exigencia real (obligatorios para tipo 11)
            // vive en validarCoherencia(); acá solo se valida FORMATO/pertenencia al
            // catálogo cuando el campo viene, para que otros tipos de DTE no se vean
            // afectados por reglas que no les aplican.
            'tipo_item_expor' => ['nullable', Rule::in(array_map(fn (TipoItemExportacion $t) => $t->value, TipoItemExportacion::cases()))],
            'recinto_fiscal' => ['nullable', 'string', Rule::exists('catalogos_mh', 'codigo')->where('cat', '027')],
            'tipo_regimen' => ['nullable', 'string', Rule::exists('catalogos_mh', 'codigo')->where('cat', '033')],
            'regimen' => ['nullable', 'string', Rule::exists('catalogos_mh', 'codigo')->where('cat', '028')],
            'cod_incoterms' => ['nullable', 'string', Rule::exists('catalogos_mh', 'codigo')->where('cat', '031')],
        ];
    }

    public function rules(): array
    {
        return self::reglasBase();
    }

    /**
     * Coherencia cliente ↔ tipo de documento. Reutilizable por el servicio.
     * - CCF (03): requiere cliente contribuyente.
     * - Factura de exportación (11): requiere cliente de exportación.
     * - Factura (01): cliente opcional, sin restricción de tipo. La exigencia de
     *   receptor identificado por monto (si la normativa lo requiere) se evalúa más
     *   adelante, en generación (ValidacionPreJsonService), donde ya existe el total;
     *   acá en el borrador el total aún no está definido. Ver
     *   config('dte.factura_consumidor_final.receptor_obligatorio_desde') — hoy null
     *   (pendiente de confirmar el monto contra la normativa vigente).
     *
     * @param  array<string, mixed>  $datos
     */
    public static function validarCoherencia(Validator $validator, array $datos, ?Cliente $cliente): void
    {
        $validator->after(function (Validator $validator) use ($datos, $cliente) {
            $tipoRaw = $datos['tipo_dte'] ?? null;
            $tipo = $tipoRaw instanceof TipoDte ? $tipoRaw : TipoDte::tryFrom((string) $tipoRaw);

            // Si se indicó un cliente por id y no existe.
            $clienteProvisto = $datos['cliente_id'] ?? null;
            if ($clienteProvisto !== null && ! $cliente) {
                $validator->errors()->add('cliente_id', 'El cliente indicado no existe.');

                return;
            }

            if ($tipo === TipoDte::CreditoFiscal) {
                if (! $cliente) {
                    $validator->errors()->add('cliente_id', 'El Crédito Fiscal requiere un cliente.');
                } elseif ($cliente->tipo_cliente !== TipoCliente::Contribuyente) {
                    $validator->errors()->add('cliente_id', 'El Crédito Fiscal requiere un cliente contribuyente.');
                }
            } elseif ($tipo === TipoDte::FacturaExportacion) {
                if (! $cliente) {
                    $validator->errors()->add('cliente_id', 'La factura de exportación requiere un cliente.');
                } elseif (! $cliente->tipo_cliente?->esExportacion()) {
                    $validator->errors()->add('cliente_id', 'La factura de exportación requiere un cliente de exportación.');
                }

                // El schema real del MH (fe-fex-v3.json) exige estos campos del emisor y del
                // resumen para tipo 11 (recintoFiscal, tipoRegimen, regimen, codIncoterms,
                // tipoItemExpor); antes se enviaban como null. descIncoterms se resuelve
                // server-side desde el catálogo, no se le pide al usuario ni se valida acá.
                foreach ([
                    'tipo_item_expor' => 'El tipo de ítem de exportación (bienes/servicios) es obligatorio.',
                    'recinto_fiscal' => 'El recinto fiscal es obligatorio para la factura de exportación.',
                    'tipo_regimen' => 'El tipo de régimen es obligatorio para la factura de exportación.',
                    'regimen' => 'El régimen de exportación es obligatorio.',
                    'cod_incoterms' => 'El INCOTERM es obligatorio para la factura de exportación.',
                ] as $campo => $mensaje) {
                    if (blank($datos[$campo] ?? null)) {
                        $validator->errors()->add($campo, $mensaje);
                    }
                }
            }
        });
    }

    public function withValidator(Validator $validator): void
    {
        $cliente = $this->input('cliente_id') ? Cliente::find($this->input('cliente_id')) : null;
        $sucursal = $this->input('cliente_sucursal_id') ? ClienteSucursal::find($this->input('cliente_sucursal_id')) : null;
        self::validarCoherencia($validator, $this->all(), $cliente);

        // UX en la futura UI: marcar el campo si falta la orden de compra exigida
        // (la exige el cliente o la sucursal seleccionada).
        $validator->after(function (Validator $validator) use ($cliente, $sucursal) {
            $tipo = TipoDte::tryFrom((string) $this->input('tipo_dte'));
            $requiere = \App\Support\Dte\ReglaOrdenCompra::requerida($cliente, $sucursal);

            if ($tipo === TipoDte::CreditoFiscal && $requiere && blank($this->input('numero_orden_compra'))) {
                $validator->errors()->add(
                    'numero_orden_compra',
                    'Este cliente requiere número de orden de compra para emitir CCF.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'tipo_dte.required' => 'El tipo de documento es obligatorio.',
            'tipo_dte.in' => 'El tipo de documento no está habilitado.',
            'establecimiento_id.required' => 'El establecimiento es obligatorio.',
            'punto_venta_id.required' => 'El punto de venta es obligatorio.',
        ];
    }
}
