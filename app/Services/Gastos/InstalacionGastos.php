<?php

namespace App\Services\Gastos;

use App\Http\Middleware\ModuloGastosActivo;
use App\Models\Gastos\Aviso;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * ¿Está instalada la fase 2 de Gastos en ESTA base de datos?
 *
 * Existe por un fallo real: el menú lateral contaba avisos sin leer con una
 * consulta directa a `gastos_avisos`. En una base con las migraciones de fase 2 sin
 * aplicar esa tabla no existe, la consulta reventaba, y como el menú se dibuja en
 * TODAS las pantallas, el sistema entero respondía 500 —incluido el dashboard, que
 * no tiene nada que ver con Gastos—.
 *
 * La lección no es «faltaba una tabla»: es que **el menú no puede tumbar la
 * aplicación**. Un contador decorativo es lo menos importante de la pantalla y no
 * puede ser lo que decida si la pantalla existe. De ahí las dos reglas que este
 * servicio impone:
 *
 *  1. Nadie consulta tablas de Gastos desde una vista compartida. Se pregunta acá,
 *     y acá se responde con un valor seguro cuando no hay de dónde sacarlo.
 *  2. El código de módulo no asume su propio esquema. Entre desplegar el código y
 *     correr las migraciones hay una ventana —a veces de días, si las migraciones
 *     esperan autorización— y durante esa ventana el resto del sistema tiene que
 *     seguir funcionando exactamente igual.
 *
 * Esto NO reemplaza al interruptor del módulo ({@see ModuloGastosActivo}):
 * son preguntas distintas. «Apagado» es una decisión; «sin instalar» es un estado
 * del esquema. Un módulo encendido y sin migrar debe avisar que faltan migraciones,
 * no fingir que no existe.
 *
 * La respuesta se memoiza por petición: el servicio se registra como singleton, así
 * que las decenas de pantallas de un render preguntan una vez.
 */
final class InstalacionGastos
{
    /**
     * Tablas que la fase 2 necesita para funcionar. `gastos_avisos` y `gastos_reglas`
     * alcanzan como testigos: la migración las crea todas juntas o ninguna.
     */
    public const TABLAS_FASE2 = [
        'gastos_reglas',
        'gastos_regla_versiones',
        'gastos_ocurrencias',
        'gastos_preferencias_avisos',
        'gastos_avisos',
        'gastos_resumenes',
    ];

    private ?bool $fase2 = null;

    /** ¿Existen las tablas de recurrencias y avisos? */
    public function fase2Instalada(): bool
    {
        return $this->fase2 ??= Schema::hasTable('gastos_avisos') && Schema::hasTable('gastos_reglas');
    }

    /**
     * Qué tablas faltan. Solo se calcula cuando ya sabemos que algo falta, para no
     * gastar seis consultas en el camino normal.
     *
     * @return array<int, string>
     */
    public function tablasQueFaltan(): array
    {
        if ($this->fase2Instalada()) {
            return [];
        }

        return array_values(array_filter(
            self::TABLAS_FASE2,
            fn (string $tabla) => ! Schema::hasTable($tabla),
        ));
    }

    /**
     * Avisos sin leer de una persona. CERO cuando la fase 2 no está instalada, y
     * cero también sin usuario: es un contador de menú, y un contador de menú no
     * puede lanzar una excepción.
     */
    public function avisosSinLeer(?User $usuario): int
    {
        if ($usuario === null || ! $this->fase2Instalada()) {
            return 0;
        }

        return Aviso::where('usuario_id', $usuario->id)->whereNull('leido_at')->count();
    }

    /** Mensaje para quien tenga que resolverlo: dice qué falta y qué hay que correr. */
    public function motivoNoInstalada(): string
    {
        return 'Recurrencias y avisos de Gastos todavía no están instalados en esta base de datos: '
            .'faltan las tablas '.implode(', ', $this->tablasQueFaltan()).'. '
            .'El resto del sistema funciona con normalidad. Para habilitarlo hay que aplicar '
            .'las migraciones pendientes (php artisan migrate).';
    }

    /** Solo para las pruebas: olvida lo memoizado tras cambiar el esquema. */
    public function olvidar(): void
    {
        $this->fase2 = null;
    }
}
