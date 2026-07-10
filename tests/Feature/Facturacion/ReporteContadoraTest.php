<?php

namespace Tests\Feature\Facturacion;

use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\User;
use App\Services\Reportes\ReporteContadoraExcel;
use App\Services\Reportes\ReporteContadoraQuery;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Reporte contadora: pantalla + Excel de SOLO LECTURA. Por defecto excluye
 * pruebas/mock (ambiente 01 + aceptados reales). No emite, no transmite, no toca
 * correlativos ni envía correos.
 */
class ReporteContadoraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        \App\Support\Sala::olvidarCache();
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

    public function test_el_reporte_carga_para_gestores_y_contador(): void
    {
        foreach (['administrador', 'contador', 'facturacion'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('facturacion.reporte-contadora'))
                ->assertOk()
                ->assertSee('Reporte contadora')
                ->assertSee('No incluye documentos hechos en Conta Portable');
        }
    }

    public function test_consulta_no_accede(): void
    {
        $this->actingAs($this->usuario('consulta'))
            ->get(route('facturacion.reporte-contadora'))
            ->assertForbidden();
    }

    public function test_por_defecto_excluye_ambiente_00_y_mock(): void
    {
        $real = $this->crearDte('01', '2026SELLOREAL0001', true);           // producción, aceptado real
        $pruebas = $this->crearDte('00', '2026SELLOPRUEBA1', true);          // ambiente 00 (excluir)
        $mock = $this->crearDte('01', 'MOCK-SIMULADO-ABCD', true);           // sello mock (excluir)

        $html = $this->actingAs($this->usuario('contador'))
            ->get(route('facturacion.reporte-contadora'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($real->numero_control, $html);
        $this->assertStringNotContainsString($pruebas->numero_control, $html);
        $this->assertStringNotContainsString($mock->numero_control, $html);
    }

    public function test_el_excel_se_genera_con_las_columnas_esperadas(): void
    {
        $cliente = Cliente::factory()->create(['num_documento' => '06140506901121', 'nrc' => '123456']);
        $real = $this->crearDte('01', '2026SELLOREAL0002', true, '03', $cliente);
        $mock = $this->crearDte('01', 'MOCK-SIMULADO-ZZZZ', true);

        $filtros = ReporteContadoraQuery::filtros([]); // defaults: prod 01 + aceptado real
        $dtes = ReporteContadoraQuery::query($filtros)->get();
        $ruta = (new ReporteContadoraExcel())->generar($dtes);

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

    public function test_exportar_descarga_un_xlsx(): void
    {
        $this->crearDte('01', '2026SELLOREAL0003', true);

        $this->actingAs($this->usuario('contador'))
            ->get(route('facturacion.reporte-contadora.exportar'))
            ->assertOk()
            ->assertDownload();
    }

    public function test_ver_y_exportar_no_emite_ni_toca_correlativos_ni_envia_correo(): void
    {
        Mail::fake();
        $this->crearDte('01', '2026SELLOREAL0004', true);
        $dtesAntes = Dte::count();
        $correlAntes = Correlativo::orderBy('id')->get(['id', 'ultimo_numero'])->toArray();

        $admin = $this->usuario('administrador');
        $this->actingAs($admin)->get(route('facturacion.reporte-contadora'))->assertOk();
        $this->actingAs($admin)->get(route('facturacion.reporte-contadora.exportar'))->assertOk();

        $this->assertSame($dtesAntes, Dte::count());                 // no se creó/emitió nada
        $this->assertEquals($correlAntes, Correlativo::orderBy('id')->get(['id', 'ultimo_numero'])->toArray());
        Mail::assertNothingSent();                                    // el reporte no manda correos
    }
}
