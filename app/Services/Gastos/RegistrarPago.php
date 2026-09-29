<?php

namespace App\Services\Gastos;

use App\Models\Gastos\Cuota;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\User;
use App\Services\Gastos\Contracts\ProteccionDeGastos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RegistrarPago
{
    public function __construct(
        private AccesoGastos $acceso,
        private SaldosGastos $saldos,
        private ProteccionDeGastos $proteccion,
    ) {}

    /** Aplicaciones explícitas por cuota; nunca reparte silenciosamente en el servidor. */
    public function registrar(User $usuario, array $datos, array $aplicaciones): Pago
    {
        abort_unless($usuario->activo && $usuario->can('gastos.pagos.registrar'), 403);
        $datos = Validator::make($datos, [
            'clave' => ['required', 'uuid'], 'importe' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'gt:0'],
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'metodo' => ['required', Rule::in(array_keys(config('gastos.metodos')))],
            'pagado_por' => ['required', Rule::exists('users', 'id')->where('activo', true)],
            'referencia' => ['nullable', 'string', 'max:180'],
            'sin_comprobante' => ['nullable', 'string', 'max:250'],
        ])->validate();
        Validator::make(['aplicaciones' => $aplicaciones], [
            'aplicaciones' => ['required', 'array', 'min:1', 'max:100'],
            'aplicaciones.*.cuota_id' => ['required', 'integer', 'distinct'],
            'aplicaciones.*.importe' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'gt:0'],
        ])->validate();
        usort($aplicaciones, fn ($a, $b) => $a['cuota_id'] <=> $b['cuota_id']);
        $huella = hash('sha256', json_encode([$datos, $aplicaciones], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($usuario, $datos, $aplicaciones, $huella) {
            // Serializa reintentos del mismo operador; gasto/cuota serializa operadores distintos.
            User::whereKey($usuario->id)->lockForUpdate()->firstOrFail();
            if ($existente = Pago::where('clave', $datos['clave'])->first()) {
                abort_unless($this->acceso->pagoCompleto($usuario, $existente), 403);
                if ($existente->registrado_por !== $usuario->id || $existente->huella_peticion !== $huella) {
                    throw ValidationException::withMessages(['pago' => 'Este registro ya fue utilizado con otros datos.']);
                }

                return $existente;
            }
            $ids = array_column($aplicaciones, 'cuota_id');
            $gastoIds = Cuota::whereIn('id', $ids)->pluck('gasto_id');
            $gastos = Gasto::whereIn('id', $gastoIds)->orderBy('id')->lockForUpdate()->get();
            $cuotas = Cuota::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($cuotas->count() !== count($ids)) {
                throw ValidationException::withMessages(['pago' => 'Revisá las cuotas seleccionadas.']);
            }
            abort_unless($gastos->every(fn ($gasto) => $this->acceso->ver($usuario, $gasto)), 403);
            // Alcanzar una obligación y poder pagarla no son lo mismo cuando la creó
            // otro módulo: un sueldo se ve con el permiso de salarios y se paga con el
            // de pagos de planilla.
            if ($negativa = $this->proteccion->motivoParaNoPagar($usuario, $gastos->pluck('id')->all())) {
                abort(403, $negativa);
            }
            // Sin catálogo canónico todavía, exige el mismo nombre exacto; no fusiona por parecido.
            if ($gastos->pluck('beneficiario')->unique()->count() !== 1 || $gastos->pluck('moneda')->unique()->count() !== 1) {
                throw ValidationException::withMessages(['pago' => 'Un pago debe corresponder al mismo destinatario y moneda.']);
            }
            $total = 0;
            foreach ($aplicaciones as $fila) {
                $importe = Dinero::centavos((string) $fila['importe']);
                if ($importe > $this->saldos->pendienteCuota($cuotas[$fila['cuota_id']])) {
                    throw ValidationException::withMessages(['pago' => 'El pago supera el pendiente de una cuota. Revisá el reparto.']);
                }
                $total += $importe;
            }
            if ($total !== Dinero::centavos((string) $datos['importe'])) {
                throw ValidationException::withMessages(['pago' => 'El reparto debe sumar exactamente el importe pagado.']);
            }
            $pago = Pago::create($datos + [
                'huella_peticion' => $huella, 'registrado_por' => $usuario->id,
                'beneficiario' => $gastos->first()->beneficiario, 'moneda' => $gastos->first()->moneda,
            ]);
            foreach ($aplicaciones as $fila) {
                DB::table('gastos_pago_aplicaciones')->insert([
                    'pago_id' => $pago->id, 'cuota_id' => $fila['cuota_id'],
                    'importe' => Dinero::decimal(Dinero::centavos((string) $fila['importe'])),
                ]);
            }
            DB::table('gastos_eventos')->insert([
                'pago_id' => $pago->id, 'usuario_id' => $usuario->id, 'accion' => 'pago_registrado',
                'datos' => json_encode($aplicaciones, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);

            return $pago;
        });
    }

    public function revertir(User $usuario, Pago $pago, string $motivo): void
    {
        abort_unless($usuario->activo && $usuario->can('gastos.pagos.corregir'), 403);
        Validator::make(['motivo' => trim($motivo)], ['motivo' => ['required', 'string', 'min:5', 'max:500']])->validate();
        DB::transaction(function () use ($usuario, $pago, $motivo) {
            User::whereKey($usuario->id)->lockForUpdate()->firstOrFail();
            $ids = DB::table('gastos_pago_aplicaciones')->where('pago_id', $pago->id)->pluck('cuota_id');
            Gasto::whereIn('id', Cuota::whereIn('id', $ids)->select('gasto_id'))->orderBy('id')->lockForUpdate()->get();
            Cuota::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $pago = Pago::whereKey($pago->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->acceso->pagoCompleto($usuario, $pago), 403);
            // `$negativa` y no `$motivo`: esta función YA tiene un `$motivo`, que es el
            // de la reversión y termina guardado en la fila. Reusar el nombre lo pisaba
            // con null y la reversión quedaba sin explicación. Lo cazaron dos pruebas.
            if ($negativa = $this->proteccion->motivoParaNoRevertir($usuario, $pago)) {
                abort(403, $negativa);
            }
            if ($pago->revertido_at) {
                return;
            }
            // Esto no es un permiso: es un nudo que hay que desatar antes. Va DESPUÉS
            // del corte por reversión para que deshacer dos veces siga siendo inocuo.
            if ($impedimento = $this->proteccion->impedimentoParaRevertir($pago)) {
                throw ValidationException::withMessages(['pago' => $impedimento]);
            }
            $pago->update(['revertido_at' => now(), 'revertido_por' => $usuario->id, 'motivo_reversion' => trim($motivo)]);
            DB::table('gastos_eventos')->insert([
                'pago_id' => $pago->id, 'usuario_id' => $usuario->id, 'accion' => 'pago_revertido',
                'datos' => json_encode(['motivo' => trim($motivo)], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        });
    }
}
