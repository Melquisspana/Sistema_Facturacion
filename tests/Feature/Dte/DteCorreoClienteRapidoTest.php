<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Jobs\EnviarDteCorreo;
use App\Mail\DteCorreo;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DtePdfService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Botón rápido "Enviar por correo" del CCF generado: envío de un clic al correo del
 * cliente/sala, reutilizando el pipeline encolado (PDF adjunto + historial DteEnvio).
 * Construye el estado "generado" sin pasar por DteGeneracionService::generar() (que exige
 * catálogos MH no seedeados en tests); aquí solo se prueba el envío por correo.
 */
class DteCorreoClienteRapidoTest extends TestCase
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
        Storage::fake('local');
        $this->seed(CatalogosMhSeeder::class);

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'nit' => '0614-000000-000-0', 'ambiente' => '00', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'activo' => true]);
        $this->pv = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** CCF con una línea; en borrador o "generado" (estado fijado sin generar()). */
    private function ccf(EstadoDte $estado, ?string $correo): Dte
    {
        $cliente = Cliente::factory()->contribuyente()->create(['correo' => $correo]);
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $b = app(DteBorradorService::class);
        $dte = $b->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ]);
        $b->agregarLineaDesdeProducto($dte, $producto, cantidad: 5);

        if ($estado !== EstadoDte::Borrador) {
            $dte->numero_control = 'DTE-03-M001P001-000000000000050';
            $dte->codigo_generacion = 'A1B2C3D4-E5F6-7A8B-9C0D-1E2F3A4B5C6D';
            $dte->json_generado_path = 'dte/json/dte-03-'.$dte->id.'.json';
            Storage::disk('local')->put($dte->json_generado_path, '{"identificacion":{"x":1}}');
            $dte->estado = $estado;
            Dte::withoutEvents(fn () => $dte->save()); // fixture: no probamos generación aquí
        }

        return $dte->refresh();
    }

    private function rutaCliente(Dte $dte): string
    {
        return route('facturacion.correo.cliente', $dte);
    }

    // --- UI: un solo control de envío ---

    public function test_no_muestra_seccion_de_correo_en_borrador(): void
    {
        $dte = $this->ccf(EstadoDte::Borrador, 'cliente@calleja.com');

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $dte))
            ->assertOk()
            ->assertDontSee('Correo del cliente')
            ->assertDontSee('Enviar correo');
    }

    public function test_solo_hay_un_boton_de_envio_y_no_en_el_encabezado(): void
    {
        $dte = $this->ccf(EstadoDte::Generado, 'cliente@calleja.com');

        $content = $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $dte))
            ->assertOk()
            ->getContent();

        // El botón de envío aparece UNA sola vez (en la sección "Correo del cliente").
        $this->assertSame(1, substr_count($content, 'Enviar correo'));
        // Ya no existe el link/acción rápida del encabezado superior.
        $this->assertStringNotContainsString('Enviar por correo', $content);
        $this->assertStringNotContainsString(route('facturacion.correo.cliente', $dte), $content);
    }

    public function test_si_ya_fue_enviado_muestra_badge_y_boton_reenviar(): void
    {
        $dte = $this->ccf(EstadoDte::Generado, 'cliente@calleja.com');
        $dte->envios()->create([
            'destinatario' => 'cliente@calleja.com', 'destinatarios' => ['cliente@calleja.com'],
            'estado' => 'enviado', 'adjuntos' => 'PDF, JSON',
        ]);

        $content = $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $dte->refresh()))
            ->assertOk()
            ->assertSee('Enviado por correo')   // badge en el encabezado
            ->assertSee('Reenviar y abrir PDF') // el botón principal pasa a reenviar
            ->getContent();

        // Ya enviado: el botón principal dice "Reenviar y abrir PDF", no "Enviar correo".
        $this->assertSame(0, substr_count($content, 'Enviar correo'));
    }

    // --- Flujo: al enviar, redirigir al PDF para imprimir ---

    public function test_enviar_correo_valido_encola_y_redirige_al_pdf(): void
    {
        Queue::fake();
        $dte = $this->ccf(EstadoDte::Generado, 'cliente@calleja.com');

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.correo.enviar', $dte), ['destinatarios' => 'cliente@calleja.com'])
            ->assertRedirect(route('facturacion.pdf', $dte)) // redirect directo al PDF (misma pestaña)
            ->assertSessionHas('status');

        // Encola (no espera al SMTP): el job queda en la cola, no se ejecuta en la request.
        Queue::assertPushed(EnviarDteCorreo::class);
    }

    public function test_correo_invalido_no_redirige_al_pdf(): void
    {
        Queue::fake();
        $dte = $this->ccf(EstadoDte::Generado, 'cliente@calleja.com');

        $resp = $this->actingAs($this->usuario('facturacion'))
            ->from(route('facturacion.show', $dte))
            ->post(route('facturacion.correo.enviar', $dte), ['destinatarios' => 'no-es-correo']);

        $resp->assertSessionHasErrors('destinatarios');
        $resp->assertRedirect(route('facturacion.show', $dte)); // vuelve a la ficha, NO al PDF
        Queue::assertNothingPushed();
    }

    public function test_el_boton_de_la_ficha_dice_abrir_pdf(): void
    {
        $dte = $this->ccf(EstadoDte::Generado, 'cliente@calleja.com');

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $dte))
            ->assertOk()
            ->assertSee('Enviar correo y abrir PDF');
    }

    // --- Comportamiento del envío (ruta rápida correo.cliente; backend sin cambios) ---

    public function test_bloquea_si_no_hay_correo(): void
    {
        Queue::fake();
        $dte = $this->ccf(EstadoDte::Generado, null);

        $this->actingAs($this->usuario('facturacion'))
            ->post($this->rutaCliente($dte))
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('dte_envios', 0);
    }

    public function test_envia_al_correo_del_cliente_y_encola_registrando_quien(): void
    {
        Queue::fake();
        $dte = $this->ccf(EstadoDte::Generado, 'cliente@calleja.com');
        $user = $this->usuario('facturacion');

        $this->actingAs($user)->post($this->rutaCliente($dte))->assertRedirect();

        Queue::assertPushed(EnviarDteCorreo::class);
        $envio = DteEnvio::where('dte_id', $dte->id)->firstOrFail();
        $this->assertSame('pendiente', $envio->estado);
        $this->assertSame(['cliente@calleja.com'], $envio->destinatarios);
        $this->assertSame($user->id, $envio->user_id);   // por quién
        $this->assertNotNull($envio->created_at);         // cuándo
    }

    public function test_prefiere_el_correo_de_la_sala_sobre_el_del_cliente(): void
    {
        Queue::fake();
        $dte = $this->ccf(EstadoDte::Generado, 'cliente@calleja.com');
        $sala = \App\Models\ClienteSucursal::factory()->create([
            'cliente_id' => $dte->cliente_id, 'correo' => 'sala@calleja.com',
        ]);
        $dte->cliente_sucursal_id = $sala->id;
        Dte::withoutEvents(fn () => $dte->save());

        $this->actingAs($this->usuario('facturacion'))->post($this->rutaCliente($dte->refresh()))->assertRedirect();

        $this->assertSame(['sala@calleja.com'], DteEnvio::where('dte_id', $dte->id)->firstOrFail()->destinatarios);
    }

    public function test_envia_email_con_pdf_adjunto(): void
    {
        config(['mail.default' => 'smtp']); // mailer real → estado 'enviado'
        Mail::fake();
        Queue::fake();
        $dte = $this->ccf(EstadoDte::Generado, 'cliente@calleja.com');

        $this->actingAs($this->usuario('facturacion'))->post($this->rutaCliente($dte))->assertRedirect();

        // Ejecuta el job encolado y verifica el adjunto PDF.
        $envio = DteEnvio::where('dte_id', $dte->id)->firstOrFail();
        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        Mail::assertSent(DteCorreo::class, fn ($m) => $m->hasTo('cliente@calleja.com'));
        $envio->refresh();
        $this->assertSame('enviado', $envio->estado);
        $this->assertStringContainsString('PDF', (string) $envio->adjuntos);
    }

    public function test_no_duplica_si_ya_hay_envio_pendiente_al_mismo_correo(): void
    {
        Queue::fake();
        $dte = $this->ccf(EstadoDte::Generado, 'cliente@calleja.com');
        $dte->envios()->create(['destinatario' => 'cliente@calleja.com', 'destinatarios' => ['cliente@calleja.com'], 'estado' => 'pendiente']);

        $this->actingAs($this->usuario('facturacion'))->post($this->rutaCliente($dte))->assertRedirect();

        $this->assertSame(1, DteEnvio::where('dte_id', $dte->id)->count());
        Queue::assertNothingPushed();
    }

    // --- Permisos ---

    public function test_borrador_no_puede_enviar_por_la_ruta(): void
    {
        Queue::fake();
        $dte = $this->ccf(EstadoDte::Borrador, 'cliente@calleja.com');

        $this->actingAs($this->usuario('facturacion'))->post($this->rutaCliente($dte))->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_consulta_no_puede_enviar(): void
    {
        Queue::fake();
        $dte = $this->ccf(EstadoDte::Generado, 'cliente@calleja.com');

        $this->actingAs($this->usuario('consulta'))->post($this->rutaCliente($dte))->assertForbidden();
        Queue::assertNothingPushed();
    }
}
