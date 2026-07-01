<?php

namespace App\Support\Dte;

/**
 * Orden FIJO de productos según la orden de compra de Calleja. Se usa para ordenar
 * tanto el catálogo de "productos disponibles" como los "productos agregados" del
 * borrador, de modo que ambos sigan la misma secuencia de la OC.
 *
 * Coincidencia: por código de barras (primario) y, si no calza, por nombre
 * normalizado (secundario). Un producto que no está en la lista queda al final
 * (rank {@see FUERA_DE_ORDEN}) y se ordena por nombre.
 *
 * NOTA (decisiones/discrepancias contra el catálogo real / seeder):
 *  - BESITOS quedó FUERA de la lista fija a propósito: la OC traía un código de
 *    barras (7412201700184) que no existe en el catálogo (el producto está con
 *    7412201700284), así que se decidió NO ordenarlo ni calzarlo por nombre; va al
 *    final con los demás productos no ordenados.
 *  - Productos base de Dulces La Negrita con el mismo nombre que un ítem de la OC
 *    (p. ej. "Pepitoria", "Conserva de coco", "Semilla de marañón") calzan por
 *    nombre y comparten el rank del ítem homónimo (se agrupan, no se pierden).
 */
final class OrdenProductosOc
{
    /** Rank para lo que no está en la orden de compra: va al final. */
    public const FUERA_DE_ORDEN = PHP_INT_MAX;

    /**
     * Secuencia oficial: [nombre, código de barras]. El orden del array ES el orden.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const ORDEN = [
        ['SEMILLA DE MARAÑON', '7412201700178'],
        ['LECHE BURRA', '7412201700024'],
        ['QUIEBRADIENTE', '7412201700017'],
        ['MARAÑON DULCE', '7412201700222'],
        ['MANI DULCE', '7412201700147'],
        ['MANI AJONJOLI', '7412201700154'],
        ['PEPITORIA', '7412201700130'],
        ['MANI HORNEADO', '7412201700123'],
        ['CANILLITAS', '7412201700031'],
        ['DULCE DE NANCE', '7412201700055'],
        ['DULCE DE TAMARINDO', '7412201700062'],
        ['COCO RALLADO', '7412201700079'],
        ['DULCES DE ANIS', '7412201700192'],
        ['DULCE DE MIEL', '7412201700109'],
        ['HUEVITOS', '7412201700185'],
        ['CONSERVA DE COCO', '7412201700048'],
        ['MAZAPÁN', '7412201700115'],
    ];

    /**
     * Posición (0..n-1) del producto en la orden de compra, o {@see FUERA_DE_ORDEN}
     * si no está. Coincide primero por código de barras y, si no, por nombre.
     */
    public static function rank(?string $codigoBarra, ?string $nombre): int
    {
        $barcode = trim((string) $codigoBarra);
        if ($barcode !== '') {
            foreach (self::ORDEN as $i => [, $bc]) {
                if ($barcode === $bc) {
                    return $i;
                }
            }
        }

        $nom = self::normalizar($nombre);
        if ($nom !== '') {
            foreach (self::ORDEN as $i => [$n]) {
                if (self::normalizar($n) === $nom) {
                    return $i;
                }
            }
        }

        return self::FUERA_DE_ORDEN;
    }

    /** Mayúsculas, sin acentos, espacios colapsados: para comparar nombres. */
    private static function normalizar(?string $s): string
    {
        $s = mb_strtoupper(trim((string) $s));
        $s = strtr($s, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'Ü' => 'U']);

        return (string) preg_replace('/\s+/', ' ', $s);
    }
}
