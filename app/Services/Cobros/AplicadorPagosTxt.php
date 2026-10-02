<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\EstadoEventoCobro;
use App\Enums\Cobros\TipoEventoCobro;
use App\Exceptions\Ppq\ArchivoConciliacionInconsistenteException;
use App\Exceptions\Ppq\ArchivoProveedorInvalidoException;
use App\Models\Cliente;
use App\Models\Cobros\CobroAjuste;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\User;
use App\Services\Ppq\ArchivoConciliacion;
use App\Services\Ppq\ConciliacionTxtParser;
use App\Services\Ppq\ValidadorCodigoProveedorTxt;
use App\Support\Dinero;
use App\Support\IdentidadPpq;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aplica un archivo de pagos del cliente (ya leído por
 * {@see ConciliacionTxtParser}) al SEGUIMIENTO de cobros.
 *
 * ═══════════════ La costura: acá no se lee ningún archivo ═══════════════
 *
 * Este servicio recibe FILAS ya normalizadas, no un TXT. Esa separación es a propósito: el
 * día que el portal ofrezca otro archivo —o una consulta— basta con un lector nuevo que
 * produzca las mismas filas, y el seguimiento no se entera. Mezclar el formato con la
 * lógica de cobro es lo que obliga a rehacerlo todo cada vez que el cliente cambia algo.
 *
 * ═══════════════════ Las cuatro reglas que no se negocian ═══════════════════
 *
 *  0. **Toda fila es del proveedor esperado, o el archivo no se aplica.** Los pagos de
 *     este circuito son siempre del proveedor configurado; una
 *     fila con otro código —o sin código— no es un pago suyo, aunque su número coincida
 *     con un documento local. Se verifica ANTES de tocar nada, con
 *     {@see ValidadorCodigoProveedorTxt}: una sola fila ajena rechaza el
 *     archivo COMPLETO, no solo esa fila.
 *
 *  1. **El archivo solo habla de lo que trae.** Un documento que no aparece NO se toca:
 *     conserva el pago que otro archivo le haya informado. «No está en el archivo» y «no
 *     está pagado» son afirmaciones distintas; confundirlas fue lo que en el circuito
 *     anterior borraba cobros ya registrados.
 *
 *  2. **Nada se cuenta dos veces, y lo dudoso no se cuenta solo.** Cada pago es un EVENTO
 *     con la huella del archivo y el número de línea. El único de la tabla impide que la
 *     MISMA línea del MISMO archivo entre otra vez —recarga, archivos solapados—.
 *
 *     Para el otro caso, el mismo pago en DOS ARCHIVOS DISTINTOS, esa llave no alcanza:
 *     huellas distintas son eventos distintos. Ahí el segundo pago se registra pero queda
 *     EN REVISIÓN y no suma, porque «el cliente lo repitió» y «el cliente pagó en dos
 *     abonos» son indistinguibles desde el archivo. Ver {@see EstadoEventoCobro} y
 *     {@see estadoDelPago()}.
 *
 *     Y los acumulados se RECALCULAN desde los eventos que cuentan, no se incrementan:
 *     aunque algo se colara, la suma no se infla.
 *
 *  3. **`FECHA_DOCUMENTO` no es la fecha del pago.** El archivo trae la fecha del
 *     DOCUMENTO; la fecha en que el cliente pagó no está en él. Se guarda la del documento
 *     como dato del evento, la de CARGA es el `created_at`, y la de PAGO solo se escribe si
 *     alguien la aporta como evidencia. Tres fechas distintas en tres lugares distintos.
 *
 * ═══════════════════════ Los descuentos globales (QD) ═══════════════════════
 *
 * No se reparten entre las facturas. Ver {@see CobroAjuste}.
 *
 * No emite, no firma, no transmite y no toca ningún valor fiscal.
 */
class AplicadorPagosTxt
{
    /** Tipos del archivo que identifican un DOCUMENTO y por tanto pueden contradecirse. */
    private const TIPOS_DOCUMENTO = ['CF', 'NC'];

    public function __construct(
        private readonly ValidadorCodigoProveedorTxt $validadorProveedor,
    ) {}

    /**
     * Aplica el archivo al seguimiento del cliente y devuelve el informe para pantalla.
     *
     * @param  array<int, array<string, mixed>>  $filas  salida de ConciliacionTxtParser::parse()
     * @param  Carbon|null  $fechaPago  la del pago REAL, si alguien la aporta. El archivo no la trae.
     * @return array<string, mixed>
     *
     * @throws ArchivoConciliacionInconsistenteException
     * @throws ArchivoProveedorInvalidoException si alguna fila trae un código de proveedor distinto del esperado
     */
    public function aplicar(
        Cliente $cliente,
        array $filas,
        ArchivoConciliacion $archivo,
        ?User $usuario = null,
        ?Carbon $fechaPago = null,
    ): array {
        // Antes que cualquier otra cosa: un archivo de otro proveedor no es evidencia de
        // ningún pago de este cliente, aunque algún número coincida con lo local.
        $this->validadorProveedor->verificar($filas);

        [$documentos, $ajustes, $repetidas, $invalidas, $otrosTipos] = $this->clasificar($filas);

        $informe = [
            'archivo' => $archivo->nombre,
            'hash' => $archivo->hash,
            'fecha_carga' => now(),
            'fecha_pago' => $fechaPago,
            'aplicados' => [],
            'sin_cambio' => [],
            // Pagos que este archivo informa sobre documentos que YA tenían un pago de otra
            // evidencia. No suman hasta que una persona diga qué son.
            'en_revision' => [],
            'no_identificados' => [],
            'invalidas' => $invalidas,
            'repetidas' => $repetidas,
            'otros_tipos' => $otrosTipos,
            'ajustes' => [],
            'conservados' => [],
            'proveedor' => $this->proveedor($filas, ValidadorCodigoProveedorTxt::codigoConfigurado()),
            // Si este MISMO archivo ya se concilió en algún lote PPQ. Solo se informa: el
            // tratamiento de acá no cambia ni se copia nada de allá.
            'en_ppq' => app(EvidenciaEntreCircuitos::class)->enPpq($archivo->hash),
        ];

        DB::transaction(function () use ($cliente, $documentos, $ajustes, $archivo, $usuario, $fechaPago, &$informe) {
            foreach ($documentos as $clave => $fila) {
                $documento = CobroDocumento::deCliente($cliente->id)
                    ->where('numero_control_norm', $clave)
                    ->lockForUpdate()
                    ->first();

                if ($documento === null) {
                    // No se crea nada a partir del archivo: un número que no está en el
                    // seguimiento puede ser de otro proveedor, de otra empresa o un error
                    // de tecleo del cliente. Se muestra para que alguien lo mire.
                    $informe['no_identificados'][] = $fila;

                    continue;
                }

                $antes = (string) $documento->monto_pagado;
                $evento = $this->registrarPago($documento, $fila, $archivo, $usuario, $fechaPago);
                $documento->recalcularPago();

                $detalle = [
                    'documento' => $documento->refresh(),
                    'fila' => $fila,
                    'evento' => $evento,
                    'monto_archivo' => $this->magnitud($fila['valor']),
                    // Contra lo esperado (el CCF menos sus NC aceptadas), no contra el bruto.
                    'diferencia' => $documento->saldo(),
                ];

                // Tres destinos, no dos: lo que se aplicó, lo que ya estaba igual, y lo que
                // quedó EN REVISIÓN porque este archivo repite un pago que otro ya informó.
                // Ese tercer grupo es el que antes se sumaba sin más y cobraba dos veces.
                if ($evento->estado === EstadoEventoCobro::EnRevision) {
                    $informe['en_revision'][] = $detalle;
                } elseif ($evento->wasRecentlyCreated || Dinero::comparar($antes, (string) $documento->monto_pagado) !== 0) {
                    $informe['aplicados'][] = $detalle;
                } else {
                    $informe['sin_cambio'][] = $detalle;
                }
            }

            foreach ($ajustes as $fila) {
                $informe['ajustes'][] = $this->registrarAjuste($cliente, $fila, $archivo, $usuario);
            }
        });

        // Los que ya tenían cobro y este archivo NO menciona. Se listan aparte: son la
        // prueba visible de que la carga no los borró.
        $informe['conservados'] = CobroDocumento::deCliente($cliente->id)
            ->where('monto_pagado', '>', 0)
            ->whereNotIn('numero_control_norm', array_keys($documentos))
            ->porAntiguedad()
            ->get();

        $informe['totales'] = $this->totales($documentos, $ajustes, $informe);

        return $informe;
    }

    /**
     * Separa las filas por tipo y rechaza el archivo si se contradice.
     *
     * Un documento repetido con datos DISTINTOS no se resuelve quedándose con el último:
     * eso haría que el importe cobrado dependiera del orden de las líneas. Repetido con
     * datos idénticos sí se acepta —no hay nada que decidir— y se informa, porque un
     * archivo que repite filas suele venir mal armado.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array{0: array<string, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>, 3: array<int, array<string, mixed>>, 4: array<int, array<string, mixed>>}
     *
     * @throws ArchivoConciliacionInconsistenteException
     */
    private function clasificar(array $filas): array
    {
        $porNumero = [];
        $ajustes = [];
        $invalidas = [];
        $otrosTipos = [];

        foreach ($filas as $fila) {
            if ($fila['tipo'] === 'QD') {
                $ajustes[] = $fila;

                continue;
            }

            if (! in_array($fila['tipo'], self::TIPOS_DOCUMENTO, true)) {
                // Ni se descarta en silencio ni se interpreta: se muestra.
                $otrosTipos[] = $fila;

                continue;
            }

            if ($fila['numeroNorm'] === null || $fila['valor'] === null) {
                // Una fila de documento sin número o sin importe no identifica ni informa
                // nada. Se muestra para que el cliente la corrija.
                $invalidas[] = $fila;

                continue;
            }

            $porNumero[$fila['numeroNorm']][] = $fila;
        }

        $documentos = [];
        $repetidas = [];

        foreach ($porNumero as $numero => $delNumero) {
            if (count($delNumero) > 1) {
                if (! $this->todasIguales($delNumero)) {
                    throw new ArchivoConciliacionInconsistenteException((string) $delNumero[0]['numero'], $delNumero);
                }

                $repetidas[] = ['fila' => $delNumero[0], 'veces' => count($delNumero)];
            }

            $documentos[$numero] = $delNumero[0];
        }

        return [$documentos, $ajustes, $repetidas, $invalidas, $otrosTipos];
    }

    /**
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
     * Anota el pago como evento y lo devuelve.
     *
     * ══════════════ Dos defensas distintas contra cobrar dos veces ══════════════
     *
     * 1. **La misma línea del mismo archivo.** `firstOrCreate` sobre la llave de evidencia
     *    —documento + tipo + huella + línea— la bloquea. Recargar el archivo, o subir dos
     *    que se solapan, no crea un segundo evento.
     *
     * 2. **El mismo pago en DOS ARCHIVOS DISTINTOS.** La llave anterior no lo ve: huellas
     *    distintas son eventos distintos, y al sumarlos la factura quedaba cobrada dos
     *    veces. Acá es donde entra {@see EstadoEventoCobro::EnRevision}: si el documento ya
     *    tiene un pago APLICADO que viene de OTRA evidencia, el nuevo se registra pero NO
     *    suma.
     *
     *    No se descarta por importe igual —eso perdería los abonos parciales legítimos— ni
     *    se aplica por importe distinto —el cliente puede repetir con un centavo de
     *    diferencia—. Desde el archivo las dos situaciones son indistinguibles, así que la
     *    decisión es de una persona y el motivo lo dice.
     */
    private function registrarPago(
        CobroDocumento $documento,
        array $fila,
        ArchivoConciliacion $archivo,
        ?User $usuario,
        ?Carbon $fechaPago,
    ): CobroEvento {
        [$estado, $motivo] = $this->estadoDelPago($documento, $fila, $archivo);

        $evento = CobroEvento::firstOrCreate(
            [
                'cobro_documento_id' => $documento->id,
                'tipo' => TipoEventoCobro::Pago->value,
                'evidencia_hash' => $archivo->hash,
                'referencia_linea' => (string) $fila['linea'],
            ],
            [
                'origen' => 'txt',
                'estado' => $estado->value,
                'estado_motivo' => $motivo,
                // La MAGNITUD: el signo del archivo indica el tipo de renglón (la NC viene
                // en negativo porque abona), pero lo que se compara contra el documento es
                // cuánto se cobró de él. Arrastrar dos convenios de signo en la misma
                // columna es cómo se termina restando dos veces.
                'monto' => $this->magnitud($fila['valor']),
                // La del PAGO solo si alguien la aporta: el archivo no la trae.
                'fecha' => $fechaPago?->toDateString(),
                'evidencia_nombre' => $archivo->nombre,
                'detalle' => 'Informado como '.$fila['tipo'].' en el archivo de pagos, línea '.$fila['linea'].'.',
                'datos' => [
                    'tipo_txt' => $fila['tipo'],
                    // La fecha que trae el archivo es la del DOCUMENTO. Se guarda con su
                    // nombre para que nadie la confunda con la del pago.
                    'fecha_documento_txt' => $fila['fecha'],
                    'valor_txt' => $fila['valor'],
                    'numero_txt' => $fila['numero'],
                ],
                'user_id' => $usuario?->id,
            ],
        );

        return $evento;
    }

    /**
     * Con qué estado entra un pago: aplicado, o en revisión porque ya había otro.
     *
     * La comprobación es por EVIDENCIA, no por importe: lo que dispara la revisión es que
     * el documento ya tenga un pago aplicado procedente de OTRO archivo. Si viniera del
     * mismo, `firstOrCreate` ni siquiera llegaría acá.
     *
     * @param  array<string, mixed>  $fila
     * @return array{0: EstadoEventoCobro, 1: ?string}
     */
    private function estadoDelPago(CobroDocumento $documento, array $fila, ArchivoConciliacion $archivo): array
    {
        $previo = CobroEvento::query()
            ->where('cobro_documento_id', $documento->id)
            ->where('tipo', TipoEventoCobro::Pago->value)
            ->where('estado', EstadoEventoCobro::Aplicado->value)
            ->where(fn ($q) => $q->where('evidencia_hash', '!=', $archivo->hash)->orWhereNull('evidencia_hash'))
            ->orderBy('id')
            ->first();

        if ($previo === null) {
            return [EstadoEventoCobro::Aplicado, null];
        }

        $nuevo = $this->magnitud($fila['valor']);
        $anterior = Dinero::redondear($previo->monto ?? '0');
        $sumados = Dinero::redondear(Dinero::sumar($anterior, $nuevo));
        $facturado = Dinero::redondear($documento->monto ?? '0');

        // Se dice lo que se sabe y lo que no. Las dos lecturas posibles van en el motivo
        // porque son exactamente las dos que la persona tiene que distinguir.
        $motivo = sprintf(
            'Anterior: %s; este pago: %s; documento: %s; suma: %s. '
                .'Puede que REPITIERA el pago o sean DOS ABONOS. No cuenta hasta decidirlo. '
                .'Archivos: «%s» / «%s».',
            $anterior,
            $nuevo,
            $facturado,
            $sumados,
            $previo->evidencia_nombre ?: 'un archivo anterior',
            $archivo->nombre,
        );

        return [EstadoEventoCobro::EnRevision, $motivo];
    }

    /**
     * Guarda el ajuste (QD) y lo relaciona con la SOLICITUD por su referencia, sin imputarlo
     * a ninguna factura.
     *
     * @return array<string, mixed>
     */
    private function registrarAjuste(Cliente $cliente, array $fila, ArchivoConciliacion $archivo, ?User $usuario): array
    {
        $referencia = (string) $fila['numero'];
        $referenciaCalleja = CobroAjuste::referenciaCalleja($referencia);

        Cliente::whereKey($cliente->id)->lockForUpdate()->firstOrFail();
        $previo = $referenciaCalleja === null ? null : CobroAjuste::query()
            ->where('cliente_id', $cliente->id)
            ->where('referencia_calleja', $referenciaCalleja)
            ->where('monto', $fila['valor'])->whereNotNull('nc_dte_id')->latest('id')->first();

        $solicitud = $referenciaCalleja === null
            ? null
            : CobroSolicitud::where('cliente_id', $cliente->id)
                ->where('referencia_calleja', $referenciaCalleja)
                ->latest('id')
                ->first();

        $ajuste = CobroAjuste::firstOrCreate(
            [
                'evidencia_hash' => $archivo->hash,
                'referencia_linea' => (string) $fila['linea'],
            ],
            [
                'cliente_id' => $cliente->id,
                'referencia' => $referencia,
                'referencia_calleja' => $referenciaCalleja,
                'cobro_solicitud_id' => $solicitud?->id,
                'nc_dte_id' => $previo?->nc_dte_id,
                'monto' => $fila['valor'],
                'evidencia_nombre' => $archivo->nombre,
                'motivo' => $previo
                    ? 'Misma deducción informada nuevamente; se conserva la nota de crédito del ajuste #'.$previo->id.'.'
                    : ($solicitud === null
                    ? 'No se encontró ninguna solicitud con la referencia '.($referenciaCalleja ?? '—')
                        .'. Queda para revisión manual.'
                    : 'Descuento de pronto pago sobre la solicitud '.$solicitud->referencia
                        .'. Pendiente de emitir la nota de crédito por el circuito de NC.'),
                'user_id' => $usuario?->id,
            ],
        );

        return ['ajuste' => $ajuste, 'fila' => $fila, 'solicitud' => $solicitud, 'nuevo' => $ajuste->wasRecentlyCreated];
    }

    /**
     * Códigos de proveedor presentes en el archivo, para la pantalla.
     *
     * Ya no puede llegar acá un código ajeno: {@see ValidadorCodigoProveedorTxt} rechazó
     * el archivo entero antes de armar este informe. Queda como constancia explícita de
     * que el archivo aplicado es del proveedor esperado.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    private function proveedor(array $filas, string $esperado): array
    {
        $codigos = [];
        foreach ($filas as $fila) {
            if (preg_match('/^\s*([^;]+);/', $fila['raw'], $m)) {
                $codigos[trim($m[1])] = true;
            }
        }
        $codigos = array_keys($codigos);

        return [
            'esperado' => $esperado,
            'en_archivo' => $codigos,
            'coincide' => $codigos === [] || in_array($esperado, $codigos, true),
            'ajenos' => array_values(array_filter($codigos, fn ($c) => $c !== $esperado)),
        ];
    }

    /** Magnitud del importe: el signo lo pone el tipo de renglón, no la columna. */
    private function magnitud(string|int|float|null $valor): string
    {
        $redondeado = Dinero::redondear($valor ?? '0');

        return Dinero::comparar($redondeado, '0') < 0
            ? Dinero::redondear(Dinero::restar('0', $redondeado))
            : $redondeado;
    }

    /**
     * @param  array<string, array<string, mixed>>  $documentos
     * @param  array<int, array<string, mixed>>  $ajustes
     * @param  array<string, mixed>  $informe
     * @return array<string, int|string>
     */
    private function totales(array $documentos, array $ajustes, array $informe): array
    {
        $suma = function (array $filas, ?string $tipo = null): string {
            $total = '0';
            foreach ($filas as $f) {
                if ($tipo === null || $f['tipo'] === $tipo) {
                    $total = Dinero::sumar($total, $f['valor'] ?? 0);
                }
            }

            return Dinero::redondear($total);
        };

        $cf = $suma($documentos, 'CF');
        $nc = $suma($documentos, 'NC');
        $qd = $suma($ajustes);

        return [
            'cantidad_cf' => count(array_filter($documentos, fn ($f) => $f['tipo'] === 'CF')),
            'cantidad_nc' => count(array_filter($documentos, fn ($f) => $f['tipo'] === 'NC')),
            'cantidad_qd' => count($ajustes),
            'cantidad_aplicados' => count($informe['aplicados']),
            'cantidad_sin_cambio' => count($informe['sin_cambio']),
            'cantidad_en_revision' => count($informe['en_revision']),
            'cantidad_no_identificados' => count($informe['no_identificados']),
            'cantidad_invalidas' => count($informe['invalidas']),
            'cantidad_repetidas' => count($informe['repetidas']),
            'total_cf' => $cf,
            'total_nc' => $nc,
            'total_qd' => $qd,
            'neto_archivo' => Dinero::redondear(Dinero::sumar(Dinero::sumar($cf, $nc), $qd)),
        ];
    }

    /**
     * Cruza un número del archivo con el seguimiento. Aislado para que la regla de
     * identidad sea una sola en todo el módulo ({@see IdentidadPpq}).
     */
    public static function clave(?string $numero): ?string
    {
        return IdentidadPpq::normalizar($numero);
    }
}
