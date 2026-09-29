<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3: PLANILLA DE CONTROL Y RECIBOS.
 *
 * Esto NO es nómina legal. No calcula ISSS, AFP, renta, vacaciones, aguinaldo,
 * indemnización ni horas extra: todos los importes los escribe y revisa una persona.
 * Es el control de cuánto se le paga a cada quien, con su recibo. Si alguna vez se
 * agrega el cálculo legal, será otra decisión y otra migración.
 *
 * ═══════════ Por qué `planilla_empleados` y no `asistencia_empleados` ═══════════
 *
 * El proyecto ya tomó esta decisión para Rutas y las tres razones valen igual acá
 * (ver 2026_08_30_100000_create_rutas_personal_table):
 *
 *  1. `activo` NO significa lo mismo. En Asistencia es «no puede marcar»; acá es
 *     «ya no está en planilla». Alguien puede dejar de marcar —pasa a comisión, anda
 *     de viaje— y seguir cobrando. Compartir la columna haría que desactivar un
 *     lector borrara a una persona de la planilla.
 *  2. El módulo de Asistencia se APAGA (`ASISTENCIA_ENABLED=false` por defecto). La
 *     planilla tiene que funcionar en un servidor sin lector de huella, y no puede
 *     exigir biometría para pagarle a nadie.
 *  3. Los SALARIOS son confidenciales. Colgarlos de la tabla de asistencia ampliaría
 *     en silencio quién alcanza qué.
 *
 * ────────────────── Y sin embargo, la identidad NO se duplica ──────────────────
 *
 * Tres punteros OPCIONALES y únicos: `asistencia_empleado_id`, `personal_ruta_id` y
 * `user_id`. Son REFERENCIAS DE IDENTIDAD, no dependencias: la planilla nunca lee
 * marcaciones ni huellas, nunca exige el módulo encendido, y funciona con los tres en
 * NULL. Lo que compran es que nadie tenga que adivinar si el Rene de acá es el de
 * allá. El alta ofrece las personas que YA existen para engancharlas de un clic en
 * vez de volver a escribirlas.
 *
 * ═══════════════ La regla que impide contar el dinero dos veces ═══════════════
 *
 * Una línea de planilla produce TRES cifras distintas, y no se suman entre sí:
 *
 *    TOTAL DE INGRESOS = salario del período + otros ingresos
 *                        → es el GASTO SALARIAL de la empresa (lo que cuesta)
 *    DESCUENTOS        = lo que se le resta a la persona
 *    A PAGAR           = total de ingresos − descuentos
 *                        → es lo que se le entrega a la persona
 *
 * Y los descuentos tienen DESTINO, que es la parte que se olvida. Son TRES casos, no
 * dos, porque «ya entregado» daba por sentado algo que no siempre es cierto:
 *
 *   tercero  → se le entrega a alguien más. Sigue siendo dinero que la empresa debe,
 *              solo que a OTRO, y genera su propia obligación.
 *   anticipo → ya se le pagó a la persona antes. No genera obligación, y guarda la
 *              REFERENCIA de ese pago para poder comprobarlo después.
 *   otro     → cualquier otro descuento acordado. Ni es dinero ya entregado ni se le
 *              debe a nadie de fuera.
 *
 *    total de ingresos = a pagar + descuentos a terceros + anticipos + otros
 *
 * Por eso `destino` es una columna y no una nota: sin ella, o se pierde la deuda con
 * el tercero, o se suma el total de ingresos Y lo que se paga, y el gasto sale
 * duplicado.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Registro de personas en planilla ──────────────────────────────────
        Schema::create('planilla_empleados', function (Blueprint $t) {
            $t->id();
            $t->uuid('clave')->unique();
            // Nombre PROPIO y obligatorio: la planilla tiene que poder explicarse sola
            // aunque la persona se borre de otro módulo.
            $t->string('nombre', 180);
            $t->string('codigo', 40)->nullable();
            $t->string('cargo', 120)->nullable();

            // Punteros de identidad. Únicos para que la misma persona no entre dos veces
            // por dos caminos, y nullable porque alguien de planilla puede no estar en
            // ningún otro módulo.
            $t->foreignId('asistencia_empleado_id')->nullable()->unique()->constrained('asistencia_empleados')->nullOnDelete();
            $t->foreignId('personal_ruta_id')->nullable()->unique()->constrained('rutas_personal')->nullOnDelete();
            $t->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();

            // Propuesta para prellenar el período. NO es un cálculo: se puede cambiar en
            // cada planilla y lo que manda es el importe escrito allí.
            $t->decimal('salario_referencia', 14, 2)->nullable();
            $t->boolean('activo')->default(true);
            $t->text('notas')->nullable();
            $t->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $t->timestamps();

            $t->index('activo');
        });

        // ── La planilla de un período ─────────────────────────────────────────
        Schema::create('planillas', function (Blueprint $t) {
            $t->id();
            $t->uuid('clave')->unique();

            $t->string('tipo_periodo', 12);  // semanal | quincenal | mensual
            // Clave lógica del período: «2026-W37», «2026-09-Q1», «2026-09». La misma
            // idea que en las repeticiones de Gastos, y por el mismo motivo: es lo que
            // hace que «la quincena de septiembre» signifique algo y no se repita.
            $t->string('periodo', 20);
            $t->string('clase', 15)->default('regular'); // regular | extraordinaria

            $t->date('desde');
            $t->date('hasta');
            $t->date('fecha_pago')->nullable();
            $t->char('moneda', 3);

            $t->string('estado', 12)->default('borrador'); // borrador | confirmada | anulada
            $t->text('observaciones')->nullable();

            $t->timestamp('confirmada_at')->nullable();
            $t->foreignId('confirmada_por')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('anulada_at')->nullable();
            $t->foreignId('anulada_por')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('motivo_anulacion', 500)->nullable();

            $t->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $t->timestamps();

            // UNA planilla regular por tipo y período. La extraordinaria existe para el
            // pago fuera de calendario y por eso entra en la clave: si no, habría que
            // elegir entre prohibirla o permitir duplicados de la regular.
            $t->unique(['tipo_periodo', 'periodo', 'clase']);
            $t->index('estado');
        });

        // ── Una línea por empleado ────────────────────────────────────────────
        Schema::create('planilla_detalles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('planilla_id')->constrained('planillas')->cascadeOnDelete();
            $t->foreignId('planilla_empleado_id')->constrained('planilla_empleados')->restrictOnDelete();

            // Fotografía del nombre y el cargo al preparar la planilla. Un recibo de
            // marzo no puede cambiar porque en junio a alguien lo asciendan.
            $t->string('nombre_snapshot', 180);
            $t->string('cargo_snapshot', 120)->nullable();

            // Importe MANUAL del período. No sale de ninguna fórmula.
            $t->decimal('salario', 14, 2);

            // Se calculan a partir de los conceptos y se guardan al confirmar, para que
            // el recibo impreso y la fila coincidan para siempre.
            //
            // Se llaman como se llaman EN PANTALLA y no «bruto» y «neto»: quien lee una
            // consulta después tiene que ver la misma palabra que vio quien preparó la
            // planilla. `total_ingresos` es salario + otros ingresos; `a_pagar` es lo que
            // se le entrega a la persona.
            $t->decimal('total_ingresos', 14, 2)->default(0);
            $t->decimal('descuentos', 14, 2)->default(0);
            $t->decimal('a_pagar', 14, 2)->default(0);

            // La obligación con el EMPLEADO por su neto. Se llena al confirmar, una sola
            // vez; que sea una columna con índice único es lo que impide generarla dos
            // veces si alguien confirma dos veces.
            $t->foreignId('gasto_id')->nullable()->unique()->constrained('gastos')->restrictOnDelete();

            $t->text('observaciones')->nullable();
            $t->timestamps();

            $t->unique(['planilla_id', 'planilla_empleado_id']);
        });

        // ── Ingresos y descuentos de cada línea ───────────────────────────────
        Schema::create('planilla_conceptos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('planilla_detalle_id')->constrained('planilla_detalles')->cascadeOnDelete();

            $t->string('tipo', 12);      // ingreso | descuento
            $t->string('concepto', 150); // escrito a mano: «Bono de venta», «Anticipo»
            $t->decimal('importe', 14, 2);

            // SOLO para descuentos, y con TRES valores, no dos. «Ya entregado» era
            // ambiguo: daba por sentado que todo descuento es dinero que la persona ya
            // recibió, y no es así.
            //
            //   anticipo → dinero que YA se le pagó antes. Reduce lo que se le debe y no
            //              genera ninguna obligación. Lleva `referencia` para saber
            //              CUÁL anticipo era: sin eso, dentro de tres meses nadie puede
            //              comprobar que ese descuento correspondía.
            //   tercero  → se le entrega a alguien más (una cuota, una retención). Genera
            //              su propia obligación con ese tercero, en `tercero`.
            //   otro     → cualquier otro descuento acordado. Ni es dinero ya entregado
            //              ni se le debe a nadie de fuera.
            //
            // Queda NULL mientras no se elija. No hay valor por defecto a propósito:
            // adivinar cuál de los tres es sería exactamente el error que se corrige.
            $t->string('destino', 12)->nullable();     // anticipo | tercero | otro
            $t->string('tercero', 180)->nullable();    // a quién se le entrega
            $t->string('referencia', 180)->nullable(); // qué anticipo era

            $t->unsignedInteger('orden')->default(0);
            $t->timestamps();

            $t->index(['planilla_detalle_id', 'tipo']);
        });
    }

    public function down(): void
    {
        foreach (['planilla_conceptos', 'planilla_detalles', 'planillas', 'planilla_empleados'] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};
