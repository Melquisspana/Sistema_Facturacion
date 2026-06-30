<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteEstadoHistorial;
use App\Models\DteLinea;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DteRelacionesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosMhSeeder::class);
    }

    private function emisor(): array
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        $correlativo = Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
        ]);

        return compact('estab', 'pv', 'correlativo');
    }

    public function test_ccf_borrador_con_lineas_historial_y_cliente(): void
    {
        ['estab' => $estab, 'pv' => $pv, 'correlativo' => $correlativo] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = Producto::factory()->create(['nombre' => 'Dulce de leche', 'codigo' => 'DUL-1']);
        $user = User::factory()->create();

        $ccf = Dte::create([
            'tipo_dte' => '03',
            'estado' => 'borrador',
            'ambiente' => '00',
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'correlativo_id' => $correlativo->id,
            'cliente_id' => $cliente->id,
            'condicion_operacion' => 1,
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => now()->toTimeString(),
            'created_by' => $user->id,
        ]);

        // Línea con snapshot del producto.
        $linea = DteLinea::create([
            'dte_id' => $ccf->id,
            'numero_linea' => 1,
            'producto_id' => $producto->id,
            'codigo' => $producto->codigo,
            'descripcion' => $producto->nombre,
            'unidad_medida_id' => $producto->unidad_medida_id,
            'tipo_producto' => $producto->tipo_producto->value,
            'tipo_impuesto' => $producto->tipo_impuesto->value,
            'cantidad' => 3,
            'precio_unitario' => 1.5,
        ]);

        // Bitácora de estado.
        DteEstadoHistorial::create([
            'dte_id' => $ccf->id,
            'estado_anterior' => null,
            'estado_nuevo' => 'borrador',
            'user_id' => $user->id,
            'comentario' => 'Creación del borrador',
        ]);

        $ccf->refresh();

        // Casts a enum.
        $this->assertSame(TipoDte::CreditoFiscal, $ccf->tipo_dte);
        $this->assertSame(EstadoDte::Borrador, $ccf->estado);
        $this->assertTrue($ccf->esEditable());

        // Relaciones de cabecera.
        $this->assertSame($cliente->id, $ccf->cliente->id);
        $this->assertSame($estab->id, $ccf->establecimiento->id);
        $this->assertSame($correlativo->id, $ccf->correlativo->id);
        $this->assertSame($user->id, $ccf->creadoPor->id);
        $this->assertCount(1, $ccf->lineas);
        $this->assertCount(1, $ccf->historial);

        // Snapshot y relación blanda de la línea.
        $this->assertSame('Dulce de leche', $ccf->lineas->first()->descripcion);
        $this->assertSame($producto->id, $ccf->lineas->first()->producto->id);

        // Próximo número informativo (sin consumir).
        $this->assertSame(1, $ccf->correlativo->siguiente_numero);
        $this->assertSame(0, $ccf->correlativo->ultimo_numero);
    }

    public function test_nota_credito_se_relaciona_al_documento_original(): void
    {
        ['estab' => $estab] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();

        $ccf = Dte::create([
            'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '00',
            'establecimiento_id' => $estab->id, 'cliente_id' => $cliente->id,
            'condicion_operacion' => 1, 'fecha_emision' => now()->toDateString(), 'hora_emision' => now()->toTimeString(),
        ]);

        $nc = Dte::create([
            'tipo_dte' => '05', 'estado' => 'borrador', 'ambiente' => '00',
            'establecimiento_id' => $estab->id, 'cliente_id' => $cliente->id,
            'dte_relacionado_id' => $ccf->id, 'motivo' => 'Devolución parcial',
            'condicion_operacion' => 1, 'fecha_emision' => now()->toDateString(), 'hora_emision' => now()->toTimeString(),
        ]);

        $this->assertSame($ccf->id, $nc->dteRelacionado->id);
        $this->assertTrue($ccf->notas->contains($nc));
        $this->assertSame(TipoDte::NotaCredito, $nc->tipo_dte);
        $this->assertFalse($ccf->esEditable(), 'El CCF aceptado no es editable.');
    }
}
