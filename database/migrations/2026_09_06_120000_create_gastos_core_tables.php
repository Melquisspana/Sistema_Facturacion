<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gastos', function (Blueprint $t) {
            $t->id();
            $t->uuid('clave')->unique();
            $t->char('huella_peticion', 64);
            $t->string('beneficiario', 180);
            $t->string('concepto', 200);
            $t->string('categoria', 100);
            $t->string('ambito', 20)->index();
            $t->string('persona', 180)->nullable();
            $t->string('naturaleza', 30);
            $t->char('moneda', 3);
            $t->decimal('importe', 14, 2)->nullable();
            $t->date('periodo_desde')->nullable();
            $t->date('periodo_hasta')->nullable();
            $t->string('documentacion', 30);
            $t->text('observaciones')->nullable();
            $t->foreignId('responsable_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('gastos_cuotas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('gasto_id')->constrained('gastos')->restrictOnDelete();
            $t->unsignedInteger('numero');
            $t->decimal('importe', 14, 2);
            $t->date('vence')->nullable()->index();
            $t->unique(['gasto_id', 'numero']);
        });
        Schema::create('gastos_pagos', function (Blueprint $t) {
            $t->id();
            $t->uuid('clave')->unique();
            $t->char('huella_peticion', 64);
            $t->string('beneficiario', 180);
            $t->char('moneda', 3);
            $t->decimal('importe', 14, 2);
            $t->date('fecha');
            $t->string('metodo', 30);
            $t->foreignId('pagado_por')->constrained('users')->restrictOnDelete();
            $t->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $t->string('referencia', 180)->nullable();
            $t->string('sin_comprobante', 250)->nullable();
            $t->timestamp('revertido_at')->nullable();
            $t->foreignId('revertido_por')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('motivo_reversion', 500)->nullable();
            $t->timestamps();
        });
        Schema::create('gastos_pago_aplicaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pago_id')->constrained('gastos_pagos')->restrictOnDelete();
            $t->foreignId('cuota_id')->constrained('gastos_cuotas')->restrictOnDelete();
            $t->decimal('importe', 14, 2);
            $t->unique(['pago_id', 'cuota_id']);
        });
        Schema::create('gastos_adjuntos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('gasto_id')->nullable()->constrained('gastos')->restrictOnDelete();
            $t->foreignId('pago_id')->nullable()->constrained('gastos_pagos')->restrictOnDelete();
            $t->string('ruta');
            $t->string('nombre');
            $t->string('mime', 100);
            $t->unsignedBigInteger('bytes');
            $t->char('sha256', 64)->index();
            $t->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        // Separada de Activitylog general: sus lectores no necesariamente ven datos personales.
        Schema::create('gastos_eventos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('gasto_id')->nullable()->constrained('gastos')->restrictOnDelete();
            $t->foreignId('pago_id')->nullable()->constrained('gastos_pagos')->restrictOnDelete();
            $t->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $t->string('accion', 40);
            $t->json('datos');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['gastos_eventos', 'gastos_adjuntos', 'gastos_pago_aplicaciones', 'gastos_pagos', 'gastos_cuotas', 'gastos'] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};
