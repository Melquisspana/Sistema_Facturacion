<?php

namespace App\Services\Planta;

use App\Enums\Planta\TipoDiferenciaReconciliacion;
use App\Exceptions\Planta\ReconciliacionBloqueadaException;
use App\Models\Planta\PlantaUbicacion;
use App\Support\Planta\BucketInventario;
use App\Support\Planta\DiferenciaReconciliacion;
use App\Support\Planta\ResultadoReconciliacion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Compara el libro mayor con la proyección de saldos y, si se le pide,
 * reconstruye la proyección desde el mayor.
 *
 * REGLA QUE NO SE NEGOCIA: `planta_movimientos` es de solo lectura para esta
 * clase. Nunca inserta, actualiza ni borra un movimiento, ni siquiera para
 * «arreglar» un mayor que esté mal. Si el defecto está en el mayor —una suma
 * negativa, un traslado incoherente— se informa y se termina con código de
 * salida distinto de cero; corregirlo es una decisión de negocio que se toma
 * registrando movimientos nuevos, no editando los viejos.
 *
 * QUÉ DETECTA, agrupando SIEMPRE por las CINCO dimensiones del bucket
 * ({@see BucketInventario::COLUMNAS}). Agrupar por cuatro —olvidar `estado` o
 * `planta_traslado_id`— sumaría saldos que no son el mismo saldo y daría
 * «cuadrado» a un inventario roto:
 *
 *   - buckets del mayor sin fila de saldo (faltantes);
 *   - filas de saldo sin un solo movimiento detrás (sobrantes);
 *   - saldos que no coinciden con la suma del mayor;
 *   - sumas negativas en el mayor;
 *   - `planta_traslado_id` incoherente con el tipo de la ubicación, que es
 *     justo la invariante que el motor no puede vigilar por sí solo al no haber
 *     clave foránea en esa columna.
 *
 * EJECUCIÓN SEGURA DE `--apply` frente a inventario en movimiento. El riesgo es
 * concreto: si entre leer el mayor y escribir la proyección otra transacción
 * aplica un movimiento, se reescribiría el saldo pisando el que esa transacción
 * acaba de dejar. Un candado cooperativo (Cache::lock, GET_LOCK) NO lo evita,
 * porque PlantaInventarioService no lo toma y por tanto no lo respeta.
 *
 * Lo que sí lo evita se apoya en una propiedad que el servicio de inventario ya
 * garantiza: TODO escritor bloquea con `lockForUpdate` la fila de existencia
 * ANTES de insertar el movimiento, y confirma ambas escrituras juntas. No hay
 * ningún instante con un movimiento escrito cuyo bucket no esté ya bloqueado
 * por su escritor. Sobre esa base, {@see aplicar()}:
 *
 *   1. toma un candado de caché que impide DOS reconciliadores a la vez
 *      (cooperativo entre iguales, que aquí basta porque ambos lo toman);
 *   2. dentro de una transacción, bloquea `planta_existencias` ENTERA con
 *      `SELECT ... FOR UPDATE`. En InnoDB eso toma next-key locks sobre
 *      registros y huecos, así que además de los buckets existentes impide
 *      INSERTAR buckets nuevos, que es por donde entraría un movimiento sobre
 *      un bucket que todavía no existe;
 *   3. lee el mayor DESPUÉS de tener ese candado, nunca antes;
 *   4. compara y corrige dentro de la misma transacción. Los escritores en
 *      espera reanudan en el COMMIT y parten del saldo ya corregido.
 *
 * El coste asumido es que `--apply` serializa el inventario mientras corre. Es
 * lo correcto: es mantenimiento excepcional, no un camino operativo.
 *
 * En SQLite el `FOR UPDATE` es no-op, pero una transacción de escritura
 * serializa la base entera y el efecto se obtiene igual. Por eso la garantía
 * REAL se verifica contra MySQL, no contra la suite.
 */
class ReconciliacionExistenciasService
{
    /** Escala del inventario. Coincide con decimal(14,4) y con el servicio de escritura. */
    private const ESCALA = 4;

    /** Factor para sumar en enteros donde el motor no tiene decimal exacto. */
    private const FACTOR = '10000';

    private const CANDADO = 'planta:reconciliar-existencias';

    private const CANDADO_SEGUNDOS = 600;

    /** Tope de diferencias que se vuelcan al registro de actividad. */
    private const MAX_DIFERENCIAS_AUDITADAS = 100;

    /**
     * Pasada de SOLO LECTURA. No escribe nada y no bloquea a nadie.
     *
     * Se envuelve en una transacción para leer el mayor y la proyección en un
     * snapshot coherente. Sin bloqueo, un movimiento confirmado ENTRE ambas
     * lecturas podría aparecer como diferencia; con el snapshot no puede.
     */
    public function analizar(): ResultadoReconciliacion
    {
        return DB::transaction(fn () => $this->comparar(bloquear: false));
    }

    /**
     * Reconstruye `planta_existencias` desde el mayor. NO toca movimientos.
     *
     * ADVERTENCIA OPERATIVA: mientras esto corre, TODA escritura de inventario
     * queda bloqueada. Es intencionado —es lo que impide que un movimiento se
     * cuele entre la lectura del mayor y la reescritura del saldo— pero significa
     * que una reconciliación sobre una base grande puede dejar en espera a las
     * recepciones y traslados que se estén registrando. Es mantenimiento, no
     * operación: conviene lanzarla fuera de horario.
     *
     * @param  callable|null  $alDetectar  Se invoca con el resultado del análisis
     *                                     JUSTO ANTES de corregir y ya DENTRO del
     *                                     candado. Sirve para informar de cuántas
     *                                     diferencias se van a aplicar sin abrir una
     *                                     ventana entre analizar y escribir.
     *
     * @throws ReconciliacionBloqueadaException Si ya hay otra pasada en curso.
     */
    public function aplicar(?callable $alDetectar = null): ResultadoReconciliacion
    {
        $candado = Cache::lock(self::CANDADO, self::CANDADO_SEGUNDOS);

        if (! $candado->get()) {
            throw ReconciliacionBloqueadaException::enCurso();
        }

        try {
            return DB::transaction(function () use ($alDetectar) {
                $resultado = $this->comparar(bloquear: true);

                $proyeccionAntes = $this->huellaProyeccion();

                if ($alDetectar !== null) {
                    $alDetectar($resultado);
                }

                $correcciones = $this->corregir($resultado);

                // Se vuelve a calcular el mayor JUSTO ANTES de confirmar, no al
                // principio: lo que hay que demostrar es que no cambió durante la
                // reconstrucción, y eso solo se sabe midiendo al final.
                $huellaDespues = $this->huellaMayor();

                // Autocomprobación: si el mayor cambió durante la corrección, algo
                // escribió en él y la transacción entera se deshace. Es barato y es
                // la única forma de demostrar la promesa «--apply no toca el mayor».
                if ($huellaDespues !== $resultado->huellaMayorAntes) {
                    throw new RuntimeException(
                        'El libro mayor cambió durante la reconciliación: se aborta sin aplicar nada. '
                        .'Antes: '.json_encode($resultado->huellaMayorAntes)
                        .' · Después: '.json_encode($huellaDespues)
                    );
                }

                $final = new ResultadoReconciliacion(
                    diferencias: $resultado->diferencias,
                    bucketsMayor: $resultado->bucketsMayor,
                    bucketsProyectados: $resultado->bucketsProyectados,
                    huellaMayorAntes: $resultado->huellaMayorAntes,
                    aplicado: true,
                    huellaMayorDespues: $huellaDespues,
                    correcciones: $correcciones,
                    huellaProyeccionAntes: $proyeccionAntes,
                    huellaProyeccionDespues: $this->huellaProyeccion(),
                );

                $this->auditar($final);

                return $final;
            });
        } finally {
            $candado->release();
        }
    }

    // --- Comparación ---

    /**
     * Compara mayor y proyección. Con `$bloquear`, toma primero el candado de
     * `planta_existencias`; el orden importa: el mayor se lee SIEMPRE después.
     */
    private function comparar(bool $bloquear): ResultadoReconciliacion
    {
        $proyectadas = $this->leerProyeccion($bloquear);
        $huellaAntes = $this->huellaMayor();
        $mayor = $this->sumarMayorPorBucket();
        $esTransito = $this->ubicacionesDeTransito();

        $diferencias = [];

        // Recorrido por el MAYOR: es la fuente de verdad, así que manda el orden.
        foreach ($mayor as $clave => $datos) {
            /** @var BucketInventario $bucket */
            $bucket = $datos['bucket'];

            $diferencias = array_merge(
                $diferencias,
                $this->revisarTraslado($bucket, $esTransito, 'según el mayor', $datos['total'], null)
            );

            if (bccomp($datos['total'], '0', self::ESCALA) === -1) {
                $diferencias[] = new DiferenciaReconciliacion(
                    tipo: TipoDiferenciaReconciliacion::SaldoNegativo,
                    bucket: $bucket,
                    saldoMayor: $datos['total'],
                    saldoProyectado: isset($proyectadas[$clave]) ? $proyectadas[$clave]['cantidad'] : null,
                    movimientos: $datos['movimientos'],
                    detalle: 'el mayor suma negativo: no puede proyectarse (CHECK cantidad >= 0)',
                );

                continue;
            }

            if (! isset($proyectadas[$clave])) {
                $diferencias[] = new DiferenciaReconciliacion(
                    tipo: TipoDiferenciaReconciliacion::Faltante,
                    bucket: $bucket,
                    saldoMayor: $datos['total'],
                    saldoProyectado: null,
                    movimientos: $datos['movimientos'],
                );

                continue;
            }

            if (bccomp($proyectadas[$clave]['cantidad'], $datos['total'], self::ESCALA) !== 0) {
                $diferencias[] = new DiferenciaReconciliacion(
                    tipo: TipoDiferenciaReconciliacion::CantidadDistinta,
                    bucket: $bucket,
                    saldoMayor: $datos['total'],
                    saldoProyectado: $proyectadas[$clave]['cantidad'],
                    movimientos: $datos['movimientos'],
                );
            }
        }

        // Recorrido por la PROYECCIÓN: lo que sobra, que el recorrido anterior no ve.
        foreach ($proyectadas as $clave => $fila) {
            if (isset($mayor[$clave])) {
                continue;
            }

            $bucket = $fila['bucket'];

            $diferencias = array_merge(
                $diferencias,
                $this->revisarTraslado($bucket, $esTransito, 'en la proyección', null, $fila['cantidad'])
            );

            $diferencias[] = new DiferenciaReconciliacion(
                tipo: TipoDiferenciaReconciliacion::Sobrante,
                bucket: $bucket,
                saldoMayor: null,
                saldoProyectado: $fila['cantidad'],
                movimientos: 0,
                detalle: bccomp($fila['cantidad'], '0', self::ESCALA) === 0
                    // REGLA DE LAS FILAS EN CERO: una fila a cero se CONSERVA solo si el
                    // mayor la respalda (bucket que existió y se vació; su historial sigue
                    // ahí). Sin un solo movimiento detrás no hay razón explícita que la
                    // sostenga y se elimina, igual que cualquier otra fila sobrante.
                    ? 'fila en cero sin ningún movimiento que la respalde'
                    : '',
            );
        }

        return new ResultadoReconciliacion(
            diferencias: $diferencias,
            bucketsMayor: count($mayor),
            bucketsProyectados: count($proyectadas),
            huellaMayorAntes: $huellaAntes,
        );
    }

    /**
     * Invariante que ninguna clave foránea puede expresar, porque es condicional
     * al TIPO de la ubicación: 0 fuera de tránsito, >0 solo en tránsito.
     *
     * Se informa pero NO se corrige: arreglarlo significa mover saldo de un
     * bucket a otro, y eso son movimientos nuevos.
     *
     * @param  array<int, bool>  $esTransito
     * @return array<int, DiferenciaReconciliacion>
     */
    private function revisarTraslado(
        BucketInventario $bucket,
        array $esTransito,
        string $donde,
        ?string $saldoMayor,
        ?string $saldoProyectado,
    ): array {
        // Ubicación desconocida (borrada de la tabla): no se puede juzgar la coherencia.
        if (! array_key_exists($bucket->ubicacionId, $esTransito)) {
            return [];
        }

        $transito = $esTransito[$bucket->ubicacionId];

        $detalle = match (true) {
            $transito && $bucket->trasladoId === 0 => "bucket de tránsito sin traslado ({$donde})",
            ! $transito && $bucket->trasladoId > 0 => "ubicación física con traslado #{$bucket->trasladoId} ({$donde})",
            default => null,
        };

        if ($detalle === null) {
            return [];
        }

        return [new DiferenciaReconciliacion(
            tipo: TipoDiferenciaReconciliacion::TrasladoInvalido,
            bucket: $bucket,
            saldoMayor: $saldoMayor,
            saldoProyectado: $saldoProyectado,
            detalle: $detalle,
        )];
    }

    // --- Corrección ---

    /**
     * Aplica SOLO sobre `planta_existencias`. Las diferencias irreparables se
     * saltan: su defecto vive en el mayor y el mayor no se toca.
     *
     * @return array<string, int>
     */
    private function corregir(ResultadoReconciliacion $resultado): array
    {
        $correcciones = ['insertadas' => 0, 'actualizadas' => 0, 'eliminadas' => 0];
        $ahora = now();

        foreach ($resultado->corregibles() as $diferencia) {
            $columnas = $diferencia->bucket->aColumnas();

            switch ($diferencia->tipo) {
                case TipoDiferenciaReconciliacion::Faltante:
                    DB::table('planta_existencias')->insert(array_merge($columnas, [
                        'cantidad' => $diferencia->saldoMayor,
                        'actualizado_en' => $ahora,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ]));
                    $correcciones['insertadas']++;
                    break;

                case TipoDiferenciaReconciliacion::CantidadDistinta:
                    DB::table('planta_existencias')->where($columnas)->update([
                        'cantidad' => $diferencia->saldoMayor,
                        'actualizado_en' => $ahora,
                        'updated_at' => $ahora,
                    ]);
                    $correcciones['actualizadas']++;
                    break;

                case TipoDiferenciaReconciliacion::Sobrante:
                    DB::table('planta_existencias')->where($columnas)->delete();
                    $correcciones['eliminadas']++;
                    break;

                default:
                    // Inalcanzable: corregibles() ya filtró el resto.
                    break;
            }
        }

        return $correcciones;
    }

    /** Deja constancia en Activitylog de lo que cambió y de que el mayor no se tocó. */
    private function auditar(ResultadoReconciliacion $resultado): void
    {
        $propiedades = $resultado->aArreglo();

        // Una base muy corrupta produciría miles de diferencias; el registro de
        // actividad no es el sitio para volcarlas todas.
        if (count($propiedades['diferencias']) > self::MAX_DIFERENCIAS_AUDITADAS) {
            $propiedades['diferencias_omitidas'] = count($propiedades['diferencias']) - self::MAX_DIFERENCIAS_AUDITADAS;
            $propiedades['diferencias'] = array_slice($propiedades['diferencias'], 0, self::MAX_DIFERENCIAS_AUDITADAS);
        }

        activity('planta_reconciliacion')
            ->withProperties($propiedades)
            ->log(sprintf(
                'reconcilió las existencias de planta: %d insertadas, %d actualizadas, %d eliminadas',
                $resultado->correcciones['insertadas'],
                $resultado->correcciones['actualizadas'],
                $resultado->correcciones['eliminadas'],
            ));
    }

    // --- Lectura de datos ---

    /**
     * Filas de la proyección, indexadas por la clave canónica del bucket.
     *
     * Con `$bloquear` toma `FOR UPDATE` sobre TODA la tabla: es el candado que
     * aísla la pasada de los escritores de inventario.
     *
     * @return array<string, array{bucket: BucketInventario, cantidad: string}>
     */
    private function leerProyeccion(bool $bloquear): array
    {
        $consulta = DB::table('planta_existencias')
            ->select(array_merge(['id', 'cantidad'], BucketInventario::COLUMNAS));

        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        $filas = [];

        foreach ($consulta->get() as $fila) {
            $bucket = BucketInventario::desdeFila($fila);

            $filas[$bucket->claveCanonica()] = [
                'bucket' => $bucket,
                'cantidad' => $this->aEscala($fila->cantidad),
            ];
        }

        return $filas;
    }

    /**
     * Suma del mayor agrupada por las CINCO dimensiones.
     *
     * @return array<string, array{bucket: BucketInventario, total: string, movimientos: int}>
     */
    private function sumarMayorPorBucket(): array
    {
        $columnas = implode(', ', BucketInventario::COLUMNAS);

        $filas = DB::table('planta_movimientos')
            ->selectRaw($columnas.', '.$this->expresionSuma().' as total_bruto, COUNT(*) as movimientos')
            ->groupBy(BucketInventario::COLUMNAS)
            ->get();

        $mayor = [];

        foreach ($filas as $fila) {
            $bucket = BucketInventario::desdeFila($fila);

            $mayor[$bucket->claveCanonica()] = [
                'bucket' => $bucket,
                'total' => $this->desdeSuma($fila->total_bruto),
                'movimientos' => (int) $fila->movimientos,
            ];
        }

        return $mayor;
    }

    /**
     * Huella del libro mayor: filas, suma total y mayor id.
     *
     * Las tres juntas detectan cualquier escritura: un INSERT mueve filas y
     * max_id, un DELETE mueve filas, y un UPDATE de importes mueve la suma
     * dejando las otras dos iguales.
     *
     * @return array{filas: int, suma: string, max_id: int}
     */
    public function huellaMayor(): array
    {
        $fila = DB::table('planta_movimientos')
            ->selectRaw(
                'COUNT(*) as filas, COALESCE('.$this->expresionSuma().', 0) as suma, COALESCE(MAX(id), 0) as max_id'
            )
            ->first();

        return [
            'filas' => (int) $fila->filas,
            'suma' => $this->desdeSuma($fila->suma),
            'max_id' => (int) $fila->max_id,
        ];
    }

    /**
     * Huella de la PROYECCIÓN: filas y suma. Es lo único que `--apply` puede
     * cambiar, y tomarla antes y después es lo que permite auditar qué cambió sin
     * volcar la tabla entera al registro de actividad.
     *
     * @return array{filas: int, suma: string}
     */
    public function huellaProyeccion(): array
    {
        $fila = DB::table('planta_existencias')
            ->selectRaw('COUNT(*) as filas, COALESCE('.$this->expresionSuma().', 0) as suma')
            ->first();

        return [
            'filas' => (int) $fila->filas,
            'suma' => $this->desdeSuma($fila->suma),
        ];
    }

    /**
     * Mapa `ubicacion_id => esTransito`. Incluye las eliminadas lógicamente:
     * pueden seguir sosteniendo saldo histórico y su coherencia también cuenta.
     *
     * @return array<int, bool>
     */
    private function ubicacionesDeTransito(): array
    {
        return PlantaUbicacion::withTrashed()
            ->get(['id', 'tipo'])
            ->mapWithKeys(fn (PlantaUbicacion $u) => [$u->id => $u->tipo->esTransito()])
            ->all();
    }

    // --- Aritmética exacta ---

    /**
     * Cómo sumar `cantidad` sin perder exactitud, que depende del motor:
     *
     *   - MySQL suma DECIMAL de forma exacta y devuelve DECIMAL;
     *   - SQLite guarda el decimal con afinidad NUMERIC y su SUM devuelve coma
     *     flotante, donde 0.1 + 0.2 no es 0.3. Se suma en ENTEROS escalados por
     *     10000 —la escala del inventario— y se divide después, que en SQLite es
     *     aritmética entera de 64 bits y por tanto exacta.
     */
    private function expresionSuma(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? 'SUM(CAST(ROUND(cantidad * '.self::FACTOR.') AS INTEGER))'
            : 'SUM(cantidad)';
    }

    /** Devuelve a escala normal lo que {@see expresionSuma()} haya producido. */
    private function desdeSuma(mixed $valor): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return bcdiv((string) (int) $valor, self::FACTOR, self::ESCALA);
        }

        return $this->aEscala($valor);
    }

    /**
     * Normaliza a la escala del inventario sin pasar por float cuando el motor
     * ya entregó una cadena decimal exacta.
     */
    private function aEscala(mixed $valor): string
    {
        if (is_string($valor) && preg_match('/^-?\d+(\.\d+)?$/', $valor) === 1) {
            return bcadd($valor, '0', self::ESCALA);
        }

        return number_format((float) $valor, self::ESCALA, '.', '');
    }
}
