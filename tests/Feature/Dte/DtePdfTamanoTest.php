<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Correlativo;
use App\Models\Producto;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DtePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Peso del PDF de un DTE. Un CCF de una línea pesaba ~1,75 MB: Dompdf incrustaba las
 * fuentes DejaVu completas y el logo iba a 420×630 px para imprimirse a 64×96. El
 * paquete mensual de contabilidad (un PDF por venta) llegó a 240 MB y el envío agotó
 * la memoria.
 */
class DtePdfTamanoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    public function test_un_pdf_de_una_linea_con_logo_pesa_menos_de_150_kb(): void
    {
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
        ]);

        $cliente = Cliente::factory()->contribuyente()->create();
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => ClienteSucursal::factory()->create(['cliente_id' => $cliente->id])->id,
        ]);
        $producto = Producto::factory()->create([
            'nombre' => 'PIÑATA DE CARAMELOS SURTIDOS', 'precio_unitario' => 4.87,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 3);

        $this->assertFileExists((string) config('dte.pdf.logo_path'));
        $bytes = app(DtePdfService::class)->bytes($dte->refresh());

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertLessThan(150 * 1024, strlen($bytes), 'El PDF pesa '.round(strlen($bytes) / 1024).' KB.');
    }

    public function test_el_logo_del_pdf_es_la_version_liviana(): void
    {
        $this->assertSame(public_path('images/dte/logo-pdf.png'), config('dte.pdf.logo_path'));
        $this->assertLessThan(50 * 1024, filesize(public_path('images/dte/logo-pdf.png')));
        // El original queda: lo usan otras pantallas (planilla).
        $this->assertFileExists(public_path('images/dte/logo-transparent.png'));
    }
}
