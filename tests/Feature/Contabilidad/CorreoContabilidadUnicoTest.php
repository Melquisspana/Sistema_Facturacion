<?php

namespace Tests\Feature\Contabilidad;

use App\Models\Configuracion;
use App\Models\DocumentoRecibido;
use App\Models\User;
use App\Support\Contabilidad\CorreoContabilidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * D9 — el correo de contabilidad se resuelve en UN solo sitio.
 *
 * Antes había cuatro copias del mismo bloque de tres líneas:
 *
 *   PaqueteContabilidadController::correoContabilidad()
 *   DocumentoRecibidoController::correoContabilidad()
 *   ReporteContadoraController::correoContabilidad()
 *   EnviarDteCorreo::correoContabilidad()
 *
 * Y no eran idénticas: tres normalizaban a minúsculas y la del paquete mensual
 * no. Estos tests fijan que ahora las cuatro dan exactamente lo mismo, incluida
 * la normalización, y que el comportamiento observable —qué se envía y qué se
 * bloquea— es el de antes.
 *
 * Aquí NO sale ningún correo: Mail::fake() en todo lo que envía.
 */
class CorreoContabilidadUnicoTest extends TestCase
{
    use RefreshDatabase;

    private const CORREO = 'contabilidad@empresa.com';

    protected function setUp(): void
    {
        parent::setUp();
        Configuracion::olvidarCache();
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function resolver(): CorreoContabilidad
    {
        return app(CorreoContabilidad::class);
    }

    // ------------------------------------------------------- comportamiento base

    public function test_sin_configurar_no_hay_direccion(): void
    {
        $this->assertNull($this->resolver()->direccion());
        $this->assertFalse($this->resolver()->configurado());
        $this->assertNull($this->resolver()->copiaOculta());
    }

    public function test_una_direccion_invalida_se_trata_como_ausente(): void
    {
        Configuracion::set('contabilidad.correo', 'no-es-un-correo');

        $this->assertNull($this->resolver()->direccion());
    }

    public function test_la_direccion_se_normaliza(): void
    {
        Configuracion::set('contabilidad.correo', '  Contabilidad@Empresa.COM ');

        $this->assertSame(self::CORREO, $this->resolver()->direccion());
    }

    public function test_la_copia_oculta_exige_las_dos_condiciones(): void
    {
        Configuracion::set('contabilidad.correo', self::CORREO);

        Configuracion::set('contabilidad.enviar_copia', false);
        $this->assertNull($this->resolver()->copiaOculta(), 'Con la preferencia apagada no hay copia.');

        Configuracion::set('contabilidad.enviar_copia', true);
        $this->assertSame(self::CORREO, $this->resolver()->copiaOculta());

        Configuracion::set('contabilidad.correo', 'roto');
        $this->assertNull($this->resolver()->copiaOculta(), 'Con la preferencia activa pero correo inválido, tampoco.');
    }

    // --------------------------------------------------- los cuatro consumidores

    /**
     * Con una dirección escrita en MAYÚSCULAS los cuatro consumidores resuelven
     * ahora el mismo valor. Antes, el paquete de contabilidad devolvía otra cosa.
     */
    public function test_los_consumidores_resuelven_la_misma_direccion(): void
    {
        Configuracion::set('contabilidad.correo', 'Contabilidad@Empresa.COM');
        Configuracion::set('contabilidad.enviar_copia', true);
        $admin = $this->admin();

        // 1) Paquete de contabilidad (mensual).
        $paquete = $this->actingAs($admin)->get(route('contabilidad.paquete'))->assertOk();
        $this->assertSame(self::CORREO, $paquete->viewData('correoContabilidad'));

        // 2) Reporte de la contadora (ventas).
        $reporte = $this->actingAs($admin)->get(route('facturacion.reporte-contadora'))->assertOk();
        $this->assertSame(self::CORREO, $reporte->viewData('correoContabilidad'));

        // 3) y 4) Compras y copia oculta del DTE comparten el resolver.
        $this->assertSame(self::CORREO, $this->resolver()->direccion());
        $this->assertSame(self::CORREO, $this->resolver()->copiaOculta());
    }

    /**
     * Bloqueo por correo ausente: el envío de una compra a contabilidad sigue
     * avisando en vez de intentarlo, exactamente igual que antes.
     */
    public function test_compras_sigue_bloqueando_el_envio_sin_correo_valido(): void
    {
        Mail::fake();
        Configuracion::set('contabilidad.correo', 'invalido');

        $documento = DocumentoRecibido::create([
            'gmail_message_id' => 'm-corr-1',
            'emisor_nombre' => 'PROVEEDOR',
            'remitente' => 'proveedor@correo.com',
            'tipo_documento' => '03',
            'numero_control' => 'DTE-03-M001P001-000000000000001',
            'codigo_generacion' => 'CG-1',
            'estado' => 'pendiente',
            'clasificacion' => 'dte_valido',
            'total' => 112.00,
            'fecha_dte' => now()->toDateString(),
            'fecha_correo' => now(),
            'tiene_pdf' => false,
            'tiene_json' => false,
            'metadata_json' => ['archivos' => []],
        ]);

        $this->actingAs($this->admin())
            ->post(route('documentos-recibidos.enviar-contabilidad', $documento))
            ->assertRedirect()
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    /** Guardar desde la pantalla de Configuración sigue sin enviar nada. */
    public function test_guardar_la_configuracion_no_envia_correos(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())
            ->put(route('configuracion.contabilidad.update'), [
                'correo_contabilidad' => self::CORREO,
                'enviar_copia_contabilidad' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        Mail::assertNothingSent();

        Configuracion::olvidarCache();
        $this->assertSame(self::CORREO, $this->resolver()->direccion());
        $this->assertTrue($this->resolver()->enviarCopia());
    }

    /** El formulario sigue exigiendo un correo válido cuando se activa la copia. */
    public function test_activar_la_copia_sin_correo_valido_falla_la_validacion(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.contabilidad.update'), [
                'correo_contabilidad' => '',
                'enviar_copia_contabilidad' => '1',
            ])
            ->assertSessionHasErrors('correo_contabilidad');

        $this->assertNull($this->resolver()->direccion());
    }

    /** Los datos siguen viviendo en la tabla `configuraciones`: nada se migró. */
    public function test_el_valor_sigue_guardandose_en_la_tabla_de_siempre(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.contabilidad.update'), [
                'correo_contabilidad' => self::CORREO,
                'enviar_copia_contabilidad' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('configuraciones', ['clave' => 'contabilidad.correo', 'valor' => self::CORREO]);
        $this->assertDatabaseMissing('ajustes_sistema', ['clave' => 'contabilidad.correo']);
    }
}
