<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Sueldo habitual con vigencia, DUI, altas/bajas y períodos particulares
|--------------------------------------------------------------------------
|
| La simplificación de la quincena se apoya en cuatro cosas, y ninguna de las
| cuatro cabía en el esquema anterior.
|
| ── 1. El sueldo habitual es una LÍNEA DE TIEMPO, no un número ──
|
| `planilla_empleados.salario_referencia` guardaba un solo importe, y cambiarlo
| reescribía el pasado: al reimprimir una quincena vieja habría salido el sueldo
| de hoy. Por eso el habitual pasa a `planilla_sueldos`, una fila por cambio con
| su `vigente_desde`. El habitual de un período es la fila más reciente cuya
| vigencia empieza en o antes del inicio de ese período.
|
| Las planillas ya confirmadas no dependen de esto: `planilla_detalles.salario`
| es una foto tomada al preparar, y sigue siéndolo. La línea de tiempo solo
| decide QUÉ SE PROPONE al abrir una quincena nueva.
|
| `salario_referencia` NO se borra: se conserva y se migra como la primera fila
| de la línea de tiempo. Borrar una columna con datos para estrenar un diseño
| es la clase de cosa que no se puede deshacer.
|
| ── 2. El DUI ──
|
| Va como TEXTO, no como número, y esto no es una preferencia: `0123 4567-8`
| guardado como número se convierte en 12345678 y pierde el cero de la
| izquierda, que es parte del documento. Se conserva tal como se escribe,
| guiones y espacios incluidos.
|
| ── 3. Altas y bajas ──
|
| `activo` ya existía, pero sin fechas no se puede responder «¿estaba trabajando
| en la quincena del 1 al 15?». Con `alta_el` y `baja_el`, dar de baja deja de
| ser un interruptor y pasa a ser un hecho con fecha: quien se fue el 05 no
| aparece en las quincenas siguientes y sigue entero en las anteriores.
|
| ── 4. El período particular de una línea ──
|
| Quien entra o sale a mitad de quincena cobra por sus días. `periodo_desde` y
| `periodo_hasta` guardan ESAS fechas para imprimirlas en el recibo y en la hoja
| de firmas. El sistema NO calcula el importe con ellas: eso se escribe a mano,
| y así queda hasta que se acuerde la regla. Nulo = la quincena completa.
|
| `dui_snapshot` acompaña a `nombre_snapshot`, que ya existía y por la misma
| razón: un documento firmado no puede cambiar porque alguien corrija una ficha
| tres meses después.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planilla_sueldos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('planilla_empleado_id')->constrained('planilla_empleados')->cascadeOnDelete();
            $t->decimal('importe', 14, 2);
            $t->date('vigente_desde');
            $t->text('motivo')->nullable();
            $t->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $t->timestamps();

            // ÚNICO por persona y fecha: registrar dos veces el mismo cambio —doble
            // clic, dos pestañas— corrige el importe en vez de dejar dos verdades
            // para el mismo día, que es una pregunta sin respuesta.
            $t->unique(['planilla_empleado_id', 'vigente_desde']);
            $t->index(['planilla_empleado_id', 'vigente_desde']);
        });

        Schema::table('planilla_empleados', function (Blueprint $t) {
            $t->string('dui', 20)->nullable()->after('codigo');
            $t->date('alta_el')->nullable()->after('activo');
            $t->date('baja_el')->nullable()->after('alta_el');
            $t->index('baja_el');
        });

        Schema::table('planilla_detalles', function (Blueprint $t) {
            $t->string('dui_snapshot', 20)->nullable()->after('nombre_snapshot');
            $t->date('periodo_desde')->nullable()->after('salario');
            $t->date('periodo_hasta')->nullable()->after('periodo_desde');
        });

        $this->trasladarSalarioReferencia();
    }

    /**
     * Traslada el `salario_referencia` que ya existía a la línea de tiempo.
     *
     * Sin esto, quien ya tenía gente cargada abriría su primera quincena con todos los
     * importes en cero y tendría que volver a escribirlos —justo lo que este cambio
     * venía a evitar—.
     *
     * La vigencia arranca en la fecha de alta si la hay, y si no en el día que se
     * registró la ficha: es lo más antiguo que se puede afirmar con lo que hay. No se
     * inventa una fecha anterior.
     *
     * Idempotente: solo escribe donde todavía no hay ninguna fila, así que volver a
     * pasar por acá no duplica ni pisa un importe puesto a mano.
     */
    private function trasladarSalarioReferencia(): void
    {
        $existentes = DB::table('planilla_sueldos')->distinct()->pluck('planilla_empleado_id')->all();

        $pendientes = DB::table('planilla_empleados')
            ->whereNotNull('salario_referencia')
            ->when($existentes !== [], fn ($q) => $q->whereNotIn('id', $existentes))
            ->get(['id', 'salario_referencia', 'alta_el', 'created_at', 'registrado_por']);

        foreach ($pendientes as $fila) {
            DB::table('planilla_sueldos')->insert([
                'planilla_empleado_id' => $fila->id,
                'importe' => $fila->salario_referencia,
                'vigente_desde' => $fila->alta_el ?? Carbon::parse($fila->created_at)->toDateString(),
                'motivo' => 'Importe que ya estaba en la ficha antes de llevar el historial de sueldos.',
                'registrado_por' => $fila->registrado_por,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('planilla_detalles', function (Blueprint $t) {
            $t->dropColumn(['dui_snapshot', 'periodo_desde', 'periodo_hasta']);
        });

        Schema::table('planilla_empleados', function (Blueprint $t) {
            $t->dropIndex(['baja_el']);
            $t->dropColumn(['dui', 'alta_el', 'baja_el']);
        });

        Schema::dropIfExists('planilla_sueldos');
    }
};
