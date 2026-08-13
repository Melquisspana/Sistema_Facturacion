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
