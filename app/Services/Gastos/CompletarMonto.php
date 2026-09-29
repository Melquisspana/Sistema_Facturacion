<?php

namespace App\Services\Gastos;

use App\Models\DocumentoRecibido;
use App\Models\Gastos\Gasto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Poner el monto a una obligación que estaba esperándolo.
 *
 * Es el cierre del caso «todavía no conozco el monto»: llega el recibo y hay que
 * dejar la deuda lista para pagar. Se COMPLETA el registro que ya existe; no se
 * crea otro. Crear uno nuevo dejaría dos filas para la misma deuda y el operador
 * tendría que acordarse de cancelar la primera —que es justo el error que este
 * módulo existe para evitar—.
 *
 * Solo se admite sobre un gasto con importe NULL. Cambiar el importe de una
 * obligación que YA lo tenía es otra cosa: eso es una corrección y va por
 * {@see RegistrarAjuste}, que deja rastro de cuánto cambió y por qué. Acá no hay
 * nada que corregir porque no había cifra anterior.
 */
final class CompletarMonto
{
    public function __construct(private AccesoGastos $acceso, private VincularCompra $compras) {}

    /**
     * @param  array<int, array{importe: string, vence: ?string}>  $cuotas
     * @param  ?DocumentoRecibido  $documento  El recibo que trae la cifra, si viene de Compras
     */
    public function completar(User $usuario, Gasto $gasto, array $cuotas, ?DocumentoRecibido $documento = null): Gasto
    {
        abort_unless($usuario->activo && $usuario->can('gastos.registrar'), 403);
        abort_unless($this->acceso->ver($usuario, $gasto), 403);

        Validator::make(['cuotas' => array_values($cuotas)], [
            'cuotas' => ['required', 'array', 'min:1', 'max:'.config('gastos.max_cuotas')],
            'cuotas.*.importe' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'gt:0'],
            'cuotas.*.vence' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'cuotas.*.importe.required' => 'Indicá el importe de cada cuota.',
        ])->validate();

        return DB::transaction(function () use ($usuario, $gasto, $cuotas, $documento) {
            User::whereKey($usuario->id)->lockForUpdate()->firstOrFail();
            $gasto = Gasto::whereKey($gasto->id)->lockForUpdate()->firstOrFail();

            // Doble clic o dos operadores a la vez: el segundo encuentra el importe ya
            // puesto y se detiene en vez de duplicar cuotas sobre la misma deuda.
            if (! $gasto->montoDesconocido()) {
                throw ValidationException::withMessages([
                    'importe' => 'Este gasto ya tiene importe ('.$gasto->moneda.' '.$gasto->importe
                        .'). Para cambiarlo, registrá un ajuste: así queda el rastro de cuánto varió y por qué.',
                ]);
            }

            if ($gasto->cuotas()->exists()) {
                throw ValidationException::withMessages([
                    'importe' => 'Este gasto ya tiene cuotas. Revisá su ficha antes de volver a completarlo.',
                ]);
            }

            $total = 0;
            foreach (array_values($cuotas) as $i => $fila) {
                $importe = Dinero::centavos((string) $fila['importe']);
                $total += $importe;

                $gasto->cuotas()->create([
                    'numero' => $i + 1,
                    'importe' => Dinero::decimal($importe),
                    'vence' => $fila['vence'] ?: null,
                ]);
            }

            $gasto->update(['importe' => Dinero::decimal($total)]);

            // El recibo que puso la cifra ES el que originó la deuda: hasta este momento
            // la obligación existía sin importe y no debía nada. Por eso se vincula como
            // `deuda` y no como respaldo, y por eso el mismo recibo ya no puede después
            // originar otro gasto: el índice único lo rechaza y esto se deshace entero.
            if ($documento !== null) {
                abort_unless($usuario->can('documentos-recibidos.ver'), 403);

                $this->compras->vincular($usuario, $gasto, $documento, 'deuda');
            }

            DB::table('gastos_eventos')->insert([
                'gasto_id' => $gasto->id,
                'usuario_id' => $usuario->id,
                'accion' => 'monto_completado',
                'datos' => json_encode([
                    'importe' => Dinero::decimal($total),
                    'moneda' => $gasto->moneda,
                    'cuotas' => count($cuotas),
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $gasto->fresh();
        });
    }
}
