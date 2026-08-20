<?php

namespace Tests\Feature\Configuracion;

use App\Facades\Ajustes;
use App\Jobs\EnviarDteCorreo;
use App\Mail\DteCorreo;
use App\Models\Configuracion;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Models\Establecimiento;
use App\Models\User;
use App\Services\Dte\DtePdfService;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Configuración de correo de contabilidad + copia BCC en el envío manual de DTE.
 * Guardar la config NO envía nada; la copia solo viaja como BCC dentro del envío
 * existente y jamás sale de verdad en tests (Mail::fake). No toca emisión.
 */
class ContabilidadCorreoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Configuracion::olvidarCache();
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    public function test_por_defecto_enviar_copia_contabilidad_es_false(): void
    {
        $this->assertFalse(Ajustes::bool('contabilidad.enviar_copia', false));
    }

    public function test_guardar_configuracion_no_envia_ningun_correo(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())
            ->put(route('configuracion.contabilidad.update'), [
                'correo_contabilidad' => 'contabilidad@empresa.com',
                'enviar_copia_contabilidad' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        Configuracion::olvidarCache();
        // Desde la fase 4 estas claves viven en `ajustes_sistema`; se consultan por
        // la API que las resuelve, no por la tabla en la que estaban antes.
        $this->assertSame('contabilidad@empresa.com', Ajustes::texto('contabilidad.correo'));
        $this->assertTrue(Ajustes::bool('contabilidad.enviar_copia'));

        // Guardar NO manda correos.
        Mail::assertNothingSent();
    }

    public function test_activar_copia_exige_un_correo_valido(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.contabilidad.update'), [
                'correo_contabilidad' => '',
                'enviar_copia_contabilidad' => '1',
            ])
            ->assertSessionHasErrors('correo_contabilidad');
    }

    public function test_el_envio_agrega_bcc_a_contabilidad_cuando_esta_activo_sin_enviar_real(): void
    {
        $this->simularProduccionCorreo(); // el BCC solo viaja cuando el envío es real
        Mail::fake();
        $this->seed(DatosInicialesNegritaSeeder::class);
        Ajustes::guardarComoSistema('contabilidad.correo', 'contabilidad@empresa.com');
        Ajustes::guardarComoSistema('contabilidad.enviar_copia', true);

        $envio = $this->crearEnvioPendiente();
        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        // El correo se "envía" solo al fake: con BCC a contabilidad y sin salir de verdad.
        Mail::assertSent(DteCorreo::class, function ($mail) {
            return $mail->hasTo('cliente@ejemplo.com') && $mail->hasBcc('contabilidad@empresa.com');
        });
    }

    public function test_sin_activar_la_copia_no_hay_bcc(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $this->seed(DatosInicialesNegritaSeeder::class);
        Ajustes::guardarComoSistema('contabilidad.enviar_copia', false);

        $envio = $this->crearEnvioPendiente();
        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        Mail::assertSent(DteCorreo::class, fn ($mail) => ! $mail->hasBcc('contabilidad@empresa.com'));
    }

    private function crearEnvioPendiente(): DteEnvio
    {
        $dte = Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'numero_control' => 'DTE-03-M001P001-000000000001100',
            'codigo_generacion' => (string) \Illuminate\Support\Str::uuid(),
            'sello_recepcion' => '2026SELLOREAL1234',
            'fecha_emision' => now(),
            'hora_emision' => now()->format('H:i:s'),
            'total_pagar' => 100.00,
        ]);

        return $dte->envios()->create([
            'destinatario' => 'cliente@ejemplo.com',
            'destinatarios' => ['cliente@ejemplo.com'],
            'estado' => 'pendiente',
        ]);
    }
}
