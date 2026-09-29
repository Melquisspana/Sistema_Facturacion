<?php

namespace App\Services\Gastos;

use App\Models\Gastos\Cuota;
use App\Models\Gastos\Gasto;
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
 */
final class SaldosGastos
{
    /** Pendiente de UNA cuota, en centavos. Puede ser negativo si un crédito la excede. */
    public function pendienteCuota(Cuota $cuota): int
    {
        return Dinero::centavos((string) $cuota->importe)
            + $this->ajustes($cuota->id, 'debito')
            - $this->ajustes($cuota->id, 'credito')
            - $this->aplicado($cuota->id);
    }

    /** Lo aplicado por pagos VIGENTES a una cuota, en centavos. Una reversión lo devuelve. */
    public function aplicado(int $cuotaId): int
    {
        return DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_pagos as p', 'p.id', '=', 'a.pago_id')
            ->where('a.cuota_id', $cuotaId)
            ->whereNull('p.revertido_at')
            ->pluck('a.importe')
            ->sum(fn ($valor) => Dinero::centavos((string) $valor));
    }

    /** Ajustes VIGENTES de una dirección sobre una cuota, en centavos. */
    public function ajustes(int $cuotaId, string $direccion): int
    {
        return DB::table('gastos_ajustes')
            ->where('cuota_id', $cuotaId)
            ->where('direccion', $direccion)
            ->whereNull('revertido_at')
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
            return [
                'pendiente' => null, 'vencido' => 0, 'importe' => null,
                'pagado' => 0, 'credito' => 0, 'debito' => 0,
                'liquidacion' => 'por_determinar', 'vencimiento' => 'sin_fecha', 'proxima' => null,
            ];
        }

        $pendiente = $vencido = $importe = $pagado = $credito = $debito = 0;
        $vencimiento = 'sin_fecha';
        $proxima = null;

        foreach ($gasto->cuotas as $cuota) {
            $saldo = $this->pendienteCuota($cuota);
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
            'liquidacion' => $this->liquidacion($pendiente, $pagado, $credito),
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
    ];

    public const VENCIMIENTO = [
        'sin_fecha' => 'Sin fecha',
        'proxima' => 'Próxima',
        'hoy' => 'Vence hoy',
        'vencida' => 'Vencida',
        'saldada' => 'Sin saldo',
    ];
}
