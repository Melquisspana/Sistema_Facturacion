<?php

namespace App\Models;

use App\Enums\AmbienteHacienda;
use App\Enums\CondicionPago;
use App\Enums\EstadoDte;
use App\Enums\MotivoAnulacion;
use App\Enums\TipoDte;
use App\Enums\TipoNotaCredito;
use App\Observers\DteObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Documento Tributario Electrónico (en esta fase: borrador).
 * La inmutabilidad fuera de borrador la impone DteObserver + DtePolicy.
 */
#[ObservedBy([DteObserver::class])]
class Dte extends Model
{
    use SoftDeletes;

    protected $table = 'dtes';

    protected $fillable = [
        'tipo_dte', 'estado', 'ambiente',
        'tipo_modelo', 'tipo_operacion', 'tipo_contingencia', 'motivo_contingencia',
        'establecimiento_id', 'punto_venta_id', 'correlativo_id',
        'cliente_id', 'cliente_sucursal_id', 'dte_relacionado_id',
        'numero_control', 'numero_interno', 'codigo_generacion', 'sello_recepcion',
        'respuesta_mh', 'respuesta_mh_path', 'fecha_procesamiento_mh',
        'condicion_operacion', 'forma_pago', 'numero_orden_compra',
        'cod_incoterms', 'desc_incoterms', 'tipo_item_expor', 'recinto_fiscal', 'tipo_regimen', 'regimen',
        'fecha_emision', 'hora_emision', 'observaciones', 'motivo', 'tipo_nota_credito', 'moneda',
        'motivo_anulacion', 'observacion_anulacion', 'fecha_anulacion', 'invalidado_by',
        'codigo_generacion_invalidacion', 'tipo_anulacion', 'json_invalidacion_path', 'jws_invalidacion_path',
        'sello_invalidacion', 'respuesta_mh_invalidacion', 'respuesta_mh_invalidacion_path',
        'fecha_invalidacion', 'fecha_procesamiento_invalidacion',
        'total_no_sujeto', 'total_exento', 'total_gravado', 'total_exportacion',
        'descuento_no_sujeto', 'descuento_exento', 'descuento_gravado',
        'descuento_global', 'descuento_porcentaje_aplicado', 'total_descuento', 'subtotal', 'iva',
        'aplica_retencion_iva', 'iva_retenido', 'retencion_renta',
        'monto_total_operacion', 'total_antes_retencion', 'total_pagar', 'total_letras',
        'flete', 'seguro',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tipo_dte' => TipoDte::class,
            'estado' => EstadoDte::class,
            'ambiente' => AmbienteHacienda::class,
            'condicion_operacion' => CondicionPago::class,
            'tipo_nota_credito' => TipoNotaCredito::class,
            'motivo_anulacion' => MotivoAnulacion::class,
            'fecha_anulacion' => 'datetime',
            'tipo_anulacion' => \App\Enums\TipoAnulacionMh::class,
            'respuesta_mh_invalidacion' => 'array',
            'fecha_invalidacion' => 'datetime',
            'fecha_procesamiento_invalidacion' => 'datetime',
            'fecha_emision' => 'date',
            'fecha_procesamiento_mh' => 'datetime',
            'respuesta_mh' => 'array',
            'aplica_retencion_iva' => 'boolean',
            'total_no_sujeto' => 'decimal:2',
            'total_exento' => 'decimal:2',
            'total_gravado' => 'decimal:2',
            'total_exportacion' => 'decimal:2',
            'descuento_no_sujeto' => 'decimal:2',
            'descuento_exento' => 'decimal:2',
            'descuento_gravado' => 'decimal:2',
            'descuento_global' => 'decimal:2',
            'descuento_porcentaje_aplicado' => 'decimal:2',
            'total_descuento' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'iva' => 'decimal:2',
            'iva_retenido' => 'decimal:2',
            'retencion_renta' => 'decimal:2',
            'monto_total_operacion' => 'decimal:2',
            'total_antes_retencion' => 'decimal:2',
            'total_pagar' => 'decimal:2',
            'flete' => 'decimal:2',
            'seguro' => 'decimal:2',
        ];
    }

    /**
     * Documentos de PRODUCCIÓN real (ambiente MH '01'). Es el filtro del listado
     * principal: solo lo emitido en producción (hoy, desde el CCF 1078). Los borradores
     * de producción también entran (ambiente 01), no solo los ya aceptados.
     */
    public function scopeProduccion(Builder $q): Builder
    {
        return $q->where('ambiente', AmbienteHacienda::Produccion->value);
    }

    /**
     * Documentos de PRUEBA / piloto / simulación (ambiente MH '00'). No se muestran en
     * el listado principal; su acceso vive escondido en el panel de Auditoría.
     */
    public function scopePruebas(Builder $q): Builder
    {
        return $q->where('ambiente', AmbienteHacienda::Pruebas->value);
    }

    /** Único estado editable. */
    public function esEditable(): bool
    {
        return $this->estado === EstadoDte::Borrador;
    }

    /** ¿Anulado/invalidado internamente? */
    public function esAnulado(): bool
    {
        return $this->estado === EstadoDte::Invalidado;
    }

    /**
     * ¿Ya tiene un evento de invalidación oficial registrado? (sello de invalidación
     * presente — en mock, ficticio marcado — o estado ya invalidado). Sirve de candado
     * de idempotencia para no invalidar dos veces.
     */
    public function tieneEventoInvalidacion(): bool
    {
        return filled($this->sello_invalidacion) || $this->esAnulado();
    }

    /**
     * ¿Este documento exige número de orden de compra? (regla única de dominio).
     * Solo aplica al CCF; delega en {@see \App\Support\Dte\ReglaOrdenCompra}.
     */
    public function requiereOrdenCompra(): bool
    {
        return \App\Support\Dte\ReglaOrdenCompra::requeridaParaDte($this);
    }

    public function establecimiento(): BelongsTo
    {
        return $this->belongsTo(Establecimiento::class);
    }

    public function puntoVenta(): BelongsTo
    {
        return $this->belongsTo(PuntoVenta::class);
    }

    public function correlativo(): BelongsTo
    {
        return $this->belongsTo(Correlativo::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /** Historial de envíos por correo (manual) al cliente, más reciente primero. */
    public function envios(): HasMany
    {
        return $this->hasMany(DteEnvio::class)->latest();
    }

    /** Sala/sucursal comercial del cliente (referencia, no receptor fiscal). */
    public function clienteSucursal(): BelongsTo
    {
        return $this->belongsTo(ClienteSucursal::class);
    }

    /** Documento original (para NC/ND). */
    public function dteRelacionado(): BelongsTo
    {
        return $this->belongsTo(Dte::class, 'dte_relacionado_id');
    }

    /**
     * ¿El documento fue ACEPTADO REALMENTE por Hacienda (no mock/simulado ni solo local)?
     * Requiere estado aceptado + sello de recepción real (no MOCK) + huella de procesamiento
     * del MH (fecha_procesamiento_mh). Las aceptaciones mock (sello "MOCK-…", sin
     * fecha_procesamiento_mh) NO cuentan: su codigoGeneracion no existe en el MH.
     */
    public function aceptadoRealmentePorMh(): bool
    {
        $sello = (string) $this->sello_recepcion;

        return $this->estado === EstadoDte::Aceptado
            && $sello !== ''
            && ! str_starts_with(strtoupper($sello), 'MOCK')
            && $this->fecha_procesamiento_mh !== null;
    }

    /** Scope: documentos ACEPTADOS REALMENTE por Hacienda (mismos criterios que aceptadoRealmentePorMh). */
    public function scopeAceptadoRealMh(Builder $q): Builder
    {
        return $q->where('estado', EstadoDte::Aceptado->value)
            ->whereNotNull('sello_recepcion')
            ->where('sello_recepcion', '!=', '')
            ->whereRaw('UPPER(sello_recepcion) NOT LIKE ?', ['MOCK%'])
            ->whereNotNull('fecha_procesamiento_mh');
    }

    /** Notas de crédito/débito que referencian a este documento. */
    public function notas(): HasMany
    {
        return $this->hasMany(Dte::class, 'dte_relacionado_id');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(DteLinea::class)->orderBy('numero_linea');
    }

    public function historial(): HasMany
    {
        return $this->hasMany(DteEstadoHistorial::class)->orderByDesc('id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Usuario que anuló el documento (reutiliza invalidado_by). */
    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invalidado_by');
    }
}
