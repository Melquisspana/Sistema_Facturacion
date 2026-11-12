<?php

namespace App\Http\Requests\Clientes;

use App\Enums\OrigenDescuentoNc;
use App\Enums\TipoNotaCredito;
use App\Services\Ppq\Exportadores\ExportadorNcFactory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validación del perfil documental de un cliente.
 *
 * La regla que no es obvia está en {@see withValidator()}: una modalidad marcada con
 * «tasa propia» sin tasa, o marcada para exportar sin formato, produciría un perfil que
 * parece configurado y falla el día que hay que emitir. Se rechaza acá, con el error en
 * el campo concreto, en vez de dejarlo pasar y explotar más tarde.
 */
class PerfilDocumentoRequest extends FormRequest
{
    /*
     * Reglas de formato de los tres campos que también se cargan por consola
     * (`perfil-documento:cliente`). Viven acá y el comando las usa tal cual, para que la
     * consola no acepte lo que esta pantalla rechaza.
     */
    public const REGLAS_TOLERANCIA = ['numeric', 'min:0', 'max:9999.99'];

    public const REGLAS_CODIGO_EXTERNO = ['string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'];

    public const REGLAS_TASA = ['numeric', 'min:0', 'max:100'];

    public const MENSAJE_CODIGO_EXTERNO = 'El código externo solo admite letras y números (por ejemplo AC02).';

    /** La autorización real la hace el controlador con la ClientePolicy (clientes.gestionar). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'activo' => ['nullable', 'boolean'],
            'codigo_proveedor' => ['nullable', 'string', 'max:20'],
            'formato_export' => ['nullable', 'string', 'max:40', Rule::in(ExportadorNcFactory::slugs())],
            'exige_albaran_en_nc' => ['nullable', 'boolean'],
            'tolerancia_albaran' => ['required', ...self::REGLAS_TOLERANCIA],

            'modalidades' => ['nullable', 'array'],
            'modalidades.*.usar' => ['nullable', 'boolean'],
            'modalidades.*.codigo_externo' => ['nullable', ...self::REGLAS_CODIGO_EXTERNO],
            'modalidades.*.etiqueta_externa' => ['nullable', 'string', 'max:60'],
            'modalidades.*.descuento_origen' => ['nullable', Rule::in(array_keys(OrigenDescuentoNc::opciones()))],
            'modalidades.*.descuento_tasa' => ['nullable', ...self::REGLAS_TASA],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $atributos = [
            'codigo_proveedor' => 'código de proveedor',
            'formato_export' => 'formato de exportación',
            'tolerancia_albaran' => 'tolerancia contra el albarán',
        ];

        foreach (TipoNotaCredito::cases() as $tipo) {
            $atributos["modalidades.{$tipo->value}.codigo_externo"] = 'código externo de '.mb_strtolower($tipo->label());
            $atributos["modalidades.{$tipo->value}.descuento_tasa"] = 'tasa de '.mb_strtolower($tipo->label());
        }

        return $atributos;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'modalidades.*.codigo_externo.regex' => self::MENSAJE_CODIGO_EXTERNO,
            'formato_export.in' => 'Ese formato de exportación no existe.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ((array) $this->input('modalidades', []) as $clave => $datos) {
                if (! (bool) ($datos['usar'] ?? false)) {
                    continue; // modalidad no mapeada: no se le exige nada
                }

                if (blank($datos['codigo_externo'] ?? null)) {
                    $v->errors()->add(
                        "modalidades.{$clave}.codigo_externo",
                        'Indique el código con el que el cliente identifica esta modalidad (por ejemplo AC02).'
                    );
                }

                $origen = OrigenDescuentoNc::tryFrom((string) ($datos['descuento_origen'] ?? ''));

                if ($origen === null) {
                    $v->errors()->add(
                        "modalidades.{$clave}.descuento_origen",
                        'Elija de dónde sale el descuento de esta modalidad.'
                    );

                    continue;
                }

                if ($origen->requiereTasa() && ! is_numeric($datos['descuento_tasa'] ?? null)) {
                    $v->errors()->add(
                        "modalidades.{$clave}.descuento_tasa",
                        'Con «tasa propia» hay que escribir el porcentaje que aplica esta modalidad.'
                    );
                }
            }

            // Un perfil activo que no exporta es legítimo (puede usarse solo para las
            // reglas de descuento), pero uno con código de proveedor y sin formato es casi
            // siempre un olvido: se avisa donde se ve.
            if (filled($this->input('codigo_proveedor')) && blank($this->input('formato_export'))) {
                $v->errors()->add(
                    'formato_export',
                    'Indicó un código de proveedor pero no un formato de exportación: sin formato no se puede generar el archivo del cliente.'
                );
            }
        });
    }
}
