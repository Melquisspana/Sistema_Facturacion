<?php

namespace App\Http\Controllers\Configuracion\Concerns;

use App\Ajustes\Ceremonias\ConfirmacionN2;
use App\Ajustes\Excepciones\ValorAjusteInvalidoException;
use App\Facades\Ajustes;
use App\Http\Controllers\Configuracion\SecretoController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Guardado en DOS PASOS para las pantallas de configuración cuyos ajustes son N2.
 *
 * El primer envío no escribe: calcula qué cambiaría de verdad y devuelve la
 * pantalla de confirmación con el antes y el después. Solo el segundo, ya con
 * `confirmacion`, guarda. Lo comparten el servidor de correo y las dos
 * integraciones porque el flujo es idéntico y tenerlo tres veces copiado
 * garantizaba que las tres versiones se separaran con el tiempo.
 *
 * DE DÓNDE SALEN LAS CLAVES. Del mapa `$campos` que pasa el controlador —fijo, en
 * código—, nunca del navegador. El formulario manda nombres humanos («servidor»,
 * «puerto») y ese mapa decide a qué ajuste del registry corresponde cada uno. Un
 * campo oculto manipulado no puede elegir qué ajuste se escribe.
 *
 * LOS SECRETOS NO PASAN POR AQUÍ. Los valores ya validados se reenvían en campos
 * ocultos para poder mostrarlos; por eso cada secreto tiene su propia pantalla
 * ({@see SecretoController}) y nunca aparece
 * en `$campos`.
 */
trait GuardaConConfirmacionN2
{
    /**
     * @param  array<string, string>  $campos  campo del formulario ⇒ clave del registry
     * @param  array<string, mixed>  $datos  valores YA validados por el controlador
     * @param  string  $volver  nombre de ruta a la que se vuelve
     */
    protected function guardarConConfirmacion(
        Request $request,
        ConfirmacionN2 $confirmacion,
        array $campos,
        array $datos,
        string $volver,
        string $titulo,
        string $consecuencia,
        string $exito,
        string $sinCambios,
    ): View|RedirectResponse {
        $propuestos = [];
        foreach ($campos as $campo => $clave) {
            $propuestos[$clave] = $datos[$campo] ?? null;
        }

        $cambios = $confirmacion->calcular($propuestos);

        if ($cambios === []) {
            return redirect()->route($volver)->with('status', $sinCambios);
        }

        if (! $request->boolean('confirmacion') && $confirmacion->requiereConfirmacion($cambios)) {
            return view('configuracion.confirmar', [
                'titulo' => $titulo,
                'consecuencia' => $consecuencia,
                'cambios' => $cambios,
                'accion' => $request->url(),
                // Se reenvían los valores YA VALIDADOS, con los mismos nombres de
                // campo del formulario. Ninguno es secreto.
                'valores' => array_intersect_key($datos, $campos),
                'volver' => route($volver),
            ]);
        }

        try {
            Ajustes::guardarVarios($propuestos);
        } catch (ValorAjusteInvalidoException $e) {
            // La validación del registry es la segunda barrera; si salta, el error
            // vuelve al campo que lo produjo y no como un error 500.
            throw ValidationException::withMessages([
                (string) (array_search($e->clave, $campos, true) ?: array_key_first($campos)) => $e->getMessage(),
            ]);
        }

        return redirect()->route($volver)->with('status', $exito);
    }
}
