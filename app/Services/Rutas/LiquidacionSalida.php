<?php

namespace App\Services\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\MotivoNoEntrega;
use App\Enums\OrigenRegistroEntrega;
use App\Enums\ResultadoEntrega;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «Terminar ruta»: la liquidación de la salida completa. En una salida de varios días el
 * dinero se entrega una sola vez, al volver: lo cobrado en todos los días menos los gastos
 * de todos los días. Los importes salen de los cierres de cada día ({@see CierreDia}), así
 * que antes de liquidar todo día con entregas o gastos tiene que estar terminado, y lo que
 * sobró (no se entregó) lleva su motivo.
 *
 * Liquidada, la salida queda finalizada y sus días, entregas y gastos ya no se tocan.
 */
class LiquidacionSalida
{
    public function __construct(private readonly EntregasCcf $entregas) {}

    /**
     * Lo que sigue sin marcar y necesita motivo. No cuentan los que ya tienen albarán de
     * entrega (constan entregados) ni los que dejaron de estar vigentes (no se entregan).
     *
     * @return Collection<int, SalidaRutaEntrega>
     */
    public function sinMarcar(SalidaRuta $salida): Collection
    {
        $pendientes = $salida->entregas()->pendientes()->with(['dte', 'sala'])->orderBy('cliente_sucursal_id')->orderBy('id')->get();
        $resoluciones = $this->entregas->resolucionesAlbaran($pendientes);
        $noVigentes = $this->entregas->noVigentes($pendientes);

        return $pendientes->reject(fn ($e) => isset($noVigentes[$e->dte_id]) || ($resoluciones[$e->dte_id] ?? null)?->estaVinculado())->values();
    }

    /**
     * Días con entregas o gastos que todavía no se terminaron.
     *
     * @return array<int, string> fechas Y-m-d
     */
    public function diasSinTerminar(SalidaRuta $salida): array
    {
        // Un facturado después de entregar no obliga a terminar su día: no tiene dinero.
        $fechas = $salida->entregas()->entregadas()->sinFacturadosDespues()->pluck('fecha_resultado')->map(fn ($f) => $f->toDateString())
            ->merge($salida->gastos()->pluck('fecha')->map(fn ($f) => $f->toDateString()))
            ->unique()->sort()->values();
        $cerrados = $salida->cierres()->get()->filter->estaCerrado()->map(fn ($c) => $c->fecha->toDateString())->all();

        return $fechas->reject(fn ($f) => in_array($f, $cerrados, true))->values()->all();
    }

    /**
     * Los cierres de cada día y la suma de todos, en centavos.
     *
     * @return array{dias: Collection, efectivo: int, cheques: int, transferencias: int, gastos: int, a_entregar: int}
     */
    public function totales(SalidaRuta $salida): array
    {
        $dias = $salida->cierres()->orderBy('fecha')->get()->filter->estaCerrado()->values();
        $suma = fn (string $campo) => $dias->sum(fn ($c) => CierreDia::centavos($c->{$campo}));
        $efectivo = $suma('efectivo_cobrado');
        $gastos = $suma('gastos_total');

        return [
            'dias' => $dias,
            'efectivo' => $efectivo,
            'cheques' => $suma('cheques_cobrado'),
            'transferencias' => $suma('transferencias_cobrado'),
            'gastos' => $gastos,
            'a_entregar' => $efectivo - $gastos,
        ];
    }

    /** Lo que no se entregó en la salida, con su motivo. Para la pantalla y el PDF. */
    public function noEntregadas(SalidaRuta $salida): Collection
    {
        return $salida->entregas()->where('resultado', ResultadoEntrega::NoEntregado->value)
            ->with(['dte', 'sala'])->orderBy('cliente_sucursal_id')->orderBy('id')->get();
    }

    /**
     * Anota el motivo de lo que sobró, comprueba que todos los días estén terminados,
     * finaliza la salida y deja constancia de quién entrega el dinero.
     *
     * @param  array{motivos?: array<int|string, array{motivo?: string|null, nota?: string|null}>, entrega_dinero_personal_id?: int|string|null}  $datos
     *
     * @throws ValidationException
     */
    public function liquidar(SalidaRuta $salida, array $datos, User $usuario): SalidaRuta
    {
        return DB::transaction(function () use ($salida, $datos, $usuario) {
            $salida = SalidaRuta::lockForUpdate()->findOrFail($salida->id);
            if ($salida->estaLiquidada()) {
                throw ValidationException::withMessages(['salida' => 'Esta ruta ya está terminada.']);
            }
            if ($salida->estado !== EstadoSalidaRuta::EnCurso) {
                throw ValidationException::withMessages(['salida' => 'Solo se termina una ruta en curso.']);
            }

            $sinMarcar = $this->sinMarcar($salida);
            $reglas = ['motivos' => ['sometimes', 'array'], 'entrega_dinero_personal_id' => ['nullable', 'integer',
                Rule::in($salida->personal()->where('activo', true)->pluck('rutas_personal.id')->all())]];
            foreach ($sinMarcar as $entrega) {
                $reglas["motivos.{$entrega->id}.motivo"] = ['required', Rule::enum(MotivoNoEntrega::class)];
                $reglas["motivos.{$entrega->id}.nota"] = ['nullable', 'string', 'max:300', "required_if:motivos.{$entrega->id}.motivo,".MotivoNoEntrega::Otro->value];
            }
            $validados = Validator::make($datos, $reglas, [
                'motivos.*.motivo.required' => 'Indicá por qué no se entregó cada CCF que sobró.',
                'motivos.*.nota.required_if' => 'Con «Otro», escribí qué pasó.',
            ])->validate();
            if (array_diff(array_map('strval', array_keys($datos['motivos'] ?? [])), array_map('strval', $sinMarcar->modelKeys()))) {
                throw ValidationException::withMessages(['motivos' => 'Solo se anota el motivo de los CCF que sobraron en esta salida.']);
            }

            if ($dias = $this->diasSinTerminar($salida)) {
                throw ValidationException::withMessages(['dias' => 'Primero terminá el día: '.collect($dias)
                    ->map(fn ($f) => Carbon::parse($f)->format('d/m/Y'))->join(', ', ' y ').'.']);
            }

            foreach ($sinMarcar as $entrega) {
                $fila = $validados['motivos'][$entrega->id];
                $this->entregas->registrar($entrega, [
                    'resultado' => ResultadoEntrega::NoEntregado->value,
                    'motivo_no_entrega' => $fila['motivo'],
                    'nota' => $fila['nota'] ?? null,
                ], $usuario, OrigenRegistroEntrega::Oficina);
            }

            $totales = $this->totales($salida);
            $hayCobro = $totales['efectivo'] + $totales['cheques'] + $totales['transferencias'] > 0;
            if ($hayCobro && empty($validados['entrega_dinero_personal_id'])) {
                throw ValidationException::withMessages(['entrega_dinero_personal_id' => 'Elegí quién entrega el dinero entre los que van en la salida.']);
            }

            $salida->finalizar();
            $salida->update([
                'entrega_dinero_personal_id' => $hayCobro ? (int) $validados['entrega_dinero_personal_id'] : null,
                'liquidada_por' => $usuario->id,
                'liquidada_en' => now(),
            ]);
            activity('salida_ruta')->performedOn($salida)->causedBy($usuario)
                ->withProperties(['a_entregar' => CierreDia::decimal($totales['a_entregar']), 'gastos' => CierreDia::decimal($totales['gastos'])])
                ->log('terminó la ruta');

            return $salida;
        });
    }
}
