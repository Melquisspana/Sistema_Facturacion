<?php

namespace App\Policies;

use App\Enums\EstadoSalidaRuta;
use App\Enums\PermisoSistema;
use App\Models\PersonalRuta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;

/**
 * Lo que puede hacer un VENDEDOR en una salida: solo en las suyas (participa con su
 * persona de Rutas activa) y solo mientras la salida está en curso. La oficina no pasa
 * por acá: gestiona con `rutas.gestionar` desde la hoja de siempre.
 */
class SalidaRutaPolicy
{
    public function view(User $usuario, SalidaRuta $salida): bool
    {
        return $this->participa($usuario, $salida) && $salida->estado === EstadoSalidaRuta::EnCurso;
    }

    public function registrar(User $usuario, SalidaRuta $salida): bool
    {
        return $this->view($usuario, $salida) && ! $salida->diaCerrado();
    }

    public function liquidacion(User $usuario, SalidaRuta $salida): bool
    {
        return $this->participa($usuario, $salida);
    }

    private function participa(User $usuario, SalidaRuta $salida): bool
    {
        return $usuario->can(PermisoSistema::RutasEntregasRegistrar->value)
            && $salida->personal()->whereIn('rutas_personal.id', PersonalRuta::query()
                ->where('user_id', $usuario->id)->where('activo', true)->select('id'))->exists();
    }

    public function deshacer(User $usuario, SalidaRuta $salida, SalidaRutaEntrega $entrega): bool
    {
        return $this->registrar($usuario, $salida)
            && (! $entrega->fecha_resultado || ! $salida->diaCerrado($entrega->fecha_resultado->toDateString()))
            && (int) $entrega->salida_ruta_id === (int) $salida->id
            && (int) $entrega->registrado_por === (int) $usuario->id;
    }
}
