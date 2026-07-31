<?php

namespace App\Support\Planta;

use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Spatie\Activitylog\Traits\LogsActivity;
use Symfony\Component\Finder\Finder;

/**
 * Rastro del `activity_log` que deja UNA ejecución de una herramienta de
 * verificación de Planta, para poder borrar exactamente eso y nada más.
 *
 * POR QUÉ EXISTE. Los scripts de verificación y el diagnóstico de concurrencia
 * crean insumos, lotes, ubicaciones y documentos temporales, y al terminar los
 * borran de sus tablas. Pero cada uno de esos modelos usa {@see LogsActivity}:
 * al crearlos se escribe además una fila en `activity_log` que NO desaparece con
 * el borrado del sujeto. La limpieza original intentaba resolverlo enumerando a
 * mano los modelos a barrer, y esa lista se quedó corta —cubría los documentos
 * pero no los catálogos—, así que cada corrida dejaba filas huérfanas apuntando
 * a sujetos inexistentes. Una lista escrita a mano vuelve a quedarse corta en
 * cuanto alguien añade un modelo; por eso aquí no hay lista escrita a mano.
 *
 * CÓMO IDENTIFICA LO SUYO. Al abrirse anota el mayor `id` de `activity_log`. Ese
 * número es la frontera: todo lo que la herramienta escriba después tendrá un id
 * superior, porque la columna es autoincremental. Al purgar borra únicamente
 * filas que cumplen LAS TRES condiciones a la vez:
 *
 *   1. `id` mayor que la marca — la fila nació DURANTE esta ejecución. Es el
 *      criterio principal: no se deduce de los datos, se observa;
 *   2. `subject_type` es un modelo de Planta — nunca se toca nada de
 *      Facturación, DTE, PPQ ni ningún otro módulo, aunque se hubiera escrito
 *      dentro de la ventana;
 *   3. el sujeto YA NO EXISTE en su tabla — la herramienta borró sus datos
 *      temporales antes de purgar, así que lo que sobrevive es lo que ella no
 *      creó. Esto protege a un registro legítimo que un usuario real hubiera
 *      generado en la misma ventana de tiempo: su sujeto sigue vivo y la fila
 *      se conserva.
 *
 * La condición 3 NO se usa sola a propósito. Un `activity_log` histórico y
 * legítimo cuyo sujeto se dio de baja hace meses también es huérfano, y borrarlo
 * sería destruir auditoría real; la marca de la condición 1 es justamente lo que
 * distingue «huérfano que acabo de fabricar yo» de «huérfano que ya estaba ahí».
 *
 * CÓMO SE USA. Se abre antes de crear nada y se purga en un `finally`, para que
 * una comprobación fallida o una excepción a media ejecución no dejen rastro:
 *
 *     $rastro = RastroActivityLog::abrir();
 *     try {
 *         // …crear datos temporales y verificar…
 *     } finally {
 *         $this->limpiarTablas();   // primero los sujetos
 *         $rastro->purgar();        // después su rastro
 *     }
 *
 * El orden importa: purgar antes de borrar los sujetos no encontraría nada que
 * borrar, porque la condición 3 no se cumpliría todavía.
 */
final class RastroActivityLog
{
    /**
     * Modelos de Planta que auditan, con su tabla. Se descubre leyendo el
     * directorio de modelos en vez de mantenerse a mano: así un modelo nuevo
     * queda cubierto el día que se crea, que es justo lo que falló antes.
     *
     * @var array<class-string, string>|null
     */
    private static ?array $modelos = null;

    private function __construct(private readonly int $marca) {}

    /** Anota la frontera. Todo lo que se escriba a partir de aquí es nuestro. */
    public static function abrir(): self
    {
        return new self((int) (DB::table('activity_log')->max('id') ?? 0));
    }

    /** El mayor id de `activity_log` en el momento de abrir el rastro. */
    public function marca(): int
    {
        return $this->marca;
    }

    /**
     * Ids que hoy cumplen los tres criterios. Se expone aparte de {@see purgar()}
     * para poder mirar qué se va a borrar —o comprobarlo en una prueba— sin
     * borrarlo.
     *
     * @return array<int, int>
     */
    public function idsPurgables(): array
    {
        $purgables = [];

        foreach (self::modelos() as $clase => $tabla) {
            $filas = DB::table('activity_log')
                ->where('id', '>', $this->marca)
                ->where('subject_type', $clase)
                ->whereNotNull('subject_id')
                ->get(['id', 'subject_id']);

            if ($filas->isEmpty()) {
                continue;
            }

            // Una sola consulta por modelo para saber qué sujetos siguen vivos:
            // los que no aparecen son los que la herramienta ya borró.
            $vivos = DB::table($tabla)
                ->whereIn('id', $filas->pluck('subject_id')->unique()->all())
                ->pluck('id')
                ->all();

            foreach ($filas as $fila) {
                if (! in_array($fila->subject_id, $vivos)) {
                    $purgables[] = (int) $fila->id;
                }
            }
        }

        sort($purgables);

        return $purgables;
    }

    /**
     * Borra el rastro de esta ejecución y devuelve cuántas filas se fueron.
     * Es idempotente: llamarla dos veces no borra nada la segunda vez.
     */
    public function purgar(): int
    {
        $ids = $this->idsPurgables();

        if ($ids === []) {
            return 0;
        }

        // Por lotes: una ejecución larga puede acumular miles de ids y hay
        // motores que no admiten un `IN` ilimitado.
        $borradas = 0;
        foreach (array_chunk($ids, 500) as $lote) {
            $borradas += DB::table('activity_log')->whereIn('id', $lote)->delete();
        }

        return $borradas;
    }

    /**
     * Modelos de `App\Models\Planta` que usan {@see LogsActivity}, mapeados a su
     * tabla.
     *
     * @return array<class-string, string>
     */
    public static function modelos(): array
    {
        if (self::$modelos !== null) {
            return self::$modelos;
        }

        $modelos = [];

        foreach (Finder::create()->files()->in(app_path('Models/Planta'))->name('*.php') as $archivo) {
            $clase = 'App\\Models\\Planta\\'.$archivo->getFilenameWithoutExtension();

            if (! class_exists($clase)) {
                continue;
            }

            $reflexion = new ReflectionClass($clase);

            if ($reflexion->isAbstract() || ! in_array(LogsActivity::class, self::trazasDe($reflexion), true)) {
                continue;
            }

            $modelos[$clase] = (new $clase)->getTable();
        }

        ksort($modelos);

        return self::$modelos = $modelos;
    }

    /**
     * Traits de una clase incluyendo los heredados y los anidados: `LogsActivity`
     * podría llegar a través de un trait intermedio o de una clase padre, y
     * mirar solo `getTraitNames()` lo pasaría por alto.
     *
     * @return array<int, class-string>
     */
    private static function trazasDe(ReflectionClass $reflexion): array
    {
        $trazas = [];

        for ($clase = $reflexion; $clase !== false; $clase = $clase->getParentClass()) {
            foreach ($clase->getTraitNames() as $traza) {
                $trazas[] = $traza;
                $trazas = array_merge($trazas, class_uses_recursive($traza));
            }
        }

        return array_values(array_unique($trazas));
    }

    /** Solo para pruebas: obliga a redescubrir los modelos. */
    public static function olvidarModelos(): void
    {
        self::$modelos = null;
    }
}
