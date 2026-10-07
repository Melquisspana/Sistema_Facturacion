<?php

namespace App\Services\Dte\Serializadores;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Models\Dte;
use App\Services\Dte\ValidadorReglasInvalidacion;
use App\Support\Dte\CodigoGeneracion;
use App\Support\Dte\PoliticaInvalidacion;
use App\Support\HoraNegocio;

/**
 * Serializa el EVENTO DE INVALIDACIÓN oficial de un DTE ya aceptado por el MH, a la
 * estructura del schema `invalidacion-schema-v3.json` (bloques `identificacion`,
 * `emisor`, `documento`, `motivo`). Es el evento que — en una fase POSTERIOR — se
 * firma y se transmite a `/fesv/anulardte`.
 *
 * FASE B (preparación): este serializador SOLO produce el array del evento. NO firma,
 * NO transmite, NO cambia el estado del DTE, NO toca `sello_recepcion`, `respuesta_mh`
 * ni `fecha_procesamiento_mh`. Usa los datos REALES del DTE aceptado (sello, número de
 * control, código de generación, emisor y receptor) más los datos NUEVOS del evento
 * (tipo de anulación, motivo, responsable y solicitante) que aporta {@see EventoInvalidacionData}.
 *
 * El `identificacion.codigoGeneracion` del evento es un UUID NUEVO generado aquí,
 * SIEMPRE distinto al `codigoGeneracion` del DTE invalidado.
 *
 * Candados de dominio (lanza {@see DteNoSerializableException} sin producir JSON):
 *  - Solo se serializa el evento de un DTE ACEPTADO REALMENTE por Hacienda
 *    (estado aceptado + sello real no-MOCK + fecha de procesamiento del MH).
 *  - Las reglas FISCALES (matriz documento x motivo, verificación del sustituto y
 *    dependencia de notas vigentes) las aplica {@see ValidadorReglasInvalidacion}, el
 *    mismo objeto que usan el formulario, el preflight, el mock y la transmisión real.
 *    Se re-evalúan AQUÍ, en el último momento antes de firmar: lo que se mostró al abrir
 *    la pantalla no autoriza nada, y entre una cosa y otra pueden haber aparecido una
 *    nota de crédito nueva o la invalidación del sustituto elegido.
 */
class SerializadorInvalidacionMh
{
    public function __construct(
        private readonly ValidadorReglasInvalidacion $reglas,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws DteNoSerializableException
     */
    public function serializar(Dte $dte, EventoInvalidacionData $evento): array
    {
        $problemas = $this->candados($dte, $evento);
        if ($problemas !== []) {
            throw new DteNoSerializableException($problemas);
        }

        $dte->loadMissing(['establecimiento.empresa', 'puntoVenta', 'cliente']);

        return [
            'identificacion' => $this->identificacion($dte),
            'emisor' => $this->emisor($dte),
            'documento' => $this->documento($dte, $evento),
            'motivo' => $this->motivo($evento),
        ];
    }

    /**
     * Candados de dominio previos a serializar. No inventa datos: si algo falta o no
     * corresponde, lo reporta como problema.
     *
     * @return array<int, string>
     */
    private function candados(Dte $dte, EventoInvalidacionData $evento): array
    {
        $problemas = [];

        // Solo se invalida un DTE aceptado REALMENTE por el MH (no mock/simulado): su
        // codigoGeneracion existe en Hacienda y tiene sello + fecha de procesamiento.
        if (! $dte->aceptadoRealmentePorMh()) {
            $problemas[] = 'Solo se puede invalidar un DTE aceptado realmente por Hacienda '
                .'(estado aceptado, sello de recepción real y fecha de procesamiento del MH). '
                .'Estado actual: '.$dte->estado->label().'.';
        }

        // Códigos MH del establecimiento y punto de venta desde donde se transmite: con
        // formato inválido el MH rechaza el evento, así que se detiene antes de firmar.
        [$codEstableMH, $codPuntoVentaMH] = $this->codigosMh($dte);
        if (! preg_match('/^[MSBP]\d{3}$/', $codEstableMH)) {
            $problemas[] = 'El código de establecimiento asignado por el MH («'.$codEstableMH.'») no tiene el formato '
                .'M000, S000, B000 o P000 (Normativa 2.0, Anexo V, campo 24).';
        }
        if (! preg_match('/^P\d{3}$/', $codPuntoVentaMH)) {
            $problemas[] = 'El código de punto de venta asignado por el MH («'.$codPuntoVentaMH.'») no tiene el formato '
                .'P000 que usa el número de control (Normativa 2.0, Anexo V, campo 26).';
        }

        // Matriz documento x motivo, sustituto verificado y dependencias fiscales: una
        // sola fuente, revalidada justo antes de firmar.
        return array_merge($problemas, $this->reglas->problemas($dte, $evento));
    }

    /**
     * Códigos MH del establecimiento y del punto de venta DESDE DONDE SE TRANSMITE el evento.
     *
     * Normativa 2.0, Anexo V, Sección 3 (p.124): el emisor es el mismo del DTE, pero el
     * establecimiento y el punto de venta son los de donde se transmite el evento, «no
     * necesariamente» los del documento. Campos 24 y 26 (p.125): códigos asignados por el
     * MH (M000, S000, B000, P000). Los internos del contribuyente (campos 25 y 27) son
     * opcionales y van null.
     *
     * Este sistema transmite desde el mismo establecimiento y punto de venta que emitió el
     * DTE: sus códigos son los del número de control, que ya tienen el formato del MH. Si
     * algún día se transmite desde otro, se fija por configuración
     * (dte.invalidacion.cod_estable_mh / cod_punto_venta_mh) sin tocar código.
     *
     * @return array{0: string, 1: string}
     */
    private function codigosMh(Dte $dte): array
    {
        $dte->loadMissing(['establecimiento', 'puntoVenta']);

        return [
            (string) config('dte.invalidacion.cod_estable_mh') ?: (string) ($dte->establecimiento?->codigo ?? ''),
            (string) config('dte.invalidacion.cod_punto_venta_mh') ?: (string) ($dte->puntoVenta?->codigo ?? ''),
        ];
    }

    /** @return array<string, mixed> Bloque `identificacion` del evento (UUID nuevo). */
    private function identificacion(Dte $dte): array
    {
        // REGLA MH (confirmada por rechazo real de anulardte, codigoMsg 027
        // "[identificacion.fecEmi] DATO NO COINCIDE CON DTE"): la fecha de emisión del
        // EVENTO de invalidación debe coincidir con la fecha de emisión del DTE que se
        // invalida (documento.fecEmi), NO con la fecha actual del sistema. Por eso
        // fecEmi se toma del DTE original (misma fuente que documento.fecEmi).
        $fecEmiDte = $dte->fecha_emision?->format('Y-m-d') ?? '';

        // horEmi: el MH NO rechazó la hora (solo fecEmi). El evento es un acto distinto
        // al DTE, así que su hora es la del momento de la invalidación (hora de El Salvador). Si un
        // rechazo futuro indicara que horEmi también debe coincidir con la del DTE
        // (dte.hora_emision), se cambiaría aquí; por ahora se deja documentado.
        $horEmiEvento = HoraNegocio::ahora()->format('H:i:s');

        return [
            'version' => (int) config('dte.invalidacion.version', 3),
            'ambiente' => $dte->ambiente->value,
            // UUID NUEVO del evento: SIEMPRE distinto al codigoGeneracion del DTE.
            'codigoGeneracion' => CodigoGeneracion::generar(),
            'fecEmi' => $fecEmiDte,      // = documento.fecEmi (fecha del DTE invalidado)
            'horEmi' => $horEmiEvento,   // hora local del evento; ver nota arriba
            'fusion' => null,
        ];
    }

    /** @return array<string, mixed> Bloque `emisor` del evento (datos del emisor del DTE). */
    private function emisor(Dte $dte): array
    {
        $emp = $dte->establecimiento?->empresa;
        [$codEstableMH, $codPuntoVentaMH] = $this->codigosMh($dte);

        return [
            'nit' => $this->soloDigitos($emp?->nit),
            'nombre' => (string) ($emp?->razon_social ?? ''),
            'codEstableMH' => $codEstableMH,
            'codEstable' => null,
            'codPuntoVentaMH' => $codPuntoVentaMH,
            'codPuntoVenta' => null,
            'telefono' => (string) ($emp?->telefono ?? ''),
            'correo' => (string) ($emp?->correo ?? ''),
        ];
    }

    /**
     * @return array<string, mixed> Bloque `documento` (datos REALES del DTE aceptado + receptor).
     */
    private function documento(Dte $dte, EventoInvalidacionData $evento): array
    {
        $r = $dte->cliente;

        // El sustituto viaja SOLO en las celdas de la matriz que lo exigen; en el resto
        // va null, aunque alguien haya intentado mandarlo (los candados ya lo rechazaron
        // antes de llegar hasta aquí).
        $codigoGeneracionR = PoliticaInvalidacion::requisitos($dte->tipo_dte, $evento->tipoAnulacion)->requiereReemplazo
            ? strtoupper(trim((string) $evento->codigoGeneracionReemplazo))
            : null;

        return [
            'tipoDte' => $dte->tipo_dte->value,
            'codigoGeneracion' => (string) $dte->codigo_generacion,
            'selloRecibido' => (string) $dte->sello_recepcion,
            'numeroControl' => $dte->numero_control,
            'fecEmi' => $dte->fecha_emision?->format('Y-m-d') ?? '',
            'codigoGeneracionR' => $codigoGeneracionR,
            // Receptor del DTE invalidado (tal como se identificó fiscalmente).
            'tipoDocumento' => $r?->tipo_documento?->value,
            'numDocumento' => $r !== null ? $this->soloDigitos($r->num_documento) : null,
            'nombre' => $r?->nombre,
            'telefono' => $r?->telefono,
            'correo' => $r?->correo,
        ];
    }

    /** @return array<string, mixed> Bloque `motivo` del evento (CAT-024 + responsable/solicitante). */
    private function motivo(EventoInvalidacionData $e): array
    {
        return [
            'tipoAnulacion' => $e->tipoAnulacion->value,
            // Texto libre; null salvo tipo 3 (validado en los candados).
            'motivoAnulacion' => $e->motivoAnulacion,
            'nombreResponsable' => (string) ($e->nombreResponsable ?? ''),
            'tipDocResponsable' => (string) ($e->tipoDocResponsable ?? ''),
            'numDocResponsable' => (string) ($e->numDocResponsable ?? ''),
            'nombreSolicita' => (string) ($e->nombreSolicita ?? ''),
            'tipDocSolicita' => (string) ($e->tipoDocSolicita ?? ''),
            'numDocSolicita' => (string) ($e->numDocSolicita ?? ''),
        ];
    }

    /** Solo dígitos (NIT/DUI sin guiones). */
    private function soloDigitos(?string $v): string
    {
        return preg_replace('/\D+/', '', (string) $v) ?? '';
    }
}
