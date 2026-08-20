<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\Ceremonias\ConfirmacionN2;
use App\Ajustes\EstadoAjuste;
use App\Ajustes\Integraciones\ConfiguracionGmail;
use App\Ajustes\Integraciones\DesconectarGmail;
use App\Ajustes\Integraciones\PruebaConexionGmail;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Http\Controllers\Configuracion\Concerns\GuardaConConfirmacionN2;
use App\Http\Controllers\Controller;
use App\Models\GmailCuenta;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Integraciones → Gmail (Prontos Pagos).
 *
 * La pantalla separa tres cosas que antes estaban mezcladas entre el .env y la
 * pantalla de PPQ:
 *
 *   1. ESTADO DE LA CONEXIÓN — si hay cuenta conectada, cuál, con qué permisos,
 *      quién la conectó y cuándo se comprobó por última vez.
 *   2. CREDENCIALES Y PARÁMETROS — lo que hace falta para pedir permiso a Google
 *      y para saber dónde buscar.
 *   3. ACCIONES — conectar, desconectar, comprobar.
 *
 * NO CAMBIA NADA DE PPQ. La búsqueda de CCF, la conciliación con albaranes, los
 * lotes y el programador siguen igual: lo único que cambió es de dónde salen los
 * valores de configuración, y sin overrides guardados salen del mismo sitio de
 * siempre.
 *
 * LOS TOKENS NO ESTÁN EN NINGUNA VARIABLE QUE LLEGUE A LA VISTA. De la cuenta se
 * publican correo, permisos, quién la conectó y desde cuándo; el modelo los tiene
 * en `$hidden` y aquí ni se leen.
 */
class IntegracionGmailController extends Controller
{
    use GuardaConConfirmacionN2;

    /**
     * Campo del formulario ⇒ clave del registry. Es la ÚNICA traducción posible:
     * lo que no esté aquí no se puede escribir desde esta pantalla. El secreto de
     * cliente NO está: tiene su propia pantalla.
     */
    private const CAMPOS = [
        'activada' => 'ppq.gmail.enabled',
        'client_id' => 'ppq.gmail.client_id',
        'redirect_uri' => 'ppq.gmail.redirect_uri',
        'label_albaranes' => 'ppq.gmail.label_albaranes',
        'enviados_query' => 'ppq.gmail.enviados_query',
        'dte_adjunto_query' => 'ppq.gmail.dte_adjunto_query',
    ];

    public function index(
        ServicioAjustes $ajustes,
        ConfiguracionGmail $configuracion,
        RegistroVerificaciones $verificaciones,
    ): View {
        $cuenta = GmailCuenta::actual();

        return view('configuracion.integraciones.gmail', [
            'campos' => $this->estados($ajustes),
            'secreto' => $ajustes->estadoParaPantalla('ppq.gmail.client_secret'),
            'carpeta' => $ajustes->estadoParaPantalla('ppq.gmail.storage_dir'),
            'configuracion' => $configuracion,
            // Estado de la conexión: nunca los tokens.
            'conexion' => [
                'conectada' => $cuenta?->conectada() === true,
                'correo' => $cuenta?->email,
                'scopes' => $cuenta?->scopes,
                'conectado_por' => $cuenta?->conectado_por
                    ? User::find($cuenta->conectado_por)?->name
                    : null,
                'desde' => $cuenta?->created_at,
                'expira' => $cuenta?->expires_at,
            ],
            'ultimaPrueba' => $verificaciones->ultima(ConfiguracionGmail::CLAVE_VERIFICACION),
        ]);
    }

    public function update(Request $request, ConfirmacionN2 $confirmacion): View|RedirectResponse
    {
        $datos = $request->validate([
            'client_id' => ['nullable', 'string', 'max:255'],
            'redirect_uri' => ['nullable', 'string', 'max:255'],
            'label_albaranes' => ['nullable', 'string', 'max:120'],
            'enviados_query' => ['nullable', 'string', 'max:255'],
            'dte_adjunto_query' => ['nullable', 'string', 'max:255'],
        ], [], [
            'client_id' => 'identificador de cliente',
            'redirect_uri' => 'URL de retorno',
            'label_albaranes' => 'etiqueta de los albaranes',
            'enviados_query' => 'búsqueda de documentos enviados',
            'dte_adjunto_query' => 'filtro de adjunto',
        ]);

        // Una casilla ausente significa "desactivada", no "no la toques": se
        // resuelve acá y no en la validación, que ignoraría el campo faltante.
        $datos['activada'] = $request->boolean('activada');

        return $this->guardarConConfirmacion(
            $request,
            $confirmacion,
            self::CAMPOS,
            $datos,
            volver: 'configuracion.integraciones.gmail',
            titulo: 'Vas a cambiar la integración con Gmail',
            consecuencia: 'Si el identificador o la URL de retorno no coinciden EXACTAMENTE con lo registrado en la consola de Google, la autorización se rechaza y el módulo deja de poder leer correos. Cambiar la etiqueta o las búsquedas hace que no se encuentren albaranes o documentos que sí existen.',
            exito: 'Integración con Gmail guardada. Probá la conexión para comprobar que funciona.',
            sinCambios: 'No había nada que cambiar en la integración con Gmail.',
        );
    }

    /** Comprueba que la cuenta responde. NO sincroniza ni descarga nada. */
    public function probar(PruebaConexionGmail $prueba): RedirectResponse
    {
        $resultado = $prueba->ejecutar();

        return redirect()
            ->route('configuracion.integraciones.gmail')
            ->with($resultado->exito ? 'status' : 'error', $resultado->mensaje);
    }

    /** Desconecta la cuenta. Deja rastro en auditoría (ver DesconectarGmail). */
    public function desconectar(DesconectarGmail $desconectar): RedirectResponse
    {
        $correo = $desconectar->ejecutar();

        return redirect()
            ->route('configuracion.integraciones.gmail')
            ->with('status', $correo === null
                ? 'No había ninguna cuenta de Gmail conectada.'
                : 'Gmail desconectado ('.$correo.'). Los documentos ya descargados no se tocaron.');
    }

    /**
     * Estado de cada campo indexado por el NOMBRE DEL CAMPO (no por la clave
     * técnica): así la vista no tiene que conocer el registry.
     *
     * @return array<string, EstadoAjuste>
     */
    private function estados(ServicioAjustes $ajustes): array
    {
        $estados = [];

        foreach (self::CAMPOS as $campo => $clave) {
            $estados[$campo] = $ajustes->estadoParaPantalla($clave);
        }

        return $estados;
    }
}
