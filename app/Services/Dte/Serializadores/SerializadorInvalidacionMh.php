<?php

namespace App\Services\Dte\Serializadores;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Models\Dte;
use App\Support\Dte\CodigoGeneracion;
use Illuminate\Support\Carbon;

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
 *  - tipoAnulacion=1 (Error en la información) EXIGE el código de generación del
 *    documento de reemplazo, distinto al del DTE invalidado.
 */
class SerializadorInvalidacionMh
{
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

        // Regla CAT-024: el tipo 1 (Error en la información) exige el código de
        // generación del documento que REEMPLAZA al invalidado, y debe ser distinto.
        if ($evento->tipoAnulacion->requiereDocumentoReemplazo()) {
            $reemplazo = trim((string) $evento->codigoGeneracionReemplazo);
            if ($reemplazo === '') {
                $problemas[] = 'La invalidación tipo 1 (Error en la información) exige el código de '
                    .'generación del documento de reemplazo (codigoGeneracionR).';
            } elseif (! CodigoGeneracion::esValido($reemplazo)) {
                $problemas[] = 'El código de generación del documento de reemplazo no tiene formato oficial (UUID v4 en mayúsculas).';
            } elseif (strtoupper($reemplazo) === strtoupper((string) $dte->codigo_generacion)) {
                $problemas[] = 'El documento de reemplazo no puede ser el mismo DTE que se está invalidando.';
            }
        } elseif (filled($evento->codigoGeneracionReemplazo)) {
            // Tipos 2 y 3 NO llevan documento de reemplazo.
            $problemas[] = 'Solo la invalidación tipo 1 admite documento de reemplazo; los tipos 2 y 3 no.';
        }

        // El tipo 3 (Otro) exige motivo en texto.
        if ($evento->tipoAnulacion->requiereMotivoTexto() && blank($evento->motivoAnulacion)) {
            $problemas[] = 'La invalidación tipo 3 (Otro) exige un motivo en texto (motivoAnulacion).';
        }

        return $problemas;
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
        // al DTE, así que su hora es la del momento de la invalidación (now()). Si un
        // rechazo futuro indicara que horEmi también debe coincidir con la del DTE
        // (dte.hora_emision), se cambiaría aquí; por ahora se deja documentado.
        $horEmiEvento = Carbon::now()->format('H:i:s');

        return [
            'version' => (int) config('dte.invalidacion.version', 3),
            'ambiente' => $dte->ambiente->value,
            // UUID NUEVO del evento: SIEMPRE distinto al codigoGeneracion del DTE.
            'codigoGeneracion' => CodigoGeneracion::generar(),
            'fecEmi' => $fecEmiDte,      // = documento.fecEmi (fecha del DTE invalidado)
            'horEmi' => $horEmiEvento,   // hora del evento (now); ver nota arriba
            'fusion' => null,
        ];
    }

    /** @return array<string, mixed> Bloque `emisor` del evento (datos del emisor del DTE). */
    private function emisor(Dte $dte): array
    {
        $emp = $dte->establecimiento?->empresa;
        $codEstable = (string) ($dte->establecimiento?->codigo ?? '');
        $codPuntoVenta = (string) ($dte->puntoVenta?->codigo ?? '');

        // TODO (INSUMOS_PENDIENTES): confirmar contra el MH si codEstableMH/codPuntoVentaMH
        // son los códigos internos (M001/P001) o códigos asignados por Hacienda. Mientras
        // no se confirme, se usan los internos (los mismos que arma el número de control),
        // y los códigos "del contribuyente" (codEstable/codPuntoVenta) quedan null.
        // Overridables por config sin tocar código: dte.invalidacion.cod_estable_mh / cod_punto_venta_mh.
        $codEstableMH = (string) config('dte.invalidacion.cod_estable_mh') ?: $codEstable;
        $codPuntoVentaMH = (string) config('dte.invalidacion.cod_punto_venta_mh') ?: $codPuntoVenta;

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

        // Solo el tipo 1 lleva documento de reemplazo; para 2 y 3 va null.
        $codigoGeneracionR = $evento->tipoAnulacion->requiereDocumentoReemplazo()
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
