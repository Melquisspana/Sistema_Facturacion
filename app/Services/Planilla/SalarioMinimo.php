<?php

namespace App\Services\Planilla;

use App\Services\Gastos\Dinero;
use Illuminate\Validation\ValidationException;

/**
 * El salario mínimo mensual del sector de la empresa, con vigencia.
 *
 * Sin un mínimo vigente lanza un error en vez de devolver cero: un cero aprobaría
 * cualquier sueldo sin que nadie se entere de que falta el dato.
 */
final class SalarioMinimo
{
    public function vigenteEn(string $fecha): int
    {
        $version = collect(config('planilla_descuentos.salario_minimo'))
            ->filter(fn (array $v) => $v['vigente_desde'] <= $fecha)
            ->sortBy('vigente_desde')
            ->last();

        $sector = (string) config('planilla.sector_salario_minimo');

        if ($version === null || ! isset($version['sectores'][$sector])) {
            throw ValidationException::withMessages([
                'sueldo_mensual' => 'No hay salario mínimo vigente al '.$fecha.' para el sector «'.$sector.'». Hay que cargarlo en la configuración.',
            ]);
        }

        return Dinero::centavos($version['sectores'][$sector]);
    }
}
