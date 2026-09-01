<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Configuración clave/valor del sistema. Acceso por helpers estáticos con caché por
 * request. Los valores se guardan como texto (los booleanos como '1'/'0').
 *
 * AUDITORÍA — la regla es al revés de lo habitual, a propósito:
 *
 * El valor de una clave SOLO queda registrado si la clave está declarada en
 * {@see self::CLAVES_VALOR_AUDITABLE}. Es una lista blanca, no una lista negra: una
 * clave nueva que alguien agregue mañana se audita como HECHO ("cambió tal ajuste")
 * pero sin su valor, hasta que alguien decida explícitamente que es pública. Con una
 * lista negra, olvidarse de agregar una clave nueva significaría filtrar su contenido
 * al log — que es exactamente lo que no puede pasar cuando esta tabla empiece a
 * guardar contraseñas y tokens.
 *
 * Aparte, {@see self::CLAVES_NO_AUDITABLES} son claves que no son configuración sino
 * ESTADO de proceso (marcas de progreso de comandos programados). Auditarlas llenaría
 * el registro de ruido — la sincronización de albaranes corre cada 5 minutos — y
 * enterraría los cambios que sí importan.
 */
class Configuracion extends Model
{
    // El alias es obligatorio: shouldLogEvent() viene del TRAIT, no de Model, así que
    // sobrescribirlo y llamar a parent:: no lo encuentra (fatal en tiempo de ejecución).
    use LogsActivity {
        shouldLogEvent as protected shouldLogEventPorDefecto;
    }

    /**
     * Claves cuyo VALOR puede quedar registrado en la auditoría. Todo lo que no esté
     * acá se trata como sensible por defecto. Antes de agregar una clave, preguntarse:
     * ¿me molestaría ver este valor en la pantalla de Auditoría?
     *
     * `correo.plantilla` queda deliberadamente fuera: no es un secreto, pero admite
     * 5000 caracteres y registrar antes y después metería dos bloques de ese tamaño en
     * el log cada vez que se guarda. Se audita el hecho ("cambió la plantilla"), que es
     * lo que hace falta; el contenido está en la propia pantalla.
     */
    public const CLAVES_VALOR_AUDITABLE = [
        'correo.auto_envio',
        'correo.adjuntar_jws',
        'contabilidad.correo',
        'contabilidad.enviar_copia',
        'produccion.auth_prod_validada',
        'produccion.auth_prod_validada_en',
        'produccion.auth_prod_validada_fuente',
        'produccion.ultimo_ccf_externo',
    ];

    /** Estado de proceso, no configuración: no se audita en absoluto. */
    public const CLAVES_NO_AUDITABLES = [
        'ppq.albaranes.ultimo_dia_completo',
        // Bitácora de la sincronización de compras: se reescribe en cada corrida
        // programada (cada 15 minutos), así que auditarla enterraría lo que sí importa.
        // {@see \App\Services\DocumentosRecibidos\BitacoraSincronizacionCompras}
        'documentos_recibidos.ultima_corrida_inicio',
        'documentos_recibidos.ultima_corrida_exito',
        'documentos_recibidos.ultima_corrida_resumen',
        'documentos_recibidos.ultimo_error',
    ];

    protected $table = 'configuraciones';

    protected $fillable = ['clave', 'valor'];

    /** @var array<string, ?string> */
    private static array $cache = [];

    public static function get(string $clave, ?string $default = null): ?string
    {
        if (! array_key_exists($clave, self::$cache)) {
            self::$cache[$clave] = static::query()->where('clave', $clave)->value('valor');
        }

        return self::$cache[$clave] ?? $default;
    }

    public static function getBool(string $clave, bool $default = false): bool
    {
        $v = static::get($clave);

        return $v === null ? $default : in_array(strtolower($v), ['1', 'true', 'on', 'yes', 'si', 'sí'], true);
    }

    public static function set(string $clave, string|bool|null $valor): void
    {
        $texto = is_bool($valor) ? ($valor ? '1' : '0') : $valor;
        static::updateOrCreate(['clave' => $clave], ['valor' => $texto]);
        self::$cache[$clave] = $texto;
    }

    /** Limpia la caché en memoria (útil en tests). */
    public static function olvidarCache(): void
    {
        self::$cache = [];
    }

    /** ¿El valor de esta clave puede quedar escrito en la auditoría? */
    public static function valorEsAuditable(string $clave): bool
    {
        return in_array($clave, self::CLAVES_VALOR_AUDITABLE, true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        // La clave SIEMPRE se registra (es el "qué se tocó"). El valor solo si la clave
        // está en la lista blanca; si no, se registra el hecho y nada más.
        $atributos = self::valorEsAuditable((string) $this->clave)
            ? ['clave', 'valor']
            : ['clave'];

        return LogOptions::defaults()
            ->logOnly($atributos)
            ->dontSubmitEmptyLogs()
            ->useLogName('configuracion')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'configuró «'.$this->clave.'»',
                'updated' => 'cambió la configuración «'.$this->clave.'»',
                'deleted' => 'eliminó la configuración «'.$this->clave.'»',
                default => $evento,
            });
    }

    /** No se auditan las marcas de progreso de los comandos programados. */
    protected function shouldLogEvent(string $eventName): bool
    {
        if (in_array((string) $this->clave, self::CLAVES_NO_AUDITABLES, true)) {
            return false;
        }

        return $this->shouldLogEventPorDefecto($eventName);
    }
}
