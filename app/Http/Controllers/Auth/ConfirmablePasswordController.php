<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ConfirmablePasswordController extends Controller
{
    /** Fallos por minuto antes de pedir que espere. */
    private const INTENTOS = 6;

    /**
     * Show the confirm password view.
     */
    public function show(): View
    {
        return view('auth.confirm-password');
    }

    /**
     * Confirm the user's password.
     */
    public function store(Request $request): RedirectResponse
    {
        // Límite de fallos por cuenta, como el login: estando adentro tampoco se prueban
        // contraseñas sin freno. Solo cuentan los fallos; un acierto limpia el contador.
        $clave = 'confirmar-clave|'.$request->user()->getAuthIdentifier();
        if (RateLimiter::tooManyAttempts($clave, self::INTENTOS)) {
            $segundos = RateLimiter::availableIn($clave);

            throw ValidationException::withMessages([
                'password' => __('auth.throttle', ['seconds' => $segundos, 'minutes' => ceil($segundos / 60)]),
            ]);
        }

        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->password,
        ])) {
            RateLimiter::hit($clave, 60);

            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        RateLimiter::clear($clave);
        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
