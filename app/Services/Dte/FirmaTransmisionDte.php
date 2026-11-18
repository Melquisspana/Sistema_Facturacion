<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Exceptions\Dte\DteFirmaDeshabilitadaException;
use App\Exceptions\Dte\DteFirmaException;
use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Dte;
use App\Models\User;
use App\Support\Dte\CandadoEmisionDte;
use Illuminate\Support\Facades\Log;

/**
 * La ÚNICA forma de firmar y transmitir un DTE ya generado. La usan la oficina
 * («Firmar y transmitir» y «Generar y transmitir producción») y la factura automática
 * de la tienda web (ADR 0011). Asegura el JSON, firma SOLO si el documento está
 * generado (idempotente) y transmite el firmado por la política oficial de reintentos.
 * NO envía correo.
 *
 * Quien llama ya tiene el candado del documento ({@see CandadoEmisionDte}): acá no se
 * vuelve a tomar.
 */
class FirmaTransmisionDte
{
    public function __construct(
        private readonly DteJsonService $json,
        private readonly DteFirmaService $firma,
    ) {}

    /**
     * @param  ?User  $usuario  quien emite; null en la emisión automática (sin persona)
     * @return array{error: string}|array{resultado: array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, observaciones?: array<int, string>}}
     */
    public function procesar(Dte $dte, ?User $usuario = null): array
    {
        // CASO 2 del manual. Se mira ANTES de firmar: si el documento YA llega firmado,
        // hubo un intento anterior cuyo desenlace nadie conoce (el usuario que vuelve a
        // pulsar el botón). Ese es el escenario donde reenviar a ciegas duplica, y por
        // eso la política consulta antes del primer envío. Si llega Generado, la firma
        // es nueva: no hubo envío previo y no hay nada que consultar.
        $estadoIncierto = $dte->estado === EstadoDte::Firmado;

        // 1. Asegurar el JSON oficial (genera si falta, reusando la misma garantía del correo).
        //    Sin tomar el candado otra vez: quien llega hasta acá ya lo tiene.
        if (($error = $this->asegurarJson($dte)) !== null) {
            return ['error' => 'No se puede firmar/transmitir: '.$error];
        }

        // 2. Firmar SOLO si aún está generado; si ya está firmado se salta y se reintenta el envío.
        try {
            if ($dte->estado === EstadoDte::Generado) {
                $this->firma->firmar($dte);
                $dte->refresh();
                // Recién firmado: es seguro que todavía no hubo ningún envío. Queda
                // anotado, y desde acá se anota cada envío de este documento.
                app(BitacoraEnvioDte::class)->anclarFirma($dte);
            }
        } catch (DteFirmaDeshabilitadaException|DteFirmaException $e) {
            Log::warning('DTE firma: fallo', ['dte_id' => $dte->id, 'error' => $e->getMessage()]);

            return ['error' => 'No se pudo firmar el documento: '.self::sinDetalleTecnico($e->getMessage()).' Podés reintentar.'];
        }

        // 3. Transmitir el documento firmado, SIEMPRE por la política oficial de
        //    reintentos (Manual V2.0): consulta antes de cualquier reenvío, máximo 2
        //    reenvíos. Al agotarse, la política no activa nada: si está probado que
        //    Hacienda no lo tiene, lo pasa a contingencia ContingenciaAutomaticaService.
        //    Se resuelve del contenedor en el momento: la política no es una opción de
        //    cada llamador, es la única forma de transmitir.
        if ($dte->esTransitorio()) {
            return ['resultado' => ['resultado' => 'transitorio', 'http_status' => null,
                'mensaje' => 'Documento emitido en contingencia, pendiente de sello de recepción.', 'sello' => null]];
        }

        try {
            $r = app(DteTransmisionResiliente::class)->transmitir($dte, $estadoIncierto);
            if (app(ContingenciaAutomaticaService::class)->convertirTrasReintentos($dte, $r, $usuario)) {
                $r['resultado'] = 'transitorio';
                $r['mensaje'] = 'Documento emitido en contingencia, pendiente de sello de recepción.';
            }
        } catch (DteFirmaException $e) {
            Log::warning('DTE contingencia: no se pudo re-firmar', ['dte_id' => $dte->id, 'error' => $e->getMessage()]);

            return ['error' => 'Se activó la contingencia, pero no se pudo re-firmar el documento: '.self::sinDetalleTecnico($e->getMessage())];
        } catch (DteTransmisionDeshabilitadaException $e) {
            Log::warning('DTE transmisión bloqueada', ['dte_id' => $dte->id, 'error' => $e->getMessage()]);

            return ['error' => 'Firmado, pero la transmisión está bloqueada por candados de seguridad: '
                .$e->getMessage().' El documento queda firmado; podés reintentar.'];
        } catch (DteTransmisionException $e) {
            Log::warning('DTE fallo en transmisión', ['dte_id' => $dte->id, 'error' => $e->getMessage()]);

            return ['error' => 'Firmado, pero no se pudo transmitir: '.self::sinDetalleTecnico($e->getMessage())
                .' El documento queda firmado; podés reintentar.'];
        }

        // 4. Sin respuesta definitiva y sin contingencia: lo técnico (códigos, cURL, la
        //    traza de envíos y consultas) va al registro; quien emite lee qué pasó.
        if (! in_array($r['resultado'], ['aceptado', 'rechazado', 'transitorio'], true)) {
            Log::warning('DTE transmisión sin terminar', ['dte_id' => $dte->id, 'resultado' => $r['resultado'],
                'http_status' => $r['http_status'] ?? null, 'detalle' => $r['mensaje'], 'traza' => $r['traza'] ?? []]);
            $r['mensaje'] = $this->mensajeSinTerminar($dte, $r);
        }

        return ['resultado' => $r];
    }

    /**
     * Qué le pasó al documento, en palabras para quien emite (simulacro del 17/11, F10:
     * la pantalla mostraba el código del resultado, el texto de cURL y «fase pendiente»).
     *
     * @param  array{resultado: string, mensaje: string}  $r
     */
    private function mensajeSinTerminar(Dte $dte, array $r): string
    {
        return match ($r['resultado']) {
            DteTransmisionResiliente::ESTADO_INCIERTO => 'No se pudo confirmar si Hacienda recibió el documento, '
                .'y por eso no se volvió a mandar: podría quedar duplicado.',
            'reintentos_agotados' => trim('Hacienda no respondió. '
                .app(ContingenciaAutomaticaService::class)->motivoSinContingencia($dte)),
            default => self::sinDetalleTecnico($r['mensaje']),
        };
    }

    /**
     * El mensaje sin el texto técnico de una falla de conexión, que Guzzle arma como
     * «cURL error 7: Failed to connect… (see https://curl.se/…) for https://…». Ese
     * detalle queda en el registro; a la pantalla va solo lo que está antes.
     */
    private static function sinDetalleTecnico(string $mensaje): string
    {
        $limpio = (string) preg_replace('/[\s:]*cURL error \d+:.*$/s', '', $mensaje);

        return $limpio === $mensaje ? $mensaje : rtrim($limpio, ' .:').'.';
    }

    /**
     * Garantiza que el DTE tenga su JSON oficial guardado. Si ya lo tiene, no hace nada.
     * Si falta y el documento está en condiciones (tipo soportado + estado generado), lo
     * genera. Si no se puede, devuelve el mensaje para el usuario; null = todo OK.
     *
     * Para quien YA tiene el candado del documento: el candado no se toma dos veces.
     */
    public function asegurarJson(Dte $dte): ?string
    {
        if (filled($dte->json_generado_path)) {
            return null;
        }

        $faltante = 'Este DTE no tiene JSON generado. Regenerá el JSON antes de enviar.';

        if (! $this->json->soporta($dte->tipo_dte) || $dte->estado !== EstadoDte::Generado) {
            return $faltante;
        }

        try {
            $this->json->generar($dte); // asigna numeración oficial + guarda json_generado_path
        } catch (\Throwable $e) { // schema inválido, no mapeable, no serializable, etc.
            return $faltante;
        }

        return null;
    }
}
