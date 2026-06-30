<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuenta de Gmail conectada para el módulo PPQ (OAuth2). Guarda los tokens del
 * flujo de autorización; los tokens van CIFRADOS (cast 'encrypted' en el modelo),
 * nunca en texto plano ni en logs. Normalmente hay una sola fila (la cuenta que
 * tiene los correos enviados + el label Calleja_Albaranes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmail_cuentas', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('scopes')->nullable();
            $table->foreignId('conectado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_cuentas');
    }
};
