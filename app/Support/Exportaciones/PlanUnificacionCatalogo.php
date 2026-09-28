<?php

namespace App\Support\Exportaciones;

/**
 * Tabla de unificación del catálogo de exportación, aprobada por el usuario el
 * 28/09/2026 (ver docs/DISENO_CATALOGO_EXPORTACION.md).
 *
 * Cada presentación se identifica por su ID Y por lo que se espera encontrar en
 * él —una palabra de su nombre, unidades por caja y gramos—. Si la base donde se
 * corre no coincide (otra copia, IDs distintos), el comando aborta antes de tocar
 * nada: los IDs solos no bastan para fusionar precios negociados.
 *
 * Formato de cada presentación:
 *   'conservar' => [id, clave, unidades, gramos]      la fila que sobrevive
 *   'fusionar'  => [[id, clave, unidades, gramos]…]   se funden en la anterior
 *   'gramos'    => nuevo valor                        corrección puntual
 */
final class PlanUnificacionCatalogo
{
    public const UNIDAD_ESTANDAR = [
        144 => 'Bolsa de polipropileno 12x12',
        216 => 'Bolsa de polipropileno 12x18',
        288 => 'Bolsa de polipropileno 24x12',
        120 => 'Bolsa de polipropileno 12x10',
    ];

    /** @return list<array<string, mixed>> */
    public static function productos(): array
    {
        return [
            // ── Semillas y maní
            self::base('Semilla de marañón horneada', 'Baked cashew seed', 'semillas', [
                ['conservar' => [1, 'maranon', 216, 45]],
                ['conservar' => [33, 'maranon', 144, 85], 'fusionar' => [[63, 'maranon', 144, 85]]],
            ]),
            self::base('Semilla de marañón con chile', 'Baked cashew seed with chili', 'semillas', [
                ['conservar' => [41, 'maranon', 144, 85]],
                ['conservar' => [49, 'maranon', 144, 60]],
            ]),
            self::base('Maní dulce', 'Sweet baked peanut', 'semillas', [['conservar' => [2, 'mani dulce', 144, 85]]]),
            self::base('Maní horneado', 'Baked peanut', 'semillas', [['conservar' => [4, 'mani horneado', 144, 85]]]),
            self::base('Maní horneado con ajonjolí', 'Baked peanut with sesame seed', 'semillas', [['conservar' => [3, 'ajonjoli', 144, 85]]]),
            self::base('Maní horneado con chile', 'Baked peanut with chili', 'semillas', [
                ['conservar' => [9, 'chile', 144, 85]],
                ['conservar' => [30, 'chile', 144, 90.63]],
            ]),
            self::base('Maní japonés', 'Japanese peanuts', 'semillas', [['conservar' => [10, 'japones', 216, 75]]]),
            self::base('Pepita natural', 'Natural pumpkin seed', 'semillas', [['conservar' => [11, 'pepita', 216, 55]]]),
            self::base('Pepitoria', 'Pumpkin seed brittle', 'semillas', [['conservar' => [69, 'pepitoria', 216, 70]]]),
            self::base('Pistacho', 'Pistachio', 'semillas', [['conservar' => [15, 'pistacho', 216, 50]]]),
            self::base('Mix de semillas dulces', 'Sweet seeds mix', 'semillas', [['conservar' => [40, 'semillas', 144, 85]]]),

            // ── Dulces tradicionales
            self::base('Coco rayado', 'Shredded coconut candy', 'tradicionales', [
                ['conservar' => [7, 'coco', 144, 60]],
                ['conservar' => [45, 'coco', 144, 85], 'fusionar' => [[64, 'coco', 144, 85]]],
                ['conservar' => [25, 'coco', 120, 91.67]],
            ]),
            self::base('Conserva de coco oscura', 'Dark coconut candy', 'tradicionales', [['conservar' => [39, 'coco', 144, 85]]]),
            self::base('Cocada', 'Coconut sweet', 'tradicionales', [
                ['conservar' => [27, 'cocada', 144, 50], 'fusionar' => [[65, 'cocada', 144, 50]]],
            ]),
            self::base('Alfeñique', 'Sugar cane candy', 'tradicionales', [
                ['conservar' => [35, 'alfe', 144, 70], 'fusionar' => [[58, 'alfe', 144, 70]]],
            ]),
            self::base('Bandeja de alfeñique grande', 'Large alfeñique candy tray', 'tradicionales', [['conservar' => [23, 'bandeja', 36, 180]]]),
            self::base('Dulce de batido', 'Sweet sugar candy', 'tradicionales', [
                ['conservar' => [46, 'batido', 144, 110], 'fusionar' => [[59, 'batido', 144, 110]]],
                ['conservar' => [36, 'batido', 144, 147.64]],
            ]),
            self::base('Dulce de alegría', 'Alegría candy', 'tradicionales', [
                ['conservar' => [26, 'alegr', 144, 60], 'fusionar' => [[61, 'alegr', 144, 60]]],
            ]),
            self::base('Melcocha', 'Molasses candy', 'tradicionales', [
                ['conservar' => [14, 'melcocha', 216, 75]],
                ['conservar' => [42, 'melcocha', 144, 75], 'fusionar' => [[32, 'melcocha', 144, 75]]],
                ['conservar' => [31, 'melcocha', 144, 90.97]],
            ]),
            self::base('Dulce de nance', 'Yellow cherry candy', 'tradicionales', [
                ['conservar' => [6, 'nance', 216, 85]],
                ['conservar' => [28, 'nance', 144, 85]],
            ]),
            // El usuario confirmó que la caja de Diamond (80 g) es la misma: se declaran 85 g.
            self::base('Huevitos', 'Little eggs candy', 'tradicionales', [
                ['conservar' => [16, 'huevitos', 216, 85], 'fusionar' => [[74, 'huevitos', 216, 80]]],
                ['conservar' => [38, 'huevitos', 144, 85]],
            ]),
            self::base('Dulce de camote', 'Sweet potato candy', 'tradicionales', [['conservar' => [5, 'camote', 144, 85]]]),
            self::base('Canillitas', 'Milk candy sticks', 'tradicionales', [
                ['conservar' => [47, 'canillitas', 144, 70], 'fusionar' => [[67, 'canillitas', 144, 70]]],
            ]),
            self::base('Espumillas', 'Meringue candy', 'tradicionales', [['conservar' => [24, 'espumillas', 36, 60]]]),
            self::base('Pachanga salvadoreña en tiras', 'Pachanga candy mix strips', 'tradicionales', [['conservar' => [73, 'pachanga', 42, 70]]]),
            // El Excel decía 0.5 g por canasta; el usuario confirmó que son 50 g.
            self::base('Canastas con dulces', 'Baskets with sweets', 'tradicionales', [
                ['conservar' => [76, 'canastas', 24, 0.5], 'gramos' => 50],
            ]),

            // ── Caramelos
            self::base('Caramelo de coco', 'Coconut candy', 'caramelos', [['conservar' => [21, 'coco', 144, 85]]]),
            self::base('Dulce de anís', 'Aniseed candy', 'caramelos', [
                ['conservar' => [48, 'anis', 144, 85], 'fusionar' => [[72, 'aniz', 144, 85]]],
            ]),
            self::base('Dulce de menta', 'Hard mint candy', 'caramelos', [['conservar' => [22, 'menta', 144, 85]]]),
            self::base('Caramelo de naranja', 'Orange candy', 'caramelos', [['conservar' => [12, 'naranja', 144, 85]]]),
            self::base('Caramelo de miel', 'Honey candy', 'caramelos', [['conservar' => [13, 'miel', 144, 85]]]),
            self::base('Caramelo de colores', 'Colorful hard candy', 'caramelos', [['conservar' => [68, 'colores', 144, 85]]]),
            // Producto distinto de la miel con jengibre (confirmado por el usuario).
            self::base('Dulce de miel', 'Honey sweet', 'caramelos', [['conservar' => [43, 'miel', 144, 60]]]),
            self::base('Dulce de miel con jengibre', 'Honey candy with ginger', 'caramelos', [
                ['conservar' => [60, 'genjibre', 144, 60]],
                ['conservar' => [34, 'jengibre', 144, 76.39]],
            ]),
            self::base('Trocitos dulces', 'Hard candy pieces', 'caramelos', [['conservar' => [66, 'trocitos', 144, 70]]]),
            self::base('Dulces variados mix', 'Assorted candy mix', 'caramelos', [['conservar' => [8, 'mix', 144, 90]]]),
            self::base('Quiebradientes en tableta', 'Hard candy bar', 'caramelos', [['conservar' => [70, 'tableta', 216, 85]]]),
            // El «quiebradiente» 144 × 85 g de Solfi son trocitos (confirmado por el usuario).
            self::base('Quiebradientes en trocitos', 'Hard candy chunks', 'caramelos', [
                ['conservar' => [71, 'trocitos', 144, 70]],
                ['conservar' => [37, 'quiebradiente', 144, 85]],
            ]),
            self::base('Dulce de cereza', 'Cherry candy', 'caramelos', [['conservar' => [18, 'cereza', 216, 60]]]),

            // ── Paletas y nougat
            self::base('Pirulín', 'Pirulín lollipop', 'paletas', [
                ['conservar' => [29, 'pirul', 216, 66.67]],
                ['conservar' => [44, 'pirul', 144, 70], 'fusionar' => [[62, 'pirul', 144, 70]]],
            ]),
            self::base('Paleta rosada', 'Pink lollipop', 'paletas', [['conservar' => [20, 'paleta', 216, 45]]]),
            self::base('Nougat de fresa', 'Strawberry nougat', 'paletas', [['conservar' => [17, 'fresa', 216, 55]]]),
            self::base('Nougat de vainilla', 'Vanilla nougat', 'paletas', [['conservar' => [75, 'vainilla', 216, 55]]]),

            // ── Chicles y chocolate
            self::base('Clorets', 'Clorets gum', 'chicles', [['conservar' => [19, 'clorets', 288, 30]]]),

            // ── Exhibidores
            self::base('Mueble exhibidor de hierro', 'Iron display rack, 1.27 × 0.26 × 0.21 m', 'exhibidores', [
                ['conservar' => [77, 'exhibidor', 5, 1000]],
            ]),
        ];
    }

    /**
     * Restos de una importación mala y un mix que nunca se usó. Se ARCHIVAN
     * (activo = false), no se borran.
     *
     * @return list<array{0:int,1:string,2:int,3:float}>
     */
    public static function archivar(): array
    {
        return [
            [50, 'mani dulce', 720, 120.96],
            [51, 'sal', 720, 288],
            [52, 'chile', 720, 288],
            [53, 'ajonjoli', 720, 120.96],
            [54, 'jengibre', 288, 129.6],
            [56, 'melcocha', 864, 120.96],
            [57, 'mixtos', 144, 75],
        ];
    }

    /**
     * Presentaciones que el cliente compró según las listas en Excel de Drive y
     * que nunca se cargaron al sistema. El precio queda como vigente del cliente
     * con la fecha de esa lista.
     *
     * @return list<array<string, mixed>>
     */
    public static function nuevas(): array
    {
        $carolinas = 'IMPORTADOR NORTE';

        return [
            [
                'base' => ['Clorets azul', 'Blue Clorets gum', 'chicles'],
                'unidades' => 288, 'gramos' => 30, 'unidad' => self::UNIDAD_ESTANDAR[288], 'neto' => 9.5,
                'cliente' => $carolinas, 'precio' => 238.80, 'fecha' => '2026-02-05',
            ],
            [
                'base' => ['Bubbaloo', 'Bubbaloo gum', 'chicles'],
                'unidades' => 216, 'gramos' => 50, 'unidad' => self::UNIDAD_ESTANDAR[216], 'neto' => 10.3,
                'cliente' => $carolinas, 'precio' => 172.80, 'fecha' => '2025-11-13',
            ],
            [
                'base' => ['Monedas de chocolate', 'Chocolate coins', 'chicles'],
                'unidades' => 216, 'gramos' => 60, 'unidad' => self::UNIDAD_ESTANDAR[216], 'neto' => 15.5,
                'cliente' => $carolinas, 'precio' => 237.60, 'fecha' => '2026-05-13',
            ],
            [
                // Presentación nueva de un base que ya existe en productos().
                'base' => ['Canastas con dulces', 'Baskets with sweets', 'tradicionales'],
                'unidades' => 18, 'gramos' => 50, 'unidad' => 'Caja Master', 'neto' => 11.5,
                'cliente' => $carolinas, 'precio' => 90.00, 'fecha' => '2026-09-10',
            ],
        ];
    }

    private static function base(string $es, string $en, string $categoria, array $presentaciones): array
    {
        return ['nombre_es' => $es, 'nombre_en' => $en, 'categoria' => $categoria, 'presentaciones' => $presentaciones];
    }
}
