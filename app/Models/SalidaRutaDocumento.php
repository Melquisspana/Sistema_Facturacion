<?php

namespace App\Models;

use App\Enums\EstadoDte;
use App\Enums\MotivoRevisionDocumento;
use App\Services\Rutas\AlbaranLocalizador;
use App\Services\Rutas\LocalizadorNotaCredito;
use App\Services\Rutas\LocalizadorPpq;
use App\Support\OrdenCompra;
use App\Support\Sala;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Un documento (CCF) dentro de una salida de ruta. Ver la migración para el
 * alcance de la tabla y el porqué del candado de unicidad.
 *
 * Dos formas de leer los datos del documento, según de dónde venga:
 *
 *  - con `dte_id` (P002): SIEMPRE se lee del DTE. Aunque haya snapshot guardado,
 *    el DTE manda; una copia vieja mostrando otro monto es peor que no mostrar nada.
 *  - sin `dte_id` (P001 histórico): se lee del snapshot, que es todo lo que hay.
 *
 * Los datos DERIVADOS —si fue entregado (albarán) y si tiene NC— no viven en esta
 * tabla ni se cachean en ella: se resuelven al mostrarlos. Para listas, quien
 * los precarga en bloque es {@see App\Services\Rutas\SeguimientoDocumentos}; si
 * no se precargaron, los métodos de acá los consultan de a uno (correcto pero
 * lento, así que en pantallas con muchas filas hay que precargar).
 */
class SalidaRutaDocumento extends Model
{
    use HasFactory;

    /*
     * SIN LogsActivity a propósito. Un «updated» automático no dice si el papel
     * llegó, si se movió el documento o si alguien lo marcó para NC, y conviviendo
     * con los registros explícitos del servicio dejaría la historia duplicada y a
     * media explicar. Toda la auditoría de esta tabla la escribe
     * {@see App\Services\Rutas\AsignadorDocumentos}, con el acto por su nombre y
     * colgada de la SALIDA —que es lo que se consulta y lo que no desaparece cuando
     * el documento se quita—.
     */

    /** El documento existe en `dtes` (serie viva del sistema propio). */
    public const ORIGEN_P002 = 'p002';

    /** Documento histórico de Conta Portable: solo se conoce su número de control. */
    public const ORIGEN_P001 = 'p001';

    protected $table = 'salida_ruta_documentos';

    protected $fillable = [
        'salida_ruta_id',
        'dte_id',
        'numero_control',
        'numero_orden_compra',
        'cliente_sucursal_id',
        'origen',
        'cliente_nombre',
        'sala_nombre',
        'monto',
        'fecha_documento',
        'asignado_at',
        'asignado_por',
        'asignacion_automatica',
        'documentacion_fisica_recibida_at',
        'documentacion_fisica_recibida_por',
        'requiere_nc',
        'motivo_revision',
        'motivo_revision_nota',
        'bloqueo_asignacion',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_documento' => 'date',
            'asignado_at' => 'datetime',
            'asignacion_automatica' => 'boolean',
            'documentacion_fisica_recibida_at' => 'datetime',
            'requiere_nc' => 'boolean',
            'motivo_revision' => MotivoRevisionDocumento::class,
        ];
    }

    /**
     * Precargas hechas en bloque por el servicio de seguimiento. `false` significa
     * «todavía no se resolvió»; `null`, «se resolvió y no hay».
     */
    private PpqAlbaran|false|null $albaranResuelto = false;

    private Dte|false|null $notaCreditoResuelta = false;

    private PpqItem|false|null $ppqResuelto = false;

    private PpqItem|false|null $ppqNotaCreditoResuelto = false;

    /** @var Collection<int, Dte>|false */
    private Collection|false $notasVinculadasResueltas = false;

    /** @var array<int, PpqItem>|false */
    private array|false $ppqDeNotasResuelto = false;

    // ------------------------------------------------------------- relaciones

    public function salida(): BelongsTo
    {
        return $this->belongsTo(SalidaRuta::class, 'salida_ruta_id');
    }

    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class, 'dte_id');
    }

    public function clienteSucursal(): BelongsTo
    {
        return $this->belongsTo(ClienteSucursal::class);
    }

    public function asignadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_por');
    }

    public function documentacionRecibidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'documentacion_fisica_recibida_por');
    }

    // ---------------------------------------------------------------- lectura

    public function esHistorico(): bool
    {
        return $this->dte_id === null;
    }

    /** Número visible del documento. El control es la identidad de los dos caminos. */
    public function numeroLegible(): string
    {
        return $this->dte?->numero_control ?? $this->numero_control;
    }

    /** Últimos 4 dígitos, que es como la gente nombra los documentos de viva voz. */
    public function ultimos4(): string
    {
        $digitos = preg_replace('/\D/', '', $this->numeroLegible());

        return $digitos === '' ? '—' : substr($digitos, -4);
    }

    public function clienteNombre(): ?string
    {
        return $this->dte?->cliente?->nombre ?? $this->cliente_nombre;
    }

    public function salaNombre(): ?string
    {
        return $this->dte?->clienteSucursal?->nombre
            ?? $this->clienteSucursal?->nombre
            ?? $this->sala_nombre
            ?? Sala::nombre(OrdenCompra::salaDesde($this->orden()));
    }

    public function monto(): ?float
    {
        $valor = $this->dte?->total_pagar ?? $this->monto;

        return $valor === null ? null : (float) $valor;
    }

    public function fecha(): ?Carbon
    {
        return $this->dte?->fecha_emision ?? $this->fecha_documento;
    }

    /** Orden de compra efectiva: la del DTE si existe, si no la guardada. */
    public function orden(): ?string
    {
        return $this->dte?->numero_orden_compra ?? $this->numero_orden_compra;
    }

    // -------------------------------------------------------------- derivados

    /** Precarga en bloque (la hace el servicio de seguimiento). */
    public function precargarAlbaran(?PpqAlbaran $albaran): void
    {
        $this->albaranResuelto = $albaran;
    }

    public function precargarNotaCredito(?Dte $nc): void
    {
        $this->notaCreditoResuelta = $nc;
    }

    /**
     * Albarán relacionado, o null. Si no se precargó, se resuelve para este solo
     * documento con las MISMAS reglas que usa PPQ (nunca con reglas propias).
     */
    public function albaran(): ?PpqAlbaran
    {
        if ($this->albaranResuelto === false) {
            $this->albaranResuelto = app(AlbaranLocalizador::class)->paraUno($this->dte_id, $this->orden());
        }

        return $this->albaranResuelto;
    }

    /**
     * ENTREGADO es una lectura, no un dato guardado: existe albarán = la sala
     * recibió la mercadería. Una sola fuente de verdad, `ppq_albaranes`.
     */
    public function entregado(): bool
    {
        return $this->albaran() !== null;
    }

    public function fechaEntrega(): ?Carbon
    {
        return $this->albaran()?->fecha_albaran;
    }

    /** Nota de crédito REAL relacionada, si la hay. Su estado se lee de ella, no de acá. */
    public function notaCredito(): ?Dte
    {
        if ($this->notaCreditoResuelta === false) {
            $this->notaCreditoResuelta = app(LocalizadorNotaCredito::class)->paraUno($this->dte_id, $this->orden());
        }

        return $this->notaCreditoResuelta;
    }

    /**
     * ¿La nota de crédito de este documento todavía corrige algo?
     *
     * Una NC RECHAZADA por Hacienda nunca llegó a existir, y una INVALIDADA se anuló
     * después: ninguna de las dos descuenta un centavo. Sumarlas al contador de
     * «Notas de crédito» hacía que el número dijera que hay correcciones donde no
     * quedó ninguna.
     *
     * La NC sin efecto NO se esconde: la tarjeta del documento la sigue mostrando en
     * rojo, porque saber que hubo un intento fallido es justamente lo que hace falta
     * para ir a corregirlo. Lo que cambia es solo qué se CUENTA arriba.
     */
    public function notaCreditoVigente(): bool
    {
        $nc = $this->notaCredito();

        if ($nc === null) {
            return false;
        }

        $estado = $nc->estado instanceof EstadoDte
            ? $nc->estado
            : EstadoDte::tryFrom((string) $nc->estado);

        return ! in_array($estado, [EstadoDte::Rechazado, EstadoDte::Invalidado], true);
    }

    public function documentacionFisicaRecibida(): bool
    {
        return $this->documentacion_fisica_recibida_at !== null;
    }

    // ------------------------------------------------------------ cobro / PPQ

    public function precargarPpq(?PpqItem $item): void
    {
        $this->ppqResuelto = $item;
    }

    public function precargarPpqNotaCredito(?PpqItem $item): void
    {
        $this->ppqNotaCreditoResuelto = $item;
    }

    /** @param Collection<int, Dte> $notas */
    public function precargarNotasVinculadas(Collection $notas): void
    {
        $this->notasVinculadasResueltas = $notas;
    }

    /** @param array<int, PpqItem> $porNotaId */
    public function precargarPpqDeNotas(array $porNotaId): void
    {
        $this->ppqDeNotasResuelto = $porNotaId;
    }

    /**
     * Renglón de PPQ de este documento, o null si todavía no entró a ningún lote.
     * Se resuelve al consultarlo: nada de esto se guarda en esta tabla.
     */
    public function ppqItem(): ?PpqItem
    {
        if ($this->ppqResuelto === false) {
            $this->ppqResuelto = app(LocalizadorPpq::class)->paraUno($this->dte_id, $this->numeroLegible());
        }

        return $this->ppqResuelto;
    }

    /** Renglón de PPQ de la NC de este documento (si la NC existe y entró a un lote). */
    public function ppqNotaCredito(): ?PpqItem
    {
        if ($this->ppqNotaCreditoResuelto === false) {
            $nc = $this->notaCredito();
            $this->ppqNotaCreditoResuelto = $nc === null
                ? null
                : app(LocalizadorPpq::class)->paraUno($nc->id, $nc->numero_control);
        }

        return $this->ppqNotaCreditoResuelto;
    }

    /** ¿El documento ya fue ingresado a un lote de cobro? */
    public function enPpq(): bool
    {
        return $this->ppqItem() !== null;
    }

    /**
     * PAGADO en el sentido estricto de PPQ: el documento apareció en el TXT de pagos
     * de Calleja. Estar dentro de un lote NO alcanza, y el estado del LOTE tampoco
     * —un lote marcado «pagado» a mano es una etiqueta de gestión del paquete, no la
     * prueba de que este renglón se cobró—.
     */
    public function pagado(): bool
    {
        return $this->ppqItem()?->conciliacion_estado === 'pagado';
    }

    /** NC descontada por Calleja según el TXT. Es el equivalente de «pagado» para una NC. */
    public function ncAplicada(): bool
    {
        return $this->ppqItem()?->conciliacion_estado === 'aplicada';
    }

    /** Fecha que reporta el TXT de Calleja. Null mientras no esté conciliado. */
    public function fechaPago(): ?Carbon
    {
        return $this->ppqItem()?->fecha_pago;
    }

    /** Monto que reporta el TXT (no el del documento). Null mientras no esté conciliado. */
    public function montoPagado(): ?float
    {
        $monto = $this->ppqItem()?->monto_pagado;

        return $monto === null ? null : (float) $monto;
    }

    // ------------------------------------------------------------------ dinero

    /**
     * TODAS las notas de crédito de este documento por vínculo FISCAL.
     *
     * El dinero se calcula sobre esta colección y no sobre {@see notaCredito()}, que
     * devuelve una sola —la más reciente— y sirve para la insignia de la pantalla.
     * Son dos preguntas distintas: «¿hubo corrección?» se contesta con la última,
     * pero «¿cuánto descuenta?» se contesta sumando todas, porque una NC aceptada no
     * deja de descontar porque después alguien haya generado un borrador.
     *
     * Solo `dte_relacionado_id`, nunca la orden de compra: una OC ampara varios CCF y
     * por ahí la misma NC se restaría de dos documentos. Un histórico P001 no tiene
     * vínculo fiscal posible, así que su colección va siempre vacía: se prefiere
     * decir «no hay NC atribuible» antes que adivinar.
     *
     * @return Collection<int, Dte>
     */
    public function notasCreditoVinculadas(): Collection
    {
        if ($this->dte_id === null) {
            return collect();
        }

        if ($this->notasVinculadasResueltas === false) {
            $this->notasVinculadasResueltas = collect(
                app(LocalizadorNotaCredito::class)->todasVinculadas([$this->dte_id])[$this->dte_id] ?? []
            );
        }

        return $this->notasVinculadasResueltas;
    }

    /** Renglón PPQ de una NC concreta. */
    private function ppqDeNota(Dte $nc): ?PpqItem
    {
        if ($this->ppqDeNotasResuelto === false) {
            $this->ppqDeNotasResuelto = [];
            foreach ($this->notasCreditoVinculadas() as $nota) {
                $item = app(LocalizadorPpq::class)->paraUno($nota->id, $nota->numero_control);
                if ($item !== null) {
                    $this->ppqDeNotasResuelto[$nota->id] = $item;
                }
            }
        }

        return $this->ppqDeNotasResuelto[$nc->id] ?? null;
    }

    /**
     * Los renglones PPQ de las NC que Calleja YA descontó.
     *
     * @return Collection<int, PpqItem>
     */
    private function itemsNcAplicadas(): Collection
    {
        return $this->notasCreditoVinculadas()
            ->map(fn (Dte $nc) => $this->ppqDeNota($nc))
            ->filter(fn (?PpqItem $item) => $item?->conciliacion_estado === 'aplicada')
            ->values();
    }

    /** Lo facturado. Null = desconocido, que NO es lo mismo que cero. */
    public function montoFacturado(): ?float
    {
        return $this->monto();
    }

    /** Lo efectivamente cobrado según el TXT de Calleja. Null si todavía no se cobró. */
    public function montoCobrado(): ?float
    {
        if (! $this->pagado()) {
            return null;
        }

        return $this->montoPagado();
    }

    /**
     * NC ya DESCONTADAS por Calleja, sumadas: el monto que reporta el TXT y no el
     * fiscal, porque para el saldo lo que cuenta es lo que realmente se descontó.
     */
    public function montoNcAplicada(): ?float
    {
        $items = $this->itemsNcAplicadas();

        if ($items->isEmpty()) {
            return null;
        }

        return round($items->reduce(fn (float $t, PpqItem $i) => $t + (float) $i->monto_pagado, 0.0), 2);
    }

    /**
     * NC emitidas y ACEPTADAS que todavía no se descontaron, sumadas. Reducen lo que
     * esperamos cobrar aunque Calleja no las haya aplicado.
     *
     * El candado contra el doble descuento es POR NOTA, no por documento: cada NC se
     * pregunta primero si ya está aplicada y, si lo está, no vuelve a contar acá. Un
     * documento con una NC aplicada y otra aceptada suma una en cada columna, que es
     * lo correcto; ninguna suma en las dos.
     *
     * Solo `aceptado` descuenta. Borrador, generada, rechazada e invalidada no: la
     * primera no existe, la segunda todavía no fue aceptada por Hacienda y las dos
     * últimas ya no corrigen nada.
     */
    public function montoNcAceptadaPorAplicar(): ?float
    {
        $total = null;

        foreach ($this->notasCreditoVinculadas() as $nc) {
            if ($this->ppqDeNota($nc)?->conciliacion_estado === 'aplicada') {
                continue; // ya cuenta como aplicada
            }

            $estado = $nc->estado instanceof EstadoDte ? $nc->estado : EstadoDte::tryFrom((string) $nc->estado);

            if ($estado === EstadoDte::Aceptado) {
                $total = ($total ?? 0.0) + (float) $nc->total_pagar;
            }
        }

        return $total === null ? null : round($total, 2);
    }

    /**
     * Lo que falta cobrar de este documento.
     *
     * Devuelve NULL —desconocido— y nunca un cero de relleno cuando falta alguna
     * pieza: sin monto facturado, o cobrado/descontado sin importe registrado. Un
     * saldo inventado se suma al total y ahí ya no hay forma de notar que era humo.
     */
    public function saldoPendiente(): ?float
    {
        $facturado = $this->montoFacturado();

        if ($facturado === null) {
            return null;
        }

        // Está pagado (o hay una NC aplicada) pero sin importe: no se puede restar.
        if ($this->pagado() && $this->montoPagado() === null) {
            return null;
        }
        if ($this->itemsNcAplicadas()->contains(fn (PpqItem $i) => $i->monto_pagado === null)) {
            return null;
        }

        return round(
            $facturado
            - ($this->montoCobrado() ?? 0)
            - ($this->montoNcAplicada() ?? 0)
            - ($this->montoNcAceptadaPorAplicar() ?? 0),
            2,
        );
    }

    /** ¿Se pudo calcular el saldo? Lo contrario se declara, no se rellena. */
    public function saldoConocido(): bool
    {
        return $this->saldoPendiente() !== null;
    }

    /** ¿Queda algo por cobrar? Un saldo desconocido NO se da por cobrado. */
    public function tieneSaldo(): bool
    {
        $saldo = $this->saldoPendiente();

        return $saldo !== null && $saldo > 0;
    }

    /**
     * Días desde la EMISIÓN del documento. Null si no se conoce la fecha, que en los
     * históricos P001 pasa: el snapshot es opcional y no se inventa.
     */
    public function diasAntiguedad(): ?int
    {
        $fecha = $this->fecha();

        return $fecha === null ? null : (int) $fecha->copy()->startOfDay()->diffInDays(Carbon::today());
    }

    /**
     * Diferencia entre lo facturado y lo pagado, solo cuando supera la tolerancia de
     * redondeo que ya usa PPQ. Null si coinciden, si no hay pago o si no hay monto.
     */
    public function diferenciaPago(): ?float
    {
        $pagado = $this->montoPagado();
        $documento = $this->monto();

        if ($pagado === null || $documento === null) {
            return null;
        }

        $diferencia = round($documento - $pagado, 2);

        return abs($diferencia) > (float) config('ppq.diferencia_coincide', 0.05) ? $diferencia : null;
    }

    /** ¿Esta fila es la asignación VIGENTE del documento? (ver el candado en la migración) */
    public function esAsignacionVigente(): bool
    {
        return $this->bloqueo_asignacion !== null;
    }

    // ----------------------------------------------------------------- scopes

    /** Filas que hoy retienen el documento (salida abierta). */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->whereNotNull('bloqueo_asignacion');
    }

    public function scopeConDocumentacionFisica(Builder $query): Builder
    {
        return $query->whereNotNull('documentacion_fisica_recibida_at');
    }
}
