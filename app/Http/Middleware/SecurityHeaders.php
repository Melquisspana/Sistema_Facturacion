<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Añade cabeceras de seguridad HTTP a cada respuesta web.
 * Los valores se configuran en config/security.php.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $config = config('security.headers');

        if (! ($config['enabled'] ?? true)) {
            return $response;
        }

        $response->headers->set('X-Frame-Options', $config['x_frame_options']);
        $response->headers->set('X-Content-Type-Options', $config['x_content_type_options']);
        $response->headers->set('Referrer-Policy', $config['referrer_policy']);
        $response->headers->set('Permissions-Policy', $config['permissions_policy']);

        $csp = $config['content_security_policy'] ?? [];
        if ($csp['enabled'] ?? false) {
            $value = implode('; ', $csp['directives']);
            $header = ($csp['report_only'] ?? false)
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            $response->headers->set($header, $value);
        }

        return $response;
    }
}
