<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planilla_empleados', function (Blueprint $table) {
            $table->string('documento_tipo', 20)->default('dui');
            $table->boolean('con_descuentos_ley')->default(false);
            $table->decimal('sueldo_mensual', 14, 2)->nullable();
            $table->char('sexo', 1)->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->string('estado_civil', 30)->nullable();
            $table->string('profesion', 120)->nullable();
            $table->string('domicilio', 180)->nullable();
            $table->string('residencia', 255)->nullable();
            $table->string('nacionalidad', 60)->nullable();
            $table->string('dui_expedido_en', 120)->nullable();
            $table->date('dui_expedido_el')->nullable();
            $table->text('funciones')->nullable();
            $table->string('lugar_trabajo', 255)->nullable();
            $table->text('herramientas')->nullable();
            $table->json('dependientes')->nullable();
            $table->unique(['documento_tipo', 'dui']);
        });
        Schema::table('planilla_detalles', function (Blueprint $table) {
            $table->string('documento_tipo_snapshot', 20)->nullable()->after('dui_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('planilla_detalles', fn (Blueprint $table) => $table->dropColumn('documento_tipo_snapshot'));
        Schema::table('planilla_empleados', function (Blueprint $table) {
            $table->dropUnique(['documento_tipo', 'dui']);
            $table->dropColumn(['documento_tipo', 'con_descuentos_ley', 'sueldo_mensual', 'sexo', 'fecha_nacimiento', 'estado_civil', 'profesion', 'domicilio', 'residencia', 'nacionalidad', 'dui_expedido_en', 'dui_expedido_el', 'funciones', 'lugar_trabajo', 'herramientas', 'dependientes']);
        });
    }
};
