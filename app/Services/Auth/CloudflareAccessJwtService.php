<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Validación CRIPTOGRÁFICA del JWT de Cloudflare Access (header
 * Cf-Access-Jwt-Assertion). Política FAIL CLOSED: ante cualquier duda —
 * formato raro, algoritmo no permitido, kid desconocido, certificados
 * inaccesibles, firma que no verifica, issuer/audience distintos, token
 * vencido o aún no vigente, email ausente o malformado — devuelve null y el
 * llamador NO autentica.
 *
 * La verificación de firma usa los CERTIFICADOS PÚBLICOS del team
 * (https://{team}/cdn-cgi/access/certs, campo `public_certs` en PEM) con
 * openssl_verify + SHA-256: sin dependencias nuevas y sin implementar
 * conversión JWK->PEM a mano. Los certificados se CACHEAN (TTL configurable);
 * los fallos de red NUNCA se cachean. Este servicio no escribe BD, no loguea
 * el JWT ni ningún secreto.
 */
class CloudflareAccessJwtService
{
    /** Único algoritmo aceptado (el que usa Cloudflare Access). */
    private const ALGORITMO = 'RS256';

    /**
     * Valida el JWT y devuelve sus claims si TODO pasa; null si algo falla.
     *
     * @return array<string, mixed>|null
     */
    public function validar(string $jwt): ?array
    {
        $partes = explode('.', $jwt);
        if (count($partes) !== 3 || $partes[0] === '' || $partes[1] === '' || $partes[2] === '') {
            return $this->rechazar('formato');
        }

        [$header64, $payload64, $firma64] = $partes;

        $header = $this->decodificarJson($header64);
        $payload = $this->decodificarJson($payload64);
        $firma = $this->base64UrlDecode($firma64);

        if ($header === null || $payload === null || $firma === null || $firma === '') {
            return $this->rechazar('decodificacion');
        }

        // Algoritmo FIJO: nada de "none" ni familias HMAC (evita confusión de clave).
        if (($header['alg'] ?? null) !== self::ALGORITMO) {
            return $this->rechazar('algoritmo');
        }

        $kid = (string) ($header['kid'] ?? '');
        if ($kid === '') {
            return $this->rechazar('kid_ausente');
        }

        $certPem = $this->certificadoPorKid($kid);
        if ($certPem === null) {
            return $this->rechazar('kid_desconocido');
        }

        $clavePublica = openssl_pkey_get_public($certPem);
        if ($clavePublica === false) {
            return $this->rechazar('certificado_invalido');
        }

        $verifica = openssl_verify($header64.'.'.$payload64, $firma, $clavePublica, OPENSSL_ALGO_SHA256);
        if ($verifica !== 1) {
            return $this->rechazar('firma');
        }

        // --- Claims (después de la firma: nunca se decide nada con claims sin verificar) ---

        $issuerEsperado = 'https://'.trim((string) config('cloudflare_access.team_domain'));
        if (($payload['iss'] ?? null) !== $issuerEsperado) {
            return $this->rechazar('issuer');
        }

        $audEsperado = (string) config('cloudflare_access.aud');
        $aud = $payload['aud'] ?? [];
        $aud = is_array($aud) ? $aud : [$aud];
        if ($audEsperado === '' || ! in_array($audEsperado, $aud, true)) {
            return $this->rechazar('audience');
        }

        $ahora = time();
        if (! isset($payload['exp']) || ! is_numeric($payload['exp']) || (int) $payload['exp'] <= $ahora) {
            return $this->rechazar('expirado');
        }
        // nbf presente pero malformado (no numérico) también es rechazo: un claim
        // temporal que no se puede evaluar nunca se trata como "válido" (fail closed).
        if (isset($payload['nbf']) && (! is_numeric($payload['nbf']) || (int) $payload['nbf'] > $ahora)) {
            return $this->rechazar('nbf');
        }

        $email = (string) ($payload['email'] ?? '');
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->rechazar('email');
        }

        return $payload;
    }

    /**
     * Certificado PEM del kid indicado, desde el endpoint oficial del team,
     * cacheado. Devuelve null si no se puede obtener o el kid no existe
     * (fail closed). Los errores de red no se cachean.
     *
     * ROTACIÓN DE CLAVES: si el kid no está en el set cacheado (Cloudflare rota
     * sus claves periódicamente), se refresca el set UNA vez y se reintenta. Un
     * candado de 60s impide que JWTs con kids basura conviertan cada request en
     * una petición al endpoint (anti-DoS del refresco).
     */
    private function certificadoPorKid(string $kid): ?string
    {
        $team = trim((string) config('cloudflare_access.team_domain'));
        // El team domain viene de config/env (nunca del request), pero igual se
        // valida como hostname: defensa extra contra typos o valores raros que
        // armarían una URL inesperada hacia el endpoint de certificados.
        if ($team === '' || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $team) !== 1) {
            return null;
        }

        $cacheKey = 'cloudflare_access.certs.'.$team;
        $llaveRefresco = $cacheKey.'.refrescado';
        $ttl = (int) config('cloudflare_access.certs_cache_ttl', 3600);
        $certs = Cache::get($cacheKey);

        // Caché fría: primera descarga (no cuenta para el candado anti-DoS).
        if (! is_array($certs)) {
            $certs = $this->descargarCertificados($team);
            if ($certs === null) {
                return null; // sin cachear el fallo
            }
            Cache::put($cacheKey, $certs, $ttl);

            return $certs[$kid] ?? null;
        }

        if (isset($certs[$kid])) {
            return $certs[$kid];
        }

        // Kid ausente con caché caliente (rotación de claves): un solo refresco por
        // minuto — kids basura no pueden convertir cada request en una petición.
        if (Cache::get($llaveRefresco)) {
            return null;
        }
        $certs = $this->descargarCertificados($team);
        if ($certs === null) {
            return null; // sin cachear el fallo
        }
        Cache::put($cacheKey, $certs, $ttl);
        Cache::put($llaveRefresco, true, 60);

        return $certs[$kid] ?? null;
    }

    /**
     * Descarga el set de certificados públicos: mapa kid => PEM. Timeout corto
     * y try/catch: el SSO nunca debe tumbar la página por un problema de red
     * (simplemente no autentica).
     *
     * @return array<string, string>|null
     */
    private function descargarCertificados(string $team): ?array
    {
        try {
            $resp = Http::timeout((int) config('cloudflare_access.certs_timeout', 5))
                ->acceptJson()
                ->get('https://'.$team.'/cdn-cgi/access/certs');
        } catch (Throwable) {
            Log::warning('Cloudflare Access: no se pudieron obtener los certificados públicos (red).');

            return null;
        }

        if (! $resp->successful()) {
            Log::warning('Cloudflare Access: el endpoint de certificados respondió HTTP '.$resp->status().'.');

            return null;
        }

        $mapa = [];
        foreach ((array) $resp->json('public_certs', []) as $cert) {
            $kid = (string) ($cert['kid'] ?? '');
            $pem = (string) ($cert['cert'] ?? '');
            if ($kid !== '' && str_contains($pem, 'BEGIN CERTIFICATE')) {
                $mapa[$kid] = $pem;
            }
        }

        return $mapa !== [] ? $mapa : null;
    }

    /** @return array<string, mixed>|null */
    private function decodificarJson(string $segmento64): ?array
    {
        $crudo = $this->base64UrlDecode($segmento64);
        if ($crudo === null) {
            return null;
        }
        $json = json_decode($crudo, true);

        return is_array($json) ? $json : null;
    }

    private function base64UrlDecode(string $dato): ?string
    {
        $decodificado = base64_decode(strtr($dato, '-_', '+/'), true);

        return $decodificado === false ? null : $decodificado;
    }

    /**
     * Registro del rechazo SIN datos sensibles: solo el motivo técnico, nunca
     * el JWT, el email ni claims.
     */
    private function rechazar(string $motivo): ?array
    {
        Log::info('Cloudflare Access: JWT rechazado ('.$motivo.').');

        return null;
    }
}
