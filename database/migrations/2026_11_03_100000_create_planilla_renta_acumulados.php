<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Lo gravado y la renta retenida ANTES del sistema, por persona y año
|--------------------------------------------------------------------------
|
| El recálculo de junio y diciembre (Decreto 10, Art. 1 lit. f) necesita todo lo
| gravado del año. El sistema arrancó a mitad de año: lo anterior no está en ninguna
| planilla. Esta tabla guarda esa cifra, confirmada por una persona.
|
| `hasta` es el último día que cubre la cifra. Las planillas del sistema se suman
| desde el día siguiente, así nada se cuenta dos veces. Sirve también para la
| constancia de un patrono anterior, que el decreto manda considerar.
|
| Que exista la fila es que alguien la confirmó: la propuesta se calcula en pantalla
| y no se guarda hasta entonces. Solo agrega una tabla; no toca ninguna otra.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planilla_renta_acumulados', function (Blueprint $t) {
            $t->id();
            $t->foreignId('planilla_empleado_id')->constrained('planilla_empleados')->restrictOnDelete();
            $t->unsignedSmallInteger('anio');
            $t->date('hasta');
            $t->decimal('gravado', 14, 2);
            $t->decimal('renta_retenida', 14, 2);
            $t->text('observaciones')->nullable();
            $t->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $t->timestamps();

            $t->unique(['planilla_empleado_id', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planilla_renta_acumulados');
    }
};
