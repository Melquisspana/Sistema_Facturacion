<?php

namespace Database\Seeders;

use App\Models\Departamento;
use App\Models\Municipio;
use App\Support\Ubicacion\VinculaMunicipioDistrito;
use Illuminate\Database\Seeder;

/**
 * Subconjunto útil de municipios por departamento para empezar a operar.
 * Incluye Olocuilta (La Paz). El código MH (CAT-013) se completará al importar
 * el catálogo oficial; aquí se siembran nombres y la relación al departamento.
 *
 * OJO: es un SUBCONJUNTO deliberado de municipios PRE-2024, no el catálogo completo, y
 * el select de municipio del formulario se arma con estas filas. Una AGRUPACIÓN fiscal
 * 2024 cuyos municipios anteriores no figuren acá se volvería inelegible y arrastraría
 * consigo a todos sus distritos —fue el caso de San Salvador Norte (CAT-013 20) y su
 * distrito Guazapa—. Ya no hace falta completar esta lista a mano para evitarlo:
 * {@see VinculaMunicipioDistrito::asegurarAgrupaciones()} corre después
 * (DistritoSeeder y CatalogosMhTablaSeeder) y crea la fila representante de cualquier
 * agrupación que quede sin una.
 */
class MunicipioSeeder extends Seeder
{
    public function run(): void
    {
        // Indexado por código de departamento (CAT-012).
        $municipiosPorDepto = [
            '01' => ['Ahuachapán', 'Atiquizaya', 'San Francisco Menéndez'],
            '02' => ['Santa Ana', 'Chalchuapa', 'Metapán'],
            '03' => ['Sonsonate', 'Izalco', 'Acajutla', 'Nahuizalco'],
            '04' => ['Chalatenango', 'Nueva Concepción'],
            '05' => ['Santa Tecla', 'Antiguo Cuscatlán', 'Colón', 'Quezaltepeque', 'San Juan Opico', 'Ciudad Arce'],
            '06' => ['San Salvador', 'Soyapango', 'Mejicanos', 'Apopa', 'Ciudad Delgado', 'Ilopango', 'San Marcos', 'Cuscatancingo', 'Ayutuxtepeque', 'San Martín'],
            '07' => ['Cojutepeque', 'Suchitoto'],
            '08' => ['Zacatecoluca', 'Olocuilta', 'San Pedro Masahuat', 'Santiago Nonualco', 'San Juan Nonualco', 'San Luis Talpa', 'El Rosario'],
            '09' => ['Sensuntepeque', 'Ilobasco'],
            '10' => ['San Vicente', 'Tecoluca'],
            '11' => ['Usulután', 'Jiquilisco', 'Santiago de María'],
            '12' => ['San Miguel', 'Chinameca', 'Ciudad Barrios'],
            '13' => ['San Francisco Gotera', 'Jocoro'],
            '14' => ['La Unión', 'Santa Rosa de Lima', 'Conchagua'],
        ];

        foreach ($municipiosPorDepto as $codigoDepto => $municipios) {
            $departamento = Departamento::where('codigo', $codigoDepto)->first();

            if (! $departamento) {
                continue;
            }

            foreach ($municipios as $nombre) {
                Municipio::updateOrCreate(
                    ['departamento_id' => $departamento->id, 'nombre' => $nombre],
                    ['activo' => true],
                );
            }
        }
    }
}
