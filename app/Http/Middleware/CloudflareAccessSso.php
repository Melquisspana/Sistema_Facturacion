<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\CloudflareAccessJwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * SSO con Cloudflare Access para el dominio público. SOLO corre cuando TODAS
 * estas condiciones se cumplen; en cualquier otro caso es un no-op y el login
 * local de Laravel (facturacion.test / localhost / IP local / Tailscale) sigue
 * intacto:
 *
 *  1. cloudflare_access.enabled = true Y team_domain + aud configurados.
 *  2. El Host coincide EXACTAMENTE con allowed_host.
 *  3. La petición llegó por HTTPS (vía proxy confiable: cloudflared conecta
 *     desde 127.0.0.1, único proxy en trustProxies).
 *
 * Con el header Cf-Access-Jwt-Assertion presente en el host autorizado, el JWT
 * se valida CRIPTOGRÁFICAMENTE (CloudflareAccessJwtService, fail closed):
 *  - inválido (firma/issuer/audience/exp/...) -> 403 neutro.
 *  - válido + usuario local existente y activo -> Auth::login + regeneración de
 *    sesión (anti session-fixation) + auditoría (sin guardar el JWT).
 *  - válido + usuario inexistente o inactivo -> 403 neutro (NUNCA crea usuarios
 *    ni asigna roles).
 *
 * El header Cf-Access-Authenticated-User-Email NUNCA se usa para autenticar.
 */
class CloudflareAccessSso
{
    public function __construct(private readonly CloudflareAccessJwtService $jwtService) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->aplicaAlRequest($request)) {
            return $next($request);
        }

        // Sesión local ya iniciada: no revalidar el JWT en cada request.
        if (Auth::check()) {
            return $next($request);
        }

        $jwt = (string) $request->headers->get('Cf-Access-Jwt-Assertion', '');
        if ($jwt === '') {
            // Sin JWT no hay identidad que evaluar (p. ej. rutas abiertas del
            // propio Cloudflare). Nunca se autentica por headers de email.
            return $next($request);
        }

        $claims = $this->jwtService->validar($jwt);
        if ($claims === null) {
            // JWT presente pero inválido: en el dominio público esto es una
            // anomalía real (Cloudflare siempre manda uno válido) -> corte neutro.
            abort(403, 'Acceso no autorizado.');
        }

        if (! (bool) config('cloudflare_access.auto_login', true)) {
            return $next($request);
        }

        $usuario = $this->usuarioLocal((string) $claims['email']);
        if ($usuario === null) {
            Log::warning('Cloudflare Access: identidad válida sin usuario local habilitado.', [
                'email_enmascarado' => $this->enmascarar((string) $claims['email']),
            ]);

            abort(403, 'Acceso no autorizado.');
        }

        Auth::login($usuario);
        $request->session()->regenerate();

        // Auditoría del acceso SSO (spatie/activitylog). Sin JWT ni claims.
        activity()
            ->causedBy($usuario)
            ->withProperties(['via' => 'cloudflare_access', 'host' => $request->getHost()])
            ->log('Inicio de sesión SSO (Cloudflare Access)');

        return $next($request);
    }

    /** ¿El SSO aplica a este request? (config completa + host exacto + HTTPS). */
    private function aplicaAlRequest(Request $request): bool
    {
        if (! (bool) config('cloudflare_access.enabled', false)) {
            return false;
        }

        $team = trim((string) config('cloudflare_access.team_domain'));
        $aud = trim((string) config('cloudflare_access.aud'));
        $hostPermitido = trim((string) config('cloudflare_access.allowed_host'));
        if ($team === '' || $aud === '' || $hostPermitido === '') {
            return false; // configuración incompleta = SSO apagado (default seguro)
        }

        // Comparación EXACTA de host (sin subdominios ni comodines) y solo HTTPS
        // (cloudflared manda X-Forwarded-Proto=https desde 127.0.0.1, ya confiado).
        return strcasecmp($request->getHost(), $hostPermitido) === 0
            && $request->isSecure();
    }

    /** Usuario local por email normalizado; solo si existe y está activo. */
    private function usuarioLocal(string $email): ?User
    {
        $usuario = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->first();

        return ($usuario !== null && $usuario->activo) ? $usuario : null;
    }

    /** Enmascara un email para el log: "j***@dominio.com". */
    private function enmascarar(string $email): string
    {
        $arroba = strpos($email, '@');
        if ($arroba === false || $arroba < 1) {
            return '***';
        }

        return $email[0].'***'.substr($email, $arroba);
    }
}
