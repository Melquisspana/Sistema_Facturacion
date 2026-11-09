<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\CatalogoAjustes;
use App\Ajustes\Ceremonias\CambioPropuesto;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «Usar el valor del servidor»: borra el valor guardado de UN ajuste para que vuelva a
 * mandar el .env (o, si el .env no dice nada, el valor por defecto).
 *
 * Es el mismo `Ajustes::quitarOverride()` que usan los secretos, con las mismas
 * barreras: la clave tiene que estar en la lista blanca del catálogo y ser editable,
 * el permiso lo exige el NIVEL del ajuste (un N3 seguiría pidiendo
 * `configuracion.critica`) y queda en la auditoría con el antes y el después.
 *
 * Respeta el nivel: un N1 se borra con el clic; un N2 muestra antes la confirmación con
 * el valor que vuelve a mandar. Los secretos no pasan por acá: tienen su pantalla, que
 * ya ofrece lo mismo sin anunciar ningún valor.
 */
class ValorServidorController extends Controller
{
    public function destroy(Request $request, string $clave, ServicioAjustes $ajustes, CatalogoAjustes $catalogo): View|RedirectResponse
    {
        abort_unless($catalogo->existe($clave), 404);

        $definicion = $catalogo->definicion($clave);

        abort_if($definicion->tipo->esSecreto() || ! $definicion->editabilidad->permiteEscritura(), 404);

        $volver = $this->volver($request);

        if ($ajustes->fuente($clave) !== FuenteAjuste::BaseDeDatos) {
            return redirect()->to($volver)->with('status', "«{$definicion->etiqueta}» ya usa el valor del servidor.");
        }

        if ($definicion->nivel->requiereConfirmacion() && ! $request->boolean('confirmacion')) {
            return view('configuracion.confirmar', [
                'titulo' => 'Vas a volver al valor del servidor',
                'consecuencia' => 'Se borra lo guardado en la aplicación y vuelve a mandar el archivo de configuración del servidor (.env) o, si no dice nada, el valor por defecto. Para cambiarlo otra vez, guardalo de nuevo en esta pantalla.',
                'cambios' => [CambioPropuesto::deValor(
                    $definicion,
                    $ajustes->resolverParaPantalla($clave)->valor,
                    $ajustes->valorDelServidor($clave)->valor,
                )],
                'accion' => $request->url(),
                'metodo' => 'DELETE',
                // A dónde volver después de confirmar: la confirmación se dibuja en la
                // URL de esta acción, así que sin esto el regreso caería acá mismo.
                'valores' => ['volver' => $volver],
                'volver' => $volver,
                'etiquetaConfirmar' => 'Usar el valor del servidor',
            ]);
        }

        $ajustes->quitarOverride($clave);

        return redirect()->to($volver)->with('status', "«{$definicion->etiqueta}» vuelve a usar el valor del servidor.");
    }

    /**
     * Pantalla de la que vino el pedido, solo si es de esta misma aplicación: un
     * `volver` armado a mano no puede convertir este botón en una redirección afuera.
     */
    private function volver(Request $request): string
    {
        $base = rtrim(url('/'), '/').'/';

        foreach ([(string) $request->input('volver', ''), url()->previous('')] as $candidata) {
            if ($candidata !== '' && str_starts_with($candidata, $base)
                && ! str_starts_with($candidata, $base.'configuracion/ajustes/')) {
                return $candidata;
            }
        }

        return route('configuracion.resumen');
    }
}
