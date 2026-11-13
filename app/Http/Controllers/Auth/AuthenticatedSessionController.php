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
     * «Salir», el único del sistema (también atiende /logout-completo, por enlaces viejos).
     *
     * Deja la marca con la hora de salida ({@see CloudflareAccessSso::MARCA_SALIDA}): sin ella,
     * en el dominio público el pedido siguiente traía el JWT de Access y la sesión se abría
     * otra vez sola (12/11/2026). Si el pedido vino por Access, cierra también Access con su
     * ruta del mismo dominio: la próxima vez pide el PIN y entra directo (13/11/2026). Por la
     * red local no hay Access: va a la raíz y de ahí al login.
     */
    public function destroy(Request $request, CloudflareAccessSso $sso): RedirectResponse
    {
        $porAccess = $sso->vinoPorAccess($request);

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        // Sin `Clear-Site-Data: "cache"` (13/11/2026): borrar todo lo guardado del sitio tardaba
        // segundos en el celular y la pantalla parecía trabada. Que «atrás» no muestre una página
        // de antes de salir lo resuelve la pestaña (layouts/partials/salio-atras; el vendedor
        // usa `no-store` y recarga al volver): la pide de nuevo antes de mostrarla.
        return redirect($porAccess ? '/cdn-cgi/access/logout' : '/')
            ->withCookie(CloudflareAccessSso::marcaSalida());
    }
}
