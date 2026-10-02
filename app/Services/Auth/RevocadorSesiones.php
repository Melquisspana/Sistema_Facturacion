<?php

namespace App\Services\Auth;

use App\Http\Middleware\CerrarSesionUsuarioInactivo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Corta los accesos vivos de un usuario: sus sesiones guardadas y su token de «Recordarme».
 *
 * Se usa al desactivarlo y al cambiarle la contraseña. Sin esto, ninguna de las dos cosas
 * tenía efecto sobre quien ya estaba dentro: la sesión seguía valiendo y la cookie de
 * «Recordarme» volvía a abrirla aunque la sesión venciera.
 *
 * Las sesiones solo se pueden borrar si viven en la base (driver `database`, el de este
 * proyecto). Con otro driver se rota igual el token y el middleware
 * {@see CerrarSesionUsuarioInactivo} cubre a los desactivados.
 */
class RevocadorSesiones
{
    /**
     * @param  string|null  $conservarSesionId  la sesión que hace el cambio, si es del propio
     *                                          usuario (cambiar la contraseña propia no echa
     *                                          a quien la está cambiando)
     */
    public function revocar(User $usuario, ?string $conservarSesionId = null): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table((string) config('session.table', 'sessions'))
                ->where('user_id', $usuario->getKey())
                ->when($conservarSesionId !== null, fn ($q) => $q->where('id', '!=', $conservarSesionId))
                ->delete();
        }

        // saveQuietly: no vuelve a disparar el observer ni ensucia la bitácora del usuario.
        $usuario->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }
}
