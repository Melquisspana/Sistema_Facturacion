<?php

namespace App\Ajustes\Verificaciones;

use App\Models\VerificacionConfiguracion;
use Illuminate\Support\Facades\Auth;

/**
 * Registro de comprobaciones de configuración, reutilizable por servicio.
 *
 * Hoy lo usa la prueba de conexión SMTP. Está pensado para que Hacienda, el
 * firmador, Gmail e IMAP se enganchen sin tocar el esquema: cada uno registra
 * bajo su propia clave y la pantalla Resumen pregunta por ella.
 *
 * RETENCIÓN: se conservan las {@see MAXIMO_POR_CLAVE} últimas de cada servicio.
 * Sin esto, un botón "Probar conexión" pulsado con insistencia (o una futura
 * comprobación programada cada cinco minutos) haría crecer la tabla para
 * siempre a cambio de nada: lo que se consulta es la última y, a lo sumo, si
 * viene fallando desde hace rato.
 */
class RegistroVerificaciones
{
    /** Comprobaciones que se conservan por servicio. */
    public const MAXIMO_POR_CLAVE = 20;

    /** Longitud máxima del mensaje guardado (la columna admite 500). */
    private const MAX_MENSAJE = 480;

    public function registrar(
        string $clave,
        ResultadoVerificacion $resultado,
        ?string $mensaje = null,
    ): VerificacionConfiguracion {
        $verificacion = VerificacionConfiguracion::create([
            'clave' => $clave,
            'resultado' => $resultado,
            'mensaje' => $mensaje === null ? null : mb_substr($mensaje, 0, self::MAX_MENSAJE),
            'user_id' => Auth::id(),
        ]);

        $this->podar($clave);

        return $verificacion;
    }

    /** Última comprobación de un servicio, o null si nunca se comprobó. */
    public function ultima(string $clave): ?VerificacionConfiguracion
    {
        return VerificacionConfiguracion::query()
            ->de($clave)
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    /**
     * Últimas comprobaciones de varios servicios de una vez, para que la pantalla
     * Resumen no dispare una consulta por tarjeta.
     *
     * @param  array<int, string>  $claves
     * @return array<string, VerificacionConfiguracion>
     */
    public function ultimasDe(array $claves): array
    {
        if ($claves === []) {
            return [];
        }

        return VerificacionConfiguracion::query()
            ->whereIn('clave', $claves)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            // La última de cada clave gana porque se recorre en orden ascendente.
            ->keyBy('clave')
            ->all();
    }

    private function podar(string $clave): void
    {
        $sobrantes = VerificacionConfiguracion::query()
            ->de($clave)
            ->latest('created_at')
            ->latest('id')
            ->skip(self::MAXIMO_POR_CLAVE)
            ->take(1000)
            ->pluck('id');

        if ($sobrantes->isNotEmpty()) {
            VerificacionConfiguracion::query()->whereIn('id', $sobrantes)->delete();
        }
    }
}
