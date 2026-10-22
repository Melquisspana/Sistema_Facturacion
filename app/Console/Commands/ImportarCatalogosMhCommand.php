<?php

namespace App\Console\Commands;

use App\Models\ActividadEconomica;
use App\Models\CatalogoMh;
use App\Models\Departamento;
use App\Models\Pais;
use App\Models\UnidadMedida;
use App\Services\Importacion\ImportadorCatalogosMh;
use App\Support\Dte\CatalogoOficialMh;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Importa los catálogos oficiales del MH desde el Excel oficial a catalogos_mh.
 * Idempotente. No genera JSON, no firma, no transmite.
 */
class ImportarCatalogosMhCommand extends Command
{
    protected $signature = 'dte:catalogos
        {--archivo= : Ruta del .xlsx (por defecto, catálogo activo verificado por SHA-256)}
        {--verificar : Compara sin escribir. Municipios y distritos se auditan con ubicacion:auditar}';

    protected $description = 'Importa los catálogos oficiales del MH (CAT-001..033) desde el Excel oficial';

    public function handle(ImportadorCatalogosMh $importador): int
    {
        $archivo = $this->option('archivo') ?: null;

        try {
            // Sin --archivo: el catálogo ACTIVO del registro, verificado por SHA-256 antes
            // de tocar la base. Con --archivo: ese archivo tal cual, para inspeccionar una
            // revisión todavía no registrada.
            if ($archivo === null) {
                $this->info('Catálogo activo: '.CatalogoOficialMh::version());
                $this->line('  archivo: '.basename(CatalogoOficialMh::ruta()));
                $this->line('  sha-256: '.CatalogoOficialMh::sha256Esperado().' (verificado)');
            } else {
                $this->warn('Archivo AD-HOC (sin verificación de hash): '.basename($archivo));
            }

            if ($this->option('verificar')) {
                return $this->verificar($importador->leer($archivo)['filas']);
            }

            $r = $importador->importar($archivo);
        } catch (RuntimeException $e) {
            $this->error('Error: '.$e->getMessage());

            return self::FAILURE;
        }

        $filas = [];
        ksort($r['cats']);
        foreach ($r['cats'] as $cat => $n) {
            $filas[] = ['CAT-'.$cat, $r['nombres'][$cat] ?? '', $n];
        }
        $this->table(['Catálogo', 'Nombre', 'Registros'], $filas);

        $this->info("Listo: {$r['secciones']} secciones, {$r['total']} registros importados desde {$r['archivo']}.");

        return self::SUCCESS;
    }

    private function verificar(array $filas): int
    {
        // Algunos CAT repiten código por departamento: conservar todas sus filas.
        $oficiales = [];
        foreach ($filas as $fila) {
            $oficiales[$fila['cat']][$fila['codigo']][] = $fila['valor'];
        }

        $diferencias = [];
        $importados = CatalogoMh::query()->get(['cat', 'codigo', 'valor']);
        $pendientes = $oficiales;
        $sobrantes = [];
        foreach ($importados as $fila) {
            $valores = $pendientes[$fila->cat][$fila->codigo] ?? [];
            $indice = array_search($fila->valor, $valores, true);
            if ($indice === false) {
                $sobrantes[$fila->cat][$fila->codigo][] = $fila->valor;
            } else {
                unset($pendientes[$fila->cat][$fila->codigo][$indice]);
            }
        }

        foreach ($sobrantes as $cat => $codigos) {
            foreach ($codigos as $codigo => $valores) {
                foreach ($valores as $valor) {
                    if (! empty($pendientes[$cat][$codigo])) {
                        $esperado = array_shift($pendientes[$cat][$codigo]);
                        $diferencias[$cat][] = "Valor distinto: {$codigo} — base: {$valor}; Excel: {$esperado}";
                    } else {
                        $diferencias[$cat][] = "Sobrante: {$codigo} — {$valor}";
                    }
                }
            }
        }
        foreach ($pendientes as $cat => $codigos) {
            foreach ($codigos as $codigo => $valores) {
                foreach ($valores as $valor) {
                    $diferencias[$cat][] = "Faltante: {$codigo} — {$valor}";
                }
            }
        }

        foreach (['012' => Departamento::class, '014' => UnidadMedida::class, '019' => ActividadEconomica::class, '020' => Pais::class] as $cat => $modelo) {
            foreach ($modelo::query()->whereNotNull('codigo')->get(['codigo', 'nombre']) as $fila) {
                if (! isset($oficiales[$cat][$fila->codigo])) {
                    $tabla = $fila->getTable();
                    $diferencias[$cat][] = "Código propio inexistente en el oficial: {$tabla}.codigo {$fila->codigo} — {$fila->nombre}";
                }
            }
        }

        ksort($diferencias);
        foreach ($diferencias as $cat => $mensajes) {
            $this->warn('CAT-'.$cat);
            foreach ($mensajes as $mensaje) {
                $this->line('  '.$mensaje);
            }
        }

        if ($importados->isEmpty()) {
            $this->error('La tabla catalogos_mh está vacía.');
        }
        if ($diferencias !== [] || $importados->isEmpty()) {
            return self::FAILURE;
        }

        $this->info('Los catálogos importados y los códigos propios coinciden con el Excel oficial.');

        return self::SUCCESS;
    }
}
