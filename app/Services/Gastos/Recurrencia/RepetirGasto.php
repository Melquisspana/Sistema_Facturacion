<?php

namespace App\Services\Gastos\Recurrencia;

use App\Models\Gastos\Gasto;
use App\Models\Gastos\Ocurrencia;
use App\Models\Gastos\Regla;
use App\Models\Gastos\ReglaVersion;
use App\Models\User;
use App\Services\Gastos\AccesoGastos;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * «Este gasto se repite»: convierte un gasto que YA EXISTE en el primero de una
 * serie, sin volver a capturar sus datos.
 *
 * ──────────────────────── Las tres cosas que no puede hacer ────────────────────────
 *
 *  1. NO DUPLICAR EL PERÍODO DEL GASTO. El gasto original ES el período que le
 *     toca. Se le crea su ocurrencia apuntando a él, así que la generación lo
 *     encuentra resuelto y nunca crea un gemelo. Sin esto, «el alquiler de
 *     septiembre» quedaría dos veces y alguien lo pagaría dos veces.
 *
 *  2. NO TOCAR SUS PAGOS. Este servicio no escribe una sola fila en `gastos_pagos`
 *     ni en `gastos_pago_aplicaciones`. Si el gasto ya estaba pagado, sigue pagado;
 *     si estaba a medias, sigue a medias. Convertirlo en recurrente es una decisión
 *     sobre el FUTURO y no puede reescribir lo que ya pasó.
 *
 *  3. NO GENERAR HISTÓRICOS. `vigente_desde` se fija al inicio del período del
 *     propio gasto, así que no hay ningún período anterior dentro del alcance de la
 *     regla. Convertir el alquiler de septiembre no puede inventar los de enero a
 *     agosto.
 *
 * ──────────────────────────── Qué se reutiliza ────────────────────────────
 *
 * Todo lo del gasto: destinatario, concepto, categoría, ámbito, persona,
 * naturaleza, moneda, documentación, observaciones, responsable e importe. Lo único
 * que se pregunta es CADA CUÁNTO y si el importe será siempre el mismo o cambiará.
 * El importe NO se vuelve a pedir: si es fijo, se toma el del gasto.
 *
 * Elegir «cambia cada vez» sobre un gasto que sí tiene monto es legítimo y está
 * permitido: el recibo de la luz de este mes tiene un importe concreto, y el del mes
 * que viene no se sabe. La regla guarda entonces importe NULL y los períodos
 * siguientes nacen esperando monto.
 *
 * IDEMPOTENTE. La clave de la regla se deriva (UUIDv5) de la del gasto, así que
 * pulsar dos veces el botón devuelve la misma repetición en vez de crear dos.
 */
final class RepetirGasto
{
    /** Espacio de nombres para derivar la clave de la regla desde la del gasto. */
    private const NS_REGLA = 'b7c2e1a4-95d3-4c6f-8e1b-3d5a7f9c0e24';

    public function __construct(
        private AccesoGastos $acceso,
        private CalendarioRecurrencia $calendario,
    ) {}

    /** La repetición de la que ya nació este gasto, si nació de alguna. */
    public function reglaDe(Gasto $gasto): ?Regla
    {
        return Ocurrencia::where('gasto_id', $gasto->id)->first()?->regla;
    }

    /**
     * Por qué este gasto NO se puede convertir, o null si sí se puede.
     *
     * El mensaje es para mostrarlo tal cual: quien mira la ficha tiene que entender
     * por qué no está el botón, no deducirlo.
     */
    public function motivoNoRepetible(Gasto $gasto): ?string
    {
        if ($this->reglaDe($gasto) !== null) {
            return 'Este gasto ya viene de uno que se repite.';
        }

        // Varias cuotas es un CALENDARIO DE PAGOS, no algo que se repita: no está
        // claro si lo que vuelve es el gasto entero o cada cuota. Se deja fuera a
        // propósito y se dice por qué, en vez de adivinar.
        if ($gasto->cuotas()->count() > 1) {
            return 'Este gasto está dividido en varias cuotas, que es un plan de pagos y no algo que se repita. '
                .'Para repetirlo, registrá uno con un solo vencimiento.';
        }

        return null;
    }

    /**
     * La fecha en la que «vive» el gasto, para saber qué período ocupa.
     *
     * Primero su vencimiento, que es lo que la gente entiende por «el alquiler de
     * septiembre». Si no tiene —un gasto que todavía espera monto no tiene cuota—,
     * el período declarado, y en último caso el día en que se registró.
     */
    public function anclaDe(Gasto $gasto): CarbonImmutable
    {
        $cuota = $gasto->cuotas()->first();

        $fecha = $cuota?->vence
            ?? $gasto->periodo_desde
            ?? $gasto->created_at;

        return CarbonImmutable::parse($fecha)->startOfDay();
    }

    /**
     * El período al que pertenece una fecha con una frecuencia dada, resuelto por el
     * mismo calendario que genera. No se reimplementa la aritmética acá: para
     * quincenal habría que decidir si el día cae en Q1 o Q2, y esa decisión ya está
     * tomada en un solo sitio.
     *
     * @param  array<string, mixed>  $calendario
     * @return array{periodo: string, vence: CarbonImmutable}
     */
    public function periodoDe(array $calendario, CarbonImmutable $ancla): array
    {
        $candidatos = $this->calendario->periodos($calendario, $ancla->subMonths(2), $ancla->addMonths(2));

        $mejor = null;
        $distancia = null;

        foreach ($candidatos as $candidato) {
            $d = abs($candidato['vence']->diffInDays($ancla));
            if ($distancia === null || $d < $distancia) {
                $distancia = $d;
                $mejor = $candidato;
            }
        }

        // Sin candidatos (no debería pasar con una frecuencia válida) el período se
        // resuelve por la propia fecha, para no quedarse sin ninguno.
        return $mejor ?? ['periodo' => $ancla->format('Y-m'), 'vence' => $ancla];
    }

    /**
     * El próximo vencimiento que esta repetición va a producir, sin contar los
     * períodos ya resueltos. Es lo que se muestra al confirmar y en la ficha: quien
     * configura una repetición necesita ver CUÁNDO cae la siguiente.
     *
     * @return array{periodo: string, vence: string}|null
     */
    public function proximoVencimiento(Regla $regla, CarbonImmutable $hoy): ?array
    {
        if (! $regla->activa()) {
            return null;
        }

        $hasta = $regla->vigente_hasta !== null
            ? CarbonImmutable::parse($regla->vigente_hasta->toDateString())
            : $hoy->addYears(2);

        $resueltos = Ocurrencia::where('regla_id', $regla->id)->pluck('periodo')->all();

        foreach ($this->calendario->periodos($regla->calendario(), $hoy->subDay(), $hasta) as $candidato) {
            if (! in_array($candidato['periodo'], $resueltos, true)) {
                return ['periodo' => $candidato['periodo'], 'vence' => $candidato['vence']->toDateString()];
            }
        }

        return null;
    }

    /**
     * Convierte el gasto. Devuelve la repetición creada —o la que ya existía, si se
     * repitió la acción—.
     *
     * @param  array<string, mixed>  $datos
     */
    public function desdeGasto(User $usuario, Gasto $gasto, array $datos): Regla
    {
        abort_unless($usuario->activo && $usuario->can('gastos.recurrencias'), 403);
        abort_unless($this->acceso->ver($usuario, $gasto), 403);

        $clave = Uuid::uuid5(self::NS_REGLA, $gasto->clave)->toString();

        // La idempotencia se comprueba PRIMERO. Después de convertirlo, el gasto queda
        // ligado a su propia repetición, así que `motivoNoRepetible()` diría —con
        // razón— que ya viene de una. Repetir la acción tiene que devolver lo mismo,
        // no fallar.
        if ($yaCreada = Regla::where('clave', $clave)->first()) {
            return $yaCreada;
        }

        if ($motivo = $this->motivoNoRepetible($gasto)) {
            throw ValidationException::withMessages(['repetir' => $motivo]);
        }

        $datos = $this->validar($gasto, $datos);

        return DB::transaction(function () use ($usuario, $gasto, $datos, $clave) {
            User::whereKey($usuario->id)->lockForUpdate()->firstOrFail();

            // Doble clic, o dos personas a la vez: la segunda encuentra la repetición
            // ya creada en vez de crear otra sobre el mismo gasto.
            if ($existente = Regla::where('clave', $clave)->first()) {
                return $existente;
            }

            $gasto = Gasto::whereKey($gasto->id)->lockForUpdate()->firstOrFail();

            $calendario = [
                'frecuencia' => $datos['frecuencia'],
                'dia_semana' => $datos['dia_semana'] ?? null,
                'dia_mes' => $datos['dia_mes'] ?? null,
                'dia_mes_2' => $datos['dia_mes_2'] ?? null,
                'mes' => $datos['mes'] ?? null,
            ];

            $ancla = $this->anclaDe($gasto);
            $periodo = $this->periodoDe($calendario, $ancla);

            $regla = Regla::create($calendario + [
                'clave' => $clave,
                // El nombre sale del gasto: no se pregunta otra cosa que ya está escrita.
                'nombre' => mb_substr($gasto->concepto, 0, 200),
                'beneficiario' => $gasto->beneficiario,
                'concepto' => $gasto->concepto,
                'categoria' => $gasto->categoria,
                'ambito' => $gasto->ambito,
                'persona' => $gasto->persona,
                'naturaleza' => $gasto->naturaleza,
                'moneda' => $gasto->moneda,
                'documentacion' => $gasto->documentacion,
                'observaciones' => $gasto->observaciones,
                'responsable_id' => $gasto->responsable_id,
                'monto_modo' => $datos['monto_modo'],
                // El importe NO se vuelve a pedir: sale del gasto, o es NULL si a
                // partir de ahora cambia cada vez.
                'importe' => $datos['monto_modo'] === 'fijo' ? $gasto->importe : null,
                'dias_generar_antes' => $datos['dias_generar_antes'],
                // Al inicio del período del propio gasto: así no hay ningún período
                // ANTERIOR dentro del alcance y no se pueden inventar históricos.
                'vigente_desde' => $this->inicioDelPeriodo($datos['frecuencia'], $periodo['vence'])->toDateString(),
                'vigente_hasta' => $datos['vigente_hasta'] ?? null,
                'estado' => 'activa',
                'version' => 1,
                'zona_horaria' => config('app.timezone'),
                'registrado_por' => $usuario->id,
                'generado_hasta' => $periodo['vence']->toDateString(),
            ]);

            ReglaVersion::create([
                'regla_id' => $regla->id,
                'version' => 1,
                'datos' => $regla->plantilla() + $regla->calendario(),
                'vigente_desde' => now()->toDateString(),
                'motivo' => 'Creada desde el gasto #'.$gasto->id.', que queda como su primer período.',
                'registrado_por' => $usuario->id,
                'created_at' => now(),
            ]);

            // EL CANDADO. El gasto ocupa su propio período: la generación lo va a
            // encontrar resuelto y no creará otro para esa fecha.
            Ocurrencia::create([
                'regla_id' => $regla->id,
                'periodo' => $periodo['periodo'],
                'vence' => $periodo['vence']->toDateString(),
                'estado' => 'generada',
                'gasto_id' => $gasto->id,
                'version_regla' => 1,
                'motivo' => null,
                'registrado_por' => $usuario->id,
                'created_at' => now(),
            ]);

            DB::table('gastos_eventos')->insert([
                'gasto_id' => $gasto->id,
                'regla_id' => $regla->id,
                'usuario_id' => $usuario->id,
                'accion' => 'gasto_convertido_en_repeticion',
                'datos' => json_encode([
                    'frecuencia' => $regla->frecuencia,
                    'cuando' => $this->calendario->enPalabras($regla->calendario()),
                    'monto_modo' => $regla->monto_modo,
                    'periodo_que_ocupa' => $periodo['periodo'],
                    // Se deja dicho en la bitácora, porque es la garantía que importa.
                    'pagos_intactos' => true,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $regla->fresh();
        });
    }

    /** Primer día del tramo de calendario al que pertenece un vencimiento. */
    private function inicioDelPeriodo(string $frecuencia, CarbonImmutable $vence): CarbonImmutable
    {
        return match ($frecuencia) {
            'semanal' => $vence->startOfWeek(),
            'anual' => $vence->startOfYear(),
            default => $vence->startOfMonth(),
        };
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function validar(Gasto $gasto, array $datos): array
    {
        $frecuencia = $datos['frecuencia'] ?? null;

        $validados = Validator::make($datos, [
            'frecuencia' => ['required', Rule::in(array_keys(CalendarioRecurrencia::FRECUENCIAS))],
            'monto_modo' => ['required', Rule::in(array_keys(Regla::MONTO_MODOS))],
            'dia_semana' => [Rule::requiredIf($frecuencia === 'semanal'), 'nullable', 'integer', 'between:1,7'],
            'dia_mes' => [Rule::requiredIf(in_array($frecuencia, ['quincenal', 'mensual', 'anual'], true)), 'nullable', 'integer', 'between:1,31'],
            'dia_mes_2' => [Rule::requiredIf($frecuencia === 'quincenal'), 'nullable', 'integer', 'between:1,31', 'gt:dia_mes'],
            'mes' => [Rule::requiredIf($frecuencia === 'anual'), 'nullable', 'integer', 'between:1,12'],
            'dias_generar_antes' => ['required', 'integer', 'between:0,60'],
            'vigente_hasta' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'dia_mes_2.gt' => 'La segunda fecha del mes tiene que ser posterior a la primera.',
        ])->validate();

        // «Siempre el mismo importe» necesita un importe, y el único que hay es el del
        // gasto. Si todavía no lo tiene, la única opción honesta es «cambia cada vez».
        if ($validados['monto_modo'] === 'fijo' && $gasto->montoDesconocido()) {
            throw ValidationException::withMessages([
                'monto_modo' => 'Este gasto todavía no tiene importe, así que no se puede repetir con uno fijo. '
                    .'Elegí «cambia cada vez», o completá primero el monto de este gasto.',
            ]);
        }

        return $validados;
    }
}
