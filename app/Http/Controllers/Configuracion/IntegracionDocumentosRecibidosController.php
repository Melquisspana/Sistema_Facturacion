<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\Ceremonias\ConfirmacionN2;
use App\Ajustes\EstadoAjuste;
use App\Ajustes\Integraciones\ConfiguracionDocumentosRecibidos;
use App\Ajustes\Integraciones\PruebaConexionImap;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Http\Controllers\Configuracion\Concerns\GuardaConConfirmacionN2;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Integraciones → Buzón de compras (IMAP).
 *
 * Configura de dónde se leen los documentos que mandan los proveedores. El lector
 * sigue siendo de SOLO LECTURA: no borra, no mueve y no marca como leído — ni
 * usándolo ni probándolo.
 *
 * NO CAMBIA LA LÓGICA DE COMPRAS. El parseo, la clasificación, las exclusiones y
 * el envío a contabilidad siguen igual; lo único que cambió es de dónde salen el
 * servidor y las credenciales, y sin overrides guardados salen del mismo sitio de
 * siempre.
 *
 * LA CONTRASEÑA NO ESTÁ EN {@see self::CAMPOS} y no llega a la vista: tiene su
 * propia pantalla, porque la confirmación N2 reenvía los valores en campos
 * ocultos. El usuario del buzón sí se muestra, y parcialmente tapado.
 */
class IntegracionDocumentosRecibidosController extends Controller
{
    use GuardaConConfirmacionN2;

    /** Campo del formulario ⇒ clave del registry. Mapa FIJO: nada viene del navegador. */
    private const CAMPOS = [
        'lectura' => 'documentos_recibidos.driver',
        'servidor' => 'documentos_recibidos.host',
        'puerto' => 'documentos_recibidos.port',
        'seguridad' => 'documentos_recibidos.encryption',
        'usuario' => 'documentos_recibidos.username',
        'carpeta' => 'documentos_recibidos.folder',
        'busqueda' => 'documentos_recibidos.search',
        'espera' => 'documentos_recibidos.timeout',
        'limite' => 'documentos_recibidos.limite',
    ];

    public function index(
        ServicioAjustes $ajustes,
        ConfiguracionDocumentosRecibidos $configuracion,
        RegistroVerificaciones $verificaciones,
    ): View {
        return view('configuracion.integraciones.documentos-recibidos', [
            'campos' => $this->estados($ajustes),
            'secreto' => $ajustes->estadoParaPantalla('documentos_recibidos.password'),
            'carpeta' => $ajustes->estadoParaPantalla('documentos_recibidos.storage_dir'),
            'estado' => $configuracion->paraPantalla(),
            'completa' => $configuracion->completa(),
            'ultimaPrueba' => $verificaciones->ultima(ConfiguracionDocumentosRecibidos::CLAVE_VERIFICACION),
            // El lector necesita la extensión de PHP; decirlo evita que alguien
            // persiga durante media hora una configuración que está bien.
            'soportado' => function_exists('imap_open'),
        ]);
    }

    public function update(Request $request, ConfirmacionN2 $confirmacion): View|RedirectResponse
    {
        $datos = $request->validate([
            'lectura' => ['required', 'in:imap,none'],
            'servidor' => ['nullable', 'string', 'max:255'],
            'puerto' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'seguridad' => ['required', 'in:ssl,tls,ninguna'],
            'usuario' => ['nullable', 'string', 'max:255'],
            'carpeta' => ['nullable', 'string', 'max:120'],
            'busqueda' => ['nullable', 'string', 'max:255'],
            'espera' => ['nullable', 'integer', 'min:1', 'max:120'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:500'],
        ], [], [
            'lectura' => 'lectura del buzón',
            'servidor' => 'servidor',
            'puerto' => 'puerto',
            'seguridad' => 'seguridad de la conexión',
            'usuario' => 'usuario',
            'carpeta' => 'carpeta',
            'busqueda' => 'filtro de búsqueda',
            'espera' => 'tiempo de espera',
            'limite' => 'correos por sincronización',
        ]);

        return $this->guardarConConfirmacion(
            $request,
            $confirmacion,
            self::CAMPOS,
            $datos,
            volver: 'configuracion.integraciones.documentos-recibidos',
            titulo: 'Vas a cambiar el buzón de compras',
            consecuencia: 'Si los datos de conexión no son correctos, la revisión del buzón deja de traer documentos y las compras hay que cargarlas a mano. Nada de lo ya descargado se pierde. Después de guardar, usá «Probar conexión».',
            exito: 'Buzón de compras guardado. Probá la conexión para comprobar que funciona.',
            sinCambios: 'No había nada que cambiar en el buzón de compras.',
        );
    }

    /** Abre y cierra el buzón en solo lectura. NO lee correos ni sincroniza. */
    public function probar(PruebaConexionImap $prueba): RedirectResponse
    {
        $resultado = $prueba->ejecutar();

        return redirect()
            ->route('configuracion.integraciones.documentos-recibidos')
            ->with($resultado->exito ? 'status' : 'error', $resultado->mensaje);
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
