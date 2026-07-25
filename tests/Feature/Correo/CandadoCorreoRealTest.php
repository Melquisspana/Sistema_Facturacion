<?php

namespace Tests\Feature\Correo;

use App\Enums\EstadoDte;
use App\Jobs\EnviarDocumentoRecibidoContabilidad;
use App\Jobs\EnviarDteCorreo;
use App\Mail\DocumentoRecibidoContabilidadCorreo;
use App\Mail\DteCorreo;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\DocumentoRecibido;
use App\Models\DocumentoRecibidoEnvio;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Models\Establecimiento;
use App\Models\User;
use App\Services\DocumentosRecibidos\AdjuntosDocumentoRecibido;
use App\Services\Dte\DtePdfService;
use App\Support\Correo\CandadoCorreoReal;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * CANDADO de correo real: fuera de `production` NINGÚN flujo llama al transporte, aunque
 * MAIL_MAILER sea smtp. El envío se registra como `simulado`, el documento conserva su
 * estado y la interfaz lo avisa. En producción se conserva el comportamiento actual.
 *
 * No hay variable de escape: la única forma de enviar real es que el entorno sea
 * production. Los envíos reales #45/#46 de compras quedaron fuera de esta suite (son
 * datos de la base de desarrollo, no fixtures).
 */
class CandadoCorreoRealTest extends TestCase
{
    use RefreshDatabase;

    private const CORREO_CONTA = 'contabilidad@empresa.com';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Configuracion::olvidarCache();
        Storage::fake('local');
        Configuracion::set('contabilidad.correo', self::CORREO_CONTA);
    }

    private function candado(): CandadoCorreoReal
    {
        return app(CandadoCorreoReal::class);
    }

    // ---------- La decisión del candado ----------

    public function test_local_con_smtp_no_permite_envio_real(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['mail.default' => 'smtp']); // driver REAL configurado a propósito

        $candado = $this->candado();
        $this->assertTrue($candado->transporteEsReal());   // el transporte sí entregaría
        $this->assertFalse($candado->permiteEnvioReal());  // pero el entorno no lo permite
        $this->assertTrue($candado->debeSimular());
        $this->assertStringContainsString('local', $candado->motivo());
    }

    public function test_testing_con_smtp_no_permite_envio_real(): void
    {
        config(['mail.default' => 'smtp']); // entorno testing (el de la suite)

        $this->assertTrue($this->candado()->debeSimular());
        $this->assertStringContainsString('testing', $this->candado()->motivo());
    }

    public function test_production_con_smtp_permite_envio_real(): void
    {
        $this->simularProduccionCorreo();

        $candado = $this->candado();
        $this->assertTrue($candado->permiteEnvioReal());
        $this->assertTrue($candado->transporteEsReal());
        $this->assertFalse($candado->debeSimular());
    }

    public function test_production_con_mailer_de_prueba_sigue_siendo_simulado(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'log']); // driver que no entrega

        $this->assertTrue($this->candado()->debeSimular());
        $this->assertStringContainsString('MAIL_MAILER', $this->candado()->motivo());
    }

    public function test_segunda_barrera_fuerza_un_mailer_de_prueba_fuera_de_produccion(): void
    {
        // AppServiceProvider fuerza mail.default=log al arrancar fuera de producción, así
        // ni un flujo que se olvide del candado puede llegar al SMTP. La app de la suite
        // ya está booteada: se comprueba el efecto sobre la config resuelta.
        $this->assertNotSame('smtp', config('mail.default'));
        $this->assertFalse(app()->environment('production'));
    }

    // ---------- Ventas / DTE (job compartido) ----------

    private function ventaAceptada(): Dte
    {
        $this->seed(DatosInicialesNegritaSeeder::class);

        $dte = Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'cliente_id' => Cliente::factory()->create(['nombre' => 'CLIENTE PRUEBA'])->id,
            'tipo_dte' => '03',
            'estado' => EstadoDte::Aceptado->value,
            'ambiente' => '01',
            'numero_control' => 'DTE-03-M001P001-'.str_pad((string) random_int(1, 999999999), 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => (string) Str::uuid(),
            'sello_recepcion' => '2026SELLOREAL'.random_int(1000, 9999),
            'fecha_procesamiento_mh' => now(),
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => now()->format('H:i:s'),
            'total_gravado' => 100.00,
            'iva' => 13.00,
            'total_pagar' => 113.00,
        ]);

        $ruta = 'dte/json/dte-03-'.$dte->id.'.json';
        Storage::disk('local')->put($ruta, '{"identificacion":{"x":1}}');
        $dte->json_generado_path = $ruta;
        $dte->save();

        return $dte->refresh();
    }

    private function envioDte(Dte $dte, ?string $canal = DteEnvio::CANAL_CLIENTE): DteEnvio
    {
        return $dte->envios()->create([
            'destinatario' => 'cliente@calleja.com',
            'destinatarios' => ['cliente@calleja.com'],
            'canal' => $canal,
            'estado' => 'pendiente',
        ]);
    }

    public function test_dte_al_cliente_fuera_de_produccion_queda_simulado_sin_tocar_el_transporte(): void
    {
        config(['mail.default' => 'smtp']); // aunque haya SMTP configurado
        Mail::fake();
        $dte = $this->ventaAceptada();
        $envio = $this->envioDte($dte);

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        Mail::assertNothingSent(); // el transporte NO se llamó
        $envio->refresh();
        $this->assertSame('simulado', $envio->estado);
        $this->assertStringContainsString('entorno', (string) $envio->error);
        $this->assertStringContainsString('PDF', (string) $envio->adjuntos); // el ensayo sí armó los adjuntos
        // El estado fiscal del DTE no se toca en ningún caso.
        $this->assertSame(EstadoDte::Aceptado, $dte->refresh()->estado);
    }

    public function test_dte_a_contabilidad_fuera_de_produccion_no_cuenta_como_enviado(): void
    {
        Mail::fake();
        $dte = $this->ventaAceptada();
        $envio = $this->envioDte($dte, DteEnvio::CANAL_CONTABILIDAD);

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        Mail::assertNothingSent();
        $this->assertSame('simulado', $envio->refresh()->estado);

        // El filtro "Enviados a contabilidad" exige estado 'enviado': un simulado no entra.
        $this->actingAs(User::factory()->create()->assignRole('contabilidad'))
            ->get(route('facturacion.reporte-contadora', ['contabilidad' => 'enviados']))
            ->assertOk()
            ->assertDontSee($dte->numero_control);
    }

    public function test_dte_en_produccion_si_envia(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $dte = $this->ventaAceptada();
        $envio = $this->envioDte($dte);

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        Mail::assertSent(DteCorreo::class);
        $this->assertSame('enviado', $envio->refresh()->estado);
        $this->assertNull($envio->error);
    }

    public function test_dte_en_produccion_con_transporte_caido_queda_error(): void
    {
        $this->simularProduccionCorreo();
        $dte = $this->ventaAceptada();
        $envio = $this->envioDte($dte);

        // Falla REAL del transporte (Mail::fake nunca falla): el estado debe ser 'error'.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caído'));

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        $envio->refresh();
        $this->assertSame('error', $envio->estado);
        $this->assertStringContainsString('SMTP caído', (string) $envio->error);
    }

    public function test_autoenvio_del_observer_fuera_de_produccion_queda_simulado(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        Configuracion::set('correo.auto_envio', true);
        $this->seed(DatosInicialesNegritaSeeder::class);

        // DTE generado que pasa a aceptado: el observer encola el autoenvío.
        $dte = Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'cliente_id' => Cliente::factory()->create(['correo' => 'auto@cliente.com'])->id,
            'tipo_dte' => '03', 'estado' => EstadoDte::Generado->value, 'ambiente' => '01',
            'numero_control' => 'DTE-03-M001P001-000000000000777',
            'codigo_generacion' => (string) Str::uuid(),
            'fecha_emision' => now()->toDateString(), 'hora_emision' => now()->format('H:i:s'),
            'total_gravado' => 10.00, 'iva' => 1.30, 'total_pagar' => 11.30,
        ]);
        $ruta = 'dte/json/dte-03-'.$dte->id.'.json';
        Storage::disk('local')->put($ruta, '{"identificacion":{"x":1}}');
        $dte->json_generado_path = $ruta;
        $dte->sello_recepcion = '2026SELLOAUTO';
        $dte->fecha_procesamiento_mh = now();
        $dte->estado = EstadoDte::Aceptado;
        $dte->save();

        $envio = DteEnvio::where('dte_id', $dte->id)->firstOrFail();
        $this->assertNull($envio->user_id); // automático

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        Mail::assertNothingSent();
        $this->assertSame('simulado', $envio->refresh()->estado);
    }

    // ---------- Compras (documento recibido) ----------

    private function compra(): DocumentoRecibido
    {
        $ruta = 'documentos-recibidos/candado/factura.pdf';
        Storage::disk('local')->put($ruta, '%PDF-1.4 fake');

        return DocumentoRecibido::create([
            'gmail_message_id' => 'm-candado',
            'emisor_nombre' => 'PROVEEDOR CANDADO',
            'tipo_documento' => '03',
            'numero_control' => 'DTE-03-PROV-CANDADO',
            'estado' => 'pendiente',
            'clasificacion' => 'dte_valido',
            'total' => 50.00,
            'tiene_pdf' => true,
            'tiene_json' => false,
            'fecha_correo' => now(),
            'fecha_dte' => now()->toDateString(),
            'metadata_json' => ['archivos' => [$ruta]],
        ]);
    }

    public function test_compra_fuera_de_produccion_queda_simulada_y_pendiente(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        $doc = $this->compra();
        $envio = $doc->envios()->create([
            'destinatario' => self::CORREO_CONTA, 'destinatarios' => [self::CORREO_CONTA], 'estado' => 'pendiente',
        ]);

        (new EnviarDocumentoRecibidoContabilidad($envio->id))->handle(app(AdjuntosDocumentoRecibido::class));

        Mail::assertNothingSent();
        $envio->refresh();
        $this->assertSame('simulado', $envio->estado);
        $this->assertStringContainsString('entorno', (string) $envio->error);
        $this->assertSame('factura.pdf', $envio->adjuntos);
        $this->assertSame('pendiente', $doc->refresh()->estado); // NO se marca enviada
    }

    public function test_compra_en_produccion_si_envia_y_marca_enviada(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $doc = $this->compra();
        $envio = $doc->envios()->create([
            'destinatario' => self::CORREO_CONTA, 'destinatarios' => [self::CORREO_CONTA], 'estado' => 'pendiente',
        ]);

        (new EnviarDocumentoRecibidoContabilidad($envio->id))->handle(app(AdjuntosDocumentoRecibido::class));

        Mail::assertSent(DocumentoRecibidoContabilidadCorreo::class);
        $this->assertSame('enviado', $envio->refresh()->estado);
        $this->assertSame('enviado', $doc->refresh()->estado);
    }

    public function test_compra_en_produccion_con_transporte_caido_queda_error_y_pendiente(): void
    {
        $this->simularProduccionCorreo();
        $doc = $this->compra();
        $envio = $doc->envios()->create([
            'destinatario' => self::CORREO_CONTA, 'destinatarios' => [self::CORREO_CONTA], 'estado' => 'pendiente',
        ]);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caído'));

        (new EnviarDocumentoRecibidoContabilidad($envio->id))->handle(app(AdjuntosDocumentoRecibido::class));

        $this->assertSame('error', $envio->refresh()->estado);
        $this->assertSame('pendiente', $doc->refresh()->estado);
    }

    // ---------- Aviso en la interfaz ----------

    public function test_las_pantallas_de_compras_y_ventas_avisan_del_candado(): void
    {
        $texto = 'el correo se registrará como simulado y no se enviará realmente';
        $user = User::factory()->create()->assignRole('contabilidad');

        $this->actingAs($user)->get(route('documentos-recibidos.index'))->assertOk()->assertSee($texto);
        $this->actingAs($user)->get(route('facturacion.reporte-contadora'))->assertOk()->assertSee($texto);
    }

    public function test_en_produccion_no_se_muestra_el_aviso(): void
    {
        $this->simularProduccionCorreo();
        $user = User::factory()->create()->assignRole('contabilidad');

        $this->actingAs($user)->get(route('documentos-recibidos.index'))
            ->assertOk()
            ->assertDontSee('se registrará como simulado');
    }

    public function test_ningun_envio_simulado_deja_correos_en_la_cola_de_verdad(): void
    {
        // Garantía transversal de la suite: con el candado activo, ni un solo mailable
        // llega al transporte en ninguno de los tres flujos.
        Mail::fake();
        $dte = $this->ventaAceptada();
        (new EnviarDteCorreo($this->envioDte($dte)->id))->handle(app(DtePdfService::class));

        $doc = $this->compra();
        $envioCompra = $doc->envios()->create([
            'destinatario' => self::CORREO_CONTA, 'destinatarios' => [self::CORREO_CONTA], 'estado' => 'pendiente',
        ]);
        (new EnviarDocumentoRecibidoContabilidad($envioCompra->id))->handle(app(AdjuntosDocumentoRecibido::class));

        Mail::assertNothingSent();
        $this->assertSame('simulado', DteEnvio::firstOrFail()->estado);
        $this->assertSame('simulado', DocumentoRecibidoEnvio::firstOrFail()->estado);
    }
}
