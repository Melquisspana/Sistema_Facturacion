<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\Ceremonias\ConfirmacionN2;
use App\Ajustes\EstadoAjuste;
use App\Ajustes\Integraciones\ConfiguracionBuzonAdicional;
use App\Ajustes\Integraciones\ConfiguracionDocumentosRecibidos;
use App\Ajustes\Integraciones\PruebaConexionImap;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Http\Controllers\Configuracion\Concerns\GuardaConConfirmacionN2;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Integraciones → Segundo buzón de compras (IMAP), para los cambios de correo.
 *
 * Igual que el buzón principal ({@see IntegracionDocumentosRecibidosController}):
 * estado, datos de conexión con confirmación N2, contraseña en la pantalla de secretos
 * y prueba de conexión en solo lectura. Sin servidor, el segundo buzón está apagado.
 *
 * Una regla propia: su carpeta no puede ser la del principal. El progreso de la
 * sincronización se lleva por carpeta y dos buzones con la misma mezclarían sus
 * cursores; `compras:sincronizar` ya frena en ese caso, y acá no se deja guardar.
 *
 * LA CONTRASEÑA NO ESTÁ EN {@see self::CAMPOS}: la confirmación N2 reenvía los valores
 * en campos ocultos.
 */
class IntegracionBuzonAdicionalController extends Controller
{
    use GuardaConConfirmacionN2;

    /** Campo del formulario ⇒ clave del registry. Mapa FIJO: nada viene del navegador. */
    private const CAMPOS = [
        'servidor' => 'documentos_recibidos.buzon_adicional.host',
        'puerto' => 'documentos_recibidos.buzon_adicional.port',
        'seguridad' => 'documentos_recibidos.buzon_adicional.encryption',
        'usuario' => 'documentos_recibidos.buzon_adicional.username',
        'carpeta' => 'documentos_recibidos.buzon_adicional.folder',
    ];

    public function index(
        ServicioAjustes $ajustes,
        ConfiguracionBuzonAdicional $configuracion,
        RegistroVerificaciones $verificaciones,
    ): View {
        $principal = $this->principal();

        return view('configuracion.integraciones.buzon-adicional', [
            'campos' => $this->estados($ajustes),
            'secreto' => $ajustes->estadoParaPantalla('documentos_recibidos.buzon_adicional.password'),
            'estado' => $configuracion->paraPantalla(),
            'completa' => $configuracion->completa(),
            'carpetaPrincipal' => $principal->carpeta(),
            'ultimaPrueba' => $verificaciones->ultima(ConfiguracionBuzonAdicional::CLAVE_VERIFICACION),
            'soportado' => function_exists('imap_open'),
        ]);
    }

    public function update(
        Request $request,
        ConfirmacionN2 $confirmacion,
        ConfiguracionBuzonAdicional $configuracion,
    ): View|RedirectResponse {
        $principal = $this->principal();

        $datos = $request->validate([
            'servidor' => ['nullable', 'string', 'max:255'],
            'puerto' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'seguridad' => ['required', 'in:ssl,tls,ninguna'],
            'usuario' => ['nullable', 'string', 'max:255'],
            'carpeta' => ['nullable', 'string', 'max:120'],
        ], [], [
            'servidor' => 'servidor',
            'puerto' => 'puerto',
            'seguridad' => 'seguridad de la conexión',
            'usuario' => 'usuario',
            'carpeta' => 'carpeta',
        ]);

        // Con el buzón encendido (servidor escrito o ya guardado), su carpeta no puede
        // ser la del principal. Vacía, la carpeta sigue siendo la que ya manda.
        $servidor = filled($datos['servidor'] ?? null) ? $datos['servidor'] : $configuracion->host();
        $carpeta = filled($datos['carpeta'] ?? null) ? trim((string) $datos['carpeta']) : $configuracion->carpeta();

        if (filled($servidor) && $carpeta === $principal->carpeta()) {
            throw ValidationException::withMessages([
                'carpeta' => 'La carpeta del segundo buzón tiene que ser distinta de la del principal («'.$principal->carpeta().'»): el progreso se lleva por carpeta y se mezclaría.',
            ]);
        }

        return $this->guardarConConfirmacion(
            $request,
            $confirmacion,
            self::CAMPOS,
            $datos,
            volver: 'configuracion.integraciones.buzon-adicional',
            titulo: 'Vas a cambiar el segundo buzón de compras',
            consecuencia: 'Si los datos de conexión no son correctos, la sincronización deja de traer los documentos que lleguen a ese buzón. Nada de lo ya descargado se pierde. Después de guardar, usá «Probar conexión».',
            exito: 'Segundo buzón guardado. Probá la conexión para comprobar que funciona.',
            sinCambios: 'No había nada que cambiar en el segundo buzón.',
        );
    }

    /** Abre y cierra el segundo buzón en solo lectura. NO lee correos ni sincroniza. */
    public function probar(ConfiguracionBuzonAdicional $configuracion): RedirectResponse
    {
        $resultado = app()->make(PruebaConexionImap::class, ['configuracion' => $configuracion])->ejecutar();

        return redirect()
            ->route('configuracion.integraciones.buzon-adicional')
            ->with($resultado->exito ? 'status' : 'error', $resultado->mensaje);
    }

    /**
     * El buzón principal se pide al contenedor y no como parámetro: el segundo hereda de
     * él, y la inyección de rutas descarta un parámetro cuyo tipo ya cubre otro.
     */
    private function principal(): ConfiguracionDocumentosRecibidos
    {
        return app(ConfiguracionDocumentosRecibidos::class);
    }

    /** @return array<string, EstadoAjuste> */
    private function estados(ServicioAjustes $ajustes): array
    {
        $estados = [];

        foreach (self::CAMPOS as $campo => $clave) {
            $estados[$campo] = $ajustes->estadoParaPantalla($clave);
        }

        return $estados;
    }
}
