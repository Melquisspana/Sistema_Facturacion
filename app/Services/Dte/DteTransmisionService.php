<?php

namespace App\Services\Dte;

use App\Enums\EstadoDte;
use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Dte;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Transmisión del DTE firmado a Hacienda (recepción) — FASE DE PREPARACIÓN.
 *
 * En esta fase NO se transmite nada real: la transmisión está DESHABILITADA por
 * defecto ('dte.transmision.enabled' = false) y, aunque se habilite, este servicio
 * NO persiste el sello de recepción ni cambia el estado a aceptado (eso queda para
 * la fase de transmisión real, tras confirmar el manual técnico del MH). Sirve para
 * dejar lista y testeada (con Http::fake) toda la infraestructura previa.
 *
 * NO imprime tokens/credenciales. NO usa endpoints reales (vacíos por defecto).
 */
class DteTransmisionService
{
    public function __construct(
        private readonly DteTransmisionAuthService $auth,
        private readonly DteStateMachine $maquina,
    ) {}

    /**
     * Diagnóstico de SOLO LECTURA: ¿está el DTE listo para transmitir? No toca BD,
     * no transmite, no lee credenciales. Lo usa `dte:transmision-check`.
     *
     * @return array{
     *     listo: bool,
     *     habilitada: bool,
     *     checks: array<int, array{ok: bool, etiqueta: string, detalle: string}>,
     *     problemas: array<int, string>
     * }
     */
    public function diagnosticar(Dte $dte): array
    {
        $checks = [];
        $problemas = [];
        $add = function (bool $ok, string $etiqueta, string $detalle) use (&$checks, &$problemas) {
            $checks[] = ['ok' => $ok, 'etiqueta' => $etiqueta, 'detalle' => $detalle];
            if (! $ok) {
                $problemas[] = $etiqueta.': '.$detalle;
            }
        };

        $esFirmado = $dte->estado === EstadoDte::Firmado;
        $add($esFirmado, 'Estado firmado', $esFirmado
            ? 'El documento está firmado (listo para transmitir).'
            : 'Estado actual: '.$dte->estado->label().' (se requiere firmado; firme primero).');

        $tieneJson = filled($dte->json_generado_path);
        $add($tieneJson, 'JSON generado', $tieneJson ? $dte->json_generado_path : 'No tiene json_generado_path.');

        $tieneNumeracion = filled($dte->numero_control) && filled($dte->codigo_generacion);
        $add($tieneNumeracion, 'Numeración oficial', $tieneNumeracion
            ? 'numero_control y codigo_generacion presentes.'
            : 'Falta numero_control y/o codigo_generacion.');

        $disco = (string) config('dte.storage.disk', 'local');
        $tieneJws = filled($dte->json_firmado_path);
        $jwsExiste = $tieneJws && $this->rutaFirmadaValida($dte->json_firmado_path)
            && Storage::disk($disco)->exists($this->normalizar($dte->json_firmado_path));
        $add($jwsExiste, 'JWS firmado en disco', $jwsExiste
            ? $dte->json_firmado_path
            : ($tieneJws ? 'No se encontró el archivo JWS firmado.' : 'No tiene json_firmado_path (firme primero).'));

        $sinSello = blank($dte->sello_recepcion);
        $add($sinSello, 'Sin sello de recepción', $sinSello
            ? 'Aún no transmitido (sin sello).'
            : 'Ya tiene sello de recepción.');

        $noAceptado = $dte->estado !== EstadoDte::Aceptado;
        $add($noAceptado, 'No aceptado', $noAceptado ? 'No está aceptado.' : 'Ya está aceptado.');

        $noInvalidado = ! $dte->esAnulado();
        $add($noInvalidado, 'No invalidado/anulado', $noInvalidado ? 'No está invalidado/anulado.' : 'Está invalidado/anulado.');

        return [
            'listo' => $problemas === [],
            'habilitada' => (bool) config('dte.transmision.enabled', false),
            'checks' => $checks,
            'problemas' => $problemas,
        ];
    }

    /**
     * Construye el payload interno de recepción a partir del DTE firmado. No envía
     * nada. Algunos campos oficiales quedan marcados con TODO hasta confirmar el
     * manual técnico del MH.
     *
     * @return array<string, mixed>
     *
     * @throws DteTransmisionException si no se puede leer el JWS firmado
     */
    public function prepararPayloadRecepcion(Dte $dte): array
    {
        $disco = (string) config('dte.storage.disk', 'local');
        if (! $this->rutaFirmadaValida($dte->json_firmado_path)
            || ! Storage::disk($disco)->exists($this->normalizar($dte->json_firmado_path))) {
            throw new DteTransmisionException('No se encontró el archivo JWS firmado para construir el payload.');
        }

        $jws = trim((string) Storage::disk($disco)->get($this->normalizar($dte->json_firmado_path)));
        $version = (int) (config('dte.json.versiones')[$dte->tipo_dte->value] ?? 0);

        // Campos del body de recepción uno-a-uno según el Manual Técnico (4.2.1):
        // ambiente, idEnvio, version, tipoDte, documento, codigoGeneracion.
        // numeroControl NO va en el body (viaja dentro del JWS firmado).
        return [
            // Código de ambiente MH (CAT-001: '00' pruebas / '01' producción).
            'ambiente' => $dte->ambiente->value,
            // Identificador de envío. El manual lo define como Integer "correlativo a
            // discreción" para el modelo uno-a-uno. Usamos el id interno del documento.
            'idEnvio' => (int) $dte->id,
            // Versión del JSON del DTE (debe coincidir con la identificación del DTE).
            'version' => $version,
            // Tipo de DTE (CAT-002), debe coincidir con la identificación del DTE.
            'tipoDte' => $dte->tipo_dte->value,
            // DTE firmado a transmitir (JWS en serialización compacta).
            'documento' => $jws,
            // Código de generación (UUID v4).
            'codigoGeneracion' => (string) $dte->codigo_generacion,
        ];
    }

    /**
     * Transmite el DTE a recepción. FASE DE PREPARACIÓN:
     *  - Si 'dte.transmision.enabled' = false → lanza excepción clara (NO hace HTTP).
     *  - Si está habilitada → hace el POST (en tests, Http::fake) e INTERPRETA la
     *    respuesta, pero NO persiste sello ni cambia estado (eso es la fase real).
     *
     * @return array{resultado: string, http_status: int|null, mensaje: string, sello: string|null}
     *
     * @throws DteTransmisionException             si falla una precondición
     * @throws DteTransmisionDeshabilitadaException si la transmisión está deshabilitada
     */
    public function transmitir(Dte $dte): array
    {
        $this->verificarPrecondiciones($dte);

        // MODO MOCK (local): simula la respuesta de Hacienda sin credenciales, sin
        // token y sin HTTP real. Devuelve "aceptado" con un sello FICTICIO marcado y
        // lo aplica por la misma ruta real (`aplicarResultado()` → DteStateMachine):
        // persiste el sello mock, registra historial y avanza el estado a Aceptado.
        if ((bool) config('dte.transmision.mock', false)) {
            return $this->transmitirMock($dte);
        }

        // Candados de seguridad ANTES de cualquier HTTP: enabled, confirmación real,
        // dry-run, producción autorizada y sistema viejo. Si alguno bloquea, no se
        // hace ninguna petición.
        $candados = $this->evaluarCandados();
        if ($candados['bloqueado']) {
            throw new DteTransmisionDeshabilitadaException(implode(' | ', $candados['razones']));
        }

        $payload = $this->prepararPayloadRecepcion($dte);

        $url = $this->urlRecepcion();
        $timeout = (int) config('dte.transmision.timeout', 15);
        $userAgent = (string) config('dte.transmision.user_agent', 'DTE/1.0');

        // Token desde el servicio de autenticación (login + cache). El token ya viene
        // normalizado con prefijo "Bearer". NUNCA se loguea.
        $token = $this->auth->obtenerToken();

        // Headers según el manual (4.2.1): Authorization (token), User-Agent,
        // content-Type application/JSON.
        $headers = [
            'User-Agent' => $userAgent,
            'Authorization' => $token,
        ];

        try {
            $resp = Http::timeout($timeout)->acceptJson()->withHeaders($headers)->post($url, $payload);
        } catch (Throwable $e) {
            // Error transitorio (conexión/timeout): el documento sigue Firmado y se
            // puede reintentar. No se cambia estado.
            return $this->resultado('error_conexion', null, 'No se pudo conectar con recepción: '.$e->getMessage());
        }

        // Respuesta DEFINITIVA del MH: si es aceptado/rechazado se persiste el sello
        // (solo aceptado) y se avanza el estado por la máquina. Errores transitorios
        // (token inválido, malformada, etc.) NO cambian el estado.
        return $this->aplicarResultado($dte, $this->interpretarRespuesta($resp));
    }

    /**
     * Aplica el resultado de una respuesta DEFINITIVA del MH al documento, vía
     * DteStateMachine (con historial), dentro de una transacción:
     *  - aceptado  → persiste sello_recepcion y avanza Firmado → Enviado → Aceptado.
     *  - rechazado → avanza Firmado → Enviado → Rechazado (sin sello).
     *  - cualquier otro (errores transitorios) → no cambia estado (sigue Firmado).
     *
     * Los flags técnicos (json_firmado_path, sello_recepcion) se mantienen como
     * evidencia; el `estado` representa la fase real del documento.
     *
     * @param  array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, observaciones?: array<int, string>}  $resultado
     * @return array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, observaciones?: array<int, string>}
     */
    private function aplicarResultado(Dte $dte, array $resultado): array
    {
        $tipo = $resultado['resultado'] ?? 'desconocido';
        if (! in_array($tipo, ['aceptado', 'rechazado'], true)) {
            return $resultado; // transitorio: el documento sigue Firmado
        }

        DB::transaction(function () use ($dte, $tipo, $resultado) {
            // Envío efectivo a recepción.
            $this->maquina->transicionar($dte, EstadoDte::Enviado, null, 'Transmisión a Hacienda (recepción)');

            if ($tipo === 'aceptado') {
                if (filled($resultado['sello'] ?? null)) {
                    $dte->sello_recepcion = (string) $resultado['sello'];
                }
                // Guarda la respuesta completa del MH (sello/codigoMsg/observaciones/…)
                // + el JSON crudo en disco, antes de avanzar el estado.
                $this->persistirRespuesta($dte, $resultado);
                $this->maquina->transicionar($dte, EstadoDte::Aceptado, null, 'Aceptado por Hacienda (sello de recepción recibido)');
            } else {
                // Rechazado: se guarda la respuesta (codigoMsg/descripcionMsg/observaciones)
                // para que quede el motivo del rechazo, sin sello.
                $this->persistirRespuesta($dte, $resultado);
                $this->maquina->transicionar($dte, EstadoDte::Rechazado, null, 'Rechazado por Hacienda');
            }
        });

        return $resultado;
    }

    /**
     * Persiste la respuesta de recepción del MH: el JSON crudo en disco
     * (dte/respuestas/) y los campos interpretados en la columna `respuesta_mh`,
     * más `fecha_procesamiento_mh` si vino. Aplica a aceptados y rechazados. NO
     * cambia el estado (eso lo hace la máquina) ni imprime nada.
     *
     * @param  array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, observaciones?: array<int, string>, cuerpo?: array<string, mixed>|null}  $resultado
     */
    private function persistirRespuesta(Dte $dte, array $resultado): void
    {
        $cuerpo = $resultado['cuerpo'] ?? null;
        $get = fn (string $k) => is_array($cuerpo) ? ($cuerpo[$k] ?? null) : null;

        // JSON crudo de la respuesta (lo que devolvió recepción tal cual).
        $disco = (string) config('dte.storage.disk', 'local');
        $carpeta = trim((string) config('dte.storage.respuestas', 'dte/respuestas'), '/');
        $ruta = $carpeta.'/dte-'.$dte->tipo_dte->value.'-'.$dte->id.'-'.$dte->codigo_generacion.'.json';
        $crudo = is_array($cuerpo) ? $cuerpo : ['_sin_json' => true, 'mensaje' => $resultado['mensaje'] ?? null];
        Storage::disk($disco)->put($ruta, (string) json_encode($crudo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Campos interpretados, listos para mostrar en UI.
        $dte->respuesta_mh = [
            'resultado' => $resultado['resultado'] ?? null,
            'estado' => $get('estado'),
            'http_status' => $resultado['http_status'] ?? null,
            'codigoMsg' => $get('codigoMsg'),
            'descripcionMsg' => $get('descripcionMsg') ?? ($resultado['mensaje'] ?? null),
            'clasificaMsg' => $get('clasificaMsg'),
            'selloRecibido' => $resultado['sello'] ?? $get('selloRecibido'),
            'fhProcesamiento' => $get('fhProcesamiento'),
            'observaciones' => $resultado['observaciones'] ?? [],
        ];
        $dte->respuesta_mh_path = $ruta;

        if (filled($get('fhProcesamiento'))) {
            $fh = (string) $get('fhProcesamiento');
            // El MH devuelve fhProcesamiento como "d/m/Y H:i:s"; Carbon::parse lo leería
            // como formato americano. Probar el formato MH primero, luego parse libre.
            $dte->fecha_procesamiento_mh =
                rescue(fn () => \Illuminate\Support\Carbon::createFromFormat('d/m/Y H:i:s', $fh), null, false)
                ?: rescue(fn () => \Illuminate\Support\Carbon::parse($fh), null, false);
        }

        $dte->save();
    }

    /**
     * Transmisión SIMULADA (modo mock local): NO hace HTTP, NO usa token ni
     * credenciales, NO evalúa candados de producción (nunca sale a la red). Devuelve
     * un resultado "aceptado" con un sello FICTICIO claramente marcado. A diferencia de
     * un simple diagnóstico, usa la MISMA ruta de aplicación de resultado que la fase
     * real (`aplicarResultado()`): pasa por la `DteStateMachine`, registra historial,
     * persiste el sello mock y avanza el estado a Aceptado (o Rechazado según el
     * resultado simulado). Lo único que omite es la red/credenciales.
     *
     * @return array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, observaciones: array<int, string>}
     */
    private function transmitirMock(Dte $dte): array
    {
        $sello = 'MOCK-SIMULADO-'.strtoupper(substr((string) \Illuminate\Support\Str::uuid(), 0, 16));
        $mensaje = 'Transmisión SIMULADA (MH_MOCK=true): no se envió nada a Hacienda. Sello ficticio para pruebas locales.';

        // Cuerpo "como del MH" para que persistirRespuesta también pueble la fecha de
        // procesamiento (fhProcesamiento en formato MH d/m/Y H:i:s) aunque sea simulada.
        $cuerpo = [
            'estado' => 'PROCESADO',
            'descripcionMsg' => $mensaje,
            'selloRecibido' => $sello,
            'fhProcesamiento' => now()->format('d/m/Y H:i:s'),
            '_mock' => true,
        ];

        // El mock respeta el flujo real: persiste sello + fecha y avanza Firmado → Enviado →
        // Aceptado por la máquina de estados (sin HTTP, sin credenciales).
        return $this->aplicarResultado($dte, $this->resultado('aceptado', 200, $mensaje, $sello, [], $cuerpo));
    }

    /**
     * Evalúa los candados de seguridad previos a una transmisión REAL. No hace HTTP,
     * no toca BD. Devuelve las razones de bloqueo (vacío = ningún candado bloquea).
     *
     * @return array{bloqueado: bool, razones: array<int, string>, flags: array<string, bool>}
     */
    public function evaluarCandados(): array
    {
        $enabled = (bool) config('dte.transmision.enabled', false);
        $confirm = (bool) config('dte.transmision.real_confirmation', false);
        $dryRun = (bool) config('dte.transmision.dry_run', true);
        $esProd = $this->esProduccion();
        $allowProd = (bool) config('dte.transmision.allow_production', false);
        $actualActivo = (bool) config('dte.transmision.sistema_actual_activo', true);
        $modo = $this->modoOperacion();

        $flags = [
            'enabled' => $enabled,
            'real_confirmation' => $confirm,
            'dry_run' => $dryRun,
            'es_produccion' => $esProd,
            'allow_production' => $allowProd,
            'sistema_actual_activo' => $actualActivo,
            'modo_operacion' => $modo,
        ];

        // Vía DEDICADA de pruebas: si está habilitada y el ambiente es testing, se
        // permite transmitir a apitest sin los candados de producción (no aplica a prod).
        if ($this->pruebasHabilitadas()) {
            return ['bloqueado' => false, 'razones' => [], 'flags' => $flags + ['pruebas_apitest' => true]];
        }

        $razones = [];
        if (! $enabled) {
            $razones[] = 'Transmisión deshabilitada. No se envió nada a Hacienda.';
        }
        if (! $confirm) {
            $razones[] = 'Falta la confirmación de transmisión real (DTE_TRANSMISION_REAL_CONFIRMATION=false). No se envió nada a Hacienda.';
        }
        if ($dryRun) {
            $razones[] = 'Modo dry-run activo (DTE_TRANSMISION_DRY_RUN=true): no se realiza transmisión real.';
        }
        if ($esProd && ! $allowProd) {
            $razones[] = 'Ambiente de producción sin autorización (DTE_TRANSMISION_ALLOW_PRODUCTION=false). No se transmitió.';
        }

        // Modo de operación frente al SISTEMA ACTUAL en uso (no se transmite desde dos
        // sistemas sin coordinar correlativos/punto de venta/ambiente).
        if ($modo === 'paralelo') {
            $razones[] = 'Modo paralelo: el sistema actual factura oficialmente; el sistema nuevo no transmite (solo genera JSON, firma local y dry-run).';
        } elseif ($modo === 'respaldo') {
            if (! $confirm) {
                $razones[] = 'Modo respaldo: la transmisión real requiere confirmación manual fuerte (DTE_TRANSMISION_REAL_CONFIRMATION=true) y revisión de correlativos; el sistema actual sigue activo.';
            }
        } elseif ($modo !== 'principal') {
            $razones[] = 'Modo de operación desconocido ("'.$modo.'"): por seguridad se bloquea como paralelo.';
        }

        // Refuerzo: con el sistema actual activo, solo el modo principal podría
        // transmitir realmente (la migración debe estar definida).
        if ($actualActivo && ! in_array($modo, ['paralelo', 'respaldo', 'principal'], true)) {
            $razones[] = 'Sistema actual en uso: transmisión real bloqueada por defecto (modo no principal).';
        }

        return [
            'bloqueado' => $razones !== [],
            'razones' => $razones,
            'flags' => $flags,
        ];
    }

    /**
     * ¿Es posible emitir REAL a producción AHORA MISMO? (candados abiertos + ambiente
     * de producción). SOLO LECTURA. La usa la guardia de la UI para exigir la frase
     * manual "EMITIR PRODUCCION" únicamente cuando una emisión real sería posible; en
     * modo seguro (paralelo/mock/dry-run/apitest) devuelve false y no estorba.
     */
    public function emisionRealPosible(): bool
    {
        return (bool) $this->estadoOperativo()['transmision_real_posible'];
    }

    /** ¿El DTE cumple las precondiciones para un dry-run (sin transmitir)? */
    public function puedeDryRun(Dte $dte): bool
    {
        try {
            $this->verificarPrecondiciones($dte);

            return true;
        } catch (DteTransmisionException $e) {
            return false;
        }
    }

    /** Modo de operación del sistema nuevo: paralelo (default seguro), respaldo, principal. */
    public function modoOperacion(): string
    {
        $m = strtolower(trim((string) config('dte.transmision.modo_operacion', 'paralelo')));

        return $m !== '' ? $m : 'paralelo';
    }

    /**
     * Resumen del modo de operación pensado para PANTALLA (badge del navbar / panel
     * "Salud del sistema"): mismo cálculo que `dte:modo-operacion` (reutiliza
     * evaluarCandados(), sin candados nuevos ni lógica fiscal). SOLO LECTURA: no
     * transmite, no hace HTTP, no muestra secretos.
     *
     * @return array{
     *     etiqueta: string, color: string, detalle: string,
     *     transmision_real_posible: bool,
     *     mocks: array{firma: bool, transmision: bool, invalidacion: bool, alguno: bool},
     *     candados: array{bloqueado: bool, razones: array<int, string>, flags: array<string, bool>}
     * }
     */
    public function estadoOperativo(): array
    {
        $c = $this->evaluarCandados();
        $flags = $c['flags'];
        $modo = $flags['modo_operacion'];
        $esProduccion = (bool) ($flags['es_produccion'] ?? false);

        // Misma terminología que `dte:modo-operacion` (no se inventa vocabulario nuevo).
        $etiqueta = match ($modo) {
            'paralelo' => 'PARALELO SEGURO',
            'respaldo' => $c['bloqueado'] ? 'RESPALDO BLOQUEADO' : 'RESPALDO LISTO',
            'principal' => $c['bloqueado'] ? 'PRINCIPAL BLOQUEADO' : 'PRINCIPAL LISTO',
            default => strtoupper($modo).' BLOQUEADO',
        };

        // evaluarCandados() NO bloqueó → una transmisión real es posible AHORA. Hay que
        // distinguir el DESTINO, porque no es lo mismo mandar a producción que a apitest:
        //  - PRODUCCIÓN: documentos fiscales reales → único caso de alerta ROJA.
        //  - apitest (pruebas): ambiente de prueba del MH; NO produce documentos válidos.
        //    La vía dedicada de pruebas (DTE_TRANSMISION_TEST_ENABLED) abre apitest aun en
        //    modo paralelo, por eso mirar solo `bloqueado` daba un falso "transmite REAL".
        $abierto = ! $c['bloqueado'];
        $produccionRealPosible = $abierto && $esProduccion;
        $apitestPosible = $abierto && ! $esProduccion;

        // En el sistema oficial, producción habilitada es el estado operativo esperado.
        // Solo se conserva el rojo cuando otro modo puede transmitir producción de forma inesperada.
        if ($produccionRealPosible && $modo === 'principal') {
            $color = 'ok';
            $puntoVenta = (string) (config('dte.punto_venta_predeterminado') ?: 'automático');
            $detalle = 'Producción activa · sistema principal · transmisión real habilitada · punto de venta '.$puntoVenta.'.';
        } elseif ($produccionRealPosible) {
            $color = 'critico';
            $detalle = 'Producción habilitada desde un modo no principal ('.$modo.'). Revisar la configuración operativa.';
        } elseif ($apitestPosible) {
            $color = 'advertencia';
            $detalle = 'Transmisión al ambiente de PRUEBAS (apitest) habilitada: envía a Hacienda de pruebas, NO a producción. Fuera del piloto conviene dejarla apagada (DTE_TRANSMISION_TEST_ENABLED=false).';
        } elseif ($modo === 'paralelo') {
            $color = 'ok';
            $detalle = 'Modo paralelo seguro: este sistema NO transmite producción (solo genera JSON, firma local y dry-run).';
        } else {
            $color = 'advertencia';
            // Bloqueado en respaldo/principal: mostrar por qué (incluye dry-run/confirmación).
            $detalle = $c['razones'] !== []
                ? 'Transmisión real bloqueada: '.implode(' ', $c['razones'])
                : 'Transmisión real bloqueada por candados de seguridad.';
        }

        $mocks = [
            'firma' => (bool) config('dte.firma.mock', false),
            'transmision' => (bool) config('dte.transmision.mock', false),
            'invalidacion' => (bool) config('dte.invalidacion.mock', false),
        ];
        $mocks['alguno'] = $mocks['firma'] || $mocks['transmision'] || $mocks['invalidacion'];

        return [
            'etiqueta' => $etiqueta,
            'color' => $color,
            'detalle' => $detalle,
            // Única condición de alerta ROJA: transmisión real a PRODUCCIÓN posible ahora.
            'transmision_real_posible' => $produccionRealPosible,
            // MODO SEGURO: NO es posible emitir real a producción ahora (paralelo, mock, dry-run,
            // candados cerrados o ambiente de pruebas). Es lo contrario de transmision_real_posible;
            // se expone con nombre propio para las pantallas (aviso + guardia de botones).
            'modo_seguro' => ! $produccionRealPosible,
            // Transmisión a apitest (pruebas) posible: es HTTP real, pero al ambiente de
            // pruebas del MH; no equivale a emitir documentos fiscales de producción.
            'apitest_posible' => $apitestPosible,
            'mocks' => $mocks,
            'candados' => $c,
        ];
    }

    /**
     * Modo DRY-RUN formal: valida precondiciones y arma el payload final, pero NO
     * hace HTTP, NO guarda sello, NO cambia estado, NO transmite. Devuelve un resumen
     * SEGURO (sin token, sin contraseña, sin el JWS completo).
     *
     * @return array{
     *     tipoDte: string, ambiente: string, version: int, codigoGeneracion: string,
     *     tiene_jws: bool, jws_preview: string, endpoint: string,
     *     auth_configurado: bool, candados: array{bloqueado: bool, razones: array<int, string>, flags: array<string, bool>}
     * }
     *
     * @throws DteTransmisionException si no pasa precondiciones
     */
    public function dryRun(Dte $dte): array
    {
        $this->verificarPrecondiciones($dte);
        $payload = $this->prepararPayloadRecepcion($dte);

        return [
            'tipoDte' => (string) $payload['tipoDte'],
            'ambiente' => (string) $payload['ambiente'],                        // código MH del DTE ('00'/'01')
            'ambiente_transmision' => (string) config('dte.transmision.ambiente', 'testing'), // rótulo operativo
            'version' => (int) $payload['version'],
            'codigoGeneracion' => (string) $payload['codigoGeneracion'],
            'tiene_jws' => filled($dte->json_firmado_path),
            'jws_preview' => $this->previewJws((string) $payload['documento']),
            'endpoint' => $this->urlRecepcion() ?: '(no configurado)',
            'auth_configurado' => $this->authConfigurado(),
            'candados' => $this->evaluarCandados(),
        ];
    }

    /**
     * Checklist de riesgo (SOLO LECTURA) previo a una transmisión real. No hace HTTP,
     * no transmite, no muestra secretos.
     *
     * @return array{listo: bool, checks: array<int, array{ok: bool, etiqueta: string, detalle: string}>}
     */
    public function preflight(Dte $dte): array
    {
        $checks = [];
        $add = function (bool $ok, string $etiqueta, string $detalle) use (&$checks) {
            $checks[] = ['ok' => $ok, 'etiqueta' => $etiqueta, 'detalle' => $detalle];
        };

        $disco = (string) config('dte.storage.disk', 'local');
        $jwsExiste = filled($dte->json_firmado_path) && $this->rutaFirmadaValida($dte->json_firmado_path)
            && Storage::disk($disco)->exists($this->normalizar($dte->json_firmado_path));

        // --- Documento ---
        $add($dte->estado === EstadoDte::Firmado, 'Documento firmado', $dte->estado->label());
        $add($jwsExiste, 'JWS firmado', $jwsExiste ? 'presente' : 'falta');
        $add(blank($dte->sello_recepcion), 'Sin sello de recepción', blank($dte->sello_recepcion) ? 'sí' : 'ya tiene sello');
        $add($dte->estado !== EstadoDte::Aceptado, 'No aceptado', $dte->estado !== EstadoDte::Aceptado ? 'sí' : 'ya aceptado');
        $add(! $dte->esAnulado(), 'No invalidado', ! $dte->esAnulado() ? 'sí' : 'invalidado');

        // --- Configuración ---
        $add(filled(config('dte.transmision.ambiente')), 'Ambiente configurado', (string) config('dte.transmision.ambiente'));
        $add($this->urlRecepcion() !== '', 'Endpoint recepción configurado', $this->urlRecepcion() ?: 'no configurado');
        $add($this->authConfigurado(), 'Auth configurado', $this->authConfigurado() ? 'sí' : 'no');
        $add((bool) config('dte.firma.enabled'), 'Firma habilitada', config('dte.firma.enabled') ? 'sí' : 'no');

        // --- Candados / convivencia con el sistema actual ---
        $c = $this->evaluarCandados();
        $modo = $c['flags']['modo_operacion'];
        $add(true, 'Sistema actual en uso', $c['flags']['sistema_actual_activo'] ? 'sí' : 'no');
        $add($modo === 'principal', 'Modo de operación', $modo);
        $add(false, 'Riesgo de correlativos', 'requiere revisión manual (no duplicar correlativos/punto de venta/ambiente entre sistemas)');
        $add($c['flags']['enabled'], 'Transmisión habilitada', $c['flags']['enabled'] ? 'sí' : 'no');
        $add(! $c['flags']['dry_run'], 'Dry-run desactivado', $c['flags']['dry_run'] ? 'NO (dry-run activo)' : 'sí');
        $add($c['flags']['real_confirmation'], 'Confirmación real', $c['flags']['real_confirmation'] ? 'sí' : 'no');
        $add(! $c['flags']['es_produccion'] || $c['flags']['allow_production'], 'Producción permitida', $c['flags']['es_produccion'] ? ($c['flags']['allow_production'] ? 'sí' : 'NO') : 'n/a (no es producción)');

        $precondicionesOk = $dte->estado === EstadoDte::Firmado && $jwsExiste
            && blank($dte->sello_recepcion) && ! $dte->esAnulado();

        return [
            'listo' => $precondicionesOk && ! $c['bloqueado'],
            'checks' => $checks,
        ];
    }

    /** URL de recepción (base + endpoint, sin barra final). '' si no está configurada. */
    private function urlRecepcion(): string
    {
        $base = rtrim((string) config('dte.transmision.url_base', ''), '/');
        $endpoint = '/'.ltrim((string) config('dte.transmision.endpoint_recepcion', ''), '/');
        if ($base === '' && trim($endpoint, '/') === '') {
            return '';
        }

        return rtrim($base.$endpoint, '/');
    }

    private function authConfigurado(): bool
    {
        // Auth disponible si hay usuario+password (para login) O un token manual
        // (DTE_TRANSMISION_TOKEN). Solo para reflejarlo en el diagnóstico/dry-run;
        // NO cambia cuándo ni cómo se transmite (eso lo rigen los candados).
        $tokenManual = filled(config('dte.transmision.token'));
        $userPwd = filled(config('dte.transmision.usuario_api')) && filled(config('dte.transmision.password'));

        return $userPwd || $tokenManual;
    }

    private function previewJws(string $jws): string
    {
        $jws = trim($jws);

        return $jws === '' ? '(vacío)' : mb_substr($jws, 0, 12).'… ('.mb_strlen($jws).' chars)';
    }

    private function esProduccion(): bool
    {
        $amb = strtolower((string) config('dte.transmision.ambiente', 'testing'));

        return in_array($amb, ['produccion', 'production', 'prod', '01'], true);
    }

    /**
     * ¿Transmisión REAL habilitada SOLO contra el ambiente de pruebas (apitest)?
     * Vía dedicada: cuando ambiente=testing y dte.transmision.test_enabled=true, se
     * permite el envío a apitest sin los candados de producción. Nunca aplica a prod.
     */
    public function pruebasHabilitadas(): bool
    {
        return ! $this->esProduccion() && (bool) config('dte.transmision.test_enabled', false);
    }

    /**
     * @throws DteTransmisionException
     */
    private function verificarPrecondiciones(Dte $dte): void
    {
        if ($dte->estado === EstadoDte::Aceptado) {
            throw new DteTransmisionException('El documento ya está aceptado; no se retransmite.');
        }
        if ($dte->esAnulado()) {
            throw new DteTransmisionException('El documento está invalidado/anulado; no se transmite.');
        }
        if ($dte->estado !== EstadoDte::Firmado) {
            throw new DteTransmisionException('Solo se transmite un documento firmado (actual: '.$dte->estado->label().'). Firme primero.');
        }
        if (blank($dte->numero_control) || blank($dte->codigo_generacion)) {
            throw new DteTransmisionException('Falta numeración oficial (numero_control / codigo_generacion).');
        }
        if (blank($dte->json_firmado_path)) {
            throw new DteTransmisionException('El documento no está firmado (json_firmado_path vacío). Firme primero.');
        }
        if (filled($dte->sello_recepcion)) {
            throw new DteTransmisionException('El documento ya tiene sello de recepción; no se retransmite.');
        }

        $disco = (string) config('dte.storage.disk', 'local');
        if (! $this->rutaFirmadaValida($dte->json_firmado_path)
            || ! Storage::disk($disco)->exists($this->normalizar($dte->json_firmado_path))) {
            throw new DteTransmisionException('No se encontró el archivo JWS firmado en el almacenamiento.');
        }
    }

    /**
     * Interpreta la respuesta de recepción (real o simulada con Http::fake) según el
     * Manual Técnico (4.2.1) SIN persistir nada. El MH responde por el campo `estado`
     * (PROCESADO = aceptado, RECHAZADO = rechazado) y un RECHAZADO puede venir con
     * HTTP 400; por eso se clasifica por `estado` antes que por el código HTTP.
     *
     * @return array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, observaciones: array<int, string>}
     */
    private function interpretarRespuesta(\Illuminate\Http\Client\Response $resp): array
    {
        $status = $resp->status();
        $cuerpo = $resp->json();

        if (is_array($cuerpo) && filled($cuerpo['estado'] ?? null)) {
            $estado = strtoupper((string) $cuerpo['estado']);
            $mensaje = (string) ($cuerpo['descripcionMsg'] ?? $cuerpo['mensaje'] ?? 'Sin mensaje.');
            $observaciones = $this->observaciones($cuerpo['observaciones'] ?? null);
            // El sello se interpreta pero NO se guarda en esta fase.
            $sello = isset($cuerpo['selloRecibido']) ? (string) $cuerpo['selloRecibido'] : null;

            if (in_array($estado, ['PROCESADO', 'ACEPTADO', 'RECIBIDO'], true)) {
                return $this->resultado('aceptado', $status, $mensaje, $sello, $observaciones, $cuerpo);
            }
            if (in_array($estado, ['RECHAZADO', 'RECHAZO'], true)) {
                return $this->resultado('rechazado', $status, $mensaje, null, $observaciones, $cuerpo);
            }

            return $this->resultado('desconocido', $status, 'Respuesta de recepción no reconocida.', null, $observaciones);
        }

        if ($status === 401 || $status === 403) {
            return $this->resultado('token_invalido', $status, 'Credenciales/token rechazados por recepción.');
        }
        if (! is_array($cuerpo)) {
            return $this->resultado('respuesta_malformada', $status, 'La respuesta de recepción no es JSON válido.');
        }

        return $this->resultado('error_http', $status, 'Recepción respondió HTTP '.$status.' sin estado reconocible.');
    }

    /**
     * @param  mixed  $obs
     * @return array<int, string>
     */
    private function observaciones($obs): array
    {
        if (! is_array($obs)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($o) => trim((string) $o), $obs), fn ($o) => $o !== ''));
    }

    /**
     * @param  array<int, string>  $observaciones
     * @param  array<string, mixed>|null  $cuerpo  respuesta cruda del MH (para persistir)
     * @return array{resultado: string, http_status: int|null, mensaje: string, sello: string|null, observaciones: array<int, string>, cuerpo: array<string, mixed>|null}
     */
    private function resultado(string $resultado, ?int $httpStatus, string $mensaje, ?string $sello = null, array $observaciones = [], ?array $cuerpo = null): array
    {
        return [
            'resultado' => $resultado,
            'http_status' => $httpStatus,
            'mensaje' => $mensaje,
            'sello' => $sello,
            'observaciones' => $observaciones,
            // Cuerpo crudo de la respuesta del MH (se persiste en aceptado/rechazado).
            'cuerpo' => $cuerpo,
        ];
    }

    private function rutaFirmadaValida(?string $ruta): bool
    {
        $carpeta = trim((string) config('dte.storage.firmados', 'dte/firmados'), '/');
        $norm = $this->normalizar($ruta);

        return $norm !== '' && ! str_contains($norm, '..') && str_starts_with($norm, $carpeta.'/');
    }

    private function normalizar(?string $ruta): string
    {
        return ltrim(str_replace('\\', '/', (string) $ruta), '/');
    }
}
