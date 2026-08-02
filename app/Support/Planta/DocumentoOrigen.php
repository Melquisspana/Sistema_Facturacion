<?php

namespace App\Support\Planta;

use App\Models\Planta\PlantaAjuste;
use App\Models\Planta\PlantaCambioDisponibilidad;
use App\Models\Planta\PlantaRecepcion;
use App\Models\Planta\PlantaTraslado;

/**
 * Traduce el `documento_type` de una fila del libro mayor a algo que se pueda
 * enseñar y enlazar.
 *
 * MAPA EXPLÍCITO, no adivinación. `planta_movimientos` guarda el FQCN del
 * documento en una relación polimórfica suelta, sin morph map, así que sería
 * tentador derivar la ruta del nombre de la clase. No se hace: un mapa cerrado
 * es la única forma de que el historial no se rompa cuando aparezca un tipo que
 * no tenga pantalla —o que la tenga con otro nombre—, y de que renombrar un
 * modelo falle aquí, de golpe, en vez de generar enlaces rotos en silencio.
 *
 * Lo DESCONOCIDO es un caso normal, no un error: el mayor puede contener tipos
 * escritos por pruebas o por documentos de fases futuras que todavía no tienen
 * pantalla. Para esos, {@see ruta()} devuelve null y la vista imprime texto sin
 * enlace. Nunca se lanza una excepción por leer el historial.
 *
 * El PERMISO va en el mapa junto a la ruta porque enlazar a una pantalla que va
 * a responder 403 es peor que no enlazar: el candado sigue siendo el middleware
 * de la ruta, pero el enlace no debe prometer lo que no se puede abrir.
 */
final class DocumentoOrigen
{
    /**
     * Tipos de documento que mueven inventario y tienen pantalla propia.
     *
     * @var array<class-string, array{clave: string, etiqueta: string, ruta: string, permiso: string}>
     */
    private const MAPA = [
        PlantaRecepcion::class => [
            'clave' => 'recepcion',
            'etiqueta' => 'Recepción',
            'ruta' => 'planta.recepciones.show',
            'permiso' => 'planta.recepciones.ver',
        ],
        PlantaTraslado::class => [
            'clave' => 'traslado',
            'etiqueta' => 'Traslado',
            'ruta' => 'planta.traslados.show',
            'permiso' => 'planta.traslados.ver',
        ],
        PlantaAjuste::class => [
            'clave' => 'ajuste',
            'etiqueta' => 'Ajuste',
            'ruta' => 'planta.ajustes.show',
            'permiso' => 'planta.ajustes.ver',
        ],
        PlantaCambioDisponibilidad::class => [
            'clave' => 'disponibilidad',
            'etiqueta' => 'Cambio de disponibilidad',
            'ruta' => 'planta.disponibilidad.show',
            'permiso' => 'planta.existencias.ver',
        ],
    ];

    /** Nombre legible del tipo. Para lo no mapeado, el nombre corto de la clase. */
    public static function etiqueta(?string $type): string
    {
        if ($type === null || $type === '') {
            return 'Sin documento';
        }

        return self::MAPA[self::normalizar($type)]['etiqueta'] ?? class_basename($type);
    }

    /** Ruta de la ficha del documento, o null si ese tipo no tiene pantalla. */
    public static function ruta(?string $type): ?string
    {
        return $type === null ? null : (self::MAPA[self::normalizar($type)]['ruta'] ?? null);
    }

    /** Permiso que exige esa ficha, o null si no hay pantalla que abrir. */
    public static function permiso(?string $type): ?string
    {
        return $type === null ? null : (self::MAPA[self::normalizar($type)]['permiso'] ?? null);
    }

    /** Clase del modelo, para cargar en lote los números de documento. */
    public static function modelo(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $normalizado = self::normalizar($type);

        return isset(self::MAPA[$normalizado]) ? $normalizado : null;
    }

    /** FQCN a partir de la clave corta que viaja en el querystring. */
    public static function claseDesdeClave(?string $clave): ?string
    {
        if ($clave === null || $clave === '') {
            return null;
        }

        foreach (self::MAPA as $clase => $datos) {
            if ($datos['clave'] === $clave) {
                return $clase;
            }
        }

        return null;
    }

    /**
     * Opciones del filtro por tipo de documento.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function opciones(): array
    {
        return array_values(array_map(
            fn (array $datos) => ['value' => $datos['clave'], 'label' => $datos['etiqueta']],
            self::MAPA,
        ));
    }

    /**
     * El servicio guarda el tipo con `ltrim($type, '\\')`, pero una fila escrita
     * a mano podría traer la barra inicial. Normalizar aquí evita que el mismo
     * documento se muestre enlazado unas veces y como texto otras.
     */
    private static function normalizar(string $type): string
    {
        return ltrim($type, '\\');
    }
}
