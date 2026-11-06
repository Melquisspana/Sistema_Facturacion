<?php

namespace Tests\Feature\Contabilidad;

use App\Enums\EstadoDte;
use App\Jobs\EnviarDteCorreo;
use App\Mail\DteCorreo;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Models\Establecimiento;
use App\Models\User;
use App\Services\Dte\DtePdfService;
use App\Support\Sala;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaArchivoEntregaDte;
use Tests\TestCase;

/**
 * Envío INDIVIDUAL de una venta a contabilidad (canal `contabilidad`) desde la ficha
 * de la factura: botón según permiso, estado del último envío, guardas y el correo.
 *
 * Antes vivía en la pantalla «Ventas», retirada el 06/11/2026. Las ventas van en el
 * paquete mensual, pero hay quien trabaja mandando a diario. Reglas: solo aceptados
 * REALMENTE por Hacienda; destinatario desde `contabilidad.correo`; solo `enviado`
 * cuenta como enviado. Nunca sale un correo real (Mail::fake) y no se toca estado
 * fiscal, sello ni correlativos.
 */
class VentasEnvioContabilidadTest extends TestCase
{
    use PreparaArchivoEntregaDte;
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
        Sala::olvidarCache();
        $this->seed(DatosInicialesNegritaSeeder::class);
        Configuracion::set('contabilidad.correo', self::CORREO_CONTA);
    }

    /**
     * Venta ACEPTADA REALMENTE por el MH (ambiente 01, sello real, fecha de
     * procesamiento) con su JSON oficial guardado. Fixture directo: no pasa por
     * generación/firma/transmisión (no mueve correlativos).
     */
    private function venta(?string $fecha = null, bool $conJson = true, ?string $sello = null): Dte
    {
        $dte = Dte::create([
            'establecimiento_id' => Establecimiento::firstOrFail()->id,
            'cliente_id' => Cliente::factory()->create(['nombre' => 'Calleja, S.A. de C.V.'])->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'numero_control' => 'DTE-03-M001P001-'.str_pad((string) random_int(1, 999999999), 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => (string) Str::uuid(),
            'sello_recepcion' => $sello ?? ('2026SELLOREAL'.random_int(1000, 9999)),
            'fecha_procesamiento_mh' => now(),
            'fecha_emision' => $fecha ?? now()->toDateString(),
            'hora_emision' => now()->format('H:i:s'),
            'total_gravado' => 100.00,
            'iva' => 13.00,
            'iva_retenido' => 1.00,
            'total_pagar' => 112.00,
        ]);

        if ($conJson) {
            $ruta = 'dte/json/dte-03-'.$dte->id.'-'.$dte->codigo_generacion.'.json';
            // json_generado_path no es fillable: se asigna directo (el observer lo permite).
            $dte->json_generado_path = $ruta;
            $this->prepararArchivoEntrega($dte);
        }

        return $dte->refresh();
    }

    /** Crea a mano un envío del historial (para probar badges/filtros sin correr el job). */
    private function envio(Dte $dte, ?string $canal, string $estado, ?string $error = null): DteEnvio
    {
        return $dte->envios()->create([
            'destinatario' => self::CORREO_CONTA,
            'destinatarios' => [self::CORREO_CONTA],
            'canal' => $canal,
            'estado' => $estado,
            'error' => $error,
        ]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function ficha(Dte $dte): TestResponse
    {
        return $this->get(route('facturacion.show', $dte));
    }

    // --- Ficha: botón y estado ---

    public function test_la_ficha_muestra_el_envio_a_contabilidad_a_quien_tiene_permiso(): void
    {
        $dte = $this->venta();

        foreach (['contabilidad', 'administrador'] as $rol) {
            $this->actingAs($this->usuario($rol))->ficha($dte)->assertOk()
                ->assertSee(route('facturacion.enviar-contabilidad', $dte), false)
                ->assertSee('Enviar a contabilidad')
                ->assertSee(self::CORREO_CONTA);
        }

        // Facturación ve la ficha, pero no tiene contabilidad.enviar: sin botón.
        $this->actingAs($this->usuario('facturacion'))->ficha($dte)->assertOk()
            ->assertDontSee(route('facturacion.enviar-contabilidad', $dte), false);
    }

    public function test_la_ficha_no_ofrece_el_envio_de_un_documento_no_aceptado_realmente(): void
    {
        $mock = $this->venta(sello: 'MOCK-SIMULADO-ABCD');

        $this->actingAs($this->usuario('contabilidad'))->ficha($mock)->assertOk()
            ->assertDontSee(route('facturacion.enviar-contabilidad', $mock), false);
    }

    public function test_la_ficha_muestra_el_estado_del_ultimo_envio_a_contabilidad(): void
    {
        $dte = $this->venta();
        $user = $this->usuario('contabilidad');

        $this->envio($dte, DteEnvio::CANAL_CONTABILIDAD, 'error', 'SMTP connect() failed');
        $this->actingAs($user)->ficha($dte)->assertOk()->assertSee('SMTP connect() failed')->assertSee('Enviar a contabilidad');

        $this->envio($dte, DteEnvio::CANAL_CONTABILIDAD, 'enviado');
        $this->actingAs($user)->ficha($dte)->assertOk()->assertSee('Reenviar a contabilidad');
    }

    public function test_un_envio_a_contabilidad_no_cuenta_como_correo_al_cliente(): void
    {
        $dte = $this->venta();
        $this->envio($dte, DteEnvio::CANAL_CONTABILIDAD, 'enviado');

        $this->actingAs($this->usuario('administrador'))->ficha($dte)->assertOk()
            ->assertSee('Pendiente de correo')
            ->assertDontSee('Correo enviado');
    }

    // --- Envío desde la ficha: encolado con canal contabilidad ---

    public function test_enviar_encola_canal_contabilidad_y_registra_quien(): void
    {
        Mail::fake();
        Queue::fake();
        $dte = $this->venta();
        $user = $this->usuario('contabilidad');

        $this->actingAs($user)
            ->post(route('facturacion.enviar-contabilidad', $dte))
            ->assertRedirect()
            ->assertSessionHas('status');

        Queue::assertPushed(EnviarDteCorreo::class);
        $envio = DteEnvio::where('dte_id', $dte->id)->firstOrFail();
        $this->assertSame(DteEnvio::CANAL_CONTABILIDAD, $envio->canal);
        $this->assertSame('pendiente', $envio->estado);
        $this->assertSame([self::CORREO_CONTA], $envio->destinatarios);
        $this->assertSame($user->id, $envio->user_id);
        Mail::assertNothingSent(); // solo encola: el correo lo manda el job

        $this->actingAs($user)->ficha($dte)->assertOk()->assertSee('En cola');
    }

    public function test_permisos_del_envio(): void
    {
        Queue::fake();
        $dte = $this->venta();

        foreach (['facturacion', 'jefatura'] as $rol) { // no tienen contabilidad.enviar
            $this->actingAs($this->usuario($rol))
                ->post(route('facturacion.enviar-contabilidad', $dte))
                ->assertForbidden();
        }
        $this->assertDatabaseCount('dte_envios', 0);

        foreach (['contabilidad', 'administrador'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->post(route('facturacion.enviar-contabilidad', $this->venta()))
                ->assertRedirect();
        }
        $this->assertSame(2, DteEnvio::where('canal', DteEnvio::CANAL_CONTABILIDAD)->count());
    }

    public function test_no_envia_un_dte_no_aceptado_realmente(): void
    {
        Queue::fake();
        $user = $this->usuario('contabilidad');

        $this->actingAs($user)->post(route('facturacion.enviar-contabilidad', $this->venta(sello: 'MOCK-SIMULADO-ABCD')))->assertForbidden();

        $generado = $this->venta();
        $generado->update(['estado' => 'generado']);
        $this->actingAs($user)->post(route('facturacion.enviar-contabilidad', $generado))->assertForbidden();

        $this->assertDatabaseCount('dte_envios', 0);
        Queue::assertNothingPushed();
    }

    public function test_sin_correo_de_contabilidad_no_encola(): void
    {
        Queue::fake();
        Configuracion::set('contabilidad.correo', '');
        Configuracion::olvidarCache();

        $this->actingAs($this->usuario('contabilidad'))
            ->post(route('facturacion.enviar-contabilidad', $this->venta()))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('dte_envios', 0);
        Queue::assertNothingPushed();
    }

    public function test_no_duplica_si_ya_esta_en_cola_y_reenvia_tras_un_envio_exitoso(): void
    {
        Queue::fake();
        $user = $this->usuario('contabilidad');

        $dte = $this->venta();
        $this->actingAs($user)->post(route('facturacion.enviar-contabilidad', $dte));
        $this->actingAs($user)->post(route('facturacion.enviar-contabilidad', $dte))->assertSessionHas('status');
        $this->assertSame(1, DteEnvio::where('dte_id', $dte->id)->count());

        $otro = $this->venta();
        $this->envio($otro, DteEnvio::CANAL_CONTABILIDAD, 'enviado');
        $this->actingAs($user)->post(route('facturacion.enviar-contabilidad', $otro))->assertRedirect();
        $this->assertSame(2, DteEnvio::where('dte_id', $otro->id)->where('canal', DteEnvio::CANAL_CONTABILIDAD)->count());
    }

    // --- Resultado del job ---

    public function test_enviado_solo_cuando_el_job_termina_bien(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $envio = $this->envio($this->venta(), DteEnvio::CANAL_CONTABILIDAD, 'pendiente');

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        $this->assertSame('enviado', $envio->refresh()->estado);
    }

    public function test_simulado_no_cuenta_como_enviado(): void
    {
        // Mailer de pruebas (array, el de phpunit): el job marca 'simulado', no 'enviado'.
        Mail::fake();
        $envio = $this->envio($this->venta(), DteEnvio::CANAL_CONTABILIDAD, 'pendiente');

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        $this->assertSame('simulado', $envio->refresh()->estado);
    }

    // --- Asunto/cuerpo por canal y BCC ---

    public function test_el_correo_a_contabilidad_tiene_asunto_y_cuerpo_propios(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $dte = $this->venta();
        $envio = $this->envio($dte, DteEnvio::CANAL_CONTABILIDAD, 'pendiente');

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        Mail::assertSent(DteCorreo::class, function (DteCorreo $m) use ($dte) {
            $m->assertHasSubject('DTE para contabilidad — '.$dte->tipo_dte->label().' '.$dte->numero_control);
            $m->assertSeeInHtml('Se adjunta el documento electrónico para registro contable.', false);
            $m->assertSeeInHtml('PDF y JSON oficiales');
            $m->assertDontSeeInHtml('Estimado cliente');   // nada dirigido al cliente
            $m->assertDontSeeInHtml('Gracias por su preferencia');

            return $m->hasTo(self::CORREO_CONTA);
        });
    }

    public function test_el_correo_al_cliente_conserva_asunto_y_cuerpo_actuales(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $dte = $this->venta();
        $envio = $dte->envios()->create([
            'destinatario' => 'cliente@cliente.example.com', 'destinatarios' => ['cliente@cliente.example.com'],
            'canal' => DteEnvio::CANAL_CLIENTE, 'estado' => 'pendiente',
        ]);

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        Mail::assertSent(DteCorreo::class, function (DteCorreo $m) use ($dte) {
            $m->assertHasSubject($dte->tipo_dte->label().' '.$dte->numero_control.' — Dulces La Negrita');
            $m->assertSeeInHtml('Estimado cliente');
            $m->assertDontSeeInHtml('registro contable');

            return $m->hasTo('cliente@cliente.example.com');
        });
    }

    public function test_un_envio_historico_sin_canal_conserva_el_correo_al_cliente(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $dte = $this->venta();
        $envio = $dte->envios()->create([
            'destinatario' => 'cliente@cliente.example.com', 'destinatarios' => ['cliente@cliente.example.com'],
            'estado' => 'pendiente', // canal NULL
        ]);

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        Mail::assertSent(DteCorreo::class, function (DteCorreo $m) {
            $m->assertSeeInHtml('Estimado cliente');

            return true;
        });
    }

    public function test_contabilidad_no_recibe_el_correo_duplicado_por_bcc(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        Configuracion::set('contabilidad.enviar_copia', true); // copia BCC activada
        $dte = $this->venta();
        $envio = $this->envio($dte, DteEnvio::CANAL_CONTABILIDAD, 'pendiente');

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        // Va UNA sola vez: como destinatario, sin BCC al mismo correo.
        Mail::assertSent(DteCorreo::class, fn (DteCorreo $m) => $m->hasTo(self::CORREO_CONTA) && ! $m->hasBcc(self::CORREO_CONTA));
    }

    public function test_el_canal_cliente_conserva_el_bcc_a_contabilidad(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        Configuracion::set('contabilidad.enviar_copia', true);
        $dte = $this->venta();
        $envio = $dte->envios()->create([
            'destinatario' => 'cliente@cliente.example.com', 'destinatarios' => ['cliente@cliente.example.com'],
            'canal' => DteEnvio::CANAL_CLIENTE, 'estado' => 'pendiente',
        ]);

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        Mail::assertSent(DteCorreo::class, fn (DteCorreo $m) => $m->hasTo('cliente@cliente.example.com') && $m->hasBcc(self::CORREO_CONTA));
    }

    // --- No toca nada fiscal ---

    public function test_el_envio_no_toca_estado_fiscal_ni_correlativos(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $dte = $this->venta();
        $campos = ['estado', 'sello_recepcion', 'numero_control', 'codigo_generacion', 'fecha_procesamiento_mh', 'json_generado_path'];
        $instantanea = fn (Dte $d) => collect($d->only($campos))
            ->map(fn ($v) => match (true) {
                $v instanceof EstadoDte => $v->value,
                $v instanceof \DateTimeInterface => $v->format('Y-m-d H:i:s'),
                default => (string) $v,
            })->all();
        $antes = $instantanea($dte);
        $correlAntes = Correlativo::orderBy('id')->get(['id', 'ultimo_numero'])->toArray();
        $dtesAntes = Dte::count();

        $this->actingAs($this->usuario('contabilidad'))
            ->post(route('facturacion.enviar-contabilidad', $dte))->assertRedirect();
        (new EnviarDteCorreo(DteEnvio::firstOrFail()->id))->handle(app(DtePdfService::class));

        $this->assertSame($antes, $instantanea($dte->refresh()));
        $this->assertEquals($correlAntes, Correlativo::orderBy('id')->get(['id', 'ultimo_numero'])->toArray());
        $this->assertSame($dtesAntes, Dte::count());
    }
}
