<?php

namespace App\Console\Commands;

use App\Services\Rutas\ResumenDiarioPedidos;
use Illuminate\Console\Command;

/**
 * Manda el resumen diario de los pedidos de sala ({@see ResumenDiarioPedidos}). Lo corre el
 * planificador a las 17:00, hora de El Salvador. Una vez por día: correrlo de nuevo no lo
 * repite. Sin correo en Configuración › Rutas no manda nada y lo deja en el log.
 */
class PedidosCorreoDiarioCommand extends Command
{
    protected $signature = 'pedidos:correo-diario';

    protected $description = 'Manda el resumen diario de los pedidos de sala (una vez por día)';

    public function handle(ResumenDiarioPedidos $resumen): int
    {
        $resultado = $resumen->enviar();

        $mensaje = match ($resultado) {
            'sin_destinatarios' => 'No se mandó: falta el correo en Configuración › Rutas.',
            'ya_enviado' => 'El resumen de hoy ya se había mandado.',
            'simulado' => 'Resumen SIMULADO: fuera de producción el correo no sale.',
            'enviado' => 'Resumen enviado.',
            'fallido' => 'El resumen no se pudo mandar. Mirá el log antes de reintentar: pudo haber salido igual.',
        };

        if ($resultado === 'fallido') {
            $this->error($mensaje);

            return self::FAILURE;
        }

        $this->info($mensaje);

        return self::SUCCESS;
    }
}
