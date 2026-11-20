<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\LimiteContrasena;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        // Límite de fallos por cuenta, como el login: estando adentro tampoco se prueban
        // contraseñas sin freno. Se cuenta antes de comprobarla; si la actual estaba bien y
        // el error es de la nueva, el intento se devuelve, y un cambio hecho limpia el contador.
        $clave = LimiteContrasena::clave($request->user());
        LimiteContrasena::contar($clave, 'current_password', 'updatePassword');

        try {
            $validated = $request->validateWithBag('updatePassword', [
                'current_password' => ['required', 'current_password'],
                'password' => ['required', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            if (! $e->validator->errors()->has('current_password')) {
                LimiteContrasena::devolver($clave);
            }

            throw $e;
        }

        LimiteContrasena::limpiar($clave);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        // El observer de User ya cerró las otras sesiones de esta cuenta; acá queda el rastro.
        activity('acceso')
            ->causedBy($request->user())
            ->performedOn($request->user())
            ->withProperties(['ip' => $request->ip()])
            ->log('Cambió su propia contraseña');

        return back()->with('status', 'password-updated');
    }
}
