<?php

namespace App\Ajustes\Verificaciones;

use App\Ajustes\RepositorioAjustes;
use App\Models\VerificacionConfiguracion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

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

    /**
     * Guarda el resultado de una comprobación, o NO guarda nada si la tabla del
     * historial todavía no existe (ventana entre desplegar y migrar).
     *
     * Devuelve null en ese caso en vez de fallar. La comprobación en sí —conectar
     * al SMTP, pedir el perfil de Gmail— ya se hizo y su resultado ya se le mostró
     * a quien pulsó el botón; lo único que falta es el apunte histórico. Tirar un
     * 500 con una excepción de SQL después de una prueba que salió bien sería
     * convertir un dato de segundo orden en un fallo de la pantalla.
     *
     * Es lo contrario de lo que hace la escritura de un AJUSTE, que sí falla
     * ruidosamente con AlmacenAjustesNoDisponibleException: ahí se perdería una
     * decisión de una persona; acá, una línea de historial que se puede volver a
     * generar pulsando el botón otra vez.
     */
    public function registrar(
        string $clave,
        ResultadoVerificacion $resultado,
        ?string $mensaje = null,
    ): ?VerificacionConfiguracion {
        if (! $this->tablaDisponible()) {
            return null;
        }

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
        if (! $this->tablaDisponible()) {
            return null;
        }

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
        if ($claves === [] || ! $this->tablaDisponible()) {
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

    /**
     * ¿Existe ya la tabla del historial?
     *
     * Igual que en {@see RepositorioAjustes}: entre desplegar y
     * migrar, el código nuevo corre contra el esquema viejo. Sin esta comprobación
     * la pantalla Resumen —que consulta la última verificación de cada servicio—
     * devolvería 500 durante toda esa ventana. Sin historial la respuesta correcta
     * es "nunca se comprobó", que es justamente lo que se devuelve.
     */
    private function tablaDisponible(): bool
    {
        return Schema::hasTable((new VerificacionConfiguracion)->getTable());
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
