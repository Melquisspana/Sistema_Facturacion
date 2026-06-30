<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tercer nivel territorial (reforma 2024 de El Salvador): DISTRITO.
 *
 * Diseño NO destructivo: no se renombra la tabla `municipios` existente (la usan
 * clientes/empresas y ~48 archivos). El distrito es una tabla propia que lleva su
 * departamento y el nombre del MUNICIPIO 2024 (las 44 agrupaciones) como dato, de
 * modo que la cascada Departamento → Municipio → Distrito se arma desde una sola
 * tabla. La sala/establecimiento referencia `distrito_id`.
 *
 * `codigo` (CAT del MH) queda nullable: se completa al importar el catálogo oficial,
 * igual que ya ocurre con municipios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distritos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('departamento_id')->constrained('departamentos')->cascadeOnDelete();
            $table->string('municipio')->comment('Municipio 2024 (agrupación de 44) al que pertenece el distrito');
            $table->string('codigo')->nullable()->comment('Código MH del distrito (pendiente catálogo oficial)');
            $table->string('nombre');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['departamento_id', 'municipio']);
            $table->unique(['departamento_id', 'municipio', 'nombre']);
        });

        Schema::table('cliente_sucursales', function (Blueprint $table) {
            $table->foreignId('distrito_id')->nullable()->after('municipio_id')
                ->constrained('distritos')->nullOnDelete();
        });

        Schema::table('establecimientos', function (Blueprint $table) {
            $table->foreignId('distrito_id')->nullable()->after('municipio_id')
                ->constrained('distritos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cliente_sucursales', function (Blueprint $table) {
            $table->dropForeign(['distrito_id']);
            $table->dropColumn('distrito_id');
        });
        Schema::table('establecimientos', function (Blueprint $table) {
            $table->dropForeign(['distrito_id']);
            $table->dropColumn('distrito_id');
        });
        Schema::dropIfExists('distritos');
    }
};
