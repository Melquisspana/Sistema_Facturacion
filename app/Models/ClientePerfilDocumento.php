<?php

namespace App\Models;

use App\Enums\ModalidadNotaCredito;
use App\Enums\TipoNotaCredito;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Perfil de exigencias documentales de un cliente. Ver la migración para el porqué de
 * cada campo. Sin fila para un cliente, ese cliente se comporta como siempre.
 */
class ClientePerfilDocumento extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'cliente_perfiles_documento';

    protected $fillable = [
        'cliente_id',
        'activo',
        'codigo_proveedor',
        'formato_export',
        'exige_albaran_en_nc',
        'tolerancia_albaran',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'exige_albaran_en_nc' => 'boolean',
            'tolerancia_albaran' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('perfil_documento_cliente')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó el perfil de documentos del cliente',
                'updated' => 'actualizó el perfil de documentos del cliente',
                'deleted' => 'eliminó el perfil de documentos del cliente',
                default => $evento,
            });
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function tiposNc(): HasMany
    {
        return $this->hasMany(ClientePerfilTipoNc::class, 'cliente_perfil_documento_id');
    }

    /**
     * Regla DECLARADA para una modalidad interna, o null si no hay fila para ella.
     *
     * Es la pregunta literal por la fila: la usa la pantalla del perfil para saber qué
     * está configurado y qué no. Quien necesita saber qué regla GOBIERNA a una nota debe
     * preguntar por {@see reglaOperativaPara()}, que además cubre a las modalidades
     * hermanas.
     */
    public function reglaPara(?TipoNotaCredito $tipo): ?ClientePerfilTipoNc
    {
        if ($tipo === null) {
            return null;
        }

        return $this->tiposNc->firstWhere('tipo_nota_credito', $tipo->value);
    }

    /**
     * Regla que GOBIERNA una modalidad interna: la suya si está declarada, y si no la que
     * el perfil declaró para otra modalidad interna de la MISMA modalidad operativa
     * ({@see ModalidadNotaCredito}).
     *
     * POR QUÉ EXISTE. El perfil se declara por modalidad INTERNA (siete), pero quien emite
     * la nota elige una de las CUATRO modalidades operativas, y una de ellas agrupa dos
     * internas —devolución y faltante de entrega— que son el MISMO hecho fiscal y el mismo
     * albarán del cliente. Calleja declaró `devolucion_producto -> AC04 · sin descuento` y
     * nunca declaró `faltante_entrega`, porque en su pantalla son una sola cosa. Con la
     * pregunta exacta, un faltante no encontraba regla y caía al criterio histórico: le
     * heredaba el 5 % del CCF y la nota salía por $1.05 donde el albarán AC04 imprime
     * $1.11 ($0.98 gravado + $0.13 de IVA, «Descuentos Generales · Porcentaje 0»). La
     * pantalla ya rotulaba «AC04» para las dos, así que el formulario y el motor decían
     * cosas distintas sobre la misma nota.
     *
     * Lo declarado para el tipo EXACTO manda siempre: un cliente que quiera tratar el
     * faltante distinto de la devolución solo tiene que declarar su propia fila. Y un
     * cliente SIN perfil sigue saliendo con null, o sea con el comportamiento histórico
     * intacto.
     *
     * ALCANCE ACOTADO. La herencia entre hermanas vale SOLO donde hay evidencia de que la
     * regla es la misma, y eso hoy es únicamente devolución/faltante
     * ({@see ModalidadNotaCredito::comparteReglaDocumental()}). «Otro ajuste» agrupa tres
     * modalidades internas en la pantalla, pero agruparlas para elegir no prueba que el
     * cliente quiera el mismo código ni el mismo descuento para las tres, y hacer
     * equivalentes descuentos y exigencias sin un documento que lo respalde sería inventar
     * una regla suya. Ahí cada modalidad interna responde solo por su propia fila.
     */
    public function reglaOperativaPara(?TipoNotaCredito $tipo): ?ClientePerfilTipoNc
    {
        if ($tipo === null) {
            return null;
        }

        if ($propia = $this->reglaPara($tipo)) {
            return $propia;
        }

        $modalidad = ModalidadNotaCredito::desdeTipo($tipo);

        if ($modalidad === null || ! $modalidad->comparteReglaDocumental()) {
            return null;
        }

        // Orden de tiposInternos(): determinista y el mismo que ya usaba la tarjeta de
        // reversión del CCF para rotular el código, así que pantalla y motor coinciden.
        foreach ($modalidad->tiposInternos() as $hermana) {
            if ($regla = $this->reglaPara($hermana)) {
                return $regla;
            }
        }

        return null;
    }

    /** ¿Este perfil puede exportar el Excel del cliente? */
    public function exporta(): bool
    {
        return $this->activo && filled($this->formato_export);
    }
}
