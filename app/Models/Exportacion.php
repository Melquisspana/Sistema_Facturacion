<?php

namespace App\Models;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Exportación / lista de empaque. Documento administrativo (NO es DTE): agrupa
 * items snapshot y genera el Excel de la lista.
 *
 * FLUJO CORTO, dos estados y nada más:
 *
 *   borrador  → editable, se le agregan y quitan productos, se le vinculan FEX.
 *   finalizada → cerrada. Ya no se edita ni se borra; para corregirla hay que
 *                REABRIRLA con motivo, y esa reapertura queda en la auditoría.
 *
 * No hay cola logística, ni aduana, ni tránsito, ni ningún estado intermedio.
 *
 * ESTADOS HEREDADOS. Una instalación anterior pudo dejar listas en 'aprobada'.
 * Esas filas NO se reinterpretan: conservan su valor y se marcan con
 * `requiere_revision`. Mientras la marca esté puesta la lista está CONGELADA —no
 * se edita, no se factura, no se finaliza y no se borra— hasta que un
 * administrador la clasifique con motivo. Tratarlas como borrador editable habría
 * permitido modificar una lista que históricamente estuvo aprobada sin que nadie
 * lo notara. Ver {@see requiereRevision()} y {@see puedeEditarse()}.
 */
class Exportacion extends Model
{
    use HasFactory;
    use LogsActivity;

    public const ESTADO_BORRADOR = 'borrador';

    public const ESTADO_FINALIZADA = 'finalizada';

    protected $table = 'exportaciones';

    protected $fillable = [
        'exportacion_cliente_id',
        'dte_id',
        'cliente_nombre',
        'cliente_direccion',
        'exportador_nombre',
        'exportador_direccion',
        'fecha',
        'factura',
        'fda_reg_number',
        'observaciones',
        'estado',
        'archivada',
        'archivada_en',
        'finalizada_en',
        'finalizada_por_user_id',
        'requiere_revision',
        'revision_motivo',
        'revision_estado_original',
        'revision_resuelta_en',
        'revision_resuelta_por_user_id',
        'revision_resolucion',
    ];

    /** Clasificaciones posibles al resolver una lista heredada. */
    public const RESOLUCIONES = [self::ESTADO_BORRADOR, self::ESTADO_FINALIZADA, 'archivada'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'archivada' => 'boolean',
            'archivada_en' => 'datetime',
            'finalizada_en' => 'datetime',
            'requiere_revision' => 'boolean',
            'revision_resuelta_en' => 'datetime',
        ];
    }

    /**
     * Auditoría de la lista. Existe por una razón concreta del flujo nuevo: una
     * lista finalizada solo se corrige REABRIÉNDOLA, y esa reapertura tiene que
     * dejar rastro de quién y por qué. Sin bitácora, «corregir con acción
     * administrativa auditada» sería lo mismo que editar en silencio.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'estado', 'finalizada_en', 'finalizada_por_user_id', 'dte_id', 'archivada',
                'requiere_revision', 'revision_motivo', 'revision_resolucion', 'revision_resuelta_por_user_id',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('lista_empaque')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó la lista de empaque',
                'updated' => 'actualizó la lista de empaque',
                'deleted' => 'eliminó la lista de empaque',
                default => $evento,
            });
    }

    /**
     * ¿Las observaciones marcan esta lista como una prueba (APITEST/no real)?
     * Solo lectura de texto libre, para poder mostrar "Prueba APITEST" en el
     * badge sin depender de una columna nueva dedicada a ese matiz.
     */
    public function esPruebaApitest(): bool
    {
        return str_contains(mb_strtoupper((string) $this->observaciones), 'APITEST');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExportacionItem::class);
    }

    /** Cliente de exportación de referencia; el encabezado usa el snapshot de texto. */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(ExportacionCliente::class, 'exportacion_cliente_id');
    }

    /** @deprecated Estado del flujo anterior. Se conserva para leer filas históricas. */
    public function estaAprobada(): bool
    {
        return $this->estado === 'aprobada';
    }

    /**
     * PRIMERA Factura de Exportación de la lista. Sigue siendo un `belongsTo` sobre
     * la columna `dte_id` original: no se tocó ni se vació, así que todo consumidor
     * anterior —incluido `Dte::exportacionOrigen()`— funciona exactamente igual.
     * Para el conjunto completo de facturas, ver {@see dtes()}.
     */
    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class);
    }

    /**
     * TODAS las facturas de exportación de esta lista. Un embarque puede facturarse
     * en varias FEX (por contenedor, por orden de compra, por tope de monto), así
     * que la relación real es uno-a-muchos y vive en `exportacion_dte`.
     *
     * `dte_id` se mantiene sincronizado con la PRIMERA de estas facturas (la marcada
     * como `principal`) por compatibilidad; ver {@see VincularFexALista}.
     */
    public function dtes(): BelongsToMany
    {
        return $this->belongsToMany(Dte::class, 'exportacion_dte')
            ->withPivot('principal')
            ->withTimestamps()
            ->orderBy('dtes.id');
    }

    /**
     * True si la lista tiene al menos una Factura de Exportación real vinculada.
     * Comprueba el TIPO además de la existencia: `dte_id` podría en teoría apuntar
     * a un registro que ya no exista o (por error futuro) a otro tipo de documento.
     */
    public function tieneFex(): bool
    {
        return $this->facturas()->isNotEmpty();
    }

    /**
     * Facturas VIVAS: las que respaldan de verdad el embarque.
     *
     * Una FEX rechazada por Hacienda o invalidada después no respalda nada, así que
     * no cuenta para dar por cerrada la lista. Se siguen mostrando —son parte del
     * historial y explican qué pasó— pero {@see puedeFinalizarse()} las ignora.
     *
     * @return Collection<int, Dte>
     */
    public function facturasVigentes(): Collection
    {
        return $this->facturas()->reject(
            fn (Dte $d) => in_array($d->estado, [EstadoDte::Rechazado, EstadoDte::Invalidado], true)
        )->values();
    }

    /**
     * Facturas de exportación REALES de la lista (tipo 11), en orden.
     *
     * Lee de la relación nueva y, si esa colección viene vacía pero la columna
     * histórica `dte_id` sí apunta a algo, cae a ella. Ese respaldo es lo que hace
     * que una instalación cuyo backfill todavía no corrió siga mostrando su factura
     * en vez de decir que no tiene ninguna.
     *
     * @return Collection<int, Dte>
     */
    public function facturas(): Collection
    {
        $vinculadas = $this->relationLoaded('dtes') ? $this->dtes : $this->dtes()->get();
        $facturas = $vinculadas->filter(fn (Dte $d) => $d->tipo_dte === TipoDte::FacturaExportacion)->values();

        if ($facturas->isNotEmpty()) {
            return $facturas;
        }

        $heredada = $this->dte_id !== null ? $this->dte : null;

        return $heredada?->tipo_dte === TipoDte::FacturaExportacion
            ? collect([$heredada])
            : collect();
    }

    /**
     * Números de control de las facturas vinculadas, ya listos para mostrar o
     * imprimir. Un borrador todavía no tiene `numero_control` (se asigna al
     * generar), así que se identifica por su número interno para que la lista nunca
     * muestre un hueco en blanco.
     *
     * @return list<string>
     */
    public function numerosFactura(): array
    {
        return $this->facturas()
            ->map(fn (Dte $d) => filled($d->numero_control) ? (string) $d->numero_control : 'Borrador #'.$d->id)
            ->values()
            ->all();
    }

    /**
     * Texto de la casilla «Factura» del documento.
     *
     * Con facturas vinculadas SIEMPRE gana el dato derivado del DTE: es la razón de
     * ser del cambio, que ese número deje de teclearse. Sin facturas todavía, se
     * muestra el texto libre histórico (`factura`) para no perder lo que alguien
     * escribió antes de que existiera la relación.
     */
    public function textoFactura(): string
    {
        $numeros = $this->numerosFactura();

        return $numeros !== [] ? implode(' · ', $numeros) : trim((string) $this->factura);
    }

    // ------------------------------------------------------------------ estado

    public function estaFinalizada(): bool
    {
        return $this->estado === self::ESTADO_FINALIZADA;
    }

    /**
     * ¿Es un estado que el flujo nuevo no conoce? Solo puede pasar con filas
     * anteriores a la migración (p. ej. 'aprobada'). Se responde por exclusión, no
     * por lista de valores viejos: cualquier estado desconocido futuro cae también
     * acá en vez de romper la pantalla.
     */
    public function estadoHeredado(): bool
    {
        return ! in_array($this->estado, [self::ESTADO_BORRADOR, self::ESTADO_FINALIZADA], true);
    }

    /**
     * Lista heredada PENDIENTE DE CLASIFICAR.
     *
     * Mientras lo esté, la lista está CONGELADA. La versión anterior la trataba como
     * borrador editable «porque es lo que deja trabajar», y eso estaba mal: una lista
     * que históricamente estuvo APROBADA podía modificarse sin que nadie lo notara,
     * que es exactamente lo que la marca existía para evitar. Ante la duda sobre un
     * documento que quizá alguien cerró, lo seguro es no dejar tocarlo.
     */
    public function requiereRevision(): bool
    {
        return (bool) $this->requiere_revision;
    }

    /** Estado con el que la lista llegó del sistema anterior, si se guardó. */
    public function estadoOriginalHeredado(): ?string
    {
        return $this->revision_estado_original ?: ($this->requiereRevision() ? $this->estado : null);
    }

    /**
     * Borrador de trabajo: ni finalizada, ni pendiente de clasificar, ni archivada.
     * Es el único estado en el que la lista se comporta como un documento vivo.
     *
     * ARCHIVADA cuenta como fuera del flujo. Sin esto quedaba un rodeo: clasificar
     * una lista congelada como «archivada» la sacaba de revisión y, como el estado
     * histórico no cambia, volvía a pasar por borrador editable. Dos pasos para
     * acabar editando justo la lista que la marca protegía.
     */
    public function esBorrador(): bool
    {
        return ! $this->estaFinalizada() && ! $this->requiereRevision() && ! $this->archivada;
    }

    /**
     * Una lista puede FINALIZARSE cuando —y solo cuando— está en borrador y tiene al
     * menos una factura VIGENTE. Finalizar no es «marcar como revisada»: es declarar
     * que el embarque ya está facturado y que el documento no se toca más.
     *
     * Con una sola FEX rechazada o invalidada NO se puede: ese documento no respalda
     * el embarque, y cerrar la lista con él dejaría una exportación dada por buena
     * sin factura válida detrás.
     *
     * Deliberadamente NO es automático al vincular la primera FEX: un embarque puede
     * necesitar una segunda factura, y cerrar la lista sola obligaría a reabrirla
     * para algo que era parte normal del trabajo.
     */
    public function puedeFinalizarse(): bool
    {
        return $this->esBorrador() && $this->facturasVigentes()->isNotEmpty();
    }

    /**
     * ¿Se pueden modificar encabezado, productos y vínculos? Es la única pregunta
     * que deben hacer el controlador y las vistas.
     *
     * Dice que NO en los dos casos en que el documento no es un borrador de trabajo:
     * finalizada (se corrige reabriéndola con motivo) y pendiente de clasificar (la
     * resuelve un administrador antes de que nadie la toque).
     */
    public function puedeEditarse(): bool
    {
        return $this->esBorrador();
    }

    /**
     * Motivo por el que la lista está bloqueada, para decírselo al usuario en vez de
     * dejarlo adivinando por qué no hay botones. Null si se puede trabajar en ella.
     */
    public function motivoBloqueo(): ?string
    {
        if ($this->requiereRevision()) {
            return 'Esta lista viene del flujo anterior con el estado «'.$this->estadoOriginalHeredado()
                .'» y está congelada hasta que un administrador la clasifique. No se puede editar, facturar ni finalizar.';
        }

        if ($this->estaFinalizada()) {
            return 'Esta lista está finalizada. Para corregirla hay que reabrirla indicando el motivo.';
        }

        if ($this->archivada) {
            return 'Esta lista está archivada: quedó fuera del flujo de trabajo y no se edita. Si hay que retomarla, duplicala.';
        }

        return null;
    }

    /**
     * Líneas para PREPARAR la factura de exportación, calculadas EN VIVO desde el
     * snapshot de los items (no relee precios del catálogo). Es un ayudante para
     * copiar/armar la factura en Conta Portable: NO es un DTE ni se persiste.
     *
     * @return list<array{descripcion: string, cantidad: int, precio_unitario: float, total: float}>
     */
    public function lineasFactura(): array
    {
        return $this->items
            ->map(fn (ExportacionItem $i) => [
                'descripcion' => $i->descripcionFactura(),
                'cantidad' => (int) $i->cantidad_cajas,
                'precio_unitario' => (float) $i->precio_caja,
                'total' => $i->valorTotal(),
            ])
            ->all();
    }

    public function totalCajas(): int
    {
        return (int) $this->items->sum('cantidad_cajas');
    }

    public function totalUnidades(): int
    {
        return (int) $this->items->sum(fn (ExportacionItem $i) => $i->totalUnidades());
    }

    public function valorTotal(): float
    {
        return round((float) $this->items->sum(fn (ExportacionItem $i) => $i->valorTotal()), 2);
    }

    public function pesoNetoTotalKg(): float
    {
        return round((float) $this->items->sum(fn (ExportacionItem $i) => $i->pesoNetoTotalKg()), 2);
    }

    public function pesoBrutoTotalKg(): float
    {
        return round((float) $this->items->sum(fn (ExportacionItem $i) => $i->pesoBrutoTotalKg()), 2);
    }

    public function pesoNetoTotalLb(): float
    {
        return round((float) $this->items->sum(fn (ExportacionItem $i) => $i->pesoNetoTotalLb()), 2);
    }

    public function pesoBrutoTotalLb(): float
    {
        return round((float) $this->items->sum(fn (ExportacionItem $i) => $i->pesoBrutoTotalLb()), 2);
    }
}
