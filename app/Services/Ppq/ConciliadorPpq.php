<?php

namespace App\Services\Ppq;

use App\Enums\OrigenConciliacionPpq;
use App\Exceptions\Ppq\ArchivoConciliacionInconsistenteException;
use App\Exceptions\Ppq\ConciliacionYaProcesadaException;
use App\Models\PpqConciliacion;
use App\Models\PpqConciliacionMovimiento;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use App\Support\Dinero;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Concilia un lote PPQ contra el TXT de pagos del cliente (ya parseado por
 * {@see ConciliacionTxtParser}).
 *
 * Regla central, que no cambia: un CCF NO está pagado por estar en el PPQ. Solo se marca
 * PAGADO cuando aparece en el TXT como tipo CF, y una NC como APLICADA cuando aparece
 * como NC. El cruce es por número de documento NORMALIZADO y por tipo.
 *
 * ═══════════════════ LO QUE ESTE SERVICIO NO HACE (y antes sí) ═══════════════════
 *
 * NO TOCA los renglones que el archivo no menciona.
 *
 * Antes, cada corrida recorría todos los items del lote y al que no encontraba en el TXT
 * le ponía el estado, la fecha y el monto en NULL. Leído rápido parecía razonable —«si no
 * está en el archivo, no está pagado»— y era destructivo: bastaba subir un TXT parcial,
 * viejo o del lote equivocado para BORRAR pagos que una corrida anterior había registrado.
 * No quedaba rastro, el archivo no se guardaba, y el documento volvía a figurar como deuda
 * y se reclamaba dos veces.
 *
 * El error de fondo era confundir dos cosas distintas:
 *
 *   · que un documento NO APAREZCA en un archivo  → el archivo no dice nada de él;
 *   · que un documento NO ESTÉ PAGADO            → una afirmación sobre el documento.
 *
 * Un archivo solo puede hablar de lo que trae dentro. Por eso ahora una corrida SOLO
 * escribe sobre los renglones que el archivo identifica; el resto se reporta —separando
 * los que nunca se cobraron de los que ya tenían un pago y lo CONSERVAN— y se queda como
 * estaba.
 *
 * Quitar un pago ya registrado sigue siendo posible, pero dejó de ser un efecto colateral:
 * es una acción con nombre propio, permiso y motivo obligatorio
 * ({@see ReversionConciliacion}).
 *
 * ═══════════════════ Un archivo que se contradice no se aplica ═══════════════════
 *
 * Si el mismo documento aparece dos veces con datos distintos, el archivo se rechaza
 * ENTERO y no se toca ni un renglón ({@see ArchivoConciliacionInconsistenteException}).
 * Quedarse con la última fila —lo que hace un `$indice[$clave] = $fila` sin más— haría que
 * el importe cobrado dependiera del orden de las líneas dentro del archivo. Un duplicado
 * IDÉNTICO sí se acepta: las dos filas dicen lo mismo, así que no hay nada que decidir, y
 * se informa como repetición.
 *
 * ═══════════════════════════ Lo que sí queda registrado ═══════════════════════════
 *
 * Cada corrida deja una fila en `ppq_conciliaciones` —quién, cuándo, qué archivo, con qué
 * huella y con qué resultado— y una fila en `ppq_conciliacion_movimientos` por cada
 * renglón que efectivamente cambió, con el valor anterior y el nuevo. Un renglón que no
 * cambia no genera movimiento: la bitácora dice qué pasó, no qué se miró.
 *
 * TODO ocurre dentro de una transacción. Si algo falla a mitad, no queda ni la corrida ni
 * un solo renglón a medio actualizar.
 *
 * ═══════════════════════════ Aritmética de dinero ═══════════════════════════
 *
 * Todos los importes se manejan con {@see Dinero} (BCMath sobre cadenas), nunca con
 * `float`. No es purismo: acá se COMPARA un importe guardado contra uno recién leído para
 * decidir si hubo cambio, y con coma flotante dos valores que representan los mismos
 * centavos pueden no ser iguales. Eso produciría movimientos fantasma en la bitácora —o,
 * al revés, un cambio real que no se registra— y totales que no cuadran por un centavo.
 * Es el mismo criterio que usa el motor DTE para todo lo monetario.
 *
 * No toca el Excel oficial del cliente, ni los DTE, ni los albaranes.
 */
class ConciliadorPpq
{
    /** Tipos del TXT que identifican un DOCUMENTO del lote y por tanto pueden colisionar. */
    private const TIPOS_DE_DOCUMENTO = ['CF', 'NC'];

    /**
     * Aplica el archivo al lote y devuelve el resumen para pantalla.
     *
     * @param  array<int, array<string, mixed>>  $filas  salida de ConciliacionTxtParser::parse()
     * @param  ArchivoConciliacion|null  $archivo  la evidencia. Null solo en pruebas de la
     *                                             lógica de cruce que no verifican bitácora.
     * @return array<string, mixed>
     *
     * @throws ConciliacionYaProcesadaException si ese mismo archivo ya se aplicó al lote
     * @throws ArchivoConciliacionInconsistenteException si el archivo se contradice
     */
    public function conciliar(PpqLote $lote, array $filas, ?User $usuario = null, ?ArchivoConciliacion $archivo = null): array
    {
        $lote->loadMissing('items');

        if ($archivo !== null) {
            $anterior = PpqConciliacion::yaProcesado($lote->id, $archivo->hash);

            if ($anterior !== null) {
                throw new ConciliacionYaProcesadaException($anterior);
            }
        }

        // Indexar puede rechazar el archivo. Va ANTES de abrir la transacción: un archivo
        // que se contradice no llega siquiera a tocar un renglón.
        [$cf, $nc, $qd, $repetidas] = $this->indexar($filas);

        $usadosCf = [];
        $usadosNc = [];
        $ccfPagados = [];
        $ccfPendientes = [];
        $ncAplicadas = [];
        $ncPendientes = [];
        $conservados = [];
        $movimientos = [];
        $sinCambio = 0;

        try {
            $corrida = DB::transaction(function () use (
                $lote, $cf, $nc, $qd, $filas, $usuario, $archivo, $repetidas,
                &$usadosCf, &$usadosNc, &$ccfPagados, &$ccfPendientes,
                &$ncAplicadas, &$ncPendientes, &$conservados, &$movimientos, &$sinCambio,
            ) {
                foreach ($lote->itemsOrdenados() as $item) {
                    $clave = $item->numeroNormalizado();
                    $esNc = $item->esNc();
                    $fila = $clave !== null ? (($esNc ? $nc : $cf)[$clave] ?? null) : null;

                    // ─── El archivo no habla de este renglón: NO SE TOCA. ───
                    if ($fila === null) {
                        $sinCambio++;

                        if ($item->estaConciliado()) {
                            // Tenía un pago de una corrida anterior y lo conserva. Esta es
                            // exactamente la fila que antes se borraba en silencio.
                            $conservados[] = $item;
                        } elseif ($esNc) {
                            $ncPendientes[] = $item;
                        } else {
                            $ccfPendientes[] = $item;
                        }

                        continue;
                    }

                    $esNc ? ($usadosNc[$clave] = true) : ($usadosCf[$clave] = true);

                    $movimiento = $this->aplicar($item, $esNc ? 'aplicada' : 'pagado', $fila);

                    if ($movimiento === null) {
                        $sinCambio++;
                    } else {
                        $movimientos[] = $movimiento;
                    }

                    $esNc
                        ? $ncAplicadas[] = $this->detalle($item, $fila)
                        : $ccfPagados[] = $this->detalle($item, $fila);
                }

                return $this->registrar($lote, $usuario, $archivo, $filas, $cf, $nc, $qd, $movimientos, $sinCambio, $repetidas);
            });
        } catch (QueryException $e) {
            // El índice único ganó la carrera contra otra pestaña que subía el mismo
            // archivo. 23000 = violación de integridad.
            if (($e->errorInfo[0] ?? null) === '23000' && $archivo !== null) {
                $anterior = PpqConciliacion::yaProcesado($lote->id, $archivo->hash);

                if ($anterior !== null) {
                    throw new ConciliacionYaProcesadaException($anterior);
                }
            }

            throw $e;
        }

        return [
            'corrida' => $corrida,
            'ccfPagados' => $ccfPagados,
            'ccfPendientes' => $ccfPendientes,
            'ncAplicadas' => $ncAplicadas,
            'ncPendientes' => $ncPendientes,
            // Los que ya estaban cobrados y este archivo no menciona. Se listan aparte
            // porque son la prueba visible de que la corrida no los borró.
            'conservados' => $conservados,
            // Documentos que venían más de una vez con datos IDÉNTICOS. No cambian nada,
            // pero se informan: un archivo que repite filas suele venir mal armado.
            'repetidas' => $repetidas,
            'noEnPpq' => $this->noEnPpq($cf, $nc, $usadosCf, $usadosNc),
            'ajustesQd' => $qd,
            'totales' => $this->totales($cf, $nc, $qd, $ccfPagados, $ccfPendientes, $ncAplicadas, $ncPendientes, $conservados, $repetidas),
        ];
    }

    /**
     * Índices del TXT por número normalizado, separados por tipo, rechazando el archivo si
     * se contradice.
     *
     * ─────────────────────── Por qué el duplicado importa tanto ───────────────────────
     *
     * La versión anterior hacía `$cf[$numero] = $fila` en un bucle: con el número repetido,
     * ganaba la ÚLTIMA línea, en silencio. Si las dos filas traían importes distintos, el
     * renglón quedaba cobrado por una cifra elegida por el orden del archivo y no por una
     * persona.
     *
     * La comparación es por (tipo, fecha, importe) y el importe se compara con BCMath: dos
     * escrituras del mismo dinero —«126.44» y «126.440»— son la misma fila repetida, no una
     * contradicción.
     *
     * Los QD quedan FUERA de esta comprobación a propósito: su «número» (`PPQ/19891`) no
     * identifica ningún documento del lote y no se imputa a ningún renglón, así que
     * repetirlo no puede producir un cobro equivocado. Dos ajustes distintos con la misma
     * referencia son perfectamente posibles.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>, 2: array<int, array<string, mixed>>, 3: array<int, array<string, mixed>>}
     *
     * @throws ArchivoConciliacionInconsistenteException
     */
    private function indexar(array $filas): array
    {
        /** @var array<string, array<int, array<string, mixed>>> $porNumero */
        $porNumero = [];
        $qd = [];

        foreach ($filas as $f) {
            if (! in_array($f['tipo'], self::TIPOS_DE_DOCUMENTO, true)) {
                if ($f['tipo'] === 'QD') {
                    $qd[] = $f;
                }

                continue; // tipos no esperados se ignoran, como siempre
            }

            if ($f['numeroNorm'] === null) {
                continue; // fila de documento sin número: no identifica nada
            }

            $porNumero[$f['numeroNorm']][] = $f;
        }

        $cf = [];
        $nc = [];
        $repetidas = [];

        foreach ($porNumero as $numero => $delNumero) {
            if (count($delNumero) > 1) {
                if (! $this->todasIguales($delNumero)) {
                    throw new ArchivoConciliacionInconsistenteException((string) $delNumero[0]['numero'], $delNumero);
                }

                // Idénticas: no hay nada que decidir. Se conserva la primera y se informa.
                $repetidas[] = ['fila' => $delNumero[0], 'veces' => count($delNumero)];
            }

            $fila = $delNumero[0];
            $fila['tipo'] === 'NC'
                ? $nc[$numero] = $fila
                : $cf[$numero] = $fila;
        }

        return [$cf, $nc, $qd, $repetidas];
    }

    /**
     * ¿Todas estas filas dicen exactamente lo mismo? Compara tipo, fecha e importe; el
     * importe con BCMath, para que dos escrituras del mismo dinero no pasen por distintas.
     *
     * @param  array<int, array<string, mixed>>  $filas
     */
    private function todasIguales(array $filas): bool
    {
        $primera = $filas[0];

        foreach (array_slice($filas, 1) as $otra) {
            if ($otra['tipo'] !== $primera['tipo'] || $otra['fecha'] !== $primera['fecha']) {
                return false;
            }

            if (($otra['valor'] === null) !== ($primera['valor'] === null)) {
                return false;
            }

            if ($otra['valor'] !== null && Dinero::comparar($otra['valor'], $primera['valor']) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Escribe el estado que dice el archivo, SI cambia algo. Devuelve el movimiento a
     * registrar, o `null` si el renglón ya estaba exactamente así.
     *
     * Comparar antes de escribir no es una optimización: es lo que permite que la bitácora
     * distinga «este archivo actualizó 12 renglones» de «este archivo no cambió nada»,
     * que es la diferencia entre un pago nuevo y un reproceso. Y por eso la comparación de
     * importes va con BCMath: con `float`, dos representaciones de los mismos centavos
     * pueden dar distinto y generar un movimiento que no ocurrió.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>|null
     */
    private function aplicar(PpqItem $item, string $estado, array $fila): ?array
    {
        $fecha = $fila['fecha'] ?? null;
        $monto = $fila['valor'] === null ? null : $this->importe($fila['valor']);

        $estadoAnterior = $item->conciliacion_estado;
        $fechaAnterior = $item->fecha_pago?->toDateString();
        $montoAnterior = $item->monto_pagado === null ? null : Dinero::redondear($item->monto_pagado);

        $mismoMonto = $montoAnterior === null && $monto === null
            ? true
            : ($montoAnterior !== null && $monto !== null && Dinero::comparar($montoAnterior, $monto) === 0);

        if ($estadoAnterior === $estado && $fechaAnterior === $fecha && $mismoMonto) {
            return null;
        }

        $item->forceFill([
            'conciliacion_estado' => $estado,
            'fecha_pago' => $fecha,
            'monto_pagado' => $monto,
            'conciliado_en' => now(),
        ])->save();

        return [
            'ppq_item_id' => $item->id,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $estado,
            'fecha_pago_anterior' => $fechaAnterior,
            'fecha_pago_nueva' => $fecha,
            'monto_pagado_anterior' => $montoAnterior,
            'monto_pagado_nuevo' => $monto,
            'linea_txt' => $fila['linea'] ?? null,
        ];
    }

    /**
     * Importe del TXT en valor absoluto y a dos decimales, como cadena.
     *
     * El archivo escribe las NC y los ajustes en negativo porque son abonos; acá se guarda
     * la magnitud, que es lo que se compara contra el documento. El signo lo pone el tipo
     * de renglón, no el importe ({@see PpqItem::signo()}), y arrastrar dos convenios de
     * signo en la misma columna es cómo se termina restando dos veces.
     */
    private function importe(string|int|float $valor): string
    {
        $redondeado = Dinero::redondear($valor);

        return Dinero::comparar($redondeado, '0') < 0
            ? Dinero::redondear(Dinero::restar('0', $redondeado))
            : $redondeado;
    }

    /**
     * Deja la constancia de la corrida y de cada renglón que cambió.
     *
     * @param  array<string, array<string, mixed>>  $cf
     * @param  array<string, array<string, mixed>>  $nc
     * @param  array<int, array<string, mixed>>  $qd
     * @param  array<int, array<string, mixed>>  $movimientos
     * @param  array<int, array<string, mixed>>  $repetidas
     */
    private function registrar(
        PpqLote $lote,
        ?User $usuario,
        ?ArchivoConciliacion $archivo,
        array $filas,
        array $cf,
        array $nc,
        array $qd,
        array $movimientos,
        int $sinCambio,
        array $repetidas,
    ): PpqConciliacion {
        $corrida = PpqConciliacion::create([
            'ppq_lote_id' => $lote->id,
            'user_id' => $usuario?->id,
            'origen' => OrigenConciliacionPpq::Txt,
            'archivo_nombre' => $archivo?->nombre,
            'archivo_hash' => $archivo?->hash,
            'archivo_path' => $archivo?->ruta,
            'total_filas' => count($filas),
            'filas_cf' => count($cf),
            'filas_nc' => count($nc),
            'filas_qd' => count($qd),
            'items_cambiados' => count($movimientos),
            'items_sin_cambio' => $sinCambio,
        ]);

        foreach ($movimientos as $movimiento) {
            PpqConciliacionMovimiento::create($movimiento + ['ppq_conciliacion_id' => $corrida->id]);
        }

        activity('ppq_conciliacion')
            ->performedOn($lote)
            ->causedBy($usuario)
            ->withProperties([
                'conciliacion_id' => $corrida->id,
                'archivo' => $archivo?->nombre,
                'hash' => $archivo?->hash,
                'filas' => count($filas),
                'items_cambiados' => count($movimientos),
                'items_sin_cambio' => $sinCambio,
                // Se registra aunque no cambie nada: un archivo que repite filas suele
                // venir mal armado y conviene poder verlo después.
                'filas_repetidas' => count($repetidas),
            ])
            ->log('concilió el lote contra el archivo de pagos');

        return $corrida;
    }

    /**
     * Documentos del TXT (CF/NC) que no están agregados a este lote.
     *
     * @param  array<string, array<string, mixed>>  $cf
     * @param  array<string, array<string, mixed>>  $nc
     * @param  array<string, bool>  $usadosCf
     * @param  array<string, bool>  $usadosNc
     * @return array<int, array<string, mixed>>
     */
    private function noEnPpq(array $cf, array $nc, array $usadosCf, array $usadosNc): array
    {
        $fuera = [];

        foreach ($cf as $k => $f) {
            if (! isset($usadosCf[$k])) {
                $fuera[] = $f;
            }
        }

        foreach ($nc as $k => $f) {
            if (! isset($usadosNc[$k])) {
                $fuera[] = $f;
            }
        }

        return $fuera;
    }

    /**
     * Fila de detalle para el resumen: item + datos del TXT + diferencia de monto.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function detalle(PpqItem $item, array $fila): array
    {
        $montoTxt = $fila['valor'] === null ? null : $this->importe($fila['valor']);

        return [
            'item' => $item,
            'linea' => $fila['linea'],
            'fecha' => $fila['fecha'],
            'monto_txt' => $montoTxt,
            'diferencia' => $montoTxt === null
                ? null
                : Dinero::redondear(Dinero::restar($item->monto_dte ?? '0', $montoTxt)),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $cf
     * @param  array<string, array<string, mixed>>  $nc
     * @param  array<int, array<string, mixed>>  $qd
     * @param  array<int, array<string, mixed>>  $repetidas
     * @return array<string, int|string>
     */
    private function totales(array $cf, array $nc, array $qd, array $ccfPagados, array $ccfPendientes, array $ncAplicadas, array $ncPendientes, array $conservados, array $repetidas): array
    {
        // Suma exacta: `array_sum` sobre floats acumula el error una vez por fila, y un
        // lote de sesenta renglones lo vuelve visible en el neto final.
        $sum = function (array $filas): string {
            $total = '0';
            foreach ($filas as $f) {
                $total = Dinero::sumar($total, $f['valor'] ?? 0);
            }

            return Dinero::redondear($total);
        };

        $totalCf = $sum(array_values($cf));         // CF del TXT (positivo)
        $totalNc = $sum(array_values($nc));         // NC del TXT (negativo)
        $totalQd = $sum($qd);                        // QD del TXT (negativo)

        return [
            'cantidad_ccf_pagados' => count($ccfPagados),
            'cantidad_ccf_pendientes' => count($ccfPendientes),
            'cantidad_nc_aplicadas' => count($ncAplicadas),
            'cantidad_nc_pendientes' => count($ncPendientes),
            // Renglones con un cobro anterior que este archivo no menciona y que se
            // conservaron tal cual.
            'cantidad_conservados' => count($conservados),
            'cantidad_repetidas' => count($repetidas),
            'cantidad_no_en_ppq' => (count($cf) - count($ccfPagados)) + (count($nc) - count($ncAplicadas)),
            'cantidad_qd' => count($qd),
            'total_ccf_pagado' => $totalCf,
            'total_nc_descontado' => $totalNc,
            'total_qd' => $totalQd,
            'neto_final' => Dinero::redondear(Dinero::sumar(Dinero::sumar($totalCf, $totalNc), $totalQd)),
        ];
    }
}
