<?php

namespace App\Services\DocumentosRecibidos;

use App\Models\Configuracion;
use Illuminate\Support\Carbon;

/**
 * Estado de la ÚLTIMA corrida de sincronización de compras: cuándo empezó, cuándo
 * terminó bien por última vez, con qué conteos y con qué error.
 *
 * POR QUÉ EXISTE. Hasta ahora el único rastro de una revisión era el mensaje flash que
 * desaparecía al recargar la página. Con la sincronización automática eso ya no
 * alcanza: nadie está mirando cuando corre, así que el resultado tiene que quedar en
 * algún lado que la pantalla pueda leer después. Es lo que convierte "sincronizar" de
 * una acción que hay que acordarse de apretar en un estado que se puede mirar.
 *
 * Vive en `configuraciones`, el mismo mecanismo que ya usa la marca de albaranes. No
 * guarda credenciales ni contenido de correos: fechas, conteos y un motivo saneado. Las
 * claves están declaradas como NO auditables ({@see Configuracion::CLAVES_NO_AUDITABLES})
 * porque son estado de proceso y, a una corrida cada quince minutos, enterrarían en el
 * registro de auditoría los cambios que sí importan.
 */
class BitacoraSincronizacionCompras
{
    public const CLAVE_INICIO = 'documentos_recibidos.ultima_corrida_inicio';

    public const CLAVE_EXITO = 'documentos_recibidos.ultima_corrida_exito';

    public const CLAVE_RESUMEN = 'documentos_recibidos.ultima_corrida_resumen';

    public const CLAVE_ERROR = 'documentos_recibidos.ultimo_error';

    /** Todas las claves de estado, para declararlas no auditables de una sola vez. */
    public const CLAVES = [self::CLAVE_INICIO, self::CLAVE_EXITO, self::CLAVE_RESUMEN, self::CLAVE_ERROR];

    public function iniciar(): void
    {
        Configuracion::set(self::CLAVE_INICIO, now()->toDateTimeString());
    }

    /**
     * Corrida terminada BIEN. Limpia el error anterior: si volvió a funcionar, el
     * fallo viejo ya no describe el estado actual y dejarlo en pantalla confunde.
     *
     * @param  array<string, mixed>  $resumen
     */
    public function exito(array $resumen): void
    {
        Configuracion::set(self::CLAVE_EXITO, now()->toDateTimeString());
        Configuracion::set(self::CLAVE_RESUMEN, (string) json_encode($resumen, JSON_UNESCAPED_UNICODE));
        Configuracion::set(self::CLAVE_ERROR, null);
    }

    /**
     * Corrida que falló. NO toca la marca de éxito: el operador necesita ver las dos
     * cosas a la vez —cuándo funcionó por última vez y qué está fallando ahora—.
     *
     * @param  array<string, mixed>  $resumen
     */
    public function fallo(string $motivo, array $resumen = []): void
    {
        Configuracion::set(self::CLAVE_ERROR, mb_substr($motivo, 0, 500));
        if ($resumen !== []) {
            Configuracion::set(self::CLAVE_RESUMEN, (string) json_encode($resumen, JSON_UNESCAPED_UNICODE));
        }
    }

    public function ultimoInicio(): ?Carbon
    {
        return $this->fecha(self::CLAVE_INICIO);
    }

    public function ultimoExito(): ?Carbon
    {
        return $this->fecha(self::CLAVE_EXITO);
    }

    public function ultimoError(): ?string
    {
        $v = Configuracion::get(self::CLAVE_ERROR);

        return filled($v) ? (string) $v : null;
    }

    /** @return array<string, mixed> */
    public function ultimoResumen(): array
    {
        $json = Configuracion::get(self::CLAVE_RESUMEN);
        $datos = filled($json) ? json_decode((string) $json, true) : null;

        return is_array($datos) ? $datos : [];
    }

    /**
     * ¿Hace cuánto que no termina bien? `null` si nunca terminó bien.
     *
     * Lo usa la pantalla para ponerse en ámbar sin necesidad de que nadie mire el log:
     * una sincronización que debía correr cada quince minutos y lleva horas sin éxito
     * es un scheduler apagado, y así se ve.
     */
    public function minutosDesdeElUltimoExito(): ?int
    {
        return $this->ultimoExito()?->diffInMinutes(now());
    }

    private function fecha(string $clave): ?Carbon
    {
        $v = Configuracion::get($clave);
        if (blank($v)) {
            return null;
        }

        try {
            return Carbon::parse((string) $v);
        } catch (\Throwable) {
            return null;
        }
    }
}
