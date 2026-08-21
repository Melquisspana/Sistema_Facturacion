<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Fiscal\EstadoHaciendaApi;
use App\Ajustes\Fiscal\InventarioFiscal;
use App\Ajustes\Fiscal\PruebaConexionHacienda;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Facturación electrónica → Hacienda / API.
 *
 * PANTALLA DE SOLO LECTURA. No tiene ninguna ruta de escritura: ni un PUT, ni un
 * formulario, ni un campo. Todo lo que muestra se administra en el archivo de
 * configuración del servidor, y eso se dice en la pantalla en vez de dejar que el
 * usuario lo deduzca de que no hay botón de guardar.
 *
 * La ÚNICA acción es «Probar conexión», que inicia sesión contra el ambiente de
 * PRUEBAS del Ministerio de Hacienda y no transmite ningún documento.
 */
class HaciendaController extends Controller
{
    public function index(
        EstadoHaciendaApi $estado,
        InventarioFiscal $inventario,
        RegistroVerificaciones $verificaciones,
    ): View {
        return view('configuracion.fiscal.hacienda', [
            'estado' => $estado->paraPantalla(),
            'prueba' => $estado->pruebaDisponible(),
            'ajustes' => $inventario->hacienda(),
            'candados' => $inventario->candados(),
            'resumenCandados' => $inventario->resumenCandados(),
            'muertas' => $inventario->configuracionMuerta(),
            'ultimaPrueba' => $verificaciones->ultima(EstadoHaciendaApi::CLAVE_VERIFICACION),
        ]);
    }

    /** Solo inicia sesión contra apitest. NO transmite, NO firma, NO toca ningún DTE. */
    public function probar(PruebaConexionHacienda $prueba): RedirectResponse
    {
        $resultado = $prueba->ejecutar();

        return redirect()
            ->route('configuracion.fiscal.hacienda')
            ->with($resultado->exito ? 'status' : 'error', $resultado->mensaje);
    }
}
