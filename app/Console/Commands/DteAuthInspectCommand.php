<?php

namespace App\Console\Commands;

use App\Services\Dte\DteTransmisionAuthService;
use Illuminate\Console\Command;

/**
 * Inspección SEGURA del request de autenticación: muestra la ESTRUCTURA del POST
 * (URL, ambiente, content-type, user-agent, nombres de campos) y métricas
 * ENMASCARADAS del user/pwd (longitudes, si el user tiene guiones, si es solo
 * dígitos), pero NUNCA los valores. No hace HTTP, no autentica, no transmite.
 */
class DteAuthInspectCommand extends Command
{
    protected $signature = 'dte:auth-inspect';

    protected $description = 'Muestra la estructura del request de auth (sin valores reales, sin HTTP, sin secretos)';

    public function handle(DteTransmisionAuthService $auth): int
    {
        $i = $auth->inspeccionarRequest();

        $this->line('Inspección del request de autenticación — SOLO ESTRUCTURA, sin valores reales.');
        $this->newLine();
        $this->line('  Método            : '.$i['metodo']);
        $this->line('  Ambiente          : '.$i['ambiente']);
        $this->line('  URL               : '.$i['url']);
        $this->line('  Content-Type      : '.$i['content_type']);
        $this->line('  User-Agent        : '.$i['user_agent']);
        $this->line('  Campos del body   : '.implode(', ', $i['campos']).'  (form-urlencoded)');
        $this->newLine();
        $this->line('  user configurado  : '.($i['user_configurado'] ? 'sí' : 'NO'));
        $this->line('  user longitud     : '.$i['user_longitud'].' caracteres');
        $this->line('  user tiene guiones: '.($i['user_tiene_guiones'] ? 'SÍ' : 'no'));
        $this->line('  user solo dígitos : '.($i['user_solo_digitos'] ? 'sí' : 'no'));
        $this->line('  user cant. dígitos: '.$i['user_cant_digitos'].'  (un NIT salvadoreño tiene 14)');
        $this->line('  password config.  : '.($i['password_configurada'] ? 'sí' : 'NO'));
        $this->line('  password longitud : '.$i['password_longitud']);
        $this->line('  token manual      : '.($i['token_manual_configurado'] ? 'configurado (oculto)' : 'no configurado'));
        $this->newLine();

        if ($i['user_tiene_guiones']) {
            $this->warn('Aviso: el user tiene guiones. El MH espera el NIT en 14 dígitos SIN guiones.');
        }
        $this->warn('*** SOLO ESTRUCTURA — NO se hizo HTTP / NO se mostraron valores ni secretos ***');

        return self::SUCCESS;
    }
}
