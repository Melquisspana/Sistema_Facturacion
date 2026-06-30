<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\MotivoAnulacion;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\AnulacionException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteAnulacionService;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DteAnulacionTest extends TestCase
{
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(CatalogosMhSeeder::class);
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        foreach (['03', '05'] as $t) {
            Correlativo::create(['tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        }

        return compact('estab', 'pv');
    }

    /** CCF generado con una línea gravada 10 × 10. */
    private function ccfGenerado(array $emisor): Dte
    {
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    public function test_admin_puede_anular_ccf_generado(): void
    {
        $ccf = $this->ccfGenerado($this->emisor());

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.anular', $ccf), ['motivo_anulacion' => 'error_monto', 'observacion_anulacion' => 'Monto equivocado'])
            ->assertRedirect(route('facturacion.show', $ccf));

        $ccf->refresh();
        $this->assertSame(EstadoDte::Invalidado, $ccf->estado);
        $this->assertSame(MotivoAnulacion::ErrorMonto, $ccf->motivo_anulacion);
        $this->assertSame('Monto equivocado', $ccf->observacion_anulacion);
        $this->assertNotNull($ccf->fecha_anulacion);
        $this->assertNotNull($ccf->invalidado_by);
    }

    public function test_facturacion_puede_anular_nc_generada(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->aceptarCcf($this->ccfGenerado($emisor)); // la NC exige CCF aceptado
        $nc = $this->borradores->crearNotaCredito($ccf);
        $this->borradores->acreditarLinea($nc, $ccf->lineas()->first(), cantidad: 4);
        app(DteGeneracionService::class)->generar($nc);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.anular', $nc), ['motivo_anulacion' => 'nota_credito_incorrecta'])
            ->assertRedirect();

        $this->assertSame(EstadoDte::Invalidado, $nc->refresh()->estado);
    }

    public function test_consulta_no_puede_anular(): void
    {
        $ccf = $this->ccfGenerado($this->emisor());

        $this->actingAs($this->usuario('consulta'))
            ->post(route('facturacion.anular', $ccf), ['motivo_anulacion' => 'otro'])
            ->assertForbidden();

        $this->assertSame(EstadoDte::Generado, $ccf->refresh()->estado);
    }

    public function test_no_se_puede_anular_borrador(): void
    {
        $emisor = $this->emisor();
        $borrador = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);

        // La policy bloquea (no está generado) → 403.
        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.anular', $borrador), ['motivo_anulacion' => 'otro'])
            ->assertForbidden();
    }

    public function test_no_se_puede_anular_dos_veces(): void
    {
        $ccf = $this->ccfGenerado($this->emisor());
        app(DteAnulacionService::class)->anular($ccf, MotivoAnulacion::Otro);

        $this->expectException(AnulacionException::class);
        app(DteAnulacionService::class)->anular($ccf->refresh(), MotivoAnulacion::Otro);
    }

    public function test_anulacion_registra_historial(): void
    {
        $ccf = $this->ccfGenerado($this->emisor());
        app(DteAnulacionService::class)->anular($ccf, MotivoAnulacion::ErrorProductos, null, $this->usuario('administrador'));

        $this->assertDatabaseHas('dte_estado_historial', [
            'dte_id' => $ccf->id,
            'estado_anterior' => 'generado',
            'estado_nuevo' => 'invalidado',
        ]);
    }

    public function test_nc_anulada_libera_saldo_acreditable(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->aceptarCcf($this->ccfGenerado($emisor)); // la NC exige CCF aceptado
        $lineaOriginal = $ccf->lineas()->first();

        // NC #1 acredita 4 de 10 y se genera, luego se anula.
        $nc1 = $this->borradores->crearNotaCredito($ccf);
        $this->borradores->acreditarLinea($nc1, $lineaOriginal, cantidad: 4);
        app(DteGeneracionService::class)->generar($nc1);
        app(DteAnulacionService::class)->anular($nc1->refresh(), MotivoAnulacion::NotaCreditoIncorrecta);

        // NC #2 ahora puede acreditar las 10 completas (las 4 anuladas volvieron).
        $nc2 = $this->borradores->crearNotaCredito($ccf);
        $linea = $this->borradores->acreditarLinea($nc2, $lineaOriginal, cantidad: 10);

        $this->assertSame('100.00', $nc2->refresh()->total_gravado);
        $this->assertSame('100.00', $linea->venta_gravada);
    }

    public function test_impresion_muestra_marca_de_anulado(): void
    {
        $ccf = $this->ccfGenerado($this->emisor());
        app(DteAnulacionService::class)->anular($ccf, MotivoAnulacion::DocumentoDuplicado);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.imprimir', $ccf->refresh()))
            ->assertOk()
            ->assertSee('DOCUMENTO ANULADO / INVALIDADO INTERNAMENTE')
            ->assertSee('Documento duplicado');
    }
}
