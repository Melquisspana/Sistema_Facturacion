<?php

namespace App\Services\Dte;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\EstadoDte;
use App\Exceptions\Dte\DteEvidenciaProtegidaException;
use App\Exceptions\Dte\DteInvalidacionException;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Models\Dte;
use App\Services\Dte\Serializadores\SerializadorInvalidacionMh;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * FASE D — Firma REAL del evento de invalidación y transmisión a `/fesv/anulardte`
 * (SOLO apitest), fuertemente candada. Reutiliza el firmador real ({@see DteFirmaService})
 * y el token de recepción ({@see DteTransmisionAuthService}).
 *
 * NUNCA producción. Persiste la respuesta en COLUMNAS DEDICADAS de invalidación y, solo
 * si el MH ACEPTA, transiciona Aceptado → Invalidado. NO toca la evidencia de recepción
 * original (sello_recepcion / respuesta_mh / fecha_procesamiento_mh).
 *
 * El envío real exige TODOS los candados: --transmitir-real + --confirmo-invalidar
 * (flags del comando), DTE_INVALIDACION_MOCK=false, DTE_INVALIDACION_REAL_CONFIRMATION=true,
 * firma real habilitada (no mock), ambiente apitest, responsable/solicitante completos,
 * NC aceptada realmente por el MH y sin evento de invalidación previo.
 */
class DteInvalidacionService
{
    public function __construct(
        private readonly SerializadorInvalidacionMh $serializador,
        private readonly DteSchemaValidator $validador,
        private readonly DteFirmaService $firma,
        private readonly DteTransmisionAuthService $auth,
        private readonly DteStateMachine $maquina,
    ) {}

    /**
     * DRY-RUN de SOLO LECTURA: arma el evento, lo valida contra el schema y muestra
     * EXACTAMENTE a qué endpoint iría y con qué cuerpo, MÁS el estado de los candados.
     * NO firma, NO transmite, NO toca BD, NO lee credenciales.
     *
     * @return array{
     *     transmitiria: bool,
     *     endpoint: string,
     *     ambiente: string,
     *     schema: array{estado: string, valido: bool, errores: array<int, string>},
     *     candados: array{bloqueado: bool, razones: array<int, string>},
     *     evento: array<string, mixed>,
     *     cuerpo_envio: array<string, mixed>
     * }
     *
     * @throws DteInvalidacionException si el evento ni siquiera se puede construir
     */
    public function dryRun(Dte $dte, EventoInvalidacionData $evento, bool $transmitirReal = false, bool $confirmoInvalidar = false, bool $confirmoNcRelacionada = false): array
    {
        $eventoJson = $this->construirEvento($dte, $evento);
        $schema = $this->validador->validarInvalidacion($eventoJson);
        $candados = $this->evaluarCandados($dte, $evento, $transmitirReal, $confirmoInvalidar, $confirmoNcRelacionada);

        return [
            'transmitiria' => ! $candados['bloqueado'] && ($schema['estado'] ?? null) !== 'invalido',
            'endpoint' => $this->urlAnulacion($dte),
            'ambiente' => $dte->ambiente->value,
            'schema' => [
                'estado' => $schema['estado'] ?? 'desconocido',
                'valido' => (bool) ($schema['valido'] ?? false),
                'errores' => $schema['errores'] ?? [],
            ],
            'candados' => $candados,
            'evento' => $eventoJson,
            // Cuerpo que se enviaría (el `documento` real es el JWS; aquí se muestra un
            // marcador porque en dry-run NO se firma).
            'cuerpo_envio' => [
                'ambiente' => $dte->ambiente->value,
                'idEnvio' => (int) $dte->id,
                'version' => (int) config('dte.invalidacion.version', 3),
                'documento' => '<<JWS firmado — se genera al transmitir; no se firma en dry-run>>',
            ],
        ];
    }

    /**
     * Transmisión REAL del evento de invalidación a `/fesv/anulardte` (apitest).
     *
     * @return array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, estado_dte: string, invalidado: bool}
     *
     * @throws DteInvalidacionException
     */
    public function transmitir(Dte $dte, EventoInvalidacionData $evento, bool $transmitirReal, bool $confirmoInvalidar, bool $confirmoNcRelacionada = false): array
    {
        // Guarda de EVIDENCIA: primera y más dura de todas, independiente de los demás
        // candados y sin flag de override. Se verifica de nuevo aquí (no solo dentro de
        // evaluarCandados) para que ningún refactor futuro de los candados pueda dejar
        // pasar una transmisión real sobre un documento protegido.
        $this->verificarNoProtegido($dte);

        $candados = $this->evaluarCandados($dte, $evento, $transmitirReal, $confirmoInvalidar, $confirmoNcRelacionada);
        if ($candados['bloqueado']) {
            throw new DteInvalidacionException('Transmisión de invalidación bloqueada: '.implode(' | ', $candados['razones']));
        }

        // 1) Evento + validación de schema (defensa final antes de firmar).
        $eventoJson = $this->construirEvento($dte, $evento);
        $val = $this->validador->validarInvalidacion($eventoJson);
        if (($val['estado'] ?? null) === 'invalido') {
            throw new DteInvalidacionException(
                'El evento no es válido contra el schema ('.implode(' | ', array_slice($val['errores'], 0, 6)).').'
            );
        }

        $codigoEvento = (string) $eventoJson['identificacion']['codigoGeneracion'];

        // 2) Firma REAL con el firmador local (JWS). En tests se hace Http::fake al firmador.
        $jws = $this->firma->firmarJson($eventoJson);

        // 3) Token de recepción (reutilizado). En tests se hace Http::fake a /seguridad/auth.
        $token = $this->auth->obtenerToken();

        // 4) Guardar evidencia en disco ANTES del POST (JSON + JWS).
        [$rutaJson, $rutaJws] = $this->guardarArchivos($dte, $eventoJson, $jws, $codigoEvento);

        // 5) POST a /fesv/anulardte.
        $payload = [
            'ambiente' => $dte->ambiente->value,
            'idEnvio' => (int) $dte->id,
            'version' => (int) config('dte.invalidacion.version', 3),
            'documento' => $jws,
        ];
        $headers = [
            'User-Agent' => (string) config('dte.transmision.user_agent', 'DTE/1.0'),
            'Authorization' => $token,
        ];

        try {
            $resp = Http::timeout((int) config('dte.transmision.timeout', 15))
                ->acceptJson()->withHeaders($headers)->post($this->urlAnulacion($dte), $payload);
        } catch (Throwable $e) {
            // Error transitorio: no se persiste nada en BD (se puede reintentar).
            return $this->resultado('error_conexion', null, 'No se pudo conectar con anulardte: '.$e->getMessage(), null, $dte, false);
        }

        $interpretado = $this->interpretar($resp);

        // 6) Solo respuestas DEFINITIVAS (aceptado/rechazado) persisten en BD.
        if (! in_array($interpretado['resultado'], ['aceptado', 'rechazado'], true)) {
            return $this->resultado($interpretado['resultado'], $interpretado['http_status'], $interpretado['mensaje'], null, $dte, false);
        }

        return $this->persistir($dte, $evento, $codigoEvento, $rutaJson, $rutaJws, $interpretado);
    }

    /**
     * Evalúa TODOS los candados de la transmisión real (sin HTTP, sin BD).
     *
     * @return array{bloqueado: bool, razones: array<int, string>}
     */
    public function evaluarCandados(Dte $dte, EventoInvalidacionData $evento, bool $transmitirReal, bool $confirmoInvalidar, bool $confirmoNcRelacionada = false): array
    {
        $r = [];

        // Evidencia PROTEGIDA: primero y sin flag de override. Ver estaProtegidoComoEvidencia().
        if ($dte->estaProtegidoComoEvidencia()) {
            $r[] = 'DTE PROTEGIDO como evidencia APITEST (config dte.invalidacion.protegidos_numero_control / '
                .'protegidos_codigo_generacion): no puede invalidarse por esta vía, sin excepción.';
        }

        // Confirmaciones explícitas del comando.
        if (! $transmitirReal) {
            $r[] = 'Falta la confirmación --transmitir-real.';
        }
        if (! $confirmoInvalidar) {
            $r[] = 'Falta la confirmación --confirmo-invalidar.';
        }

        // Flags de configuración.
        if ((bool) config('dte.invalidacion.mock', false)) {
            $r[] = 'DTE_INVALIDACION_MOCK=true: apague el mock para transmitir real.';
        }
        if (! (bool) config('dte.invalidacion.real_confirmation', false)) {
            $r[] = 'DTE_INVALIDACION_REAL_CONFIRMATION=false.';
        }

        // Nunca producción; solo apitest.
        if ($this->esProduccion()) {
            $r[] = 'Ambiente de producción no permitido para invalidación (solo apitest).';
        }
        if (! $this->urlEsApitest($dte)) {
            $r[] = 'El endpoint de anulación no es apitest (apitest.dtes.mh.gob.sv).';
        }

        // Firma REAL (no mock) habilitada.
        if (! (bool) config('dte.firma.enabled', false)) {
            $r[] = 'DTE_FIRMA_ENABLED=false: la firma real está deshabilitada.';
        }
        if ((bool) config('dte.firma.mock', false)) {
            $r[] = 'DTE_FIRMADOR_MOCK=true: la firma está en mock, no real.';
        }

        // Responsable / solicitante completos (el schema los exige).
        if (blank($evento->nombreResponsable) || blank($evento->tipoDocResponsable) || blank($evento->numDocResponsable)) {
            $r[] = 'Faltan datos del responsable del evento (DTE_INVALIDACION_RESP_*).';
        }
        if (blank($evento->nombreSolicita) || blank($evento->tipoDocSolicita) || blank($evento->numDocSolicita)) {
            $r[] = 'Faltan datos del solicitante del evento (DTE_INVALIDACION_SOL_*).';
        }

        // Estado del DTE.
        if (! $dte->aceptadoRealmentePorMh()) {
            $r[] = 'La NC no está aceptada realmente por el MH.';
        }
        if ($dte->tieneEventoInvalidacion()) {
            $r[] = 'La NC ya tiene un evento de invalidación o está invalidada.';
        }

        // Nota de Crédito relacionada: NO es un bloqueo automático (no hay base fiscal
        // confirmada para prohibirlo) sino una CONFIRMACIÓN REFORZADA: bloquea salvo que
        // se pase --confirmo-nc-relacionada (comando) / el checkbox equivalente (UI),
        // asumiendo el riesgo de una posible doble corrección fiscal (NC + invalidación
        // cubriendo la misma operación).
        if ($dte->tieneNotaCreditoRelacionada() && ! $confirmoNcRelacionada) {
            $r[] = 'El documento tiene una Nota de Crédito relacionada (posible doble corrección fiscal): '
                .'pase --confirmo-nc-relacionada para invalidar de todas formas, bajo su responsabilidad.';
        }

        return ['bloqueado' => $r !== [], 'razones' => $r];
    }

    /**
     * Guarda dura e incondicional: un documento marcado como evidencia PROTEGIDA nunca
     * se transmite, sin importar el resto de candados ni ningún flag.
     *
     * @throws DteEvidenciaProtegidaException
     */
    private function verificarNoProtegido(Dte $dte): void
    {
        if ($dte->estaProtegidoComoEvidencia()) {
            throw new DteEvidenciaProtegidaException(
                'El DTE '.$dte->id.' ('.$dte->numero_control.') está PROTEGIDO como evidencia APITEST y NO puede '
                .'invalidarse por esta vía (ni mock ni real), sin excepción. Revise '
                ."config('dte.invalidacion.protegidos_numero_control' / 'protegidos_codigo_generacion')."
            );
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws DteInvalidacionException
     */
    private function construirEvento(Dte $dte, EventoInvalidacionData $evento): array
    {
        try {
            return $this->serializador->serializar($dte, $evento);
        } catch (DteNoSerializableException $e) {
            throw new DteInvalidacionException('No se pudo construir el evento de invalidación: '.implode(' ', $e->problemas));
        }
    }

    /**
     * Guarda JSON + JWS del evento en disco (evidencia). No toca BD.
     *
     * @param  array<string, mixed>  $eventoJson
     * @return array{0: string, 1: string} [rutaJson, rutaJws]
     */
    private function guardarArchivos(Dte $dte, array $eventoJson, string $jws, string $codigoEvento): array
    {
        $disco = (string) config('dte.storage.disk', 'local');
        $base = 'invalidacion-'.$dte->tipo_dte->value.'-'.$dte->id.'-'.$codigoEvento;
        $rutaJson = trim((string) config('dte.storage.invalidacion_json', 'dte/invalidacion/json'), '/').'/'.$base.'.json';
        $rutaJws = trim((string) config('dte.storage.invalidacion_firmados', 'dte/invalidacion/firmados'), '/').'/'.$base.'.jws';

        Storage::disk($disco)->put($rutaJson, (string) json_encode($eventoJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Storage::disk($disco)->put($rutaJws, $jws);

        return [$rutaJson, $rutaJws];
    }

    /**
     * Persiste la respuesta DEFINITIVA en columnas dedicadas y, si fue aceptada,
     * transiciona Aceptado → Invalidado. NUNCA toca sello_recepcion/respuesta_mh/
     * fecha_procesamiento_mh del DTE original.
     *
     * @param  array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, observaciones: array<int, string>, cuerpo: array<string, mixed>|null}  $interpretado
     * @return array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, estado_dte: string, invalidado: bool}
     */
    private function persistir(Dte $dte, EventoInvalidacionData $evento, string $codigoEvento, string $rutaJson, string $rutaJws, array $interpretado): array
    {
        $aceptado = $interpretado['resultado'] === 'aceptado';
        $cuerpo = $interpretado['cuerpo'] ?? null;
        $ahora = Carbon::now();

        $disco = (string) config('dte.storage.disk', 'local');
        $rutaResp = trim((string) config('dte.storage.invalidacion_respuestas', 'dte/invalidacion/respuestas'), '/').'/invalidacion-'.$dte->tipo_dte->value.'-'.$dte->id.'-'.$codigoEvento.'.json';
        Storage::disk($disco)->put($rutaResp, (string) json_encode(is_array($cuerpo) ? $cuerpo : ['mensaje' => $interpretado['mensaje']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $respuestaMh = [
            'resultado' => $interpretado['resultado'],
            'estado' => is_array($cuerpo) ? ($cuerpo['estado'] ?? null) : null,
            'http_status' => $interpretado['http_status'],
            'codigoMsg' => is_array($cuerpo) ? ($cuerpo['codigoMsg'] ?? null) : null,
            'descripcionMsg' => is_array($cuerpo) ? ($cuerpo['descripcionMsg'] ?? null) : $interpretado['mensaje'],
            'selloRecibido' => $interpretado['sello'],
            'observaciones' => $interpretado['observaciones'] ?? [],
        ];

        DB::transaction(function () use ($dte, $evento, $codigoEvento, $rutaJson, $rutaJws, $rutaResp, $respuestaMh, $interpretado, $cuerpo, $aceptado, $ahora) {
            $dte->codigo_generacion_invalidacion = $codigoEvento;
            $dte->tipo_anulacion = $evento->tipoAnulacion->value;
            $dte->json_invalidacion_path = $rutaJson;
            $dte->jws_invalidacion_path = $rutaJws;
            $dte->respuesta_mh_invalidacion = $respuestaMh;
            $dte->respuesta_mh_invalidacion_path = $rutaResp;
            $dte->fecha_invalidacion = $ahora;

            $fh = is_array($cuerpo) ? ($cuerpo['fhProcesamiento'] ?? null) : null;
            if (filled($fh)) {
                $dte->fecha_procesamiento_invalidacion =
                    rescue(fn () => Carbon::createFromFormat('d/m/Y H:i:s', (string) $fh), null, false)
                    ?: rescue(fn () => Carbon::parse((string) $fh), null, false);
            }

            if ($aceptado) {
                // Sello de la invalidación (columna DEDICADA, no la de recepción).
                $dte->sello_invalidacion = (string) $interpretado['sello'];
                $dte->save();
                // Solo si el MH acepta: Aceptado → Invalidado (con historial).
                $this->maquina->transicionar($dte, EstadoDte::Invalidado, null, 'Invalidación aceptada por Hacienda (evento anulardte)');
            } else {
                // Rechazado: se guarda la respuesta (motivo) SIN sello y SIN cambiar estado.
                $dte->save();
            }

            // Auditoría de la transmisión REAL (simétrica al log del mock): quién, qué DTE,
            // tipo de anulación, resultado del MH, ambiente y si vino de consola o web.
            // NUNCA guarda contraseñas, tokens ni el JSON/JWS firmado completo (esos ya
            // viven aparte en disco, en rutas ya registradas en columnas dedicadas).
            activity('dte_invalidacion')
                ->performedOn($dte)
                ->withProperties([
                    'codigo_generacion_evento' => $codigoEvento,
                    'tipo_anulacion' => $evento->tipoAnulacion->value,
                    'resultado_mh' => $interpretado['resultado'],
                    'http_status' => $interpretado['http_status'],
                    'ambiente' => $dte->ambiente->value,
                    'aceptado' => $aceptado,
                    'origen' => app()->runningInConsole() ? 'consola' : 'web',
                    'usuario' => optional(Auth::user())->name,
                    'fecha' => $ahora->toIso8601String(),
                ])
                ->log($aceptado
                    ? 'transmitió (REAL) el evento de invalidación — Hacienda lo ACEPTÓ'
                    : 'transmitió (REAL) el evento de invalidación — Hacienda lo RECHAZÓ');
        });

        return $this->resultado(
            $interpretado['resultado'],
            $interpretado['http_status'],
            $interpretado['mensaje'],
            $aceptado ? $interpretado['sello'] : null,
            $dte->refresh(),
            $aceptado,
        );
    }

    /**
     * Interpreta la respuesta de anulardte (misma convención que recepción: el campo
     * `estado` manda; PROCESADO/ACEPTADO = aceptado, RECHAZADO = rechazado).
     *
     * @return array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, observaciones: array<int, string>, cuerpo: array<string, mixed>|null}
     */
    private function interpretar(\Illuminate\Http\Client\Response $resp): array
    {
        $status = $resp->status();
        $cuerpo = $resp->json();

        if (is_array($cuerpo) && filled($cuerpo['estado'] ?? null)) {
            $estado = strtoupper((string) $cuerpo['estado']);
            $mensaje = (string) ($cuerpo['descripcionMsg'] ?? $cuerpo['mensaje'] ?? 'Sin mensaje.');
            $obs = is_array($cuerpo['observaciones'] ?? null)
                ? array_values(array_filter(array_map(fn ($o) => trim((string) $o), $cuerpo['observaciones']), fn ($o) => $o !== ''))
                : [];
            $sello = isset($cuerpo['selloRecibido']) ? (string) $cuerpo['selloRecibido'] : null;

            if (in_array($estado, ['PROCESADO', 'ACEPTADO', 'RECIBIDO'], true)) {
                return ['resultado' => 'aceptado', 'http_status' => $status, 'mensaje' => $mensaje, 'sello' => $sello, 'observaciones' => $obs, 'cuerpo' => $cuerpo];
            }
            if (in_array($estado, ['RECHAZADO', 'RECHAZO'], true)) {
                return ['resultado' => 'rechazado', 'http_status' => $status, 'mensaje' => $mensaje, 'sello' => null, 'observaciones' => $obs, 'cuerpo' => $cuerpo];
            }

            return ['resultado' => 'desconocido', 'http_status' => $status, 'mensaje' => 'Respuesta de anulardte no reconocida.', 'sello' => null, 'observaciones' => $obs, 'cuerpo' => $cuerpo];
        }

        if ($status === 401 || $status === 403) {
            return ['resultado' => 'token_invalido', 'http_status' => $status, 'mensaje' => 'Credenciales/token rechazados por anulardte.', 'sello' => null, 'observaciones' => [], 'cuerpo' => null];
        }

        return ['resultado' => 'error_http', 'http_status' => $status, 'mensaje' => 'anulardte respondió HTTP '.$status.' sin estado reconocible.', 'sello' => null, 'observaciones' => [], 'cuerpo' => null];
    }

    /**
     * @return array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, estado_dte: string, invalidado: bool}
     */
    private function resultado(string $resultado, ?int $httpStatus, string $mensaje, ?string $sello, Dte $dte, bool $invalidado): array
    {
        return [
            'resultado' => $resultado,
            'http_status' => $httpStatus,
            'mensaje' => $mensaje,
            'sello' => $sello,
            'estado_dte' => $dte->estado->value,
            'invalidado' => $invalidado,
        ];
    }

    /** URL de anulación por ambiente (DTE_TEST_ANULACION_URL); default seguro apitest. Sin barra final. */
    private function urlAnulacion(Dte $dte): string
    {
        $url = rtrim((string) config('dte.ambientes.'.$dte->ambiente->value.'.anulacion_url', ''), '/');
        if ($url === '') {
            // Nunca producción por defecto: cae a apitest.
            $url = 'https://apitest.dtes.mh.gob.sv/fesv/anulardte';
        }

        return $url;
    }

    private function urlEsApitest(Dte $dte): bool
    {
        return str_contains($this->urlAnulacion($dte), 'apitest.dtes.mh.gob.sv');
    }

    private function esProduccion(): bool
    {
        $amb = strtolower((string) config('dte.transmision.ambiente', 'testing'));

        return in_array($amb, ['produccion', 'production', 'prod', '01'], true);
    }
}
