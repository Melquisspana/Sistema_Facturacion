<?php

namespace App\Console\Commands;

use App\Models\CatalogoMh;
use App\Services\Dte\DteSchemaRepository;
use App\Services\Importacion\ImportadorCatalogosMh;
use Illuminate\Console\Command;

/**
 * Reporte SOLO LECTURA del estado de los insumos oficiales del MH para generar
 * el JSON del DTE: qué JSON Schemas están colocados y qué catálogos hay cargados.
 *
 * No descarga nada, no toca facturación ni cálculos, no genera JSON. Solo informa
 * qué falta para poder avanzar a la generación oficial.
 */
class DteInsumosCommand extends Command
{
    protected $signature = 'dte:insumos';

    protected $description = 'Estado de insumos oficiales del MH (schemas y catálogos) para generar JSON del DTE';

    public function handle(DteSchemaRepository $repo): int
    {
        $this->info('== Insumos oficiales del DTE (Ministerio de Hacienda) ==');

        // --- JSON Schemas ---
        $this->newLine();
        $this->line('JSON Schemas (resources/dte/schemas):');
        $filasSchema = [];
        foreach ($repo->tiposSoportados() as $tipo) {
            $info = $repo->paraTipo($tipo);
            $filasSchema[] = [
                $tipo->value.' '.$tipo->label(),
                $info ? 'PRESENTE' : 'FALTA',
                $info['archivo'] ?? '—',
                'v'.((int) (config('dte.json.versiones')[$tipo->value] ?? 0)),
            ];
        }
        $this->table(['Tipo', 'Estado', 'Archivo', 'Versión esperada'], $filasSchema);
        $faltanSchemas = count($repo->faltantes());

        // --- Excel de catálogos + estado de importación ---
        $this->newLine();
        $excel = app(ImportadorCatalogosMh::class)->archivoPorDefecto();
        $this->line('Excel de catálogos (resources/dte/catalogos): '.($excel ? 'PRESENTE ('.basename($excel).')' : 'FALTA'));
        $secciones = CatalogoMh::distinct()->count('cat');
        $registros = CatalogoMh::count();
        $this->line("Importado a la BD (catalogos_mh): {$secciones} secciones, {$registros} registros.");
        if ($registros === 0) {
            $this->line('Aún sin importar. Corré: php artisan dte:catalogos');
        }

        // --- Catálogos clave (desde catalogos_mh). CAT-018 = Plazo, CAT-031 = INCOTERMS. ---
        $this->newLine();
        $this->line('Catálogos clave:');
        $clave = [
            '001' => 'Ambiente', '002' => 'Tipo de DTE / evento', '003' => 'Modelo de facturación',
            '004' => 'Tipo de transmisión', '005' => 'Tipo de contingencia', '009' => 'Tipo establecimiento',
            '011' => 'Tipo de ítem', '012' => 'Departamento', '013' => 'Municipio', '014' => 'Unidad de medida',
            '015' => 'Tributos', '016' => 'Condición de operación', '017' => 'Forma de pago', '018' => 'Plazo',
            '019' => 'Actividad económica', '020' => 'País', '021' => 'Documentos asociados',
            '022' => 'Tipo de documento de identificación', '031' => 'INCOTERMS',
        ];
        $cat = [];
        foreach ($clave as $num => $nombre) {
            $n = CatalogoMh::where('cat', $num)->count();
            $cat[] = ["CAT-{$num} {$nombre}", $n > 0 ? 'PRESENTE' : 'FALTA', "{$n} registros"];
        }
        $this->table(['Catálogo', 'Estado', 'Detalle'], $cat);

        $faltanCatalogos = collect($cat)->filter(fn ($c) => $c[1] === 'FALTA')->count();

        // --- Resumen ---
        $this->newLine();
        if ($faltanSchemas === 0 && $faltanCatalogos === 0) {
            $this->info('Insumos completos: se puede avanzar a la generación de JSON oficial.');
        } else {
            $this->warn("Faltan insumos: {$faltanSchemas} schema(s) y {$faltanCatalogos} catálogo(s) marcados FALTA.");
            $this->line('No se genera JSON oficial hasta colocar los schemas. Ver docs/dte/INSUMOS_PENDIENTES.md');
        }

        return self::SUCCESS;
    }
}
