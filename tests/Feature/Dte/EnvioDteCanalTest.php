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
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DtePdfService;
use App\Services\Dte\EnvioDteCorreoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Canal del envío por correo (dte_envios.canal): todos los envíos actuales van por el
 * canal 'cliente' y el anti-duplicado está aislado por canal. Los envíos históricos
 * (canal NULL) se siguen leyendo y procesando como envíos al cliente, sin backfill.
 *
 * Todavía NO existe un botón de envío a contabilidad: ese canal se prueba llamando al
 * servicio compartido directamente.
 */
class EnvioDteCanalTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Configuracion::olvidarCache();
        Storage::fake('local');
        $this->seedCatalogosDte();

        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
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

        app(DteGeneracionService::class)->generar($dte);
        $dte->refresh();
        $dte->numero_control = 'DTE-03-M001P001-000000000000048';
        $dte->codigo_generacion = 'A1B2C3D4-E5F6-7A8B-9C0D-1E2F3A4B5C6D';
        $dte->json_generado_path = 'dte/json/dte-03-'.$dte->id.'-'.$dte->codigo_generacion.'.json';
        Storage::disk('local')->put($dte->json_generado_path, '{"identificacion":{"x":1}}');

        if ($estado === EstadoDte::Aceptado) {
            $dte->sello_recepcion = 'SELLO-OK-123';
            $dte->estado = EstadoDte::Aceptado;
        }
        $dte->save();

        return $dte->refresh();
    }

    private function servicio(): EnvioDteCorreoService
    {
        return app(EnvioDteCorreoService::class);
    }

    // --- Los flujos actuales al cliente guardan canal='cliente' ---

    public function test_envio_manual_guarda_canal_cliente(): void
    {
        Queue::fake();
        $dte = $this->ccf();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.correo.enviar', $dte), ['destinatarios' => 'cliente@calleja.com'])
            ->assertRedirect(route('facturacion.pdf', $dte));

        Queue::assertPushed(EnviarDteCorreo::class);
        $envio = DteEnvio::where('dte_id', $dte->id)->firstOrFail();
        $this->assertSame(DteEnvio::CANAL_CLIENTE, $envio->canal);
        $this->assertSame('pendiente', $envio->estado);
    }

    public function test_envio_rapido_al_cliente_guarda_canal_cliente(): void
    {
        Queue::fake();
        $dte = $this->ccf();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.correo.cliente', $dte))
            ->assertRedirect();

        $envio = DteEnvio::where('dte_id', $dte->id)->firstOrFail();
        $this->assertSame(DteEnvio::CANAL_CLIENTE, $envio->canal);
        $this->assertSame(['cliente@calleja.com'], $envio->destinatarios);
    }

    public function test_reenvio_conserva_canal_cliente(): void
    {
        Queue::fake();
        $dte = $this->ccf();
        $previo = $dte->envios()->create([
            'destinatario' => 'cliente@calleja.com', 'destinatarios' => ['cliente@calleja.com'],
            'canal' => DteEnvio::CANAL_CLIENTE, 'estado' => 'enviado', 'adjuntos' => 'PDF, JSON',
        ]);

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.correo.reenviar', [$dte, $previo]))
            ->assertRedirect();

        $nuevo = DteEnvio::where('dte_id', $dte->id)->where('estado', 'pendiente')->firstOrFail();
        $this->assertSame(DteEnvio::CANAL_CLIENTE, $nuevo->canal);
        $this->assertSame(['cliente@calleja.com'], $nuevo->destinatarios);
    }

    public function test_auto_envio_del_observer_guarda_canal_cliente_y_usuario_nulo(): void
    {
        Queue::fake();
        Configuracion::set('correo.auto_envio', true);
        $dte = $this->ccf(EstadoDte::Generado, 'auto@cliente.com');

        // Simula la aceptación por MH (transición de estado).
        $dte->sello_recepcion = 'SELLO-AUTO';
        $dte->estado = EstadoDte::Aceptado;
        $dte->save();

        Queue::assertPushed(EnviarDteCorreo::class);
        $envio = DteEnvio::where('dte_id', $dte->id)->firstOrFail();
        $this->assertSame(DteEnvio::CANAL_CLIENTE, $envio->canal);
        $this->assertNull($envio->user_id); // automático (sistema)
        $this->assertSame(['auto@cliente.com'], $envio->destinatarios);
    }

    // --- Anti-duplicado aislado por canal ---

    public function test_pendiente_cliente_bloquea_duplicado_del_mismo_canal(): void
    {
        Queue::fake();
        $dte = $this->ccf();
        $this->servicio()->encolar($dte, ['cliente@calleja.com'], null, DteEnvio::CANAL_CLIENTE);

        $duplicado = $this->servicio()->encolar($dte->refresh(), ['cliente@calleja.com'], null, DteEnvio::CANAL_CLIENTE);

        $this->assertNull($duplicado);
        $this->assertSame(1, DteEnvio::where('dte_id', $dte->id)->count());
        Queue::assertPushed(EnviarDteCorreo::class, 1);
    }

    public function test_pendiente_cliente_no_bloquea_envio_a_contabilidad(): void
    {
        Queue::fake();
        $dte = $this->ccf();
        $this->servicio()->encolar($dte, ['cliente@calleja.com'], null, DteEnvio::CANAL_CLIENTE);

        $contabilidad = $this->servicio()->encolar($dte->refresh(), ['cliente@calleja.com'], null, DteEnvio::CANAL_CONTABILIDAD);

        $this->assertNotNull($contabilidad);
        $this->assertSame(DteEnvio::CANAL_CONTABILIDAD, $contabilidad->canal);
        $this->assertSame(2, DteEnvio::where('dte_id', $dte->id)->count());
        Queue::assertPushed(EnviarDteCorreo::class, 2);
    }

    public function test_pendiente_contabilidad_no_bloquea_envio_al_cliente(): void
    {
        Queue::fake();
        $dte = $this->ccf();
        $this->servicio()->encolar($dte, ['cliente@calleja.com'], null, DteEnvio::CANAL_CONTABILIDAD);

        $cliente = $this->servicio()->encolar($dte->refresh(), ['cliente@calleja.com'], null, DteEnvio::CANAL_CLIENTE);

        $this->assertNotNull($cliente);
        $this->assertSame(DteEnvio::CANAL_CLIENTE, $cliente->canal);
        $this->assertSame(2, DteEnvio::where('dte_id', $dte->id)->count());
    }

    public function test_pendiente_a_otros_destinatarios_no_bloquea_el_mismo_canal(): void
    {
        Queue::fake();
        $dte = $this->ccf();
        $this->servicio()->encolar($dte, ['cliente@calleja.com'], null, DteEnvio::CANAL_CLIENTE);

        $otro = $this->servicio()->encolar($dte->refresh(), ['compras@calleja.com'], null, DteEnvio::CANAL_CLIENTE);

        $this->assertNotNull($otro);
        $this->assertSame(2, DteEnvio::where('dte_id', $dte->id)->count());
    }

    public function test_canal_invalido_no_encola_nada(): void
    {
        Queue::fake();
        $dte = $this->ccf();

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->servicio()->encolar($dte, ['cliente@calleja.com'], null, 'proveedor');
        } finally {
            $this->assertDatabaseCount('dte_envios', 0);
            Queue::assertNothingPushed();
        }
    }

    // --- Compatibilidad con envíos históricos (canal NULL, sin backfill) ---

    public function test_pendiente_historico_sin_canal_bloquea_al_cliente_pero_no_a_contabilidad(): void
    {
        Queue::fake();
        $dte = $this->ccf();
        // Envío previo a la columna `canal`: se guardó sin canal (NULL).
        $historico = $dte->envios()->create([
            'destinatario' => 'cliente@calleja.com', 'destinatarios' => ['cliente@calleja.com'],
            'estado' => 'pendiente',
        ]);
        $this->assertNull($historico->canal);

        $this->assertNull($this->servicio()->encolar($dte->refresh(), ['cliente@calleja.com'], null, DteEnvio::CANAL_CLIENTE));
        $this->assertNotNull($this->servicio()->encolar($dte->refresh(), ['cliente@calleja.com'], null, DteEnvio::CANAL_CONTABILIDAD));

        $this->assertSame(2, DteEnvio::where('dte_id', $dte->id)->count());
    }

    public function test_envio_historico_sin_canal_se_lee_como_cliente(): void
    {
        $dte = $this->ccf();
        $historico = $dte->envios()->create([
            'destinatario' => 'cliente@calleja.com', 'destinatarios' => ['cliente@calleja.com'],
            'estado' => 'enviado', 'adjuntos' => 'PDF, JSON',
        ]);

        $this->assertNull($historico->canal);
        $this->assertSame(DteEnvio::CANAL_CLIENTE, $historico->canalEfectivo());
        $this->assertFalse($historico->esCanalContabilidad());
    }

    public function test_job_procesa_un_envio_historico_sin_canal(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $dte = $this->ccf();
        $historico = $dte->envios()->create([
            'destinatario' => 'cliente@calleja.com', 'destinatarios' => ['cliente@calleja.com'],
            'estado' => 'pendiente',
        ]);

        (new EnviarDteCorreo($historico->id))->handle(app(DtePdfService::class));

        Mail::assertSent(DteCorreo::class, fn ($m) => $m->hasTo('cliente@calleja.com'));
        $historico->refresh();
        $this->assertSame('enviado', $historico->estado);
        $this->assertNull($historico->canal); // el job no inventa canal: sigue histórico
        $this->assertStringContainsString('PDF', (string) $historico->adjuntos);
    }

    public function test_historial_de_la_ficha_muestra_un_envio_sin_canal(): void
    {
        $dte = $this->ccf();
        $dte->envios()->create([
            'destinatario' => 'cliente@calleja.com', 'destinatarios' => ['cliente@calleja.com'],
            'estado' => 'enviado', 'adjuntos' => 'PDF, JSON',
        ]);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $dte->refresh()))
            ->assertOk()
            ->assertSee('cliente@calleja.com');
    }
}
