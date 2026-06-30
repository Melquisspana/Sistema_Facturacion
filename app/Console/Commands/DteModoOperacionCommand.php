<?php

namespace App\Console\Commands;

use App\Services\Dte\DteTransmisionService;
use Illuminate\Console\Command;

/**
 * Muestra el modo de operación del sistema NUEVO respecto del sistema ACTUAL en uso
 * (paralelo / respaldo / principal) y el estado de los candados. SOLO LECTURA: no
 * transmite, no hace HTTP, no muestra secretos.
 */
class DteModoOperacionCommand extends Command
{
    protected $signature = 'dte:modo-operacion';

    protected $description = 'Muestra el modo de operación (paralelo/respaldo/principal) y los candados';

    public function handle(DteTransmisionService $transmision): int
    {
        $c = $transmision->evaluarCandados();
        $f = $c['flags'];
        $modo = $f['modo_operacion'];

        $this->line('Modo de operación del sistema DTE — SOLO DIAGNÓSTICO (sin secretos).');
        $this->newLine();
        $this->estado('Modo de operación', $modo);
        $this->estado('Sistema actual en uso', $f['sistema_actual_activo'] ? 'sí' : 'no');
        $this->estado('Firma habilitada', config('dte.firma.enabled') ? 'sí' : 'no');
        $this->estado('Transmisión habilitada', $f['enabled'] ? 'sí' : 'no');
        $this->estado('Dry-run activo', $f['dry_run'] ? 'sí' : 'no');
        $this->estado('Confirmación real', $f['real_confirmation'] ? 'sí' : 'no');
        $this->estado('Producción permitida', $f['es_produccion'] ? ($f['allow_production'] ? 'sí' : 'no') : 'n/a (no es producción)');

        $resultado = match ($modo) {
            'paralelo' => 'PARALELO SEGURO',
            'respaldo' => $c['bloqueado'] ? 'RESPALDO BLOQUEADO' : 'RESPALDO LISTO',
            'principal' => $c['bloqueado'] ? 'PRINCIPAL BLOQUEADO' : 'PRINCIPAL LISTO',
            default => strtoupper($modo).' BLOQUEADO',
        };

        $this->newLine();
        if (str_contains($resultado, 'SEGURO') || str_contains($resultado, 'BLOQUEADO')) {
            $this->info('Resultado: '.$resultado);
        } else {
            $this->warn('Resultado: '.$resultado);
        }
        if ($c['razones'] !== []) {
            $this->newLine();
            $this->line('Candados activos:');
            foreach ($c['razones'] as $r) {
                $this->line('  - '.$r);
            }
        }

        $this->newLine();
        $this->warn('*** SOLO DIAGNÓSTICO — NO SE TRANSMITIÓ NADA / NO SE MOSTRARON SECRETOS ***');

        return self::SUCCESS;
    }

    private function estado(string $etiqueta, string $valor): void
    {
        $this->line('  '.str_pad($etiqueta, 26).' : '.$valor);
    }
}
