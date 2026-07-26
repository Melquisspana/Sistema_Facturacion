<?php

namespace App\Console\Commands;

use App\Models\Dte;
use App\Models\Secuencia;
use Illuminate\Console\Command;

/**
 * SOLO LECTURA: lista los documentos de PRODUCCIÓN (ambiente 01) candidatos a recibir
 * numeración de sistema, para que una persona decida cuáles son y en qué orden.
 *
 * NO escribe nada: no asigna números, no toca la secuencia, no modifica ningún DTE.
 * El backfill real es otro comando (`dte:numero-sistema-backfill`) y exige la lista
 * explícita de ids.
 *
 * Deliberadamente NO adivina el orden comercial: muestra fecha de emisión, alta y número
 * de control para que la decisión sea humana y verificable.
 */
class DteNumeroSistemaCandidatosCommand extends Command
{
    protected $signature = 'dte:numero-sistema-candidatos
        {--incluir-borradores : Incluye también los borradores (por defecto se omiten: no consumen número)}
        {--todos : Incluye los que YA tienen numero_sistema asignado}';

    protected $description
        = 'Lista (solo lectura) los DTE de producción candidatos a numeración de sistema';

    public function handle(): int
    {
        $q = Dte::query()
            ->produccion()
            ->with('cliente:id,nombre')
            ->orderBy('fecha_emision')
            ->orderBy('created_at')
            ->orderBy('id');

        if (! $this->option('todos')) {
            $q->whereNull('numero_sistema');
        }
        if (! $this->option('incluir-borradores')) {
            $q->where('estado', '!=', \App\Enums\EstadoDte::Borrador->value);
        }

        $dtes = $q->get();

        $this->info('SOLO LECTURA — no se modificó ningún documento ni la secuencia.');
        $this->line('Secuencia numero_sistema: último entregado = '.Secuencia::ultimo(Secuencia::NUMERO_SISTEMA)
            .' (el próximo documento tomaría el '.(Secuencia::ultimo(Secuencia::NUMERO_SISTEMA) + 1).')');
        $this->newLine();

        if ($dtes->isEmpty()) {
            $this->warn('No hay documentos de producción que cumplan el filtro.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'tipo', 'estado', 'ambiente', 'fecha emisión', 'creado', 'número control', 'cliente', 'archivado', 'n.º sistema'],
            $dtes->map(fn (Dte $d) => [
                $d->id,
                $d->tipo_dte->value.' '.$d->tipo_dte->label(),
                $d->estado->value,
                $d->ambiente->value.' '.$d->ambiente->label(),
                $d->fecha_emision?->format('Y-m-d') ?? '—',
                $d->created_at?->format('Y-m-d H:i') ?? '—',
                $d->numero_control ?? $d->numero_interno ?? '—',
                $d->cliente?->nombre ?? 'Consumidor final',
                $d->archivado ? 'SÍ' : 'no',
                $d->numero_sistema ?? '—',
            ])->all()
        );

        $this->newLine();
        $this->line('Total: '.$dtes->count().' documento(s).');
        $this->line('Para asignar, confirmá los ids EN ORDEN y corré (dry-run por defecto):');
        $this->line('  php artisan dte:numero-sistema-backfill --ids=<id1,id2,...>');

        return self::SUCCESS;
    }
}
