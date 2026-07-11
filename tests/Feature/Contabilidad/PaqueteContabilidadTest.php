<?php

namespace Tests\Feature\Contabilidad;

use App\Models\DocumentoRecibido;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\User;
use App\Services\Contabilidad\PaqueteContabilidadZip;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use ZipArchive;

/**
 * Paquete mensual para contabilidad: herramienta INTERNA (la contadora no entra al
 * sistema). Junta compras (recibidos) + ventas (emitidos) en un ZIP. SOLO lectura:
 * no envía correos, no toca Yahoo, DTE emitidos, correlativos ni estados.
 */
class PaqueteContabilidadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** Compra (documento recibido) en una fecha. */
    private function compra(string $fecha, float $total, array $extra = []): DocumentoRecibido
    {
        static $n = 0;
        $n++;

        return DocumentoRecibido::create($extra + [
            'gmail_message_id' => 'c'.$n,
            'emisor_nombre' => 'PROVEEDOR '.$n,
            'tipo_documento' => '03',
            'numero_control' => 'DTE-03-PROV-'.$n,
            'estado' => 'pendiente',
            'total' => $total,
            'tiene_pdf' => true,
            'tiene_json' => true,
            'fecha_correo' => Carbon::parse($fecha),
        ]);
    }

    /** Venta (DTE emitido, aceptado real por MH, ambiente 01) en una fecha. */
    private function venta(string $fecha, float $total): Dte
    {
        return Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'numero_control' => 'DTE-03-M001P001-'.str_pad((string) random_int(1, 999999999), 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => (string) Str::uuid(),
            'sello_recepcion' => '2026SELLOREAL'.random_int(1000, 9999),
            'fecha_procesamiento_mh' => Carbon::parse($fecha),
            'fecha_emision' => Carbon::parse($fecha),
            'hora_emision' => '10:00:00',
            'total_gravado' => $total,
            'iva' => round($total * 0.13, 2),
            'total_pagar' => $total,
        ]);
    }

    public function test_pantalla_paquete_carga_con_resumen(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->compra('2026-07-05', 100);
        $this->compra('2026-07-06', 50);
        $this->venta('2026-07-10', 200);

        $resumen = $this->actingAs($this->usuario('contador'))
            ->get(route('contabilidad.paquete', ['mes' => 7, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('interno')
            ->assertSee('La contadora no')
            ->viewData('resumen');

        $this->assertSame(2, $resumen['compras_cantidad']);
        $this->assertSame(150.0, $resumen['compras_total']);
        $this->assertSame(1, $resumen['ventas_cantidad']);
        $this->assertSame(200.0, $resumen['ventas_total']);
    }

    public function test_resumen_respeta_el_rango(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->compra('2026-07-05', 100);   // dentro
        $this->compra('2026-06-20', 999);   // fuera (mes anterior)

        $resumen = $this->actingAs($this->usuario('administrador'))
            ->get(route('contabilidad.paquete', ['mes' => 7, 'anio' => 2026]))
            ->assertOk()->viewData('resumen');

        $this->assertSame(1, $resumen['compras_cantidad']);
        $this->assertSame(100.0, $resumen['compras_total']);
    }

    public function test_zip_trae_ambos_excel_y_adjuntos_de_compras(): void
    {
        Storage::fake('local');
        // Adjuntos físicos de la compra.
        Storage::disk('local')->put('documentos-recibidos/c1/factura.pdf', '%PDF fake');
        Storage::disk('local')->put('documentos-recibidos/c1/factura.json', '{"x":1}');
        $compra = $this->compra('2026-07-05', 100, [
            'gmail_message_id' => 'c1',
            'metadata_json' => ['archivos' => ['documentos-recibidos/c1/factura.pdf', 'documentos-recibidos/c1/factura.json']],
        ]);
        $venta = new Collection([]); // sin ventas: igual debe generar

        $r = app(PaqueteContabilidadZip::class)->generar('2026-07', new Collection([$compra]), $venta, true, true);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($r['ruta']) === true);
        $nombres = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombres[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($r['ruta']);

        $this->assertContains('compras/documentos_recibidos_2026-07.xlsx', $nombres);
        $this->assertContains('ventas/reporte_contadora_2026-07.xlsx', $nombres);
        $this->assertContains('compras/pdf/'.$compra->id.'_factura.pdf', $nombres);
        $this->assertContains('compras/json/'.$compra->id.'_factura.json', $nombres);
        $this->assertContains('LEEME.txt', $nombres);
        $this->assertSame(1, $r['compras_pdf']);
        $this->assertSame(1, $r['compras_json']);
    }

    public function test_zip_solo_compras_no_incluye_excel_de_ventas(): void
    {
        Storage::fake('local');
        $compra = $this->compra('2026-07-05', 100);

        $r = app(PaqueteContabilidadZip::class)->generar('2026-07', new Collection([$compra]), new Collection(), true, false);

        $zip = new ZipArchive();
        $zip->open($r['ruta']);
        $nombres = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombres[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($r['ruta']);

        $this->assertContains('compras/documentos_recibidos_2026-07.xlsx', $nombres);
        $this->assertNotContains('ventas/reporte_contadora_2026-07.xlsx', $nombres);
    }

    public function test_generar_descarga_zip_y_no_envia_correo(): void
    {
        Mail::fake();
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->compra('2026-07-05', 100);

        $this->actingAs($this->usuario('contador'))
            ->post(route('contabilidad.paquete.generar'), ['mes' => 7, 'anio' => 2026, 'incluir_compras' => 1, 'incluir_ventas' => 1])
            ->assertOk()
            ->assertDownload('documentos_contabilidad_2026-07.zip');

        Mail::assertNothingSent();
    }

    public function test_no_falla_si_no_hay_documentos_en_el_rango(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);

        $resp = $this->actingAs($this->usuario('administrador'))
            ->get(route('contabilidad.paquete', ['mes' => 1, 'anio' => 2020]))
            ->assertOk();
        $this->assertSame(0, $resp->viewData('resumen')['compras_cantidad']);
        $this->assertSame(0, $resp->viewData('resumen')['ventas_cantidad']);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('contabilidad.paquete.generar'), ['mes' => 1, 'anio' => 2020])
            ->assertOk()
            ->assertDownload('documentos_contabilidad_2020-01.zip');
    }

    public function test_no_toca_correlativos_ni_crea_dtes(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->compra('2026-07-05', 100);
        $this->venta('2026-07-10', 200);
        $dtes = Dte::count();
        $correl = \App\Models\Correlativo::orderBy('id')->get(['id', 'ultimo_numero'])->toArray();

        $this->actingAs($this->usuario('administrador'))
            ->post(route('contabilidad.paquete.generar'), ['mes' => 7, 'anio' => 2026])
            ->assertOk();

        $this->assertSame($dtes, Dte::count());
        $this->assertEquals($correl, \App\Models\Correlativo::orderBy('id')->get(['id', 'ultimo_numero'])->toArray());
        // El paquete NO cambia estados de compras.
        $this->assertSame('pendiente', DocumentoRecibido::first()->estado);
    }

    public function test_consulta_no_accede(): void
    {
        $this->actingAs($this->usuario('consulta'))
            ->get(route('contabilidad.paquete'))
            ->assertForbidden();
    }
}
