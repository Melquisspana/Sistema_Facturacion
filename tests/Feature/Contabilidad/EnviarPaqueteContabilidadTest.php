<?php

namespace Tests\Feature\Contabilidad;

use App\Mail\PaqueteContabilidadCorreo;
use App\Models\Configuracion;
use App\Models\DocumentoRecibido;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\User;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Envío del paquete mensual a contabilidad. Herramienta INTERNA: manda UN correo con
 * el ZIP al correo configurado, solo tras confirmación con frase exacta.
 *
 * SEGURIDAD: no toca DTE emitidos, correlativos, firmador ni transmisión a Hacienda; las
 * ventas son solo lectura. No toca el buzón Yahoo/IMAP. Si el envío es exitoso, marca
 * como "enviado" solo las compras (documentos_recibidos) del rango que estaban
 * "pendiente" (no toca "ignorado" ni las ya "enviado"). Si falla, no cambia estados.
 */
class EnviarPaqueteContabilidadTest extends TestCase
{
    use RefreshDatabase;

    private const CORREO = 'contabilidad@empresa.com';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Configuracion::olvidarCache();
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function conCorreo(): void
    {
        Configuracion::set('contabilidad.correo', self::CORREO);
    }

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

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return $extra + [
            'mes' => 7, 'anio' => 2026,
            'incluir_compras' => 1, 'incluir_ventas' => 1,
            'frase' => 'ENVIAR A CONTABILIDAD',
        ];
    }

    public function test_boton_deshabilitado_si_no_hay_correo_contabilidad(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->compra('2026-07-05', 100);

        $resp = $this->actingAs($this->usuario('administrador'))
            ->get(route('contabilidad.paquete', ['mes' => 7, 'anio' => 2026]))
            ->assertOk();

        $this->assertNull($resp->viewData('correoContabilidad'));
        $this->assertFalse($resp->viewData('puedeEnviar'));
        // El envío no se ofrece; se guía a configurar el correo.
        $resp->assertSee('Configuración &gt; Contabilidad', false);
    }

    public function test_boton_habilitado_con_correo_y_documentos(): void
    {
        $this->conCorreo();
        $this->compra('2026-07-05', 100);

        $resp = $this->actingAs($this->usuario('administrador'))
            ->get(route('contabilidad.paquete', ['mes' => 7, 'anio' => 2026]))
            ->assertOk();

        $this->assertSame(self::CORREO, $resp->viewData('correoContabilidad'));
        $this->assertTrue($resp->viewData('puedeEnviar'));
    }

    public function test_no_habilitado_si_no_hay_documentos_en_el_rango(): void
    {
        $this->conCorreo();

        $resp = $this->actingAs($this->usuario('administrador'))
            ->get(route('contabilidad.paquete', ['mes' => 1, 'anio' => 2020]))
            ->assertOk();

        $this->assertFalse($resp->viewData('puedeEnviar'));
    }

    public function test_no_envia_si_falta_la_frase_exacta(): void
    {
        Mail::fake();
        $this->conCorreo();
        $this->compra('2026-07-05', 100);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('contabilidad.paquete.enviar'), $this->payload(['frase' => 'enviar']))
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_no_envia_sin_correo_configurado_aunque_haya_frase(): void
    {
        Mail::fake();
        $this->compra('2026-07-05', 100);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('contabilidad.paquete.enviar'), $this->payload())
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_no_envia_si_no_hay_documentos_en_el_rango(): void
    {
        Mail::fake();
        $this->conCorreo();

        $this->actingAs($this->usuario('administrador'))
            ->post(route('contabilidad.paquete.enviar'), $this->payload(['mes' => 1, 'anio' => 2020]))
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_envia_un_solo_correo_a_contabilidad_con_zip_adjunto(): void
    {
        Mail::fake();
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->conCorreo();
        $this->compra('2026-07-05', 100);
        $this->venta('2026-07-10', 200);

        $this->actingAs($this->usuario('contador'))
            ->post(route('contabilidad.paquete.enviar'), $this->payload())
            ->assertSessionHas('status');

        Mail::assertSentCount(1);
        Mail::assertSent(PaqueteContabilidadCorreo::class, function (PaqueteContabilidadCorreo $mail) {
            return $mail->hasTo(self::CORREO)
                && ! $mail->hasCc(self::CORREO)
                && ! $mail->hasBcc(self::CORREO)
                && $mail->nombreZip === 'documentos_contabilidad_2026-07.zip'
                && strlen($mail->zipBytes) > 0;
        });
    }

    public function test_registra_auditoria_del_envio(): void
    {
        Mail::fake();
        $this->conCorreo();
        $this->compra('2026-07-05', 100);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('contabilidad.paquete.enviar'), $this->payload(['incluir_ventas' => 0]));

        $log = Activity::where('log_name', 'paquete_contabilidad')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('enviado', $log->getExtraProperty('estado'));
        $this->assertSame(self::CORREO, $log->getExtraProperty('correo_destino'));
        $this->assertSame('documentos_contabilidad_2026-07.zip', $log->getExtraProperty('zip'));
        $this->assertNotNull($log->causer_id);
    }

    public function test_si_falla_el_envio_no_cambia_estados_y_audita_fallido(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->conCorreo();
        $compra = $this->compra('2026-07-05', 100);
        $this->venta('2026-07-10', 200);
        $dtes = Dte::count();
        $correl = \App\Models\Correlativo::orderBy('id')->get(['id', 'ultimo_numero'])->toArray();

        // Simula caída del correo: el envío lanza excepción.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caído'));

        $this->actingAs($this->usuario('administrador'))
            ->post(route('contabilidad.paquete.enviar'), $this->payload())
            ->assertSessionHas('error');

        // No cambia estados de documentos, ni correlativos, ni crea/elimina DTE.
        $this->assertSame('pendiente', $compra->fresh()->estado);
        $this->assertSame($dtes, Dte::count());
        $this->assertEquals($correl, \App\Models\Correlativo::orderBy('id')->get(['id', 'ultimo_numero'])->toArray());

        $log = Activity::where('log_name', 'paquete_contabilidad')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('fallido', $log->getExtraProperty('estado'));
    }

    public function test_marca_compras_pendientes_del_rango_como_enviadas_tras_envio_exitoso(): void
    {
        Mail::fake();
        $this->conCorreo();
        $dentro1 = $this->compra('2026-07-05', 100);
        $dentro2 = $this->compra('2026-07-20', 50);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('contabilidad.paquete.enviar'), $this->payload())
            ->assertSessionHas('status');

        $this->assertSame('enviado', $dentro1->fresh()->estado);
        $this->assertSame('enviado', $dentro2->fresh()->estado);

        $log = Activity::where('log_name', 'paquete_contabilidad')->latest('id')->first();
        $this->assertSame(2, $log->getExtraProperty('compras_marcadas_enviadas'));
    }

    public function test_no_marca_compras_fuera_del_rango(): void
    {
        Mail::fake();
        $this->conCorreo();
        $dentro = $this->compra('2026-07-05', 100);
        $fuera = $this->compra('2026-06-20', 999); // mes anterior: fuera del rango enviado

        $this->actingAs($this->usuario('administrador'))
            ->post(route('contabilidad.paquete.enviar'), $this->payload())
            ->assertSessionHas('status');

        $this->assertSame('enviado', $dentro->fresh()->estado);
        $this->assertSame('pendiente', $fuera->fresh()->estado);
    }

    public function test_no_marca_compras_ignoradas(): void
    {
        Mail::fake();
        $this->conCorreo();
        $pendiente = $this->compra('2026-07-05', 100);
        $ignorada = $this->compra('2026-07-06', 30, ['estado' => 'ignorado']);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('contabilidad.paquete.enviar'), $this->payload())
            ->assertSessionHas('status');

        $this->assertSame('enviado', $pendiente->fresh()->estado);
        $this->assertSame('ignorado', $ignorada->fresh()->estado);
    }

    public function test_no_toca_compras_ya_enviadas(): void
    {
        Mail::fake();
        $this->conCorreo();
        $yaEnviada = $this->compra('2026-07-06', 30, ['estado' => 'enviado']);
        $updatedAtOriginal = $yaEnviada->updated_at;

        $this->actingAs($this->usuario('administrador'))
            ->post(route('contabilidad.paquete.enviar'), $this->payload())
            ->assertSessionHas('status');

        $yaEnviada->refresh();
        $this->assertSame('enviado', $yaEnviada->estado);
        $this->assertEquals($updatedAtOriginal, $yaEnviada->updated_at);
    }

    public function test_no_marca_nada_si_incluir_compras_es_falso(): void
    {
        Mail::fake();
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->conCorreo();
        $compra = $this->compra('2026-07-05', 100);
        $this->venta('2026-07-10', 200);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('contabilidad.paquete.enviar'), $this->payload(['incluir_compras' => 0]))
            ->assertSessionHas('status');

        $this->assertSame('pendiente', $compra->fresh()->estado);

        $log = Activity::where('log_name', 'paquete_contabilidad')->latest('id')->first();
        $this->assertSame(0, $log->getExtraProperty('compras_marcadas_enviadas'));
    }

    public function test_no_toca_ventas_dte_tras_envio_exitoso(): void
    {
        Mail::fake();
        $this->seed(DatosInicialesNegritaSeeder::class);
        $this->conCorreo();
        $this->compra('2026-07-05', 100);
        $venta = $this->venta('2026-07-10', 200);
        $estadoOriginal = $venta->estado;
        $selloOriginal = $venta->sello_recepcion;
        $codigoOriginal = $venta->codigo_generacion;
        $totalOriginal = $venta->total_pagar;
        $updatedAtOriginal = $venta->updated_at;

        $this->actingAs($this->usuario('administrador'))
            ->post(route('contabilidad.paquete.enviar'), $this->payload())
            ->assertSessionHas('status');

        $venta->refresh();
        $this->assertSame($estadoOriginal, $venta->estado);
        $this->assertSame($selloOriginal, $venta->sello_recepcion);
        $this->assertSame($codigoOriginal, $venta->codigo_generacion);
        $this->assertSame($totalOriginal, $venta->total_pagar);
        $this->assertEquals($updatedAtOriginal, $venta->updated_at);
    }

    public function test_no_toca_yahoo_imap_al_enviar(): void
    {
        Mail::fake();
        $this->conCorreo();
        $this->compra('2026-07-05', 100);

        // Si el envío tocara el buzón, el contrato IMAP recibiría llamadas: no debe pasar.
        $buzon = \Mockery::mock(MailboxClient::class);
        $buzon->shouldNotReceive('mensajesConAdjuntos');
        $buzon->shouldNotReceive('disponible');
        $this->app->instance(MailboxClient::class, $buzon);

        $antes = DocumentoRecibido::count();

        $this->actingAs($this->usuario('administrador'))
            ->post(route('contabilidad.paquete.enviar'), $this->payload(['incluir_ventas' => 0]))
            ->assertSessionHas('status');

        // No se descargó nada nuevo del buzón.
        $this->assertSame($antes, DocumentoRecibido::count());
    }

    public function test_consulta_no_puede_enviar(): void
    {
        Mail::fake();
        $this->conCorreo();
        $this->compra('2026-07-05', 100);

        $this->actingAs($this->usuario('consulta'))
            ->post(route('contabilidad.paquete.enviar'), $this->payload())
            ->assertForbidden();

        Mail::assertNothingSent();
    }
}
