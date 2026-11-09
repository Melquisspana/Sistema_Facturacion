<?php

namespace App\Http\Controllers\Auth;

use App\Ajustes\Correo\ConfiguracionCorreoRuntime;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        // El enlace sale por el servidor de correo guardado en Configuración.
        app(ConfiguracionCorreoRuntime::class)->aplicar();
        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }
}
