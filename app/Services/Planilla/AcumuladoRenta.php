<?php

namespace App\Services\Planilla;

use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Planilla\PlanillaRentaAcumulado;
use App\Models\Planilla\PlanillaSueldo;
use App\Models\User;
use App\Services\Gastos\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lo gravado y la renta retenida en el año, que pide el recálculo de junio y diciembre.
 *
 * Sale de dos lados: lo que una persona confirmó para los meses ANTERIORES al sistema
 * ({@see PlanillaRentaAcumulado}) y las quincenas y mensuales normales confirmadas del
 * sistema posteriores a esa cifra; una mensual cuenta como dos quincenas. Las anuladas y los borradores no cuentan: ahí no se pagó.
 *
 * SIN CIFRA CONFIRMADA NO HAY RECÁLCULO. Solo se prescinde de ella cuando el sistema
 * tiene a la persona en todas las quincenas del año hasta esta. Si no, la planilla no
 * se genera: una renta calculada con medio año sería un número que nadie revisó.
 */
final class AcumuladoRenta
{
    public function __construct(
        private DescuentosLey $descuentos,
        private SueldoHabitual $sueldos,
    ) {}

    /**
     * Lo acumulado de esta persona antes de la planilla, o qué falta para tenerlo.
     *
     * @return array{listo: bool, falta: ?string, gravado: int, renta: int}
     */
    public function paraRecalculo(PlanillaEmpleado $empleado, Planilla $planilla): array
    {
        $anio = $planilla->hasta->year;
        $desde = $planilla->desde->toDateString();
        $fila = PlanillaRentaAcumulado::where('planilla_empleado_id', $empleado->id)->where('anio', $anio)->first();

        if ($fila !== null) {
            if ($fila->hasta->toDateString() >= $desde) {
                return $this->falta('lo confirmado antes del sistema llega hasta el '.$fila->hasta->format('d/m/Y')
                    .', que ya es esta quincena o después. Corregilo en «Renta antes del sistema»');
            }

            $sistema = $this->delSistema($empleado, $anio, $desde, $fila->hasta->toDateString());
            $entreMedio = $this->quincenaDelAno($planilla->desde->toImmutable()) - $this->quincenaDelAno($fila->hasta->toImmutable()) - 1;

            // Entre lo confirmado y esta quincena no puede quedar un hueco sin contar.
            if ($sistema['quincenas'] < $entreMedio) {
                return $this->falta('lo confirmado antes del sistema llega hasta el '.$fila->hasta->format('d/m/Y')
                    .' y después faltan quincenas en el sistema. Corregilo en «Renta antes del sistema»');
            }

            return [
                'listo' => true,
                'falta' => null,
                'gravado' => Dinero::centavos((string) $fila->gravado) + $sistema['gravado'],
                'renta' => Dinero::centavos((string) $fila->renta_retenida) + $sistema['renta'],
            ];
        }

        $sistema = $this->delSistema($empleado, $anio, $desde, null);

        if ($sistema['quincenas'] < $this->quincenaDelAno($planilla->desde->toImmutable())) {
            return $this->falta('falta confirmar lo gravado y la renta de '.$anio
                .' antes del sistema, para el recálculo. Hacelo en «Renta antes del sistema»');
        }

        return ['listo' => true, 'falta' => null, 'gravado' => $sistema['gravado'], 'renta' => $sistema['renta']];
    }

    /**
     * Lo que se propone confirmar: el sueldo habitual repetido en cada quincena que el
     * sistema no tiene, desde enero (o desde el alta, si fue en el año).
     *
     * `corte` es hasta dónde proponer cuando la persona todavía no tiene ninguna
     * quincena en el sistema ese año. Null si el sistema la tiene desde el 1 de enero.
     *
     * @return array{hasta: string, quincenas: int, gravado: int, renta: int}|null
     */
    public function propuesta(PlanillaEmpleado $empleado, int $anio, string $corte): ?array
    {
        $primera = $this->consultaDelSistema($empleado, $anio)->min('planillas.desde');

        if ($primera !== null && CarbonImmutable::parse($primera)->toDateString() === $anio.'-01-01') {
            return null;
        }

        $hasta = CarbonImmutable::parse($primera ?? $corte)->subDay();
        $inicio = CarbonImmutable::create($anio, 1, 1);

        if ($empleado->alta_el !== null && $empleado->alta_el->year === $anio) {
            $inicio = $empleado->alta_el->toImmutable();
        }

        $quincenas = $hasta->lt($inicio) ? 0 : $this->quincenaDelAno($hasta) + 1 - $this->quincenaDelAno($inicio);

        // El sueldo de siempre. Si el historial empieza después (se cargó al llegar al
        // sistema), se toma el primero que hay: casi nunca cambia de una quincena a otra.
        $base = $this->sueldos->vigenteEn($empleado, $hasta->toDateString())
            ?? $this->primerSueldo($empleado)
            ?? 0;

        $acumulado = $this->descuentos->proponerAcumulado($base, $quincenas, $hasta->toDateString())
            ?? ['gravado' => 0, 'renta' => 0];

        return ['hasta' => $hasta->toDateString(), 'quincenas' => $quincenas] + $acumulado;
    }

    /**
     * Guarda la cifra que una persona revisó. Una por persona y año: confirmar otra vez
     * la corrige. La bitácora dice quién y de quién, nunca cuánto: la ve quien no tiene
     * `planilla.salarios`.
     *
     * @param  array<string, mixed>  $datos
     */
    public function confirmar(User $usuario, array $datos): PlanillaRentaAcumulado
    {
        abort_unless($usuario->activo && $usuario->can('planilla.gestionar') && $usuario->can('planilla.salarios'), 403);

        $importe = ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'];
        $datos = Validator::make($datos, [
            'planilla_empleado_id' => ['required', 'integer', Rule::exists('planilla_empleados', 'id')],
            'anio' => ['required', 'integer', 'min:2025', 'max:'.now()->year],
            'hasta' => ['required', 'date_format:Y-m-d'],
            'gravado' => $importe,
            'renta_retenida' => $importe,
            'observaciones' => ['nullable', 'string', 'max:500'],
        ], [
            'gravado.regex' => 'Escribí lo gravado con hasta dos decimales, sin comas.',
            'renta_retenida.regex' => 'Escribí la renta con hasta dos decimales, sin comas.',
        ])->validate();

        $empleado = PlanillaEmpleado::findOrFail($datos['planilla_empleado_id']);
        $anio = (int) $datos['anio'];

        if (! $empleado->con_descuentos_ley) {
            throw ValidationException::withMessages(['planilla_empleado_id' => $empleado->nombre.' va sin descuentos de ley: no lleva recálculo de renta.']);
        }

        if (CarbonImmutable::parse($datos['hasta'])->year !== $anio) {
            throw ValidationException::withMessages(['hasta' => 'La fecha tiene que ser del mismo año, '.$anio.'.']);
        }

        if (Dinero::centavos($datos['renta_retenida']) > Dinero::centavos($datos['gravado'])) {
            throw ValidationException::withMessages(['renta_retenida' => 'La renta retenida no puede ser mayor que lo gravado.']);
        }

        return DB::transaction(function () use ($usuario, $datos, $empleado, $anio) {
            $fila = PlanillaRentaAcumulado::where('planilla_empleado_id', $empleado->id)->where('anio', $anio)->lockForUpdate()->first();
            $nueva = $fila === null;

            $fila ??= new PlanillaRentaAcumulado(['planilla_empleado_id' => $empleado->id, 'anio' => $anio]);
            $fila->fill([
                'hasta' => $datos['hasta'],
                'gravado' => Dinero::decimal(Dinero::centavos($datos['gravado'])),
                'renta_retenida' => Dinero::decimal(Dinero::centavos($datos['renta_retenida'])),
                'observaciones' => trim((string) ($datos['observaciones'] ?? '')) ?: null,
                'registrado_por' => $usuario->id,
            ])->save();

            activity('planilla')->performedOn($fila)->causedBy($usuario)
                ->withProperties(['planilla_empleado_id' => $empleado->id, 'anio' => $anio])
                ->log($nueva ? 'confirmó la renta antes del sistema' : 'corrigió la renta antes del sistema');

            return $fila;
        });
    }

    /**
     * Las quincenas y mensuales normales confirmadas del año, antes de `antesDe` y, si se da,
     * después de `despuesDe`. Lo gravado de cada una es lo que cotizó menos ISSS y AFP,
     * con los importes que quedaron guardados.
     *
     * @return array{gravado: int, renta: int, quincenas: int}
     */
    private function delSistema(PlanillaEmpleado $empleado, int $anio, string $antesDe, ?string $despuesDe): array
    {
        $filas = $this->consultaDelSistema($empleado, $anio)
            ->whereDate('planillas.hasta', '<', $antesDe)
            ->when($despuesDe !== null, fn ($q) => $q->whereDate('planillas.desde', '>', $despuesDe))
            ->get(['planilla_detalles.id', 'planillas.tipo_periodo']);
        $ids = $filas->pluck('id');

        $gravado = 0;
        $renta = 0;

        foreach (PlanillaDetalle::whereIn('id', $ids)->with('conceptos')->get() as $detalle) {
            $deLey = $detalle->conceptos->whereNotNull('ley')->keyBy('ley')
                ->map(fn ($c) => Dinero::centavos((string) $c->importe));

            $base = $this->descuentos->baseDeLinea(Dinero::centavos((string) $detalle->salario), $detalle->conceptos->toArray())['base'];
            $gravado += $base - ($deLey['isss'] ?? 0) - ($deLey['afp'] ?? 0);
            $renta += $deLey['renta'] ?? 0;
        }

        // Una mensual paga las dos quincenas de su mes.
        return ['gravado' => $gravado, 'renta' => $renta, 'quincenas' => $filas->sum(fn ($f) => $f->tipo_periodo === 'mensual' ? 2 : 1)];
    }

    private function consultaDelSistema(PlanillaEmpleado $empleado, int $anio)
    {
        return DB::table('planilla_detalles')
            ->join('planillas', 'planillas.id', '=', 'planilla_detalles.planilla_id')
            ->where('planilla_detalles.planilla_empleado_id', $empleado->id)
            ->where('planillas.estado', 'confirmada')
            ->whereIn('planillas.tipo_periodo', ['quincenal', 'mensual'])
            ->where('planillas.clase', 'regular')
            ->whereDate('planillas.desde', '>=', $anio.'-01-01')
            ->whereDate('planillas.desde', '<=', $anio.'-12-31');
    }

    /** Cuántas quincenas del año hay antes de la que contiene esta fecha: 0 para el 1 de enero. */
    private function quincenaDelAno(CarbonImmutable $fecha): int
    {
        return ($fecha->month - 1) * 2 + ($fecha->day > 15 ? 1 : 0);
    }

    private function primerSueldo(PlanillaEmpleado $empleado): ?int
    {
        $importe = PlanillaSueldo::where('planilla_empleado_id', $empleado->id)
            ->orderBy('vigente_desde')->orderBy('id')->value('importe');

        return $importe === null ? null : Dinero::centavos((string) $importe);
    }

    /** @return array{listo: bool, falta: string, gravado: int, renta: int} */
    private function falta(string $motivo): array
    {
        return ['listo' => false, 'falta' => $motivo, 'gravado' => 0, 'renta' => 0];
    }
}
