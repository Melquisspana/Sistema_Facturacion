<?php

namespace App\Services\Dte;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Exceptions\Dte\DteInvalidacionException;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Models\Dte;
use App\Services\Dte\Serializadores\SerializadorInvalidacionMh;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * FASE C — Firma MOCK del evento de invalidación y persistencia CONTROLADA en columnas
 * dedicadas. NO transmite a Hacienda (/fesv/anulardte), NO cambia el estado de la NC
 * (no la pasa a Invalidado) y NO toca la evidencia de recepción original del DTE
 * (sello_recepcion / respuesta_mh / fecha_procesamiento_mh).
 *
 * Flujo:
 *  1. Candados (mock activo, NC aceptada realmente por MH, sin evento previo).
 *  2. Serializa el evento con {@see SerializadorInvalidacionMh} (revalida aceptación y
 *     reglas CAT-024) y lo valida contra invalidacion-schema-v3.json.
 *  3. Firma MOCK: JWS ficticio marcado (misma forma que {@see DteFirmaService} mock).
 *  4. Si se pide persistir: guarda JSON + JWS en storage y escribe SOLO las columnas
 *     nuevas de invalidación, dentro de una transacción.
 *
 * El sello_invalidacion en mock es FICTICIO y claramente marcado (no es un acuse real
 * del MH); sirve como evidencia y candado de idempotencia.
 */
class DteInvalidacionMockService
{
    public function __construct(
        private readonly SerializadorInvalidacionMh $serializador,
        private readonly DteSchemaValidator $validador,
    ) {}

    /**
     * @return array{
     *     codigo_generacion_evento: string,
     *     tipo_anulacion: int,
     *     mock: bool,
     *     persistido: bool,
     *     transmitido: bool,
     *     estado_dte: string,
     *     sello_invalidacion: string,
     *     json_invalidacion_path: ?string,
     *     jws_invalidacion_path: ?string,
     *     respuesta_mh_invalidacion_path: ?string,
     *     jws_preview: string
     * }
     *
     * @throws DteInvalidacionException
     */
    public function firmarMock(Dte $dte, EventoInvalidacionData $evento, bool $persistir = false, bool $permitirSinMock = false): array
    {
        $this->verificarCandados($dte, $permitirSinMock);

        // 1) Serializar el evento (revalida aceptación real + reglas CAT-024).
        try {
            $eventoJson = $this->serializador->serializar($dte, $evento);
        } catch (DteNoSerializableException $e) {
            throw new DteInvalidacionException('No se pudo construir el evento de invalidación: '.implode(' ', $e->problemas));
        }

        // 2) Validar contra el schema oficial del evento.
        $val = $this->validador->validarInvalidacion($eventoJson);
        if (($val['estado'] ?? null) === 'invalido') {
            throw new DteInvalidacionException(
                'El evento de invalidación no es válido contra el schema ('
                .implode(' | ', array_slice($val['errores'], 0, 6)).').'
            );
        }

        $codigoEvento = (string) $eventoJson['identificacion']['codigoGeneracion'];

        // 3) Firma MOCK: JWS ficticio marcado (no vale ante Hacienda).
        $jws = $this->firmarMockJws($eventoJson, $codigoEvento);

        // Sello MOCK de invalidación (ficticio, claramente marcado).
        $sello = 'MOCK-INVAL-'.strtoupper(substr((string) Str::uuid(), 0, 16));
        $ahora = Carbon::now();

        $resultado = [
            'codigo_generacion_evento' => $codigoEvento,
            'tipo_anulacion' => (int) $evento->tipoAnulacion->value,
            'mock' => true,
            'persistido' => false,
            'transmitido' => false, // FASE C: NUNCA se transmite
            'estado_dte' => $dte->estado->value, // sin cambios
            'sello_invalidacion' => $sello,
            'json_invalidacion_path' => null,
            'jws_invalidacion_path' => null,
            'respuesta_mh_invalidacion_path' => null,
            'jws_preview' => $this->previewJws($jws),
        ];

        if (! $persistir) {
            return $resultado;
        }

        return $this->persistir($dte, $eventoJson, $jws, $codigoEvento, $sello, $evento, $ahora, $resultado);
    }

    /**
     * Candados previos: mock activo (o confirmado), NC aceptada realmente por MH y sin
     * evento de invalidación previo. No toca BD ni escribe nada.
     *
     * @throws DteInvalidacionException
     */
    private function verificarCandados(Dte $dte, bool $permitirSinMock): void
    {
        if (! (bool) config('dte.invalidacion.mock', false) && ! $permitirSinMock) {
            throw new DteInvalidacionException(
                'La Fase C corre en modo MOCK. DTE_INVALIDACION_MOCK=false: active el flag o confirme '
                .'explícitamente el mock (no se transmite nada real).'
            );
        }
        if (! $dte->aceptadoRealmentePorMh()) {
            throw new DteInvalidacionException(
                'Solo se puede invalidar un DTE aceptado realmente por Hacienda (estado aceptado, sello real '
                .'y fecha de procesamiento del MH). Estado actual: '.$dte->estado->label().'.'
            );
        }
        if ($dte->tieneEventoInvalidacion()) {
            throw new DteInvalidacionException(
                'El DTE ya tiene un evento de invalidación registrado (sello_invalidacion presente o estado invalidado). '
                .'No se invalida dos veces.'
            );
        }
    }

    /**
     * Persiste archivos + columnas nuevas en una transacción. NO cambia estado, NO
     * toca sello_recepcion/respuesta_mh/fecha_procesamiento_mh.
     *
     * @param  array<string, mixed>  $eventoJson
     * @param  array<string, mixed>  $resultado
     * @return array<string, mixed>
     */
    private function persistir(Dte $dte, array $eventoJson, string $jws, string $codigoEvento, string $sello, EventoInvalidacionData $evento, Carbon $ahora, array $resultado): array
    {
        $disco = (string) config('dte.storage.disk', 'local');

        $baseNombre = 'invalidacion-'.$dte->tipo_dte->value.'-'.$dte->id.'-'.$codigoEvento;
        $rutaJson = trim((string) config('dte.storage.invalidacion_json', 'dte/invalidacion/json'), '/').'/'.$baseNombre.'.json';
        $rutaJws = trim((string) config('dte.storage.invalidacion_firmados', 'dte/invalidacion/firmados'), '/').'/'.$baseNombre.'.jws';
        $rutaResp = trim((string) config('dte.storage.invalidacion_respuestas', 'dte/invalidacion/respuestas'), '/').'/'.$baseNombre.'.json';

        // Respuesta MOCK "como del MH" (simulada; no hubo transmisión).
        $respuesta = [
            'estado' => 'PROCESADO',
            'descripcionMsg' => 'Invalidación SIMULADA (DTE_INVALIDACION_MOCK): no se transmitió nada a Hacienda.',
            'selloRecibido' => $sello,
            'fhProcesamiento' => $ahora->format('d/m/Y H:i:s'),
            '_mock' => true,
        ];

        return DB::transaction(function () use ($dte, $eventoJson, $jws, $codigoEvento, $sello, $evento, $ahora, $disco, $rutaJson, $rutaJws, $rutaResp, $respuesta, $resultado) {
            Storage::disk($disco)->put($rutaJson, (string) json_encode($eventoJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            Storage::disk($disco)->put($rutaJws, $jws);
            Storage::disk($disco)->put($rutaResp, (string) json_encode($respuesta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // SOLO columnas nuevas de invalidación. NO se toca estado ni evidencia de
            // recepción original (sello_recepcion / respuesta_mh / fecha_procesamiento_mh).
            $dte->codigo_generacion_invalidacion = $codigoEvento;
            $dte->tipo_anulacion = $evento->tipoAnulacion->value;
            $dte->json_invalidacion_path = $rutaJson;
            $dte->jws_invalidacion_path = $rutaJws;
            $dte->sello_invalidacion = $sello;
            $dte->respuesta_mh_invalidacion = $respuesta;
            $dte->respuesta_mh_invalidacion_path = $rutaResp;
            $dte->fecha_invalidacion = $ahora;
            $dte->fecha_procesamiento_invalidacion = $ahora;
            $dte->save();

            activity('dte_invalidacion')
                ->performedOn($dte)
                ->withProperties([
                    'codigo_generacion_evento' => $codigoEvento,
                    'tipo_anulacion' => $evento->tipoAnulacion->value,
                    'mock' => true,
                    'transmitido' => false,
                ])
                ->log('firmó (MOCK) el evento de invalidación, sin transmitir ni cambiar estado');

            return array_merge($resultado, [
                'persistido' => true,
                'estado_dte' => $dte->estado->value, // sigue igual
                'json_invalidacion_path' => $rutaJson,
                'jws_invalidacion_path' => $rutaJws,
                'respuesta_mh_invalidacion_path' => $rutaResp,
            ]);
        });
    }

    /**
     * JWS MOCK del evento: header.payload.firma-marcada. Misma forma que la firma mock
     * de {@see DteFirmaService}. El payload lleva el codigoGeneracion del EVENTO.
     *
     * @param  array<string, mixed>  $eventoJson
     */
    private function firmarMockJws(array $eventoJson, string $codigoEvento): string
    {
        $b64 = fn (array $d) => rtrim(strtr(base64_encode((string) json_encode($d)), '+/', '-_'), '=');
        $header = $b64(['alg' => 'none', 'mock' => true, 'evento' => 'invalidacion']);
        $payload = $b64([
            'mock' => true,
            'aviso' => 'FIRMA SIMULADA (MOCK) - EVENTO DE INVALIDACIÓN - NO VÁLIDA ANTE HACIENDA',
            'codigoGeneracion' => $codigoEvento,
            'tipoDte' => $eventoJson['documento']['tipoDte'] ?? null,
            'fecha' => Carbon::now()->toIso8601String(),
        ]);

        return $header.'.'.$payload.'.MOCK-SIN-FIRMA-REAL';
    }

    private function previewJws(string $jws): string
    {
        return mb_substr($jws, 0, 16).'… ('.mb_strlen($jws).' chars)';
    }
}
