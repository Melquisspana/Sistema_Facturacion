<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\EstadoAjuste;
use App\Facades\Ajustes;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Contraseña del servidor SMTP: el PRIMER secreto que se puede escribir desde la
 * aplicación.
 *
 * TIENE PANTALLA PROPIA, y no es por comodidad. La confirmación N2 del resto de
 * los campos del servidor funciona reenviando los valores en campos ocultos para
 * poder enseñar el antes y el después; un secreto no puede hacer ese viaje ni una
 * sola vez. Aquí la consecuencia se explica ANTES, en la misma pantalla, y el
 * usuario marca la casilla de confirmación junto al campo: misma ceremonia N2
 * —resumen, consecuencia, confirmar o cancelar— sin que el secreto dé una vuelta
 * por el HTML.
 *
 * QUÉ NUNCA PASA POR AQUÍ:
 *  - el campo no se precarga, ni con el valor ni con un relleno que insinúe su
 *    longitud;
 *  - la vista no recibe el valor: recibe {@see EstadoAjuste}, que para
 *    un secreto se construye con el valor en null;
 *  - la auditoría registra «reemplazó el secreto», sin valores y sin hash.
 *
 * UN CAMPO VACÍO NO BORRA NADA. Enviar el formulario en blanco es un descuido, no
 * una orden: la validación lo rechaza. Para volver al valor del .env está la
 * acción separada de quitar el override, que se pide a propósito.
 */
class PasswordSmtpController extends Controller
{
    private const CLAVE = 'mail.smtp.password';

    public function edit(ServicioAjustes $ajustes): View
    {
        return view('configuracion.correo.password-smtp', [
            'estado' => $ajustes->estadoParaPantalla(self::CLAVE),
            // ¿Hay algo que quitar? Solo si el valor vive en la base de datos: el
            // .env no se toca desde aquí, así que ofrecer "quitar" cuando el valor
            // viene del archivo sería ofrecer un botón que no puede hacer nada.
            'hayOverride' => $ajustes->fuente(self::CLAVE) === FuenteAjuste::BaseDeDatos,
        ]);
    }

    /**
     * Reemplaza la contraseña. `confirmacion` es la ceremonia N2: sin ella no se
     * escribe, aunque venga una contraseña válida.
     */
    public function update(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            // `required` es la regla que impide que un envío en blanco borre la
            // contraseña. No hay `nullable` a propósito.
            'password' => ['required', 'string', 'max:255'],
            'confirmacion' => ['accepted'],
        ], [
            'password.required' => 'Escribí la contraseña nueva. Dejar el campo vacío no borra la actual.',
            'confirmacion.accepted' => 'Confirmá que querés reemplazar la contraseña del servidor de correo.',
        ], [
            'password' => 'contraseña',
        ]);

        // Ajustes::guardar cifra con Crypt, invalida la caché compartida y emite la
        // auditoría del reemplazo. Este controlador no toca nada de eso.
        Ajustes::guardar(self::CLAVE, $datos['password']);

        return redirect()
            ->route('configuracion.correo.edit')
            ->with('status', 'Contraseña del servidor de correo reemplazada. Probá la conexión para comprobar que funciona.');
    }

    /** Quita el override y devuelve la contraseña al archivo de configuración. */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'confirmacion' => ['accepted'],
        ], [
            'confirmacion.accepted' => 'Confirmá que querés volver a la contraseña del archivo de configuración.',
        ]);

        Ajustes::quitarOverride(self::CLAVE);

        return redirect()
            ->route('configuracion.correo.edit')
            ->with('status', 'Se quitó la contraseña guardada en la aplicación: vuelve a usarse la del archivo de configuración.');
    }
}
