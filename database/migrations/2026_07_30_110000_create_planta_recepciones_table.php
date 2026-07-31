<?php

use App\Models\Secuencia;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cabecera de una RECEPCIÓN de insumos: la entrada física de mercancía a una
 * ubicación de Planta.
 *
 * Es el primer documento del módulo que MUEVE INVENTARIO, y por eso su ciclo de
 * vida es de documento y no de catálogo:
 *
 *     borrador --confirmar--> confirmada --reversar--> reversada
 *        |
 *        +----anular--------> anulada
 *
 * SIN softDeletes, deliberadamente. Un borrador que no sirve se ANULA, y una
 * recepción confirmada ya escribió en el libro mayor: borrarla —aunque sea de
 * forma lógica— dejaría movimientos apuntando a un documento invisible y
 * rompería la única cadena que explica de dónde salió el saldo.
 *
 * NUMERACIÓN. `numero` es un contador propio del módulo, servido por
 * {@see Secuencia} con la clave `planta_recepcion`. NO es fiscal:
 * no es `numero_sistema`, no es un correlativo del Ministerio de Hacienda y no
 * tiene obligación de continuidad. Es NOT NULL porque se asigna al CREAR el
 * borrador —la operación se refiere a «la recepción 47» desde el primer
 * momento—, lo que implica que anular un borrador deja un hueco en la serie.
 * Ese hueco es aceptable justamente porque la numeración no es fiscal.
 *
 * REVERSIÓN. Se modela con dos punteros al MISMO documento en vez de con una
 * tabla aparte: `reversion_de_id` en el documento que compensa, y
 * `revertido_por_id` en el original. Así una reversión es una recepción más
 * —con sus líneas, su fecha y su responsable— y se lista, se filtra y se
 * audita con el mismo código, en vez de necesitar una segunda entidad casi
 * idéntica.
 *
 * `responsable_user_id` y `responsable_nombre` conviven a propósito: el segundo
 * es una INSTANTÁNEA que sobrevive al borrado del usuario y que además permite
 * anotar a quien recibió la mercancía aunque no tenga cuenta en el sistema.
 *
 * Las FK a `users` son `nullable + nullOnDelete`, que es la convención de todo
 * el repositorio. `NOT NULL` sería incompatible con `nullOnDelete`: el motor no
 * puede escribir NULL en una columna que no lo admite, y al borrar un usuario
 * fallaría la sentencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planta_recepciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('numero')
                ->comment('Contador propio (Secuencia: planta_recepcion). NO es fiscal ni numero_sistema');
            $table->string('estado', 20)
                ->comment('App\Enums\Planta\EstadoRecepcionPlanta: borrador|confirmada|anulada|reversada');
            $table->date('fecha')
                ->comment('Fecha OPERATIVA de la entrada física, no la de captura');

            $table->foreignId('planta_proveedor_id')->nullable()
                ->constrained('planta_proveedores')->nullOnDelete();
            $table->foreignId('planta_ubicacion_id')
                ->constrained('planta_ubicaciones')->restrictOnDelete()
                ->comment('Dónde entra la mercancía. Nunca TRANSITO');

            $table->string('documento_referencia', 60)->nullable()
                ->comment('Factura, remisión o guía del proveedor; texto libre');

            $table->foreignId('creado_por')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('confirmado_por')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('confirmado_en')->nullable();

            $table->foreignId('responsable_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('responsable_nombre', 120)->nullable()
                ->comment('Instantánea: sobrevive al borrado del usuario y admite a quien no tiene cuenta');

            $table->text('observaciones')->nullable();

            $table->foreignId('reversion_de_id')->nullable()
                ->constrained('planta_recepciones')->restrictOnDelete()
                ->comment('En el documento que COMPENSA: apunta al original');
            $table->foreignId('revertido_por_id')->nullable()
                ->constrained('planta_recepciones')->restrictOnDelete()
                ->comment('En el ORIGINAL: apunta al documento que lo compensó');

            $table->timestamps();

            $table->unique('numero', 'planta_recep_numero_unico');
            $table->index('estado', 'planta_recep_estado_idx');
            $table->index('fecha', 'planta_recep_fecha_idx');
            $table->index('planta_proveedor_id', 'planta_recep_proveedor_idx');
            $table->index('planta_ubicacion_id', 'planta_recep_ubicacion_idx');
            $table->index('creado_por', 'planta_recep_creado_por_idx');
            $table->index('reversion_de_id', 'planta_recep_reversion_de_idx');
            $table->index('revertido_por_id', 'planta_recep_revertido_por_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planta_recepciones');
    }
};
