<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Fiscal\EstadoFirmador;
use App\Ajustes\Fiscal\InventarioFiscal;
use App\Ajustes\Fiscal\PruebaFirmador;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Facturación electrónica → Certificado y firmador.
 *
 * PANTALLA DE SOLO LECTURA, igual que Hacienda / API. La única acción es «Probar
 * firma», que comprueba el firmador con un documento inventado y no firma nada
 * real.
 *
 * El certificado NO se sube desde aquí ni desde ninguna otra parte: vive en el
 * firmador del Ministerio de Hacienda. El motivo está explicado en
 * {@see EstadoFirmador} y se repite en la pantalla, porque es la primera pregunta
 * que hace cualquiera que la abra.
 */
class FirmadorController extends Controller
{
    public function index(
        EstadoFirmador $estado,
        InventarioFiscal $inventario,
        RegistroVerificaciones $verificaciones,
    ): View {
        return view('configuracion.fiscal.firmador', [
            'estado' => $estado->paraPantalla(),
            'prueba' => $estado->pruebaDisponible(),
            'ajustes' => $inventario->firmador(),
            'candados' => $inventario->candados()['Firma'],
            'ultimaPrueba' => $verificaciones->ultima(EstadoFirmador::CLAVE_VERIFICACION),
        ]);
    }

    /** Documento inventado, NIT de relleno y contraseña falsa. NO firma ningún DTE real. */
    public function probar(PruebaFirmador $prueba): RedirectResponse
    {
        $resultado = $prueba->ejecutar();

        return redirect()
            ->route('configuracion.fiscal.firmador')
            ->with($resultado->exito ? 'status' : 'error', $resultado->mensaje);
    }
}
