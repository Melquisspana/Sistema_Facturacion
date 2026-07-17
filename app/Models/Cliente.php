<?php

namespace App\Models;

use App\Enums\TamanioContribuyente;
use App\Enums\TipoCliente;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoPersona;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Cliente / receptor de documentos.
 *
 * Auditoría: registra creación, edición y eliminación con spatie/activitylog
 * (solo los atributos que cambian).
 */
class Cliente extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'clientes';

    protected $fillable = [
        'codigo',
        'tipo_cliente',
        'tipo_persona',
        'tipo_documento',
        'num_documento',
        'nrc',
        'es_agente_retencion',
        'tamanio_contribuyente',
        'nombre',
        'nombre_comercial',
        'actividad_economica_id',
        'pais_id',
        'departamento_id',
        'municipio_id',
        'distrito_id',
        'direccion',
        'complemento_direccion',
        'correo',
        'telefono',
        'contacto_principal',
        'observaciones',
        'requiere_orden_compra',
        'etiqueta_orden_compra',
        'observaciones_facturacion',
        'descuento_global_default',
        'condicion_operacion_default',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'tipo_cliente' => TipoCliente::class,
            'tipo_persona' => TipoPersona::class,
            'tipo_documento' => TipoDocumentoCliente::class,
            'requiere_orden_compra' => 'boolean',
            'es_agente_retencion' => 'boolean',
            'tamanio_contribuyente' => TamanioContribuyente::class,
            'descuento_global_default' => 'decimal:2',
            'condicion_operacion_default' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('cliente')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó el cliente',
                'updated' => 'actualizó el cliente',
                'deleted' => 'eliminó el cliente',
                default => $evento,
            });
    }

    public function actividadEconomica(): BelongsTo
    {
        return $this->belongsTo(ActividadEconomica::class);
    }

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class);
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class);
    }

    public function distrito(): BelongsTo
    {
        return $this->belongsTo(Distrito::class);
    }

    /** Sucursales / salas comerciales del cliente. */
    public function sucursales(): HasMany
    {
        return $this->hasMany(ClienteSucursal::class);
    }

    /**
     * Clientes administrativos de Exportaciones (Lista de Empaque) vinculados a
     * este Cliente DTE. Normalmente 1 a 1, pero no es único a nivel de esquema.
     */
    public function exportacionClientes(): HasMany
    {
        return $this->hasMany(ExportacionCliente::class);
    }

    /**
     * Valor centinela EXACTO usado como documento provisional (nunca un documento
     * real): 14 ceros, igual de largo que un NIT, para que pase las validaciones de
     * formato mientras el usuario no tiene el documento verdadero del receptor.
     */
    public const DOCUMENTO_PROVISIONAL = '00000000000000';

    /**
     * True solo si es un Cliente de exportación con el valor centinela EXACTO como
     * documento. No requiere columna nueva: se resuelve por el valor guardado, así
     * que el bloqueo desaparece automáticamente en cuanto se guarda el documento real.
     */
    public function tieneDocumentoProvisional(): bool
    {
        return $this->tipo_cliente === TipoCliente::Exportacion
            && $this->num_documento === self::DOCUMENTO_PROVISIONAL;
    }
}
