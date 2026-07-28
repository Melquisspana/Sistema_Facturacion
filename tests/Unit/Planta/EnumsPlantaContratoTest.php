<?php

namespace Tests\Unit\Planta;

use App\Enums\Planta\EstadoAjustePlanta;
use App\Enums\Planta\EstadoCambioDisponibilidad;
use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\EstadoRecepcionPlanta;
use App\Enums\Planta\EstadoTrasladoPlanta;
use App\Enums\Planta\MercadoPlanta;
use App\Enums\Planta\TipoAjuste;
use App\Enums\Planta\TipoInsumo;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Enums\Planta\TipoUbicacion;
use App\Enums\Planta\UnidadBase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Invariantes que valen para TODOS los enums de Planta, sin importar su
 * contenido. Son el contrato que el resto de la Fase 2 da por supuesto.
 *
 * La más importante es {@see test_los_valores_caben_en_su_columna()}: los
 * estados y tipos se guardan como `string(N)` con `comment` apuntando al enum
 * PHP (el repo no usa `->enum()`), así que un valor más largo que su columna se
 * truncaría en MySQL y rompería el `match` al releerlo. Esta prueba fija los
 * anchos ANTES de que existan las migraciones, en el paso 2.
 */
class EnumsPlantaContratoTest extends TestCase
{
    /** Todos los enums del módulo. */
    private const ENUMS = [
        TipoInsumo::class,
        UnidadBase::class,
        TipoUbicacion::class,
        MercadoPlanta::class,
        EstadoDisponibilidad::class,
        TipoMovimientoPlanta::class,
        TipoAjuste::class,
        EstadoRecepcionPlanta::class,
        EstadoTrasladoPlanta::class,
        EstadoAjustePlanta::class,
        EstadoCambioDisponibilidad::class,
    ];

    /** @return array<string, array{0: class-string}> */
    public static function todosLosEnums(): array
    {
        $casos = [];
        foreach (self::ENUMS as $enum) {
            $casos[class_basename($enum)] = [$enum];
        }

        return $casos;
    }

    /**
     * Ancho de la columna `string(N)` en la que se guardará cada enum, según el
     * modelo de datos del plan (§4, §7, §8).
     *
     * @return array<string, array{0: class-string, 1: int}>
     */
    public static function anchosDeColumna(): array
    {
        return [
            'planta_insumos.tipo' => [TipoInsumo::class, 20],
            'planta_insumos.unidad_base' => [UnidadBase::class, 10],
            'planta_ubicaciones.tipo' => [TipoUbicacion::class, 20],
            'planta_empaque_configs.mercado' => [MercadoPlanta::class, 20],
            'planta_existencias.estado' => [EstadoDisponibilidad::class, 20],
            'planta_movimientos.tipo' => [TipoMovimientoPlanta::class, 40],
            'planta_ajustes.tipo' => [TipoAjuste::class, 20],
            'planta_recepciones.estado' => [EstadoRecepcionPlanta::class, 20],
            'planta_traslados.estado' => [EstadoTrasladoPlanta::class, 20],
            'planta_ajustes.estado' => [EstadoAjustePlanta::class, 20],
            'planta_cambios_disponibilidad.estado' => [EstadoCambioDisponibilidad::class, 20],
        ];
    }

    /**
     * @param  class-string  $enum
     */
    #[DataProvider('anchosDeColumna')]
    public function test_los_valores_caben_en_su_columna(string $enum, int $ancho): void
    {
        foreach ($enum::cases() as $caso) {
            $this->assertLessThanOrEqual(
                $ancho,
                strlen($caso->value),
                "El valor `{$caso->value}` ({$enum}) no cabe en una columna string({$ancho}) y se truncaría."
            );
        }
    }

    /**
     * @param  class-string  $enum
     */
    #[DataProvider('todosLosEnums')]
    public function test_los_valores_son_unicos_y_snake_case(string $enum): void
    {
        $valores = array_map(fn ($c) => $c->value, $enum::cases());

        $this->assertSame(array_unique($valores), $valores, "{$enum} tiene valores repetidos.");

        foreach ($valores as $valor) {
            $this->assertMatchesRegularExpression(
                '/^[a-z]+(_[a-z]+)*$/',
                $valor,
                "El valor `{$valor}` de {$enum} debería ser snake_case en minúsculas y sin acentos."
            );
        }
    }

    /**
     * @param  class-string  $enum
     */
    #[DataProvider('todosLosEnums')]
    public function test_todos_los_casos_tienen_etiqueta_y_color(string $enum): void
    {
        foreach ($enum::cases() as $caso) {
            $this->assertNotSame('', trim($caso->label()), "{$enum}::{$caso->name} no tiene etiqueta.");

            // Vocabulario de colores ya usado por los enums de Facturación:
            // no se introduce paleta nueva para Planta.
            $this->assertContains(
                $caso->color(),
                ['gray', 'blue', 'indigo', 'amber', 'green', 'red', 'rose'],
                "{$enum}::{$caso->name} usa un color fuera del vocabulario del repo."
            );
        }
    }

    /**
     * @param  class-string  $enum
     */
    #[DataProvider('todosLosEnums')]
    public function test_las_opciones_reflejan_todos_los_casos(string $enum): void
    {
        $opciones = $enum::opciones();

        $this->assertCount(count($enum::cases()), $opciones);

        foreach ($opciones as $opcion) {
            $this->assertArrayHasKey('value', $opcion);
            $this->assertArrayHasKey('label', $opcion);
            $this->assertNotNull($enum::tryFrom($opcion['value']));
        }
    }

    /**
     * @param  class-string  $enum
     */
    #[DataProvider('todosLosEnums')]
    public function test_las_etiquetas_no_se_repiten(string $enum): void
    {
        // Dos casos con la misma etiqueta serían indistinguibles en un select.
        $etiquetas = array_map(fn ($c) => $c->label(), $enum::cases());

        $this->assertSame(array_unique($etiquetas), $etiquetas, "{$enum} tiene etiquetas duplicadas.");
    }

    public function test_los_enums_viven_en_su_propio_espacio_de_nombres(): void
    {
        // Aislamiento: Planta no cuelga sus enums del espacio fiscal.
        foreach (self::ENUMS as $enum) {
            $this->assertStringStartsWith('App\\Enums\\Planta\\', $enum);
        }
    }
}
