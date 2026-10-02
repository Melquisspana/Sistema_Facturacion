<?php

namespace App\Models\Cobros;

use App\Enums\Cobros\EstadoEventoCobro;
use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\EstadoDte;
use App\Models\Cliente;
use App\Models\Concerns\RecortaTextosAColumna;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Dte\SaldoMontoCcf;
use App\Support\Dinero;
use App\Support\IdentidadPpq;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Seguimiento de UN documento de cobro (CCF o NC) del cliente. Ver la migración para el
 * porqué de cada campo.
 *
 * Los acumulados de pago (`monto_pagado`, `pago_estado`) NO se escriben desde fuera: los
 * recalcula {@see recalcularPago()} a partir de los eventos, que son los que llevan la
 * evidencia. Un modelo que dejara asignar el pago a mano volvería a hacer posible el
 * error que originó todo esto: un archivo parcial que pisa lo que otro ya había informado.
 */
class CobroDocumento extends Model
{
    use HasFactory;
    use RecortaTextosAColumna;

    /** @return array<string, int> */
    public static function largosDeTexto(): array
    {
        return [
            'vinculacion_motivo' => 255,
            'revisar_historico_motivo' => 255,
        ];
    }

    protected $table = 'cobro_documentos';

    protected $fillable = [
        'cliente_id',
        'origen',
        'dte_id',
        'tipo_dte',
        'numero_control',
        'numero_control_norm',
        'codigo_generacion',
        'sello_recepcion',
        'fecha_emision',
        'monto',
        'establecimiento_codigo',
        'punto_venta_codigo',
        'ppq_albaran_id',
        'vinculacion_estado',
        'vinculacion_motivo',
        'vinculacion_candidatos',
        'vinculado_en',
        'vinculado_por',
        'presentacion_estado',
        'cobro_solicitud_id',
        'observaciones',
        'revisar_historico',
        'revisar_historico_motivo',
    ];

    /**
     * Los acumulados de pago arrancan declarados también acá, no solo en la migración: un
     * documento recién creado en memoria tiene que decir lo mismo que su fila.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'origen' => 'dte',
        'vinculacion_estado' => 'sin_albaran',
        'presentacion_estado' => 'sin_presentar',
        'pago_estado' => 'pendiente',
        'monto_pagado' => 0,
        'revisar_historico' => false,
    ];

    protected function casts(): array
    {
        return [
            'origen' => OrigenCobroDocumento::class,
            'fecha_emision' => 'date',
            'monto' => 'decimal:2',
            'vinculacion_estado' => EstadoVinculacionAlbaran::class,
            'vinculacion_candidatos' => 'array',
            'vinculado_en' => 'datetime',
            'presentacion_estado' => EstadoPresentacionCobro::class,
            'pago_estado' => EstadoPagoCobro::class,
            'monto_pagado' => 'decimal:2',
            'fecha_pago' => 'date',
            'revisar_historico' => 'boolean',
        ];
    }

    /**
     * El número normalizado se deriva SIEMPRE del número de control y nunca se teclea: es
     * la llave anti-duplicado, y dejar que alguien la escriba aparte sería dejar que la
     * llave y el dato se contradigan.
     */
    protected static function booted(): void
    {
        static::saving(function (CobroDocumento $doc) {
            $doc->numero_control_norm = IdentidadPpq::normalizar($doc->numero_control);
        });
    }

    // ───────────────────────────────── relaciones ─────────────────────────────────

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class);
    }

    public function albaran(): BelongsTo
    {
        return $this->belongsTo(PpqAlbaran::class, 'ppq_albaran_id');
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(CobroSolicitud::class, 'cobro_solicitud_id');
    }

    public function vinculadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vinculado_por');
    }

    /** Bitácora completa del documento, de lo más reciente a lo más viejo. */
    public function eventos(): HasMany
    {
        return $this->hasMany(CobroEvento::class)->orderByDesc('id');
    }

    public function solicitudItems(): HasMany
    {
        return $this->hasMany(CobroSolicitudItem::class);
    }

    // ────────────────────────────────── consultas ──────────────────────────────────

    /** @param  Builder<CobroDocumento>  $q */
    public function scopeDeCliente(Builder $q, int $clienteId): Builder
    {
        return $q->where('cliente_id', $clienteId);
    }

    /** La invalidación fiscal solo consta en el DTE; los manuales no la heredan. */
    public function scopeInvalidados(Builder $q): Builder
    {
        return $q->whereHas('dte', fn (Builder $d) => $d->where('estado', EstadoDte::Invalidado->value));
    }

    /** Conserva a la vista los invalidados con presentación o pago que requieren revisión. */
    public function scopeSinInvalidadosRetirables(Builder $q): Builder
    {
        return $q->where(fn (Builder $w) => $w
            ->whereDoesntHave('dte', fn (Builder $d) => $d->where('estado', EstadoDte::Invalidado->value))
            ->orWhere('pago_estado', '!=', EstadoPagoCobro::Pendiente->value)
            ->orWhereIn('presentacion_estado', [
                EstadoPresentacionCobro::Preparada->value,
                EstadoPresentacionCobro::Presentada->value,
                EstadoPresentacionCobro::Recibida->value,
            ]));
    }

    /**
     * Lo que todavía se le debe cobrar al cliente: sin pago completo.
     *
     * @param  Builder<CobroDocumento>  $q
     */
    public function scopePendienteDeCobro(Builder $q): Builder
    {
        return $q->whereIn('pago_estado', [
            EstadoPagoCobro::Pendiente->value,
            EstadoPagoCobro::Parcial->value,
            EstadoPagoCobro::Diferencia->value,
        ]);
    }

    /**
     * Lo más viejo primero. No es estética: una factura vieja es la que más riesgo tiene
     * de no cobrarse nunca, y es la que debe aparecer aunque el operador solo mire arriba.
     *
     * @param  Builder<CobroDocumento>  $q
     */
    public function scopePorAntiguedad(Builder $q): Builder
    {
        return $q->orderByRaw('fecha_emision IS NULL')
            ->orderBy('fecha_emision')
            ->orderBy('numero_control')
            ->orderBy('id');
    }

    /** Correlativo sin ceros («DTE-03-…-000000000000214» → «214»); el control completo si no es estándar. */
    public function correlativoCorto(): string
    {
        return preg_match('/(\d+)$/', (string) $this->numero_control, $m)
            ? (ltrim($m[1], '0') ?: '0')
            : (string) $this->numero_control;
    }

    /** Más recientes primero, con desempate estable entre páginas. */
    public function scopePorRecencia(Builder $q): Builder
    {
        return $q->orderByRaw('fecha_emision IS NULL')
            ->orderByDesc('fecha_emision')
            ->orderByDesc('numero_control')
            ->orderByDesc('id');
    }

    // ─────────────────────────────────── estado ───────────────────────────────────

    /** Consulta el estado fiscal sin atribuir invalidación a documentos manuales. */
    public function estaInvalidado(): bool
    {
        if ($this->dte_id === null) {
            return false;
        }

        $this->loadMissing('dte');

        return $this->dte?->estado === EstadoDte::Invalidado;
    }

    public function esNc(): bool
    {
        return $this->tipo_dte === '05';
    }

    /** Días transcurridos desde la emisión. Null si no consta la fecha. */
    public function diasDesdeEmision(): ?int
    {
        return $this->fecha_emision?->startOfDay()->diffInDays(Carbon::today());
    }

    /** Días desde que alguien declaró la presentación. Null si todavía no se presentó. */
    public function diasDesdePresentacion(): ?int
    {
        return $this->solicitud?->presentada_en?->startOfDay()->diffInDays(Carbon::today());
    }

    /** Fecha que el cliente programó para pagar, si la informó. */
    public function fechaProgramadaPago(): ?Carbon
    {
        return $this->solicitud?->fecha_programada_pago;
    }

    /**
     * Días que faltan (negativo: días de atraso) para la fecha programada de pago. Null si
     * el cliente no programó ninguna.
     */
    public function diasParaFechaProgramada(): ?int
    {
        $fecha = $this->fechaProgramadaPago();

        return $fecha === null ? null : Carbon::today()->diffInDays($fecha->startOfDay(), false);
    }

    /**
     * Lo que el cliente tiene que pagar por este documento: su importe menos las notas de
     * crédito aceptadas sobre él. No hay pagos parciales: el cliente paga el CCF MENOS sus
     * NC, y la línea del CCF en el archivo de pagos llega neta (regla del negocio, issue #14).
     * Una NC, o un documento sin DTE propio, se espera completo.
     */
    public function facturadoEfectivo(): string
    {
        $monto = Dinero::redondear($this->monto ?? '0');
        if ($this->esNc() || $this->dte_id === null) {
            return $monto;
        }

        $notas = app(SaldoMontoCcf::class)->descontadoEnCobro([$this->dte_id])[$this->dte_id];

        return Dinero::redondear(Dinero::restar($monto, $notas));
    }

    /** Saldo por cobrar: lo que se espera cobrar menos lo informado. */
    public function saldo(): string
    {
        return Dinero::redondear(Dinero::restar($this->facturadoEfectivo(), $this->monto_pagado ?? '0'));
    }

    /**
     * Pagos registrados que TODAVÍA NO CUENTAN porque nadie decidió si son una repetición
     * de algo ya cobrado o un abono nuevo.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CobroEvento>
     */
    public function pagosEnRevision(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->eventos()->enRevision()->get();
    }

    /** ¿Hay algún importe informado esperando que alguien decida qué es? */
    public function tienePagosEnRevision(): bool
    {
        return $this->eventos()->enRevision()->exists();
    }

    /**
     * Suma de lo que está en revisión. Se muestra al lado del cobrado para que se vea que
     * el saldo puede no ser el que parece: no es dinero cobrado, pero tampoco es aire.
     */
    public function montoEnRevision(): string
    {
        $total = '0';
        foreach ($this->pagosEnRevision() as $evento) {
            $total = Dinero::sumar($total, $evento->monto ?? '0');
        }

        return Dinero::redondear($total);
    }

    /**
     * @param  Builder<CobroDocumento>  $q
     */
    public function scopeConPagosEnRevision(Builder $q): Builder
    {
        return $q->whereHas('eventos', fn (Builder $e) => $e->where('estado', EstadoEventoCobro::EnRevision->value));
    }

    /** Observaciones que el CLIENTE mandó (las nuestras viven en `observaciones`). */
    public function observacionesDelCliente(): Collection
    {
        return $this->eventos->where('tipo', TipoEventoCobro::Observacion);
    }

    /**
     * Qué le falta a este documento para poder presentarse. Vacío = está completo.
     *
     * El formato de solicitud saca sala, número, año, mes y tipo DEL ALBARÁN, así que sin
     * albarán vinculado no hay fila posible. Se dice el motivo en vez de esconder el
     * documento: una factura sin albarán tiene que seguir viéndose, porque es justo la que
     * hay que resolver.
     *
     * @return array<int, string>
     */
    public function faltantesParaPresentar(): array
    {
        $faltan = [];

        if ($this->revisar_historico) {
            $faltan[] = 'resolver la revisión histórica con evidencia antes de presentar';
        }
        if ($this->pago_estado !== EstadoPagoCobro::Pendiente || Dinero::comparar($this->monto_pagado ?? '0', '0') !== 0) {
            $faltan[] = 'revisar el pago registrado: este formato presenta la factura completa, no su saldo';
        }
        if ($this->tienePagosEnRevision()) {
            $faltan[] = 'resolver los pagos en revisión';
        }

        if ($this->vinculacion_estado === EstadoVinculacionAlbaran::Revisar) {
            $faltan[] = 'la vinculación del albarán está en revisión'
                .($this->vinculacion_motivo ? ' ('.$this->vinculacion_motivo.')' : '');

            return $faltan;
        }

        $albaran = $this->albaran;

        if ($albaran === null) {
            $faltan[] = 'el albarán (de ahí salen sala, número, año, mes y tipo)';

            return $faltan;
        }

        if (blank($albaran->sala_codigo)) {
            $faltan[] = 'el código de sala o CD del albarán';
        }
        if (blank($albaran->numero_albaran)) {
            $faltan[] = 'el número del albarán';
        }
        if ($albaran->fecha_albaran === null) {
            $faltan[] = 'la fecha del albarán (de ahí salen el año y el mes)';
        }
        if (blank($albaran->tipo_codigo)) {
            $faltan[] = 'el tipo de albarán';
        }

        return $faltan;
    }

    /** ¿Se puede incluir en una solicitud tal como está? */
    public function estaCompletoParaPresentar(): bool
    {
        return $this->faltantesParaPresentar() === [];
    }

    /**
     * Recalcula el cobro DESDE LOS EVENTOS y lo guarda. Es la única vía por la que cambian
     * `monto_pagado`, `fecha_pago` y `pago_estado`.
     *
     * Derivar en vez de acumular es lo que vuelve la carga idempotente: si un evento ya
     * existía, volver a procesarlo no suma nada porque la suma se rehace entera. Y es lo
     * que permite deshacer un pago sin rastrear qué corrida lo puso.
     *
     * La fecha de pago es la del evento de pago MÁS RECIENTE con fecha propia. Nunca la
     * fecha del documento ni la de carga del archivo: son otras tres cosas.
     */
    public function recalcularPago(): void
    {
        $eventos = $this->eventos()->get();

        $cobrado = '0';
        $fecha = null;

        foreach ($eventos as $evento) {
            // Un evento EN REVISIÓN no suma: está registrado con su evidencia, pero nadie
            // ha decidido todavía si es una repetición o un abono nuevo
            // ({@see \App\Enums\Cobros\EstadoEventoCobro}).
            if (! $evento->cuenta()) {
                continue;
            }

            $cobrado = Dinero::sumar($cobrado, $evento->monto);

            if ($evento->tipo === TipoEventoCobro::Pago && $evento->fecha !== null) {
                $fecha = $fecha === null || $evento->fecha->gt($fecha) ? $evento->fecha : $fecha;
            }
        }

        $cobrado = Dinero::redondear($cobrado);

        $this->forceFill([
            'monto_pagado' => $cobrado,
            'fecha_pago' => $fecha?->toDateString(),
            'pago_estado' => self::estadoDePago($this->facturadoEfectivo(), $cobrado)->value,
        ])->save();

        $this->completarCircuitoPorPago();
    }

    /**
     * Si Calleja ya pagó (o descontó) el documento, es porque se presentó y lo recibió:
     * un pago no llega sin pasar por todo el circuito. Regla del usuario (25/09/2026).
     * Deja la presentación en «Recibida» y levanta la revisión histórica.
     */
    private function completarCircuitoPorPago(): void
    {
        if (Dinero::comparar($this->monto_pagado ?? '0', '0') === 0) {
            return;
        }

        $cambia = $this->presentacion_estado !== EstadoPresentacionCobro::Recibida || $this->revisar_historico;
        if (! $cambia) {
            return;
        }

        // La evidencia es el propio evento de pago del TXT: no se agrega otro evento.
        $this->forceFill([
            'presentacion_estado' => EstadoPresentacionCobro::Recibida->value,
            'revisar_historico' => false,
        ])->save();
    }

    /**
     * El estado de pago que corresponde a un importe facturado (ya descontadas las NC,
     * {@see facturadoEfectivo()}) y uno cobrado.
     *
     * La tolerancia sale de la misma llave que usa PPQ para decir «el monto coincide»
     * (`ppq.diferencia_coincide`): dos centavos de redondeo no son una diferencia que
     * alguien deba investigar, y tener dos tolerancias distintas en el mismo módulo sería
     * tener dos definiciones de «cuadra».
     */
    public static function estadoDePago(string|float|null $facturado, string|float|null $cobrado): EstadoPagoCobro
    {
        $facturado = Dinero::redondear($facturado ?? '0');
        $cobrado = Dinero::redondear($cobrado ?? '0');
        $tolerancia = (string) config('ppq.diferencia_coincide', 0.05);

        if (Dinero::comparar($cobrado, '0') === 0) {
            return EstadoPagoCobro::Pendiente;
        }

        $diferencia = Dinero::restar($facturado, $cobrado);
        $absoluta = Dinero::comparar($diferencia, '0') < 0
            ? Dinero::restar('0', $diferencia)
            : $diferencia;

        if (Dinero::comparar($absoluta, $tolerancia) <= 0) {
            return EstadoPagoCobro::Pagado;
        }

        // Cobrado de MÁS (p. ej. la línea llegó bruta, sin restar una NC): no cuadra.
        if (Dinero::comparar($diferencia, '0') < 0) {
            return EstadoPagoCobro::Diferencia;
        }

        // De menos y sin NC que lo explique: no existen pagos parciales, es un faltante.
        return EstadoPagoCobro::Parcial;
    }
}
