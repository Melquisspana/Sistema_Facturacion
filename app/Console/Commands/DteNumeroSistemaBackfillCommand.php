<?php

namespace App\Console\Commands;

use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Models\Secuencia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Asigna numeración de sistema a documentos de PRODUCCIÓN ya emitidos, en el ORDEN
 * EXACTO de la lista de ids que se le pasa. No adivina nada: la lista y su orden los
 * decide una persona a partir de `dte:numero-sistema-candidatos`.
 *
 * SEGURIDAD:
 *  - DRY-RUN por defecto: sin --aplicar no escribe nada.
 *  - Aplicar exige además la frase exacta "NUMERAR SISTEMA {cantidad}".
 *  - Rechaza documentos que no sean de producción, borradores, ids repetidos, ids
 *    inexistentes y documentos que YA tengan número (nunca renumera).
 *  - Solo escribe `dtes.numero_sistema` y el contador de la secuencia. NO toca id,
 *    numero_control, numero_interno, codigo_generacion ni los correlativos fiscales.
 *  - Exige que la secuencia esté en 0 (backfill inicial), salvo --continuar.
 *
 * Tras numerar 6 documentos como 1..6, la secuencia queda en 6 y el próximo documento
 * generado toma el 7.
 */
class DteNumeroSistemaBackfillCommand extends Command
{
    protected $signature = 'dte:numero-sistema-backfill
        {--ids= : Ids de dtes EN EL ORDEN comercial deseado, separados por coma (p. ej. 120,131,140)}
        {--aplicar : Aplica de verdad (además exige la frase exacta)}
        {--frase= : Frase exacta de confirmación: NUMERAR SISTEMA {cantidad}}
        {--continuar : Permite correr con la secuencia ya iniciada (por defecto exige que esté en 0)}';

    protected $description = 'Asigna numero_sistema a documentos de producción en el orden indicado (dry-run por defecto)';

    public function handle(): int
    {
        $ids = collect(explode(',', (string) $this->option('ids')))
            ->map(fn ($v) => trim($v))
            ->filter(fn ($v) => $v !== '')
            ->map(fn ($v) => (int) $v)
            ->values();

        if ($ids->isEmpty()) {
            $this->error('Indicá --ids con la lista de ids EN ORDEN (p. ej. --ids=120,131,140). Abortado.');

            return self::FAILURE;
        }

        if ($ids->duplicates()->isNotEmpty()) {
            $this->error('La lista tiene ids repetidos: '.$ids->duplicates()->implode(', ').'. Abortado.');

            return self::FAILURE;
        }

        $ultimo = Secuencia::ultimo(Secuencia::NUMERO_SISTEMA);
        if ($ultimo !== 0 && ! $this->option('continuar')) {
            $this->error('La secuencia ya entregó números (último = '.$ultimo.'). '
                .'Si de verdad querés continuarla, pasá --continuar. Abortado.');

            return self::FAILURE;
        }

        // --- Verificación documento por documento (sin escribir) ---
        $dtes = Dte::query()->whereIn('id', $ids)->get()->keyBy('id');
        $problemas = [];

        foreach ($ids as $id) {
            $dte = $dtes->get($id);

            if (! $dte) {
                $problemas[] = "id {$id}: no existe.";

                continue;
            }
            if (! $dte->ambiente->esProduccion()) {
                $problemas[] = "id {$id}: es ambiente {$dte->ambiente->value} (pruebas), no producción.";
            }
            if ($dte->estado === EstadoDte::Borrador) {
                $problemas[] = "id {$id}: está en borrador (un borrador no consume número).";
            }
            if ($dte->numero_sistema !== null) {
                $problemas[] = "id {$id}: ya tiene numero_sistema = {$dte->numero_sistema} (nunca se renumera).";
            }
        }

        if ($problemas) {
            $this->error('No se aplicó nada. Problemas encontrados:');
            foreach ($problemas as $p) {
                $this->line('  · '.$p);
            }

            return self::FAILURE;
        }

        // --- Plan (esto es todo lo que hace el dry-run) ---
        $numero = $ultimo;
        $plan = [];
        foreach ($ids as $id) {
            $numero++;
            $dte = $dtes->get($id);
            $plan[] = [
                $id,
                $dte->tipo_dte->value,
                $dte->estado->value,
                $dte->fecha_emision?->format('Y-m-d') ?? '—',
                $dte->numero_control ?? $dte->numero_interno ?? '—',
                $dte->cliente?->nombre ?? 'Consumidor final',
                'N.º '.$numero,
            ];
        }

        $this->table(['id', 'tipo', 'estado', 'fecha', 'número control', 'cliente', 'asignaría'], $plan);
        $this->line('La secuencia quedaría en '.$numero.' → el próximo documento generado tomaría el '.($numero + 1).'.');
        $this->newLine();

        if (! $this->option('aplicar')) {
            $this->warn('DRY-RUN: no se escribió nada. Para aplicar:');
            $this->line('  php artisan dte:numero-sistema-backfill --ids='.$ids->implode(',')
                .' --aplicar --frase="NUMERAR SISTEMA '.$ids->count().'"');

            return self::SUCCESS;
        }

        $fraseEsperada = 'NUMERAR SISTEMA '.$ids->count();
        if ((string) $this->option('frase') !== $fraseEsperada) {
            $this->error('Frase de confirmación incorrecta. Se esperaba exactamente: '.$fraseEsperada.'. Abortado.');

            return self::FAILURE;
        }

        // --- Aplicación real, atómica y bajo el mismo bloqueo de la secuencia ---
        DB::transaction(function () use ($ids) {
            foreach ($ids as $id) {
                $dte = Dte::query()->whereKey($id)->lockForUpdate()->firstOrFail();

                if ($dte->numero_sistema !== null) {
                    continue; // carrera improbable: alguien lo numeró en el intertanto
                }

                // Escritura directa: `numero_sistema` no está en $fillable ni en la
                // whitelist del DteObserver justamente para que nadie lo cambie por las
                // vías normales; acá se usa el query builder para no disparar el observer
                // de inmutabilidad sobre un documento ya emitido.
                Dte::query()->whereKey($dte->id)->update([
                    'numero_sistema' => Secuencia::siguiente(Secuencia::NUMERO_SISTEMA),
                ]);
            }
        });

        $this->info('Listo. '.$ids->count().' documento(s) numerados. Secuencia en '
            .Secuencia::ultimo(Secuencia::NUMERO_SISTEMA)
            .' → el próximo documento generado tomará el '.(Secuencia::ultimo(Secuencia::NUMERO_SISTEMA) + 1).'.');

        return self::SUCCESS;
    }
}
