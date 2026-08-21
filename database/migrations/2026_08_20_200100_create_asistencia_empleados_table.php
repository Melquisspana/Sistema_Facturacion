<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personas que marcan asistencia.
 *
 * POR QUÉ NO SE REUTILIZA `users`. Un `User` es alguien que ENTRA AL SISTEMA:
 * tiene correo, contraseña, roles de Spatie y permisos fiscales. Quien marca
 * asistencia es alguien que TRABAJA ACÁ: puede no tener correo, no tener
 * contraseña y no deber entrar jamás al sistema de facturación. Meterlos en
 * `users` obligaría a inventar correos falsos, a que aparezcan en el listado de
 * usuarios y a que cada permiso nuevo tenga que preguntarse si aplica a gente que
 * ni siquiera tiene login. Son dos conceptos distintos que se parecen.
 *
 * El puente existe igual: `user_id` es NULLABLE y ÚNICO. Si un empleado además
 * usa el sistema (un administrador que también marca), se enlazan las dos filas
 * sin duplicar identidad y sin que ninguno de los dos lados dependa del otro.
 *
 * PREPARADA PARA PLANILLA, sin implementarla: `codigo` (el número con el que
 * Recursos Humanos ya identifica a cada quien) y `fecha_ingreso` son los dos
 * datos que después piden horarios, antigüedad y planilla, y son los dos que
 * duele rellenar hacia atrás. Nada más se anticipa: salarios, cargos, horarios y
 * departamentos llegan cuando existan sus reglas.
 *
 * NO se borra gente: `activo = false`. Un empleado con marcaciones no puede
 * desaparecer sin llevarse historial laboral por delante, y por eso
 * `asistencia_marcaciones` lo protege con `restrictOnDelete`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencia_empleados', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->nullable()->unique()
                ->comment('Código de planilla/RRHH. Nullable: hoy no todos lo tienen');
            $table->string('nombres', 80);
            $table->string('apellidos', 80);
            $table->boolean('activo')->default(true)
                ->comment('false = no puede marcar. Nunca se borra: se desactiva');
            $table->date('fecha_ingreso')->nullable();
            $table->foreignId('user_id')->nullable()->unique()
                ->constrained('users')->nullOnDelete()
                ->comment('Enlace OPCIONAL con el usuario del sistema, si además tiene login');
            $table->timestamps();

            $table->index('activo', 'asistencia_empleados_activo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_empleados');
    }
};
