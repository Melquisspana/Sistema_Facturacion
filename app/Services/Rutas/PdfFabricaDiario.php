<?php

namespace App\Services\Rutas;

use App\Enums\OrigenPedidoSala;
use App\Models\PedidoSalaEnvio;
use App\Services\Dte\DtePdfService;
use App\Support\NombreEmpresa;
use Barryvdh\DomPDF\Facade\Pdf;

class PdfFabricaDiario
{
    public function html(array $contenido): string
    {
        $fabrica = app(PedidosFabrica::class);
        $hojas = [];
        foreach (PedidoSalaEnvio::with('lineas.linea.producto:id,nombre,codigo')->whereIn('id', $contenido['envios_ids'])->orderBy('id')->get() as $envio) {
            $pedido = $envio->pedido;
            $pedido->load($fabrica->relaciones(conSala: true));
            $hojas[] = ['pedido' => $pedido, 'envio' => $envio,
                'web' => $pedido->origen === OrigenPedidoSala::Web,
                'corrige' => $fabrica->corrigeA($pedido, $envio)] + $fabrica->faltanteAlEnvio($pedido, $envio);
        }

        return view('fabrica.pedidos.resumen-diario-pdf', [
            'contenido' => $contenido, 'hojas' => $hojas,
            'empresa' => NombreEmpresa::actual(), 'logo' => app(DtePdfService::class)->logoSrc(),
        ])->render();
    }

    public function binario(array $contenido): string
    {
        return Pdf::loadHTML($this->html($contenido))->setPaper('letter')->output();
    }
}
