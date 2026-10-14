<?php

namespace Tests\Feature\Contabilidad;

use App\Models\Configuracion;
use App\Models\Correlativo;
use App\Models\DocumentoRecibido;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\User;
use App\Services\Contabilidad\EstadoPaquete;
use App\Services\Contabilidad\PaqueteContabilidadZip;
use App\Services\Contabilidad\PeriodoPaquete;
use App\Services\DocumentosRecibidos\ProgresoSincronizacionCompras;
use App\Services\Dte\ArchivoEntregaDteService;
use App\Services\Reportes\ReporteContadoraExcel;
use App\Services\Reportes\ReporteContadoraQuery;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaArchivoEntregaDte;
use Tests\TestCase;
use ZipArchive;

/**
 * Paquete mensual para contabilidad: herramienta INTERNA (la contadora no entra al
 * sistema). Junta compras (recibidos) + ventas (emitidos) en un ZIP. SOLO lectura:
 * no envía correos, no toca Yahoo, DTE emitidos, correlativos ni estados.
 */
class PaqueteContabilidadTest extends TestCase
{
    use PreparaArchivoEntregaDte;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // El período de estas pruebas (julio 2026) parte CUBIERTO: acá se prueba el
        // armado del paquete, no la cobertura.
        $this->periodoCubierto();
    }

    /**
     * Marca todo el mes como recorrido por completo en el buzón.
     *
     * Sin esto, la verificación de cobertura considera el período NO verificable —no hay
     * registro de que se hayan leído esos días— y bloquea el envío. Estas pruebas son
     * sobre la mecánica del envío, no sobre la cobertura, así que parten de un período
     * cubierto. El bloqueo se prueba aparte, en CoberturaPaqueteTest.
     */
    private function periodoCubierto(int $mes = 7, int $anio = 2026): void
    {
        $progreso = app(ProgresoSincronizacionCompras::class);
        $inicio = Carbon::create($anio, $mes, 1)->startOfMonth();
        $fin = $inicio->copy()->endOfMonth();

        for ($d = $inicio->copy(); $d->lte($fin); $d->addDay()) {
            $progreso->marcarCompleto($d, 'INBOX', 5001, null, []);
        }
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
            // Fecha FISCAL: es la que decide el período del paquete.
            'fecha_dte' => Carbon::parse($fecha),
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

    private function invalidada(array $extra = []): Dte
    {
        $dte = $this->venta('2026-07-10', 900);
        $dte->forceFill($extra + [
            'estado' => 'invalidado',
            'sello_invalidacion' => 'SELLO-EVENTO-REAL',
            'fecha_invalidacion' => Carbon::parse('2026-08-02 12:30:00'),
            'codigo_generacion_invalidacion' => 'EVENTO-001',
            'json_invalidacion_path' => 'invalidaciones/'.$dte->codigo_generacion.'.json',
            'respuesta_mh_invalidacion' => ['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-EVENTO-REAL'],
        ])->saveQuietly();

        return $dte;
    }

    public function test_paquete_incluye_solo_invalidaciones_reales_y_no_altera_filtro_del_reporte(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $aceptada = $this->venta('2026-07-05', 200);
        $real = $this->invalidada();
        foreach ([['sello_invalidacion' => 'mock-inval-001'], ['sello_recepcion' => null, 'fecha_procesamiento_mh' => null], ['ambiente' => '00'], ['sello_invalidacion' => ''], ['sello_recepcion' => 'MOCK-001']] as $extra) {
            $otra = $this->invalidada($extra);
            $this->assertSame($extra === ['ambiente' => '00'], ReporteContadoraQuery::esInvalidadoRealMh($otra));
        }
        $periodo = app(PeriodoPaquete::class);
        $ventas = $periodo->ventas(['desde' => '2026-07-01', 'hasta' => '2026-07-31']);
        $this->assertSame([$aceptada->id, $real->id], $ventas->pluck('id')->all());
        $this->assertSame(['ventas_cantidad' => 1, 'ventas_invalidadas' => 1, 'ventas_total' => 200.0], $periodo->resumenVentas($ventas));
        $f = ReporteContadoraQuery::filtros(['fecha_desde' => '2026-07-01', 'fecha_hasta' => '2026-07-31', 'estado' => 'fiscal']);
        $this->assertSame('aceptado', $f['estado']);
        $this->assertSame([$aceptada->id], ReporteContadoraQuery::query($f)->pluck('id')->all());

        $resumen = $this->actingAs($this->usuario('contabilidad'))->get(route('contabilidad.paquete', ['mes' => 7, 'anio' => 2026]))
            ->assertOk()->assertSee('1 invalidadas (no suman)')->viewData('resumen');
        $this->assertSame(200.0, $resumen['ventas_total']);
        $this->assertSame(1, $resumen['ventas_cantidad']);
        $this->assertSame(1, $resumen['ventas_invalidadas']);
        $this->assertSame(1, $resumen['ventas_sin_json']);
    }

    public function test_excel_invalidada_informa_evento_y_anula_montos_sumables(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $aceptada = $this->venta('2026-07-05', 200);
        $invalidada = $this->invalidada();
        $excel = app(ReporteContadoraExcel::class);
        $ruta = $excel->generar(collect([$aceptada, $invalidada]));
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);
        $this->assertSame('INVALIDADO', $hoja->getCell('I3')->getValue());
        foreach (['J', 'K', 'L', 'M'] as $col) {
            $this->assertEquals(0, $hoja->getCell($col.'3')->getValue());
        }
        $this->assertSame('02/08/2026 12:30', $hoja->getCell('P3')->getValue());
        $this->assertSame('EVENTO-001', $hoja->getCell('Q3')->getValue());
        $this->assertEquals(900, $hoja->getCell('R3')->getValue());
        $this->assertTrue($hoja->getStyle('I3')->getFont()->getBold());
        $this->assertSame('FEE2E2', $hoja->getStyle('A3')->getFill()->getStartColor()->getRGB());
        // Total separado por una fila vacía; solo suma la aceptada. Encabezado fijo.
        $this->assertSame([[null, null, null, null, null]], $hoja->rangeToArray('I4:M4'));
        $this->assertSame('TOTAL aceptados (sin invalidados)', $hoja->getCell('I5')->getValue());
        $this->assertEquals(200, $hoja->getCell('M5')->getValue());
        $this->assertSame('A2', $hoja->getFreezePane());
        $ruta = $excel->generar(collect([$aceptada]));
        $solo = IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);
        $this->assertSame($solo->rangeToArray('A2:O2'), $hoja->rangeToArray('A2:O2'));
        $this->assertEquals(200, $hoja->getCell('M2')->getValue());
        $this->assertSame([[null, null, null]], $hoja->rangeToArray('P2:R2'));
    }

    public function test_zip_invalidada_adjunta_evidencia_y_avisa_evento_faltante(): void
    {
        Storage::fake('local');
        $this->seed(DatosInicialesNegritaSeeder::class);
        $dte = $this->invalidada();
        Storage::disk('local')->put($dte->json_invalidacion_path, '{"evento":"inventado"}');
        foreach ([true, false] as $presente) {
            if (! $presente) {
                Storage::disk('local')->delete($dte->json_invalidacion_path);
            }
            $r = app(PaqueteContabilidadZip::class)->generar('2026-07', collect(), collect([$dte]), false, true);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($r['ruta']));
            $prefijo = 'ventas/invalidaciones/'.$dte->id.'_EVENTO-001';
            $this->assertSame($presente ? '{"evento":"inventado"}' : false, $zip->getFromName($prefijo.'_evento.json'));
            $this->assertSame($dte->respuesta_mh_invalidacion, json_decode($zip->getFromName($prefijo.'_respuesta_mh.json'), true));
            $leeme = $zip->getFromName('LEEME.txt');
            $this->assertStringContainsString('DOCUMENTOS INVALIDADOS (1) — NO suman en los totales:', $leeme);
            $this->assertStringContainsString('EVENTO-001', $leeme);
            $this->assertStringContainsString('02/08/2026 12:30', $leeme);
            $this->assertSame(0, $r['ventas_json']);
            $this->assertSame(1, $r['ventas_invalidadas']);
            $this->assertSame($presente ? 2 : 1, $r['invalidaciones_archivos']);
            $this->assertCount($presente ? 0 : 1, $r['incidencias']);
            if (! $presente) {
                $this->assertStringContainsString('Venta #'.$dte->id.' ('.$dte->numero_control.'): invalidación evento:', $leeme);
            }
            $zip->close();
            @unlink($r['ruta']);
        }
    }

    public function test_zip_invalidacion_lee_respuesta_guardada_y_sanea_codigo_del_evento(): void
    {
        Storage::fake('local');
        $this->seed(DatosInicialesNegritaSeeder::class);
        $dte = $this->invalidada([
            'codigo_generacion_invalidacion' => 'evento / inventado',
            'respuesta_mh_invalidacion_path' => 'invalidaciones/respuesta.json',
        ]);
        Storage::disk('local')->put($dte->json_invalidacion_path, '{"evento":1}');
        Storage::disk('local')->put($dte->respuesta_mh_invalidacion_path, '{"respuesta":"guardada"}');
        $r = app(PaqueteContabilidadZip::class)->generar('2026-07', collect(), collect([$dte]), false, true);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($r['ruta']));
        $prefijo = 'ventas/invalidaciones/'.$dte->id.'_EVENTO_INVENTADO';
        $this->assertSame('{"evento":1}', $zip->getFromName($prefijo.'_evento.json'));
        $this->assertSame('{"respuesta":"guardada"}', $zip->getFromName($prefijo.'_respuesta_mh.json'));
        $this->assertSame(2, $r['invalidaciones_archivos']);
        $this->assertSame([], $r['incidencias']);
        $zip->close();
        @unlink($r['ruta']);
    }

    public function test_job_cuenta_vigentes_y_boton_permite_solo_invalidadas(): void
    {
        Storage::fake('local');
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->invalidada();
        Configuracion::set('contabilidad.correo', 'contadora@ejemplo.com');
        $usuario = $this->usuario('contabilidad');
        $parametros = ['mes' => 7, 'anio' => 2026, 'incluir_compras' => 0, 'incluir_ventas' => 1];
        $this->assertTrue($this->actingAs($usuario)->get(route('contabilidad.paquete', $parametros))->assertOk()->viewData('puedeEnviar'));
        $this->venta('2026-07-05', 200);
        $this->post(route('contabilidad.paquete.generar'), $parametros)->assertRedirect()->assertSessionHas('status');
        $estado = EstadoPaquete::para($usuario->id, 'zip', ['etiqueta' => '2026-07'], false, true)->leer();
        $this->assertSame(1, $estado['ventas']);
        $this->assertSame(1, $estado['ventas_invalidadas']);
    }

    public function test_pantalla_paquete_carga_con_resumen(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->compra('2026-07-05', 100);
        $this->compra('2026-07-06', 50);
        $this->venta('2026-07-10', 200);

        $resumen = $this->actingAs($this->usuario('contabilidad'))
            ->get(route('contabilidad.paquete', ['mes' => 7, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('Paquete mensual')          // título simplificado
            ->assertDontSee('La contadora no')       // bloque naranja explicativo eliminado
            ->viewData('resumen');

        $this->assertSame(2, $resumen['compras_cantidad']);
        $this->assertSame(150.0, $resumen['compras_total']);
        $this->assertSame(1, $resumen['ventas_cantidad']);
        $this->assertSame(200.0, $resumen['ventas_total']);
    }

    // ---------- FASE B: resumen ampliado (solo datos existentes) ----------

    public function test_resumen_cuenta_faltantes_de_pdf_y_json(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->compra('2026-07-05', 100);                                   // pdf + json
        $this->compra('2026-07-06', 50, ['tiene_pdf' => false]);           // sin PDF
        $this->compra('2026-07-07', 30, ['tiene_json' => false]);          // sin JSON
        $venta1 = $this->venta('2026-07-10', 200);                          // sin JSON generado
        $venta2 = $this->venta('2026-07-11', 300);
        // json_generado_path no es fillable (lo escribe el servicio de generación): forceFill.
        $venta2->forceFill(['json_generado_path' => 'dte/generados/venta2.json'])->save();

        $resumen = $this->actingAs($this->usuario('contabilidad'))
            ->get(route('contabilidad.paquete', ['mes' => 7, 'anio' => 2026]))
            ->assertOk()
            ->viewData('resumen');

        $this->assertSame(3, $resumen['compras_cantidad']);
        $this->assertSame(2, $resumen['ventas_cantidad']);
        $this->assertSame(1, $resumen['compras_sin_pdf']);
        $this->assertSame(1, $resumen['compras_sin_json']);
        $this->assertSame(1, $resumen['ventas_sin_json']); // solo venta1
    }

    public function test_destinatario_configurado_es_visible(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        Configuracion::set('contabilidad.correo', 'contadora@ejemplo.com');
        Configuracion::olvidarCache();

        $this->actingAs($this->usuario('contabilidad'))
            ->get(route('contabilidad.paquete', ['mes' => 7, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('contadora@ejemplo.com');
    }

    public function test_sin_correo_configurado_muestra_no_configurado(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        Configuracion::olvidarCache(); // sin correo configurado

        $this->actingAs($this->usuario('contabilidad'))
            ->get(route('contabilidad.paquete', ['mes' => 7, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('No configurado');
    }

    public function test_ultimo_envio_exitoso_se_muestra(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $user = $this->usuario('contabilidad');

        // Envío exitoso previo, registrado en el activity log ya existente (sin persistencia nueva).
        activity('paquete_contabilidad')
            ->causedBy($user)
            ->withProperties([
                'estado' => 'enviado', 'etiqueta' => '2026-06',
                'correo_destino' => 'contadora@ejemplo.com', 'compras_cantidad' => 4, 'ventas_cantidad' => 2,
            ])
            ->log('Envío de paquete de contabilidad 2026-06: enviado');

        $ultimo = $this->actingAs($user)
            ->get(route('contabilidad.paquete', ['mes' => 7, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('2026-06')
            ->assertSee('contadora@ejemplo.com')
            ->viewData('ultimoEnvio');

        $this->assertNotNull($ultimo);
        $this->assertSame('2026-06', $ultimo['etiqueta']);
        $this->assertSame('contadora@ejemplo.com', $ultimo['correo']);
    }

    public function test_sin_envios_anteriores(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);

        $ultimo = $this->actingAs($this->usuario('contabilidad'))
            ->get(route('contabilidad.paquete', ['mes' => 7, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('Sin envíos anteriores')
            ->viewData('ultimoEnvio');

        $this->assertNull($ultimo);
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

        $zip = new ZipArchive;
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

    public function test_zip_incluye_pdf_y_json_de_ventas(): void
    {
        Storage::fake('local');
        $this->seed(DatosInicialesNegritaSeeder::class);
        $venta = $this->venta('2026-07-10', 200);
        // La ruta lleva el código de generación para que sea ÚNICA. `Storage::fake('local')`
        // apunta siempre a la misma carpeta física y con SQLite en memoria los id arrancan
        // en 1, así que 'dte/json/dte-03-1.json' era el mismo archivo real para varias
        // clases de prueba: una podía borrarlo mientras otra lo estaba usando.
        $venta->json_generado_path = 'dte/json/dte-03-'.$venta->id.'-'.$venta->codigo_generacion.'.json';

        // Evidencia coherente (JSON + JWS + sello). Se afirma lo intermedio: si el disco
        // falla, la prueba lo dice ACÁ y no con un "falta el archivo" que no explica nada.
        $this->prepararArchivoEntrega($venta);
        $this->assertSame('local', config('dte.storage.disk'), 'el ZIP lee del disco configurado en dte.storage.disk');
        $this->assertTrue(Storage::disk('local')->exists($venta->json_generado_path));

        $r = app(PaqueteContabilidadZip::class)->generar('2026-07', new Collection, new Collection([$venta]), false, true);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($r['ruta']) === true);
        $nombres = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombres[] = $zip->getNameIndex($i);
        }
        $rutaJson = 'ventas/json/'.$venta->id.'_'.strtoupper($venta->codigo_generacion).'.json';
        $json = $zip->getFromName($rutaJson);
        $zip->close();
        @unlink($r['ruta']);

        $this->assertContains('ventas/pdf/'.$venta->id.'_dte-03-'.$venta->id.'.pdf', $nombres);
        $this->assertContains($rutaJson, $nombres);
        // La contadora recibe EXACTAMENTE el archivo del cliente: documento + firma + sello.
        $this->assertSame(app(ArchivoEntregaDteService::class)->construir($venta)->contenido, $json);
        $this->assertSame($venta->sello_recepcion, json_decode($json, true)['selloRecibido']);
        $this->assertArrayHasKey('firmaElectronica', json_decode($json, true));
        $this->assertSame(1, $r['ventas_pdf']);
        $this->assertSame(1, $r['ventas_json']);
    }

    public function test_zip_solo_compras_no_incluye_excel_de_ventas(): void
    {
        Storage::fake('local');
        $compra = $this->compra('2026-07-05', 100);

        $r = app(PaqueteContabilidadZip::class)->generar('2026-07', new Collection([$compra]), new Collection, true, false);

        $zip = new ZipArchive;
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

        $this->actingAs($this->usuario('contabilidad'))
            ->post(route('contabilidad.paquete.generar'), ['mes' => 7, 'anio' => 2026, 'incluir_compras' => 1, 'incluir_ventas' => 1])
            ->assertRedirect();

        $this->get(route('contabilidad.paquete.descargar', ['mes' => 7, 'anio' => 2026, 'incluir_compras' => 1, 'incluir_ventas' => 1]))
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

        // Enero 2020 es anterior a cualquier registro de sincronización: no se puede
        // afirmar que esté completo, así que el ZIP sale marcado. Sigue descargándose.
        $this->actingAs($this->usuario('administrador'))
            ->post(route('contabilidad.paquete.generar'), ['mes' => 1, 'anio' => 2020])
            ->assertRedirect();

        $this->get(route('contabilidad.paquete.descargar', ['mes' => 1, 'anio' => 2020]))
            ->assertOk()
            ->assertDownload('documentos_contabilidad_2020-01_INCOMPLETO.zip');
    }

    public function test_no_toca_correlativos_ni_crea_dtes(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->compra('2026-07-05', 100);
        $this->venta('2026-07-10', 200);
        $dtes = Dte::count();
        $correl = Correlativo::orderBy('id')->get(['id', 'ultimo_numero'])->toArray();

        $this->actingAs($this->usuario('administrador'))
            ->post(route('contabilidad.paquete.generar'), ['mes' => 7, 'anio' => 2026])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame($dtes, Dte::count());
        $this->assertEquals($correl, Correlativo::orderBy('id')->get(['id', 'ultimo_numero'])->toArray());
        // El paquete NO cambia estados de compras.
        $this->assertSame('pendiente', DocumentoRecibido::first()->estado);
    }

    public function test_jefatura_ve_el_paquete_pero_no_lo_envia(): void
    {
        // Nueva política: jefatura tiene reportes.ver (ve el paquete), pero no puede
        // enviarlo (contabilidad.enviar es solo de administrador y contabilidad).
        $jefa = $this->usuario('jefatura');

        $this->actingAs($jefa)->get(route('contabilidad.paquete'))->assertOk();
        $this->actingAs($jefa)->post(route('contabilidad.paquete.enviar'), [])->assertForbidden();
    }

    public function test_facturacion_ve_el_paquete_pero_no_lo_envia(): void
    {
        // Facturación puede ver reportes, pero NO enviar el paquete a contabilidad.
        $fact = $this->usuario('facturacion');

        $this->actingAs($fact)->get(route('contabilidad.paquete'))->assertOk();
        $this->actingAs($fact)->post(route('contabilidad.paquete.enviar'), [])->assertForbidden();
    }
}
