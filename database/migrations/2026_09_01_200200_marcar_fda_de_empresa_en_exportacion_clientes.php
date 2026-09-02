<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El FDA de la EMPRESA exportadora dejó de vivir en el perfil de cada cliente:
 * ahora se resuelve en Configuración → Parámetros fiscales, con respaldo al valor
 * histórico de `config('exportaciones.fda_reg_number')`.
 *
 * `exportacion_clientes.fda_reg_number` conserva su propósito legítimo —el número
 * de registro FDA del IMPORTADOR, que es un dato real y distinto—, pero hoy varios
 * perfiles guardan ahí el de la empresa por arrastre del formulario anterior.
 *
 * NO SE BORRA NI UN VALOR. Un `UPDATE ... SET NULL` sería exactamente la
 * reinterpretación silenciosa que hay que evitar: nadie puede afirmar desde una
 * migración que ese número no es también, por casualidad, el del importador. Lo
 * que se hace es MARCAR las filas donde el valor coincide exactamente con el de la
 * empresa, para que la ficha del cliente lo muestre con un aviso y sea una persona
 * quien decida limpiarlo.
 *
 * Idempotente y reversible: `down()` solo quita la columna añadida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exportacion_clientes', function (Blueprint $table) {
            $table->boolean('fda_requiere_revision')->default(false)->after('fda_reg_number')
                ->comment('El FDA guardado coincide con el de la empresa exportadora: probablemente no es el del importador.');
        });

        $fdaEmpresa = trim((string) config('exportaciones.fda_reg_number', ''));

        if ($fdaEmpresa === '') {
            return;
        }

        DB::table('exportacion_clientes')
            ->whereNotNull('fda_reg_number')
            ->where('fda_reg_number', $fdaEmpresa)
            ->update(['fda_requiere_revision' => true]);
    }

    public function down(): void
    {
        Schema::table('exportacion_clientes', function (Blueprint $table) {
            $table->dropColumn('fda_requiere_revision');
        });
    }
};
