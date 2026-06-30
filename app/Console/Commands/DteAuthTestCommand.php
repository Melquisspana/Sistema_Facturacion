<?php

namespace App\Console\Commands;

use App\Services\Dte\DteTransmisionAuthService;
use Illuminate\Console\Command;

/**
 * Prueba CONTROLADA del login/token contra el ambiente de PRUEBAS (apitest), sin
 * transmitir ningún DTE. Bloqueada salvo que TODOS los candados de prueba estén OK
 * (ver DteTransmisionAuthService::pruebaAuthTesting). NUNCA imprime usuario,
 * contraseña ni token.
 */
class DteAuthTestCommand extends Command
{
    protected $signature = 'dte:auth-test {--prod : Login-only contra PRODUCCIÓN (candado DTE_AUTH_TEST_PROD_ENABLED) para diagnosticar si la credencial es de producción; no transmite ni cachea token}';

    protected $description = 'Prueba el login/token contra pruebas (o producción con --prod), sin transmitir ningún DTE';

    public function handle(DteTransmisionAuthService $auth): int
    {
        if ($this->option('prod')) {
            return $this->probarProduccion($auth);
        }

        $r = $auth->pruebaAuthTesting();

        $this->line('Prueba de autenticación (solo testing) — el token NUNCA se muestra.');
        $this->newLine();
        $this->line('  ambiente               : '.$r['ambiente']);
        $this->line('  URL auth               : '.$r['url']);
        $this->line('  usuario configurado    : '.($r['usuario_configurado'] ? 'sí' : 'no'));
        $this->line('  password configurado   : '.($r['password_configurado'] ? 'sí' : 'no'));
        $this->line('  token obtenido         : '.($r['token_obtenido'] ? 'sí' : 'no'));
        $this->line('  token cacheado         : '.($r['token_cacheado'] ? 'sí' : 'no'));
        $this->newLine();

        if ($r['bloqueado']) {
            $this->warn('Auth test bloqueado: '.$r['razon']);
            $this->warn('*** NO SE AUTENTICÓ REAL / NO SE TRANSMITIÓ NINGÚN DTE / TOKEN NO MOSTRADO ***');

            return self::FAILURE;
        }

        $this->info('Token obtenido correctamente (no se muestra por seguridad). Vigencia ~'.$auth->vigenciaHoras().' h.');
        $this->warn('*** TOKEN NO MOSTRADO / NO SE TRANSMITIÓ NINGÚN DTE ***');

        return self::SUCCESS;
    }

    /**
     * Login-only contra PRODUCCIÓN (api.dtes.mh.gob.sv), SOLO para diagnosticar si la
     * credencial pertenece a producción. No transmite, no cachea token, no lo muestra.
     */
    private function probarProduccion(DteTransmisionAuthService $auth): int
    {
        $r = $auth->pruebaAuthProduccion();

        $this->line('Prueba de login contra PRODUCCIÓN (solo diagnóstico) — el token NUNCA se muestra ni se cachea.');
        $this->newLine();
        $this->line('  URL auth (prod)        : '.$r['url']);
        $this->line('  usuario configurado    : '.($r['usuario_configurado'] ? 'sí' : 'no'));
        $this->line('  password configurado   : '.($r['password_configurado'] ? 'sí' : 'no'));
        $this->newLine();

        if ($r['bloqueado']) {
            $this->warn('Login-prod bloqueado: '.$r['razon']);
            $this->warn('*** NO SE AUTENTICÓ / NO SE TRANSMITIÓ NINGÚN DTE ***');

            return self::FAILURE;
        }

        $this->line('  HTTP status            : '.($r['http_status'] ?? '—'));
        $this->line('  mensaje del MH         : '.($r['mensaje_mh'] ?? '—'));
        $this->line('  login aceptado         : '.($r['login_aceptado'] ? 'SÍ' : 'no'));
        $this->newLine();

        if ($r['login_aceptado']) {
            $this->info('La credencial es VÁLIDA en PRODUCCIÓN (login OK). El token se DESCARTÓ (no se cachea).');
            $this->warn('=> Si en testing falla y en producción funciona, la credencial es de PRODUCCIÓN, no de pruebas.');
        } else {
            $this->warn('La credencial tampoco fue aceptada en producción (revisar usuario/contraseña/formato).');
        }
        $this->warn('*** LOGIN-ONLY / TOKEN NO MOSTRADO NI CACHEADO / NO SE TRANSMITIÓ NINGÚN DTE / CANDADOS DE TRANSMISIÓN INTACTOS ***');

        return self::SUCCESS;
    }
}
