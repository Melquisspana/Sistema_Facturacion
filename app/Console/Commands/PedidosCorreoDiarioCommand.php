<?php

namespace App\Console\Commands;

use App\Services\Rutas\ResumenDiarioPedidos;
use Illuminate\Console\Command;

/**
 * Manda el resumen diario de los pedidos de sala ({@see ResumenDiarioPedidos}). Lo corre el
 * planificador a las 18:00, hora de El Salvador. Una vez por día: correrlo de nuevo no lo
 * repite, y antes de las 18:00 no sale. Con --forzar sale ya, sin contar como el del día.
 * Sin correo en Configuración › Rutas no manda nada y lo deja en el log.
 */
class PedidosCorreoDiarioCommand extends Command
{
    protected $signature = 'pedidos:correo-diario
        {--forzar : Lo manda ya, aunque sea temprano o ya haya salido; no cuenta como el de las 6}';

    protected $description = 'Manda el resumen diario de los pedidos de sala (una vez por día)';

    public function handle(ResumenDiarioPedidos $resumen): int
    {
        $resultado = $resumen->enviar((bool) $this->option('forzar'));

        $mensaje = match ($resultado) {
            'sin_destinatarios' => 'No se mandó: falta el correo en Configuración › Rutas.',
            'temprano' => 'Todavía no son las 6 de la tarde: el resumen del día sale a esa hora. Para mandarlo ya, sin que cuente como el de las 6, usá --forzar.',
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
