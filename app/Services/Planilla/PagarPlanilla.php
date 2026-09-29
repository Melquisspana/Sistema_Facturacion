<?php

namespace App\Services\Planilla;

use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDetalle;
use App\Models\Planilla\PlanillaLote;
use App\Models\Planilla\PlanillaObligacionTercero;
use App\Models\User;
use App\Services\Gastos\Dinero;
use App\Services\Gastos\RegistrarPago;
use App\Services\Gastos\SaldosGastos;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Pagar una planilla: individual, por lote y en abonos parciales.
 *
 * ═══════════ No hay una segunda contabilidad ═══════════
 *
 * Todo esto registra PAGOS NORMALES de Gastos contra las obligaciones que la planilla
 * creó al confirmarse. No hay una tabla de pagos de planilla, no hay importes copiados
 * y no hay nada que pueda discrepar: el saldo de una persona es el saldo de su gasto,
 * calculado por el mismo servicio que calcula el de un alquiler.
 *
 * Lo que este servicio aporta es la ORQUESTACIÓN —pagarle a ocho personas de una vez,
 * respetando que cada pago tiene un beneficiario— y el vínculo del lote.
 *
 * ═══════════ Parciales ═══════════
 *
 * Un abono parcial es simplemente un pago por menos del saldo. Lo valida
 * {@see RegistrarPago}, que ya impide aplicar más que el pendiente real de la cuota.
 * Acá solo se propone el pendiente como importe por defecto.
 *
 * ═══════════ Reintentos y concurrencia ═══════════
 *
 * Cada pago lleva una `clave` única. En el pago individual la manda el formulario; en
 * el lote se DERIVA de la clave del lote y del detalle: el mismo lote produce siempre
 * la misma clave para la misma persona.
 *
 * Mirar el PENDIENTE no alcanza para reintentar bien, y esto costó encontrarlo. Saltar
 * a quien ya está pagado del todo cubre el caso fácil; no cubre estos dos:
 *
 *  - ABONO PARCIAL. El lote le pagó 60 de 100 a alguien —porque eso era lo que
 *    quedaba— y después ese saldo se movió. Al reintentar, el pendiente ya no es 60,
 *    así que se pediría el MISMO pago con OTRO importe. Gastos lo rechaza, con razón
 *    («Este registro ya fue utilizado con otros datos»), pero al hacerlo tumbaba el
 *    lote entero y dejaba sin cobrar a quienes todavía no habían cobrado.
 *
 *  - DOS PETICIONES A LA VEZ. Dos clics, dos procesos, mismo lote. Ambas leen el mismo
 *    pendiente y ambas intentan insertar la misma clave; una gana y la otra se estrella
 *    contra el índice único con un error de base de datos en la cara del usuario.
 *
 * La respuesta son tres cosas, y las tres hacen falta:
 *
 *  1. Se pregunta por la CLAVE, no por el saldo: si ya existe un pago con la clave
 *     derivada, esa persona ya fue atendida por este lote. Punto. El saldo que tenga
 *     hoy da igual.
 *  2. El lote se BLOQUEA mientras se procesa, así dos peticiones simultáneas se ponen
 *     en fila en vez de competir.
 *  3. Y aun así se tolera la violación de unicidad, porque entre dos motores y dos
 *     niveles de aislamiento no se puede prometer que el bloqueo llegue primero. Si
 *     salta, se recupera el pago que ganó y se sigue.
 *
 * ═══════════ El interbloqueo que apareció en MySQL ═══════════
 *
 * Con las tres cosas puestas, dos procesos reales contra MySQL seguían dando un
 * resultado feo: uno pagaba a los tres y el otro moría con un **deadlock** de InnoDB.
 * El dinero quedaba bien —nadie cobró dos veces—, pero la segunda persona se llevaba un
 * error de base de datos en la cara, y eso en una pantalla de pagos es inaceptable: no
 * hay forma de saber, mirando ese error, si se pagó o no.
 *
 * El interbloqueo nacía de crear el lote DENTRO de la transacción larga. Los dos
 * procesos intentaban insertar la misma clave; el segundo se quedaba esperando en el
 * índice único mientras el primero seguía tomando candados de usuario, gasto y cuota
 * persona por persona. InnoDB detectaba el cruce y mataba a uno.
 *
 * Dos cambios, y el segundo depende del primero:
 *
 *  - El lote se crea en su PROPIA transacción corta, que confirma enseguida. La espera
 *    del segundo proceso pasa a durar milisegundos en vez de todo el lote.
 *  - La transacción larga se reintenta si hay interbloqueo. Reintentar es seguro
 *    justamente por la clave derivada: al volver a entrar, encuentra hechos los pagos
 *    que alcanzó a hacer y los cuenta como repetidos. Sin esa idempotencia, un reintento
 *    automático sería la peor idea posible acá.
 */
final class PagarPlanilla
{
    private const NS_LOTE = 'e8b4c2a1-7f36-4d59-b0e2-1a4c6d8f0b35';

    public function __construct(
        private RegistrarPago $pagos,
        private SaldosGastos $saldos,
        private EstadoPlanilla $estado,
    ) {}

    /** Lo que falta por pagarle a una línea, en centavos. */
    public function pendienteDe(PlanillaDetalle $detalle): int
    {
        return $this->estado->deDetalle($detalle)['pendiente'];
    }

    /**
     * Pago a UNA persona. Admite abono parcial.
     *
     * @param  array<string, mixed>  $datos
     */
    public function pagarPersona(User $usuario, PlanillaDetalle $detalle, array $datos): Pago
    {
        $this->autorizar($usuario);
        $gasto = $this->obligacionDe($detalle);

        return DB::transaction(function () use ($usuario, $gasto, $datos) {
            $cuota = $gasto->cuotas()->first();

            return $this->pagos->registrar($usuario, [
                'clave' => $datos['clave'] ?? (string) Str::uuid(),
                'importe' => $datos['importe'],
                'fecha' => $datos['fecha'],
                'metodo' => $datos['metodo'],
                'pagado_por' => $datos['pagado_por'],
                'referencia' => $datos['referencia'] ?? null,
                'sin_comprobante' => $datos['sin_comprobante'] ?? null,
            ], [['cuota_id' => $cuota->id, 'importe' => $datos['importe']]]);
        });
    }

    /** Pago a un TERCERO por lo que la planilla le descontó a todos. */
    public function pagarTercero(User $usuario, PlanillaObligacionTercero $obligacion, array $datos): Pago
    {
        $this->autorizar($usuario);

        if ($obligacion->gasto === null) {
            throw ValidationException::withMessages(['pago' => 'Esta obligación todavía no tiene su gasto.']);
        }

        $cuota = $obligacion->gasto->cuotas()->first();

        return $this->pagos->registrar($usuario, [
            'clave' => $datos['clave'] ?? (string) Str::uuid(),
            'importe' => $datos['importe'],
            'fecha' => $datos['fecha'],
            'metodo' => $datos['metodo'],
            'pagado_por' => $datos['pagado_por'],
            'referencia' => $datos['referencia'] ?? null,
            'sin_comprobante' => $datos['sin_comprobante'] ?? null,
        ], [['cuota_id' => $cuota->id, 'importe' => $datos['importe']]]);
    }

    /**
     * Pagar a VARIAS personas de una vez.
     *
     * Un lote son N pagos, uno por persona, cada uno con su beneficiario: no se
     * inventa un pago único de todos, que rompería la regla de Gastos y haría imposible
     * revertir el de una sola persona.
     *
     * Es idempotente: la clave de cada pago se deriva del lote y del detalle, así que
     * reintentar un lote interrumpido completa lo que falta sin pagar dos veces.
     *
     * @param  array<string, mixed>  $datos
     * @param  array<int, int>  $detalleIds
     * @return array{lote: PlanillaLote, pagos: int, omitidos: int, repetidos: int}
     */
    public function pagarLote(User $usuario, Planilla $planilla, array $datos, array $detalleIds): array
    {
        $this->autorizar($usuario);

        if (! $planilla->confirmada()) {
            throw ValidationException::withMessages([
                'lote' => 'Solo se puede pagar una planilla confirmada. Un borrador no debe nada todavía.',
            ]);
        }

        if ($detalleIds === []) {
            throw ValidationException::withMessages(['lote' => 'Elegí al menos a una persona.']);
        }

        $claveLote = $datos['clave'] ?? (string) Str::uuid();

        // FUERA de la transacción larga, y a propósito: ver el bloque «El interbloqueo
        // que apareció en MySQL» arriba.
        $lote = $this->registrarLote($usuario, $planilla, $datos, $claveLote);

        // Tres intentos: si InnoDB mata esta transacción por interbloqueo, se vuelve a
        // entrar. Es seguro porque cada pago se reconoce por su clave derivada.
        return DB::transaction(function () use ($usuario, $planilla, $datos, $detalleIds, $lote) {
            $lote = PlanillaLote::whereKey($lote->id)->lockForUpdate()->firstOrFail();

            $pagados = 0;
            $omitidos = 0;
            $repetidos = 0;

            foreach ($detalleIds as $id) {
                $detalle = PlanillaDetalle::where('planilla_id', $planilla->id)->whereKey($id)->first();

                if ($detalle === null || $detalle->gasto_id === null) {
                    $omitidos++;

                    continue;
                }

                // DERIVADA del lote y del detalle. Es la pregunta que de verdad importa
                // en un reintento: «¿este lote ya atendió a esta persona?». El saldo de
                // hoy no responde eso —pudo moverse por un abono o una reversión—, y
                // preguntárselo a él era el error.
                $clave = Uuid::uuid5(self::NS_LOTE, $lote->clave.'|'.$detalle->id)->toString();

                if ($previo = Pago::where('clave', $clave)->first()) {
                    $this->vincularAlLote($lote, $previo);
                    $repetidos++;

                    continue;
                }

                $pendiente = $this->pendienteDe($detalle);

                // Sin saldo no hay nada que pagar. Esto ya no es la defensa del
                // reintento —de eso se ocupa la clave—, sino el caso normal de alguien
                // que cobró por otra vía antes de que corriera el lote.
                if ($pendiente <= 0) {
                    $omitidos++;

                    continue;
                }

                $cuota = $detalle->gasto->cuotas()->first();

                try {
                    $pago = $this->pagos->registrar($usuario, [
                        'clave' => $clave,
                        'importe' => Dinero::decimal($pendiente),
                        'fecha' => $datos['fecha'],
                        'metodo' => $datos['metodo'],
                        'pagado_por' => $datos['pagado_por'],
                        'referencia' => $datos['referencia'] ?? null,
                        'sin_comprobante' => $datos['sin_comprobante'] ?? null,
                    ], [['cuota_id' => $cuota->id, 'importe' => Dinero::decimal($pendiente)]]);
                } catch (QueryException $e) {
                    // Otra petición del mismo lote ganó la carrera por esta persona. No
                    // es un fallo: es justo lo que el índice único tiene que impedir.
                    $pago = Pago::where('clave', $clave)->first();

                    if ($pago === null) {
                        throw $e;
                    }

                    $this->vincularAlLote($lote, $pago);
                    $repetidos++;

                    continue;
                }

                $this->vincularAlLote($lote, $pago);
                $pagados++;
            }

            DB::table('gastos_eventos')->insert([
                'usuario_id' => $usuario->id,
                'accion' => 'planilla_lote_pagado',
                'datos' => json_encode([
                    'planilla_id' => $planilla->id, 'lote_id' => $lote->id,
                    'pagos' => $pagados, 'omitidos' => $omitidos, 'repetidos' => $repetidos,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return [
                'lote' => $lote->fresh(),
                'pagos' => $pagados,
                // Quien ya fue atendido por ESTE lote cuenta como omitido de cara a
                // quien mira la pantalla: en los dos casos el lote no le pagó ahora.
                // `repetidos` se informa aparte para poder distinguirlos en el rastro y
                // en las pruebas, que es donde la diferencia sí importa.
                'omitidos' => $omitidos + $repetidos,
                'repetidos' => $repetidos,
            ];
        }, 3);
    }

    /**
     * El lote, creado si hace falta, en su propia transacción corta.
     *
     * Corta a propósito: mientras esta no confirme, cualquier otra petición del mismo
     * lote se queda esperando en el índice único de `clave`. Si esa espera ocurriera
     * dentro de la transacción larga —como ocurría—, se cruzaría con los candados de
     * usuario, gasto y cuota que el primer proceso va tomando, y MySQL mataría a uno de
     * los dos por interbloqueo.
     *
     * `firstOrCreate` puede perder igualmente su propia carrera, así que se tolera la
     * violación de unicidad y se recupera la fila que ganó.
     *
     * @param  array<string, mixed>  $datos
     */
    private function registrarLote(User $usuario, Planilla $planilla, array $datos, string $claveLote): PlanillaLote
    {
        $atributos = [
            'planilla_id' => $planilla->id,
            'fecha' => $datos['fecha'],
            'metodo' => $datos['metodo'],
            'referencia' => $datos['referencia'] ?? null,
            'registrado_por' => $usuario->id,
            'created_at' => now(),
        ];

        return DB::transaction(function () use ($claveLote, $atributos) {
            try {
                return PlanillaLote::firstOrCreate(['clave' => $claveLote], $atributos);
            } catch (QueryException $e) {
                // LECTURA BLOQUEANTE, no corriente, y acá está la sutileza que costó dos
                // intentos: en REPEATABLE READ —el nivel por defecto de MySQL— una
                // lectura normal responde con la foto del inicio de la transacción, y esa
                // foto es ANTERIOR a que el otro proceso confirmara. Es decir: preguntar
                // «¿existe la fila?» devolvía «no» justo cuando el error decía que sí.
                //
                // `lockForUpdate` fuerza una lectura del estado actual, que es la única
                // que puede ver a la ganadora.
                $ganadora = PlanillaLote::where('clave', $claveLote)->lockForUpdate()->first();

                if ($ganadora === null) {
                    throw $e;
                }

                return $ganadora;
            }
        }, 3);
    }

    /** El vínculo lote↔pago es informativo y se reescribe sin daño: `insertOrIgnore`. */
    private function vincularAlLote(PlanillaLote $lote, Pago $pago): void
    {
        DB::table('planilla_lote_pagos')->insertOrIgnore([
            'planilla_lote_id' => $lote->id,
            'pago_id' => $pago->id,
        ]);
    }

    /**
     * Revertir un pago de planilla. Es el mismo camino de Gastos —motivo obligatorio,
     * permiso propio y rastro— y por eso no se reimplementa: el pago vuelve a quedar
     * pendiente y el historial conserva que existió.
     */
    public function revertirPago(User $usuario, Pago $pago, string $motivo): void
    {
        $this->pagos->revertir($usuario, $pago, $motivo);
    }

    private function obligacionDe(PlanillaDetalle $detalle): Gasto
    {
        if (! $detalle->planilla->confirmada()) {
            throw ValidationException::withMessages([
                'pago' => 'Esta planilla todavía es un borrador: no hay nada que pagar.',
            ]);
        }

        if ($detalle->gasto === null) {
            throw ValidationException::withMessages([
                'pago' => 'Esta persona no tiene nada que cobrar en esta planilla.',
            ]);
        }

        return $detalle->gasto;
    }

    private function autorizar(User $usuario): void
    {
        abort_unless($usuario->activo && $usuario->can('planilla.pagar'), 403);
    }
}
