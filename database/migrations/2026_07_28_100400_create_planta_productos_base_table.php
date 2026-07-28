<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué se fabrica: la identidad del dulce, independiente de su empaque.
 *
 * Al producto base pertenece solo lo que NO cambia por formato comercial
 * (código, nombre, descripción). El contenido y las unidades por bulto son de
 * la presentación, y la marca y el mercado son de la configuración de empaque
 * (paso 4), porque el mismo dulce en el mismo formato puede empacarse distinto.
 *
 * SIN `producto_id`, ni siquiera nullable: cero referencias al catálogo
 * comercial de Facturación. Dejar la columna ahí sin uso invitaría a que algún
 * flujo la empezara a leer y acoplara Planta con Facturación por la puerta de
 * atrás, que es justo lo que el aislamiento busca impedir. Añadirla en el
 * futuro sería una migración puramente aditiva, sin riesgo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planta_productos_base', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30);
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('codigo', 'planta_prodbase_codigo_unico');
            $table->index('activo', 'planta_prodbase_activo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planta_productos_base');
    }
};
