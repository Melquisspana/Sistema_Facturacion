<?php

namespace App\Support\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\EstadoTrasladoPlanta;
use App\Enums\Planta\TipoInsumo;
use App\Enums\Planta\UnidadBase;
use App\Models\Planta\PlantaExistencia;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consulta de SOLO LECTURA sobre el saldo proyectado.
 *
 * Vive fuera del controlador por dos razones concretas: los mismos filtros
 * alimentan el listado paginado y los totales por estado —y tienen que ser
 * exactamente los mismos, o el total no describiría la tabla que hay debajo—, y
 * porque así se puede probar la consulta sin pasar por HTTP.
 *
 * TRES REGLAS QUE NO SE NEGOCIAN:
 *
 *  1. NO ESCRIBE. No hay un solo `update`, `insert` ni `delete` aquí, y el
 *     modelo los rechazaría igualmente. Leer el inventario jamás lo modifica.
 *  2. LOS ESTADOS NO SE SUMAN ENTRE SÍ. `disponible`, `retenido` y `rechazado`
 *     son dimensiones distintas del bucket, no fases de la misma cantidad; los
 *     dos métodos de totales agrupan por estado y ninguno ofrece un gran total,
 *     porque un número que mezclara los tres no significaría nada operativo.
 *  3. LAS UNIDADES TAMPOCO SE SUMAN ENTRE SÍ. `cantidad` guarda libras y
 *     unidades en la misma columna, así que 700 libras y 400 bolsas no hacen
 *     «1100» de nada. {@see totalesPorUnidadYEstado()} separa también por
 *     `unidad_base` y es el que debe usar cualquier pantalla que abarque varios
 *     insumos. {@see totalesPorEstado()} solo es correcto cuando el conjunto ya
 *     está acotado a UNA unidad —el caso de la ficha de un lote, que pertenece a
 *     un único insumo— y se conserva para ese uso.
 *  4. NADA PASA POR FLOAT. Los totales se suman en SQL con
 *     {@see SumaInventario} y salen como cadena decimal de 4 posiciones.
 *
 * Un filtro con valor inválido —un estado que no existe, un id que no es
 * número— se IGNORA en vez de reventar: esto es una pantalla de consulta a la
 * que se llega con querystrings escritos a mano y compartidos por correo, y un
 * 500 por un parámetro mal copiado no ayuda a nadie.
 */
final class ExistenciaQuery
{
    /** Filtro de tránsito: solo lo que viaja / solo lo que está quieto. */
    public const TRANSITO_SI = 'si';

    public const TRANSITO_NO = 'no';

    /** Filtro de saldo: por defecto se ocultan los buckets vacíos. */
    public const SALDO_CON = 'con';

    public const SALDO_TODOS = 'todos';

    /** @param array<string, mixed> $filtros */
    public function __construct(private readonly array $filtros = []) {}

    public static function desdeRequest(Request $request): self
    {
        return new self($request->query());
    }

    /**
     * Página de saldos, en el orden canónico del bucket.
     *
     * El orden es DETERMINISTA por construcción: las cinco dimensiones forman el
     * único de la tabla, así que ordenar por ellas no deja empates posibles y
     * dos cargas de la misma página devuelven las mismas filas. Ordenar por
     * cantidad, que es lo que pediría la intuición, sí los dejaría.
     */
    public function paginar(int $porPagina = 25): LengthAwarePaginator
    {
        return $this->base()
            ->with([
                'insumo:id,codigo,nombre,tipo,unidad_base,stock_minimo',
                'lote:id,codigo_interno,es_generico,fecha_vencimiento',
                'ubicacion:id,codigo,nombre',
                'traslado:id,numero,estado',
            ])
            ->orderBy('planta_insumo_id')
            ->orderBy('planta_lote_id')
            ->orderBy('planta_ubicacion_id')
            ->orderBy('estado')
            ->orderBy('planta_traslado_id')
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * Totales del conjunto filtrado, SEPARADOS por estado y calculados en SQL
     * sobre TODAS las filas, no solo sobre la página visible.
     *
     * Devuelve una entrada por estado presente. Deliberadamente NO devuelve la
     * suma de los tres: ver la regla 2 de la cabecera de esta clase.
     *
     * SOLO PARA CONJUNTOS DE UNA SOLA UNIDAD. No separa por `unidad_base`, así
     * que sumaría libras con unidades si el filtro abarcara insumos de ambas.
     * Su uso legítimo es la ficha de un lote —que pertenece a un único insumo y
     * por tanto a una única unidad—. Para cualquier pantalla que abarque varios
     * insumos, {@see totalesPorUnidadYEstado()}.
     *
     * @return array<int, array{estado: EstadoDisponibilidad, total: string, buckets: int}>
     */
    public function totalesPorEstado(): array
    {
        $filas = $this->base()
            ->getQuery()
            ->select('estado')
            ->selectRaw(SumaInventario::expresion('cantidad').' as total')
            ->selectRaw('COUNT(*) as buckets')
            ->groupBy('estado')
            ->get();

        $porEstado = [];

        foreach ($filas as $fila) {
            $porEstado[(string) $fila->estado] = [
                'total' => SumaInventario::desdeSuma($fila->total),
                'buckets' => (int) $fila->buckets,
            ];
        }

        // Se recorre el enum, no el resultado, para que el orden de las tarjetas
        // sea siempre el mismo aunque cambie el orden que devuelva el motor.
        $totales = [];

        foreach (EstadoDisponibilidad::cases() as $estado) {
            if (! isset($porEstado[$estado->value])) {
                continue;
            }

            $totales[] = [
                'estado' => $estado,
                'total' => $porEstado[$estado->value]['total'],
                'buckets' => $porEstado[$estado->value]['buckets'],
            ];
        }

        return $totales;
    }

    /**
     * Totales del conjunto filtrado, separados por UNIDAD BASE y por estado.
     *
     * Es lo que muestra la pantalla general de Existencias, y la razón por la que
     * existe es concreta: `planta_existencias.cantidad` guarda libras y unidades
     * en la misma columna, así que agrupar solo por estado —lo que hace
     * {@see totalesPorEstado()}— produce cifras como «1100» al juntar 700 libras
     * con 400 bolsas. Ese número no es nada: ni libras, ni unidades, ni una
     * cantidad física que alguien pueda ir a contar a la bodega.
     *
     * DOS SEPARACIONES, NO UNA. Los estados siguen sin sumarse entre sí (regla 2
     * de la cabecera) y ahora tampoco se suman las unidades. Por eso NO se
     * devuelve un total por unidad que junte disponible + retenido + rechazado:
     * sería matemáticamente válido y operativamente falso, porque solo el
     * disponible puede moverse.
     *
     * LA UNIDAD SALE DE `planta_insumos`, no de aquí: `planta_existencias` no
     * guarda `unidad_base` —solo la tiene el mayor, congelada al escribir cada
     * movimiento—, así que hace falta el JOIN. Las columnas van CALIFICADAS a
     * propósito: con las dos tablas en juego, `activo` existe en ambas y dejar
     * nombres sueltos sería sembrar una ambigüedad para el primero que añada un
     * filtro.
     *
     * UNA SOLA CONSULTA, cueste lo que cueste el volumen: un `GROUP BY` de dos
     * columnas, con la suma exacta de {@see SumaInventario} —enteros escalados en
     * SQLite, decimal nativo en MySQL— y `COUNT(*)` para los buckets. Nada pasa
     * por float y no se escribe absolutamente nada.
     *
     * Hereda los filtros de {@see base()}, el mismo constructor que usa
     * {@see paginar()}: el resumen describe exactamente la tabla que hay debajo.
     *
     * @return array<int, array{unidad: UnidadBase, estado: EstadoDisponibilidad, total: string, buckets: int}>
     */
    public function totalesPorUnidadYEstado(): array
    {
        $filas = $this->base()
            ->getQuery()
            ->join('planta_insumos', 'planta_insumos.id', '=', 'planta_existencias.planta_insumo_id')
            ->select('planta_insumos.unidad_base', 'planta_existencias.estado')
            ->selectRaw(SumaInventario::expresion('planta_existencias.cantidad').' as total')
            ->selectRaw('COUNT(*) as buckets')
            ->groupBy('planta_insumos.unidad_base', 'planta_existencias.estado')
            ->get();

        $porClave = [];

        foreach ($filas as $fila) {
            $porClave[(string) $fila->unidad_base.'|'.(string) $fila->estado] = [
                'total' => SumaInventario::desdeSuma($fila->total),
                'buckets' => (int) $fila->buckets,
            ];
        }

        // Se recorren los ENUMS, no el resultado del motor: así el orden de las
        // tarjetas es siempre el mismo y no depende de cómo agrupe la base.
        $totales = [];

        foreach (UnidadBase::cases() as $unidad) {
            foreach (EstadoDisponibilidad::cases() as $estado) {
                $clave = $unidad->value.'|'.$estado->value;

                if (! isset($porClave[$clave])) {
                    continue;
                }

                $totales[] = [
                    'unidad' => $unidad,
                    'estado' => $estado,
                    'total' => $porClave[$clave]['total'],
                    'buckets' => $porClave[$clave]['buckets'],
                ];
            }
        }

        return $totales;
    }

    // --- Construcción ---

    /**
     * Los filtros, aplicados. Se construye de cero en cada llamada para que el
     * listado y los totales no compartan un builder ya mutado por el otro.
     */
    private function base(): Builder
    {
        return PlantaExistencia::query()
            ->when($this->id('insumo'), fn (Builder $q, int $v) => $q->where('planta_insumo_id', $v))
            ->when($this->id('lote'), fn (Builder $q, int $v) => $q->where('planta_lote_id', $v))
            ->when($this->id('ubicacion'), fn (Builder $q, int $v) => $q->where('planta_ubicacion_id', $v))
            ->when(
                $this->tipoInsumo(),
                fn (Builder $q, TipoInsumo $t) => $q->whereHas('insumo', fn (Builder $i) => $i->where('tipo', $t->value))
            )
            ->when(
                $this->estado(),
                fn (Builder $q, EstadoDisponibilidad $e) => $q->where('estado', $e->value)
            )
            ->when($this->transito() === self::TRANSITO_SI, fn (Builder $q) => $this->soloEnTransito($q))
            ->when($this->transito() === self::TRANSITO_NO, fn (Builder $q) => $q->where('planta_traslado_id', 0))
            ->when($this->saldo() === self::SALDO_CON, fn (Builder $q) => $q->conSaldo());
    }

    /**
     * «En tránsito» no es «tiene un id de traslado»: es saldo que está viajando
     * AHORA, es decir, atado a un traslado que sigue en estado `enviado`.
     *
     * La diferencia importa. Un traslado ya recibido o reversado dejó su bucket
     * de tránsito en cero, pero la fila sobrevive con saldo 0 y el id intacto.
     * Filtrar solo por `planta_traslado_id > 0` la incluiría y la pantalla
     * afirmaría que hay mercancía en camino que llegó hace semanas.
     */
    private function soloEnTransito(Builder $query): Builder
    {
        return $query
            ->where('planta_traslado_id', '>', 0)
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('planta_traslados')
                    ->whereColumn('planta_traslados.id', 'planta_existencias.planta_traslado_id')
                    ->where('planta_traslados.estado', EstadoTrasladoPlanta::Enviado->value);
            });
    }

    // --- Lectura de filtros ---

    /** Id positivo, o null si el parámetro falta o no es un número usable. */
    private function id(string $clave): ?int
    {
        $valor = $this->filtros[$clave] ?? null;

        if (! is_scalar($valor) || ! ctype_digit(ltrim((string) $valor, '+'))) {
            return null;
        }

        $entero = (int) $valor;

        return $entero > 0 ? $entero : null;
    }

    private function estado(): ?EstadoDisponibilidad
    {
        return EstadoDisponibilidad::tryFrom($this->cadena('estado') ?? '');
    }

    private function tipoInsumo(): ?TipoInsumo
    {
        return TipoInsumo::tryFrom($this->cadena('tipo') ?? '');
    }

    public function transito(): ?string
    {
        return in_array($this->cadena('transito'), [self::TRANSITO_SI, self::TRANSITO_NO], true)
            ? $this->cadena('transito')
            : null;
    }

    /** Con saldo por defecto: un listado lleno de ceros esconde lo que sí hay. */
    public function saldo(): string
    {
        return $this->cadena('saldo') === self::SALDO_TODOS ? self::SALDO_TODOS : self::SALDO_CON;
    }

    private function cadena(string $clave): ?string
    {
        $valor = $this->filtros[$clave] ?? null;

        return is_scalar($valor) && (string) $valor !== '' ? (string) $valor : null;
    }
}
