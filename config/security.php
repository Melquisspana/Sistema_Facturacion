<?php

/*
|--------------------------------------------------------------------------
| Configuración de seguridad de la aplicación
|--------------------------------------------------------------------------
|
| Centraliza las cabeceras de seguridad HTTP (las aplica el middleware
| App\Http\Middleware\SecurityHeaders), la política de contraseñas y el
| límite de intentos de inicio de sesión.
|
*/

return [

    /*
    | Cabeceras de seguridad HTTP. Se pueden desactivar globalmente con
    | SECURITY_HEADERS_ENABLED=false (no recomendado).
    */
    'headers' => [
        'enabled' => env('SECURITY_HEADERS_ENABLED', true),

        // Evita que la app se cargue dentro de un <iframe> (clickjacking).
        'x_frame_options' => 'DENY',

        // Evita el "MIME sniffing".
        'x_content_type_options' => 'nosniff',

        // Cuánta información de referencia se envía al navegar fuera del sitio.
        'referrer_policy' => 'strict-origin-when-cross-origin',

        // Desactiva APIs del navegador que la app no usa.
        'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',

        /*
        | Content-Security-Policy "básica". Permite 'unsafe-inline' y
        | 'unsafe-eval' en scripts para no romper Alpine.js/Livewire (que
        | evalúan expresiones en tiempo de ejecución). Se podrá endurecer
        | con nonces más adelante. Usar CSP_REPORT_ONLY=true para probar sin
        | bloquear.
        */
        'content_security_policy' => [
            'enabled' => env('CSP_ENABLED', true),
            'report_only' => env('CSP_REPORT_ONLY', false),
            'directives' => [
                "default-src 'self'",
                "base-uri 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "object-src 'none'",
            ],
        ],
    ],

    /*
    | Política de contraseñas (se aplicará en la gestión de usuarios y reglas
    | de validación en la fase de seguridad/usuarios).
    */
    'password' => [
        'min_length' => (int) env('PASSWORD_MIN_LENGTH', 12),
        'require_mixed_case' => env('PASSWORD_REQUIRE_MIXED_CASE', true),
        'require_numbers' => env('PASSWORD_REQUIRE_NUMBERS', true),
        'require_symbols' => env('PASSWORD_REQUIRE_SYMBOLS', true),
    ],

    /*
    | Límite de intentos de inicio de sesión (protección contra fuerza bruta).
    | Se conectará al controlador de login en la fase de seguridad/usuarios.
    */
    'login_throttle' => [
        'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'decay_minutes' => (int) env('LOGIN_DECAY_MINUTES', 1),
    ],
];
