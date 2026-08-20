<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\CatalogoAjustes;
use App\Ajustes\Definicion\DefinicionAjuste;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\EstadoAjuste;
use App\Facades\Ajustes;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pantalla ÚNICA para reemplazar o quitar cualquier secreto administrable:
 * la contraseña SMTP, el secreto de cliente de Google, la del buzón de compras y
 * las que vengan.
 *
 * DE DÓNDE SALE LA CLAVE. Del archivo de rutas, como valor por defecto de la
 * ruta (`->defaults('clave', ...)`), NUNCA de la petición. Es la diferencia entre
 * "cada secreto tiene su URL" y "hay una URL que escribe el secreto que le
 * mandes". Además se exige que la definición sea de tipo secreto y editable: aun
 * si alguien registrara mal una ruta, esta pantalla no puede escribir un ajuste
 * que no lo sea.
 *
 * POR QUÉ LOS SECRETOS TIENEN PANTALLA PROPIA Y NO VAN EN EL FORMULARIO DE SU
 * SECCIÓN. La confirmación N2 de los demás campos reenvía los valores en campos
 * ocultos para poder enseñar el antes y el después; un secreto no puede hacer ese
 * viaje ni una vez. Aquí la consecuencia se explica ANTES, en la misma pantalla,
 * y el usuario marca la casilla de confirmación junto al campo: misma ceremonia
 * N2 —resumen, consecuencia, confirmar o cancelar— sin que el secreto dé una
 * vuelta por el HTML.
 *
 * QUÉ NUNCA PASA POR AQUÍ: el campo no se precarga (ni con el valor ni con un
 * relleno que insinúe su longitud), la vista recibe un {@see EstadoAjuste}
 * que para un secreto lleva el valor en null, y la auditoría registra «reemplazó
 * el secreto», sin valores y sin hash.
 *
 * UN CAMPO VACÍO NO BORRA NADA. Enviar el formulario en blanco es un descuido, no
 * una orden: la validación lo rechaza. Para volver al valor del .env está la
 * acción separada de quitar el override, que se pide a propósito.
 */
class SecretoController extends Controller
{
    public function edit(Request $request, ServicioAjustes $ajustes): View
    {
        $definicion = $this->definicion($request);

        return view('configuracion.secretos.edit', [
            'definicion' => $definicion,
            'estado' => $ajustes->estadoParaPantalla($definicion->clave),
            // ¿Hay algo que quitar? Solo si el valor vive en la base de datos: el
            // .env no se toca desde aquí, así que ofrecer "quitar" cuando el valor
            // viene del archivo sería ofrecer un botón que no puede hacer nada.
            'hayOverride' => $ajustes->fuente($definicion->clave) === FuenteAjuste::BaseDeDatos,
            'volver' => $this->volver($request),
            'rutas' => $this->rutas($request),
        ]);
    }

    /**
     * Reemplaza el secreto. `confirmacion` es la ceremonia N2: sin ella no se
     * escribe, aunque venga un valor válido.
     */
    public function update(Request $request): RedirectResponse
    {
        $definicion = $this->definicion($request);

        $datos = $request->validate([
            // `required` es la regla que impide que un envío en blanco borre el
            // secreto. No hay `nullable` a propósito.
            'password' => ['required', 'string', 'max:512'],
            'confirmacion' => ['accepted'],
        ], [
            'password.required' => 'Escribí el valor nuevo. Dejar el campo vacío no borra el actual.',
            'confirmacion.accepted' => 'Confirmá que querés reemplazar «'.$definicion->etiqueta.'».',
        ], [
            'password' => mb_strtolower($definicion->etiqueta),
        ]);

        // Ajustes::guardar cifra con Crypt, invalida la caché compartida y emite la
        // auditoría del reemplazo. Este controlador no toca nada de eso.
        Ajustes::guardar($definicion->clave, $datos['password']);

        return redirect($this->volver($request))
            ->with('status', $definicion->etiqueta.': valor reemplazado. Probá la conexión para comprobar que funciona.');
    }

    /** Quita el override y devuelve el secreto al archivo de configuración. */
    public function destroy(Request $request): RedirectResponse
    {
        $definicion = $this->definicion($request);

        $request->validate([
            'confirmacion' => ['accepted'],
        ], [
            'confirmacion.accepted' => 'Confirmá que querés volver al valor del archivo de configuración.',
        ]);

        Ajustes::quitarOverride($definicion->clave);

        return redirect($this->volver($request))
            ->with('status', $definicion->etiqueta.': se quitó el valor guardado en la aplicación y vuelve a usarse el del archivo de configuración.');
    }

    // ---------------------------------------------------------------- interno

    /**
     * Definición del secreto que administra ESTA ruta.
     *
     * `$request->route()->defaults` son los valores fijados en el archivo de
     * rutas; no hay forma de influir en ellos desde el navegador. La doble
     * comprobación (es secreto y es editable) protege del caso en que una ruta
     * futura se declare mal.
     */
    private function definicion(Request $request): DefinicionAjuste
    {
        $clave = (string) ($request->route()?->defaults['clave'] ?? '');

        abort_if($clave === '', 404);

        $definicion = app(CatalogoAjustes::class)->definicion($clave);

        abort_unless(
            $definicion->tipo->esSecreto() && $definicion->editabilidad->permiteEscritura(),
            404,
        );

        return $definicion;
    }

    /** A dónde vuelve el usuario. También sale del archivo de rutas. */
    private function volver(Request $request): string
    {
        return route((string) ($request->route()?->defaults['volver'] ?? 'configuracion.resumen'));
    }

    /**
     * URLs de las tres acciones de ESTE secreto, para que la vista sea una sola y
     * no tenga que conocer los nombres de ruta de cada integración.
     *
     * @return array{update: string, destroy: string}
     */
    private function rutas(Request $request): array
    {
        $base = (string) $request->route()?->getName();
        // Los tres nombres comparten prefijo y solo cambian en el sufijo.
        $prefijo = substr($base, 0, strrpos($base, '.') ?: 0);

        return [
            'update' => route($prefijo.'.update'),
            'destroy' => route($prefijo.'.destroy'),
        ];
    }
}
