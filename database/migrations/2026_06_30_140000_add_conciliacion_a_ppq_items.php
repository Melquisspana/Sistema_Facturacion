<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de conciliación del CCF/NC contra el TXT de pagos de Calleja. Un documento NO se
 * considera pagado por estar en el PPQ: solo cuando aparece en el TXT (CF=pagado, NC=aplicada).
 * Guarda la fecha y el monto que reporta el TXT para comparar contra el del sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppq_items', function (Blueprint $table) {
            // null = pendiente; 'pagado' (CF en TXT) | 'aplicada' (NC en TXT).
            $table->string('conciliacion_estado', 20)->nullable()->after('sin_albaran');
            $table->date('fecha_pago')->nullable()->after('conciliacion_estado');
            $table->decimal('monto_pagado', 10, 2)->nullable()->after('fecha_pago');
            $table->timestamp('conciliado_en')->nullable()->after('monto_pagado');
        });
    }

    public function down(): void
    {
        Schema::table('ppq_items', function (Blueprint $table) {
            $table->dropColumn(['conciliacion_estado', 'fecha_pago', 'monto_pagado', 'conciliado_en']);
        });
    }
};
