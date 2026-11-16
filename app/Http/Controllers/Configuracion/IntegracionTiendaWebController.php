<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Ajustes;
use App\Ajustes\Ceremonias\ConfirmacionN2;
use App\Ajustes\Integraciones\ConfiguracionTiendaWeb;
use App\Http\Controllers\Configuracion\Concerns\GuardaConConfirmacionN2;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IntegracionTiendaWebController extends Controller
{
    use GuardaConConfirmacionN2;

    private const CAMPOS = ['url' => 'tienda.url', 'automatica' => 'tienda.pedidos.automatica'];

    public function index(Ajustes $ajustes, ConfiguracionTiendaWeb $configuracion): View
    {
        return view('configuracion.integraciones.tienda', [
            'completa' => $configuracion->completo(),
            'url' => $ajustes->estadoParaPantalla('tienda.url'),
            'automatica' => $ajustes->estadoParaPantalla('tienda.pedidos.automatica'),
            'secreto' => $ajustes->estadoParaPantalla('tienda.secreto'),
        ]);
    }

    public function update(Request $request, ConfirmacionN2 $confirmacion): View|RedirectResponse
    {
        $datos = $request->validate(['url' => ['nullable', 'string', 'max:255', function ($atributo, $valor, $fallar) {
            if (! ConfiguracionTiendaWeb::urlValida($valor)) {
                $fallar('Escribí una URL HTTPS sin usuario, contraseña, consulta ni fragmento.');
            }
        }]]);
        $datos['automatica'] = $request->boolean('automatica');

        return $this->guardarConConfirmacion(
            $request, $confirmacion, self::CAMPOS, $datos,
            volver: 'configuracion.integraciones.tienda',
            titulo: 'Vas a cambiar la integración con la tienda web',
            consecuencia: 'Al encender la lectura automática se traerán los pedidos pagados a la fábrica cada minuto.',
            exito: 'Integración con la tienda web guardada.',
            sinCambios: 'No había nada que cambiar en la tienda web.',
        );
    }
}
