<?php

namespace App\Services\Usuarios;

use App\Models\PersonalRuta;
use App\Models\User;
use App\Services\Rutas\FichaVendedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CrearUsuario
{
    public function crear(array $validados, ?PersonalRuta $ficha = null): User
    {
        return DB::transaction(function () use ($validados, $ficha) {
            $usuario = new User([
                'name' => $validados['name'], 'email' => $validados['email'],
                'password' => Hash::make($validados['password']), 'activo' => $validados['activo'],
                'cuenta_externa' => $validados['cuenta_externa'] ?? false,
            ]);
            $usuario->email_verified_at = now();
            $usuario->save();
            $usuario->syncRoles([$validados['rol']]);
            activity('usuario')->performedOn($usuario)->log('asignó el rol '.$validados['rol']);
            // También el jefe de vendedores: sale a ruta y usa la ficha que ya tenía.
            if ($ficha && in_array($validados['rol'], FichaVendedor::ROLES, true)) {
                $ficha->update(['user_id' => $usuario->id]);
            }
            app(FichaVendedor::class)->sincronizar($usuario);

            return $usuario;
        });
    }
}
