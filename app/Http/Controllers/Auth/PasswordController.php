<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /** Fallos de la contraseña actual por minuto antes de pedir que espere. */
    private const INTENTOS = 6;

    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        // Límite de fallos por cuenta, como el login: estando adentro tampoco se prueban
        // contraseñas sin freno. Solo cuenta la contraseña actual equivocada; un error en
        // la nueva no, y un cambio hecho limpia el contador.
        $clave = 'cambiar-clave|'.$request->user()->getAuthIdentifier();
        if (RateLimiter::tooManyAttempts($clave, self::INTENTOS)) {
            $segundos = RateLimiter::availableIn($clave);

            throw ValidationException::withMessages([
                'current_password' => __('auth.throttle', ['seconds' => $segundos, 'minutes' => ceil($segundos / 60)]),
            ])->errorBag('updatePassword');
        }

        try {
            $validated = $request->validateWithBag('updatePassword', [
                'current_password' => ['required', 'current_password'],
                'password' => ['required', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            if ($e->validator->errors()->has('current_password')) {
                RateLimiter::hit($clave, 60);
            }

            throw $e;
        }

        RateLimiter::clear($clave);

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
