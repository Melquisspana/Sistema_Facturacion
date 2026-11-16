<?php

namespace App\Console\Commands;

use App\Services\TiendaWeb\ImportarPedidosWeb;
use Illuminate\Console\Command;
use Throwable;

class TiendaTraerPedidos extends Command
{
    protected $signature = 'tienda:traer-pedidos {--aplicar : Guarda los pedidos y avisa a la tienda}';

    protected $description = 'Trae pedidos pagados de la tienda; sin --aplicar solo cuenta, sin escribir';

    public function handle(ImportarPedidosWeb $importador): int
    {
        try {
            $conteo = $importador->ejecutar((bool) $this->option('aplicar'));
        } catch (Throwable) {
            $this->error('No se pudo completar la importación. Revisá la configuración y reintentá en la próxima pasada.');

            return self::FAILURE;
        }
        $this->info(($this->option('aplicar') ? 'Aplicado' : 'Simulación').': '.implode(', ', array_map(
            fn ($clave, $valor) => $clave.'='.$valor, array_keys($conteo), $conteo,
        )));

        return self::SUCCESS;
    }
}
