<?php

namespace App\Console\Commands;

use App\Models\CatalogoMh;
use App\Models\Distrito;
use App\Models\Municipio;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Puebla `municipios.codigo` con el código OFICIAL CAT-013 del MH, SIN inventar datos.
 *
 * Contexto (reforma territorial 2024): el catálogo CAT-013 del MH ya no son los ~262
 * municipios antiguos sino los 44 municipios nuevos (ej. "LA PAZ OESTE" = 23). Lo que
 * el sistema antiguo llama "municipio" (ej. Olocuilta) hoy es un DISTRITO dentro de un
 * municipio 2024. Por eso el código que el JSON al MH debe enviar para un municipio
 * antiguo es el código CAT-013 del municipio 2024 al que pertenece.
 *
 * Cadena 100% oficial (cero invención):
 *   municipio antiguo  --(mismo nombre+departamento)-->  distrito
 *   distrito.municipio (nombre 2024)  --(catalogos_mh CAT-013)-->  codigo CAT-013
 *
 * Fuentes: `catalogos_mh` cat='013' (importado del Excel oficial del MH) y la tabla
 * `distritos` (dataset 2024). Idempotente; con --dry-run no escribe.
 */
class VincularCodigoMunicipioMh extends Command
{
    protected $signature = 'municipios:codigos-mh
        {--dry-run : Solo muestra cuántos se poblarían, sin escribir}
        {--sobrescribir : Reescribe el codigo aunque ya tenga valor}';

    protected $description = 'Puebla municipios.codigo con el CAT-013 oficial (vía distrito → municipio 2024 → catalogos_mh).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $sobrescribir = (bool) $this->option('sobrescribir');

        // Índice CAT-013 oficial: nombre normalizado del municipio 2024 => codigo.
        $cat013 = [];
        foreach (CatalogoMh::where('cat', '013')->get(['codigo', 'valor']) as $c) {
            $cat013[$this->norm($c->valor)] = $c->codigo;
        }

        if (empty($cat013)) {
            $this->error('No hay CAT-013 en catalogos_mh. Corré primero la importación oficial '
                .'(php artisan mh:importar-catalogos / ImportarCatalogosMhCommand) y reintentá.');

            return self::FAILURE;
        }
        $this->line('CAT-013 oficial disponible: '.count($cat013).' municipios 2024.');

        $poblados = 0;
        $yaTenian = 0;
        $sinDistrito = 0;
        $sinCat013 = 0;
        $sinCambios = 0;

        foreach (Municipio::with('departamento:id,nombre')->get() as $m) {
            // Distrito con el mismo nombre y departamento (el municipio antiguo = distrito hoy).
            $distrito = Distrito::where('departamento_id', $m->departamento_id)
                ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($m->nombre)])
                ->first(['municipio']);

            if (! $distrito) {
                $sinDistrito++;
                $this->warn("  · sin distrito: {$m->nombre} ({$m->departamento?->nombre})");

                continue;
            }

            $codigo = $cat013[$this->norm($distrito->municipio)] ?? null;
            if ($codigo === null) {
                $sinCat013++;
                $this->warn("  · sin CAT-013: {$m->nombre} → municipio 2024 '{$distrito->municipio}'");

                continue;
            }

            if (filled($m->codigo) && ! $sobrescribir) {
                $yaTenian++;

                continue;
            }
            if ((string) $m->codigo === (string) $codigo) {
                $sinCambios++;

                continue;
            }

            if (! $dry) {
                $m->forceFill(['codigo' => $codigo])->saveQuietly();
            }
            $poblados++;
        }

        $this->newLine();
        $this->table(
            ['Poblados', 'Ya tenían (intactos)', 'Sin cambios', 'Sin distrito', 'Distrito sin CAT-013'],
            [[$poblados, $yaTenian, $sinCambios, $sinDistrito, $sinCat013]]
        );
        $this->info(($dry ? '[dry-run] ' : '')."codigo CAT-013 poblado en {$poblados} municipio(s).");

        return self::SUCCESS;
    }

    /** Normaliza para comparar nombres: sin acentos, minúsculas, espacios colapsados. */
    private function norm(string $texto): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim(Str::ascii($texto))));
    }
}
