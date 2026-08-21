<?php

use App\Ajustes\RepositorioAjustes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Overrides de configuración administrables desde la aplicación (Centro de
 * Configuración). Tabla DELIBERADAMENTE mínima.
 *
 * Por qué NO se reutiliza `configuraciones`:
 *  - esa tabla guarda texto plano y no tiene forma de distinguir un secreto de
 *    un valor público; meter contraseñas ahí sería guardarlas legibles;
 *  - no sabe qué filas están cifradas, que es la precondición para poder rotar
 *    APP_KEY algún día (ver docs/ROTACION_APP_KEY.md);
 *  - sus 8 claves actuales siguen viviendo donde están: esta tabla no las copia.
 *
 * Por qué NO lleva columnas de metadata (tipo, nivel, sección, validación): esa
 * información vive en App\Ajustes\CatalogoAjustes, en código. Una fila de BD no
 * puede declarar reglas de validación sin convertirse en un mini-lenguaje, y
 * agregar un ajuste fiscal crítico debe verse en un diff, no en un INSERT.
 *
 * `cifrado` no es decorativo: es lo que permite a un futuro comando de rotación
 * localizar exactamente qué filas hay que descifrar y volver a cifrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes_sistema', function (Blueprint $table) {
            $table->id();

            // 191 y no 255: índice único compatible con utf8mb4 en MySQL antiguo.
            $table->string('clave', 191)->unique();

            // Texto plano para los ajustes normales; criptograma de Crypt para los
            // secretos (por eso longText: el cifrado de Laravel expande el valor).
            $table->longText('valor')->nullable();

            // ¿El contenido de `valor` está cifrado con APP_KEY?
            $table->boolean('cifrado')->default(false);

            $table->timestamps();

            // Barrido de la rotación de APP_KEY: "dame todas las filas cifradas".
            $table->index('cifrado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_sistema');

        // Al desaparecer la tabla, el mapa que los procesos tienen cacheado pasa a
        // describir filas que ya no existen: durante los 5 minutos de su TTL seguirían
        // resolviendo overrides FANTASMA, con fuente «base de datos», leídos de una
        // tabla borrada. Invalidando acá, la siguiente lectura ve que no hay tabla y
        // cada clave vuelve a su fallback, que es la verdad.
        //
        // No puede tumbar el rollback: la tabla ya se borró y una caché que no responde
        // se arregla sola al vencer la TTL.
        try {
            app(RepositorioAjustes::class)->invalidar();
        } catch (Throwable $e) {
            report($e);
        }
    }
};
