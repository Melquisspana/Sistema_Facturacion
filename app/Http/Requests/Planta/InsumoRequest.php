<?php

namespace App\Http\Requests\Planta;

use App\Enums\Planta\TipoInsumo;
use App\Enums\Planta\UnidadBase;
use App\Models\Planta\PlantaInsumo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InsumoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización de acceso la cubre el middleware de la ruta
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Ignora el propio registro al editar; el unique de la base incluye
            // los borrados lógicos, así que la regla se comporta igual.
            'codigo' => [
                'required', 'string', 'max:30',
                Rule::unique('planta_insumos', 'codigo')->ignore($this->route('insumo')),
            ],
            'nombre' => ['required', 'string', 'max:150'],
            'tipo' => ['required', Rule::enum(TipoInsumo::class)],
            'unidad_base' => ['required', Rule::enum(UnidadBase::class)],
            'controla_lotes' => ['required', 'boolean'],
            'permite_fraccion' => ['required', 'boolean'],
            // Un factor de 0 haría que toda recepción convertida diera 0.
            'factor_conversion_sugerido' => ['nullable', 'numeric', 'gt:0'],
            'unidad_recepcion_sugerida' => ['nullable', 'string', 'max:30'],
            'contenido_sugerido' => ['nullable', 'numeric', 'min:0'],
            'stock_minimo' => ['nullable', 'numeric', 'min:0'],
            'observaciones' => ['nullable', 'string'],
            'activo' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Bolsas y viñetas se cuentan enteras. Se RECHAZA la combinación
                // incoherente en vez de corregirla en silencio: cambiar el dato
                // del usuario sin avisar es peor que pedirle que lo confirme.
                $tipo = TipoInsumo::tryFrom((string) $this->input('tipo'));

                if (in_array($tipo, [TipoInsumo::Bolsa, TipoInsumo::Vinieta], true)
                    && $this->boolean('permite_fraccion')) {
                    $validator->errors()->add(
                        'permite_fraccion',
                        'Las bolsas y las viñetas se cuentan por unidades enteras: desmarque «permite fracción».'
                    );
                }
            },

            /*
            | La unidad base de un insumo CON HISTORIAL no se cambia.
            |
            | No es un rótulo: es la unidad en la que están escritas todas sus
            | cantidades. El mayor guarda una copia congelada en cada asiento y
            | `planta_existencias` la toma del catálogo por JOIN, así que
            | cambiarla dejaría el historial diciendo una cosa y el saldo
            | presentándose como otra, sin que ninguna cifra se hubiera movido.
            |
            | Se comprueba aquí para que el usuario reciba un error de validación
            | junto al campo. La misma condición vuelve a mirarse al escribir, en
            | {@see PlantaInsumo::booted()}, que es lo que cubre Tinker y los
            | comandos; ambas capas llaman a `tieneHistorialDeInventario()` para
            | que no puedan divergir.
            */
            function (Validator $validator) {
                $insumo = $this->route('insumo');

                // En la creación no hay unidad anterior que proteger.
                if (! $insumo instanceof PlantaInsumo) {
                    return;
                }

                $enviada = UnidadBase::tryFrom((string) $this->input('unidad_base'));

                // Un valor inválido ya lo rechaza `Rule::enum`; añadir un segundo
                // error sobre el mismo campo sería ruido. Y reenviar la MISMA
                // unidad —lo que hace el formulario siempre— no cambia nada.
                if ($enviada === null || $enviada === $insumo->unidad_base) {
                    return;
                }

                if (! $insumo->tieneHistorialDeInventario()) {
                    return;
                }

                $validator->errors()->add(
                    'unidad_base',
                    'La unidad base no puede modificarse porque este insumo ya tiene movimientos de '
                    .'inventario o historial registrado. Puede editar los demás datos. Si la unidad se '
                    .'configuró incorrectamente, cree un insumo nuevo con la unidad correcta y '
                    .'desactive este.'
                );
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'unidad_base' => 'unidad base',
            'controla_lotes' => 'controla lotes',
            'permite_fraccion' => 'permite fracción',
            'factor_conversion_sugerido' => 'factor de conversión sugerido',
            'unidad_recepcion_sugerida' => 'unidad de recepción sugerida',
            'contenido_sugerido' => 'contenido sugerido',
            'stock_minimo' => 'stock mínimo',
        ];
    }
}
