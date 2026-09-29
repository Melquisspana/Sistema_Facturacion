<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2: recurrencias y avisos.
 *
 * RECURRENCIAS. Una regla describe una obligación que vuelve —alquiler, agua,
 * seguro— y genera UNA obligación por período. El riesgo central es el mismo de
 * todo el módulo, la deuda inventada, pero acá en su forma más peligrosa: la
 * automática. Tres candados de base lo cierran:
 *
 *  1. `gastos_ocurrencias.unique(regla_id, periodo)`. Un período lógico
 *     («2026-03», «2026-W12», «2026-03-Q2») se genera UNA vez y nada más. No es
 *     una comprobación previa que dos workers puedan pasar a la vez: es el índice.
 *     La clave lógica NO incluye la versión de la regla, a propósito: cambiar la
 *     plantilla no puede reabrir un período ya generado.
 *  2. `gastos_regla_versiones`. La regla se versiona con fecha de efecto y cada
 *     ocurrencia guarda con QUÉ versión nació. Subir el alquiler en marzo no
 *     reescribe lo que se debía en enero.
 *  3. Estado explícito de la regla —activa, pausada, cancelada— con autor y
 *     motivo. Pausar frena la generación FUTURA y no toca ni una obligación ya
 *     creada.
 *
 * Una ocurrencia OMITIDA ocupa su período igual que una generada: es la manera de
 * decirle al proceso «este mes no, y por esto», sin que la siguiente corrida lo
 * vuelva a intentar.
 *
 * AVISOS. Bandeja interna por usuario y resúmenes por correo. Se derivan del saldo
 * real en el momento de armarlos; no son un estado que alguien mantenga a mano.
 * `gastos_avisos.clave` impide que la misma obligación, para el mismo usuario y la
 * misma ventana, genere dos entradas. `gastos_resumenes` es el registro de qué se
 * preparó y qué pasó con el envío —incluido «simulado»—, con clave única por
 * usuario, canal y ventana.
 *
 * Lo que este esquema NO tiene, y es deliberado: ninguna columna que marque una
 * obligación como pagada al crearla, y ninguna que importe pendientes de otro
 * sistema. Una obligación recurrente nace DEBIENDO; el pago se registra después
 * por el camino normal, con su comprobante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gastos_reglas', function (Blueprint $t) {
            $t->id();
            $t->uuid('clave')->unique();
            $t->string('nombre', 200);

            // Plantilla de la obligación. Mismos campos que `gastos`, porque lo que la
            // regla produce ES un gasto: si divergieran, la obligación generada sería
            // distinta de una registrada a mano y los informes dejarían de cuadrar.
            $t->string('beneficiario', 180);
            $t->string('concepto', 200);
            $t->string('categoria', 100);
            $t->string('ambito', 20)->index();
            $t->string('persona', 180)->nullable();
            $t->string('naturaleza', 30);
            $t->char('moneda', 3);
            $t->string('documentacion', 30);
            $t->text('observaciones')->nullable();
            $t->foreignId('responsable_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('registrado_por')->constrained('users')->restrictOnDelete();

            // MONTO. `variable` deja `importe` NULL y produce obligaciones «esperando
            // monto», que no suman deuda ni vencido hasta que alguien las complete.
            // Inventar una cifra «estimada» sería exactamente la deuda falsa que el
            // módulo existe para evitar.
            $t->string('monto_modo', 10); // fijo | variable
            $t->decimal('importe', 14, 2)->nullable();

            $t->string('frecuencia', 12); // semanal | quincenal | mensual | anual
            $t->unsignedTinyInteger('dia_semana')->nullable();  // 1..7 ISO (semanal)
            $t->unsignedTinyInteger('dia_mes')->nullable();     // 1..31 (quincenal/mensual/anual)
            $t->unsignedTinyInteger('dia_mes_2')->nullable();   // 1..31 (segunda quincena)
            $t->unsignedTinyInteger('mes')->nullable();         // 1..12 (anual)

            // Cuánto antes del vencimiento se crea la obligación. Cero significa «el
            // mismo día». No adelanta el vencimiento: solo la creación.
            $t->unsignedSmallInteger('dias_generar_antes')->default(0);

            $t->date('vigente_desde');
            $t->date('vigente_hasta')->nullable();

            $t->string('estado', 12)->default('activa'); // activa | pausada | cancelada
            $t->timestamp('pausada_at')->nullable();
            $t->foreignId('pausada_por')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('motivo_pausa', 500)->nullable();
            $t->timestamp('cancelada_at')->nullable();
            $t->foreignId('cancelada_por')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('motivo_cancelacion', 500)->nullable();

            $t->unsignedInteger('version')->default(1);
            // Zona horaria de NEGOCIO. Los timestamps van en UTC; qué día es «hoy» para
            // decidir un vencimiento se resuelve con esta.
            $t->string('zona_horaria', 64);
            $t->date('generado_hasta')->nullable();
            $t->timestamps();

            $t->index(['estado', 'frecuencia']);
        });

        // Configuración versionada. Cada cambio deja la versión anterior intacta y con
        // su fecha de efecto, para que una ocurrencia vieja se pueda explicar con la
        // plantilla que realmente la produjo.
        Schema::create('gastos_regla_versiones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('regla_id')->constrained('gastos_reglas')->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->json('datos');
            $t->date('vigente_desde');
            $t->string('motivo', 500)->nullable();
            $t->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');

            $t->unique(['regla_id', 'version']);
        });

        Schema::create('gastos_ocurrencias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('regla_id')->constrained('gastos_reglas')->restrictOnDelete();

            // CLAVE LÓGICA del período: «2026-03», «2026-W12», «2026-03-Q2», «2026».
            // Es lo que hace idempotente la generación. Sin versión adentro: el mismo
            // mes no se puede generar dos veces por haber editado la regla.
            $t->string('periodo', 20);

            $t->date('vence')->nullable();
            $t->string('estado', 12); // generada | omitida
            $t->foreignId('gasto_id')->nullable()->constrained('gastos')->restrictOnDelete();
            $t->unsignedInteger('version_regla');
            $t->string('motivo', 500)->nullable();
            // NULL = lo hizo el proceso programado, no una persona.
            $t->foreignId('registrado_por')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');

            $t->unique(['regla_id', 'periodo']);
            $t->index(['regla_id', 'estado']);
        });

        Schema::create('gastos_preferencias_avisos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('usuario_id')->unique()->constrained('users')->cascadeOnDelete();
            $t->boolean('activo')->default(true);
            // Días de anticipación con los que avisar. Propuesta editable, no regla.
            $t->json('dias_anticipacion');
            $t->boolean('correo')->default(false);
            $t->string('resumen', 10)->default('semanal'); // diario | semanal | nunca
            $t->unsignedTinyInteger('resumen_dia_semana')->default(1); // 1..7 ISO
            // Qué ámbitos entran en SUS avisos. Recortado además por permiso al armarlos:
            // marcar «personal» sin `gastos.personales` no agrega nada.
            $t->json('ambitos');
            $t->timestamps();
        });

        Schema::create('gastos_avisos', function (Blueprint $t) {
            $t->id();
            // usuario + tipo + cuota + ventana. Es lo que impide repetir el mismo aviso
            // en cada corrida del día.
            $t->string('clave', 190)->unique();
            $t->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $t->string('tipo', 20); // vence | vencido | falta_monto
            $t->foreignId('gasto_id')->constrained('gastos')->cascadeOnDelete();
            $t->foreignId('cuota_id')->nullable()->constrained('gastos_cuotas')->cascadeOnDelete();
            $t->string('titulo', 250);
            $t->string('detalle', 500);
            $t->date('vence')->nullable();
            $t->decimal('importe', 14, 2)->nullable();
            $t->char('moneda', 3)->nullable();
            $t->timestamp('leido_at')->nullable();
            $t->timestamp('created_at');

            $t->index(['usuario_id', 'leido_at']);
        });

        Schema::create('gastos_resumenes', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 190)->unique(); // usuario + canal + ventana
            $t->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $t->string('canal', 20); // correo
            $t->string('ventana', 30);
            // preparado | simulado | enviado | fallido
            // No existe «vacío»: un resumen sin pendientes NO se crea ni se envía.
            $t->string('estado', 12);
            $t->unsignedInteger('obligaciones');
            $t->json('contenido');
            $t->unsignedSmallInteger('intentos')->default(0);
            $t->string('error', 500)->nullable();
            $t->timestamp('enviado_at')->nullable();
            $t->timestamp('created_at');

            $t->index(['usuario_id', 'created_at']);
        });

        Schema::table('gastos_eventos', function (Blueprint $t) {
            $t->foreignId('regla_id')->nullable()->after('ajuste_id')->constrained('gastos_reglas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gastos_eventos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('regla_id');
        });

        foreach ([
            'gastos_resumenes', 'gastos_avisos', 'gastos_preferencias_avisos',
            'gastos_ocurrencias', 'gastos_regla_versiones', 'gastos_reglas',
        ] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};
