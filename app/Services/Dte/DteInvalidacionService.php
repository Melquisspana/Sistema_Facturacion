<?php

namespace App\Services\Dte;

use App\DataTransferObjects\Dte\Salida\EventoInvalidacionData;
use App\Enums\AmbienteHacienda;
use App\Enums\EstadoDte;
use App\Exceptions\Dte\DteEvidenciaProtegidaException;
use App\Exceptions\Dte\DteInvalidacionException;
use App\Exceptions\Dte\DteNoSerializableException;
use App\Models\Dte;
use App\Services\Dte\Serializadores\SerializadorInvalidacionMh;
use App\Services\Rutas\FacturarPedido;
use App\Support\Dte\CandadoEndpointOficial;
use App\Support\Dte\EndpointsHacienda;
use App\Support\Dte\IdentidadRespuestaHacienda;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * FASE D — Firma REAL del evento de invalidación y transmisión a `/fesv/anulardte`,
 * fuertemente candada. Reutiliza el firmador real ({@see DteFirmaService}) y el token de
 * recepción ({@see DteTransmisionAuthService}).
 *
 * El ambiente de destino lo determina el ambiente PROPIO del DTE ('00' apitest / '01'
 * producción, CAT-001), no una config operativa aparte. Apitest sigue funcionando exactamente
 * como antes. Producción queda bloqueada por defecto y SOLO se habilita si, ADEMÁS de todos
 * los candados de apitest, se cumple el candado dedicado
 * `dte.invalidacion.produccion_enabled` (env `DTE_INVALIDACION_PRODUCCION_ENABLED`) y el
 * endpoint resuelto es EXACTAMENTE `https://api.dtes.mh.gob.sv/fesv/anulardte`.
 *
 * Persiste la respuesta en COLUMNAS DEDICADAS de invalidación y, solo si el MH ACEPTA,
 * transiciona Aceptado → Invalidado. NO toca la evidencia de recepción original
 * (sello_recepcion / respuesta_mh / fecha_procesamiento_mh).
 *
 * El envío real exige TODOS los candados: --transmitir-real + --confirmo-invalidar
 * (flags del comando), DTE_INVALIDACION_MOCK=false, DTE_INVALIDACION_REAL_CONFIRMATION=true,
 * firma real habilitada (no mock), endpoint exacto del ambiente propio del DTE (más
 * produccion_enabled si el DTE es de ambiente '01'), responsable/solicitante completos,
 * DTE aceptado realmente por el MH y sin evento de invalidación previo.
 *
 * A eso se suman las reglas FISCALES de {@see ValidadorReglasInvalidacion} (matriz
 * documento x motivo, sustituto verificado y notas de crédito/débito vigentes). Esas NO
 * admiten confirmación: un comprobante con una nota validada vigente queda bloqueado
 * hasta que se invalide la nota, y no hay flag que lo salte.
 */
class DteInvalidacionService
{
    /**
     * Descripción fija de la bitácora para un envío que Hacienda pudo haber tomado sin que
     * llegara la respuesta. Se busca por este texto (no por el JSON de propiedades) para que la
     * consulta valga igual en MySQL y en SQLite.
     */
    public const SIN_RESPUESTA = 'Invalidación enviada sin respuesta de Hacienda';

    private const AVISO_PORTAL = 'verificá en el portal de Hacienda antes de reintentar';

    public function __construct(
        private readonly SerializadorInvalidacionMh $serializador,
        private readonly DteSchemaValidator $validador,
        private readonly DteFirmaService $firma,
        private readonly DteTransmisionAuthService $auth,
        private readonly DteStateMachine $maquina,
        private readonly ValidadorReglasInvalidacion $reglas,
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
    public function dryRun(Dte $dte, EventoInvalidacionData $evento, bool $transmitirReal = false, bool $confirmoInvalidar = false): array
    {
        $eventoJson = $this->construirEvento($dte, $evento);
        $schema = $this->validador->validarInvalidacion($eventoJson);
        $candados = $this->evaluarCandados($dte, $evento, $transmitirReal, $confirmoInvalidar);

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
     * Transmisión REAL del evento de invalidación a `/fesv/anulardte` (apitest o
     * producción, según el ambiente propio del DTE y sus candados).
     *
     * @return array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, estado_dte: string, invalidado: bool, revisar: bool}
     *
     * @throws DteInvalidacionException
     */
    public function transmitir(Dte $dte, EventoInvalidacionData $evento, bool $transmitirReal, bool $confirmoInvalidar): array
    {
        $locks = [];
        try {
            $claves = ['dte-invalidacion:'.$dte->id];
            if (filled($evento->codigoGeneracionReemplazo)) {
                $claves[] = 'dte-invalidacion-sustituto:'.strtoupper(trim($evento->codigoGeneracionReemplazo));
            }
            foreach ($claves as $clave) {
                $lock = Cache::lock($clave, 120);
                if (! $lock->get()) {
                    throw new DteInvalidacionException('Ya hay una invalidación de este documento en curso. Esperá su resultado y revisá el documento antes de reintentar.');
                }
                $locks[] = $lock;
            }
            $dte->refresh();

            return $this->transmitirConBloqueo($dte, $evento, $transmitirReal, $confirmoInvalidar);
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    private function transmitirConBloqueo(Dte $dte, EventoInvalidacionData $evento, bool $transmitirReal, bool $confirmoInvalidar): array
    {
        // Guarda de EVIDENCIA: primera y más dura de todas, independiente de los demás
        // candados y sin flag de override. Se verifica de nuevo aquí (no solo dentro de
        // evaluarCandados) para que ningún refactor futuro de los candados pueda dejar
        // pasar una transmisión real sobre un documento protegido.
        $this->verificarNoProtegido($dte);

        $candados = $this->evaluarCandados($dte, $evento, $transmitirReal, $confirmoInvalidar);
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
        // El documento por el que pregunta ESTE evento: con él se comprueba la respuesta.
        $codigoDocumento = (string) ($eventoJson['documento']['codigoGeneracion'] ?? '');

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
            $resp = Http::timeout((int) config('dte.transmision.timeout', 8))
                ->acceptJson()->withHeaders($headers)->post($this->urlAnulacion($dte), $payload);
        } catch (Throwable $e) {
            // Sin respuesta: Hacienda pudo haberla tomado igual. El documento no se toca (sin
            // sello no hay invalidación), pero el intento queda anotado para la ficha.
            $this->anotarSinRespuesta($dte, $codigoEvento, $rutaJson, 'error_conexion', null);

            return $this->resultado('error_conexion', null, 'No se pudo conectar con anulardte: '.$e->getMessage()
                .'. Hacienda pudo haberla recibido: '.self::AVISO_PORTAL.'.', null, $dte, false);
        }

        $interpretado = $this->interpretar($resp);

        // 6) Solo respuestas DEFINITIVAS (aceptado/rechazado) persisten en BD.
        if (! in_array($interpretado['resultado'], ['aceptado', 'rechazado'], true)) {
            // Un 401/403 no llegó a procesarse; cualquier otra respuesta sin estado reconocible
            // (un 5xx, un cuerpo raro) deja la misma duda que el silencio.
            if ($interpretado['resultado'] === 'token_invalido') {
                return $this->resultado($interpretado['resultado'], $interpretado['http_status'], $interpretado['mensaje'], null, $dte, false);
            }
            $this->anotarSinRespuesta($dte, $codigoEvento, $rutaJson, $interpretado['resultado'], $interpretado['http_status']);

            return $this->resultado($interpretado['resultado'], $interpretado['http_status'], $interpretado['mensaje']
                .' Hacienda pudo haberla recibido: '.self::AVISO_PORTAL.'.', null, $dte, false);
        }

        return $this->persistir($dte, $evento, $codigoEvento, $codigoDocumento, $rutaJson, $rutaJws, $interpretado);
    }

    /**
     * Evalúa TODOS los candados de la transmisión real (sin HTTP, sin BD).
     *
     * @return array{bloqueado: bool, razones: array<int, string>}
     */
    public function evaluarCandados(Dte $dte, EventoInvalidacionData $evento, bool $transmitirReal, bool $confirmoInvalidar): array
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

        // Endpoint EXACTO y candado dedicado de producción, según el ambiente PROPIO
        // del DTE (CAT-001: '00' apitest / '01' producción). Nunca se infiere de otra
        // config operativa: el ambiente del propio documento manda.
        // La comparación es contra la URL OFICIAL incorporada (EndpointsHacienda::
        // anulacionOficial), NO contra la resuelta: si un override apuntara a otro
        // sitio, esto es exactamente lo que lo detecta y bloquea el envío.
        // El candado del endpoint es el MISMO de los otros tres servicios
        // ({@see CandadoEndpointOficial}); esta comprobación ya era exacta en los dos
        // ambientes y ahora comparte la implementación en vez de repetirla.
        $endpoint = $this->urlAnulacion($dte);
        if ($dte->ambiente === AmbienteHacienda::Produccion
            && ! (bool) config('dte.invalidacion.produccion_enabled', false)) {
            $r[] = 'Producción no habilitada: DTE_INVALIDACION_PRODUCCION_ENABLED=false '
                .'(config dte.invalidacion.produccion_enabled).';
        }
        $razonEndpoint = CandadoEndpointOficial::razon(
            $dte->ambiente,
            CandadoEndpointOficial::ANULACION,
            EndpointsHacienda::anulacionOficial($dte->ambiente),
            $endpoint,
        );
        if ($razonEndpoint !== null) {
            $r[] = $razonEndpoint;
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
            $r[] = 'El DTE no está aceptado realmente por el MH.';
        }
        if ($dte->tieneEventoInvalidacion()) {
            $r[] = 'El DTE ya tiene un evento de invalidación o está invalidado.';
        }

        // Reglas FISCALES (matriz documento x motivo, sustituto verificado y dependencia
        // de notas vigentes): mismas que aplica el serializador, evaluadas también aquí
        // para que el panel y el dry-run expliquen el bloqueo antes de intentar nada.
        //
        // La dependencia de notas NO tiene override: el antiguo --confirmo-nc-relacionada
        // ya no existe en esta firma, así que ninguna ruta —web, consola o llamada
        // directa— puede saltarse la prohibición.
        $r = array_merge($r, $this->reglas->problemas($dte, $evento));

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
     * Lo que no se puede comprobar no se guarda, igual que en la emisión: la respuesta
     * tiene que ser de este documento y de este evento, y del mismo ambiente
     * ({@see IdentidadRespuestaHacienda}); para aceptar, además, traer sello. Si no, el
     * documento queda como estaba y la bitácora dice qué faltó.
     *
     * @param  array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, observaciones: array<int, string>, cuerpo: array<string, mixed>|null}  $interpretado
     * @return array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, estado_dte: string, invalidado: bool}
     */
    private function persistir(Dte $dte, EventoInvalidacionData $evento, string $codigoEvento, string $codigoDocumento, string $rutaJson, string $rutaJws, array $interpretado): array
    {
        $aceptado = $interpretado['resultado'] === 'aceptado';
        $cuerpo = $interpretado['cuerpo'] ?? null;
        $ahora = Carbon::now();

        $disco = (string) config('dte.storage.disk', 'local');
        $rutaResp = trim((string) config('dte.storage.invalidacion_respuestas', 'dte/invalidacion/respuestas'), '/').'/invalidacion-'.$dte->tipo_dte->value.'-'.$dte->id.'-'.$codigoEvento.'.json';
        Storage::disk($disco)->put($rutaResp, (string) json_encode(is_array($cuerpo) ? $cuerpo : ['mensaje' => $interpretado['mensaje']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $respuestaMh = [
            'codigoGeneracionR' => filled($evento->codigoGeneracionReemplazo) ? strtoupper(trim($evento->codigoGeneracionReemplazo)) : null,
            'resultado' => $interpretado['resultado'],
            'estado' => is_array($cuerpo) ? ($cuerpo['estado'] ?? null) : null,
            'http_status' => $interpretado['http_status'],
            'codigoMsg' => is_array($cuerpo) ? ($cuerpo['codigoMsg'] ?? null) : null,
            'descripcionMsg' => is_array($cuerpo) ? ($cuerpo['descripcionMsg'] ?? null) : $interpretado['mensaje'],
            'selloRecibido' => $interpretado['sello'],
            'observaciones' => $interpretado['observaciones'] ?? [],
        ];

        $falta = null;
        $revisar = false;
        $tardia = DB::transaction(function () use ($dte, $evento, $codigoEvento, $codigoDocumento, $rutaJson, $rutaJws, $rutaResp, $respuestaMh, $interpretado, $cuerpo, $aceptado, $ahora, &$falta, &$revisar) {
            $actual = Dte::whereKey($dte->id)->lockForUpdate()->firstOrFail();
            $dte->setRawAttributes($actual->getAttributes(), true);
            if ($dte->estado === EstadoDte::Invalidado || filled($dte->sello_invalidacion)) {
                activity('dte_invalidacion')->performedOn($dte)->withProperties([
                    'codigo_generacion_evento' => $codigoEvento,
                    'resultado_mh' => $interpretado['resultado'],
                    'respuesta_tardia_path' => $rutaResp,
                ])->log('Llegó una respuesta tardía de invalidación y se conservó el evento aceptado.');

                return true;
            }

            $falta = $aceptado && blank($interpretado['sello'])
                ? 'la respuesta no trae sello de recepción'
                : IdentidadRespuestaHacienda::falta($dte, $codigoDocumento,
                    is_array($cuerpo) ? ($cuerpo['codigoGeneracion'] ?? null) : null,
                    is_array($cuerpo) ? ($cuerpo['ambiente'] ?? null) : null,
                    [$codigoEvento]);
            if ($falta !== null) {
                activity('dte_invalidacion')->performedOn($dte)->withProperties([
                    'falta' => $falta,
                    'estado_conservado' => $dte->estado->value,
                    'resultado_informado' => $interpretado['resultado'],
                    'mensaje_informado' => $interpretado['mensaje'],
                    'http_status' => $interpretado['http_status'],
                    'sello_informado' => filled($interpretado['sello']) ? (string) $interpretado['sello'] : null,
                    'codigo_generacion' => $dte->codigo_generacion,
                    'codigo_generacion_evento' => $codigoEvento,
                    'codigo_de_la_respuesta' => is_array($cuerpo) ? ($cuerpo['codigoGeneracion'] ?? null) : null,
                    'ambiente_de_la_respuesta' => is_array($cuerpo) ? ($cuerpo['ambiente'] ?? null) : null,
                    'ambiente_del_documento' => $dte->ambiente?->value,
                    'respuesta_path' => $rutaResp,
                    'usuario' => optional(Auth::user())->name,
                ])->log('No se guardó '.($aceptado ? 'una aceptación' : 'un rechazo').' de la invalidación informada por Hacienda.');

                return false;
            }

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
                $revisar = $this->rechazoParaRevisar($dte, $interpretado);
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
                    // Quién lo pidió (nombre y tipo de documento; el número queda en el evento).
                    'solicitante' => $evento->nombreSolicita,
                    'tipo_doc_solicitante' => $evento->tipoDocSolicita,
                    'resultado_mh' => $interpretado['resultado'],
                    'http_status' => $interpretado['http_status'],
                    'ambiente' => $dte->ambiente->value,
                    'aceptado' => $aceptado,
                    'origen' => app()->runningInConsole() ? 'consola' : 'web',
                    'usuario' => optional(Auth::user())->name,
                    'fecha' => $ahora->toIso8601String(),
                    'revisar' => $revisar,
                ])
                ->log($aceptado
                    ? 'transmitió (REAL) el evento de invalidación — Hacienda lo ACEPTÓ'
                    : 'transmitió (REAL) el evento de invalidación — Hacienda lo RECHAZÓ'
                        .($revisar ? ' (para revisar: puede estar ya invalidado)' : ''));
        });

        // Lo primero: si la respuesta no se pudo comprobar, no se guardó nada y el CCF sigue
        // vigente, así que nada de lo que cuelga de una invalidación puede ocurrir.
        if ($falta !== null) {
            return $this->resultado($aceptado ? 'aceptacion_sin_comprobar' : 'rechazo_sin_comprobar', $interpretado['http_status'],
                'Hacienda informa '.($aceptado ? 'la invalidación como aceptada' : 'un rechazo de la invalidación').', pero no se guardó: '.$falta
                .'. El documento queda como estaba; revisalo en el portal de Hacienda y avisá a soporte antes de reintentar.', null, $dte, false);
        }

        // Lo que llegó de la fábrica facturado en este CCF pasa al que lo reemplaza. Va fuera de
        // la transacción de arriba: si esto fallara, la invalidación que Hacienda ya aceptó
        // queda guardada igual.
        if ($aceptado && ! $tardia && filled($evento->codigoGeneracionReemplazo)) {
            $this->pasarEnviosAlReemplazo($dte, (string) $evento->codigoGeneracionReemplazo);
        }

        if ($tardia) {
            return $this->resultado($interpretado['resultado'], $interpretado['http_status'],
                'Llegó una respuesta tardía: se conservó el evento de invalidación aceptado.', $dte->sello_invalidacion, $dte,
                $dte->estado === EstadoDte::Invalidado || filled($dte->sello_invalidacion));
        }

        if ($revisar) {
            return $this->resultado($interpretado['resultado'], $interpretado['http_status'],
                'Hacienda rechazó la invalidación ('.$interpretado['mensaje'].'), pero puede que ya esté invalidada: hubo un envío anterior '
                .'sin respuesta o el rechazo lo dice. El documento queda como estaba hasta tener el sello; revisalo en el portal de Hacienda.',
                null, $dte->refresh(), false, true);
        }

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
     * Lo que la ficha tiene que avisar según el último intento real anotado: `sin_respuesta`
     * (se envió y Hacienda no contestó) o `revisar` (la rechazó, pero puede estar ya
     * invalidada). Null si el último intento no deja dudas. Solo lectura.
     */
    public function avisoPendiente(Dte $dte): ?string
    {
        $ultimo = Activity::query()
            ->where('log_name', 'dte_invalidacion')
            ->whereMorphedTo('subject', $dte)
            ->latest('id')
            ->first();

        return match (true) {
            $ultimo === null => null,
            $ultimo->description === self::SIN_RESPUESTA => 'sin_respuesta',
            (bool) $ultimo->properties->get('revisar') => 'revisar',
            default => null,
        };
    }

    /**
     * Deja en la bitácora un envío del que Hacienda no dio respuesta definitiva. No toca el
     * documento: lo que importa es que la ficha lo diga y que un rechazo posterior no se lea
     * como un rechazo común ({@see rechazoParaRevisar()}).
     */
    private function anotarSinRespuesta(Dte $dte, string $codigoEvento, string $rutaJson, string $resultado, ?int $httpStatus): void
    {
        activity('dte_invalidacion')->performedOn($dte)->withProperties([
            'codigo_generacion_evento' => $codigoEvento,
            'resultado' => $resultado,
            'http_status' => $httpStatus,
            'json_path' => $rutaJson,
            'ambiente' => $dte->ambiente->value,
            'usuario' => optional(Auth::user())->name,
        ])->log(self::SIN_RESPUESTA);
    }

    /**
     * Un rechazo se aparta para revisión cuando puede ser Hacienda diciendo que el documento ya
     * está invalidado: hubo antes un envío sin respuesta, o el propio rechazo lo dice. No se
     * marca invalidado (no hay sello): solo se avisa que hay que mirarlo en el portal.
     *
     * @param  array{mensaje: string, observaciones?: array<int, string>}  $interpretado
     */
    private function rechazoParaRevisar(Dte $dte, array $interpretado): bool
    {
        $texto = $interpretado['mensaje'].' '.implode(' ', $interpretado['observaciones'] ?? []);
        if (preg_match('/\b(invalidad|anulad)[oa]s?\b/iu', $texto) === 1) {
            return true;
        }

        return Activity::query()
            ->where('log_name', 'dte_invalidacion')
            ->whereMorphedTo('subject', $dte)
            ->where('description', self::SIN_RESPUESTA)
            ->exists();
    }

    /**
     * Los envíos de la fábrica facturados en el CCF invalidado pasan al documento que lo
     * reemplaza ({@see FacturarPedido::pasarAlCcfQueCorrige()}). Un error acá se reporta y no
     * tumba la invalidación ya guardada: el envío volvería a «Por facturar», a la vista.
     */
    private function pasarEnviosAlReemplazo(Dte $invalidado, string $codigo): void
    {
        try {
            $reemplazo = Dte::query()->whereRaw('UPPER(codigo_generacion) = ?', [strtoupper(trim($codigo))])
                ->whereKeyNot($invalidado->id)->first();
            if ($reemplazo !== null) {
                DB::transaction(fn () => FacturarPedido::pasarAlCcfQueCorrige($invalidado, $reemplazo, 'reemplazo de la invalidación'));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Interpreta la respuesta de anulardte (misma convención que recepción: el campo
     * `estado` manda; PROCESADO/ACEPTADO = aceptado, RECHAZADO = rechazado).
     *
     * @return array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, observaciones: array<int, string>, cuerpo: array<string, mixed>|null}
     */
    private function interpretar(Response $resp): array
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
     * `revisar`: Hacienda la rechazó, pero puede que ya esté invalidada; hay que mirarla en el portal.
     *
     * @return array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, estado_dte: string, invalidado: bool, revisar: bool}
     */
    private function resultado(string $resultado, ?int $httpStatus, string $mensaje, ?string $sello, Dte $dte, bool $invalidado, bool $revisar = false): array
    {
        return [
            'resultado' => $resultado,
            'http_status' => $httpStatus,
            'mensaje' => $mensaje,
            'sello' => $sello,
            'estado_dte' => $dte->estado->value,
            'invalidado' => $invalidado,
            'revisar' => $revisar,
        ];
    }

    /**
     * URL de anulación para el ambiente PROPIO del DTE, sin barra final. Misma
     * resolución y misma precedencia de overrides que auth y recepción: la hace
     * {@see EndpointsHacienda} (override `dte.ambientes.{00|01}.anulacion_url` →
     * `url_base` → host oficial). Nunca mezcla ambientes: apitest para '00',
     * producción real para '01'.
     *
     * El candado real de producción sigue siendo `dte.invalidacion.produccion_enabled`,
     * evaluado aparte en {@see evaluarCandados()} junto con la comparación contra
     * {@see EndpointsHacienda::anulacionOficial()}.
     */
    private function urlAnulacion(Dte $dte): string
    {
        return EndpointsHacienda::anulacion($dte->ambiente);
    }
}
