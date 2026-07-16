<?php

namespace App\Services\Dte;

use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Exceptions\Dte\DteTransmisionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Autenticación contra el servicio de seguridad del MH para obtener el token de
 * transmisión — FASE DE PREPARACIÓN.
 *
 * Bloqueado por defecto: si 'dte.transmision.enabled' = false, NO hace ninguna
 * petición HTTP. El token se cachea (Cache de Laravel) con TTL por debajo de la
 * vigencia oficial (48 h pruebas / 24 h producción). NUNCA imprime ni loguea
 * usuario, contraseña ni token, y nunca los guarda en base de datos.
 *
 * Flujo (Manual Técnico 4.1): POST form-urlencoded { user, pwd } al endpoint de
 * autenticación; respuesta OK trae body.token con prefijo "Bearer".
 */
class DteTransmisionAuthService
{
    private const CACHE_PREFIX = 'dte.transmision.token.';

    private const MSG_TESTING_SIN_CREDENCIALES = 'Credenciales de apitest/homologación no configuradas.';

    /**
     * Devuelve el token de autorización ("Bearer ...") para transmitir.
     *
     * @throws DteTransmisionDeshabilitadaException si la transmisión está deshabilitada
     * @throws DteTransmisionException              si faltan credenciales o la auth falla
     */
    public function obtenerToken(): string
    {
        if (! (bool) config('dte.transmision.enabled', false) && ! $this->pruebasHabilitadas()) {
            throw new DteTransmisionDeshabilitadaException(
                'Transmisión deshabilitada (dte.transmision.enabled=false). No se autenticó contra Hacienda.'
            );
        }

        // Token provisto manualmente (override por .env): se usa tal cual, normalizado.
        $override = trim((string) config('dte.transmision.token', ''));
        if ($override !== '') {
            return $this->normalizarBearer($override);
        }

        // Token cacheado vigente.
        $cached = Cache::get($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // Login real (en tests, Http::fake).
        $token = $this->login();
        Cache::put($this->cacheKey(), $token, $this->ttlSegundos());

        return $token;
    }

    /**
     * Diagnóstico de SOLO LECTURA (sin secretos, sin HTTP). Lo usa `dte:auth-check`.
     *
     * @return array{
     *     ambiente: string, url: string, habilitada: bool, auth_test_real: bool,
     *     usuario_configurado: bool, password_configurado: bool,
     *     token_manual_configurado: bool, token_cacheado: bool, vigencia_horas: int
     * }
     */
    public function diagnostico(): array
    {
        $cred = $this->credencialesActuales();

        return [
            'ambiente' => $this->esProduccion() ? 'produccion' : 'testing',
            'url' => $this->authUrl(),
            'habilitada' => (bool) config('dte.transmision.enabled', false),
            'auth_test_real' => (bool) config('dte.transmision.auth_test_real_enabled', false),
            'usuario_configurado' => filled($cred['usuario']),
            'password_configurado' => filled($cred['password']),
            'token_manual_configurado' => filled(config('dte.transmision.token')),
            'token_cacheado' => is_string(Cache::get($this->cacheKey())),
            'vigencia_horas' => $this->esProduccion() ? 24 : 48,
        ];
    }

    /**
     * Prueba CONTROLADA del login real SOLO contra el ambiente de pruebas (apitest).
     * No transmite ningún DTE. Devuelve un resultado SEGURO (sin token, sin
     * contraseña). NO hace HTTP salvo que TODOS los candados de prueba estén OK:
     *  - DTE_AUTH_TEST_REAL_ENABLED=true
     *  - ambiente = testing (no producción)
     *  - URL contiene apitest.dtes.mh.gob.sv
     *  - usuario y contraseña configurados
     * El token, si se obtiene, vive solo en Cache (TTL testing) y nunca se imprime.
     *
     * @return array{
     *     bloqueado: bool, razon: ?string, ambiente: string, url: string,
     *     usuario_configurado: bool, password_configurado: bool,
     *     token_obtenido: bool, token_cacheado: bool
     * }
     */
    public function pruebaAuthTesting(): array
    {
        $cred = $this->credencialesTesting();
        $r = [
            'bloqueado' => true,
            'razon' => null,
            'ambiente' => $this->esProduccion() ? 'produccion' : 'testing',
            'url' => $this->authUrl(),
            'usuario_configurado' => filled($cred['usuario']),
            'password_configurado' => filled($cred['password']),
            'token_obtenido' => false,
            'token_cacheado' => is_string(Cache::get($this->cacheKey())),
        ];

        if (! (bool) config('dte.transmision.auth_test_real_enabled', false)) {
            $r['razon'] = 'DTE_AUTH_TEST_REAL_ENABLED=false: no se intenta login real.';

            return $r;
        }
        if ($this->esProduccion()) {
            $r['razon'] = 'Ambiente de producción no permitido para la prueba de auth (solo testing).';

            return $r;
        }
        if (! $this->urlEsTesting()) {
            $r['razon'] = 'La URL de autenticación no es el ambiente de pruebas (apitest.dtes.mh.gob.sv).';

            return $r;
        }
        if (! $r['usuario_configurado'] || ! $r['password_configurado']) {
            $r['razon'] = self::MSG_TESTING_SIN_CREDENCIALES;

            return $r;
        }

        // Intento de login real SOLO testing. El token NUNCA se imprime.
        try {
            $token = $this->login();
            Cache::put($this->cacheKey(), $token, $this->ttlSegundos());
            $r['bloqueado'] = false;
            $r['token_obtenido'] = true;
            $r['token_cacheado'] = true;
        } catch (DteTransmisionException $e) {
            // El mensaje de login() no incluye usuario/contraseña/token.
            $r['razon'] = 'Login rechazado: '.$e->getMessage();
        }

        return $r;
    }

    /** ¿La URL de autenticación apunta al ambiente de pruebas (apitest)? */
    private function urlEsTesting(): bool
    {
        return str_contains($this->authUrl(), 'apitest.dtes.mh.gob.sv');
    }

    /**
     * Inspección SEGURA del request de autenticación (sin HTTP, sin secretos). Muestra
     * SOLO la estructura y métricas enmascaradas del user/pwd (longitudes, si el user
     * tiene guiones, si es solo dígitos), NUNCA los valores. Lo usa `dte:auth-inspect`.
     *
     * @return array{
     *     metodo: string, ambiente: string, url: string, content_type: string,
     *     user_agent: string, campos: array<int, string>,
     *     user_configurado: bool, user_longitud: int, user_tiene_guiones: bool,
     *     user_solo_digitos: bool, user_cant_digitos: int,
     *     password_configurada: bool, password_longitud: int,
     *     token_manual_configurado: bool
     * }
     */
    public function inspeccionarRequest(): array
    {
        $cred = $this->credencialesActuales();
        $user = $cred['usuario'];
        $pwd = $cred['password'];

        return [
            'metodo' => 'POST',
            'ambiente' => $this->esProduccion() ? 'produccion' : 'testing',
            'url' => $this->authUrl(),
            'content_type' => 'application/x-www-form-urlencoded',
            'user_agent' => (string) config('dte.transmision.user_agent', 'DTE/1.0'),
            'campos' => ['user', 'pwd'],
            'user_configurado' => $user !== '',
            'user_longitud' => strlen($user),
            'user_tiene_guiones' => str_contains($user, '-'),
            'user_solo_digitos' => $user !== '' && preg_match('/^\d+$/', $user) === 1,
            'user_cant_digitos' => strlen(preg_replace('/\D/', '', $user)),
            'password_configurada' => $pwd !== '',
            'password_longitud' => strlen($pwd),
            'token_manual_configurado' => filled(config('dte.transmision.token')),
        ];
    }

    /**
     * Prueba CONTROLADA del login real SOLO contra PRODUCCIÓN (api.dtes.mh.gob.sv),
     * para diagnosticar si la credencial es de producción (no de pruebas). Es
     * LOGIN-ONLY: NO transmite ningún DTE, **NO cachea ni devuelve el token** (aunque
     * el login sea aceptado, el token se descarta), y está detrás de su propio candado
     * `DTE_AUTH_TEST_PROD_ENABLED` (default false). No abre los candados de transmisión.
     * Nunca imprime usuario, contraseña ni token; solo el código HTTP y el mensaje del MH.
     *
     * @return array{
     *     bloqueado: bool, razon: ?string, url: string,
     *     usuario_configurado: bool, password_configurado: bool,
     *     login_aceptado: bool, http_status: ?int, mensaje_mh: ?string
     * }
     */
    public function pruebaAuthProduccion(): array
    {
        $url = 'https://api.dtes.mh.gob.sv/'.ltrim((string) config('dte.transmision.endpoint_auth', '/seguridad/auth'), '/');
        $cred = $this->credencialesProduccion();
        $r = [
            'bloqueado' => true,
            'razon' => null,
            'url' => $url,
            'usuario_configurado' => filled($cred['usuario']),
            'password_configurado' => filled($cred['password']),
            'login_aceptado' => false,
            'http_status' => null,
            'mensaje_mh' => null,
        ];

        if (! (bool) config('dte.transmision.auth_test_prod_enabled', false)) {
            $r['razon'] = 'DTE_AUTH_TEST_PROD_ENABLED=false: no se intenta login real contra producción.';

            return $r;
        }
        if (! $r['usuario_configurado'] || ! $r['password_configurado']) {
            $r['razon'] = 'Faltan credenciales (DTE_PROD_USER / DTE_PROD_PASSWORD, o DTE_TRANSMISION_USER / DTE_TRANSMISION_PASSWORD como respaldo).';

            return $r;
        }

        $user = $cred['usuario'];
        $pwd = $cred['password'];
        $userAgent = (string) config('dte.transmision.user_agent', 'DTE/1.0');

        try {
            // form-urlencoded (Manual 4.1), mismo formato que testing. NO se loguea nada.
            $resp = Http::timeout((int) config('dte.transmision.timeout', 15))
                ->asForm()
                ->withHeaders(['User-Agent' => $userAgent])
                ->post($url, ['user' => $user, 'pwd' => $pwd]);
        } catch (Throwable $e) {
            $r['bloqueado'] = false;
            $r['razon'] = 'No se pudo conectar con el servicio de autenticación de producción.';

            return $r;
        }

        $r['bloqueado'] = false;
        $r['http_status'] = $resp->status();
        $cuerpo = $resp->json();
        if (is_array($cuerpo)) {
            $aceptado = (($cuerpo['status'] ?? null) === 'OK') && filled($cuerpo['body']['token'] ?? null);
            $r['login_aceptado'] = $aceptado;
            // Mensaje NO secreto del MH (su propio status/error), nunca el token.
            $r['mensaje_mh'] = $aceptado
                ? 'OK'
                : (string) ($cuerpo['message'] ?? $cuerpo['error'] ?? $cuerpo['status'] ?? 'sin mensaje');
        }
        // El token NO se cachea ni se devuelve: se descarta a propósito.

        return $r;
    }

    /** Vigencia oficial estimada del token (horas), para mostrar sin revelar el token. */
    public function vigenciaHoras(): int
    {
        return $this->esProduccion() ? 24 : 48;
    }

    /**
     * @throws DteTransmisionException
     */
    private function login(): string
    {
        $cred = $this->credencialesActuales();
        $user = $cred['usuario'];
        $pwd = $cred['password'];

        // Testing/apitest: SIN fallback a producción. Falla claro antes de cualquier HTTP.
        if (! $this->esProduccion() && ($user === '' || $pwd === '')) {
            throw new DteTransmisionException(self::MSG_TESTING_SIN_CREDENCIALES);
        }
        if ($user === '') {
            throw new DteTransmisionException('Falta el usuario de transmisión (configure DTE_PROD_USER en .env; DTE_TRANSMISION_USER sirve de respaldo temporal).');
        }
        if ($pwd === '') {
            throw new DteTransmisionException('Falta la contraseña de transmisión (configure DTE_PROD_PASSWORD en .env; DTE_TRANSMISION_PASSWORD sirve de respaldo temporal).');
        }

        $url = $this->authUrl();
        $timeout = (int) config('dte.transmision.timeout', 15);
        $userAgent = (string) config('dte.transmision.user_agent', 'DTE/1.0');

        try {
            // form-urlencoded (Manual 4.1). El User-Agent es requerido. NO se loguea nada.
            $resp = Http::timeout($timeout)
                ->asForm()
                ->withHeaders(['User-Agent' => $userAgent])
                ->post($url, ['user' => $user, 'pwd' => $pwd]);
        } catch (Throwable $e) {
            // El mensaje NUNCA incluye usuario/contraseña.
            throw new DteTransmisionException('No se pudo conectar con el servicio de autenticación: '.$e->getMessage());
        }

        $status = $resp->status();
        if ($status === 401 || $status === 403) {
            throw new DteTransmisionException('Autenticación rechazada por el MH (HTTP '.$status.').');
        }

        $cuerpo = $resp->json();
        if (! is_array($cuerpo)) {
            throw new DteTransmisionException('Respuesta de autenticación malformada (no es JSON válido).');
        }
        if (($cuerpo['status'] ?? null) !== 'OK') {
            $msg = (string) ($cuerpo['message'] ?? $cuerpo['error'] ?? 'desconocido');
            throw new DteTransmisionException('Autenticación rechazada: '.$msg);
        }

        $token = $cuerpo['body']['token'] ?? null;
        if (! is_string($token) || trim($token) === '') {
            throw new DteTransmisionException('La autenticación no devolvió un token.');
        }

        return $this->normalizarBearer(trim($token));
    }

    /** El token del MH ya incluye "Bearer"; si no, se antepone (Manual 4.1). */
    private function normalizarBearer(string $token): string
    {
        return str_starts_with($token, 'Bearer ') ? $token : 'Bearer '.$token;
    }

    /** URL de autenticación según el ambiente (host por defecto si url_base está vacío). */
    private function authUrl(): string
    {
        $base = rtrim((string) config('dte.transmision.url_base', ''), '/');
        if ($base === '') {
            $base = $this->esProduccion() ? 'https://api.dtes.mh.gob.sv' : 'https://apitest.dtes.mh.gob.sv';
        }
        $path = '/'.ltrim((string) config('dte.transmision.endpoint_auth', '/seguridad/auth'), '/');

        return rtrim($base.$path, '/');
    }

    private function esProduccion(): bool
    {
        $amb = strtolower((string) config('dte.transmision.ambiente', 'testing'));

        return in_array($amb, ['produccion', 'production', 'prod', '01'], true);
    }

    /** Credenciales de PRODUCCIÓN: caen de vuelta a DTE_TRANSMISION_USER/PASSWORD si DTE_PROD_* no están definidas. */
    private function credencialesProduccion(): array
    {
        return [
            'usuario' => (string) config('dte.transmision.usuario_produccion', ''),
            'password' => (string) config('dte.transmision.password_produccion', ''),
        ];
    }

    /** Credenciales de TESTING/apitest: SIN fallback a producción, nunca. */
    private function credencialesTesting(): array
    {
        return [
            'usuario' => (string) config('dte.transmision.usuario_testing', ''),
            'password' => (string) config('dte.transmision.password_testing', ''),
        ];
    }

    /**
     * Par de credenciales según el ambiente ACTUAL de transmisión (dte.transmision.ambiente).
     * Producción y testing/apitest son cuentas DISTINTAS en Hacienda: testing NUNCA cae
     * de vuelta a las credenciales de producción, aunque estén configuradas.
     *
     * @return array{usuario: string, password: string}
     */
    private function credencialesActuales(): array
    {
        return $this->esProduccion() ? $this->credencialesProduccion() : $this->credencialesTesting();
    }

    /** ¿Auth habilitada por la vía dedicada de pruebas (testing + test_enabled)? */
    private function pruebasHabilitadas(): bool
    {
        return ! $this->esProduccion() && (bool) config('dte.transmision.test_enabled', false);
    }

    /** TTL del cache: por debajo de la vigencia oficial para refrescar a tiempo. */
    private function ttlSegundos(): int
    {
        return $this->esProduccion() ? 23 * 3600 : 47 * 3600;
    }

    private function cacheKey(): string
    {
        return self::CACHE_PREFIX.($this->esProduccion() ? 'prod' : 'test');
    }
}
