<?php

namespace App\Http\Requests\Dte;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\TipoAnulacionMh;
use App\Models\Dte;
use App\Policies\DtePolicy;
use App\Services\Dte\DteInvalidacionService;
use App\Services\Dte\ValidadorReglasInvalidacion;
use App\Support\Dte\PoliticaInvalidacion;
use App\Support\Dte\RequisitosInvalidacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Valida y autoriza la TRANSMISIÓN REAL del evento de invalidación (anulardte) desde la web.
 *
 * La autorización de CANDIDATURA vive en la política
 * ({@see DtePolicy::transmitirInvalidacion()}). Aquí, además, se valida en
 * SERVIDOR la frase-barrera exacta `INVALIDAR DTE` (no basta el JS) y los campos CAT-024
 * según la MATRIZ del documento concreto ({@see PoliticaInvalidacion}), no según el motivo
 * por sí solo: un POST manipulado que pida sustituto para una NC, o que lo omita en un CCF
 * por motivo 3, se rechaza aquí sin llegar al servicio.
 *
 * Los candados DUROS restantes (flags, firma real, ambiente, doble invalidación, evidencia
 * protegida, sustituto verificado, notas de crédito/débito vigentes y plazo) los RE-valida
 * {@see DteInvalidacionService} en cada intento, inmediatamente antes de
 * firmar: aquí también se aplican las reglas fiscales, pero no es una autorización que
 * siga valiendo un rato después.
 */
class TransmitirInvalidacionRequest extends FormRequest
{
    /** Frase-barrera exacta exigida en servidor. */
    public const FRASE = 'INVALIDAR DTE';

    public function authorize(): bool
    {
        $dte = $this->route('dte');

        return $dte instanceof Dte
            && $this->user() !== null
            && $this->user()->can('transmitirInvalidacion', $dte);
    }

    /**
     * Normaliza la entrada ANTES de validar: un campo de formulario vacío (o con espacios)
     * es «sin valor», no «valor enviado». Sin esto, el hidden del asistente —que viaja
     * siempre, aunque el motivo elegido no pida sustituto— parecería un intento de mandar
     * reemplazo donde la matriz lo prohíbe.
     *
     * SOLO toca cadenas. Un `reemplazo[]=x` o un `motivo[]=x` se dejan EXACTAMENTE como
     * llegaron para que la regla `string` los rechace: convertirlos aquí con `(string)`
     * disparaba «Array to string conversion», que bajo el manejador de errores de Laravel
     * es una excepción —un 500— en vez de un error de validación. Tampoco se aplanan
     * booleanos ni números: el validador tiene que ver el tipo que de verdad se recibió,
     * no una versión ya maquillada de él.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'reemplazo' => self::normalizarCodigo($this->input('reemplazo')),
            'motivo' => self::normalizarTexto($this->input('motivo')),
        ]);
    }

    /**
     * Cadena recortada y en mayúsculas, o null si queda vacía. Cualquier valor que NO sea
     * cadena se devuelve intacto para que lo rechace la validación.
     */
    public static function normalizarCodigo(mixed $valor): mixed
    {
        if (! is_string($valor)) {
            return $valor;
        }

        $codigo = strtoupper(trim($valor));

        return $codigo === '' ? null : $codigo;
    }

    /** Ídem, sin pasar a mayúsculas: el motivo es texto que escribe una persona. */
    public static function normalizarTexto(mixed $valor): mixed
    {
        if (! is_string($valor)) {
            return $valor;
        }

        $texto = trim($valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(array_map(fn ($t) => $t->value, TipoAnulacionMh::cases()))],
            // Límite 200: el que declara invalidacion-schema-v3 para motivo.motivoAnulacion.
            'motivo' => ['nullable', 'string', 'max:200', Rule::requiredIf(fn () => $this->requisitos()?->requiereMotivoTexto === true)],
            'reemplazo' => [
                'nullable', 'string', 'max:36',
                Rule::requiredIf(fn () => $this->requisitos()?->requiereReemplazo === true),
                // Celda de la matriz que exige null: se rechaza con explicación, no se
                // ignora en silencio.
                Rule::prohibitedIf(fn () => $this->requisitos()?->prohibeReemplazo() === true),
            ],
            // Frase-barrera exacta validada en SERVIDOR (defensa en profundidad, no solo JS).
            'confirmacion_invalidacion' => ['required', 'string', Rule::in([self::FRASE])],
            // OBSOLETO: se sigue ACEPTANDO para no romper integraciones antiguas, pero ya no
            // se lee en ninguna parte. La dependencia de notas fiscales vigentes es una regla
            // del MH, no un riesgo que quien factura pueda asumir con una casilla.
            'confirmar_nc_relacionada' => ['nullable', 'boolean'],
        ];
    }

    /** Revalida las reglas fiscales antes de llegar al controlador. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $dte = $this->route('dte');
            if (! $dte instanceof Dte) {
                return;
            }
            $evento = new EventoInvalidacionData(
                tipoAnulacion: TipoAnulacionMh::from((int) $this->input('tipo')),
                motivoAnulacion: $this->input('motivo'),
                codigoGeneracionReemplazo: $this->input('reemplazo'),
            );
            foreach (app(ValidadorReglasInvalidacion::class)->problemas($dte, $evento) as $problema) {
                $validator->errors()->add('confirmacion_invalidacion', $problema);
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $requisitos = $this->requisitos();
        $documento = $requisitos?->documento?->label() ?? 'documento';

        return [
            'tipo.required' => 'Seleccione el tipo de anulación (CAT-024).',
            'motivo.required' => 'El motivo en texto es obligatorio para el tipo 3 (Otro).',
            'reemplazo.required' => 'Para invalidar un '.$documento.' por este motivo hace falta el código de '
                .'generación del documento que lo sustituye, previamente aceptado por Hacienda.',
            'reemplazo.prohibited' => 'Esta combinación de documento y motivo no admite documento de reemplazo: '
                .'el evento debe viajar con codigoGeneracionR en null.',
            'confirmacion_invalidacion.required' => 'Escribí la frase exacta '.self::FRASE.' para transmitir la invalidación.',
            'confirmacion_invalidacion.in' => 'La frase de confirmación no coincide: escribí exactamente '.self::FRASE.'.',
        ];
    }

    /**
     * Requisitos de la matriz para ESTE documento y el motivo enviado. Null si el motivo
     * aún no es un valor de CAT-024 —incluido el caso de que ni siquiera sea un escalar—:
     * esa falla la reporta la regla de `tipo`, y calcular la matriz a partir de una
     * entrada que el validador va a rechazar solo serviría para deducir un requisito falso
     * (`(int) ['3']` vale 1, o sea el motivo 1, que no es lo que nadie envió).
     */
    private function requisitos(): ?RequisitosInvalidacion
    {
        $entrada = $this->input('tipo');
        $dte = $this->route('dte');

        if (! is_scalar($entrada) || ! $dte instanceof Dte) {
            return null;
        }

        $tipo = TipoAnulacionMh::tryFrom((int) $entrada);

        return $tipo === null ? null : PoliticaInvalidacion::requisitos($dte->tipo_dte, $tipo);
    }
}
