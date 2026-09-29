<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3, segunda parte: confirmar, pagar, anticipos y documentos firmados.
 *
 * ═══════════════ Por qué los anticipos son una TABLA y no un texto ═══════════════
 *
 * Un descuento de anticipo dice «te descuento algo que ya te pagué». Escribir la
 * referencia a mano no impide descontar el mismo anticipo dos veces: en la quincena
 * siguiente alguien vuelve a escribir «recibo 148» y el dinero se recupera dos veces.
 *
 * Por eso el anticipo existe como FILA con importe, y cada descuento que lo recupera
 * crea una APLICACIÓN contra esa fila. Lo que queda por recuperar es una resta, no una
 * creencia:
 *
 *      pendiente del anticipo = importe − aplicaciones vigentes
 *
 * Y `planilla_anticipo_aplicaciones.planilla_concepto_id` es único: un mismo descuento
 * no puede recuperar dos veces. Cuando el anticipo corresponde a un pago que YA está en
 * Gastos, se enlaza (`pago_id`), también único: ese pago no puede volver a registrarse
 * como otro anticipo.
 *
 * ═══════════════ Las obligaciones, solo al CONFIRMAR ═══════════════
 *
 * Ni las del empleado ni las de terceros existen mientras la planilla es borrador. Un
 * borrador se corrige veinte veces; si generara deuda, cada corrección tendría que
 * deshacer obligaciones ya creadas, y alguna quedaría viva.
 *
 * Al confirmar se crea:
 *   - una obligación por EMPLEADO, por lo que se le paga (`planilla_detalles.gasto_id`,
 *     que ya llevaba índice único);
 *   - una obligación por TERCERO, agrupando lo que se le entrega en toda la planilla
 *     (`planilla_obligaciones_terceros`, único por planilla y tercero).
 *
 * Los dos índices únicos son lo que hace que confirmar dos veces —un reintento, dos
 * pestañas, dos procesos— no pueda duplicar nada. No es una comprobación previa.
 *
 * ═══════════════ Los pagos son los de Gastos ═══════════════
 *
 * No hay una segunda contabilidad. Pagar una planilla registra pagos NORMALES de
 * Gastos contra las obligaciones que la planilla generó, con sus aplicaciones, sus
 * parciales y su reversión trazable. El «lote» es solo una agrupación operativa: no
 * cambia la regla de un beneficiario por pago y no guarda ni un importe propio, para
 * que no exista forma de que el total del lote y la suma de sus pagos discrepen.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Anticipos: dinero que ya se le entregó a alguien ──────────────────
        Schema::create('planilla_anticipos', function (Blueprint $t) {
            $t->id();
            $t->uuid('clave')->unique();
            $t->foreignId('planilla_empleado_id')->constrained('planilla_empleados')->restrictOnDelete();

            $t->date('fecha');
            $t->decimal('importe', 14, 2);
            $t->char('moneda', 3);

            // El pago REAL, cuando existe en Gastos. Único: un pago no puede quedar
            // registrado como dos anticipos distintos.
            $t->foreignId('pago_id')->nullable()->unique()->constrained('gastos_pagos')->nullOnDelete();
            // Para los anticipos que se entregaron fuera del sistema. Es DESCRIPTIVO:
            // el control de cuánto queda lo da el importe y sus aplicaciones, no esto.
            $t->string('referencia', 180)->nullable();
            $t->string('descripcion', 250)->nullable();

            $t->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $t->timestamps();

            $t->index(['planilla_empleado_id', 'fecha']);
        });

        // ── Cuánto de cada anticipo se recuperó, y en qué descuento ───────────
        Schema::create('planilla_anticipo_aplicaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('planilla_anticipo_id')->constrained('planilla_anticipos')->restrictOnDelete();
            // ÚNICO: un descuento recupera de un anticipo una sola vez.
            $t->foreignId('planilla_concepto_id')->unique()->constrained('planilla_conceptos')->cascadeOnDelete();
            $t->decimal('importe', 14, 2);
            $t->timestamp('created_at');

            $t->index('planilla_anticipo_id');
        });

        Schema::table('planilla_conceptos', function (Blueprint $t) {
            // Un descuento de tipo `anticipo` apunta al anticipo que recupera. La
            // referencia escrita sigue existiendo en el anticipo, pero el control es este.
            $t->foreignId('planilla_anticipo_id')->nullable()->after('referencia')
                ->constrained('planilla_anticipos')->nullOnDelete();
        });

        // ── Obligaciones con terceros, una por tercero y planilla ─────────────
        Schema::create('planilla_obligaciones_terceros', function (Blueprint $t) {
            $t->id();
            $t->foreignId('planilla_id')->constrained('planillas')->restrictOnDelete();
            $t->string('tercero', 180);
            $t->decimal('importe', 14, 2);
            $t->foreignId('gasto_id')->nullable()->unique()->constrained('gastos')->restrictOnDelete();
            $t->timestamp('created_at');

            // Confirmar dos veces no puede crear dos deudas con la cooperativa.
            $t->unique(['planilla_id', 'tercero']);
        });

        // ── Lotes de pago: agrupación operativa, sin importes propios ─────────
        Schema::create('planilla_lotes', function (Blueprint $t) {
            $t->id();
            $t->uuid('clave')->unique();
            $t->foreignId('planilla_id')->constrained('planillas')->restrictOnDelete();
            $t->date('fecha');
            $t->string('metodo', 30);
            $t->string('referencia', 180)->nullable();
            $t->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });

        Schema::create('planilla_lote_pagos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('planilla_lote_id')->constrained('planilla_lotes')->cascadeOnDelete();
            // Único: un pago pertenece a un solo lote.
            $t->foreignId('pago_id')->unique()->constrained('gastos_pagos')->restrictOnDelete();
        });

        // ── Documentos firmados ───────────────────────────────────────────────
        Schema::create('planilla_documentos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('planilla_id')->constrained('planillas')->cascadeOnDelete();
            // NULL = es la hoja general; con detalle = es el recibo de esa persona.
            $t->foreignId('planilla_detalle_id')->nullable()->constrained('planilla_detalles')->cascadeOnDelete();
            $t->string('tipo', 20); // hoja_firmada | recibo_firmado

            // Mismo criterio que los adjuntos de Gastos: disco privado, nombre generado
            // por el servidor, MIME real y sha256. Se sirven SOLO por controlador
            // autorizado, porque un recibo de sueldo es un documento confidencial.
            $t->string('ruta');
            $t->string('nombre');
            $t->string('mime', 100);
            $t->unsignedBigInteger('bytes');
            $t->char('sha256', 64)->index();

            $t->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');

            $t->index(['planilla_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::table('planilla_conceptos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('planilla_anticipo_id');
        });

        foreach ([
            'planilla_documentos', 'planilla_lote_pagos', 'planilla_lotes',
            'planilla_obligaciones_terceros', 'planilla_anticipo_aplicaciones', 'planilla_anticipos',
        ] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};
