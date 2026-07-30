<?php

use App\Support\Ubicacion\VinculaMunicipioDistrito;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula cada DISTRITO (CAT-008) con su MUNICIPIO fiscal 2024 (CAT-013).
 *
 * Por qué: `distritos.municipio` guarda el NOMBRE de la agrupación 2024 (p. ej.
 * "Cabañas Oeste") como texto suelto. Ese texto solo se usaba como etiqueta visual, así
 * que nada impedía guardar un municipio de "Cabañas Este" junto a un distrito de
 * "Cabañas Oeste": una combinación que Hacienda rechaza con
 * «[receptor.direccion.distrito] VALOR NO ES PERMITIDO».
 *
 * Esta migración agrega el código CAT-013 del municipio como DATO, para que la
 * pertenencia distrito → municipio se valide por código y no por texto.
 *
 * Diseño NO destructivo:
 *  - Solo AGREGA `municipio_codigo` (nullable) y un índice.
 *  - NO toca `distritos.codigo` (CAT-008) ni `distritos.municipio`.
 *  - NO renombra ni elimina columnas existentes.
 *  - NO toca DTE emitidos, JSON históricos ni las ubicaciones ya guardadas en
 *    clientes / salas / empresa (eso lo revisa `php artisan ubicaciones:auditar`).
 *
 * El backfill delega en {@see VinculaMunicipioDistrito}, fuente única del emparejamiento
 * (verificado 262/262 contra el catálogo oficial). Si un nombre queda ambiguo o sin
 * correspondencia, ABORTA con el detalle en vez de dejar filas en NULL. Cuando el
 * catálogo CAT-013 todavía no está cargado (BD nueva o suite de pruebas) no hay nada que
 * emparejar: lo completan el seeder de catálogos o `distritos:vincular-municipio`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distritos', function (Blueprint $table) {
            $table->string('municipio_codigo', 2)->nullable()->after('municipio')
                ->comment('Código CAT-013 del municipio 2024 al que pertenece el distrito');

            // Consulta típica: distritos de un municipio dentro de un departamento.
            $table->index(['departamento_id', 'municipio_codigo'], 'distritos_depto_municipio_cod_idx');
        });

        if (VinculaMunicipioDistrito::hayCatalogo()) {
            VinculaMunicipioDistrito::ejecutar();
        }
    }

    public function down(): void
    {
        Schema::table('distritos', function (Blueprint $table) {
            $table->dropIndex('distritos_depto_municipio_cod_idx');
            $table->dropColumn('municipio_codigo');
        });
    }
};
