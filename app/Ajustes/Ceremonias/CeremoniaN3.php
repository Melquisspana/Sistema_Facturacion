<?php

namespace App\Ajustes\Ceremonias;

use App\Ajustes\AuditoriaAjustes;
use App\Enums\PermisoSistema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Ceremonia de confirmación FUERTE para acciones de impacto fiscal (nivel N3).
 *
 * Cuatro puertas, en este orden, y ninguna se puede saltar:
 *
 *   1. permiso `configuracion.critica` — no basta con administrar configuración;
 *   2. precondiciones de la acción — el estado del sistema lo permite;
 *   3. frase exacta — el usuario leyó qué va a pasar;
 *   4. su contraseña actual — es él, no una sesión abierta que alguien encontró.
 *
 * EL ORDEN IMPORTA. El permiso va primero para que un usuario sin él no pueda ni
 * averiguar qué frase pide la acción ni usar el formulario como oráculo de
 * contraseñas. Las precondiciones van antes que la frase porque no tiene sentido
 * hacer escribir una frase larga para después decir que faltaba un respaldo.
 *
 * LA CONTRASEÑA NO SE GUARDA, NO SE DEVUELVE Y NO SE AUDITA. Entra por parámetro,
 * se comprueba con el guard —el mismo mecanismo que usa la pantalla de confirmar
 * contraseña de Laravel— y se descarta. No se pasa a la acción, no viaja en el
 * resultado y {@see AuditoriaAjustes::accionCritica()} no tiene por dónde
 * recibirla.
 *
 * LÍMITE DE INTENTOS: el formulario acepta contraseñas, así que es un oráculo. Se
 * limita por usuario y acción; sin esto, una sesión secuestrada podría probar
 * contraseñas contra este endpoint sin las protecciones del login.
 *
 * AVISO PERSISTENTE: la acción puede declarar uno y el resultado lo devuelve, para
 * que la pantalla que la invoque lo muestre y lo conserve. Esta fase no lo
 * persiste porque todavía no hay ninguna acción N3 real que lo necesite, y
 * fabricar la tabla antes que el caso llevaría a inventar columnas a ciegas.
 */
class CeremoniaN3
{
    /** Intentos permitidos por usuario y acción antes de bloquear. */
    private const INTENTOS = 5;

    /** Ventana del límite, en segundos. */
    private const VENTANA = 300;

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly AuditoriaAjustes $auditoria,
    ) {}

    /**
     * Ejecuta la acción SOLO si pasa la ceremonia entera.
     *
     * @throws AuthorizationException si falta `configuracion.critica` (403)
     */
    public function ejecutar(AccionCriticaN3 $accion, ?string $frase, ?string $password): ResultadoCeremonia
    {
        $usuario = $this->auth->guard('web')->user();

        $this->exigirPermiso($usuario, $accion);

        if ($motivo = $accion->precondicionIncumplida()) {
            return $this->rechazar($accion, 'No se puede continuar: '.$motivo);
        }

        if (! $accion->fraseCoincide($frase)) {
            return $this->rechazar(
                $accion,
                'La frase de confirmación no coincide exactamente con la solicitada.',
                'frase',
            );
        }

        if ($espera = $this->segundosDeBloqueo($usuario, $accion)) {
            return $this->rechazar(
                $accion,
                'Demasiados intentos fallidos. Volvé a intentarlo en '.$espera.' segundos.',
                'password',
            );
        }

        if (! $this->passwordCorrecta($usuario, $password)) {
            RateLimiter::hit($this->clavePorIntentos($usuario, $accion), self::VENTANA);

            return $this->rechazar(
                $accion,
                'La contraseña no es correcta.',
                'password',
            );
        }

        RateLimiter::clear($this->clavePorIntentos($usuario, $accion));

        $valor = ($accion->ejecutar)();

        $this->auditoria->accionCritica($accion->clave, $accion->titulo, ejecutada: true);

        return ResultadoCeremonia::ejecutada(
            'Acción confirmada y ejecutada: '.$accion->titulo.'.',
            $valor,
            $accion->avisoPersistente,
        );
    }

    /**
     * ¿Este usuario podría siquiera abrir el formulario de la acción? Lo consulta
     * la interfaz para no ofrecer un botón que va a terminar en 403.
     */
    public function puedeEjecutar(?Authorizable $usuario): bool
    {
        return $usuario !== null && $usuario->can(PermisoSistema::ConfiguracionCritica->value);
    }

    // ---------------------------------------------------------------- interno

    private function exigirPermiso(?Authenticatable $usuario, AccionCriticaN3 $accion): void
    {
        if ($usuario instanceof Authorizable && $usuario->can(PermisoSistema::ConfiguracionCritica->value)) {
            return;
        }

        // No se audita como intento: sin permiso no hay acción que intentar, y
        // registrar cada 403 convertiría el log en ruido de escaneo.
        throw new AuthorizationException(
            'Se requiere el permiso «'.PermisoSistema::ConfiguracionCritica->value.'» para «'.$accion->titulo.'».'
        );
    }

    /**
     * Comprueba la contraseña con el MISMO mecanismo que la pantalla de confirmar
     * contraseña de Laravel (`Auth::guard('web')->validate()`), en vez de comparar
     * hashes a mano: si mañana cambia el driver o el algoritmo, esto sigue siendo
     * correcto sin tocarlo.
     */
    private function passwordCorrecta(?Authenticatable $usuario, ?string $password): bool
    {
        if ($usuario === null || blank($password)) {
            return false;
        }

        try {
            // Mismas credenciales que arma ConfirmablePasswordController: el correo
            // es el identificador de acceso de esta aplicación.
            return (bool) $this->auth->guard('web')->validate([
                'email' => $usuario->email,
                'password' => $password,
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    private function rechazar(AccionCriticaN3 $accion, string $mensaje, ?string $campo = null): ResultadoCeremonia
    {
        $this->auditoria->accionCritica($accion->clave, $accion->titulo, ejecutada: false, detalle: $mensaje);

        return ResultadoCeremonia::rechazada($mensaje, $campo);
    }

    private function segundosDeBloqueo(?Authenticatable $usuario, AccionCriticaN3 $accion): int
    {
        $clave = $this->clavePorIntentos($usuario, $accion);

        return RateLimiter::tooManyAttempts($clave, self::INTENTOS)
            ? RateLimiter::availableIn($clave)
            : 0;
    }

    private function clavePorIntentos(?Authenticatable $usuario, AccionCriticaN3 $accion): string
    {
        return 'ceremonia-n3:'.$accion->clave.':'.($usuario?->getAuthIdentifier() ?? 'anonimo');
    }
}
