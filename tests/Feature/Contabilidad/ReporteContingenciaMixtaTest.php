<?php

namespace Tests\Feature\Contabilidad;

use App\Enums\EstadoDte;
use App\Mail\PaqueteContabilidadCorreo;
use App\Models\Configuracion;
use App\Models\Contingencia;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\User;
use App\Services\Contabilidad\EstadoPaquete;
use App\Services\Contabilidad\PaqueteContabilidadZip;
use App\Services\Contabilidad\PeriodoPaquete;
use App\Services\DocumentosRecibidos\ProgresoSincronizacionCompras;
use App\Services\Reportes\ReporteContadoraExcel;
use App\Services\Reportes\ReporteContadoraQuery;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use ZipArchive;

/**
 * Reporte de la contadora con ventas mixtas (#92): normal, emitida en contingencia
 * con sello, emitida en contingencia todavía sin sello (transitoria) e invalidada.
 * La transitoria se informa aparte como «pendiente de sello» y no suma en los totales.
 */
class ReporteContingenciaMixtaTest extends TestCase
{
    use RefreshDatabase;

    private Dte $normal;

    private Dte $conSello;

    private Dte $sinSello;

    private Dte $invalidada;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(DatosInicialesNegritaSeeder::class);

        $progreso = app(ProgresoSincronizacionCompras::class);
        for ($d = Carbon::create(2026, 7, 1); $d->month === 7; $d->addDay()) {
            $progreso->marcarCompleto($d, 'INBOX', 5001, null, []);
        }

        $contingencia = Contingencia::create(['tipo' => 1, 'motivo' => 'Corte inventado', 'origen' => 'automatica',
            'inicio' => Carbon::parse('2026-07-06 08:00:00'), 'cese' => Carbon::parse('2026-07-07 18:00:00'), 'estado' => 'cerrada']);

        $this->normal = $this->venta('2026-07-05', 200);
        $this->conSello = $this->venta('2026-07-06', 300, ['contingencia_id' => $contingencia->id]);
        $this->sinSello = $this->venta('2026-07-07', 400, ['contingencia_id' => $contingencia->id, 'estado' => 'firmado',
            'sello_recepcion' => null, 'fecha_procesamiento_mh' => null]);
        $this->invalidada = $this->venta('2026-07-10', 900, ['estado' => 'invalidado', 'sello_invalidacion' => 'SELLO-EVENTO-REAL',
            'fecha_invalidacion' => Carbon::parse('2026-08-02 12:30:00'), 'codigo_generacion_invalidacion' => 'EVENTO-001']);

        // Ninguno de estos es una venta pendiente de sello:
        $this->venta('2026-07-08', 50, ['estado' => 'firmado', 'sello_recepcion' => null, 'fecha_procesamiento_mh' => null]); // sin contingencia
        $this->venta('2026-07-08', 60, ['contingencia_id' => $contingencia->id, 'estado' => 'rechazado', 'sello_recepcion' => null, 'fecha_procesamiento_mh' => null]);
        $this->venta('2026-07-08', 70, ['contingencia_id' => $contingencia->id, 'estado' => 'firmado', 'ambiente' => '00', 'sello_recepcion' => null, 'fecha_procesamiento_mh' => null]);
    }

    private function venta(string $fecha, float $total, array $extra = []): Dte
    {
        $dte = Dte::create([
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
        $dte->forceFill($extra)->saveQuietly();

        return $dte->refresh();
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    public function test_consultas_separan_pendientes_de_sello_sin_depender_del_interruptor(): void
    {
        config(['dte.contingencia.enabled' => false]);
        $f = ReporteContadoraQuery::filtros(['fecha_desde' => '2026-07-01', 'fecha_hasta' => '2026-07-31']);
        $this->assertSame([$this->normal->id, $this->conSello->id, $this->sinSello->id], ReporteContadoraQuery::query($f)->pluck('id')->all());

        $periodo = app(PeriodoPaquete::class);
        $ventas = $periodo->ventas(['desde' => '2026-07-01', 'hasta' => '2026-07-31']);
        $this->assertSame([$this->normal->id, $this->conSello->id, $this->sinSello->id, $this->invalidada->id], $ventas->pluck('id')->all());
        $this->assertSame([false, false, true, false], $ventas->map(fn (Dte $d) => ReporteContadoraQuery::esPendienteDeSello($d))->all());
        $this->assertSame(['ventas_cantidad' => 2, 'ventas_invalidadas' => 1, 'ventas_pendientes_sello' => 1, 'ventas_total' => 500.0],
            $periodo->resumenVentas($ventas));
    }

    public function test_excel_marca_contingencia_y_aparta_el_monto_sin_sello(): void
    {
        $ventas = app(PeriodoPaquete::class)->ventas(['desde' => '2026-07-01', 'hasta' => '2026-07-31']);
        $ruta = app(ReporteContadoraExcel::class)->generar($ventas);
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);

        // A–R no cambian de lugar; S y T van al final.
        $this->assertSame('Monto original invalidado', $hoja->getCell('R1')->getValue());
        $this->assertSame('En contingencia', $hoja->getCell('S1')->getValue());
        $this->assertSame('Monto pendiente de sello (no suma)', $hoja->getCell('T1')->getValue());
        $this->assertTrue($hoja->getStyle('T1')->getFont()->getBold());

        // Fila 2: normal. Fila 3: contingencia con sello, venta normal marcada.
        $this->assertSame([[null, null]], $hoja->rangeToArray('S2:T2'));
        $this->assertSame(EstadoDte::Aceptado->label(), $hoja->getCell('I3')->getValue());
        $this->assertEquals(300, $hoja->getCell('M3')->getValue());
        $this->assertSame('Sí', $hoja->getCell('S3')->getValue());
        $this->assertSame([[null]], $hoja->rangeToArray('T3'));

        // Fila 4: contingencia sin sello. Montos sumables en 0, monto aparte en T.
        $this->assertSame('PENDIENTE DE SELLO', $hoja->getCell('I4')->getValue());
        foreach (['J', 'K', 'L', 'M'] as $col) {
            $this->assertEquals(0, $hoja->getCell($col.'4')->getValue());
        }
        $this->assertSame([[null, null, null]], $hoja->rangeToArray('P4:R4'));
        $this->assertSame('Sí', $hoja->getCell('S4')->getValue());
        $this->assertEquals(400, $hoja->getCell('T4')->getValue());
        $this->assertSame('#,##0.00', $hoja->getStyle('T4')->getNumberFormat()->getFormatCode());
        $this->assertSame('FEF3C7', $hoja->getStyle('A4')->getFill()->getStartColor()->getRGB());
        $this->assertTrue($hoja->getStyle('I4')->getFont()->getBold());

        // Fila 5: invalidada, como siempre.
        $this->assertSame('INVALIDADO', $hoja->getCell('I5')->getValue());
        $this->assertSame([[null, null]], $hoja->rangeToArray('S5:T5'));

        // Total: solo las aceptadas (normal + contingencia con sello).
        $this->assertSame('TOTAL aceptados (sin invalidados)', $hoja->getCell('I7')->getValue());
        $this->assertEquals(500, $hoja->getCell('M7')->getValue());
    }

    public function test_zip_lista_pendientes_de_sello_con_pdf_y_sin_json(): void
    {
        Storage::fake('local');
        $ventas = app(PeriodoPaquete::class)->ventas(['desde' => '2026-07-01', 'hasta' => '2026-07-31']);
        $r = app(PaqueteContabilidadZip::class)->generar('2026-07', collect(), $ventas, false, true);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($r['ruta']));
        $nombres = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));
        $leeme = $zip->getFromName('LEEME.txt');
        $zip->close();
        @unlink($r['ruta']);

        $id = $this->sinSello->id;
        $this->assertTrue($nombres->contains(fn ($n) => str_starts_with($n, 'ventas/pdf/'.$id.'_')));
        $this->assertFalse($nombres->contains(fn ($n) => str_starts_with($n, 'ventas/json/'.$id.'_')));
        $this->assertSame(1, $r['ventas_pendientes_sello']);
        $this->assertSame([], array_values(array_filter($r['incidencias'], fn ($i) => str_starts_with($i, 'Venta #'.$id.' '))));
        $this->assertStringContainsString('PENDIENTES DE SELLO (1) — NO suman en los totales:', $leeme);
        $this->assertStringContainsString($this->sinSello->numero_control, $leeme);
        $this->assertStringContainsString('400.00', $leeme);
        $this->assertStringContainsString('DOCUMENTOS INVALIDADOS (1)', $leeme);
    }

    public function test_pantallas_avisan_y_no_permiten_enviar_un_pendiente(): void
    {
        $contadora = $this->usuario('contabilidad');
        $this->actingAs($contadora)->get(route('facturacion.reporte-contadora', ['fecha_desde' => '2026-07-01', 'fecha_hasta' => '2026-07-31']))
            ->assertOk()->assertSee('Pendiente de sello')->assertSee('En contingencia');
        $this->get(route('facturacion.reporte-contadora.json', $this->sinSello))->assertForbidden();
        $this->post(route('facturacion.reporte-contadora.enviar', $this->sinSello))->assertForbidden();

        $resumen = $this->get(route('contabilidad.paquete', ['mes' => 7, 'anio' => 2026]))
            ->assertOk()->assertSee('1 pendientes de sello (no suman)')->viewData('resumen');
        $this->assertSame(500.0, $resumen['ventas_total']);
        $this->assertSame(1, $resumen['ventas_pendientes_sello']);
        $this->assertSame(2, $resumen['ventas_sin_json']);

        $correo = (new PaqueteContabilidadCorreo('2026-07', 'https://drive.example.test/x', 'x.zip', $resumen + [
            'desde' => '2026-07-01', 'hasta' => '2026-07-31', 'incluir_compras' => false, 'incluir_ventas' => true,
        ]))->render();
        $this->assertStringContainsString('Pendientes de sello', $correo);
    }

    public function test_un_periodo_solo_con_pendientes_se_puede_enviar_y_el_job_los_cuenta(): void
    {
        Storage::fake('local');
        $this->venta('2026-08-03', 400, ['contingencia_id' => $this->sinSello->contingencia_id, 'estado' => 'firmado',
            'sello_recepcion' => null, 'fecha_procesamiento_mh' => null]);
        Configuracion::set('contabilidad.correo', 'contadora@ejemplo.com');
        $usuario = $this->usuario('contabilidad');
        $parametros = ['mes' => 8, 'anio' => 2026, 'incluir_compras' => 0, 'incluir_ventas' => 1];
        $this->assertTrue($this->actingAs($usuario)->get(route('contabilidad.paquete', $parametros))->assertOk()->viewData('puedeEnviar'));

        $this->post(route('contabilidad.paquete.generar'), $parametros)->assertRedirect()->assertSessionHas('status');
        $estado = EstadoPaquete::para($usuario->id, 'zip', ['etiqueta' => '2026-08'], false, true)->leer();
        $this->assertSame(0, $estado['ventas']);
        $this->assertSame(1, $estado['ventas_pendientes_sello']);
    }
}
