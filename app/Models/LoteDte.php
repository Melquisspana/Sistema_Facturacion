<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LoteDte extends Model
{
    protected $table = 'lotes_dte';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['respuesta_envio' => 'array', 'respuesta_consulta' => 'array', 'enviado_en' => 'datetime', 'ultima_consulta_en' => 'datetime', 'cerrado_en' => 'datetime'];
    }

    public function contingenciaEvento(): BelongsTo
    {
        return $this->belongsTo(ContingenciaEvento::class);
    }

    public function dtes(): HasMany
    {
        return $this->hasMany(Dte::class);
    }

    /**
     * Hacienda contesta que ya tiene un lote con este idEnvio. Así contestó apitest el
     * 26/11 (F21) al reenvío de un lote cuya primera respuesta se perdió: HTTP 208,
     * RECHAZADO, codigoMsg 099 y «YA EXISTE UN LOTE CON ESTE ID DE ENVIO». Se reconoce por
     * el texto; el codigoMsg, si viene, tiene que ser el 099. El código HTTP lo mira quien
     * recibe la respuesta.
     */
    public static function esIdEnvioRepetido(mixed $cuerpo): bool
    {
        if (! is_array($cuerpo) || ! is_string($cuerpo['descripcionMsg'] ?? null)) {
            return false;
        }
        $codigo = trim((string) ($cuerpo['codigoMsg'] ?? ''));

        return str_contains(Str::upper(Str::squish(Str::ascii($cuerpo['descripcionMsg']))), 'YA EXISTE UN LOTE CON ESTE ID DE ENVIO')
            && ($codigo === '' || (ctype_digit($codigo) && (int) $codigo === 99));
    }

    /**
     * La marca de un lote que Hacienda ya tenía: quedó recibido sin código de lote, con
     * esa respuesta guardada. No se reenvía ni suelta sus documentos para otro lote; se
     * consultan uno por uno por su código de generación.
     */
    public function haciendaYaLoTenia(): bool
    {
        return blank($this->codigo_lote) && self::esIdEnvioRepetido($this->respuesta_envio);
    }
}
