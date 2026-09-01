<?php

namespace Tests\Feature\Contabilidad;

use App\Models\Configuracion;
use App\Models\Correlativo;
use App\Models\DocumentoRecibido;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\User;
use App\Services\Contabilidad\PaqueteContabilidadZip;
use App\Services\DocumentosRecibidos\ProgresoSincronizacionCompras;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
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

        // Se afirma lo intermedio: si el disco falla, la prueba lo dice ACÁ y no tres
        // aserciones más abajo con un "falta el archivo" que no explica nada.
        $this->assertTrue(Storage::disk('local')->put($venta->json_generado_path, '{"identificacion":{"x":1}}'));
        $this->assertSame('local', config('dte.storage.disk'), 'el ZIP lee del disco configurado en dte.storage.disk');
        $this->assertTrue(Storage::disk('local')->exists($venta->json_generado_path));
        $venta->save();

        $r = app(PaqueteContabilidadZip::class)->generar('2026-07', new Collection, new Collection([$venta]), false, true);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($r['ruta']) === true);
        $nombres = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombres[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($r['ruta']);

        $this->assertContains('ventas/pdf/'.$venta->id.'_dte-03-'.$venta->id.'.pdf', $nombres);
        $this->assertContains('ventas/json/'.$venta->id.'_'.basename($venta->json_generado_path), $nombres);
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
            ->assertOk();

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
