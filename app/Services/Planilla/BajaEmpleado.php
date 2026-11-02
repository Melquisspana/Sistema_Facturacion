<?php

namespace App\Services\Planilla;

use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Rutas\FichaVendedor;
use App\Services\Usuarios\UltimoAdministrador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BajaEmpleado
{
    public function dar(User $actor, PlanillaEmpleado $e, string $fecha): void
    {
        $this->autorizar($actor, $e);
        Validator::make(['fecha' => $fecha], ['fecha' => ['required', 'date_format:Y-m-d']])->validate();
        $this->cambiar($actor, $e, false, $fecha);
    }

    public function reactivar(User $actor, PlanillaEmpleado $e): void
    {
        $this->autorizar($actor, $e);
        $this->cambiar($actor, $e, true, null);
    }

    private function autorizar(User $actor, PlanillaEmpleado $e): void
    {
        abort_unless($actor->activo && $actor->can('planilla.gestionar'), 403);
        foreach (['user_id' => 'usuarios.gestionar', 'asistencia_empleado_id' => 'asistencia.gestionar', 'personal_ruta_id' => 'rutas.personal.gestionar'] as $campo => $permiso) {
            abort_unless(! $e->$campo || $actor->can($permiso), 403);
        }
    }

    private function cambiar(User $actor, PlanillaEmpleado $e, bool $activo, ?string $fecha): void
    {
        DB::transaction(function () use ($actor, $e, $activo, $fecha) {
            $e = PlanillaEmpleado::lockForUpdate()->findOrFail($e->id);
            $this->autorizar($actor, $e);
            $usuario = $e->usuario()->lockForUpdate()->first();
            if (! $activo && $usuario && ($usuario->id === $actor->id || UltimoAdministrador::es($usuario))) {
                throw ValidationException::withMessages(['empleado' => 'No podés dar de baja a tu propio usuario ni al último administrador activo.']);
            }
            $e->update(['activo' => $activo, 'baja_el' => $fecha]);
            $e->asistencia?->update(['activo' => $activo]);
            $e->personalRuta?->update(['activo' => $activo]);
            if ($usuario) {
                $usuario->update(['activo' => $activo]);
                app(FichaVendedor::class)->sincronizar($usuario);
            }
            activity('empleado')->performedOn($e)->causedBy($actor)->log($activo ? 'reactivó al empleado' : 'dio de baja al empleado');
        });
    }
}
