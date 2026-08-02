<?php

namespace App\Support\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoInsumo;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Models\Planta\PlantaMovimiento;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Consulta de SOLO LECTURA sobre el libro mayor.
 *
 * El mayor es append-only, así que esta clase no tiene ni la posibilidad de
 * escribir: el modelo lanza en `updating` y `deleting`, y aquí no se invoca
 * ninguno de los dos. Leer el historial no lo altera.
 *
 * ORDEN: `fecha_efectiva` descendente y luego `id` descendente. Las dos, no
 * solo la primera. `fecha_efectiva` es la fecha OPERATIVA del documento y se
 * repite muchísimo —una recepción de diez líneas comparte fecha en las diez—,
 * de modo que ordenar solo por ella deja empates que el motor puede resolver de
 * forma distinta en cada consulta y hacer que una fila salte de página entre dos
 * cargas. El `id` desempata siempre porque es único y creciente, y además ordena
 * los movimientos de una misma operación en el orden en que se escribieron.
 *
 * FILTRO POR DOCUMENTO: acota por `documento_type` + `documento_id`, NUNCA por
 * `documento_detalle_id`. Preguntar por un documento es preguntar por todo lo
 * que hizo: sus líneas y los dos lados de cada par compensado. Filtrar por línea
 * mostraría media operación y haría creer que el inventario no cuadra.
 */
final class MovimientoQuery
{
    /** Naturaleza del movimiento: hecho físico frente a corrección contable. */
    public const NATURALEZA_OPERATIVO = 'operativo';

    public const NATURALEZA_REVERSION = 'reversion';

    /** @param array<string, mixed> $filtros */
    public function __construct(private readonly array $filtros = []) {}

    public static function desdeRequest(Request $request): self
    {
        return new self($request->query());
    }

    public function paginar(int $porPagina = 50): LengthAwarePaginator
    {
        return $this->base()
            ->with([
                'insumo:id,codigo,nombre,tipo,unidad_base',
                'lote:id,codigo_interno,es_generico',
                'ubicacion:id,codigo,nombre',
                'user:id,name',
            ])
            ->orderByDesc('fecha_efectiva')
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * Número visible de cada documento de origen de la página, en UNA consulta
     * por tipo de documento (cuatro como mucho), no una por fila.
     *
     * Sin esto la vista tendría que resolver el morph fila a fila, que es el N+1
     * clásico de un historial: cincuenta filas, cincuenta consultas. Con esto el
     * coste no depende del tamaño de la página.
     *
     * @param  iterable<PlantaMovimiento>  $movimientos
     * @return array<string, int> Clave `FQCN#id` => número del documento.
     */
    public static function numerosDeDocumento(iterable $movimientos): array
    {
        $porTipo = [];

        foreach ($movimientos as $movimiento) {
            $modelo = DocumentoOrigen::modelo($movimiento->documento_type);

            if ($modelo === null || $movimiento->documento_id === null) {
                continue;
            }

            $porTipo[$modelo][] = (int) $movimiento->documento_id;
        }

        $numeros = [];

        foreach ($porTipo as $modelo => $ids) {
            /** @var Collection<int, object> $filas */
            $filas = $modelo::query()
                ->whereIn('id', array_unique($ids))
                ->get(['id', 'numero']);

            foreach ($filas as $fila) {
                $numeros[$modelo.'#'.$fila->id] = (int) $fila->numero;
            }
        }

        return $numeros;
    }

    // --- Construcción ---

    private function base(): Builder
    {
        return PlantaMovimiento::query()
            ->when($this->id('insumo'), fn (Builder $q, int $v) => $q->where('planta_insumo_id', $v))
            ->when($this->id('lote'), fn (Builder $q, int $v) => $q->where('planta_lote_id', $v))
            ->when($this->id('ubicacion'), fn (Builder $q, int $v) => $q->where('planta_ubicacion_id', $v))
            ->when($this->id('usuario'), fn (Builder $q, int $v) => $q->where('user_id', $v))
            ->when(
                $this->tipoInsumo(),
                fn (Builder $q, TipoInsumo $t) => $q->whereHas('insumo', fn (Builder $i) => $i->where('tipo', $t->value))
            )
            ->when(
                $this->estado(),
                fn (Builder $q, EstadoDisponibilidad $e) => $q->where('estado', $e->value)
            )
            ->when(
                $this->tipoMovimiento(),
                fn (Builder $q, TipoMovimientoPlanta $t) => $q->where('tipo', $t->value)
            )
            ->when($this->naturaleza(), fn (Builder $q, string $n) => $this->porNaturaleza($q, $n))
            ->when($this->documentoType(), fn (Builder $q, string $type) => $q->where('documento_type', $type))
            ->when($this->id('documento_id'), fn (Builder $q, int $v) => $q->where('documento_id', $v))
            ->when($this->fecha('desde'), fn (Builder $q, string $f) => $q->whereDate('fecha_efectiva', '>=', $f))
            ->when($this->fecha('hasta'), fn (Builder $q, string $f) => $q->whereDate('fecha_efectiva', '<=', $f));
    }

    /**
     * Separa lo que PASÓ de lo que se CORRIGIÓ.
     *
     * La lista de tipos sale del enum, no de un `LIKE 'reversion_%'` escrito
     * aquí: así un tipo nuevo queda clasificado por la misma regla que usa el
     * resto del módulo y no hay dos definiciones de «reversión» que puedan
     * separarse con el tiempo.
     */
    private function porNaturaleza(Builder $query, string $naturaleza): Builder
    {
        $reversiones = array_map(
            fn (TipoMovimientoPlanta $t) => $t->value,
            TipoMovimientoPlanta::reversiones(),
        );

        return $naturaleza === self::NATURALEZA_REVERSION
            ? $query->whereIn('tipo', $reversiones)
            : $query->whereNotIn('tipo', $reversiones);
    }

    // --- Lectura de filtros ---

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
        return TipoInsumo::tryFrom($this->cadena('tipo_insumo') ?? '');
    }

    private function tipoMovimiento(): ?TipoMovimientoPlanta
    {
        return TipoMovimientoPlanta::tryFrom($this->cadena('tipo') ?? '');
    }

    public function naturaleza(): ?string
    {
        $valor = $this->cadena('naturaleza');

        return in_array($valor, [self::NATURALEZA_OPERATIVO, self::NATURALEZA_REVERSION], true)
            ? $valor
            : null;
    }

    /** FQCN del documento a partir de la clave corta del querystring. */
    private function documentoType(): ?string
    {
        return DocumentoOrigen::claseDesdeClave($this->cadena('documento'));
    }

    /** Fecha en formato `Y-m-d`; cualquier otra cosa se ignora. */
    private function fecha(string $clave): ?string
    {
        $valor = $this->cadena($clave);

        if ($valor === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            return null;
        }

        return $valor;
    }

    private function cadena(string $clave): ?string
    {
        $valor = $this->filtros[$clave] ?? null;

        return is_scalar($valor) && (string) $valor !== '' ? (string) $valor : null;
    }
}
