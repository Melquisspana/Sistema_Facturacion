<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Jobs\EnviarDteCorreo;
use App\Mail\DteCorreo;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DtePdfService;
use App\Support\Dte\PlantillaCorreo;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Envío de DTE por correo a nivel producción: ENCOLADO (no espera al SMTP), múltiples
 * destinatarios, plantilla configurable, adjuntos (PDF/JSON/JWS), historial, reenviar
 * y auto-envío al ser aceptado por MH. No transmite a Hacienda.
 */
class DteEnvioCorreoTest extends TestCase
{
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Configuracion::olvidarCache();
        Storage::fake('local');
        $this->seed(CatalogosMhSeeder::class);

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'nit' => '0614-000000-000-0', 'ambiente' => '00', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'activo' => true]);
        $this->pv = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function ccf(EstadoDte $estado = EstadoDte::Aceptado, string $correoCliente = 'cliente@calleja.com'): Dte
    {
        $cliente = Cliente::factory()->contribuyente()->create(['correo' => $correoCliente]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $b = app(DteBorradorService::class);
        $dte = $b->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ]);
        $b->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);

        if ($estado === EstadoDte::Borrador) {
            return $dte->refresh();
        }

        app(DteGeneracionService::class)->generar($dte);
        $dte->refresh();
        $dte->numero_control = 'DTE-03-M001P001-000000000000048';
        $dte->codigo_generacion = 'A1B2C3D4-E5F6-7A8B-9C0D-1E2F3A4B5C6D';
        $dte->json_generado_path = 'dte/json/dte-03-'.$dte->id.'.json';
        Storage::disk('local')->put($dte->json_generado_path, '{"identificacion":{"x":1}}');

        if ($estado === EstadoDte::Aceptado) {
            $dte->sello_recepcion = 'SELLO-OK-123';
            $dte->estado = EstadoDte::Aceptado;
        }
        $dte->save();

        return $dte->refresh();
    }

    public function test_envio_manual_encola_y_registra_pendiente_con_multiples(): void
    {
        Queue::fake();
        $dte = $this->ccf();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.correo.enviar', $dte), [
                'destinatarios' => "cliente@correo.com, contabilidad@empresa.com\ncompras@empresa.com",
            ])
            ->assertRedirect();

        Queue::assertPushed(EnviarDteCorreo::class);
        $envio = DteEnvio::where('dte_id', $dte->id)->first();
        $this->assertSame('pendiente', $envio->estado);
        $this->assertSame(['cliente@correo.com', 'contabilidad@empresa.com', 'compras@empresa.com'], $envio->destinatarios);
    }

    public function test_job_envia_y_marca_enviado_con_adjuntos(): void
    {
        config(['mail.default' => 'smtp']); // mailer REAL → estado 'enviado'
        Mail::fake();
        $dte = $this->ccf();
        $envio = $dte->envios()->create([
            'destinatario' => 'a@a.com', 'destinatarios' => ['a@a.com', 'b@b.com'], 'estado' => 'pendiente',
        ]);

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        Mail::assertSent(DteCorreo::class, fn ($m) => $m->hasTo('a@a.com') && $m->hasTo('b@b.com'));
        $envio->refresh();
        $this->assertSame('enviado', $envio->estado);
        $this->assertNull($envio->error);
        $this->assertStringContainsString('PDF', $envio->adjuntos);
        $this->assertStringContainsString('JSON', $envio->adjuntos);
    }

    public function test_job_con_mailer_log_queda_simulado_no_enviado(): void
    {
        config(['mail.default' => 'log']); // mailer NO real → no sale por SMTP
        Mail::fake();
        $dte = $this->ccf();
        $envio = $dte->envios()->create([
            'destinatario' => 'a@a.com', 'destinatarios' => ['a@a.com'], 'estado' => 'pendiente',
        ]);

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        $envio->refresh();
        $this->assertSame('simulado', $envio->estado);
        $this->assertFalse($envio->fueExitoso());
        $this->assertTrue($envio->esSimulado());
        $this->assertStringContainsString('MAIL_MAILER=log', (string) $envio->error);
        $this->assertStringContainsString('PDF', $envio->adjuntos); // los adjuntos sí se generan
    }

    public function test_job_marca_error_si_smtp_falla(): void
    {
        config(['mail.default' => 'smtp']);
        $dte = $this->ccf();
        $envio = $dte->envios()->create([
            'destinatario' => 'a@a.com', 'destinatarios' => ['a@a.com'], 'estado' => 'pendiente',
        ]);

        // Simula una caída de SMTP en el envío.
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP caído: Connection refused'));

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        $envio->refresh();
        $this->assertSame('error', $envio->estado);
        $this->assertStringContainsString('SMTP caído', (string) $envio->error);
    }

    public function test_no_duplica_envio_si_ya_hay_pendiente_mismos_destinatarios(): void
    {
        Queue::fake();
        $dte = $this->ccf();
        $dte->envios()->create([
            'destinatario' => 'a@a.com', 'destinatarios' => ['a@a.com'], 'estado' => 'pendiente',
        ]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.correo.enviar', $dte), ['destinatarios' => 'A@A.com']) // mismo set (case-insensitive)
            ->assertRedirect();

        $this->assertSame(1, DteEnvio::where('dte_id', $dte->id)->count()); // NO se duplicó
        Queue::assertNothingPushed();
    }

    public function test_destinatarios_invalidos_no_encolan(): void
    {
        Queue::fake();
        $dte = $this->ccf();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.correo.enviar', $dte), ['destinatarios' => 'no-es-correo'])
            ->assertSessionHasErrors('destinatarios');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('dte_envios', 0);
    }

    public function test_reenviar_encola_nuevo_envio(): void
    {
        Queue::fake();
        $dte = $this->ccf();
        $envio = $dte->envios()->create([
            'destinatario' => 'x@x.com', 'destinatarios' => ['x@x.com'], 'estado' => 'error', 'error' => 'SMTP caído',
        ]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.correo.reenviar', [$dte, $envio]))
            ->assertRedirect();

        Queue::assertPushed(EnviarDteCorreo::class);
        $this->assertSame(2, DteEnvio::where('dte_id', $dte->id)->count()); // el viejo + el nuevo pendiente
    }

    public function test_borrador_y_consulta_no_pueden_enviar(): void
    {
        Queue::fake();
        $borrador = $this->ccf(EstadoDte::Borrador);
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.correo.enviar', $borrador), ['destinatarios' => 'x@x.com'])
            ->assertForbidden();

        $aceptado = $this->ccf();
        $this->actingAs($this->usuario('consulta'))
            ->post(route('facturacion.correo.enviar', $aceptado), ['destinatarios' => 'x@x.com'])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_auto_envio_al_aceptar_si_esta_activado(): void
    {
        Queue::fake();
        Configuracion::set('correo.auto_envio', true);
        $dte = $this->ccf(EstadoDte::Generado, 'auto@cliente.com');

        // Simula la aceptación por MH (transición de estado).
        $dte->sello_recepcion = 'SELLO-AUTO';
        $dte->estado = EstadoDte::Aceptado;
        $dte->save();

        Queue::assertPushed(EnviarDteCorreo::class);
        $envio = DteEnvio::where('dte_id', $dte->id)->first();
        $this->assertNotNull($envio);
        $this->assertNull($envio->user_id);                 // automático
        $this->assertSame(['auto@cliente.com'], $envio->destinatarios);
    }

    public function test_no_auto_envia_si_esta_desactivado(): void
    {
        Queue::fake();
        Configuracion::set('correo.auto_envio', false);
        $dte = $this->ccf(EstadoDte::Generado);

        $dte->sello_recepcion = 'SELLO';
        $dte->estado = EstadoDte::Aceptado;
        $dte->save();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('dte_envios', 0);
    }

    public function test_configuracion_correo_se_guarda(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->put(route('configuracion.correo.update'), [
                'auto_envio' => '1', 'adjuntar_jws' => '1', 'plantilla' => 'Hola {{cliente}}',
            ])
            ->assertRedirect();

        Configuracion::olvidarCache();
        $this->assertTrue(Configuracion::getBool('correo.auto_envio'));
        $this->assertTrue(Configuracion::getBool('correo.adjuntar_jws'));
        $this->assertSame('Hola {{cliente}}', Configuracion::get('correo.plantilla'));
    }

    public function test_plantilla_renderiza_variables(): void
    {
        $dte = $this->ccf();
        $cuerpo = PlantillaCorreo::render('Doc {{numero_control}} total {{total}} para {{cliente}}', $dte);

        $this->assertStringContainsString('DTE-03-M001P001-000000000000048', $cuerpo);
        $this->assertStringContainsString('$'.number_format((float) $dte->total_pagar, 2), $cuerpo);
        $this->assertStringNotContainsString('{{', $cuerpo);
    }
}
