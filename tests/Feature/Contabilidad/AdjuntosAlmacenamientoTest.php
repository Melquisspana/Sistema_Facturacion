<?php

namespace Tests\Feature\Contabilidad;

use App\Jobs\EnviarDteCorreo;
use App\Models\DocumentoRecibido;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Models\Establecimiento;
use App\Services\Contabilidad\PaqueteContabilidadZip;
use App\Services\Dte\DtePdfService;
use App\Support\Archivos\ArchivoAlmacenado;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * JSON presente, ausente y con el disco fallando.
 *
 * EL PATRÓN QUE SE ENDURECIÓ. El ZIP del paquete y el correo del DTE preguntaban
 * `Storage::disk($d)->exists($ruta)` y, si daba `false`, seguían de largo. Como los
 * discos del proyecto están declarados con `throw => false`, un disco mal configurado o
 * un permiso denegado devuelven exactamente ese mismo `false`: el JSON no viajaba y no
 * había ni un error. Un archivo que nunca existió y un servidor roto se veían igual.
 *
 * Ahora se distinguen, y el ZIP y el historial de envío lo dicen con contexto suficiente
 * para actuar: qué archivo, qué disco, qué pasó.
 */
class AdjuntosAlmacenamientoTest extends TestCase
{
    use RefreshDatabase;

    /** Nombre de un disco que NO existe: la forma más limpia de romper el almacenamiento. */
    private const DISCO_ROTO = 'disco-que-no-existe';

    private function venta(string $fecha = '2026-07-10', float $total = 200): Dte
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

    /** @return array<int, string> nombres dentro del ZIP */
    private function contenidoZip(string $ruta): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        $nombres = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombres[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $nombres;
    }

    private function leeme(string $ruta): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        $leeme = (string) $zip->getFromName('LEEME.txt');
        $zip->close();

        return $leeme;
    }

    // ---------------------------------------------------- ArchivoAlmacenado

    public function test_archivo_presente(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('dte/json/x.json', '{"a":1}');

        $r = ArchivoAlmacenado::leer('local', 'dte/json/x.json');

        $this->assertTrue($r->presente());
        $this->assertSame('{"a":1}', $r->contenido);
    }

    public function test_archivo_ausente_no_es_un_error(): void
    {
        Storage::fake('local');

        $r = ArchivoAlmacenado::leer('local', 'dte/json/no-esta.json');

        $this->assertTrue($r->ausente());
        $this->assertFalse($r->fallo());
        $this->assertStringContainsString('no existe en el disco', $r->explicacion());
    }

    public function test_sin_ruta_registrada_es_ausente(): void
    {
        Storage::fake('local');

        $this->assertTrue(ArchivoAlmacenado::leer('local', null)->ausente());
        $this->assertTrue(ArchivoAlmacenado::leer('local', '')->ausente());
    }

    /** Un disco inexistente es un ERROR, no una ausencia. Esa es toda la diferencia. */
    public function test_un_disco_que_falla_es_error_y_no_ausencia(): void
    {
        $r = ArchivoAlmacenado::leer(self::DISCO_ROTO, 'dte/json/x.json');

        $this->assertTrue($r->fallo());
        $this->assertFalse($r->ausente());
        $this->assertStringContainsString(self::DISCO_ROTO, $r->explicacion());
        $this->assertStringContainsString('No se pudo leer', $r->explicacion());
    }

    // ---------------------------------------------------- ZIP del paquete

    public function test_el_zip_incluye_el_json_de_ventas_cuando_esta(): void
    {
        Storage::fake('local');
        $this->seed(DatosInicialesNegritaSeeder::class);

        $venta = $this->venta();
        $venta->json_generado_path = 'dte/json/dte-03-'.$venta->id.'-'.$venta->codigo_generacion.'.json';
        // El put() se AFIRMA: si el disco falso fallara, la prueba lo dice acá y no
        // tres aserciones más abajo con un "falta el archivo" que no explica nada.
        $this->assertTrue(Storage::disk('local')->put($venta->json_generado_path, '{"identificacion":{"x":1}}'));
        $this->assertSame('local', config('dte.storage.disk'));
        $venta->save();

        $r = app(PaqueteContabilidadZip::class)->generar('2026-07', new Collection, new Collection([$venta]), false, true);
        $nombres = $this->contenidoZip($r['ruta']);
        @unlink($r['ruta']);

        $this->assertContains('ventas/json/'.$venta->id.'_'.basename($venta->json_generado_path), $nombres);
        $this->assertSame(1, $r['ventas_json']);
        $this->assertSame([], $r['incidencias']);
    }

    /** Sin JSON generado no hay nada que incluir; se reporta como ausencia, sin drama. */
    public function test_el_zip_reporta_el_json_ausente_de_una_venta(): void
    {
        Storage::fake('local');
        $this->seed(DatosInicialesNegritaSeeder::class);

        $venta = $this->venta();
        $venta->forceFill(['json_generado_path' => 'dte/json/no-generado.json'])->save();

        $r = app(PaqueteContabilidadZip::class)->generar('2026-07', new Collection, new Collection([$venta]), false, true);
        $leeme = $this->leeme($r['ruta']);
        @unlink($r['ruta']);

        $this->assertSame(0, $r['ventas_json']);
        $this->assertCount(1, $r['incidencias']);
        $this->assertStringContainsString('ARCHIVOS QUE NO SE PUDIERON INCLUIR', $leeme);
        $this->assertStringContainsString('no existe en el disco', $leeme);
    }

    /**
     * Con el disco roto, el ZIP no puede quedarse callado: el LEEME nombra el disco y el
     * motivo, para que quien reciba el paquete sepa que falta algo por un problema del
     * servidor y no porque el documento no lo tuviera.
     */
    public function test_el_zip_reporta_un_error_de_disco_con_contexto(): void
    {
        Storage::fake('local');
        $this->seed(DatosInicialesNegritaSeeder::class);
        config(['dte.storage.disk' => self::DISCO_ROTO]);

        $venta = $this->venta();
        $venta->forceFill(['json_generado_path' => 'dte/json/dte-03-'.$venta->id.'-'.$venta->codigo_generacion.'.json'])->save();

        $r = app(PaqueteContabilidadZip::class)->generar('2026-07', new Collection, new Collection([$venta]), false, true);
        $leeme = $this->leeme($r['ruta']);
        @unlink($r['ruta']);

        $this->assertSame(0, $r['ventas_json']);
        $this->assertCount(1, $r['incidencias']);
        $this->assertStringContainsString(self::DISCO_ROTO, $leeme);
        $this->assertStringContainsString('Error de almacenamiento', $leeme);
        // El PDF sí viajó: el fallo del JSON no tira abajo el paquete entero.
        $this->assertSame(1, $r['ventas_pdf']);
    }

    /** Lo mismo para un adjunto de COMPRA que no se puede leer. */
    public function test_el_zip_reporta_un_adjunto_de_compra_que_no_se_pudo_leer(): void
    {
        Storage::fake('local');

        $compra = DocumentoRecibido::create([
            'gmail_message_id' => 'z1',
            'identidad' => 'mid:z1@proveedor.example',
            'emisor_nombre' => 'PROVEEDOR Z',
            'tipo_documento' => '03',
            'numero_control' => 'DTE-03-Z-1',
            'estado' => 'pendiente',
            'total' => 100,
            'tiene_pdf' => true,
            'tiene_json' => true,
            'fecha_correo' => Carbon::parse('2026-07-05'),
            'fecha_dte' => Carbon::parse('2026-07-05'),
            // Ruta registrada pero archivo inexistente en disco.
            'metadata_json' => ['archivos' => ['documentos-recibidos/z1/factura.pdf']],
        ]);

        $r = app(PaqueteContabilidadZip::class)->generar('2026-07', new Collection([$compra]), new Collection, true, false);
        $leeme = $this->leeme($r['ruta']);
        @unlink($r['ruta']);

        $this->assertSame(0, $r['compras_pdf']);
        $this->assertStringContainsString('Compra #'.$compra->id, $leeme);
        $this->assertStringContainsString('DTE-03-Z-1', $leeme);
    }

    // ---------------------------------------------------- correo del DTE

    public function test_el_correo_adjunta_el_json_cuando_esta(): void
    {
        Storage::fake('local');
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->simularProduccionCorreo();
        Mail::fake();

        $venta = $this->venta();
        $venta->json_generado_path = 'dte/json/dte-03-'.$venta->id.'-'.$venta->codigo_generacion.'.json';
        $this->assertTrue(Storage::disk('local')->put($venta->json_generado_path, '{"identificacion":{"x":1}}'));
        $venta->save();

        // El archivo tiene que seguir ahí justo antes de que el job lo lea. Si no está, el
        // problema es el disco de prueba y no el adjunto: que lo diga esta línea.
        $this->assertTrue(Storage::disk('local')->exists($venta->json_generado_path));

        $envio = $venta->envios()->create(['destinatario' => 'a@a.com', 'destinatarios' => ['a@a.com'], 'estado' => 'pendiente']);
        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        $envio->refresh();
        $this->assertSame('enviado', $envio->estado);
        $this->assertStringContainsString('JSON', $envio->adjuntos);
        $this->assertNull($envio->error);
    }

    /** Un DTE sin JSON generado se envía solo con el PDF, y eso no es un error. */
    public function test_el_correo_sin_json_no_registra_error(): void
    {
        Storage::fake('local');
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->simularProduccionCorreo();
        Mail::fake();

        $venta = $this->venta();
        $envio = $venta->envios()->create(['destinatario' => 'a@a.com', 'destinatarios' => ['a@a.com'], 'estado' => 'pendiente']);

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        $envio->refresh();
        $this->assertSame('enviado', $envio->estado);
        $this->assertSame('PDF', $envio->adjuntos);
        $this->assertNull($envio->error, 'no tener JSON es normal: no se reporta como fallo');
    }

    /**
     * Con el disco roto el correo sale igual (el PDF se genera en memoria), pero el
     * historial deja escrito que faltó el JSON por un problema de almacenamiento.
     * Antes el envío quedaba "enviado" sin JSON y sin una sola pista.
     */
    public function test_el_correo_registra_el_error_de_disco_en_el_historial(): void
    {
        Storage::fake('local');
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->simularProduccionCorreo();
        Mail::fake();
        config(['dte.storage.disk' => self::DISCO_ROTO]);

        $venta = $this->venta();
        $venta->forceFill(['json_generado_path' => 'dte/json/dte-03-'.$venta->id.'-'.$venta->codigo_generacion.'.json'])->save();

        $envio = $venta->envios()->create(['destinatario' => 'a@a.com', 'destinatarios' => ['a@a.com'], 'estado' => 'pendiente']);
        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        $envio->refresh();
        $this->assertSame('enviado', $envio->estado);
        $this->assertStringNotContainsString('JSON', $envio->adjuntos);
        $this->assertNotNull($envio->error);
        $this->assertStringContainsString(self::DISCO_ROTO, (string) $envio->error);
        $this->assertStringContainsString('error de almacenamiento', mb_strtolower((string) $envio->error));

        $this->assertSame(1, DteEnvio::where('dte_id', $venta->id)->count());
    }
}
