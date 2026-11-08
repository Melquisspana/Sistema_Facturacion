<?php

namespace App\Models;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Services\Ppq\SalaSucursalResolver;
use App\Support\OrdenCompra;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Schema;

/**
 * Orden de compra de Calleja que llegó por correo (etiqueta «Ordenes» de Gmail), con
 * su PDF guardado en el disco privado. La escribe solo `ordenes:sincronizar`.
 *
 * «Facturada» no se guarda: se calcula contra los CCF vigentes cuyo
 * `numero_orden_compra`, sin espacios, guiones ni puntos, es igual a `numero`. Así un
 * CCF creado, invalidado o borrado después cambia la respuesta sin tocar esta tabla.
 *
 * @property string|null $numero
 * @property string|null $sala_codigo
 * @property int|null $cliente_sucursal_id
 * @property string $estado
 */
class OrdenCompraRecibida extends Model
{
    protected $table = 'ordenes_compra_recibidas';

    public const LEIDA = 'leida';

    public const SIN_LEER = 'sin_leer';

    /** Carpeta del disco privado donde se guardan los PDF (`{carpeta}/{sha256}.pdf`). */
    public const CARPETA_PDF = 'ppq/ordenes-compra';

    protected $fillable = [
        'numero', 'sala_codigo', 'cliente_sucursal_id', 'fecha_creacion', 'fecha_despacho',
        'total', 'lineas', 'pdf_path', 'pdf_hash', 'gmail_message_id', 'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_creacion' => 'date',
            'fecha_despacho' => 'date',
            'total' => 'decimal:2',
            'lineas' => 'array',
        ];
    }

    private static ?bool $instalada = null;

    public function clienteSucursal(): BelongsTo
    {
        return $this->belongsTo(ClienteSucursal::class);
    }

    /**
     * ¿Está migrada la tabla? El formulario del CCF y su ficha la consultan: entre
     * desplegar el código y correr la migración no pueden caerse por esto.
     */
    public static function instalada(): bool
    {
        return self::$instalada ??= Schema::hasTable('ordenes_compra_recibidas');
    }

    /** Olvida lo anterior (pruebas). */
    public static function olvidarInstalacion(): void
    {
        self::$instalada = null;
    }

    /** El número de OC del CCF, normalizado en SQL igual que OrdenCompra::normalizar() para los casos reales. */
    private static function ocDelDte(): string
    {
        return "REPLACE(REPLACE(REPLACE(TRIM(dtes.numero_orden_compra), ' ', ''), '-', ''), '.', '')";
    }

    /** CCF que cuentan como «facturada»: no invalidados ni borrados (los borradores sí cuentan). */
    private static function ccfVigentes(QueryBuilder $q): QueryBuilder
    {
        return $q->from('dtes')
            ->where('dtes.tipo_dte', TipoDte::CreditoFiscal->value)
            ->where('dtes.estado', '!=', EstadoDte::Invalidado->value)
            ->whereNull('dtes.deleted_at')
            ->whereNotNull('dtes.numero_orden_compra');
    }

    /** Órdenes leídas que todavía no tienen ningún CCF vigente con su número. */
    public function scopeSinFacturar(Builder $q): Builder
    {
        return $q->whereNotNull('numero')->whereNotExists(fn (QueryBuilder $s) => self::ccfVigentes($s->selectRaw('1'))
            ->whereRaw(self::ocDelDte().' = ordenes_compra_recibidas.numero'));
    }

    /**
     * CCF vigente (el más reciente) de cada número de orden.
     *
     * @param  array<int, string>  $numeros
     * @return array<string, Dte>
     */
    public static function ccfPorNumero(array $numeros): array
    {
        $numeros = array_values(array_unique(array_filter($numeros)));
        if ($numeros === []) {
            return [];
        }

        return Dte::query()
            ->where('tipo_dte', TipoDte::CreditoFiscal->value)
            ->where('estado', '!=', EstadoDte::Invalidado->value)
            ->whereRaw(self::ocDelDte().' in ('.implode(',', array_fill(0, count($numeros), '?')).')', $numeros)
            ->orderBy('id')
            ->get(['id', 'numero_control', 'numero_interno', 'numero_orden_compra', 'estado', 'fecha_emision'])
            ->keyBy(fn (Dte $d) => OrdenCompra::normalizar($d->numero_orden_compra))
            ->all();
    }

    /** La orden recibida con el número de OC de este CCF, si llegó por correo. */
    public static function deCcf(Dte $dte): ?self
    {
        $numero = OrdenCompra::normalizar($dte->numero_orden_compra);
        if ($dte->tipo_dte !== TipoDte::CreditoFiscal || $numero === '' || ! self::instalada()) {
            return null;
        }

        return self::query()->where('numero', $numero)->first();
    }

    /**
     * Órdenes leídas y sin CCF, agrupadas por la sala (sucursal) a la que van, de la más
     * nueva a la más vieja. Para ofrecerlas en el formulario del CCF.
     *
     * La sala sale de `cliente_sucursal_id`; si al llegar la orden la sala todavía no
     * tenía su código cargado, se vuelve a buscar ahora por `sala_codigo`.
     *
     * @return array<int, list<array{numero: string, detalle: string}>>
     */
    public static function pendientesPorSucursal(): array
    {
        if (! self::instalada()) {
            return [];
        }

        $resolver = app(SalaSucursalResolver::class);
        $porSucursal = [];

        $pendientes = self::query()
            ->sinFacturar()
            ->where('estado', self::LEIDA)
            ->orderByDesc('fecha_creacion')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'numero', 'sala_codigo', 'cliente_sucursal_id', 'fecha_creacion', 'fecha_despacho', 'total']);

        foreach ($pendientes as $orden) {
            $sucursalId = $orden->cliente_sucursal_id ?? $resolver->resolver($orden->sala_codigo)['cliente_sucursal_id'];
            if ($sucursalId === null) {
                continue;
            }

            $porSucursal[$sucursalId][] = [
                'numero' => (string) $orden->numero,
                'detalle' => $orden->detalle(),
            ];
        }

        return $porSucursal;
    }

    /** «Creada 05/11/2026 · despacho 07/11/2026 · $10.40», para la lista del formulario. */
    public function detalle(): string
    {
        return implode(' · ', array_filter([
            $this->fecha_creacion ? 'Creada '.$this->fecha_creacion->format('d/m/Y') : null,
            $this->fecha_despacho ? 'despacho '.$this->fecha_despacho->format('d/m/Y') : null,
            $this->total !== null ? '$'.number_format((float) $this->total, 2) : null,
        ]));
    }
}
