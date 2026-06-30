<?php

namespace App\Console\Commands;

use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Services\Dte\DteJsonService;
use Illuminate\Console\Command;

/**
 * Backfill SEGURO del JSON oficial preliminar para documentos que quedaron en estado
 * GENERADO sin json_generado_path (creados antes de que la generación produjera el JSON
 * automáticamente). Por cada documento asigna numeración oficial (si falta), serializa,
 * valida contra el schema del MH y guarda el archivo + json_generado_path.
 *
 * NO cambia totales ni datos fiscales, NO firma, NO transmite, NO contacta a Hacienda.
 * Solo procesa documentos SIN JSON (nunca regenera los que ya lo tienen).
 */
class DteBackfillJsonCommand extends Command
{
    protected $signature = 'dte:backfill-json
        {--ids= : Limita a IDs concretos, separados por coma (ej. --ids=71 o --ids=71,72). Vacío = todos los pendientes}
        {--dry-run : Solo lista los documentos afectados, sin generar nada}';

    protected $description = 'Genera el JSON oficial faltante de documentos en estado generado sin json_generado_path';

    public function handle(DteJsonService $servicio): int
    {
        $ids = $this->idsOpcion();
        if ($ids === false) {
            return self::FAILURE;
        }

        $pendientes = Dte::query()
            ->where('estado', EstadoDte::Generado->value)
            ->whereNull('json_generado_path')
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->get()
            ->filter(fn (Dte $dte) => $servicio->soporta($dte->tipo_dte));

        // Con --ids, avisar de los que se pidieron pero NO califican (ya tienen JSON,
        // no están generados, o tipo sin serializador). No se tocan, solo se reportan.
        if ($ids !== []) {
            $omitidos = array_diff($ids, $pendientes->pluck('id')->all());
            foreach ($omitidos as $idOmitido) {
                $this->warn('  · #'.$idOmitido.' omitido: no está en estado generado SIN JSON de un tipo soportado (no se modifica).');
            }
        }

        if ($pendientes->isEmpty()) {
            $this->info('No hay documentos generados sin JSON para procesar. Nada que hacer.');

            return self::SUCCESS;
        }

        $this->info($pendientes->count().' documento(s) generado(s) sin JSON oficial.');

        if ($this->option('dry-run')) {
            foreach ($pendientes as $dte) {
                $this->line('  - #'.$dte->id.' '.$dte->tipo_dte->label().' ('.($dte->numero_interno ?: 's/n').')');
            }
            $this->newLine();
            $this->warn('DRY-RUN: no se generó nada. Quitá --dry-run para aplicar.');

            return self::SUCCESS;
        }

        $ok = 0;
        $fallos = 0;

        foreach ($pendientes as $dte) {
            try {
                $r = $servicio->generar($dte);
                $ok++;
                $this->line('  ✓ #'.$dte->id.'  '.$r['numeroControl']);
            } catch (\Throwable $e) {
                $fallos++;
                $this->error('  ✗ #'.$dte->id.'  '.mb_substr($e->getMessage(), 0, 160));
            }
        }

        $this->newLine();
        $this->info("Backfill terminado: {$ok} generado(s), {$fallos} con error.");
        $this->warn('*** SIN FIRMA / SIN TRANSMISIÓN / NO ENVIADO A HACIENDA ***');

        return $fallos === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Parsea --ids=. Devuelve una lista de IDs (vacía = sin filtro, procesa todos los
     * pendientes), o false si el valor es inválido (para abortar sin tocar nada).
     *
     * @return array<int, int>|false
     */
    private function idsOpcion(): array|false
    {
        $raw = (string) ($this->option('ids') ?? '');
        if (trim($raw) === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $parte) {
            $parte = trim($parte);
            if ($parte === '') {
                continue;
            }
            if (! ctype_digit($parte)) {
                $this->error('--ids inválido: "'.$parte.'" no es un ID numérico.');

                return false;
            }
            $ids[] = (int) $parte;
        }

        return array_values(array_unique($ids));
    }
}
