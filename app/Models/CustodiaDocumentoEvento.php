<?php

namespace App\Models;

use App\Enums\TipoEventoCustodia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un hecho de la custodia del CCF físico. Ver la migración para el porqué de la bitácora.
 *
 * Solo se INSERTA. Un evento mal registrado se anula con otro evento que lo compensa; nunca
 * se edita ni se borra. Por eso `UPDATED_AT = null` y por eso lo único que este modelo
 * permite cambiar después es la marca `anulado`, que escribe el servicio de custodia dentro
 * de la misma transacción en que crea la anulación.
 */
class CustodiaDocumentoEvento extends Model
{
    use HasFactory;

    /** Bitácora: solo `created_at`. Una fila que se puede actualizar no prueba nada. */
    public const UPDATED_AT = null;

    protected $table = 'custodia_documento_eventos';

    protected $fillable = [
        'salida_ruta_documento_id',
        'salida_ruta_id',
        'tipo',
        'origen_personal_id',
        'destino_personal_id',
        'registrado_por',
        'ocurrido_en',
        'observacion',
        'motivo',
        'anula_evento_id',
        'anulado',
        'recepcion_vigente',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoEventoCustodia::class,
            'ocurrido_en' => 'datetime',
            'anulado' => 'boolean',
        ];
    }

    // ------------------------------------------------------------- relaciones

    public function documento(): BelongsTo
    {
        return $this->belongsTo(SalidaRutaDocumento::class, 'salida_ruta_documento_id');
    }

    public function salida(): BelongsTo
    {
        return $this->belongsTo(SalidaRuta::class, 'salida_ruta_id');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(PersonalRuta::class, 'origen_personal_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(PersonalRuta::class, 'destino_personal_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    /** El evento que ESTE anula, si es una anulación. */
    public function anulado(): BelongsTo
    {
        return $this->belongsTo(self::class, 'anula_evento_id');
    }

    // ---------------------------------------------------------------- lectura

    public function esRecepcion(): bool
    {
        return $this->tipo === TipoEventoCustodia::RecepcionOficina;
    }

    public function esAnulacion(): bool
    {
        return $this->tipo === TipoEventoCustodia::Anulacion;
    }

    /** ¿Este evento sigue contando para el estado actual? */
    public function vigente(): bool
    {
        return ! $this->anulado && ! $this->esAnulacion();
    }

    /**
     * Frase para la línea de tiempo: «bodega entregó el documento a Rene Barillas».
     * Se arma del evento y no se guarda: una descripción congelada envejece mal cuando
     * alguien corrige el nombre de una persona.
     */
    public function resumen(): string
    {
        $texto = $this->tipo->descripcion();

        if ($this->origen && $this->destino) {
            return $texto.': de '.$this->origen->nombre.' a '.$this->destino->nombre;
        }

        if ($this->destino) {
            return $texto.' a '.$this->destino->nombre;
        }

        if ($this->origen) {
            return $texto.' (lo tenía '.$this->origen->nombre.')';
        }

        return $texto;
    }

    // ----------------------------------------------------------------- scopes

    /** Los que cuentan para el estado: ni anulados ni anulaciones. */
    public function scopeVigentes(Builder $q): Builder
    {
        return $q->where('anulado', false)->where('tipo', '!=', TipoEventoCustodia::Anulacion->value);
    }

    public function scopeDeTipo(Builder $q, TipoEventoCustodia $tipo): Builder
    {
        return $q->where('tipo', $tipo->value);
    }

    /** La recepción que sigue en pie de un documento, si la hay. */
    public function scopeRecepcionVigente(Builder $q): Builder
    {
        return $q->where('recepcion_vigente', 1);
    }
}
