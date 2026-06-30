<?php

namespace App\Http\Controllers\Ppq;

use App\Http\Controllers\Controller;
use App\Models\GmailCuenta;
use App\Services\Ppq\GmailClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Conexión OAuth2 de la cuenta de Gmail para PPQ (solo administrador). Nunca
 * muestra ni loguea tokens; estos se guardan cifrados en `gmail_cuentas`.
 */
class PpqGmailController extends Controller
{
    public function conectar(GmailClient $gmail): RedirectResponse
    {
        if (! $gmail->configurado()) {
            return redirect()->route('ppq.index')
                ->with('error', 'Faltan credenciales de Gmail en .env (GMAIL_CLIENT_ID/SECRET/REDIRECT_URI y PPQ_GMAIL_ENABLED=true).');
        }

        return redirect()->away($gmail->authUrl());
    }

    public function callback(Request $request, GmailClient $gmail): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route('ppq.index')->with('error', 'Autorización de Gmail cancelada.');
        }
        $codigo = (string) $request->query('code', '');
        if ($codigo === '') {
            return redirect()->route('ppq.index')->with('error', 'Google no devolvió el código de autorización.');
        }

        try {
            $cuenta = $gmail->conectar($codigo, $request->user()?->id);
        } catch (\Throwable $e) {
            return redirect()->route('ppq.index')->with('error', 'No se pudo conectar Gmail: '.$e->getMessage());
        }

        return redirect()->route('ppq.index')
            ->with('status', 'Gmail conectado'.($cuenta->email ? ' ('.$cuenta->email.')' : '').'. Ya podés buscar CCF.');
    }

    public function desconectar(): RedirectResponse
    {
        GmailCuenta::query()->delete();

        return redirect()->route('ppq.index')->with('status', 'Gmail desconectado.');
    }

    /** Pantalla de diagnóstico de la búsqueda en Gmail (qué query manda y qué devuelve). */
    public function debug(Request $request, GmailClient $gmail)
    {
        $numero = trim((string) $request->query('numero', ''));
        $diag = null;
        $error = null;

        if ($numero !== '') {
            if (! $gmail->disponible()) {
                $error = $gmail->configurado()
                    ? 'Gmail está configurado pero no conectado. Conectalo primero.'
                    : 'Faltan credenciales de Gmail en .env.';
            } else {
                try {
                    $diag = $gmail->diagnosticar($numero);
                } catch (\Throwable $e) {
                    $error = 'Error consultando Gmail: '.$e->getMessage();
                }
            }
        }

        return view('ppq.gmail-debug', [
            'numero' => $numero,
            'diag' => $diag,
            'error' => $error,
            'disponible' => $gmail->disponible(),
            'configurado' => $gmail->configurado(),
            'enviadosQuery' => config('ppq.gmail.enviados_query'),
            'labelAlbaranes' => config('ppq.gmail.label_albaranes'),
        ]);
    }
}
