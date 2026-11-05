<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\Ceremonias\CambioPropuesto;
use App\Ajustes\Ceremonias\ConfirmacionN2;
use App\Ajustes\Excepciones\ValorAjusteInvalidoException;
use App\Enums\ModuloSistema;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Configuración › Módulos: prender y apagar Gastos, Producción, Asistencia,
 * Planilla y Pagos de planilla.
 *
 * Lo guardado acá manda sobre el .env (ver {@see ModuloSistema}). Prender no pide
 * confirmación: no esconde nada ni toca datos. APAGAR sí la pide (N2): la pantalla
 * dice qué deja de verse de cada módulo que se apaga, y recuerda que los datos no
 * se borran. Cada cambio queda en la auditoría de ajustes con el antes y el
 * después.
 *
 * Las claves salen de {@see ModuloSistema}, nunca del navegador: el formulario
 * manda un casillero por módulo con el nombre del caso.
 */
class ModulosController extends Controller
{
    public function index(ServicioAjustes $ajustes): View
    {
        return view('configuracion.modulos.index', [
            'modulos' => array_map(static fn (ModuloSistema $modulo) => [
                'modulo' => $modulo,
                'estado' => $ajustes->estadoParaPantalla($modulo->clave()),
            ], ModuloSistema::cases()),
        ]);
    }

    public function update(Request $request, ConfirmacionN2 $confirmacion, ServicioAjustes $ajustes): View|RedirectResponse
    {
        $request->validate(array_fill_keys(
            array_map(static fn (ModuloSistema $m) => $m->value, ModuloSistema::cases()),
            ['nullable', 'boolean'],
        ));

        // Una casilla ausente es «apagado», no «no la toques».
        $propuestos = [];
        foreach (ModuloSistema::cases() as $modulo) {
            $propuestos[$modulo->clave()] = $request->boolean($modulo->value);
        }

        $cambios = $confirmacion->calcular($propuestos);

        if ($cambios === []) {
            return redirect()->route('configuracion.modulos')->with('status', 'No había nada que cambiar en los módulos.');
        }

        $apagados = $this->apagados($cambios, $propuestos);

        if ($apagados !== [] && ! $request->boolean('confirmacion')) {
            return view('configuracion.modulos.confirmar', [
                'cambios' => $cambios,
                'apagados' => $apagados,
                // Se reenvía la decisión ya validada, como '1' o '0'. Ninguno es secreto.
                'valores' => array_map(
                    static fn (bool $v) => $v ? '1' : '0',
                    array_combine(
                        array_map(static fn (ModuloSistema $m) => $m->value, ModuloSistema::cases()),
                        array_values($propuestos),
                    ),
                ),
            ]);
        }

        // Solo se escribe lo que cambia: un módulo que se deja como estaba no pasa a
        // «guardado en la aplicación» y sigue obedeciendo al .env.
        $aGuardar = [];
        foreach ($cambios as $cambio) {
            $aGuardar[$cambio->clave] = $propuestos[$cambio->clave];
        }

        try {
            $ajustes->guardarVarios($aGuardar);
        } catch (ValorAjusteInvalidoException $e) {
            throw ValidationException::withMessages(['modulos' => $e->getMessage()]);
        }

        return redirect()->route('configuracion.modulos')->with('status', $this->mensaje($cambios, $propuestos));
    }

    /**
     * Módulos que este envío APAGA.
     *
     * @param  array<int, CambioPropuesto>  $cambios
     * @param  array<string, bool>  $propuestos
     * @return array<int, ModuloSistema>
     */
    private function apagados(array $cambios, array $propuestos): array
    {
        $apagados = [];

        foreach ($cambios as $cambio) {
            if ($propuestos[$cambio->clave] === false) {
                $apagados[] = $this->moduloDe($cambio->clave);
            }
        }

        return $apagados;
    }

    /**
     * @param  array<int, CambioPropuesto>  $cambios
     * @param  array<string, bool>  $propuestos
     */
    private function mensaje(array $cambios, array $propuestos): string
    {
        $partes = array_map(
            fn (CambioPropuesto $c) => $this->moduloDe($c->clave)->etiqueta().($propuestos[$c->clave] ? ' encendido' : ' apagado'),
            $cambios,
        );

        return implode(', ', $partes).'.';
    }

    private function moduloDe(string $clave): ModuloSistema
    {
        foreach (ModuloSistema::cases() as $modulo) {
            if ($modulo->clave() === $clave) {
                return $modulo;
            }
        }

        throw new \LogicException("«{$clave}» no es un módulo.");
    }
}
