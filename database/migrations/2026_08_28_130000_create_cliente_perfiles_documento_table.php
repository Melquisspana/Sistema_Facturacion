<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERFIL DE DOCUMENTOS de un cliente: las exigencias documentales propias de una
 * cadena, declaradas como datos en vez de quemadas en el código.
 *
 * Sin fila = sin perfil = comportamiento histórico intacto. Esa es la garantía que
 * permite activarlo para un cliente sin tocar a ningún otro: todo el código nuevo
 * pregunta primero por el perfil y, si no hay, se comporta exactamente como antes.
 *
 * `formato_export` es la clave de la fábrica de exportadores, y nombra al FORMATO,
 * no al cliente: el día que otra cadena pida el mismo Excel, se le pone el mismo
 * slug y no hace falta código nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_perfiles_documento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->unique()->constrained('clientes')->cascadeOnDelete();
            $table->boolean('activo')->default(true);

            // Código que la cadena asigna al emisor. Es la columna A del Excel y la raíz
            // del nombre del archivo. Reemplaza al global config('ppq.codigo_proveedor'),
            // que solo puede servir a un cliente a la vez.
            $table->string('codigo_proveedor', 20)->nullable();

            // Slug del formato de exportación (clave de ExportadorNcFactory).
            $table->string('formato_export', 40)->nullable();

            // Si está activo, la NC no puede generarse sin los datos del albarán.
            $table->boolean('exige_albaran_en_nc')->default(false);

            // Diferencia (en valor absoluto) tolerada entre el total de la NC y el del
            // albarán antes de avisar. 0.00 = avisar ante cualquier diferencia. NUNCA
            // ajusta valores fiscales: solo decide si se muestra el aviso.
            $table->decimal('tolerancia_albaran', 8, 2)->default(0);

            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_perfiles_documento');
    }
};
