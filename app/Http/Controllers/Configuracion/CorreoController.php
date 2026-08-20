<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\Ceremonias\ConfirmacionN2;
use App\Ajustes\Correo\PruebaConexionSmtp;
use App\Ajustes\EstadoAjuste;
use App\Ajustes\Excepciones\ValorAjusteInvalidoException;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Facades\Ajustes;
use App\Http\Controllers\Controller;
use App\Support\Contabilidad\CorreoContabilidad;
use App\Support\Dte\PlantillaCorreo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sección Correo del Centro de Configuración. Una pantalla, tres cosas distintas
 * que antes estaban repartidas o directamente no existían:
 *
 *   1. SERVIDOR SMTP        — por dónde sale el correo (nuevo).
 *   2. DOCUMENTOS FISCALES  — auto-envío, JWS y plantilla (lo que ya había).
 *   3. CONTABILIDAD         — correo y copia oculta (resuelto con CorreoContabilidad).
 *
 * DE DÓNDE SALEN LAS CLAVES. De {@see self::CAMPOS_SMTP}, nunca del navegador.
 * El formulario manda nombres humanos («servidor», «puerto») y este mapa —fijo, en
 * código— decide a qué ajuste del registry corresponde cada uno. No hay ninguna
 * vía por la que un campo oculto manipulado pueda escribir una clave arbitraria.
 *
 * LA CONTRASEÑA NO PASA POR ESTE FORMULARIO. Tiene su propia pantalla
 * ({@see PasswordSmtpController}) porque la confirmación N2 de los demás campos
 * reenvía los valores en campos ocultos para mostrar el antes/después, y un
 * secreto no puede viajar así ni una vez.
 */
class CorreoController extends Controller
{
    /**
     * Campo del formulario ⇒ clave del registry. Es la ÚNICA traducción posible:
     * lo que no esté aquí no se puede escribir desde esta pantalla.
     */
    private const CAMPOS_SMTP = [
        'servidor' => 'mail.smtp.host',
        'puerto' => 'mail.smtp.port',
        'seguridad' => 'mail.smtp.scheme',
        'usuario' => 'mail.smtp.username',
        'remitente' => 'mail.from.address',
        'remitente_nombre' => 'mail.from.name',
    ];

    public function edit(
        ServicioAjustes $ajustes,
        RegistroVerificaciones $verificaciones,
        CorreoContabilidad $contabilidad,
    ): View {
        return view('configuracion.correo.edit', [
            // --- Servidor SMTP -------------------------------------------------
            'smtp' => $this->estadosSmtp($ajustes),
            'passwordSmtp' => $ajustes->estadoParaPantalla('mail.smtp.password'),
            'medioEnvio' => $ajustes->estadoParaPantalla('mail.mailer'),
            'ultimaPrueba' => $verificaciones->ultima(PruebaConexionSmtp::CLAVE),

            // --- Documentos fiscales (claves legacy, sin migrar) ----------------
            'autoEnvio' => $ajustes->bool('correo.auto_envio', false),
            'adjuntarJws' => $ajustes->bool('correo.adjuntar_jws', false),
            'plantilla' => $ajustes->texto('correo.plantilla', PlantillaCorreo::DEFAULT),
            'variables' => ['{{cliente}}', '{{documento}}', '{{numero_control}}', '{{codigo_generacion}}', '{{fecha}}', '{{empresa}}', '{{total}}'],

            // --- Contabilidad ---------------------------------------------------
            'correoContabilidad' => $ajustes->texto('contabilidad.correo'),
            'enviarCopia' => $contabilidad->enviarCopia(),
        ]);
    }

    /**
     * Documentos fiscales. Sigue escribiendo en la tabla `configuraciones` de
     * siempre (persistencia Legacy declarada en el registry): esta fase unifica la
     * pantalla, no mueve los datos.
     */
    public function update(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'plantilla' => ['nullable', 'string', 'max:5000'],
        ]);

        Ajustes::guardarVarios([
            'correo.auto_envio' => $request->boolean('auto_envio'),
            'correo.adjuntar_jws' => $request->boolean('adjuntar_jws'),
            'correo.plantilla' => trim((string) ($datos['plantilla'] ?? '')),
        ]);

        return redirect()
            ->route('configuracion.correo.edit')
            ->with('status', 'Configuración de documentos fiscales guardada.');
    }

    /**
     * Servidor SMTP. Todos sus ajustes son N2, así que el guardado es en DOS pasos:
     * el primer envío calcula qué cambiaría y devuelve la pantalla de confirmación;
     * solo el segundo, ya con `confirmacion`, escribe.
     *
     * La confirmación NO es un adorno: cambiar el servidor o el puerto no rompe
     * ninguna pantalla, hace que dejen de salir los documentos al cliente, y eso se
     * descubre cuando el cliente reclama. Ver el antes y el después de cada campo es
     * lo que evita ese error.
     */
    public function updateSmtp(Request $request, ConfirmacionN2 $confirmacion): View|RedirectResponse
    {
        $datos = $this->validarSmtp($request);

        // clave del registry ⇒ valor propuesto. Las claves salen del mapa fijo.
        $propuestos = [];
        foreach (self::CAMPOS_SMTP as $campo => $clave) {
            $propuestos[$clave] = $datos[$campo] ?? null;
        }

        $cambios = $confirmacion->calcular($propuestos);

        if ($cambios === []) {
            return redirect()
                ->route('configuracion.correo.edit')
                ->with('status', 'No había nada que cambiar en el servidor de correo.');
        }

        if (! $request->boolean('confirmacion') && $confirmacion->requiereConfirmacion($cambios)) {
            return view('configuracion.correo.confirmar-smtp', [
                'cambios' => $cambios,
                // Se reenvían los valores YA VALIDADOS, con los mismos nombres de
                // campo del formulario. Ninguno es secreto.
                'valores' => array_intersect_key($datos, self::CAMPOS_SMTP),
            ]);
        }

        try {
            Ajustes::guardarVarios($propuestos);
        } catch (ValorAjusteInvalidoException $e) {
            throw ValidationException::withMessages([
                array_search($e->clave, self::CAMPOS_SMTP, true) ?: 'servidor' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('configuracion.correo.edit')
            ->with('status', 'Servidor de correo guardado. Probá la conexión para comprobar que funciona.');
    }

    /** Prueba de conexión: conecta y autentica, NO envía ningún correo. */
    public function probarConexion(PruebaConexionSmtp $prueba): RedirectResponse
    {
        $resultado = $prueba->ejecutar();

        return redirect()
            ->route('configuracion.correo.edit')
            ->with($resultado->exito ? 'status' : 'error', $resultado->mensaje);
    }

    // ---------------------------------------------------------------- interno

    /**
     * Reglas del formulario. Duplican a propósito lo que el registry ya valida: son
     * las que producen el mensaje en el campo correcto. La del registry es la
     * segunda barrera, la que protege a cualquier otro llamador.
     *
     * @return array<string, mixed>
     */
    private function validarSmtp(Request $request): array
    {
        return $request->validate([
            'servidor' => ['nullable', 'string', 'max:255'],
            'puerto' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'seguridad' => ['required', 'in:auto,smtp,smtps'],
            'usuario' => ['nullable', 'string', 'max:255'],
            'remitente' => ['nullable', 'email', 'max:255'],
            'remitente_nombre' => ['nullable', 'string', 'max:120'],
        ], [], [
            'servidor' => 'servidor',
            'puerto' => 'puerto',
            'seguridad' => 'seguridad de la conexión',
            'usuario' => 'usuario',
            'remitente' => 'correo remitente',
            'remitente_nombre' => 'nombre remitente',
        ]);
    }

    /**
     * Estado de cada campo SMTP para la pantalla, indexado por el NOMBRE DEL CAMPO
     * (no por la clave técnica): así la vista no tiene que conocer el registry.
     *
     * @return array<string, EstadoAjuste>
     */
    private function estadosSmtp(ServicioAjustes $ajustes): array
    {
        $estados = [];

        foreach (self::CAMPOS_SMTP as $campo => $clave) {
            $estados[$campo] = $ajustes->estadoParaPantalla($clave);
        }

        return $estados;
    }
}
