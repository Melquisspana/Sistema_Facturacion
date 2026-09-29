<?php

namespace App\Services\Gastos;

use App\Models\DocumentoRecibido;
use App\Models\Gastos\Ajuste;
use App\Models\Gastos\Cuota;
use App\Models\Gastos\Gasto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Notas de crédito, notas de débito y correcciones de importe.
 *
 * UN AJUSTE NO ES UN PAGO. No hay salida de dinero: no lleva método, ni pagador,
 * ni comprobante de pago, y no entra en «pagado en el período». Una deuda saldada
 * solo con crédito se informa como «Saldada por ajuste», nunca como «Pagada»;
 * confundirlas falsearía cualquier informe de egresos.
 *
 * En V1 un crédito se admite HASTA el saldo disponible de la cuota. Un crédito
 * mayor no se aplica a medias ni empuja el saldo a negativo en silencio: se
 * rechaza indicando cuánto cabe, y el remanente queda como asunto documental
 * hasta que exista el subregistro de saldo a favor.
 *
 * Se revierte, nunca se borra: el historial de una deuda tiene que poder
 * explicarse entero.
 */
final class RegistrarAjuste
{
    public function __construct(
        private AccesoGastos $acceso,
        private SaldosGastos $saldos,
        private VincularCompra $compras,
    ) {}

    /** @param  array<string, mixed>  $datos */
    public function registrar(User $usuario, Cuota $cuota, array $datos): Ajuste
    {
        abort_unless($usuario->activo && $usuario->can('gastos.administrar'), 403);

        $datos = Validator::make($datos, [
            'clave' => ['required', 'uuid'],
            'direccion' => ['required', Rule::in(Ajuste::DIRECCIONES)],
            'tipo' => ['required', Rule::in(array_keys(Ajuste::TIPOS))],
            'importe' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'gt:0'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
            'documento_recibido_id' => ['nullable', 'integer', Rule::exists('documentos_recibidos', 'id')],
        ], [
            'motivo.required' => 'Explicá por qué se ajusta la deuda.',
            'motivo.min' => 'El motivo tiene que decir algo: un ajuste sin explicación no se puede auditar después.',
        ])->validate();

        return DB::transaction(function () use ($usuario, $cuota, $datos) {
            User::whereKey($usuario->id)->lockForUpdate()->firstOrFail();

            if ($existente = Ajuste::where('clave', $datos['clave'])->first()) {
                return $existente;
            }

            // Mismo orden de bloqueo que en los pagos —gasto y después cuota— para que
            // un ajuste y un pago simultáneos no puedan cruzarse.
            $gasto = Gasto::whereKey($cuota->gasto_id)->lockForUpdate()->firstOrFail();
            abort_unless($this->acceso->ver($usuario, $gasto), 403);

            $cuota = Cuota::whereKey($cuota->id)->lockForUpdate()->firstOrFail();

            $importe = Dinero::centavos((string) $datos['importe']);
            $disponible = $this->saldos->pendienteCuota($cuota);

            // Crédito respaldado por una nota de crédito de Compras: no puede aplicarse
            // por más de lo que ese documento vale, ni entre todos los ajustes que
            // cuelguen de él. Una NC puede repartirse entre varias deudas, así que el
            // control es ACUMULADO; si fuera «usada o no usada» bastaría partirla en dos
            // ajustes para descontarla dos veces. Se comprueba DENTRO de la transacción
            // y con el gasto bloqueado, así dos aplicaciones simultáneas no pasan las dos.
            if ($datos['direccion'] === 'credito' && ! empty($datos['documento_recibido_id'])) {
                $documento = DocumentoRecibido::lockForUpdate()->find($datos['documento_recibido_id']);

                if ($documento === null) {
                    throw ValidationException::withMessages([
                        'documento_recibido_id' => 'El documento de respaldo ya no existe.',
                    ]);
                }

                $credito = $this->compras->creditoDelDocumento($documento);

                if ($credito['total'] <= 0) {
                    throw ValidationException::withMessages([
                        'documento_recibido_id' => 'Ese documento no trae un total utilizable como crédito. Revisalo antes de aplicarlo.',
                    ]);
                }

                if ($importe > $credito['disponible']) {
                    throw ValidationException::withMessages([
                        'importe' => 'De esa nota de crédito quedan '.Dinero::decimal($credito['disponible'])
                            .' por aplicar (total '.Dinero::decimal($credito['total'])
                            .', ya aplicado '.Dinero::decimal($credito['aplicado']).'). No se puede descontar dos veces.',
                    ]);
                }
            }

            if ($datos['direccion'] === 'credito' && $importe > $disponible) {
                throw ValidationException::withMessages([
                    'importe' => $disponible > 0
                        ? 'El crédito supera el saldo de esta cuota. Como máximo caben '.Dinero::decimal($disponible).'.'
                        : 'Esta cuota ya no tiene saldo: un crédito acá dejaría la deuda en negativo.',
                ]);
            }

            $ajuste = Ajuste::create([
                'clave' => $datos['clave'],
                'cuota_id' => $cuota->id,
                'direccion' => $datos['direccion'],
                'tipo' => $datos['tipo'],
                'importe' => Dinero::decimal($importe),
                'motivo' => trim($datos['motivo']),
                'documento_recibido_id' => $datos['documento_recibido_id'] ?? null,
                'registrado_por' => $usuario->id,
            ]);

            DB::table('gastos_eventos')->insert([
                'gasto_id' => $gasto->id,
                'ajuste_id' => $ajuste->id,
                'usuario_id' => $usuario->id,
                'accion' => 'ajuste_registrado',
                'datos' => json_encode([
                    'direccion' => $ajuste->direccion, 'tipo' => $ajuste->tipo,
                    'importe' => $ajuste->importe, 'cuota' => $cuota->numero, 'motivo' => $ajuste->motivo,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $ajuste;
        });
    }

    public function revertir(User $usuario, Ajuste $ajuste, string $motivo): void
    {
        abort_unless($usuario->activo && $usuario->can('gastos.administrar'), 403);

        Validator::make(['motivo' => trim($motivo)], [
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ])->validate();

        DB::transaction(function () use ($usuario, $ajuste, $motivo) {
            User::whereKey($usuario->id)->lockForUpdate()->firstOrFail();

            $cuota = Cuota::whereKey($ajuste->cuota_id)->firstOrFail();
            $gasto = Gasto::whereKey($cuota->gasto_id)->lockForUpdate()->firstOrFail();
            abort_unless($this->acceso->ver($usuario, $gasto), 403);

            $ajuste = Ajuste::whereKey($ajuste->id)->lockForUpdate()->firstOrFail();
            if (! $ajuste->vigente()) {
                return; // ya revertido: repetir no cambia nada ni duplica el evento
            }

            // Quitar un DÉBITO baja la deuda: siempre cabe. Quitar un CRÉDITO la sube, y
            // eso puede chocar con pagos que ya la cubrieron. No se deja pasar en
            // silencio: quedaría un saldo negativo que nadie pidió.
            if ($ajuste->direccion === 'credito') {
                $cuota = Cuota::whereKey($cuota->id)->lockForUpdate()->firstOrFail();
                $futuro = $this->saldos->pendienteCuota($cuota) + Dinero::centavos((string) $ajuste->importe);
                $tope = Dinero::centavos((string) $cuota->importe)
                    + $this->saldos->ajustes($cuota->id, 'debito')
                    - $this->saldos->ajustes($cuota->id, 'credito')
                    + Dinero::centavos((string) $ajuste->importe);

                if ($futuro > $tope) {
                    throw ValidationException::withMessages([
                        'motivo' => 'Revertir este crédito dejaría la cuota descuadrada. Revisá antes los pagos aplicados.',
                    ]);
                }
            }

            $ajuste->update([
                'revertido_at' => now(),
                'revertido_por' => $usuario->id,
                'motivo_reversion' => trim($motivo),
            ]);

            DB::table('gastos_eventos')->insert([
                'gasto_id' => $gasto->id,
                'ajuste_id' => $ajuste->id,
                'usuario_id' => $usuario->id,
                'accion' => 'ajuste_revertido',
                'datos' => json_encode(['motivo' => trim($motivo)], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });
    }
}
