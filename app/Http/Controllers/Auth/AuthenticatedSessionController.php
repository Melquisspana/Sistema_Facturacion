<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CloudflareAccessSso;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     *
     * Quien salió a propósito en el dominio público ve, además del formulario, «Entrar con mi
     * cuenta» para volver por Cloudflare Access ({@see CloudflareAccessSso::MARCA_SALIDA}).
     */
    public function create(Request $request): View
    {
        return view('auth.login', [
            'entrarConCuenta' => $request->hasCookie(CloudflareAccessSso::MARCA_SALIDA)
                && app(CloudflareAccessSso::class)->aplicaAlRequest($request),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Entró con su usuario: la marca de «salió» ya no hace falta.
        return redirect()->intended(route('dashboard', absolute: false))
            ->withCookie(Cookie::forget(CloudflareAccessSso::MARCA_SALIDA));
    }

    /**
     * «Entrar con mi cuenta»: borra la marca de «salió» y vuelve a la raíz, donde
     * CloudflareAccessSso abre la sesión con la identidad de Access.
     */
    public function entrarConCuenta(): RedirectResponse
    {
        return redirect('/')->withCookie(Cookie::forget(CloudflareAccessSso::MARCA_SALIDA));
    }

    /**
     * Destroy an authenticated session.
     *
     * Deja la marca de «salió a propósito» ({@see CloudflareAccessSso::MARCA_SALIDA}): sin ella,
     * en el dominio público el pedido siguiente traía el JWT de Access y la sesión se abría
     * otra vez sola. Por eso «Salir» nunca funcionó ahí (12/11/2026).
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        // Que «atrás» no muestre una página guardada con datos después de salir: las de la
        // oficina en Rutas se dejan restaurar con «atrás» (`private, no-cache`).
        return redirect('/')->header('Clear-Site-Data', '"cache"')
            ->withCookie(CloudflareAccessSso::marcaSalida());
    }
}
