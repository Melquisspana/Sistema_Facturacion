<?php

namespace App\Console\Commands;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\OrigenConciliacionPpq;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\PpqConciliacion;
use App\Models\PpqItem;
use App\Services\Cobros\AltaCobrosService;
use App\Services\Cobros\AplicadorPagosTxt;
use App\Services\Ppq\ArchivoConciliacion;
use App\Services\Ppq\ConciliacionTxtParser;
use App\Support\IdentidadPpq;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Completa el seguimiento con la historia que ya está en los lotes PPQ:
 *
 *  1. aplica en Cobros los TXT de pago guardados por las conciliaciones PPQ (misma
 *     evidencia, idempotente por huella y línea): lo pagado queda pagado y recibido;
 *  2. lo que viajó en un lote PPQ y sigue sin presentar queda como presentado.
 *
 * Sin --aplicar solo informa.
 */
class CobrosCompletarHistorialCommand extends Command
{
    protected $signature = 'cobros:completar-historial {--cliente= : ID del cliente} {--aplicar : Escribe los cambios}';

    protected $description = 'Marca pagado/presentado lo que ya consta en los lotes PPQ y sus TXT de pago';

    public function handle(AplicadorPagosTxt $aplicador, ConciliacionTxtParser $parser): int
    {
        $cliente = Cliente::find($this->option('cliente'));
        if ($cliente === null) {
            $this->error('Indique --cliente=ID.');

            return self::FAILURE;
        }
        $aplicar = (bool) $this->option('aplicar');

        // 1. TXT de pago de los lotes PPQ, del más viejo al más nuevo, una vez por huella.
        $archivos = PpqConciliacion::query()
            ->where('origen', OrigenConciliacionPpq::Txt->value)
            ->whereNotNull('archivo_path')
            ->orderBy('id')
            ->get()
            ->unique('archivo_hash');

        $disco = Storage::disk((string) config('dte.storage.disk', 'local'));
        foreach ($archivos as $c) {
            $contenido = $disco->exists($c->archivo_path) ? (string) $disco->get($c->archivo_path) : null;
            if ($contenido === null) {
                $this->warn("TXT {$c->archivo_nombre}: la copia no está en disco; se omite.");

                continue;
            }

            if (! $aplicar) {
                $this->line("TXT {$c->archivo_nombre}: se aplicaría.");

                continue;
            }

            try {
                $informe = $aplicador->aplicar(
                    $cliente,
                    $parser->parse($contenido),
                    ArchivoConciliacion::desdeContenido($contenido, (string) ($c->archivo_nombre ?: 'ppq.txt'), $c->archivo_path),
                );
                $this->line(sprintf('TXT %s: %d aplicados, %d sin cambio, %d en revisión, %d no están en el seguimiento.',
                    $c->archivo_nombre, count($informe['aplicados']), count($informe['sin_cambio']),
                    count($informe['en_revision']), count($informe['no_identificados'])));
            } catch (Throwable $e) {
                $this->warn("TXT {$c->archivo_nombre}: no se aplicó ({$e->getMessage()}).");
            }
        }

        // 2. Lo que viajó en un lote PPQ ya se presentó.
        $enLotes = PpqItem::query()->pluck('numero_control')
            ->map(fn ($n) => IdentidadPpq::normalizar($n))->filter()->flip();

        $pendientes = CobroDocumento::deCliente($cliente->id)
            ->whereIn('presentacion_estado', [EstadoPresentacionCobro::SinPresentar->value, EstadoPresentacionCobro::Preparada->value])
            ->get()
            ->filter(fn (CobroDocumento $d) => $enLotes->has(IdentidadPpq::normalizar($d->numero_control)));

        if ($aplicar) {
            foreach ($pendientes as $doc) {
                $doc->forceFill([
                    'presentacion_estado' => EstadoPresentacionCobro::Presentada->value,
                    'revisar_historico' => false,
                ])->save();
            }
        }
        $this->info(($aplicar ? 'Marcados' : 'Se marcarían').' como presentados por estar en un lote PPQ: '.$pendientes->count().'.');

        // 3. Los que la regla de «N días sin eventos» marcó siendo posteriores al inicio del
        //    seguimiento no son históricos: son CCF sin presentar.
        $inicio = config('cobros.inicio_seguimiento');
        if (filled($inicio)) {
            $malMarcados = CobroDocumento::deCliente($cliente->id)
                ->where('revisar_historico', true)
                ->where('revisar_historico_motivo', AltaCobrosService::MOTIVO_SIN_ANTECEDENTE)
                ->whereDate('fecha_emision', '>=', $inicio);
            $cuantos = (clone $malMarcados)->count();
            if ($aplicar) {
                $malMarcados->update(['revisar_historico' => false]);
            }
            $this->info(($aplicar ? 'Quitada' : 'Se quitaría')." la revisión histórica a {$cuantos} emitido(s) desde {$inicio}.");
        }

        if ($aplicar) {
            $this->info('Quedan en revisión histórica: '.CobroDocumento::deCliente($cliente->id)->where('revisar_historico', true)->count().'.');
        }

        return self::SUCCESS;
    }
}
