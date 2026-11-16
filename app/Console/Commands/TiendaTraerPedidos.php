<?php

namespace App\Console\Commands;

use App\Services\TiendaWeb\FacturarPedidoWeb;
use App\Services\TiendaWeb\ImportarPedidosWeb;
use App\Services\TiendaWeb\InformarEstadoPedidoWeb;
use Illuminate\Console\Command;
use Throwable;

class TiendaTraerPedidos extends Command
{
    protected $signature = 'tienda:traer-pedidos {--aplicar : Guarda los pedidos, emite su factura y avisa a la tienda}';

    protected $description = 'Trae pedidos pagados de la tienda y los factura; sin --aplicar solo cuenta, sin escribir';

    public function handle(ImportarPedidosWeb $importador, FacturarPedidoWeb $facturador, InformarEstadoPedidoWeb $estados): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $fallo = false;
        $conteo = [];
        try {
            $conteo = $importador->ejecutar($aplicar);
        } catch (Throwable) {
            $this->error('No se pudo completar la importación. Revisá la configuración y reintentá en la próxima pasada.');
            $fallo = true;
        }
        // Lo ya importado se factura aunque la tienda no haya respondido en esta pasada.
        try {
            $conteo += $facturador->pasada($aplicar);
        } catch (Throwable) {
            $this->error('No se pudo completar la facturación de los pedidos web. Se reintentará en la próxima pasada.');
            $fallo = true;
        }
        $estados->pasada($aplicar);
        $this->info(($aplicar ? 'Aplicado' : 'Simulación').': '.implode(', ', array_map(
            fn ($clave, $valor) => $clave.'='.$valor, array_keys($conteo), $conteo,
        )));

        return $fallo ? self::FAILURE : self::SUCCESS;
    }
}
