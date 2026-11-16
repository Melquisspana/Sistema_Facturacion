<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteSchemaValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PreparaEmisorDte;
use Tests\Concerns\RepresentacionPdfDte;
use Tests\TestCase;

/**
 * Factura (01) a un consumidor final con nombre y correo y sin Cliente (ADR 0011): el
 * receptor lleva solo esos dos datos. La 01 sin nombre y los documentos con cliente
 * quedan como estaban. Datos inventados; nada sale a la red.
 */
class ReceptorConsumidorFinalConNombreTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;
    use RepresentacionPdfDte;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedCatalogosDte();
        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        Correlativo::create([
            'tipo_dte' => '01', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
            'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
        ]);
    }

    private function factura(array $receptor = [], ?Cliente $cliente = null): Dte
    {
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::Factura, 'cliente_id' => $cliente?->id,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ]);
        if ($receptor !== []) {
            $dte->update($receptor);
        }
        $producto = Producto::factory()->create(['nombre' => 'Dulce inventado', 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, 3, 0, '1.15');

        return $dte->refresh();
    }

    /** Genera de verdad (la generación ya valida contra el esquema) y lee el JSON guardado. */
    private function jsonOficial(Dte $dte): array
    {
        app(DteGeneracionService::class)->generar($dte);

        return json_decode(Storage::disk('local')->get($dte->refresh()->json_generado_path), true);
    }

    private function assertValidoContraEsquema(array $json): void
    {
        $res = app(DteSchemaValidator::class)->validar($json, TipoDte::Factura);
        $this->assertTrue($res['valido'], 'Errores: '.implode(' | ', $res['errores']));
    }

    public function test_sin_cliente_y_con_nombre_lleva_solo_nombre_y_correo_y_pasa_el_esquema_01(): void
    {
        $json = $this->jsonOficial($this->factura(['receptor_nombre' => 'Ana Inventada', 'receptor_correo' => 'ana@example.test']));

        $this->assertSame([
            'tipoDocumento' => null, 'numDocumento' => null, 'nrc' => null, 'nombre' => 'Ana Inventada',
            'codActividad' => null, 'descActividad' => null, 'direccion' => null, 'telefono' => null,
            'correo' => 'ana@example.test',
        ], $json['receptor']);
        $this->assertValidoContraEsquema($json);
    }

    public function test_la_01_sin_nombre_sigue_con_receptor_null(): void
    {
        $json = $this->jsonOficial($this->factura());

        $this->assertNull($json['receptor']);
        $this->assertValidoContraEsquema($json);
    }

    public function test_con_cliente_el_receptor_sigue_saliendo_del_cliente_con_su_direccion(): void
    {
        $cliente = Cliente::factory()->contribuyente()->create([
            'nombre' => 'Empresa Inventada S.A.', 'correo' => 'compras@example.test', 'direccion' => 'Calle inventada 1',
        ]);
        // Aunque el documento tuviera nombre propio, manda el cliente.
        $json = $this->jsonOficial($this->factura(['receptor_nombre' => 'Otro Nombre', 'receptor_correo' => 'otro@example.test'], $cliente));

        $this->assertSame('Empresa Inventada S.A.', $json['receptor']['nombre']);
        $this->assertSame('compras@example.test', $json['receptor']['correo']);
        $this->assertSame('36', $json['receptor']['tipoDocumento']);
        $this->assertSame('06140101011011', $json['receptor']['numDocumento']);
        $this->assertSame('Calle inventada 1', $json['receptor']['direccion']['complemento']);
        $this->assertNotSame('', $json['receptor']['direccion']['departamento']);
        $this->assertValidoContraEsquema($json);
    }

    public function test_la_representacion_grafica_muestra_nombre_y_correo_en_receptor(): void
    {
        $factura = $this->factura(['receptor_nombre' => 'Ana Inventada', 'receptor_correo' => 'ana@example.test']);

        // En borrador sale del documento; ya generada, del JSON emitido.
        foreach (['borrador', 'generada'] as $caso) {
            if ($caso === 'generada') {
                app(DteGeneracionService::class)->generar($factura);
                $factura->refresh();
            }
            $html = $this->htmlDelPdf($factura);
            $this->assertStringContainsString('Ana Inventada', $html, $caso);
            $this->assertStringContainsString('ana@example.test', $html, $caso);
            $this->assertStringNotContainsString('Consumidor final sin identificar', $html, $caso);
        }

        // La 01 sin nombre sigue diciendo que el consumidor no está identificado.
        $this->assertStringContainsString('Consumidor final sin identificar.', $this->htmlDelPdf($this->factura()));
    }
}
