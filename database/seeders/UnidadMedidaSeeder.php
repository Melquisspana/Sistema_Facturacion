<?php

namespace Database\Seeders;

use App\Models\UnidadMedida;
use Illuminate\Database\Seeder;

/**
 * Unidades de medida comunes para facturación (CAT-014).
 *
 * Se llena el código MH para las de certeza: 59 = Unidad, 99 = Otra. "Bolsa"
 * también usa 99 porque CAT-014 NO tiene un código propio de bolsa/empaque, así
 * que se mapea a "Otra" (99). Las demás quedan con código nullable hasta importar
 * el catálogo CAT-014 vigente; sus nombres/abreviaturas ya son utilizables.
 */
class UnidadMedidaSeeder extends Seeder
{
    public function run(): void
    {
        $unidades = [
            ['codigo' => '59', 'nombre' => 'Unidad', 'abreviatura' => 'u'],
            ['codigo' => '99', 'nombre' => 'Otra', 'abreviatura' => null],
            ['codigo' => null, 'nombre' => 'Caja', 'abreviatura' => 'caja'],
            ['codigo' => '99', 'nombre' => 'Bolsa', 'abreviatura' => 'bolsa'], // CAT-014 no tiene bolsa → "Otra" (99)
            ['codigo' => null, 'nombre' => 'Libra', 'abreviatura' => 'lb'],
            ['codigo' => null, 'nombre' => 'Kilogramo', 'abreviatura' => 'kg'],
            ['codigo' => null, 'nombre' => 'Gramo', 'abreviatura' => 'g'],
            ['codigo' => null, 'nombre' => 'Litro', 'abreviatura' => 'L'],
            ['codigo' => null, 'nombre' => 'Docena', 'abreviatura' => 'doc'],
            ['codigo' => null, 'nombre' => 'Servicio', 'abreviatura' => 'serv'],
        ];

        foreach ($unidades as $unidad) {
            UnidadMedida::updateOrCreate(
                ['nombre' => $unidad['nombre']],
                [
                    'codigo' => $unidad['codigo'],
                    'abreviatura' => $unidad['abreviatura'],
                    'activo' => true,
                ],
            );
        }
    }
}
