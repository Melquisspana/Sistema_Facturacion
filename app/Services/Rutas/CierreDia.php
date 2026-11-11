<?php

namespace App\Services\Rutas;

use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Lo que quedó del cierre de día de las salidas armadas a mano (retirado el 11/11/2026: el
 * dinero se entrega con el PDF del día firmado). El flujo nuevo usa de acá las cuentas en
 * centavos, el candado de la salida ya liquidada y el aviso de saldos de visitas anteriores.
 * Los cierres ya guardados (`salida_ruta_cierres`) se siguen leyendo: {@see SalidaRuta::diaCerrado()}.
 */
class CierreDia
{
    /** Calcula en centavos: ninguna suma monetaria depende de aritmética flotante. */
    public static function centavos(string|int|float $monto): int
    {
        $partes = explode('.', (string) $monto);

        $signo = str_starts_with((string) $monto, '-') ? -1 : 1;

        return $signo * ((abs((int) $partes[0]) * 100) + (int) str_pad(substr($partes[1] ?? '', 0, 2), 2, '0'));
    }

    public static function decimal(int $centavos): string
    {
        return ($centavos < 0 ? '-' : '').intdiv(abs($centavos), 100).'.'.str_pad((string) (abs($centavos) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** La salida de antes que se liquidó ya no se toca: ni sus días, ni sus entregas, ni sus gastos. */
    public static function exigirSinLiquidar(SalidaRuta $salida): void
    {
        if ($salida->estaLiquidada()) {
            throw ValidationException::withMessages(['cierre' => 'La ruta ya está terminada: sus días no se cambian.']);
        }
    }

    /** Saldos informativos de salidas anteriores, incluso si salieron el mismo día. */
    public function saldosAnteriores(SalidaRuta $salida, array $salas): Collection
    {
        return SalidaRutaEntrega::with('dte')->entregadas()->whereIn('cliente_sucursal_id', $salas)
            ->whereHas('salida', fn ($q) => $q->where('fecha_inicio', '<', $salida->fecha_inicio->toDateString())
                ->orWhere(fn ($q) => $q->whereDate('fecha_inicio', $salida->fecha_inicio->toDateString())->where('id', '<', $salida->id)))
            ->whereColumn('monto_cobrado', '<', 'monto_esperado')->get()->groupBy('cliente_sucursal_id');
    }
}
