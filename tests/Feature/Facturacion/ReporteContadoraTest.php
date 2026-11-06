<?php

namespace Tests\Feature\Facturacion;

use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\User;
use App\Services\Reportes\ReporteContadoraExcel;
use App\Services\Reportes\ReporteContadoraQuery;
use App\Support\Sala;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Reporte contadora: consulta + Excel de SOLO LECTURA que reutiliza el paquete
 * mensual. Por defecto excluye pruebas/mock (ambiente 01 + aceptados reales). La
 * pantalla «Ventas» que los mostraba se retiró el 06/11/2026.
 */
class ReporteContadoraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sala::olvidarCache();
        $this->seed(DatosInicialesNegritaSeeder::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function crearDte(string $ambiente, ?string $sello, bool $conFechaMh, string $tipo = '03', ?Cliente $cliente = null): Dte
    {
        return Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'cliente_id' => $cliente?->id,
            'tipo_dte' => $tipo,
            'estado' => 'aceptado',
            'ambiente' => $ambiente,
            'numero_control' => 'DTE-'.$tipo.'-M001P001-'.str_pad((string) random_int(1, 999999999), 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => (string) Str::uuid(),
            'sello_recepcion' => $sello,
            'fecha_procesamiento_mh' => $conFechaMh ? now() : null,
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_gravado' => 100.00,
            'iva' => 13.00,
            'iva_retenido' => 1.00,
            'total_pagar' => 112.00,
        ]);
    }

    /**
     * La pantalla «Ventas» se retiró (06/11/2026): no la usaba nadie. Ni la ruta ni el
     * enlace del menú existen; las ventas siguen yendo en el paquete mensual.
     */
    public function test_la_pantalla_ventas_ya_no_existe(): void
    {
        foreach (['facturacion.reporte-contadora', 'facturacion.reporte-contadora.exportar',
            'facturacion.reporte-contadora.json', 'facturacion.reporte-contadora.enviar'] as $ruta) {
            $this->assertFalse(Route::has($ruta), "La ruta {$ruta} debería haberse quitado.");
        }

        $usuario = $this->usuario('contabilidad');
        $this->actingAs($usuario)->get('/facturacion/reporte-contadora')->assertNotFound();
        $this->actingAs($usuario)->get(route('dashboard'))->assertOk()
            ->assertDontSee('/facturacion/reporte-contadora', false)
            ->assertSee(route('contabilidad.paquete'), false);
    }

    /** El recorte que el paquete mensual reutiliza: por defecto, solo producción real. */
    public function test_por_defecto_excluye_ambiente_00_y_mock(): void
    {
        $real = $this->crearDte('01', '2026SELLOREAL0001', true);           // producción, aceptado real
        $this->crearDte('00', '2026SELLOPRUEBA1', true);                    // ambiente 00 (excluir)
        $this->crearDte('01', 'MOCK-SIMULADO-ABCD', true);                  // sello mock (excluir)

        $ids = ReporteContadoraQuery::query(ReporteContadoraQuery::filtros([]))->pluck('id')->all();

        $this->assertSame([$real->id], $ids);
    }

    public function test_el_excel_se_genera_con_las_columnas_esperadas(): void
    {
        $cliente = Cliente::factory()->create(['num_documento' => '06140506901121', 'nrc' => '123456']);
        $real = $this->crearDte('01', '2026SELLOREAL0002', true, '03', $cliente);
        $mock = $this->crearDte('01', 'MOCK-SIMULADO-ZZZZ', true);

        $filtros = ReporteContadoraQuery::filtros([]); // defaults: prod 01 + aceptado real
        $dtes = ReporteContadoraQuery::query($filtros)->get();
        $ruta = (new ReporteContadoraExcel)->generar($dtes);

        $hoja = IOFactory::load($ruta)->getActiveSheet();
        // Encabezados en la fila 1, en el orden pedido.
        foreach (ReporteContadoraExcel::COLUMNAS as $i => $titulo) {
            $this->assertSame($titulo, $hoja->getCell([$i + 1, 1])->getValue());
        }
        // Solo la fila real (mock excluido): fila 2 con su número y NIT del cliente.
        $this->assertSame($real->numero_control, $hoja->getCell([6, 2])->getValue());
        $this->assertSame('06140506901121', $hoja->getCell([4, 2])->getValue());
        $this->assertNull($hoja->getCell([6, 3])->getValue()); // no hay una tercera fila (mock fuera)

        @unlink($ruta);
    }
}
