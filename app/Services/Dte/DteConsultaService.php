<?php

namespace App\Services\Dte;

use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Dte;
use App\Support\Dte\EndpointsHacienda;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * CONSULTA INDIVIDUAL de un DTE ya transmitido — POST al servicio de consulta del MH.
 *
 * POR QUÉ EXISTE, y por qué existe AHORA. No es una pantalla de conveniencia: es la
 * pieza sin la cual la política de reintentos no puede escribirse. Cuando recepción no
 * responde, el documento pudo haber entrado igual; la única forma honesta de saber si
 * reenviar es preguntar. Reenviar a ciegas produce el peor resultado posible —el mismo
 * hecho económico transmitido dos veces, con dos códigos de generación—, y ese daño no
 * se deshace: se corrige con una invalidación ante Hacienda.
 *
 * SOLO LEE. No cambia el estado del DTE, no persiste nada, no toca la máquina de
 * estados. Quien decide qué hacer con la respuesta es {@see DteTransmisionResiliente}.
 *
 * Body (según la especificación de consulta individual):
 *   nitEmisor, tdte (tipo de DTE, CAT-002), codigoGeneracion
 * Headers: Authorization (token Bearer), User-Agent, Content-Type application/json.
 *
 * CANDADOS. Respeta el mismo interruptor maestro que la transmisión
 * ('dte.transmision.enabled', o la vía dedicada de pruebas): si está cerrado no se
 * hace ninguna petición. Consultar es inofensivo, pero exige token, y pedir un token
 * es autenticarse contra Hacienda — no algo que deba pasar con la integración apagada.
 */
class DteConsultaService
{
    public function __construct(private readonly DteTransmisionAuthService $auth) {}

    /**
     * Consulta el estado de un DTE en Hacienda.
     *
     * @return array{
     *     resultado: string, http_status: int|null, mensaje: string,
     *     recibido: bool, sello: string|null, estado_mh: string|null,
     *     cuerpo: array<string, mixed>|null
     * }
     *
     * `resultado` es uno de:
     *   'aceptado'             → el MH lo tiene como procesado/aceptado (trae sello)
     *   'rechazado'            → el MH lo tiene como rechazado
     *   'no_encontrado'        → el MH no lo tiene: NO fue recibido
     *   'error_conexion'       → no se pudo preguntar (la duda sigue abierta)
     *   'token_invalido' | 'respuesta_malformada' | 'error_http' | 'desconocido'
     *
     * `recibido` es true SOLO cuando el MH confirma tenerlo (aceptado o rechazado).
     * Ante cualquier duda es false, porque de este booleano depende si se reenvía.
     *
     * @throws DteTransmisionDeshabilitadaException si la integración está apagada
     * @throws DteTransmisionException              si faltan datos para consultar
     */
    public function consultar(Dte $dte): array
    {
        if (! $this->habilitada()) {
            throw new DteTransmisionDeshabilitadaException(
                'Transmisión deshabilitada (dte.transmision.enabled=false). No se consultó nada a Hacienda.'
            );
        }

        $payload = $this->prepararPayload($dte);
        $url = EndpointsHacienda::consulta(EndpointsHacienda::ambienteTransmision());
        $timeout = (int) config('dte.transmision.timeout', 8);

        // Mismos headers que recepción. El token nunca se loguea.
        $headers = [
            'User-Agent' => (string) config('dte.transmision.user_agent', 'DTE/1.0'),
            'Authorization' => $this->auth->obtenerToken(),
        ];

        try {
            $resp = Http::timeout($timeout)->acceptJson()->withHeaders($headers)->post($url, $payload);
        } catch (Throwable $e) {
            // No se pudo preguntar. Esto NO es "no recibido": es "no se sabe", y la
            // diferencia decide si se reenvía. El mensaje no lleva token ni credenciales.
            return $this->resultado('error_conexion', null, 'No se pudo consultar el estado del DTE: '.$e->getMessage(), false);
        }

        return $this->interpretar($resp);
    }

    /**
     * Body de la consulta. Los tres campos salen del documento, no de configuración:
     * consultar por un NIT que no sea el del emisor del DTE devolvería la respuesta de
     * otro contribuyente, o ninguna.
     *
     * @return array<string, mixed>
     *
     * @throws DteTransmisionException
     */
    public function prepararPayload(Dte $dte): array
    {
        $nit = preg_replace('/\D/', '', (string) ($dte->establecimiento?->empresa?->nit ?? ''));
        if ($nit === '' || $nit === null) {
            throw new DteTransmisionException('El DTE no tiene NIT de emisor: no se puede consultar su estado.');
        }
        if (blank($dte->codigo_generacion)) {
            throw new DteTransmisionException('El DTE no tiene código de generación: no se puede consultar su estado.');
        }

        return [
            'nitEmisor' => $nit,
            // Tipo de DTE (CAT-002). El servicio lo nombra `tdte`, no `tipoDte`.
            'tdte' => $dte->tipo_dte->value,
            'codigoGeneracion' => (string) $dte->codigo_generacion,
        ];
    }

    /**
     * Diagnóstico de SOLO LECTURA: a qué URL se consultaría y con qué cuerpo, sin
     * hacer HTTP y sin token. Para comandos y pantallas.
     *
     * @return array{url: string, metodo: string, body: array<string, mixed>, habilitada: bool}
     *
     * @throws DteTransmisionException
     */
    public function dryRun(Dte $dte): array
    {
        return [
            'url' => EndpointsHacienda::consulta(EndpointsHacienda::ambienteTransmision()),
            'metodo' => 'POST',
            'body' => $this->prepararPayload($dte),
            'habilitada' => $this->habilitada(),
        ];
    }

    // ---------------------------------------------------------------- interno

    /** Mismo criterio de habilitación que la transmisión (incluida la vía de pruebas). */
    private function habilitada(): bool
    {
        if ((bool) config('dte.transmision.enabled', false)) {
            return true;
        }

        return ! EndpointsHacienda::ambienteTransmision()->esProduccion()
            && (bool) config('dte.transmision.test_enabled', false);
    }

    /**
     * @return array{resultado: string, http_status: int|null, mensaje: string, recibido: bool, sello: string|null, estado_mh: string|null, cuerpo: array<string, mixed>|null}
     */
    private function interpretar(\Illuminate\Http\Client\Response $resp): array
    {
        $status = $resp->status();
        $cuerpo = $resp->json();

        // 404 explícito: el MH no tiene el documento. Es la respuesta que autoriza a
        // reenviar sin miedo a duplicar.
        if ($status === 404) {
            return $this->resultado('no_encontrado', $status, 'El MH no tiene registrado el documento.', false);
        }
        if ($status === 401 || $status === 403) {
            return $this->resultado('token_invalido', $status, 'Credenciales/token rechazados por la consulta.', false);
        }
        if (! is_array($cuerpo)) {
            return $this->resultado('respuesta_malformada', $status, 'La respuesta de consulta no es JSON válido.', false);
        }

        $estado = strtoupper(trim((string) ($cuerpo['estado'] ?? '')));
        $mensaje = (string) ($cuerpo['descripcionMsg'] ?? $cuerpo['mensaje'] ?? 'Sin mensaje.');
        $sello = filled($cuerpo['selloRecibido'] ?? null) ? (string) $cuerpo['selloRecibido'] : null;

        // Mismo vocabulario de estados que interpreta la recepción, para que un
        // documento no signifique una cosa al transmitir y otra al consultar.
        if (in_array($estado, ['PROCESADO', 'ACEPTADO', 'RECIBIDO'], true)) {
            return $this->resultado('aceptado', $status, $mensaje, true, $sello, $estado, $cuerpo);
        }
        if (in_array($estado, ['RECHAZADO', 'RECHAZO'], true)) {
            return $this->resultado('rechazado', $status, $mensaje, true, null, $estado, $cuerpo);
        }

        // Estado vacío con HTTP OK: el MH respondió que no lo tiene.
        if ($estado === '' && $status >= 200 && $status < 300) {
            return $this->resultado('no_encontrado', $status, $mensaje, false, null, null, $cuerpo);
        }

        if ($status < 200 || $status >= 300) {
            return $this->resultado('error_http', $status, 'La consulta respondió HTTP '.$status.'.', false, null, $estado ?: null, $cuerpo);
        }

        return $this->resultado('desconocido', $status, 'Respuesta de consulta no reconocida.', false, null, $estado ?: null, $cuerpo);
    }

    /**
     * @param  array<string, mixed>|null  $cuerpo
     * @return array{resultado: string, http_status: int|null, mensaje: string, recibido: bool, sello: string|null, estado_mh: string|null, cuerpo: array<string, mixed>|null}
     */
    private function resultado(
        string $resultado,
        ?int $httpStatus,
        string $mensaje,
        bool $recibido,
        ?string $sello = null,
        ?string $estadoMh = null,
        ?array $cuerpo = null,
    ): array {
        return [
            'resultado' => $resultado,
            'http_status' => $httpStatus,
            'mensaje' => $mensaje,
            'recibido' => $recibido,
            'sello' => $sello,
            'estado_mh' => $estadoMh,
            'cuerpo' => $cuerpo,
        ];
    }
}
