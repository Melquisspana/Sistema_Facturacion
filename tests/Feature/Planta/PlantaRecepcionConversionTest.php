<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\UnidadBase;
use App\Exceptions\Planta\MovimientoInvalidoException;
use App\Exceptions\Planta\RecepcionInvalidaException;
use App\Models\Planta\PlantaRecepcionDetalle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Conversión de la unidad de COMPRA a la unidad BASE del inventario.
 *
 *     cantidad_base = round(cantidad_recibida × contenido_por_unidad × factor_conversion, 4)
 *
 * Todo se calcula con bcmath sobre cadenas. bcmath TRUNCA, así que el medio-arriba
 * se hace sumando medio último dígito antes de truncar; hacerlo con `round()` de
 * PHP obligaría a pasar por coma flotante, que es exactamente lo que un
 * inventario no puede permitirse.
 *
 * La prueba que más importa es {@see test_la_cantidad_base_del_formulario_se_ignora()}:
 * `cantidad_base` es un valor DERIVADO, y aceptarlo de la petición permitiría
 * declarar 5 sacos y meter 50.000 libras.
 */
class PlantaRecepcionConversionTest extends TestCase
{
    use RecepcionPlantaFixtures;
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function ejemplosDeConversion(): array
    {
        return [
            '5 sacos x 100 lb x 1' => ['5', '100', '1', '500.0000'],
            '1 saco x 50 kg x 2.20462262' => ['1', '50', '2.20462262', '110.2311'],
            '20 paquetes x 100 bolsas x 1' => ['20', '100', '1', '2000.0000'],
            '3 paquetes x 1000 viñetas x 1' => ['3', '1000', '1', '3000.0000'],
            '4 cajas x 144 unidades x 1' => ['4', '144', '1', '576.0000'],
        ];
    }

    #[DataProvider('ejemplosDeConversion')]
    public function test_los_ejemplos_de_conversion_dan_el_resultado_exacto(
        string $cantidad,
        string $contenido,
        string $factor,
        string $esperado,
    ): void {
        $this->assertSame($esperado, PlantaRecepcionDetalle::convertir($cantidad, $contenido, $factor));
    }

    #[DataProvider('ejemplosDeConversion')]
    public function test_los_ejemplos_se_guardan_en_el_borrador(
        string $cantidad,
        string $contenido,
        string $factor,
        string $esperado,
    ): void {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($insumo, [
                'cantidad_recibida' => $cantidad,
                'contenido_por_unidad' => $contenido,
                'factor_conversion' => $factor,
            ]),
        ]), $this->admin());

        $this->assertSame($esperado, $recepcion->detalles->first()->cantidad_base);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function casosDeRedondeo(): array
    {
        return [
            'medio exacto sube' => ['0.00005', '0.0001'],
            'justo por debajo baja' => ['0.00004', '0.0000'],
            'medio del quinto decimal sube' => ['0.000151', '0.0002'],
            'sin decimales de sobra' => ['0.1234', '0.1234'],
        ];
    }

    #[DataProvider('casosDeRedondeo')]
    public function test_el_redondeo_es_medio_arriba_a_cuatro_decimales(string $factor, string $esperado): void
    {
        $this->assertSame($esperado, PlantaRecepcionDetalle::convertir('1', '1', $factor));
    }

    public function test_la_suma_decimal_no_arrastra_error_de_coma_flotante(): void
    {
        // 0.1 + 0.2 en float no es 0.3. Aquí cada línea se calcula exacta.
        $this->assertSame('0.1000', PlantaRecepcionDetalle::convertir('1', '0.1', '1'));
        $this->assertSame('0.2000', PlantaRecepcionDetalle::convertir('1', '0.2', '1'));
        $this->assertSame('0.3000', PlantaRecepcionDetalle::convertir('3', '0.1', '1'));
    }

    // --- El formulario no decide ---

    public function test_la_cantidad_base_del_formulario_se_ignora(): void
    {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($insumo, [
                'cantidad_recibida' => '5',
                'contenido_por_unidad' => '100',
                'factor_conversion' => '1',
                // Una petición construida a mano intentando meter 50.000 libras.
                'cantidad_base' => '50000',
                'unidad_base' => 'unidad',
            ]),
        ]), $this->admin());

        $detalle = $recepcion->detalles->first();

        $this->assertSame('500.0000', $detalle->cantidad_base);
        $this->assertSame(UnidadBase::Libra, $detalle->unidad_base);
    }

    public function test_la_cantidad_base_se_recalcula_tambien_al_confirmar(): void
    {
        $recepcion = $this->borrador();
        $detalle = $recepcion->detalles->first();

        // Se corrompe la columna saltándose el servicio, como haría un UPDATE crudo.
        DB::table('planta_recepcion_detalles')
            ->where('id', $detalle->id)->update(['cantidad_base' => '99999.0000']);

        $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        // Al confirmar se vuelve a calcular: el saldo refleja la fórmula, no la
        // columna. Que se recalcule DOS veces es lo que hace inútil manipularla.
        $this->assertSame('500.0000', $recepcion->refresh()->detalles->first()->cantidad_base);
        $this->assertSame('500.0000', $this->saldo($this->bucketDe($recepcion)));
    }

    public function test_la_unidad_base_viene_del_insumo(): void
    {
        $ubicacion = $this->bodega();
        $porLibra = $this->insumoConLotes(['unidad_base' => UnidadBase::Libra->value]);
        $porUnidad = $this->insumoSinLotes();

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($porLibra),
            $this->linea($porUnidad, ['cantidad_recibida' => '20', 'contenido_por_unidad' => '100']),
        ]), $this->admin());

        $detalles = $recepcion->detalles()->orderBy('id')->get();

        $this->assertSame(UnidadBase::Libra, $detalles[0]->unidad_base);
        $this->assertSame(UnidadBase::Unidad, $detalles[1]->unidad_base);
    }

    // --- Valores inadmisibles ---

    public function test_rechaza_cantidad_recibida_no_positiva(): void
    {
        $recepcion = $this->borrador();
        $detalle = $recepcion->detalles->first();

        DB::table('planta_recepcion_detalles')
            ->where('id', $detalle->id)->update(['cantidad_recibida' => '0']);

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->confirmar($recepcion, $this->admin());
    }

    public function test_rechaza_factor_no_positivo(): void
    {
        $recepcion = $this->borrador();
        $detalle = $recepcion->detalles->first();

        DB::table('planta_recepcion_detalles')
            ->where('id', $detalle->id)->update(['factor_conversion' => '0']);

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->confirmar($recepcion, $this->admin());
    }

    public function test_un_insumo_indivisible_rechaza_una_cantidad_base_fraccionaria(): void
    {
        $ubicacion = $this->bodega();
        // Bolsas: se cuentan enteras. 3 paquetes × 10.5 bolsas no existe.
        $bolsa = $this->insumoSinLotes();

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($bolsa, [
                'cantidad_recibida' => '3',
                'unidad_recibida' => 'paquete',
                'contenido_por_unidad' => '10.5',
                'factor_conversion' => '1',
            ]),
        ]), $this->admin());

        $this->assertSame('31.5000', $recepcion->detalles->first()->cantidad_base);

        // La regla la aplica el MOTOR de inventario al escribir el movimiento: no
        // se duplica en el servicio del documento.
        $this->expectException(MovimientoInvalidoException::class);

        $this->servicioRecepcion()->confirmar($recepcion, $this->admin());
    }

    public function test_un_insumo_divisible_si_admite_fraccion(): void
    {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes(['permite_fraccion' => true]);

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($insumo, ['cantidad_recibida' => '1', 'contenido_por_unidad' => '50', 'factor_conversion' => '2.20462262']),
        ]), $this->admin());

        $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        $this->assertSame('110.2311', $this->saldo($this->bucketDe($recepcion)));
    }
}
