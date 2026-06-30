<?php

namespace App\Console\Commands;

use App\Models\CatalogoMh;
use App\Models\Departamento;
use App\Models\Distrito;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Puebla `distritos.codigo` con el código OFICIAL CAT-008 del MH, SIN inventar datos.
 *
 * Reto del catálogo: `catalogos_mh` cat='008' solo guarda (codigo, valor=nombre del
 * distrito), SIN columna de departamento/municipio, y el codigo REINICIA por
 * departamento (1,2,3…). Además los nombres oficiales vienen ABREVIADOS/TRUNCADOS
 * (ej. "STA ROSA GUACHI", "SAN ANT LA CRUZ") y a 1 dígito ("5" en vez de "05").
 *
 * Estrategia 100% determinista (cero invención):
 *  1. Reconstruye el departamento de cada fila CAT-008 por los reinicios de codigo a 1
 *     (los bloques siguen el orden oficial de departamentos 01..14). Verifica 14 bloques.
 *  2. Normaliza el codigo a 2 dígitos (consistente con CAT-012/013).
 *  3. Empareja cada distrito de la tabla con su CAT-008 DENTRO de su departamento, en 3
 *     pases, exigiendo SIEMPRE unicidad: (a) nombre exacto normalizado; (b) posicional
 *     con expansión de abreviaturas + prefijo; (c) subsecuencia (para nombres más cortos
 *     en la tabla). Solo escribe cuando hay UN candidato. Ambiguos/sin match: se reportan
 *     y se dejan NULL (no se sobrescribe a ciegas).
 *
 * Idempotente. Con --dry-run no escribe. Con --sobrescribir reemplaza códigos ya puestos.
 */
class VincularCodigoDistritoMh extends Command
{
    protected $signature = 'distritos:codigos-mh
        {--dry-run : Solo muestra cuántos se poblarían, sin escribir}
        {--sobrescribir : Reescribe el codigo aunque ya tenga valor}';

    protected $description = 'Puebla distritos.codigo con el CAT-008 oficial (match por departamento + nombre, abreviaturas normalizadas).';

    /** Abreviaturas oficiales recurrentes del catálogo CAT-008 → forma completa. */
    private const ABBR = [
        'sta' => 'santa', 'stgo' => 'santiago', 'stg' => 'santiago', 'sn' => 'san',
        'nva' => 'nueva', 'nvo' => 'nuevo', 'nom' => 'nombre', 'fco' => 'francisco',
        'mig' => 'miguel', 'ant' => 'antonio', 'raf' => 'rafael', 'concep' => 'concepcion',
        'orat' => 'oratorio', 'pto' => 'puerto', 'meang' => 'meanguera', 'delic' => 'delicias',
        'ote' => 'oriente', 'merced' => 'mercedes', 'med' => 'mercedes', 'herr' => 'herradura',
        'cay' => 'cayetano', 'est' => 'esteban', 'ma' => 'maria', 'antgo' => 'antiguo',
    ];

    /** Conectores que se ignoran al comparar nombres. */
    private const CONN = ['de', 'del', 'la', 'las', 'los', 'el', 'y', 'd'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $sobrescribir = (bool) $this->option('sobrescribir');

        // Orden oficial de departamentos (CAT-012) para mapear los bloques de CAT-008.
        $deptoCods = Departamento::orderBy('codigo')->pluck('codigo')->all();

        // CAT-008 agrupado por departamento, vía los reinicios de codigo a 1.
        $rows = CatalogoMh::where('cat', '008')
            ->where('valor', '!=', 'Otro (Para extranjeros)')
            ->orderBy('id')->get(['codigo', 'valor']);

        if ($rows->isEmpty()) {
            $this->error('No hay CAT-008 en catalogos_mh. Corré primero la importación oficial '
                .'(ImportarCatalogosMhCommand) y reintentá.');

            return self::FAILURE;
        }

        $cat = [];
        $idx = -1;
        foreach ($rows as $r) {
            if ((int) $r->codigo === 1) {
                $idx++;
            }
            $depCod = $deptoCods[$idx] ?? null;
            if ($depCod === null) {
                $this->error("CAT-008 tiene más bloques que departamentos ({$idx}); abortando por seguridad.");

                return self::FAILURE;
            }
            $cat[$depCod][] = ['cod' => str_pad((string) $r->codigo, 2, '0', STR_PAD_LEFT), 'val' => $r->valor];
        }

        $bloques = $idx + 1;
        $this->line("CAT-008: {$rows->count()} distritos en {$bloques} bloques de departamento (esperado ".count($deptoCods).').');
        if ($bloques !== count($deptoCods)) {
            $this->error('El número de bloques no coincide con los departamentos; abortando para no asignar mal.');

            return self::FAILURE;
        }

        $poblados = 0;
        $yaTenian = 0;
        $sinCambios = 0;
        $porExacto = 0;
        $porFuzzy = 0;
        $porSubsec = 0;
        $problemas = [];
        $revisar = [];

        $distritos = Distrito::with('departamento:id,codigo')->get();
        foreach ($distritos->groupBy(fn ($d) => $d->departamento?->codigo) as $depCod => $lista) {
            $catList = $cat[$depCod] ?? [];
            $usado = [];
            $resol = []; // distrito_id => ['cod' => ..., 'via' => ...]

            // Pase 1: nombre exacto normalizado.
            foreach ($lista as $d) {
                foreach ($catList as $k => $c) {
                    if (isset($usado[$k])) {
                        continue;
                    }
                    if ($this->norm($c['val']) === $this->norm($d->nombre)) {
                        $usado[$k] = true;
                        $resol[$d->id] = ['cod' => $c['cod'], 'via' => 'exacto'];
                        $porExacto++;
                        break;
                    }
                }
            }
            // Pase 2: posicional (abreviatura + prefijo), exige unicidad.
            $this->resolverPase($lista, $catList, $usado, $resol, fn ($c, $d) => $this->posMatch($c, $d),
                'fuzzy', $porFuzzy, $problemas);
            // Pase 3: subsecuencia (nombres más cortos en la tabla), exige unicidad.
            $this->resolverPase($lista, $catList, $usado, $resol, fn ($c, $d) => $this->subseqMatch($c, $d),
                'subsec', $porSubsec, $problemas);

            foreach ($lista as $d) {
                if (! isset($resol[$d->id])) {
                    $problemas[] = "SIN MATCH  depto {$depCod} | {$d->nombre}";

                    continue;
                }
                ['cod' => $cod, 'via' => $via] = $resol[$d->id];
                if ($via === 'subsec') {
                    $revisar[] = "  depto {$depCod} | {$d->nombre}  →  CAT-008 {$cod} (por subsecuencia)";
                }
                if (filled($d->codigo) && ! $sobrescribir) {
                    $yaTenian++;

                    continue;
                }
                if ((string) $d->codigo === (string) $cod) {
                    $sinCambios++;

                    continue;
                }
                if (! $dry) {
                    $d->forceFill(['codigo' => $cod])->saveQuietly();
                }
                $poblados++;
            }
        }

        if ($revisar) {
            $this->newLine();
            $this->comment('Resueltos por subsecuencia (nombre de la tabla más corto que el oficial) — revisar:');
            $this->line(implode("\n", $revisar));
        }
        if ($problemas) {
            $this->newLine();
            $this->warn('No mapeados con seguridad (se dejaron en NULL, NO se inventaron):');
            $this->line('  '.implode("\n  ", $problemas));
        }

        $this->newLine();
        $this->table(
            ['Poblados', 'Por exacto', 'Por fuzzy', 'Por subsec', 'Ya tenían', 'Sin cambios', 'Sin match'],
            [[$poblados, $porExacto, $porFuzzy, $porSubsec, $yaTenian, $sinCambios, count($problemas)]]
        );
        $conCodigo = Distrito::whereNotNull('codigo')->count();
        $this->info(($dry ? '[dry-run] ' : '')."codigo CAT-008 poblado en {$poblados} distrito(s). "
            ."Total con código: {$conCodigo} / ".Distrito::count().'.');

        return self::SUCCESS;
    }

    /**
     * Resuelve un pase de emparejamiento exigiendo unicidad dentro del departamento.
     *
     * @param  \Illuminate\Support\Collection<int, Distrito>  $lista
     * @param  array<int, array{cod:string, val:string}>  $catList
     * @param  array<int, bool>  $usado
     * @param  array<int, array{cod:string, via:string}>  $resol
     * @param  callable(string, string): bool  $coincide
     * @param  array<int, string>  $problemas
     */
    private function resolverPase($lista, array $catList, array &$usado, array &$resol, callable $coincide, string $via, int &$contador, array &$problemas): void
    {
        foreach ($lista as $d) {
            if (isset($resol[$d->id])) {
                continue;
            }
            $cands = [];
            foreach ($catList as $k => $c) {
                if (isset($usado[$k])) {
                    continue;
                }
                if ($coincide($c['val'], $d->nombre)) {
                    $cands[$k] = $c;
                }
            }
            if (count($cands) === 1) {
                $k = array_key_first($cands);
                $usado[$k] = true;
                $resol[$d->id] = ['cod' => $cands[$k]['cod'], 'via' => $via];
                $contador++;
            } elseif (count($cands) > 1) {
                $problemas[] = "AMBIGUO ({$via}) | {$d->nombre} → ".implode(' / ', array_map(fn ($c) => $c['val'], $cands));
            }
        }
    }

    /** Tokeniza: ascii, minúsculas, sin signos ni conectores. @return array<int, string> */
    private function tokens(string $s): array
    {
        $s = preg_replace('/[^a-z0-9 ]/', ' ', strtolower(Str::ascii($s)));

        return array_values(array_filter(
            preg_split('/\s+/', trim($s)),
            fn ($t) => $t !== '' && ! in_array($t, self::CONN, true)
        ));
    }

    private function norm(string $s): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim(Str::ascii($s))));
    }

    /** ¿El token oficial (posiblemente abreviado) corresponde al token completo? */
    private function tokenCoincide(string $c, string $t): bool
    {
        if ($c === $t || str_starts_with($t, $c)) {
            return true;
        }
        $exp = self::ABBR[$c] ?? null;

        return $exp !== null && ($exp === $t || str_starts_with($t, $exp));
    }

    /** Match posicional: mismo nº de tokens y cada uno corresponde. */
    private function posMatch(string $catNombre, string $tablaNombre): bool
    {
        $c = $this->tokens($catNombre);
        $t = $this->tokens($tablaNombre);
        if (count($c) !== count($t)) {
            return false;
        }
        foreach ($c as $i => $ct) {
            if (! $this->tokenCoincide($ct, $t[$i])) {
                return false;
            }
        }

        return true;
    }

    /** Match por subsecuencia: cada token de la tabla aparece, en orden, dentro del oficial. */
    private function subseqMatch(string $catNombre, string $tablaNombre): bool
    {
        $c = $this->tokens($catNombre);
        $t = $this->tokens($tablaNombre);
        $j = 0;
        foreach ($t as $tt) {
            $encontrado = false;
            while ($j < count($c)) {
                $ct = $c[$j];
                $j++;
                if ($this->tokenCoincide($ct, $tt) || str_starts_with($ct, $tt)) {
                    $encontrado = true;
                    break;
                }
            }
            if (! $encontrado) {
                return false;
            }
        }

        return true;
    }
}
