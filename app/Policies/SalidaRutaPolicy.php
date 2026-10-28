<?php

namespace App\Policies;

use App\Enums\EstadoSalidaRuta;
use App\Enums\PermisoSistema;
use App\Models\PersonalRuta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\SalidaRutaGasto;
use App\Models\User;
use App\Support\HoraNegocio;

/**
 * Lo que puede hacer un VENDEDOR en una salida: solo en las suyas (participa con su
 * persona de Rutas activa) y solo mientras la salida está en curso. La oficina
 * gestiona gastos con `rutas.gestionar` y consulta sus fotos con `rutas.ver`.
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

    public function verFotoGasto(User $usuario, SalidaRuta $salida): bool
    {
        return $usuario->can('rutas.ver') || $this->participa($usuario, $salida);
    }

    public function agregarGasto(User $usuario, SalidaRuta $salida, string $fecha): bool
    {
        if ($salida->diaCerrado($fecha)) {
            return false;
        }

        return $usuario->can('rutas.gestionar')
            ? in_array($salida->estado, [EstadoSalidaRuta::EnCurso, EstadoSalidaRuta::Finalizada], true)
            : ($fecha === HoraNegocio::fechaHoy() && $this->registrar($usuario, $salida));
    }

    public function borrarGasto(User $usuario, SalidaRuta $salida, SalidaRutaGasto $gasto): bool
    {
        return (int) $gasto->salida_ruta_id === (int) $salida->id
            && ! $salida->diaCerrado($gasto->fecha->toDateString())
            && ($usuario->can('rutas.gestionar')
                ? in_array($salida->estado, [EstadoSalidaRuta::EnCurso, EstadoSalidaRuta::Finalizada], true)
                : $this->registrar($usuario, $salida))
            && ($usuario->can('rutas.gestionar') || (int) $gasto->registrado_por === (int) $usuario->id);
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
