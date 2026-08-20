<?php

namespace App\Console\Commands;

use App\Services\Dte\DteTransmisionAuthService;
use Illuminate\Console\Command;

/**
 * Diagnóstico de SOLO LECTURA de la autenticación de transmisión. No autentica,
 * no hace HTTP, no toca BD y NUNCA muestra usuario, contraseña ni token.
 */
class DteAuthCheckCommand extends Command
{
    protected $signature = 'dte:auth-check';

    protected $description = 'Revisa la configuración de autenticación de transmisión (no autentica nada)';

    public function handle(DteTransmisionAuthService $auth): int
    {
        $d = $auth->diagnostico();

        $this->line('Diagnóstico de autenticación de transmisión — NO se muestran secretos.');
        $this->newLine();
        $this->estado('Ambiente', $d['ambiente'], true);
        $this->estado('URL de autenticación', $d['url'], true);
        $this->estado('Prueba de auth real (DTE_AUTH_TEST_REAL_ENABLED)', $d['auth_test_real'] ? 'ACTIVA (solo testing)' : 'BLOQUEADA', true);
        $this->estado('Transmisión', $d['habilitada'] ? 'HABILITADA' : 'BLOQUEADA (enabled=false)', ! $d['habilitada'] ? true : true);
        $this->estado('Usuario', $d['usuario_configurado'] ? 'configurado (oculto)' : 'no configurado', $d['usuario_configurado']);
        $this->estado('Password', $d['password_configurado'] ? 'configurada (oculta)' : 'no configurada', $d['password_configurado']);
        // QUÉ variables alimentan el login (no sus valores). 'legacy' significa que
        // producción está cayendo de vuelta a DTE_TRANSMISION_* porque DTE_PROD_* no
        // están definidas: funciona, pero conviene dejar de depender de ese respaldo.
        $this->estado('Fuente de credenciales', $d['fuente_credenciales_detalle'], $d['fuente_credenciales'] !== 'legacy');
        if ($d['fuente_credenciales'] === 'legacy') {
            $this->line('       → Definí DTE_PROD_USER y DTE_PROD_PASSWORD para no depender del respaldo legacy.');
        }
        $this->estado('Token manual (DTE_TRANSMISION_TOKEN)', $d['token_manual_configurado'] ? 'configurado (oculto)' : 'no configurado', true);
        $this->estado('Token en cache', $d['token_cacheado'] ? 'sí (oculto)' : 'no', true);
        $this->estado('Vigencia estimada del token', $d['vigencia_horas'].' horas', true);

        $this->newLine();
        if (! $d['habilitada']) {
            $this->warn('*** NO AUTENTICA / SOLO DIAGNÓSTICO — transmisión deshabilitada ***');
        } else {
            $this->warn('*** SOLO DIAGNÓSTICO — este comando no autentica ni transmite ***');
        }

        return self::SUCCESS;
    }

    private function estado(string $etiqueta, string $valor, bool $ok): void
    {
        $icono = $ok ? '<info>OK </info>' : '<error>!! </error>';
        $this->line('  '.$icono.str_pad($etiqueta, 38).' : '.$valor);
    }
}
