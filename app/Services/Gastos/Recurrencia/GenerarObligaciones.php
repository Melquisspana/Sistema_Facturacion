<?php

namespace App\Services\Gastos\Recurrencia;

use App\Models\Gastos\Gasto;
use App\Models\Gastos\Ocurrencia;
use App\Models\Gastos\Regla;
use App\Models\User;
use App\Services\Gastos\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Convierte reglas en obligaciones: una por período, y una sola vez.
 *
 * ───────────────────────────── Lo que NO hace ─────────────────────────────
 *
 * NO MARCA NADA COMO PAGADO. Una obligación recurrente nace debiendo: se crea el
 * gasto y su cuota, y ahí termina. No hay pago, no hay aplicación y no hay
 * comprobante, porque nadie pagó nada todavía —el sistema no ejecuta pagos y un
 * pago acá es siempre la declaración de una persona—. Que el alquiler se pague
 * todos los meses no autoriza a asentar que ESTE mes ya se pagó.
 *
 * NO IMPORTA PENDIENTES DE NINGÚN LADO. Solo genera hacia adelante, desde la
 * vigencia de la regla y dentro de una ventana acotada.
 *
 * NO TOCA LO YA GENERADO. Ni al reanudar, ni al editar la regla, ni al recuperar
 * períodos atrasados. Una obligación creada se corrige por los caminos normales
 * —ajuste o reversión—, nunca reescribiendo el pasado desde la plantilla.
 *
 * ──────────────────────── Por qué no se puede duplicar ────────────────────────
 *
 * Dos candados independientes, los dos en la base y no en una comprobación previa:
 *
 *  1. `gastos_ocurrencias.unique(regla_id, periodo)`.
 *  2. `gastos.clave` es única y acá se DERIVA (UUIDv5) de la clave de la regla más
 *     el período. El mismo período produce siempre el mismo UUID, así que ni
 *     siquiera un camino que esquivara el primer índice podría crear dos gastos.
 *
 * Cada período va en su propia transacción: que un mes falle no debe impedir que
 * los demás se generen, y una corrida a medias tiene que poder repetirse sin
 * duplicar lo que ya entró.
 *
 * ────────────────────────── Recuperar tras una caída ──────────────────────────
 *
 * Si el servicio estuvo días abajo, la corrida siguiente recupera lo que falta,
 * pero solo dentro de `gastos.recurrencias.ventana_recuperacion_dias`. Lo anterior
 * a esa ventana NO se genera solo: se informa como `fuera_de_ventana` para que una
 * persona decida. Una regla vigente desde hace tres años, generada de golpe,
 * inventaría 36 deudas que nadie pidió.
 */
final class GenerarObligaciones
{
    /** Espacio de nombres para derivar la clave del gasto desde regla + período. */
    private const NS_GASTO = 'a4f1f1d2-6c6a-4f0e-9c1a-2b7c9d4e5f60';

    public function __construct(private CalendarioRecurrencia $calendario) {}

    /**
     * Genera lo que le toque a UNA regla.
     *
     * @return array{generadas: int, periodos: array<int, string>, fuera_de_ventana: array<int, string>, motivo_omision: ?string}
     */
    public function paraRegla(Regla $regla, CarbonImmutable $hoy, ?User $usuario = null): array
    {
        $vacio = ['generadas' => 0, 'periodos' => [], 'fuera_de_ventana' => [], 'motivo_omision' => null];

        if (! $regla->activa()) {
            // Pausada o cancelada: no genera. Lo ya generado sigue igual.
            return ['motivo_omision' => 'La regla está '.mb_strtolower(Regla::ESTADOS[$regla->estado] ?? $regla->estado).'.'] + $vacio;
        }

        $ventana = (int) config('gastos.recurrencias.ventana_recuperacion_dias', 62);

        // Hasta dónde se mira hacia adelante: el vencimiento se adelanta tantos días
        // como pida la regla, y ni uno más.
        $hasta = $hoy->addDays((int) $regla->dias_generar_antes);
        if ($regla->vigente_hasta !== null) {
            $fin = CarbonImmutable::parse($regla->vigente_hasta->toDateString());
            $hasta = $hasta->gt($fin) ? $fin : $hasta;
        }

        $inicioVigencia = CarbonImmutable::parse($regla->vigente_desde->toDateString());
        $limiteVentana = $hoy->subDays($ventana);
        $desde = $inicioVigencia->gt($limiteVentana) ? $inicioVigencia : $limiteVentana;

        // Lo que la vigencia permite pero la ventana deja fuera. No se genera; se avisa.
        $fueraDeVentana = [];
        if ($inicioVigencia->lt($limiteVentana)) {
            foreach ($this->calendario->periodos($regla->calendario(), $inicioVigencia, $limiteVentana->subDay()) as $viejo) {
                if (! Ocurrencia::where('regla_id', $regla->id)->where('periodo', $viejo['periodo'])->exists()) {
                    $fueraDeVentana[] = $viejo['periodo'];
                }
            }
        }

        $generadas = 0;
        $periodos = [];

        foreach ($this->calendario->periodos($regla->calendario(), $desde, $hasta) as $periodo) {
            if ($this->generarPeriodo($regla, $periodo['periodo'], $periodo['vence'], $usuario)) {
                $generadas++;
                $periodos[] = $periodo['periodo'];
            }
        }

        if ($generadas > 0) {
            $ultima = Ocurrencia::where('regla_id', $regla->id)->max('vence');
            $regla->forceFill(['generado_hasta' => $ultima])->save();
        }

        return [
            'generadas' => $generadas,
            'periodos' => $periodos,
            'fuera_de_ventana' => $fueraDeVentana,
            'motivo_omision' => null,
        ];
    }

    /**
     * La obligación de UN período concreto: la crea si no está, la devuelve si ya está.
     *
     * Existe para que se pueda pagar una fila programada sin pasar antes por la
     * pantalla de reglas a generarla a mano. Quien paga no tiene por qué saber que
     * «generar una ocurrencia» es un paso previo: pide pagar el recibo de octubre y el
     * sistema se encarga.
     *
     * NO ES UN CAMINO NUEVO. Delega en {@see generarPeriodo()}, que es el mismo que usa
     * la corrida programada, con su bloqueo sobre la regla, su índice único y su clave
     * derivada. Por eso dos clics seguidos —o dos personas a la vez— no pueden producir
     * dos deudas del mismo mes: el segundo encuentra la que escribió el primero y la
     * devuelve. La idempotencia no se reimplementa acá; se hereda.
     *
     * UN PERÍODO OMITIDO NO SE RESUCITA. Omitir un mes fue una decisión explícita, con
     * su motivo y su rastro. Si alguien intenta pagarlo, se dice que está omitido en vez
     * de deshacer esa decisión por la puerta de atrás.
     */
    public function asegurarPeriodo(Regla $regla, string $periodo, CarbonImmutable $vence, User $usuario): Gasto
    {
        /*
         | EL BLOQUEO VA PRIMERO, ANTES DE LEER NADA. No es un detalle de orden.
         |
         | MySQL trabaja por defecto en REPEATABLE READ, y en ese nivel la PRIMERA
         | lectura consistente de la transacción fija la foto que verán todas las
         | demás. Si se leyera antes de bloquear, dos confirmaciones simultáneas
         | harían esto: la segunda toma su foto mientras la primera todavía no ha
         | cometido, después se queda esperando el bloqueo, y cuando por fin entra
         | sigue mirando un pasado donde la obligación no existe. Intenta crearla,
         | choca contra el índice único, y al ir a buscar la que ya está tampoco la
         | ve —su foto es vieja—: termina en «no se pudo preparar la obligación».
         |
         | Se comprobó así, contra MySQL real y con las dos peticiones arrancando en
         | el mismo instante. En SQLite no pasa, y por eso la suite no lo veía.
         |
         | Bloquear primero mueve la foto a DESPUÉS de que la otra cometió, y a
         | partir de ahí todo lo que sigue —la ocurrencia, el gasto, las cuotas, el
         | pendiente— lee el estado de verdad.
         */
        Regla::whereKey($regla->id)->lockForUpdate()->firstOrFail();

        $ocurrencia = Ocurrencia::where('regla_id', $regla->id)->where('periodo', $periodo)->first();

        if ($ocurrencia !== null && $ocurrencia->omitida()) {
            throw ValidationException::withMessages([
                'periodo' => 'Este período se omitió a propósito y no se puede pagar desde acá. '
                    .'Revisá la regla si hay que reabrirlo.',
            ]);
        }

        $this->generarPeriodo($regla, $periodo, $vence, $usuario);

        // Se busca por la clave DERIVADA, que es la misma para este período pase lo que
        // pase: da igual si la creó esta llamada, una corrida anterior o un proceso
        // paralelo un instante antes.
        $clave = Uuid::uuid5(self::NS_GASTO, $regla->clave.'|'.$periodo)->toString();
        $gasto = Gasto::where('clave', $clave)->first();

        if ($gasto === null) {
            // Camino raro pero posible: la ocurrencia existe apuntando a un gasto que se
            // creó con otra clave (regla regenerada tras perder la ocurrencia). Se
            // respeta la que ya está en vez de crear una gemela.
            $ocurrencia = Ocurrencia::where('regla_id', $regla->id)->where('periodo', $periodo)->first();
            $gasto = $ocurrencia?->gasto_id !== null ? Gasto::find($ocurrencia->gasto_id) : null;
        }

        if ($gasto === null) {
            throw ValidationException::withMessages([
                'periodo' => 'No se pudo preparar la obligación de este período. Revisá la regla.',
            ]);
        }

        return $gasto;
    }

    /**
     * Un período. Devuelve true solo si CREÓ la obligación; false si ya existía —da
     * igual que exista como generada o como omitida— o si otro proceso ganó la
     * carrera.
     */
    private function generarPeriodo(Regla $regla, string $periodo, CarbonImmutable $vence, ?User $usuario): bool
    {
        try {
            return DB::transaction(function () use ($regla, $periodo, $vence, $usuario) {
                // Bloqueo sobre la regla: dos workers que atacan el mismo período se
                // serializan acá, y el segundo encuentra la ocurrencia ya escrita.
                $regla = Regla::whereKey($regla->id)->lockForUpdate()->firstOrFail();

                if (Ocurrencia::where('regla_id', $regla->id)->where('periodo', $periodo)->exists()) {
                    return false;
                }

                $clave = Uuid::uuid5(self::NS_GASTO, $regla->clave.'|'.$periodo)->toString();

                if ($existente = Gasto::where('clave', $clave)->first()) {
                    // La ocurrencia se perdió pero el gasto está: se reengancha en vez de
                    // crear una deuda gemela.
                    $this->crearOcurrencia($regla, $periodo, $vence, $existente, $usuario);

                    return false;
                }

                $variable = $regla->montoVariable();
                [$desdePeriodo, $hastaPeriodo] = $this->limitesDelPeriodo($regla->frecuencia, $vence);

                $gasto = Gasto::create($regla->plantilla() + [
                    'clave' => $clave,
                    'huella_peticion' => hash('sha256', $regla->clave.'|'.$periodo.'|'.$regla->version),
                    // Monto variable: NULL, no cero. La obligación queda «esperando monto»
                    // y no suma pendiente ni vencido hasta que alguien ponga la cifra.
                    'importe' => $variable ? null : Dinero::decimal(Dinero::centavos((string) $regla->importe)),
                    'periodo_desde' => $desdePeriodo,
                    'periodo_hasta' => $hastaPeriodo,
                    'registrado_por' => $usuario?->id ?? $regla->registrado_por,
                ]);

                // Sin monto no hay cuota: una cuota exige un importe, y el vencimiento
                // esperado vive en la ocurrencia hasta que se complete el monto. Es lo
                // mismo que hace «todavía no conozco el monto» en el alta manual.
                if (! $variable) {
                    $gasto->cuotas()->create([
                        'numero' => 1,
                        'importe' => Dinero::decimal(Dinero::centavos((string) $regla->importe)),
                        'vence' => $vence->toDateString(),
                    ]);
                }

                $this->crearOcurrencia($regla, $periodo, $vence, $gasto, $usuario);

                DB::table('gastos_eventos')->insert([
                    'gasto_id' => $gasto->id,
                    'regla_id' => $regla->id,
                    'usuario_id' => $usuario?->id ?? $regla->registrado_por,
                    'accion' => 'gasto_generado_por_regla',
                    'datos' => json_encode([
                        'regla' => $regla->nombre,
                        'periodo' => $periodo,
                        'version_regla' => $regla->version,
                        'vence' => $vence->toDateString(),
                        'monto_modo' => $regla->monto_modo,
                        'importe' => $gasto->importe,
                        'automatico' => $usuario === null,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                return true;
            });
        } catch (QueryException $e) {
            // El índice único hizo su trabajo: otro proceso generó este mismo período
            // mientras este lo intentaba. No es un error que deba detener la corrida.
            if ($this->esViolacionDeUnicidad($e)) {
                return false;
            }

            throw $e;
        }
    }

    private function crearOcurrencia(Regla $regla, string $periodo, CarbonImmutable $vence, Gasto $gasto, ?User $usuario): void
    {
        Ocurrencia::create([
            'regla_id' => $regla->id,
            'periodo' => $periodo,
            'vence' => $vence->toDateString(),
            'estado' => 'generada',
            'gasto_id' => $gasto->id,
            'version_regla' => $regla->version,
            'registrado_por' => $usuario?->id,
            'created_at' => now(),
        ]);
    }

    /**
     * A qué tramo de calendario corresponde el período. Se guarda en el gasto para
     * que «alquiler de marzo» se pueda informar por su mes aunque venza el 5 de abril.
     *
     * @return array{0: string, 1: string}
     */
    private function limitesDelPeriodo(string $frecuencia, CarbonImmutable $vence): array
    {
        return match ($frecuencia) {
            'semanal' => [$vence->startOfWeek()->toDateString(), $vence->endOfWeek()->toDateString()],
            'anual' => [$vence->startOfYear()->toDateString(), $vence->endOfYear()->toDateString()],
            default => [$vence->startOfMonth()->toDateString(), $vence->endOfMonth()->toDateString()],
        };
    }

    private function esViolacionDeUnicidad(QueryException $e): bool
    {
        return $e->getCode() === '23000' || str_contains(mb_strtolower($e->getMessage()), 'unique');
    }
}
