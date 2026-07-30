<?php

namespace Database\Seeders;

use App\Models\Municipio;
use App\Services\Importacion\ImportadorCatalogosMh;
use App\Support\Ubicacion\VinculaMunicipioDistrito;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Puebla la tabla genérica `catalogos_mh` (CAT-001..CAT-033) desde el Excel oficial del
 * repo (resources/dte/catalogos/*.xlsx), igual que en producción. Es la tabla que consulta
 * la serialización del JSON oficial (p. ej. CAT-014 unidad de medida, CAT-019 actividad),
 * distinta de los catálogos propios (paises/municipios/unidades_medida) de {@see CatalogosMhSeeder}.
 *
 * Necesario en pruebas para poder GENERAR el JSON oficial de un DTE; sin esto la
 * serialización rechaza la unidad de medida ("CAT-014 no reconocido"). Idempotente.
 */
class CatalogosMhTablaSeeder extends Seeder
{
    public function run(): void
    {
        app(ImportadorCatalogosMh::class)->importar();

        // Con CAT-013 recién cargado ya se puede vincular cada distrito con su municipio
        // fiscal 2024 (distritos.municipio_codigo). Es lo que permite validar la coherencia
        // municipio ↔ distrito por código. Se hace acá porque la migración corre ANTES de
        // que exista el catálogo (BD nueva y suite de pruebas). Si los distritos todavía no
        // están sembrados, no hay nada que vincular y se sigue sin ruido.
        // Código oficial CAT-008 de cada distrito: es el valor que viaja en
        // `direccion.distrito`. Sin él el JSON saldría con `distrito: ""` y el MH lo
        // rechaza, así que se puebla junto con los catálogos y no a mano.
        Artisan::call('distritos:codigos-mh');

        try {
            VinculaMunicipioDistrito::ejecutar();
            // Y con los distritos ya vinculados, completar el CAT-013 de los municipios
            // que estén en NULL (el seeder liviano no lo trae). Solo rellena vacíos.
            VinculaMunicipioDistrito::completarCodigosMunicipio();
            Municipio::olvidarNombresFiscales();
        } catch (RuntimeException $e) {
            $this->command?->warn('No se pudo vincular distrito → municipio CAT-013: '.$e->getMessage());
        }
    }
}
