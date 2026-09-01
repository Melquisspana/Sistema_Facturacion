<?php

namespace App\Services\DocumentosRecibidos;

use App\Models\DocumentoRecibido;
use App\Models\DocumentoRecibidoProgreso;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Qué días del buzón de compras están recorridos de verdad.
 *
 * ÚNICA FUENTE DE VERDAD del avance. Se eligió una tabla por día en vez de una marca
 * suelta como la de albaranes por lo que el paquete de contabilidad necesita saber:
 * "¿agosto está completo?" no se puede responder mirando si hay documentos, porque un
 * día sin compras y un día sin leer son ambos cero filas. Acá sí se distinguen.
 *
 * LA REGLA, que gobierna todo el módulo: **solo `completo` cuenta como cubierto**.
 * `parcial`, `error` y la ausencia de fila significan lo mismo de cara al usuario —no
 * se sabe— y ninguno habilita a declarar un período cubierto. Ante la duda, se relee:
 * releer es gratis, perder un correo no.
 */
class ProgresoSincronizacionCompras
{
    /**
     * Progreso de un día, creándolo si hace falta. Nunca pisa lo ya cubierto.
     */
    public function dia(Carbon $dia, string $carpeta): DocumentoRecibidoProgreso
    {
        return DocumentoRecibidoProgreso::firstOrCreate(
            ['dia' => $dia->toDateString(), 'carpeta' => $carpeta],
            ['estado' => DocumentoRecibidoProgreso::ESTADO_PENDIENTE],
        );
    }

    /**
     * Deja el día como COMPLETO: se recorrió entero, sin truncar y sin error.
     *
     * @param  array{correos?: int, nuevos?: int, duplicados?: int, descartados?: int, rechazados?: int}  $conteos
     */
    public function marcarCompleto(Carbon $dia, string $carpeta, ?int $uidValidity, ?int $ultimoUid, array $conteos): DocumentoRecibidoProgreso
    {
        return $this->guardar($dia, $carpeta, $uidValidity, $ultimoUid, $conteos, [
            'estado' => DocumentoRecibidoProgreso::ESTADO_COMPLETO,
            'error' => null,
            'completado_en' => now(),
        ]);
    }

    /**
     * Deja el día A MEDIAS: el límite se alcanzó y quedan UID por leer.
     *
     * `ultimo_uid` es el cursor con el que la corrida siguiente continúa exactamente
     * donde esta se quedó, sin releer lo ya procesado ni saltear lo que falta.
     *
     * @param  array{correos?: int, nuevos?: int, duplicados?: int, descartados?: int, rechazados?: int}  $conteos
     */
    public function marcarParcial(Carbon $dia, string $carpeta, ?int $uidValidity, ?int $ultimoUid, array $conteos): DocumentoRecibidoProgreso
    {
        return $this->guardar($dia, $carpeta, $uidValidity, $ultimoUid, $conteos, [
            'estado' => DocumentoRecibidoProgreso::ESTADO_PARCIAL,
            'error' => null,
            'completado_en' => null,
        ]);
    }

    /**
     * Deja el día CON ERROR y el motivo. El cursor se conserva: lo ya leído no se
     * repite, pero el día tampoco se da por cubierto.
     *
     * @param  array{correos?: int, nuevos?: int, duplicados?: int, descartados?: int, rechazados?: int}  $conteos
     */
    public function marcarError(Carbon $dia, string $carpeta, ?int $uidValidity, ?int $ultimoUid, string $motivo, array $conteos = []): DocumentoRecibidoProgreso
    {
        return $this->guardar($dia, $carpeta, $uidValidity, $ultimoUid, $conteos, [
            'estado' => DocumentoRecibidoProgreso::ESTADO_ERROR,
            'error' => mb_substr($motivo, 0, 1000),
            'completado_en' => null,
        ]);
    }

    /**
     * @param  array<string, int>  $conteos
     * @param  array<string, mixed>  $extra
     */
    private function guardar(Carbon $dia, string $carpeta, ?int $uidValidity, ?int $ultimoUid, array $conteos, array $extra): DocumentoRecibidoProgreso
    {
        $fila = $this->dia($dia, $carpeta);

        // Los conteos se SUMAN: un día que se recorre en tres páginas tiene que terminar
        // con el total de las tres, no con el de la última.
        foreach (['correos', 'nuevos', 'duplicados', 'descartados', 'rechazados'] as $campo) {
            $fila->{$campo} = (int) $fila->{$campo} + (int) ($conteos[$campo] ?? 0);
        }

        $fila->uid_validity = $uidValidity;
        // El cursor nunca retrocede dentro del mismo día.
        if ($ultimoUid !== null) {
            $fila->ultimo_uid = max((int) $fila->ultimo_uid, $ultimoUid);
        }
        $fila->fill($extra)->save();

        return $fila;
    }

    /**
     * Cursor con el que reanudar un día, o null para empezarlo desde cero.
     *
     * Un día ya COMPLETO devuelve su cursor igual: si alguien vuelve a pedir ese rango
     * (solape, recuperación repetida), la lectura arranca después de lo último leído en
     * vez de rehacer el día entero. La deduplicación protegería igual, pero no hace
     * falta pagar la descarga dos veces.
     */
    public function cursorDe(Carbon $dia, string $carpeta): ?int
    {
        $fila = DocumentoRecibidoProgreso::where('dia', $dia->toDateString())
            ->where('carpeta', $carpeta)->first();

        return $fila?->ultimo_uid;
    }

    /**
     * ¿El progreso guardado sigue siendo válido para este buzón?
     *
     * Si el `UIDVALIDITY` de la carpeta cambió, los UID guardados apuntan a otros
     * correos: reanudar desde un cursor viejo saltearía documentos reales. Devuelve el
     * valor anterior cuando hay conflicto, o null cuando todo está en orden.
     */
    public function uidValidityEnConflicto(string $carpeta, ?int $uidValidityActual): ?int
    {
        if ($uidValidityActual === null) {
            return null;
        }

        $anterior = DocumentoRecibidoProgreso::where('carpeta', $carpeta)
            ->whereNotNull('uid_validity')
            ->orderByDesc('dia')
            ->value('uid_validity');

        return ($anterior !== null && (int) $anterior !== $uidValidityActual) ? (int) $anterior : null;
    }

    /**
     * Invalida el progreso por UID de una carpeta tras un cambio de `UIDVALIDITY`.
     *
     * No borra filas ni documentos: solo suelta los cursores —que ya no significan
     * nada— y baja a `pendiente` los días que no estaban cerrados, para que se
     * recorran de nuevo. Los días `completo` se conservan: sus documentos siguen
     * guardados y la deduplicación por identidad impide que se dupliquen.
     */
    public function reiniciarPorUidValidity(string $carpeta, ?int $uidValidityNuevo): int
    {
        return DocumentoRecibidoProgreso::where('carpeta', $carpeta)->update([
            'ultimo_uid' => null,
            'uid_validity' => $uidValidityNuevo,
            'estado' => DocumentoRecibidoProgreso::ESTADO_PENDIENTE,
            'completado_en' => null,
        ]);
    }

    /** Días del rango, inclusive, como fechas `Y-m-d`. @return array<int, string> */
    public function dias(Carbon $desde, Carbon $hasta): array
    {
        $dias = [];
        for ($d = $desde->copy()->startOfDay(); $d->lte($hasta); $d->addDay()) {
            $dias[] = $d->toDateString();
        }

        return $dias;
    }

    /**
     * Último día recorrido ENTERO de forma contigua desde el más viejo conocido.
     *
     * "Contiguo" importa: si hay un hueco (un día con error en medio), la marca se
     * planta ANTES del hueco. Declarar como avanzado lo que está del otro lado dejaría
     * el hueco sin reintentar para siempre, que es exactamente cómo se perdieron los
     * correos de agosto.
     */
    public function ultimoDiaCompletoContiguo(string $carpeta): ?Carbon
    {
        $filas = DocumentoRecibidoProgreso::where('carpeta', $carpeta)
            ->orderBy('dia')->get(['dia', 'estado']);

        $marca = null;
        foreach ($filas as $fila) {
            if (! $fila->estaCompleto()) {
                break;
            }
            $marca = $fila->dia->copy()->startOfDay();
        }

        return $marca;
    }

    /**
     * Días del rango que NO están cubiertos: sin fila, o con fila sin cerrar.
     *
     * @return Collection<int, array{dia: string, estado: string, error: ?string}>
     */
    public function diasSinCubrir(Carbon $desde, Carbon $hasta, string $carpeta): Collection
    {
        $filas = DocumentoRecibidoProgreso::where('carpeta', $carpeta)
            ->whereBetween('dia', [$desde->toDateString(), $hasta->toDateString()])
            ->get()->keyBy(fn ($f) => $f->dia->toDateString());

        return collect($this->dias($desde, $hasta))
            ->map(function (string $dia) use ($filas) {
                $fila = $filas->get($dia);

                return [
                    'dia' => $dia,
                    // Sin fila el día nunca se miró: es distinto de "se miró y no había nada".
                    'estado' => $fila?->estado ?? 'sin_revisar',
                    'error' => $fila?->error,
                ];
            })
            ->reject(fn (array $d) => $d['estado'] === DocumentoRecibidoProgreso::ESTADO_COMPLETO)
            ->values();
    }

    /**
     * Resumen de cobertura de un rango, para pantalla y para el comando.
     *
     * @return array{
     *   desde: string, hasta: string, carpeta: string, dias_totales: int, dias_completos: int,
     *   dias_sin_cubrir: array<int, array{dia: string, estado: string, error: ?string}>,
     *   dias_con_error: int, completo: bool, sin_datos: bool,
     *   correos: int, nuevos: int, duplicados: int, descartados: int, rechazados: int,
     *   ultima_sincronizacion: ?string
     * }
     */
    public function cobertura(Carbon $desde, Carbon $hasta, string $carpeta): array
    {
        $dias = $this->dias($desde, $hasta);
        $filas = DocumentoRecibidoProgreso::where('carpeta', $carpeta)
            ->whereBetween('dia', [$desde->toDateString(), $hasta->toDateString()])->get();

        $sinCubrir = $this->diasSinCubrir($desde, $hasta, $carpeta);
        $completos = $filas->filter(fn ($f) => $f->estaCompleto());

        return [
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'carpeta' => $carpeta,
            'dias_totales' => count($dias),
            'dias_completos' => $completos->count(),
            'dias_sin_cubrir' => $sinCubrir->all(),
            'dias_con_error' => $filas->where('estado', DocumentoRecibidoProgreso::ESTADO_ERROR)->count(),
            'completo' => $sinCubrir->isEmpty(),
            // Sin una sola fila no se puede afirmar NI que está completo ni que falta
            // algo: el período es anterior a que existiera el registro de progreso.
            'sin_datos' => $filas->isEmpty(),
            'correos' => (int) $filas->sum('correos'),
            'nuevos' => (int) $filas->sum('nuevos'),
            'duplicados' => (int) $filas->sum('duplicados'),
            'descartados' => (int) $filas->sum('descartados'),
            'rechazados' => (int) $filas->sum('rechazados'),
            'ultima_sincronizacion' => $completos->max('completado_en')?->toDateTimeString(),
        ];
    }

    /**
     * Día desde el que arrancar una corrida incremental, sin rango explícito.
     *
     * Prioridad: la marca de progreso (menos el solape) → el documento más viejo aún
     * sin cubrir → los últimos días. Nunca sale de la nada: si no hay progreso todavía
     * se parte del último documento guardado, igual que hacía la versión anterior, pero
     * ahora el hueco que quede se ve en la tabla en vez de desaparecer.
     */
    public function inicioIncremental(string $carpeta, int $solapeDias, int $diasPorDefecto = 7): Carbon
    {
        $marca = $this->ultimoDiaCompletoContiguo($carpeta);

        if ($marca !== null) {
            // El día siguiente al cubierto, retrocediendo el solape para recoger los
            // correos que llegan con fecha del día anterior.
            return $marca->copy()->addDay()->subDays(max(0, $solapeDias))->startOfDay();
        }

        $ultimo = DocumentoRecibido::query()->max('fecha_correo');
        if ($ultimo !== null) {
            return Carbon::parse($ultimo)->startOfDay()->subDays(max(0, $solapeDias));
        }

        return now()->subDays(max(1, $diasPorDefecto))->startOfDay();
    }
}
