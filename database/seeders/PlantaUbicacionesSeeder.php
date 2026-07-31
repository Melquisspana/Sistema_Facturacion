<?php

namespace Database\Seeders;

use App\Enums\Planta\TipoUbicacion;
use App\Models\Planta\PlantaUbicacion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Las TRES ubicaciones con las que opera Planta: las dos bodegas reales y la
 * ubicación de sistema donde espera la mercancía en viaje.
 *
 * IDEMPOTENTE. Se identifica por `codigo` y se usa `updateOrCreate`, así que
 * ejecutarlo dos veces no duplica nada: la segunda vez encuentra las tres filas y
 * las deja como están.
 *
 * NO SE REGISTRA EN {@see DatabaseSeeder} a propósito. Sembrar TRANSITO es una
 * decisión de configuración con consecuencias —a partir de que existe, los
 * traslados pueden enviarse— y debe invocarse a mano:
 *
 *     php artisan db:seed --class=PlantaUbicacionesSeeder
 *
 * Tampoco siembra proveedores, insumos ni lotes: eso son datos de la operación,
 * no infraestructura del módulo.
 *
 * POR QUÉ COMPRUEBA EL HISTORIAL ANTES DE ACTUALIZAR
 * --------------------------------------------------
 * `updateOrCreate` por código es cómodo y peligroso a la vez: si alguien ya creó
 * a mano una ubicación llamada CASA como ubicación de TRÁNSITO, o al revés, este
 * seeder la reescribiría en silencio. Y si esa fila ya tiene saldo, movimientos o
 * documentos, cambiarle el tipo movería inventario real de categoría sin que
 * nadie lo pidiera: saldo que estaba en una bodega pasaría a contar como
 * mercancía en viaje, o al contrario.
 *
 * Por eso, antes de tocar una fila existente:
 *
 *   - si es COMPATIBLE (mismo tipo y misma condición de sistema), se actualiza:
 *     no hay riesgo, solo se refrescan nombre y orden;
 *   - si es INCOMPATIBLE y NO tiene historial, se corrige: es una fila mal
 *     creada y vacía, arreglarla es justo lo que hace falta;
 *   - si es INCOMPATIBLE y SÍ tiene historial, se ABORTA con un error que dice
 *     qué encontró y qué esperaba. Resolverlo es una decisión de la persona, no
 *     de un seeder.
 */
class PlantaUbicacionesSeeder extends Seeder
{
    /**
     * Definición de las tres. El orden fija cómo se listan en los selectores.
     *
     * @var array<int, array<string, mixed>>
     */
    private const UBICACIONES = [
        [
            'codigo' => 'CASA',
            'nombre' => 'Casa',
            'tipo' => TipoUbicacion::Fisica,
            'es_sistema' => false,
            'permite_operacion_manual' => true,
            'activo' => true,
            'orden' => 10,
        ],
        [
            'codigo' => 'FABRICA',
            'nombre' => 'Fábrica',
            'tipo' => TipoUbicacion::Fisica,
            'es_sistema' => false,
            'permite_operacion_manual' => true,
            'activo' => true,
            'orden' => 20,
        ],
        [
            'codigo' => 'TRANSITO',
            'nombre' => 'En tránsito',
            'tipo' => TipoUbicacion::Transito,
            'es_sistema' => true,
            'permite_operacion_manual' => false,
            'activo' => true,
            'orden' => 99,
        ],
    ];

    public function run(): void
    {
        foreach (self::UBICACIONES as $definicion) {
            $this->sembrar($definicion);
        }
    }

    /** @param  array<string, mixed>  $definicion */
    private function sembrar(array $definicion): void
    {
        // `withTrashed`: una ubicación eliminada lógicamente sigue ocupando el
        // código (el unique no distingue borrados), así que ignorarla haría
        // fallar el insert con un duplicado incomprensible.
        $existente = PlantaUbicacion::withTrashed()->where('codigo', $definicion['codigo'])->first();

        if ($existente !== null) {
            $this->exigirCompatibilidad($existente, $definicion);
        }

        $atributos = [
            'nombre' => $definicion['nombre'],
            'tipo' => $definicion['tipo']->value,
            'es_sistema' => $definicion['es_sistema'],
            'permite_operacion_manual' => $definicion['permite_operacion_manual'],
            'activo' => $definicion['activo'],
            'orden' => $definicion['orden'],
        ];

        if ($existente !== null) {
            // Se escribe con el query builder a propósito: el candado de
            // {@see PlantaUbicacion} bloquea tocar las de sistema por Eloquent, y
            // aquí el que escribe es el propio módulo, no un formulario. Es la
            // única puerta legítima, y por eso está sola y comentada.
            PlantaUbicacion::withTrashed()->whereKey($existente->getKey())
                ->update($atributos + ['deleted_at' => null, 'updated_at' => now()]);

            $this->command?->info("  · {$definicion['codigo']} ya existía: actualizada.");

            return;
        }

        PlantaUbicacion::create(['codigo' => $definicion['codigo']] + $atributos);

        $this->command?->info("  · {$definicion['codigo']} creada.");
    }

    /**
     * Aborta si la fila existente es de otra naturaleza Y ya tiene historial.
     *
     * @param  array<string, mixed>  $definicion
     */
    private function exigirCompatibilidad(PlantaUbicacion $existente, array $definicion): void
    {
        $mismoTipo = $existente->tipo === $definicion['tipo'];
        $mismaCondicion = (bool) $existente->es_sistema === $definicion['es_sistema'];

        if ($mismoTipo && $mismaCondicion) {
            return;
        }

        $historial = $this->historialDe($existente);

        if (array_sum($historial) === 0) {
            // Fila mal creada y vacía: corregirla es justo lo que hace falta.
            return;
        }

        throw new RuntimeException(sprintf(
            'La ubicación «%s» ya existe con tipo «%s» y es_sistema=%s, pero este seeder la define '.
            'como tipo «%s» y es_sistema=%s. Además ya tiene historial (%s), así que cambiarla '.
            'movería inventario real de categoría. El seeder no decide esto: revisa esa ubicación y '.
            'renómbrala o corrígela a mano antes de volver a ejecutarlo.',
            $existente->codigo,
            $existente->tipo->value,
            $existente->es_sistema ? 'true' : 'false',
            $definicion['tipo']->value,
            $definicion['es_sistema'] ? 'true' : 'false',
            collect($historial)->filter()->map(fn ($n, $q) => "{$n} {$q}")->implode(', '),
        ));
    }

    /**
     * Cuánto historial cuelga de esta ubicación. Cero significa que nunca se usó.
     *
     * @return array<string, int>
     */
    private function historialDe(PlantaUbicacion $ubicacion): array
    {
        $id = $ubicacion->getKey();

        return [
            'movimientos' => DB::table('planta_movimientos')->where('planta_ubicacion_id', $id)->count(),
            'existencias' => DB::table('planta_existencias')->where('planta_ubicacion_id', $id)->count(),
            'recepciones' => DB::table('planta_recepciones')->where('planta_ubicacion_id', $id)->count(),
            'cambios de disponibilidad' => DB::table('planta_cambios_disponibilidad')->where('planta_ubicacion_id', $id)->count(),
            'traslados' => DB::table('planta_traslados')
                ->where('planta_ubicacion_origen_id', $id)
                ->orWhere('planta_ubicacion_destino_id', $id)
                ->count(),
        ];
    }
}
