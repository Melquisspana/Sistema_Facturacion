<?php

namespace App\Services\Planilla;

use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaAnticipo;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaObligacionTercero;
use App\Models\User;
use App\Services\Gastos\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Confirmar una planilla: convertirla en obligaciones reales.
 *
 * Es el ÚNICO acto que crea deuda en este módulo. Mientras la planilla es borrador no
 * existe ni una obligación, ni con los empleados ni con los terceros.
 *
 * ═══════════ Lo que crea, y por qué son dos cosas distintas ═══════════
 *
 *   POR EMPLEADO   una obligación por lo que se le paga (`a_pagar`). Es lo único que
 *                  esa persona va a cobrar.
 *   POR TERCERO    una obligación por tercero, sumando lo que se le entrega en TODA la
 *                  planilla. A la cooperativa se le hace un pago por la cuota de todos,
 *                  no uno por empleado.
 *
 * Las dos juntas, más los anticipos recuperados, suman el total de ingresos. Nunca se
 * crea una obligación por el total de ingresos: eso contaría el dinero dos veces.
 *
 * ═══════════ Por qué no se puede duplicar ═══════════
 *
 * Tres candados, todos en la base y ninguno una comprobación previa:
 *
 *  1. `planilla_detalles.gasto_id` es ÚNICO. Un detalle no puede apuntar a dos deudas.
 *  2. `planilla_obligaciones_terceros` es único por planilla y tercero.
 *  3. Las claves de los gastos se DERIVAN (UUIDv5) de la planilla y del detalle o del
 *     nombre del tercero, contra el índice único de `gastos.clave`.
 *
 * Y todo corre dentro de una transacción con la planilla bloqueada (`lockForUpdate`),
 * así que dos personas confirmando a la vez se serializan: la segunda encuentra la
 * planilla ya confirmada y devuelve lo mismo en vez de crear otra tanda.
 *
 * ═══════════ Los anticipos ═══════════
 *
 * Un descuento de anticipo no se limita a restar: crea una APLICACIÓN contra el
 * anticipo, que es lo que hace que no se pueda recuperar dos veces. Se comprueba aquí
 * —dentro de la transacción y con el anticipo bloqueado— que lo que se recupera no
 * supere lo que queda pendiente.
 *
 * NO CALCULA NADA LEGAL. Reparte importes que una persona escribió y revisó.
 */
final class ConfirmarPlanilla
{
    private const NS_EMPLEADO = 'c3a91f70-5d2b-4a18-9f3e-6b0c8d2e4a71';

    private const NS_TERCERO = 'd41d8cd9-8f00-4204-a980-0998ecf8427e';

    public function __construct(
        private PrepararPlanilla $preparar,
        private TotalesPlanilla $totales,
        private VacacionesAnuales $vacaciones,
    ) {}

    public function confirmar(User $usuario, Planilla $planilla): Planilla
    {
        abort_unless($usuario->activo && $usuario->can('planilla.gestionar'), 403);
        abort_unless($usuario->can('planilla.salarios'), 403);

        try {
            return DB::transaction(function () use ($usuario, $planilla) {
                $planilla = Planilla::whereKey($planilla->id)->lockForUpdate()->firstOrFail();

                // Ya confirmada: se devuelve tal cual. Un reintento no puede crear una
                // segunda tanda de obligaciones.
                if ($planilla->confirmada()) {
                    return $planilla;
                }

                if (! $planilla->borrador()) {
                    throw ValidationException::withMessages([
                        'estado' => 'Esta planilla está '.mb_strtolower(Planilla::ESTADOS[$planilla->estado]).' y no se puede confirmar.',
                    ]);
                }

                $planilla->load('detalles.conceptos');

                if ($reparos = $this->preparar->reparosParaConfirmar($planilla)) {
                    throw ValidationException::withMessages(['reparos' => $reparos]);
                }

                $this->comprobarAnticipos($planilla);
                $this->fijarAnioDeServicio($planilla);

                $creadas = 0;
                foreach ($planilla->detalles as $detalle) {
                    $creadas += $this->obligacionDelEmpleado($usuario, $planilla, $detalle) ? 1 : 0;
                    $this->aplicarAnticipos($detalle);
                }

                $terceros = $this->obligacionesDeTerceros($usuario, $planilla);

                $planilla->update([
                    'estado' => 'confirmada',
                    'confirmada_at' => now(),
                    'confirmada_por' => $usuario->id,
                ]);

                $t = $this->totales->dePlanilla($planilla->fresh()->load('detalles.conceptos'));

                DB::table('gastos_eventos')->insert([
                    'usuario_id' => $usuario->id,
                    'accion' => 'planilla_confirmada',
                    'datos' => json_encode([
                        'planilla_id' => $planilla->id,
                        'periodo' => $planilla->periodo,
                        'obligaciones_empleados' => $creadas,
                        'obligaciones_terceros' => $terceros,
                        'total_ingresos' => Dinero::decimal($t['total_ingresos']),
                        'a_pagar' => Dinero::decimal($t['a_pagar']),
                        'a_terceros' => Dinero::decimal($t['a_terceros']),
                        'anticipos_recuperados' => Dinero::decimal($t['anticipos']),
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                return $planilla->fresh();
            });
        } catch (QueryException $e) {
            // La transacción ya se deshizo. Si fue porque otro proceso confirmó esta misma
            // planilla mientras tanto, se devuelve lo que quedó. Cualquier otro error de
            // integridad es un error de verdad: no se disfraza de confirmación.
            $ahora = $planilla->fresh();

            if ($e->getCode() === '23000' && $ahora?->confirmada()) {
                return $ahora;
            }

            throw $e;
        }
    }

    /**
     * Que ningún anticipo se recupere por encima de lo que queda pendiente. Se hace
     * DENTRO de la transacción y bloqueando el anticipo: dos planillas confirmándose a
     * la vez no pueden repartirse el mismo saldo dos veces.
     */
    private function comprobarAnticipos(Planilla $planilla): void
    {
        $porAnticipo = [];

        foreach ($planilla->detalles as $detalle) {
            foreach ($detalle->conceptos as $concepto) {
                if ($concepto->destino !== 'anticipo' || $concepto->planilla_anticipo_id === null) {
                    continue;
                }

                $porAnticipo[$concepto->planilla_anticipo_id] ??= 0;
                $porAnticipo[$concepto->planilla_anticipo_id] += Dinero::centavos((string) $concepto->importe);
            }
        }

        // Orden de los candados: planilla, anticipo, pago. Revertir un pago toma el pago y
        // no el anticipo, así que nunca se esperan en orden cruzado.
        foreach ($porAnticipo as $anticipoId => $recupera) {
            $anticipo = PlanillaAnticipo::whereKey($anticipoId)->lockForUpdate()->firstOrFail();

            // El pago pudo revertirse después de los reparos: se mira con candado, que lee
            // lo último confirmado, y la aplicación se inserta recién después.
            if ($anticipo->pago_id !== null) {
                $pago = Pago::whereKey($anticipo->pago_id)->lockForUpdate()->first();

                if ($pago === null || $pago->revertido_at !== null) {
                    throw ValidationException::withMessages([
                        'anticipos' => 'El pago del anticipo del '.$anticipo->fecha->format('d/m/Y')
                            .' fue revertido; no se puede descontar.',
                    ]);
                }
            }

            // Con candado: sin él, otra planilla confirmada mientras tanto no se vería.
            $pendiente = $anticipo->pendiente(bloquear: true);

            if ($recupera > $pendiente) {
                throw ValidationException::withMessages([
                    'anticipos' => 'Del anticipo del '.$anticipo->fecha->format('d/m/Y').' quedan '
                        .Dinero::decimal($pendiente).' por recuperar y esta planilla descuenta '
                        .Dinero::decimal($recupera).'. No se puede recuperar dos veces el mismo anticipo.',
                ]);
            }
        }
    }

    /** Deja registrado cuánto de cada anticipo recuperó cada descuento. */
    private function aplicarAnticipos(PlanillaDetalle $detalle): void
    {
        foreach ($detalle->conceptos as $concepto) {
            if ($concepto->destino !== 'anticipo' || $concepto->planilla_anticipo_id === null) {
                continue;
            }

            // insertOrIgnore contra el índice único del concepto: si ya se aplicó, no
            // se aplica otra vez.
            DB::table('planilla_anticipo_aplicaciones')->insertOrIgnore([
                'planilla_anticipo_id' => $concepto->planilla_anticipo_id,
                'planilla_concepto_id' => $concepto->id,
                'importe' => $concepto->importe,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Vacaciones: el año de servicio que se paga queda escrito en la línea al confirmar,
     * en `periodo_desde` y `periodo_hasta`. Así el recibo firmado no cambia si después se
     * corrige la fecha de ingreso de la ficha. Se escribe siempre el calculado: nada que
     * haya llegado de un formulario o de un borrador viejo cuenta como año pagado.
     */
    private function fijarAnioDeServicio(Planilla $planilla): void
    {
        // Indemnización: lo mismo con el tiempo de servicio, del ingreso a la baja.
        if ($planilla->esIndemnizacion()) {
            foreach ($planilla->detalles as $detalle) {
                $e = $detalle->empleado;

                $detalle->update($e?->alta_el === null || $e->baja_el === null
                    ? ['periodo_desde' => null, 'periodo_hasta' => null]
                    : ['periodo_desde' => $e->alta_el->toDateString(), 'periodo_hasta' => CarbonImmutable::parse((string) $e->baja_el)->toDateString()]);
            }

            return;
        }

        if (! $planilla->esVacaciones()) {
            return;
        }

        foreach ($planilla->detalles as $detalle) {
            $anio = $detalle->empleado === null ? null : $this->vacaciones->anioDeServicio($detalle->empleado, $planilla->desde->toImmutable());

            // Sin año calculable (los reparos ya lo habrían frenado) queda vacío, no lo de antes.
            $detalle->update($anio === null
                ? ['periodo_desde' => null, 'periodo_hasta' => null]
                : ['periodo_desde' => $anio['desde']->toDateString(), 'periodo_hasta' => $anio['hasta']->toDateString()]);
        }
    }

    /** La obligación con la persona por lo que se le paga. */
    private function obligacionDelEmpleado(User $usuario, Planilla $planilla, PlanillaDetalle $detalle): bool
    {
        if ($detalle->gasto_id !== null) {
            return false;
        }

        $aPagar = $this->totales->deDetalle($detalle)['a_pagar'];

        // Cero a pagar —todo se fue en descuentos— no crea obligación: no hay nada que
        // deber. Los descuentos a terceros de esa persona sí generan la suya.
        if ($aPagar <= 0) {
            return false;
        }

        $clave = Uuid::uuid5(self::NS_EMPLEADO, $planilla->clave.'|'.$detalle->id)->toString();

        $gasto = Gasto::firstOrCreate(['clave' => $clave], [
            'huella_peticion' => hash('sha256', $clave),
            'beneficiario' => $detalle->nombre_snapshot,
            // «Vacaciones · Ana», «Indemnización · Ana», «Prestación por renuncia · Ana».
            'concepto' => ($planilla->esIndividual() ? Planilla::CLASES[$planilla->clase] : 'Planilla '.$planilla->periodo).' · '.$detalle->nombre_snapshot,
            'categoria' => 'Planilla',
            'ambito' => 'empresarial',
            'naturaleza' => 'operativo',
            'moneda' => $planilla->moneda,
            'importe' => Dinero::decimal($aPagar),
            // Pago individual: el mes de `desde` es solo su clave técnica, no un período pagado.
            'periodo_desde' => $planilla->esIndividual() ? null : $planilla->desde,
            'periodo_hasta' => $planilla->esIndividual() ? null : $planilla->hasta,
            'documentacion' => 'pendiente',
            'responsable_id' => $planilla->registrado_por,
            'registrado_por' => $usuario->id,
        ]);

        if ($gasto->cuotas()->count() === 0) {
            $gasto->cuotas()->create([
                'numero' => 1,
                'importe' => Dinero::decimal($aPagar),
                'vence' => ($planilla->fecha_pago ?? $planilla->hasta)->toDateString(),
            ]);
        }

        $detalle->update(['gasto_id' => $gasto->id]);

        return true;
    }

    /**
     * Una obligación por tercero, sumando toda la planilla.
     *
     * @return int cuántas se crearon
     */
    private function obligacionesDeTerceros(User $usuario, Planilla $planilla): int
    {
        $porTercero = [];

        foreach ($planilla->detalles as $detalle) {
            foreach ($detalle->conceptos as $concepto) {
                if (! $concepto->generaDeudaConTercero()) {
                    continue;
                }

                // Se queda el nombre como lo escribió la primera línea; la suma es de todas.
                $clave = self::claveDeTercero($concepto->tercero);
                $porTercero[$clave] ??= ['nombre' => Str::squish($concepto->tercero), 'importe' => 0];
                $porTercero[$clave]['importe'] += Dinero::centavos((string) $concepto->importe);
            }
        }

        // Las que ya hubiera, con la MISMA clave: buscarlas con la comparación de la base
        // (que en MySQL no distingue mayúsculas ni tildes) saltaba la segunda en silencio.
        $existentes = PlanillaObligacionTercero::where('planilla_id', $planilla->id)->pluck('tercero')
            ->map(fn (string $t) => self::claveDeTercero($t))->all();

        $creadas = 0;

        foreach ($porTercero as $claveTercero => ['nombre' => $nombre, 'importe' => $importe]) {
            if ($importe <= 0 || in_array((string) $claveTercero, $existentes, true)) {
                continue;
            }

            $clave = Uuid::uuid5(self::NS_TERCERO, $planilla->clave.'|'.$claveTercero)->toString();

            $gasto = Gasto::firstOrCreate(['clave' => $clave], [
                'huella_peticion' => hash('sha256', $clave),
                'beneficiario' => $nombre,
                'concepto' => $planilla->esIndividual()
                    ? 'Descuentos de '.mb_strtolower(Planilla::CLASES[$planilla->clase]).' de '.$planilla->detalles->first()?->nombre_snapshot
                    : 'Descuentos de planilla '.$planilla->periodo,
                'categoria' => 'Planilla',
                'ambito' => 'empresarial',
                'naturaleza' => 'operativo',
                'moneda' => $planilla->moneda,
                'importe' => Dinero::decimal($importe),
                'periodo_desde' => $planilla->esIndividual() ? null : $planilla->desde,
                'periodo_hasta' => $planilla->esIndividual() ? null : $planilla->hasta,
                'documentacion' => 'pendiente',
                'responsable_id' => $planilla->registrado_por,
                'registrado_por' => $usuario->id,
            ]);

            if ($gasto->cuotas()->count() === 0) {
                $gasto->cuotas()->create([
                    'numero' => 1,
                    'importe' => Dinero::decimal($importe),
                    'vence' => ($planilla->fecha_pago ?? $planilla->hasta)->toDateString(),
                ]);
            }

            PlanillaObligacionTercero::create([
                'planilla_id' => $planilla->id,
                'tercero' => $nombre,
                'importe' => Dinero::decimal($importe),
                'gasto_id' => $gasto->id,
                'created_at' => now(),
            ]);

            $creadas++;
        }

        return $creadas;
    }

    /**
     * La identidad de un tercero: sin mayúsculas, tildes ni espacios de más, sin
     * caracteres de formato invisibles (espacio de ancho cero, guion suave, unión de ancho
     * cero) y con las letras de ancho completo llevadas a las normales. Es la misma para
     * agrupar, para ver si ya existe y para la clave de su gasto, y sigue lo que el índice
     * único iguala en MySQL (`utf8mb4_unicode_ci`): «Cooperativa», «cooperativa » y un
     * nombre pegado de un PDF con un carácter invisible son el mismo acreedor y una sola
     * deuda por la suma.
     */
    private static function claveDeTercero(string $tercero): string
    {
        // Sin la extensión intl solo quedarían sin igualar las letras de ancho completo.
        if (class_exists(\Normalizer::class)) {
            $tercero = \Normalizer::normalize($tercero, \Normalizer::FORM_KC) ?: $tercero;
        }
        $tercero = preg_replace('/\p{Cf}+/u', '', $tercero) ?? $tercero;
        $limpio = Str::squish($tercero);

        return mb_strtolower(Str::ascii($limpio) ?: $limpio);
    }
}
