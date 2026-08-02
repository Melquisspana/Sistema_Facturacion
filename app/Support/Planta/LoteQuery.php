<?php

namespace App\Support\Planta;

use App\Models\Planta\PlantaLote;
use App\Services\Planta\PlantaRecepcionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Consulta de SOLO LECTURA sobre el catálogo de lotes.
 *
 * Vive fuera del controlador por la misma razón que {@see ExistenciaQuery} y
 * {@see MovimientoQuery}: así la consulta se puede probar sin pasar por HTTP y
 * el listado no depende de cómo se arme la pantalla.
 *
 * DOS REGLAS QUE NO SE NEGOCIAN:
 *
 *  1. NO ESCRIBE. Aquí no hay un solo `update`, `insert` ni `delete`. Los lotes
 *     nacen en las recepciones ({@see PlantaRecepcionService}) y la única
 *     escritura de la pantalla —retirar o reactivar— vive en el controlador,
 *     no en la consulta.
 *  2. EL GENÉRICO NO SE LISTA, y no como valor por defecto sino SIEMPRE:
 *     {@see base()} aplica `reales()` sin condición y no existe ningún filtro
 *     que lo desactive. El lote `GEN-<insumo_id>` es un detalle interno del
 *     motor de inventario —existe para que la clave del bucket nunca tenga un
 *     nulo—, no es un lote que nadie haya recibido, y ofrecerlo en una pantalla
 *     de catálogo invitaría a administrarlo. El modelo ya rechaza editarlo y
 *     borrarlo; aquí simplemente no aparece.
 *
 * Un filtro con valor inválido —un id que no es número, un modo de vencimiento
 * inventado, `dias=abc`— se IGNORA en vez de reventar: a esta pantalla se llega
 * con querystrings escritos a mano y compartidos por correo, y un 500 por un
 * parámetro mal copiado no ayuda a nadie.
 */
final class LoteQuery
{
    /** Filtro de estado del lote. Sin valor: se listan activos e inactivos. */
    public const ACTIVO_SI = '1';

    public const ACTIVO_NO = '0';

    /** Filtro de vencimiento. Los lotes sin fecha quedan fuera de ambos modos. */
    public const VENCIMIENTO_VENCIDOS = 'vencidos';

    public const VENCIMIENTO_POR_VENCER = 'por_vencer';

    /** Ventana por defecto de «por vencer», en días. */
    public const DIAS_POR_DEFECTO = 30;

    /** Tope de la ventana. Más allá de un año el filtro deja de acotar nada. */
    private const DIAS_MAXIMO = 365;

    /** @param array<string, mixed> $filtros */
    public function __construct(private readonly array $filtros = []) {}

    public static function desdeRequest(Request $request): self
    {
        return new self($request->query());
    }

    /**
     * Página de lotes, del más reciente al más antiguo.
     *
     * El orden es DETERMINISTA por construcción: `fecha_recepcion` es una fecha
     * de documento y se repite —una recepción de diez líneas crea diez lotes con
     * la misma—, así que ordenar solo por ella dejaría empates que el motor puede
     * resolver distinto en cada carga y haría saltar filas entre páginas. El `id`
     * desempata siempre porque es único y creciente.
     *
     * `withCount('movimientos')` compila una subconsulta agregada dentro de la
     * misma sentencia: dice si el lote ya tiene historial —el dato que importa
     * antes de retirarlo— sin que el coste dependa del número de filas.
     */
    public function paginar(int $porPagina = 25): LengthAwarePaginator
    {
        return $this->base()
            ->with([
                'insumo:id,codigo,nombre,tipo,unidad_base',
                'proveedor:id,nombre',
            ])
            ->withCount('movimientos')
            ->orderByDesc('fecha_recepcion')
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->withQueryString();
    }

    // --- Construcción ---

    /**
     * Los filtros, aplicados sobre los lotes REALES.
     *
     * Se construye de cero en cada llamada para que dos usos de la misma
     * instancia no compartan un builder ya mutado por el anterior.
     */
    private function base(): Builder
    {
        return PlantaLote::query()
            ->reales()
            ->when($this->id('insumo'), fn (Builder $q, int $v) => $q->where('planta_insumo_id', $v))
            ->when($this->id('proveedor'), fn (Builder $q, int $v) => $q->where('planta_proveedor_id', $v))
            ->when($this->busqueda(), fn (Builder $q, string $t) => $this->porTexto($q, $t))
            ->when($this->activo() !== null, fn (Builder $q) => $q->where('activo', $this->activo()))
            ->when($this->vencimiento(), fn (Builder $q, string $m) => $this->porVencimiento($q, $m));
    }

    /**
     * Busca en los DOS códigos a la vez: el interno que asigna el sistema y el
     * que venía impreso en la entrega del proveedor. Quien tiene el saco en la
     * mano lee el segundo; quien mira el historial, el primero.
     *
     * El grupo `where` anidado es obligatorio: sin él, el `orWhere` se ligaría
     * con los filtros anteriores y anularía la exclusión del genérico.
     */
    private function porTexto(Builder $query, string $texto): Builder
    {
        $patron = '%'.$texto.'%';

        return $query->where(fn (Builder $w) => $w
            ->where('codigo_interno', 'like', $patron)
            ->orWhere('codigo_proveedor', 'like', $patron));
    }

    /**
     * Vencimiento, medido contra HOY.
     *
     * Los lotes sin `fecha_vencimiento` quedan fuera de los dos modos, y es lo
     * correcto: un insumo que no vence no está «por vencer» ni «vencido», y
     * colarlo en cualquiera de las dos listas obligaría a descartarlo a mano.
     */
    private function porVencimiento(Builder $query, string $modo): Builder
    {
        $hoy = Carbon::today();

        $query->whereNotNull('fecha_vencimiento');

        if ($modo === self::VENCIMIENTO_VENCIDOS) {
            return $query->whereDate('fecha_vencimiento', '<', $hoy->toDateString());
        }

        return $query
            ->whereDate('fecha_vencimiento', '>=', $hoy->toDateString())
            ->whereDate('fecha_vencimiento', '<=', $hoy->copy()->addDays($this->dias())->toDateString());
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

    public function busqueda(): ?string
    {
        return $this->cadena('q');
    }

    /** true, false, o null cuando no se filtra por estado. */
    public function activo(): ?bool
    {
        return match ($this->cadena('activo')) {
            self::ACTIVO_SI => true,
            self::ACTIVO_NO => false,
            default => null,
        };
    }

    public function vencimiento(): ?string
    {
        $valor = $this->cadena('vencimiento');

        return in_array($valor, [self::VENCIMIENTO_VENCIDOS, self::VENCIMIENTO_POR_VENCER], true)
            ? $valor
            : null;
    }

    /** Ventana de «por vencer». Fuera de rango o ilegible: el valor por defecto. */
    public function dias(): int
    {
        $valor = $this->filtros['dias'] ?? null;

        if (! is_scalar($valor) || ! ctype_digit(ltrim((string) $valor, '+'))) {
            return self::DIAS_POR_DEFECTO;
        }

        $entero = (int) $valor;

        return $entero >= 1 && $entero <= self::DIAS_MAXIMO ? $entero : self::DIAS_POR_DEFECTO;
    }

    private function cadena(string $clave): ?string
    {
        $valor = $this->filtros[$clave] ?? null;

        return is_scalar($valor) && (string) $valor !== '' ? (string) $valor : null;
    }
}
