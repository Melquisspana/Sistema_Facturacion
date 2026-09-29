<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * El área «Cobros» pasa a ser el módulo de RUTAS (docs/DISENO_MODULO_RUTAS_20260926.md).
 *
 * Se va la custodia del CCF físico —entregar, transferir, recibir en oficina, anular— y el
 * seguimiento documental por salida que la sostenía: el seguimiento de CCF vive en Cobros
 * Calleja y el usuario confirmó que en producción esto nunca se usó. Con ella se va la
 * regla de «papel físico» del perfil de cliente, que solo podía cumplirse con esa custodia.
 *
 * Llega la COBERTURA de cada ruta: qué departamentos completos y qué distritos sueltos
 * atiende. Con ella se PROPONE la ruta de cada sala; asignarla sigue siendo un acto del
 * usuario sobre `cliente_sucursales.ruta_id`.
 *
 * Un departamento completo y un distrito son de UNA sola ruta a la vez (índices únicos).
 * Un distrito puede tener regla propia dentro de un departamento que cubre otra ruta: la
 * regla del distrito, más precisa, gana.
 */
return new class extends Migration
{
    private const PERMISOS_RETIRADOS = [
        'rutas.custodia.ver',
        'rutas.custodia.registrar',
        'rutas.recepcion',
        'rutas.custodia.corregir',
    ];

    public function up(): void
    {
        Schema::dropIfExists('custodia_documento_eventos');
        Schema::dropIfExists('salida_ruta_documentos');

        if (Schema::hasColumn('cliente_perfiles_documento', 'modo_papel_fisico')) {
            Schema::table('cliente_perfiles_documento', function (Blueprint $table) {
                $table->dropColumn('modo_papel_fisico');
            });
        }

        // Los pivotes de Spatie tienen cascada: quitar el permiso lo quita de roles y usuarios.
        DB::table('permissions')->whereIn('name', self::PERMISOS_RETIRADOS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::create('ruta_coberturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ruta_id')->constrained('rutas')->cascadeOnDelete();
            // Exactamente uno de los dos. Lo garantiza el controlador; los índices únicos
            // garantizan que un mismo lugar no quede en dos rutas.
            $table->foreignId('departamento_id')->nullable()->unique()->constrained('departamentos');
            $table->foreignId('distrito_id')->nullable()->unique()->constrained('distritos');
            $table->timestamps();

            $table->index('ruta_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ruta_coberturas');

        if (! Schema::hasColumn('cliente_perfiles_documento', 'modo_papel_fisico')) {
            Schema::table('cliente_perfiles_documento', function (Blueprint $table) {
                $table->string('modo_papel_fisico', 20)->default('no_requerir')->after('exige_albaran_en_nc');
            });
        }

        // Las tablas vuelven con la forma que dejaron sus migraciones originales; los
        // permisos, con `db:seed --class=RolesSeeder` sobre el código de entonces.
        foreach ([
            '2026_08_11_110000_create_salida_ruta_documentos_table.php',
            '2026_08_29_100200_add_ambiente_a_salida_ruta_documentos.php',
            '2026_08_30_100200_create_custodia_documento_eventos_table.php',
        ] as $original) {
            (require database_path('migrations/'.$original))->up();
        }
    }
};
