<?php

namespace App\Support\Ubicacion;

use Database\Seeders\MunicipioSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * FUENTE ÚNICA del vínculo DISTRITO (CAT-008) → MUNICIPIO fiscal 2024 (CAT-013).
 *
 * `distritos.municipio` guarda el NOMBRE de la agrupación 2024 ("Cabañas Oeste") como
 * texto. Esta clase lo traduce al CÓDIGO CAT-013 y lo persiste en
 * `distritos.municipio_codigo`, de modo que la pertenencia distrito → municipio se pueda
 * VALIDAR por código en lugar de confiar en una etiqueta visual.
 *
 * Determinista y sin invención: empareja por nombre normalizado (ascii, minúsculas, un
 * solo espacio) contra el CAT-013 oficial ya importado en `catalogos_mh`. Si un nombre
 * queda AMBIGUO (más de un código) o SIN CORRESPONDENCIA, lanza excepción en lugar de
 * dejar filas en NULL o asignar un código a ciegas.
 *
 * La usan por igual la migración, el comando `distritos:vincular-municipio` y el seeder
 * de catálogos, para que no existan dos reglas de emparejamiento distintas.
 */
final class VinculaMunicipioDistrito
{
    /**
     * Vincula todos los distritos con su municipio CAT-013.
     *
     * @param  bool  $dryRun  true = solo calcula, no escribe
     * @return array{vinculados: int, sin_cambios: int, mapa: array<string, string>, total: int}
     *
     * @throws RuntimeException si hay nombres ambiguos o sin correspondencia
     */
    public static function ejecutar(bool $dryRun = false): array
    {
        $mapa = self::mapaNombreACodigo();

        $vinculados = 0;
        $sinCambios = 0;

        foreach ($mapa as $nombre => $codigo) {
            $pendientes = DB::table('distritos')
                ->where('municipio', $nombre)
                ->where(fn ($q) => $q->whereNull('municipio_codigo')->orWhere('municipio_codigo', '!=', $codigo))
                ->count();

            $sinCambios += DB::table('distritos')
                ->where('municipio', $nombre)
                ->where('municipio_codigo', $codigo)
                ->count();

            if ($pendientes > 0 && ! $dryRun) {
                DB::table('distritos')->where('municipio', $nombre)->update(['municipio_codigo' => $codigo]);
            }
            $vinculados += $pendientes;
        }

        if (! $dryRun) {
            $huerfanos = DB::table('distritos')->whereNull('municipio_codigo')->count();
            if ($huerfanos > 0) {
                throw new RuntimeException(
                    "Quedaron {$huerfanos} distrito(s) sin municipio_codigo. Se aborta para no dejar "
                    .'vínculos silenciosamente en NULL.'
                );
            }
        }

        return [
            'vinculados' => $vinculados,
            'sin_cambios' => $sinCambios,
            'mapa' => $mapa,
            'total' => DB::table('distritos')->count(),
        ];
    }

    /**
     * Mapa nombre-de-municipio-2024 → código CAT-013, validado sin ambigüedad.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException
     */
    public static function mapaNombreACodigo(): array
    {
        $porNombre = [];
        foreach (DB::table('catalogos_mh')->where('cat', '013')->get(['codigo', 'valor']) as $fila) {
            $porNombre[self::norm((string) $fila->valor)][] = (string) $fila->codigo;
        }

        if ($porNombre === []) {
            throw new RuntimeException(
                'No hay CAT-013 en catalogos_mh: importá primero el catálogo oficial con `php artisan dte:catalogos`.'
            );
        }

        $mapa = [];
        $ambiguos = [];
        $sinMatch = [];

        foreach (DB::table('distritos')->distinct()->pluck('municipio') as $nombre) {
            if (blank($nombre)) {
                continue;
            }
            $codigos = array_values(array_unique($porNombre[self::norm((string) $nombre)] ?? []));

            if ($codigos === []) {
                $sinMatch[] = (string) $nombre;
            } elseif (count($codigos) > 1) {
                $ambiguos[] = $nombre.' → CAT-013 '.implode('/', $codigos);
            } else {
                $mapa[(string) $nombre] = $codigos[0];
            }
        }

        if ($sinMatch !== [] || $ambiguos !== []) {
            throw new RuntimeException(
                "No se puede vincular distritos → municipio CAT-013 sin ambigüedad.\n"
                .($sinMatch !== [] ? '  SIN CORRESPONDENCIA en CAT-013: '.implode(', ', $sinMatch)."\n" : '')
                .($ambiguos !== [] ? '  AMBIGUOS: '.implode(' | ', $ambiguos)."\n" : '')
                .'Revisá el catálogo oficial (dte:catalogos) y el dataset de distritos antes de reintentar.'
            );
        }

        return $mapa;
    }

    /**
     * Completa `municipios.codigo` (CAT-013) SOLO donde está en NULL.
     *
     * Regla determinista: en la reforma 2024 cada municipio anterior pasó a ser un
     * DISTRITO con el mismo nombre, así que el código de la agrupación de un municipio es
     * el `municipio_codigo` del distrito homónimo dentro de su departamento.
     *
     * Verificado contra los datos ya cargados: reproduce 53/53 los códigos existentes sin
     * una sola diferencia. Nunca sobreescribe un código ya puesto y nunca inventa uno: si
     * no hay distrito homónimo, la fila se deja como está y se reporta.
     *
     * @return array{completados: int, sin_referencia: array<int, string>}
     */
    public static function completarCodigosMunicipio(bool $dryRun = false): array
    {
        $completados = 0;
        $sinReferencia = [];

        $pendientes = DB::table('municipios')
            ->whereNull('codigo')
            ->get(['id', 'departamento_id', 'nombre']);

        foreach ($pendientes as $municipio) {
            $codigos = DB::table('distritos')
                ->where('departamento_id', $municipio->departamento_id)
                ->where('nombre', $municipio->nombre)
                ->whereNotNull('municipio_codigo')
                ->distinct()
                ->pluck('municipio_codigo')
                ->all();

            if (count($codigos) !== 1) {
                $sinReferencia[] = $municipio->nombre.' (municipio_id '.$municipio->id.')';

                continue;
            }

            if (! $dryRun) {
                DB::table('municipios')->where('id', $municipio->id)->update(['codigo' => $codigos[0]]);
            }
            $completados++;
        }

        return ['completados' => $completados, 'sin_referencia' => $sinReferencia];
    }

    /**
     * Garantiza que toda AGRUPACIÓN fiscal 2024 presente en `distritos` tenga al menos
     * una fila representante en `municipios`, para que se pueda ELEGIR en el formulario.
     *
     * POR QUÉ HACE FALTA: `municipios` no nació como catálogo completo, sino como el
     * "subconjunto útil para empezar a operar" de {@see MunicipioSeeder}
     * (municipios PRE-2024). El select de municipio se arma con esas filas
     * ({@see OpcionesUbicacion::municipios()}), así que una
     * agrupación cuyos municipios anteriores NO estaban en ese subconjunto se vuelve
     * INVISIBLE: no aparece en el segundo select y, como el tercero filtra por el
     * municipio elegido, TODOS sus distritos quedan inalcanzables.
     *
     * Caso que lo destapó: San Salvador Norte (CAT-013 20). Sus tres municipios anteriores
     * —Aguilares, El Paisnal y Guazapa— no estaban sembrados, así que no había ninguna
     * fila con `codigo = 20` en el departamento 06 y el distrito Guazapa no se podía
     * seleccionar, aunque el CSV y `distritos` lo tuvieran perfectamente cargado.
     *
     * QUÉ ESCRIBE: solo INSERTA la fila que falta, nombrada con la agrupación misma
     * ("San Salvador Norte"). No renombra, no reasigna ni desactiva nada existente: las
     * entidades apuntan a `municipio_id` y ningún id cambia. Si ya existe una fila
     * homónima con el código en NULL, se le completa el código (misma regla que
     * {@see completarCodigosMunicipio()}); si tiene OTRO código, no se toca y se reporta,
     * porque decidir entre dos códigos sería adivinar.
     *
     * Idempotente: en la segunda corrida no escribe una sola fila.
     *
     * @return array{creados: int, completados: int, agrupaciones: array<int, string>, conflictos: array<int, string>}
     */
    public static function asegurarAgrupaciones(bool $dryRun = false): array
    {
        // Agrupaciones que YA tienen representante: clave "departamento_id-codigo".
        $representadas = DB::table('municipios')
            ->whereNotNull('codigo')
            ->get(['departamento_id', 'codigo'])
            ->map(fn ($m) => $m->departamento_id.'-'.$m->codigo)
            ->flip();

        $creados = 0;
        $completados = 0;
        $agrupaciones = [];
        $conflictos = [];

        $huerfanas = DB::table('distritos')
            ->whereNotNull('municipio_codigo')
            ->distinct()
            ->get(['departamento_id', 'municipio', 'municipio_codigo'])
            ->reject(fn ($d) => $representadas->has($d->departamento_id.'-'.$d->municipio_codigo));

        foreach ($huerfanas as $agrupacion) {
            $nombre = (string) $agrupacion->municipio;
            $codigo = (string) $agrupacion->municipio_codigo;
            $etiqueta = $nombre.' (CAT-013 '.$codigo.')';

            $homonima = DB::table('municipios')
                ->where('departamento_id', $agrupacion->departamento_id)
                ->where('nombre', $nombre)
                ->first(['id', 'codigo']);

            // Fila homónima con OTRO código: no se pisa. Elegir cuál vale sería adivinar.
            if ($homonima && filled($homonima->codigo) && (string) $homonima->codigo !== $codigo) {
                $conflictos[] = $etiqueta.' choca con municipio_id '.$homonima->id
                    .' que ya tiene CAT-013 '.$homonima->codigo;

                continue;
            }

            if ($homonima) {
                if (! $dryRun) {
                    DB::table('municipios')->where('id', $homonima->id)->update([
                        'codigo' => $codigo,
                        'activo' => 1,
                        'updated_at' => now(),
                    ]);
                }
                $completados++;
            } else {
                if (! $dryRun) {
                    DB::table('municipios')->insert([
                        'departamento_id' => $agrupacion->departamento_id,
                        'codigo' => $codigo,
                        'nombre' => $nombre,
                        'activo' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $creados++;
            }

            $agrupaciones[] = $etiqueta;
            $representadas->put($agrupacion->departamento_id.'-'.$codigo, true);
        }

        return [
            'creados' => $creados,
            'completados' => $completados,
            'agrupaciones' => $agrupaciones,
            'conflictos' => $conflictos,
        ];
    }

    /**
     * Agrupaciones fiscales que hoy NO se pueden elegir en el formulario: existen en
     * `distritos` pero ninguna fila de `municipios` las representa. Solo lectura.
     *
     * @return array<int, string> "departamento_id-codigo => Nombre" listo para informar
     */
    public static function agrupacionesInalcanzables(): array
    {
        return array_values(self::asegurarAgrupaciones(dryRun: true)['agrupaciones']);
    }

    /** ¿Está el catálogo CAT-013 disponible para poder vincular? */
    public static function hayCatalogo(): bool
    {
        return DB::table('catalogos_mh')->where('cat', '013')->exists();
    }

    /** Normaliza un nombre de municipio para comparar: ascii, minúsculas, un solo espacio. */
    public static function norm(string $valor): string
    {
        return preg_replace('/\s+/', ' ', trim(mb_strtolower(Str::ascii($valor))));
    }
}
