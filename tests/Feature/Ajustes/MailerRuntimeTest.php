<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\Adaptadores\AdaptadorConfiguraciones;
use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\AuditoriaAjustes;
use App\Ajustes\CatalogoAjustes;
use App\Ajustes\ConversorValor;
use App\Ajustes\Correo\ConfiguracionCorreoRuntime;
use App\Ajustes\RepositorioAjustes;
use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Facades\Ajustes;
use App\Jobs\EnviarDteCorreo;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\DteEnvio;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DtePdfService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * EL PROBLEMA DEL PROCESO LARGO.
 *
 * Laravel lee config/mail.php una vez al arrancar y el MailManager cachea cada
 * mailer que construye. En una petición web da igual —el proceso muere al
 * terminar—, pero el worker de colas vive horas: construye el transporte con el
 * primer correo del día y se queda con él. Sin lo que se prueba aquí, un
 * administrador cambia el servidor SMTP, la pantalla dice "guardado", y los
 * documentos de la tarde siguen saliendo por el servidor viejo.
 *
 * Ningún test de este archivo envía correo: Mail::fake() donde hay envío, y la
 * comprobación del transporte solo lo CONSTRUYE (no lo arranca, así que no abre
 * ningún socket).
 */
class MailerRuntimeTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('administrador', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function runtime(): ConfiguracionCorreoRuntime
    {
        return app(ConfiguracionCorreoRuntime::class);
    }

    /** DSN del transporte SMTP ya construido: «smtp://host:puerto». Sin credenciales. */
    private function dsnDelTransporte(): string
    {
        return (string) app('mail.manager')->mailer('smtp')->getSymfonyTransport();
    }

    // ------------------------------------------------------------- volcado

    public function test_aplicar_vuelca_los_overrides_sobre_la_configuracion(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar('mail.smtp.host', 'smtp.nuevo.com');
        Ajustes::guardar('mail.smtp.port', 2587);
        Ajustes::guardar('mail.smtp.username', 'usuario@nuevo.com');
        Ajustes::guardar('mail.from.address', 'facturacion@nuevo.com');
        Ajustes::guardar('mail.from.name', 'La Negrita');

        $this->runtime()->aplicar();

        $this->assertSame('smtp.nuevo.com', config('mail.mailers.smtp.host'));
        $this->assertSame(2587, config('mail.mailers.smtp.port'));
        $this->assertSame('usuario@nuevo.com', config('mail.mailers.smtp.username'));
        $this->assertSame('facturacion@nuevo.com', config('mail.from.address'));
        $this->assertSame('La Negrita', config('mail.from.name'));
    }

    public function test_sin_overrides_aplicar_no_cambia_nada(): void
    {
        config([
            'mail.mailers.smtp.host' => 'smtp.delenv.com',
            'mail.mailers.smtp.port' => 587,
        ]);

        $this->runtime()->aplicar();

        $this->assertSame('smtp.delenv.com', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
    }

    /** «Automática» significa NO fijar el scheme: que lo derive Laravel del puerto. */
    public function test_la_seguridad_automatica_deja_el_scheme_sin_fijar(): void
    {
        $this->actingAs($this->admin());
        Ajustes::guardar('mail.smtp.scheme', 'auto');

        $this->runtime()->aplicar();

        $this->assertNull(config('mail.mailers.smtp.scheme'));
    }

    public function test_la_seguridad_explicita_si_fija_el_scheme(): void
    {
        $this->actingAs($this->admin());
        Ajustes::guardar('mail.smtp.scheme', 'smtps');

        $this->runtime()->aplicar();

        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
    }

    /**
     * NO decide si el correo sale de verdad. El transporte por defecto lo gobiernan
     * el .env, la segunda barrera de AppServiceProvider y CandadoCorreoReal; que
     * esta capa lo tocara sería una cuarta autoridad sobre el interruptor más
     * peligroso del módulo.
     */
    public function test_no_toca_el_medio_de_envio_por_defecto(): void
    {
        config(['mail.default' => 'log']);
        $this->actingAs($this->admin());

        $this->runtime()->aplicar();

        $this->assertSame('log', config('mail.default'));
    }

    // ------------------------------------------------------------ transporte

    /** El transporte se reconstruye: no basta con cambiar la configuración. */
    public function test_el_transporte_se_reconstruye_con_el_servidor_nuevo(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.viejo.com', 'mail.mailers.smtp.port' => 587]);
        $this->actingAs($this->admin());

        $this->runtime()->aplicar();
        $antes = $this->dsnDelTransporte();
        $this->assertStringContainsString('smtp.viejo.com', $antes);

        Ajustes::guardar('mail.smtp.host', 'smtp.nuevo.com');
        $this->runtime()->aplicar();

        $this->assertStringContainsString('smtp.nuevo.com', $this->dsnDelTransporte());
    }

    /**
     * EL CASO DEL WORKER, en un solo proceso: se construye el transporte, "otro
     * proceso" cambia el servidor, y el siguiente envío usa el nuevo SIN reiniciar.
     *
     * El cambio se hace desde una instancia INDEPENDIENTE del resolver, con su
     * propia memoria y la misma caché compartida: es la relación real entre la
     * petición web que guarda y el worker que envía.
     */
    public function test_dos_envios_seguidos_usan_configuraciones_distintas_sin_reiniciar(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.inicial.com']);
        $this->actingAs($this->admin());

        // --- Primer envío del "worker" ---
        $this->runtime()->aplicar();
        $this->assertStringContainsString('smtp.inicial.com', $this->dsnDelTransporte());

        // --- La "petición web" guarda otro servidor ---
        $this->otroProceso()->guardarComoSistema('mail.smtp.host', 'smtp.cambiado.com');

        // --- Segundo envío del MISMO worker, sin reiniciar nada ---
        $this->runtime()->aplicar();
        $this->assertStringContainsString('smtp.cambiado.com', $this->dsnDelTransporte());
    }

    // --------------------------------------------------------------- cola

    /**
     * El enganche que hace todo lo anterior automático en el worker: antes de cada
     * trabajo se vuelca la configuración vigente.
     */
    public function test_el_listener_de_la_cola_aplica_la_configuracion(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.viejo.com']);
        $this->actingAs($this->admin());

        Ajustes::guardar('mail.smtp.host', 'smtp.desde-la-cola.com');
        $this->assertSame('smtp.viejo.com', config('mail.mailers.smtp.host'));

        // El listener ignora sus argumentos; lo que se prueba es que está enganchado.
        $trabajo = Mockery::mock(Job::class);
        // Laravel engancha su propio listener a JobProcessing (contexto de logs) y
        // le pide el payload: sin esto el doble revienta antes de llegar al nuestro.
        $trabajo->shouldReceive('payload')->andReturn([]);

        event(new JobProcessing('sync', $trabajo));

        $this->assertSame('smtp.desde-la-cola.com', config('mail.mailers.smtp.host'));
    }

    /** Y el job de correo lo pide también por su cuenta, sin depender del listener. */
    public function test_el_job_de_correo_aplica_la_configuracion_antes_de_enviar(): void
    {
        Mail::fake();
        config(['mail.mailers.smtp.host' => 'smtp.viejo.com']);

        $admin = $this->admin();
        $this->actingAs($admin);
        Ajustes::guardar('mail.smtp.host', 'smtp.del-job.com');

        // Se devuelve la configuración a su estado "de arranque del proceso" para
        // demostrar que es el JOB quien la actualiza, no el guardado anterior.
        config(['mail.mailers.smtp.host' => 'smtp.viejo.com']);

        $envio = $this->envioDeCorreo($admin);
        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        $this->assertSame('smtp.del-job.com', config('mail.mailers.smtp.host'));
        Mail::assertNothingSent();
    }

    // ---------------------------------------------------------------- estado

    /** El estado que se publica no incluye la contraseña, solo si la hay. */
    public function test_el_estado_actual_no_incluye_la_contrasena(): void
    {
        $this->actingAs($this->admin());
        Ajustes::guardar('mail.smtp.password', 'secreto-del-runtime');

        $estado = $this->runtime()->estadoActual();

        $this->assertTrue($estado['password_configurada']);
        $this->assertStringNotContainsString('secreto-del-runtime', json_encode($estado, JSON_UNESCAPED_UNICODE));
    }

    // ---------------------------------------------------------------- ayudas

    /** Resolver independiente: memoria propia, caché compartida. */
    private function otroProceso(): ServicioAjustes
    {
        return new ServicioAjustes(
            app(CatalogoAjustes::class),
            new RepositorioAjustes(app(CacheRepository::class)),
            app(AdaptadorConfiguraciones::class),
            app(ConversorValor::class),
            app(AuditoriaAjustes::class),
        );
    }

    /**
     * Un envío de correo pendiente sobre un CCF ACEPTADO real.
     *
     * Se monta el emisor completo en vez de falsear el DTE porque lo que se quiere
     * demostrar es que el JOB DE VERDAD actualiza la configuración antes de tocar
     * el transporte; con un doble, el test demostraría cómo está escrito el doble.
     */
    private function envioDeCorreo(User $usuario): DteEnvio
    {
        $this->seedCatalogosDte();
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        Correlativo::create([
            'tipo_dte' => '03', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
            'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
        ]);

        $cliente = Cliente::factory()->contribuyente()->create(['correo' => 'cliente@ejemplo.com']);
        $producto = Producto::factory()->create([
            'precio_unitario' => 10,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);

        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
        $borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);

        app(DteGeneracionService::class)->generar($dte);
        $dte->refresh();
        $dte->numero_control = 'DTE-03-M001P001-000000000000048';
        $dte->codigo_generacion = 'A1B2C3D4-E5F6-7A8B-9C0D-1E2F3A4B5C6D';
        $dte->sello_recepcion = 'SELLO-OK-123';
        $dte->estado = EstadoDte::Aceptado;
        $dte->save();

        return DteEnvio::create([
            'dte_id' => $dte->id,
            'user_id' => $usuario->id,
            'destinatarios' => ['cliente@ejemplo.com'],
            'estado' => 'pendiente',
            'canal' => 'cliente',
        ]);
    }
}
