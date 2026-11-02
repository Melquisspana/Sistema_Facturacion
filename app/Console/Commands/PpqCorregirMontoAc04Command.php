<?php

namespace App\Console\Commands;

use App\Models\PpqAlbaran;
use App\Services\Ppq\AlbaranParser;
use App\Support\Dinero;
use App\Support\NumeroAlbaran;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PpqCorregirMontoAc04Command extends Command
{
    protected $signature = 'ppq:corregir-monto-ac04 {--aplicar : Guarda el gravado leído del PDF; sin esta opción solo informa}';

    protected $description = 'Corrige los montos AC04 antiguos con IVA para guardar solo el gravado (en seco por defecto)';

    public function handle(AlbaranParser $parser): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $disk = Storage::disk((string) config('dte.storage.disk', 'local'));
        $cambios = 0;
        $ilegibles = 0;
        $this->info($aplicar ? 'Aplicando correcciones AC04.' : 'En seco: no se escribe ningún monto.');
        PpqAlbaran::where('tipo_codigo', 'AC04')->whereNotNull('archivo_path')
            ->chunkById(200, function ($notas) use ($parser, $disk, $aplicar, &$cambios, &$ilegibles) {
                foreach ($notas as $nota) {
                    try {
                        $datos = $parser->desdePdf($disk->get($nota->archivo_path));
                    } catch (\Throwable) {
                        $datos = [];
                    }
                    if (($datos['monto'] ?? null) === null || filled($datos['debug']['error'] ?? null)
                        || NumeroAlbaran::desde($datos['numero'] ?? '')?->canonico !== NumeroAlbaran::desde($nota->numero_albaran)?->canonico) {
                        $this->warn($nota->numero_albaran.': PDF ilegible, ausente o sin identidad coincidente; sin cambios.');
                        $ilegibles++;

                        continue;
                    }
                    $nuevo = Dinero::redondear($datos['monto']);
                    if ($nota->monto_albaran !== null && Dinero::comparar($nota->monto_albaran, $nuevo) === 0) {
                        continue;
                    }
                    $this->line($nota->numero_albaran.': '.($nota->monto_albaran ?? 'null').' → '.$nuevo);
                    if ($aplicar && PpqAlbaran::whereKey($nota->id)->where('monto_albaran', $nota->monto_albaran)
                        ->update(['monto_albaran' => $nuevo]) !== 1) {
                        $this->warn('El registro cambió durante la lectura; no se sobrescribió.');

                        continue;
                    }
                    $cambios++;
                }
            });
        $this->info(($aplicar ? 'Corregidas: ' : 'Por corregir: ').$cambios.'. No legibles: '.$ilegibles.'.');

        return self::SUCCESS;
    }
}
