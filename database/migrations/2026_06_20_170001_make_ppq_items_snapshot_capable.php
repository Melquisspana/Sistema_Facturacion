<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El CCF/NC de un PPQ puede venir de Gmail (ContaPortable) y NO existir en la
 * tabla `dtes` local. Por eso el item pasa a SNAPSHOTEAR los datos del documento
 * (control, código, sello, fecha, tipo) y `dte_id` se vuelve opcional (solo cuando
 * el documento también está en el sistema). Origen = local | gmail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppq_items', function (Blueprint $table) {
            $table->foreignId('dte_id')->nullable()->change();
            $table->string('origen', 20)->default('local')->after('dte_id');
            $table->string('numero_control', 40)->nullable()->after('origen');
            $table->string('codigo_generacion', 40)->nullable()->after('numero_control');
            $table->string('sello_recepcion')->nullable()->after('codigo_generacion');
            $table->string('tipo_dte', 2)->nullable()->after('sello_recepcion');
            $table->date('fecha_documento')->nullable()->after('tipo_dte');
            $table->string('gmail_message_id')->nullable()->after('fecha_documento');
            // Anti-duplicado para documentos de Gmail (sin dte_id): por nº de control.
            $table->unique(['ppq_lote_id', 'numero_control'], 'ppq_item_lote_control_unico');
        });
    }

    public function down(): void
    {
        Schema::table('ppq_items', function (Blueprint $table) {
            $table->dropUnique('ppq_item_lote_control_unico');
            $table->dropColumn(['origen', 'numero_control', 'codigo_generacion', 'sello_recepcion', 'tipo_dte', 'fecha_documento', 'gmail_message_id']);
            // dte_id se deja nullable (revertirlo requeriría limpiar filas sin dte).
        });
    }
};
