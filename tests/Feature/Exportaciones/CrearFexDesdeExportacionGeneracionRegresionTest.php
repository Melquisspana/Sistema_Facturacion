<?php

namespace Tests\Feature\Exportaciones;

use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Services\Dte\DteGeneracionService;
use App\Services\Exportaciones\CrearFexDesdeExportacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Regresión: una FEX creada por el NUEVO camino (Lista de Empaque ->
 * CrearFexDesdeExportacionService) debe generar el mismo JSON oficial válido
 * que una FEX creada por el formulario manual — mismo serializador, misma
 * calculadora, sin cambios en SerializadorExportacionMh. Confirma distrito del
 * emisor y tributo C3 (0.00, sin sumarse al total).
 */
class CrearFexDesdeExportacionGeneracionRegresionTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();
    }

    public function test_generacion_completa_desde_lista_mantiene_distrito_y_tributo_c3(): void
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        $clienteDte = Cliente::factory()->exportacion()->create();
        $clienteExpo = ExportacionCliente::create(['nombre' => 'CAROLINAS WHOLESALE LLC', 'cliente_id' => $clienteDte->id, 'activo' => true]);
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $clienteExpo->id,
            'cliente_nombre' => $clienteExpo->nombre,
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-07-17',
            'estado' => 'aprobada',
        ]);
        $lista->items()->create([
            'nombre_es' => 'Canillitas 85 g', 'nombre_en' => 'Little canes 85 g',
            'unidad' => 'Bolsa', 'unidades_por_caja' => 144, 'cantidad_cajas' => 10, 'precio_caja' => 18.00,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3.00,
            'peso_neto_caja_kg' => 12, 'peso_bruto_caja_kg' => 13, 'peso_neto_caja_lb' => 26, 'peso_bruto_caja_lb' => 28,
        ]);

        $dte = app(CrearFexDesdeExportacionService::class)->crear($lista);

        app(DteGeneracionService::class)->generar($dte);
        $dte->refresh();

        $oficial = json_decode(Storage::disk('local')->get($dte->json_generado_path), true);

        // Tributo C3: presente en el resumen con valor 0.00, y en cada línea.
        $this->assertSame('C3', $oficial['resumen']['tributos'][0]['codigo']);
        $this->assertEquals(0.0, $oficial['resumen']['tributos'][0]['valor']);
        $this->assertSame(['C3'], $oficial['cuerpoDocumento'][0]['tributos']);
        // El IVA NO se suma: el total a pagar es solo cajas × precio (sin recargo).
        $this->assertEquals(180.0, $oficial['resumen']['totalGravada']);
        $this->assertEquals(180.0, $oficial['resumen']['totalPagar']);

        // Distrito del emisor: la clave sigue presente en la dirección (sin regresión
        // de estructura), igual que antes de este cambio.
        $this->assertArrayHasKey('distrito', $oficial['emisor']['direccion']);

        $this->assertNotNull($dte->json_generado_path);
        $this->assertNull($dte->sello_recepcion);
        $this->assertNull($dte->json_firmado_path);
    }
}
