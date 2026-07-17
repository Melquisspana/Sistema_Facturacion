<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\DteEstadoHistorial;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Exportaciones\CrearFexDesdeExportacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Reproduce EXACTAMENTE el 500 real al generar la FEX #131 (creada desde la
 * Lista de Empaque #7): numero_interno se arma sin el ambiente
 * ("INT-{tipo}-{serie}-{correlativo}"), y como los correlativos de ambiente 00
 * (pruebas) y 01 (producción) cuentan CADA UNO desde 0 de forma independiente,
 * el primer documento real de un tipo puede terminar generando el MISMO
 * numero_interno que un documento de prueba ya existente — y la columna es
 * ÚNICA A NIVEL GLOBAL (no por ambiente), lo que dispara
 * UniqueConstraintViolationException.
 */
class DteGeneracionNumeroInternoAmbienteTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        return $this->crearEmisorDte();
    }

    /** Lista de Empaque equivalente a la #7 real: 4 líneas, 16 cajas, $2,160.00. */
    private function listaEquivalenteALista7(): Exportacion
    {
        $clienteDte = Cliente::factory()->exportacion()->create();
        $clienteExpo = ExportacionCliente::create(['nombre' => 'CAROLINAS WHOLESALE LLC', 'cliente_id' => $clienteDte->id, 'activo' => true]);
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $clienteExpo->id, 'cliente_nombre' => $clienteExpo->nombre,
            'exportador_nombre' => 'Dulces La Negrita', 'fecha' => '2026-07-17', 'estado' => 'aprobada',
        ]);
        $pesoDefault = ['gramos_por_unidad' => 10, 'onzas_por_unidad' => 0.35, 'peso_neto_caja_kg' => 1, 'peso_bruto_caja_kg' => 1.1, 'peso_neto_caja_lb' => 2.2, 'peso_bruto_caja_lb' => 2.4];
        $lista->items()->create(['nombre_es' => 'Caja de alfeñique', 'nombre_en' => 'Sugar cane candie', 'unidad' => 'Bolsa', 'unidades_por_caja' => 144, 'cantidad_cajas' => 3, 'precio_caja' => 136.80] + $pesoDefault);
        $lista->items()->create(['nombre_es' => 'Caja de bandejas de alfeñique grande', 'nombre_en' => 'Large alfeñique candy tray', 'unidad' => 'Bolsa', 'unidades_por_caja' => 36, 'cantidad_cajas' => 6, 'precio_caja' => 54.00] + $pesoDefault);
        $lista->items()->create(['nombre_es' => 'Caja de Clorets', 'nombre_en' => 'Clorets gum', 'unidad' => 'Bolsa', 'unidades_por_caja' => 288, 'cantidad_cajas' => 3, 'precio_caja' => 244.80] + $pesoDefault);
        $lista->items()->create(['nombre_es' => 'Caja de Nougat de fresa', 'nombre_en' => 'Strawberry nugget', 'unidad' => 'Bolsa', 'unidades_por_caja' => 216, 'cantidad_cajas' => 4, 'precio_caja' => 172.80] + $pesoDefault);

        return $lista;
    }

    /**
     * Reproduce el escenario real: un documento de PRUEBA (ambiente 00) ya
     * generado en la posición 1 de su correlativo, y una FEX de PRODUCCIÓN
     * (ambiente 01) recién creada desde una Lista de Empaque con líneas libres
     * (mismos datos de la Lista #7: 4 líneas, 16 cajas, $2,160.00), también en
     * la posición 1 de SU PROPIO correlativo (independiente del de pruebas).
     */
    public function test_generar_fex_produccion_no_colisiona_con_documento_de_prueba_en_la_misma_posicion(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();

        // --- Documento de PRUEBA (ambiente 00) ya generado en la posición 1 ---
        Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        $clientePrueba = Cliente::factory()->exportacion()->create();
        $dtePrueba = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => \App\Enums\TipoDte::FacturaExportacion,
            'ambiente' => '00',
            'cliente_id' => $clientePrueba->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'tipo_item_expor' => 1, 'recinto_fiscal' => '01', 'tipo_regimen' => 'EX-1', 'regimen' => '1000.000', 'cod_incoterms' => '09',
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($dtePrueba, $producto, cantidad: 1);
        app(DteGeneracionService::class)->generar($dtePrueba);
        $dtePrueba->refresh();
        $this->assertSame(EstadoDte::Generado, $dtePrueba->estado);
        $this->assertStringEndsWith('000000000000001', $dtePrueba->numero_interno);

        // --- FEX de PRODUCCIÓN (ambiente 01) creada desde una Lista de Empaque, ---
        // --- también en la posición 1 de SU correlativo (independiente) ---
        Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '01', 'ultimo_numero' => 0, 'activo' => true]);
        $lista = $this->listaEquivalenteALista7();

        // CrearFexDesdeExportacionService no pasa 'ambiente' explícito: hereda
        // config('dte.ambiente'), que en producción real es '01' (en phpunit.xml
        // es '00' por defecto). Se simula el valor real de producción aquí.
        config(['dte.ambiente' => '01']);
        $dteReal = app(CrearFexDesdeExportacionService::class)->crear($lista);
        config(['dte.ambiente' => '00']);

        $this->assertSame('2160.00', $dteReal->total_exportacion);
        $this->assertSame('2160.00', $dteReal->total_pagar);
        $this->assertSame(16, (int) $dteReal->lineas->sum('cantidad'));

        // Antes del fix: esta línea lanza UniqueConstraintViolationException
        // (mismo error real del log: numero_interno duplicado). Después del fix,
        // debe generar sin problema y con un numero_interno DISTINTO al de prueba.
        app(DteGeneracionService::class)->generar($dteReal);

        $dteReal->refresh();
        $this->assertSame(EstadoDte::Generado, $dteReal->estado);
        $this->assertNotSame($dtePrueba->numero_interno, $dteReal->numero_interno);
        $this->assertStringContainsString('-01-', $dteReal->numero_interno);
        $this->assertStringContainsString('-00-', $dtePrueba->numero_interno);
    }

    /**
     * Genera el JSON oficial completo desde la misma Lista equivalente a la #7
     * y confirma que el esquema FEX v3 valida: distrito presente, actividad y
     * país del receptor correctos, tributo C3 correcto, sin IVA.
     */
    public function test_generacion_completa_produce_json_fex_v3_valido_con_distrito_actividad_pais_y_c3(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        $lista = $this->listaEquivalenteALista7();
        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);

        app(DteGeneracionService::class)->generar($dte);
        $dte->refresh();

        $oficial = json_decode(Storage::disk('local')->get($dte->json_generado_path), true);

        $this->assertSame('C3', $oficial['resumen']['tributos'][0]['codigo']);
        $this->assertEquals(0.0, $oficial['resumen']['tributos'][0]['valor']);
        $this->assertEquals(2160.0, $oficial['resumen']['totalGravada']);
        $this->assertEquals(2160.0, $oficial['resumen']['totalPagar']);
        $this->assertArrayHasKey('distrito', $oficial['emisor']['direccion']);
        $this->assertNotEmpty($oficial['receptor']['codPais']);
        $this->assertNotSame('SV', $oficial['receptor']['codPais'], 'El receptor de una FEX debe ser extranjero.');
        $this->assertNotEmpty($oficial['receptor']['descActividad']);
        foreach ($oficial['cuerpoDocumento'] as $linea) {
            $this->assertSame(['C3'], $linea['tributos']);
        }

        $this->assertNull($dte->sello_recepcion);
        $this->assertNull($dte->json_firmado_path);
    }

    /**
     * Si algo falla A MITAD de generar() (después de haber consumido el
     * correlativo y asignado numero_interno), la transacción debe revertir
     * TODO: el correlativo vuelve a su valor anterior y el documento sigue en
     * borrador sin numero_interno. Ninguna generación deja estado parcial.
     */
    public function test_generacion_fallida_a_mitad_de_camino_revierte_todo_y_no_consume_correlativo(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $correlativo = Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        $lista = $this->listaEquivalenteALista7();
        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);

        // Simula un fallo real DESPUÉS de que generar() ya incrementó el
        // correlativo y asignó numero_interno (mismo punto donde ocurrió el 500
        // real), mediante el evento 'creating' del historial de estados.
        DteEstadoHistorial::creating(function () {
            throw new \RuntimeException('Fallo simulado a mitad de la generación.');
        });

        try {
            app(DteGeneracionService::class)->generar($dte);
            $this->fail('Debió propagar la excepción simulada.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo simulado a mitad de la generación.', $e->getMessage());
        }

        $dte->refresh();
        $correlativo->refresh();

        $this->assertSame(EstadoDte::Borrador, $dte->estado);
        $this->assertNull($dte->numero_interno);
        $this->assertNull($dte->numero_control);
        $this->assertNull($dte->correlativo_id);
        $this->assertSame(0, $correlativo->ultimo_numero, 'El correlativo no debe quedar consumido si la generación falla.');
    }
}
