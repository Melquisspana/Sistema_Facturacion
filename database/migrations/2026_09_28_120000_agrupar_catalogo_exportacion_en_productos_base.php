<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de exportación en dos niveles: PRODUCTO BASE → PRESENTACIONES.
 *
 * Hasta acá cada presentación era un producto suelto, y el mismo dulce vivía en
 * varias filas con nombres casi iguales («Caja mani dulce», «CAJA MANI DULCE»…).
 * Ahora `exportacion_productos_base` guarda la identidad del dulce (nombre bilingüe
 * y categoría) y cada fila de `exportacion_productos` pasa a ser una PRESENTACIÓN
 * suya: unidades por caja, gramos, empaque y pesos. Los precios por cliente y los
 * items de las listas siguen apuntando a la presentación, así que nada de lo que
 * ya existe cambia de significado.
 *
 * PRECIO VIGENTE. El precio de cada cliente pasa a salir de su última lista de
 * empaque finalizada. `precio_fijado_en` guarda la fecha de la lista que lo fijó
 * (una lista más vieja que se finalice tarde no pisa un precio más nuevo) y
 * `precio_desde_exportacion_id` cuál fue.
 *
 * Aditiva y nullable: no toca datos. La agrupación de lo existente la hace el
 * comando `exportacion:unificar-catalogo`, que por defecto solo simula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exportacion_productos_base', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_es');
            $table->string('nombre_en');
            $table->string('categoria', 30)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            // La razón de ser de la tabla: un dulce, una fila.
            $table->unique('nombre_es', 'epb_nombre_es_unique');
            $table->index('categoria');
        });

        Schema::table('exportacion_productos', function (Blueprint $table) {
            // restrictOnDelete: una base con presentaciones no se borra; se archiva.
            $table->foreignId('exportacion_producto_base_id')->nullable()->after('id')
                ->constrained('exportacion_productos_base')->restrictOnDelete();
        });

        Schema::table('exportacion_cliente_productos', function (Blueprint $table) {
            $table->date('precio_fijado_en')->nullable()->after('precio_caja');
            $table->foreignId('precio_desde_exportacion_id')->nullable()->after('precio_fijado_en')
                // Nombre explícito: el automático pasa de los 64 caracteres de MySQL.
                ->constrained('exportaciones', 'id', 'ecp_precio_desde_exportacion_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exportacion_cliente_productos', function (Blueprint $table) {
            $table->dropForeign('ecp_precio_desde_exportacion_fk');
            $table->dropColumn(['precio_desde_exportacion_id', 'precio_fijado_en']);
        });

        Schema::table('exportacion_productos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exportacion_producto_base_id');
        });

        Schema::dropIfExists('exportacion_productos_base');
    }
};
