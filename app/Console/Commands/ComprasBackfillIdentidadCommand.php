<?php

namespace App\Console\Commands;

use App\Models\DocumentoRecibido;
use App\Services\DocumentosRecibidos\Buzon\IdentidadCorreo;
use Illuminate\Console\Command;

/**
 * Adopta las filas ANTERIORES a la migración de identidad.
 *
 * POR QUÉ ES UN COMANDO Y NO PARTE DE LA MIGRACIÓN. Esas filas guardan el UID crudo de
 * IMAP en `gmail_message_id` y no tienen `identidad`. Rellenarla dentro de la migración
 * sería reinterpretar datos históricos en silencio, durante un despliegue, sin que nadie
 * pueda mirar antes qué va a pasar. Acá el operador corre primero sin `--aplicar`, ve
 * exactamente cuántas filas se tocan, y recién entonces confirma.
 *
 * QUÉ ESCRIBE. Solo `identidad`, y solo donde está en NULL:
 * `legado:<gmail_message_id>`. NO inventa un Message-ID que no tiene —esas filas se
 * leyeron cuando el lector no lo pedía—, no toca `gmail_message_id`, no toca ningún otro
 * campo y no borra nada.
 *
 * NO ES OBLIGATORIO. Sin correr esto el sistema funciona igual: la deduplicación
 * reconoce las filas históricas por `gmail_message_id` mientras su `identidad` esté en
 * NULL. Correrlo solo cierra ese camino de compatibilidad y deja la tabla uniforme.
 */
class ComprasBackfillIdentidadCommand extends Command
{
    protected $signature = 'compras:backfill-identidad
        {--aplicar : Escribe la identidad (por defecto solo muestra lo que haría)}';

    protected $description = 'Marca con identidad `legado:` las compras anteriores a la migración de identidad IMAP';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');

        $pendientes = DocumentoRecibido::query()
            ->whereNull('identidad')
            ->orderBy('id')
            ->get(['id', 'gmail_message_id', 'fecha_correo', 'emisor_nombre']);

        if ($pendientes->isEmpty()) {
            $this->info('No hay filas sin identidad: nada que hacer.');

            return self::SUCCESS;
        }

        $this->info($pendientes->count().' fila(s) sin identidad.');
        $this->table(
            ['ID', 'gmail_message_id (UID viejo)', 'Fecha correo', 'Emisor', 'Identidad que quedaría'],
            $pendientes->take(20)->map(fn (DocumentoRecibido $d) => [
                $d->id,
                $d->gmail_message_id ?? '—',
                $d->fecha_correo?->toDateString() ?? '—',
                mb_substr((string) $d->emisor_nombre, 0, 28),
                $this->identidadDe($d) ?? 'SE OMITE (sin gmail_message_id)',
            ])->all(),
        );
        if ($pendientes->count() > 20) {
            $this->line('… y '.($pendientes->count() - 20).' más.');
        }

        if (! $aplicar) {
            $this->warn('DRY-RUN: no se escribió nada. Corré con --aplicar para guardar.');

            return self::SUCCESS;
        }

        $escritas = 0;
        $omitidas = 0;
        foreach ($pendientes as $doc) {
            $identidad = $this->identidadDe($doc);
            if ($identidad === null) {
                // Sin identidad histórica no hay nada determinista que escribir. Se deja
                // en NULL: inventar una haría que un correo real no se reconociera.
                $omitidas++;

                continue;
            }
            $doc->forceFill(['identidad' => $identidad])->save();
            $escritas++;
        }

        $this->info("Identidad escrita en {$escritas} fila(s).");
        if ($omitidas > 0) {
            $this->warn("{$omitidas} fila(s) sin `gmail_message_id` quedaron sin identidad: no hay dato histórico del que derivarla.");
        }

        return self::SUCCESS;
    }

    private function identidadDe(DocumentoRecibido $doc): ?string
    {
        return filled($doc->gmail_message_id)
            ? IdentidadCorreo::PREFIJO_LEGADO.$doc->gmail_message_id
            : null;
    }
}
