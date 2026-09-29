<?php

namespace App\Services\Gastos;

use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\User;
use App\Services\Gastos\Contracts\ProteccionDeGastos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quién alcanza qué en Gastos. El ámbito NO es una etiqueta: es un candado.
 *
 * Hay tres alcances distintos y no se pueden confundir:
 *
 *  - {@see ver()}: una obligación concreta. Personal exige `gastos.personales`.
 *  - {@see pagoCompleto()}: la CABECERA de un pago (su importe total, referencia,
 *    nota, método, fecha, quién pagó) y sus comprobantes. Exige alcanzar TODAS las
 *    obligaciones que ese pago cubre, porque el total de un pago mixto revela el
 *    importe personal aunque se oculte su fila.
 *  - {@see aplicacionesVisibles()}: las filas `(pago, cuota, importe)` que caen
 *    sobre obligaciones a las que el usuario SÍ alcanza. Esto no se le niega a
 *    nadie que pueda ver la obligación: si alguien ve un gasto empresarial, tiene
 *    que poder ver cuánto se le aplicó. Lo que no ve es el resto del pago.
 *
 * Ocultar la cabecera y a la vez mostrar las aplicaciones propias es exactamente
 * el acuerdo de fase 1: «puede ver sus aplicaciones empresariales y su subtotal,
 * nunca importe total mixto, referencia, nota global, datos personales ni
 * comprobante compartido».
 *
 * Y un candado más, que llegó con Planilla: hay obligaciones que son gastos de verdad
 * pero cuyo importe es el sueldo de una persona. {@see ProteccionDeGastos} deja que el
 * módulo que las creó diga quién las alcanza. Se pregunta ACÁ, en `ver()`, porque es el
 * embudo por el que pasan registrar un pago, revertirlo y abrir la ficha: cerrar solo
 * las pantallas de Planilla dejaba abierta la puerta de /gastos.
 */
final class AccesoGastos
{
    public function __construct(private ProteccionDeGastos $proteccion) {}

    public function ver(User $usuario, Gasto $gasto): bool
    {
        return $usuario->activo && $usuario->can('gastos.ver')
            && ($gasto->ambito === 'empresarial' || $usuario->can('gastos.personales'))
            && $this->proteccion->puedeVer($usuario, $gasto->id);
    }

    /** ¿Alcanza TODAS las obligaciones que cubre este pago? */
    public function pagoCompleto(User $usuario, Pago $pago): bool
    {
        $gastos = $this->gastosDelPago($pago);

        return $gastos->isNotEmpty() && $gastos->every(fn (Gasto $gasto) => $this->ver($usuario, $gasto));
    }

    /**
     * Filas del pago que este usuario puede ver, con el gasto y el ámbito de cada
     * una. Un pago mixto devuelve solo la parte alcanzable; uno de un solo ámbito
     * al que no se alcanza devuelve vacío.
     *
     * @return Collection<int, object{cuota_id: int, gasto_id: int, ambito: string, importe: string, numero: int}>
     */
    public function aplicacionesVisibles(User $usuario, Pago $pago): Collection
    {
        $permitidos = $this->gastosDelPago($pago)
            ->filter(fn (Gasto $gasto) => $this->ver($usuario, $gasto))
            ->pluck('id');

        if ($permitidos->isEmpty()) {
            return collect();
        }

        return DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_cuotas as c', 'c.id', '=', 'a.cuota_id')
            ->join('gastos as g', 'g.id', '=', 'c.gasto_id')
            ->where('a.pago_id', $pago->id)
            ->whereIn('g.id', $permitidos)
            ->orderBy('c.gasto_id')->orderBy('c.numero')
            ->get(['a.cuota_id', 'c.gasto_id', 'c.numero', 'g.ambito', 'a.importe']);
    }

    /**
     * Suma en centavos de lo que este usuario puede ver aplicado. Para un pago de
     * un solo ámbito alcanzable coincide con su importe; para uno mixto es el
     * subtotal de su parte, NUNCA el total.
     */
    public function subtotalVisible(User $usuario, Pago $pago): int
    {
        return $this->aplicacionesVisibles($usuario, $pago)
            ->sum(fn (object $fila) => Dinero::centavos((string) $fila->importe));
    }

    /** @return Collection<int, Gasto> */
    private function gastosDelPago(Pago $pago): Collection
    {
        return Gasto::whereIn('id', DB::table('gastos_cuotas')
            ->join('gastos_pago_aplicaciones as a', 'a.cuota_id', '=', 'gastos_cuotas.id')
            ->where('a.pago_id', $pago->id)
            ->select('gastos_cuotas.gasto_id'))->get();
    }
}
