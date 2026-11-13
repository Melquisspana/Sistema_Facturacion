<?php

namespace App\Services\Gastos;

use App\Models\Gastos\Cuota;
use App\Models\Gastos\Gasto;
use App\Models\Secuencia;
use Illuminate\Support\Facades\DB;

/**
 * La aritmética del módulo. Todo en centavos enteros: ni un `float` en el camino.
 *
 *      pendiente de una cuota = importe + débitos vigentes − créditos vigentes
 *                               − aplicaciones de pagos vigentes
 *      vencido = suma de pendientes de cuotas con fecha ANTERIOR a hoy
 *
 * Las dos reglas que no se pueden romper:
 *
 *  1. Una cuota futura impaga NO vuelve vencido el gasto entero. Vencido es un
 *     subconjunto de pendiente, no otra cifra que se sume.
 *  2. Importe desconocido es DESCONOCIDO, no cero. Un gasto esperando recibo no
 *     aporta a pendiente ni a vencido, y se cuenta aparte.
 *
 * Un gasto ANULADO tiene pendiente cero en todas sus cuotas. Las consultas en SQL hacen lo
 * mismo en {@see ConsultaGastos::cuotasConSaldo()}.
 *
 * QUIEN VA A ESCRIBIR LEE CON `$bloquear`. En MySQL (REPEATABLE READ) una lectura común
 * devuelve la foto que la transacción tomó en su primera lectura, que suele ser anterior a
 * esperar el candado del gasto: no ve el pago que otra persona acaba de confirmar, y dos
 * pagos simultáneos se pasaban del saldo de la cuota. Con candado se lee lo último
 * confirmado. Es compartido: quien escribe ya tiene bloqueados el gasto y la cuota, y acá
 * solo hace falta que la cuenta sea la de ahora. Las pantallas siguen leyendo sin candado.
 *
 * Y ANTES DE TODO ESO, EL TURNO ({@see tomarTurno()}).
 */
final class SaldosGastos
{
    /** La fila de {@see Secuencia} que hace de turno para mover saldos. */
    public const TURNO = 'gastos.saldos';

    /**
     * EL TURNO PARA MOVER SALDOS: un solo candado, siempre el mismo y siempre primero.
     *
     * En MySQL la lectura con candado del saldo de una cuota bloquea también el HUECO del
     * índice `cuota_id`, que comparten cuotas vecinas de gastos distintos. Dos pagos a la
     * vez tomaban el mismo hueco y después los dos insertaban su aplicación ahí: cada uno
     * esperaba al otro y MySQL cortaba a uno (pasaba siempre, en una prueba con dos
     * procesos). Ningún orden por id lo evita, porque el hueco no es de nadie.
     *
     * Con el turno, quien va a insertar o revertir pagos y ajustes lo toma COMO PRIMER
     * CANDADO de su transacción, antes de su usuario, de cualquier gasto, cuota o saldo:
     * quien espera en la fila no tiene nada tomado, así que nadie puede estar esperándolo.
     * Por eso también lo toman primero las transacciones de afuera que llegan a un pago con
     * algo ya bloqueado o escrito (alta con pago, «compré y pagué», pagar lo programado);
     * adentro, volver a tomarlo no espera. Lo usan registrar pagos y ajustes, revertir
     * ajustes y anular una planilla. Revertir un pago no lo necesita: no lee saldos con
     * candado ni inserta en ellos.
     *
     * Es una LECTURA CON CANDADO, nunca una común: en MySQL la primera lectura común fija la
     * foto de la transacción, y tomarla antes de esperar dejaría al que entra sin ver, por
     * ejemplo, el pago que su propio doble clic acaba de guardar.
     *
     * Debe llamarse dentro de la transacción: el candado dura hasta que esta termina.
     */
    public function tomarTurno(): void
    {
        if (Secuencia::consultaBloqueada(self::TURNO)->first() === null) {
            // Solo la primera vez en la vida de la base: el upsert crea la fila aunque otro
            // la esté creando a la vez ({@see Secuencia::siguiente()}).
            Secuencia::query()->upsert([['clave' => self::TURNO, 'ultimo_numero' => 0]], ['clave'], ['clave']);
            Secuencia::consultaBloqueada(self::TURNO)->firstOrFail();
        }
    }

    /**
     * Pendiente de UNA cuota, en centavos. Puede ser negativo si un crédito la excede.
     *
     * La de un gasto ANULADO es cero: ya no se debe. Su importe, sus pagos revertidos y
     * sus ajustes siguen en la ficha; lo que deja de existir es la deuda.
     */
    public function pendienteCuota(Cuota $cuota, bool $bloquear = false): int
    {
        $anulado = $cuota->relationLoaded('gasto')
            ? $cuota->gasto->anulado()
            : DB::table('gastos')->where('id', $cuota->gasto_id)->whereNotNull('anulado_at')->exists();

        return $anulado ? 0 : $this->saldoBruto($cuota, $bloquear);
    }

    /** Importe + débitos − créditos − pagos vigentes, sin mirar si el gasto se anuló. */
    private function saldoBruto(Cuota $cuota, bool $bloquear = false): int
    {
        return Dinero::centavos((string) $cuota->importe)
            + $this->ajustes($cuota->id, 'debito', $bloquear)
            - $this->ajustes($cuota->id, 'credito', $bloquear)
            - $this->aplicado($cuota->id, $bloquear);
    }

    /** Lo aplicado por pagos VIGENTES a una cuota, en centavos. Una reversión lo devuelve. */
    public function aplicado(int $cuotaId, bool $bloquear = false): int
    {
        return DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_pagos as p', 'p.id', '=', 'a.pago_id')
            ->where('a.cuota_id', $cuotaId)
            ->whereNull('p.revertido_at')
            ->when($bloquear, fn ($q) => $q->sharedLock())
            ->pluck('a.importe')
            ->sum(fn ($valor) => Dinero::centavos((string) $valor));
    }

    /** Ajustes VIGENTES de una dirección sobre una cuota, en centavos. */
    public function ajustes(int $cuotaId, string $direccion, bool $bloquear = false): int
    {
        return DB::table('gastos_ajustes')
            ->where('cuota_id', $cuotaId)
            ->where('direccion', $direccion)
            ->whereNull('revertido_at')
            ->when($bloquear, fn ($q) => $q->sharedLock())
            ->pluck('importe')
            ->sum(fn ($valor) => Dinero::centavos((string) $valor));
    }

    /**
     * Los tres ejes de un gasto, sin un estado único que los mezcle.
     *
     * @return array{
     *   pendiente: int|null, vencido: int|null, importe: int|null,
     *   pagado: int, credito: int, debito: int,
     *   liquidacion: string, vencimiento: string, proxima: ?string
     * }
     */
    public function resumen(Gasto $gasto, string $hoy): array
    {
        if ($gasto->montoDesconocido()) {
            // Pendiente DESCONOCIDO, pero vencido CERO: mientras no se sepa el monto no
            // hay deuda que reclamar. Poner null en vencido invitaría a mostrar «por
            // definir» donde la respuesta correcta es «nada exigible todavía».
            // Anulado sin haber llegado a tener monto: no se debe nada, y lo dice.
            return [
                'pendiente' => $gasto->anulado() ? 0 : null, 'vencido' => 0, 'importe' => null,
                'pagado' => 0, 'credito' => 0, 'debito' => 0,
                'liquidacion' => $gasto->anulado() ? 'anulada' : 'por_determinar',
                'vencimiento' => $gasto->anulado() ? 'saldada' : 'sin_fecha', 'proxima' => null,
            ];
        }

        $pendiente = $vencido = $importe = $pagado = $credito = $debito = 0;
        $vencimiento = 'sin_fecha';
        $proxima = null;

        foreach ($gasto->cuotas as $cuota) {
            $saldo = $gasto->anulado() ? 0 : $this->saldoBruto($cuota);
            $importe += Dinero::centavos((string) $cuota->importe);
            $pagado += $this->aplicado($cuota->id);
            $credito += $this->ajustes($cuota->id, 'credito');
            $debito += $this->ajustes($cuota->id, 'debito');
            $pendiente += $saldo;

            // Solo cuenta como vencida si TIENE saldo y su fecha ya pasó. Una cuota
            // saldada no genera reclamo aunque su fecha sea vieja.
            if ($saldo > 0 && $cuota->vence !== null) {
                $fecha = $cuota->vence->format('Y-m-d');

                // `proxima` es la fecha MÁS PRÓXIMA a reclamar, esté o no vencida. Antes
                // se registraba solo cuando la cuota aún no había vencido, así que un
                // gasto vencido mostraba «Sin fecha» junto a la insignia «Vencida»: dos
                // cosas que se contradicen en la misma fila. Además así coincide con lo
                // que calcula el SQL del listado (MIN sobre las cuotas con saldo).
                if ($proxima === null || $fecha < $proxima) {
                    $proxima = $fecha;
                }

                if ($cuota->venceAntesDe($hoy)) {
                    $vencido += $saldo;
                    $vencimiento = 'vencida';
                } elseif ($vencimiento !== 'vencida') {
                    $vencimiento = $fecha === $hoy ? 'hoy' : 'proxima';
                }
            }
        }

        return [
            'pendiente' => $pendiente, 'vencido' => $vencido, 'importe' => $importe,
            'pagado' => $pagado, 'credito' => $credito, 'debito' => $debito,
            // «Anulado» antes que cualquier otra lectura: sin esto, un anulado sin pagos
            // diría «Saldada por ajuste», y no se ajustó nada.
            'liquidacion' => $gasto->anulado() ? 'anulada' : $this->liquidacion($pendiente, $pagado, $credito),
            'vencimiento' => $pendiente <= 0 ? 'saldada' : $vencimiento,
            'proxima' => $proxima,
        ];
    }

    /**
     * Eje de liquidación. «Saldada por ajuste» y «Pagada» NO son lo mismo: en la
     * primera nunca salió dinero, y confundirlas falsearía cualquier informe de
     * pagos. Y un saldo negativo no se disfraza de cero: hay crédito por resolver.
     */
    private function liquidacion(int $pendiente, int $pagado, int $credito): string
    {
        if ($pendiente < 0) {
            return 'credito_a_favor';
        }

        if ($pendiente === 0) {
            return $pagado > 0 ? ($credito > 0 ? 'saldada_mixta' : 'pagada') : 'saldada_por_ajuste';
        }

        return $pagado > 0 ? 'parcial' : 'sin_pagos';
    }

    /** Etiquetas visibles de cada eje. Se centralizan acá para no repetirlas en vistas. */
    public const LIQUIDACION = [
        'sin_pagos' => 'Sin pagos',
        'parcial' => 'Parcialmente pagado',
        'pagada' => 'Pagada',
        'saldada_por_ajuste' => 'Saldada por ajuste',
        'saldada_mixta' => 'Pagada y ajustada',
        'credito_a_favor' => 'Crédito a favor por resolver',
        'por_determinar' => 'Por determinar',
        'anulada' => 'Anulado',
    ];

    public const VENCIMIENTO = [
        'sin_fecha' => 'Sin fecha',
        'proxima' => 'Próxima',
        'hoy' => 'Vence hoy',
        'vencida' => 'Vencida',
        'saldada' => 'Sin saldo',
    ];
}
