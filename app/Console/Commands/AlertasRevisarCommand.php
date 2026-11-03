<?php

namespace App\Console\Commands;

use App\Services\Alertas\Avisador;
use Illuminate\Console\Command;

/**
 * Revisa lo que puede estar fallando y manda los avisos que toquen (issue #163).
 * Corre cada 5 minutos desde el planificador; ver {@see Avisador} para las reglas.
 *
 * Siempre termina bien, aunque un correo no salga: si devolviera error, el
 * planificador lo anotaría como tarea fallida y el siguiente aviso sería sobre el
 * propio aviso.
 */
class AlertasRevisarCommand extends Command
{
    protected $signature = 'alertas:revisar';

    protected $description = 'Revisa fallas del sistema y manda los avisos por correo (ALERTAS_ENABLED)';

    public function handle(Avisador $avisador): int
    {
        if (! config('alertas.enabled')) {
            $this->info('Los avisos están apagados (ALERTAS_ENABLED=false). No se revisa nada.');

            return self::SUCCESS;
        }

        if ($avisador->destinatarios() === []) {
            $this->warn('Falta ALERTAS_CORREO en el .env: no hay a quién avisar. No se revisa nada.');

            return self::SUCCESS;
        }

        $hechos = $avisador->revisar();

        if ($hechos === []) {
            $this->info('Sin avisos nuevos.');
        }

        foreach ($hechos as $tipo => $accion) {
            $this->line($tipo.': '.$accion);
        }

        return self::SUCCESS;
    }
}
