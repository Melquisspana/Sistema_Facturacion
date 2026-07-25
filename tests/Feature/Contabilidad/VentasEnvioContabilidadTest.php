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
use Tests\TestCase;

/**
 * Ventas → envío INDIVIDUAL de un DTE a contabilidad (canal `contabilidad`): acciones
 * de la fila (Ver PDF / JSON / Enviar-Reenviar), estado del envío y filtros rápidos.
 *
 * Reglas verificadas: solo documentos aceptados REALMENTE por Hacienda; destinatario
 * desde `contabilidad.correo`; solo `enviado` cuenta como enviado (simulado, error y
 * en cola siguen pendientes); canal NULL o `cliente` nunca cuentan. Nunca sale un
 * correo real (Mail::fake) y no se toca estado fiscal, sello ni correlativos.
 */
class VentasEnvioContabilidadTest extends TestCase
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
        Sala::olvidarCache();
        $this->seed(DatosInicialesNegritaSeeder::class);
        Configuracion::set('contabilidad.correo', self::CORREO_CONTA);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
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
            $ruta = 'dte/json/dte-03-'.$dte->id.'.json';
            Storage::disk('local')->put($ruta, '{"identificacion":{"x":1}}');
            // json_generado_path no es fillable: se asigna directo (el observer lo permite).
            $dte->json_generado_path = $ruta;
            $dte->save();
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

    private function pantalla(array $query = []): TestResponse
    {
        return $this->get(route('facturacion.reporte-contadora', $query));
    }

    // --- Pantalla y acciones ---

    public function test_la_pantalla_muestra_las_acciones_de_la_fila(): void
    {
        $dte = $this->venta();

        $this->actingAs($this->usuario('contabilidad'))
            ->pantalla()
            ->assertOk()
            ->assertSee($dte->numero_control)
            // Acción principal + badge del estado en la misma columna.
            ->assertSee('Enviar a contabilidad')
            ->assertSee('Pendiente')
            // Las descargas viven en el menú "⋮", no en línea.
            ->assertSee('Más acciones')
            ->assertSee('Ver PDF')
            ->assertSee('Descargar JSON')
            ->assertSee(self::CORREO_CONTA);
    }

    public function test_facturacion_ve_la_pantalla_pero_no_el_boton_de_envio(): void
    {
        $this->venta();

        // Facturación tiene reportes.ver pero NO contabilidad.enviar: ve la pantalla sin el botón.
        $this->actingAs($this->usuario('facturacion'))
            ->pantalla()
            ->assertOk()
            ->assertSee('Ver PDF')
            ->assertDontSee('Enviar a contabilidad');
    }

    // --- Envío: encolado con canal contabilidad ---

    public function test_enviar_encola_canal_contabilidad_al_correo_configurado(): void
    {
        Mail::fake();
        Queue::fake();
        $dte = $this->venta();
        $user = $this->usuario('contabilidad');

        $this->actingAs($user)
            ->post(route('facturacion.reporte-contadora.enviar', $dte))
            ->assertRedirect()
            ->assertSessionHas('status');

        Queue::assertPushed(EnviarDteCorreo::class);
        $envio = DteEnvio::where('dte_id', $dte->id)->firstOrFail();
        $this->assertSame(DteEnvio::CANAL_CONTABILIDAD, $envio->canal);
        $this->assertSame('pendiente', $envio->estado);
        $this->assertSame([self::CORREO_CONTA], $envio->destinatarios);
        $this->assertSame($user->id, $envio->user_id);
        Mail::assertNothingSent(); // solo encola: el correo lo manda el job
    }

    public function test_el_estado_inmediato_es_en_cola(): void
    {
        Queue::fake();
        $dte = $this->venta();
        $user = $this->usuario('contabilidad');

        $this->actingAs($user)->post(route('facturacion.reporte-contadora.enviar', $dte));

        $this->actingAs($user)->pantalla()->assertOk()->assertSee('En cola');
    }

    public function test_enviado_solo_cuando_el_job_termina_bien(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $dte = $this->venta();
        $envio = $this->envio($dte, DteEnvio::CANAL_CONTABILIDAD, 'pendiente');

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        $this->assertSame('enviado', $envio->refresh()->estado);
        $this->actingAs($this->usuario('contabilidad'))->pantalla()->assertOk()->assertSee('Enviado');
    }

    public function test_error_del_job_se_muestra_y_sigue_pendiente(): void
    {
        $dte = $this->venta();
        $this->envio($dte, DteEnvio::CANAL_CONTABILIDAD, 'error', 'SMTP connect() failed');
        $user = $this->usuario('contabilidad');

        $this->actingAs($user)->pantalla()->assertOk()
            ->assertSee('Error')
            ->assertSee('SMTP connect() failed');

        // Sigue contando como pendiente de enviar a contabilidad.
        $this->actingAs($user)->pantalla(['contabilidad' => 'pendientes'])->assertOk()->assertSee($dte->numero_control);
        $this->actingAs($user)->pantalla(['contabilidad' => 'enviados'])->assertOk()->assertDontSee($dte->numero_control);
    }

    public function test_simulado_no_cuenta_como_enviado(): void
    {
        // Mailer de pruebas (array, el de phpunit): el job marca 'simulado', no 'enviado'.
        Mail::fake();
        $dte = $this->venta();
        $envio = $this->envio($dte, DteEnvio::CANAL_CONTABILIDAD, 'pendiente');

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        $this->assertSame('simulado', $envio->refresh()->estado);
        $user = $this->usuario('contabilidad');
        $this->actingAs($user)->pantalla()->assertOk()->assertSee('Simulado');
        $this->actingAs($user)->pantalla(['contabilidad' => 'pendientes'])->assertOk()->assertSee($dte->numero_control);
        $this->actingAs($user)->pantalla(['contabilidad' => 'enviados'])->assertOk()->assertDontSee($dte->numero_control);
    }

    public function test_canal_cliente_y_canal_null_no_cuentan_como_enviados_a_contabilidad(): void
    {
        $dte = $this->venta();
        $this->envio($dte, DteEnvio::CANAL_CLIENTE, 'enviado');   // envío al cliente
        $this->envio($dte, null, 'enviado');                      // histórico (sin canal)
        $user = $this->usuario('contabilidad');

        // La columna Contabilidad no muestra badge y el documento sigue pendiente.
        $this->actingAs($user)->pantalla(['contabilidad' => 'pendientes'])->assertOk()->assertSee($dte->numero_control);
        $this->actingAs($user)->pantalla(['contabilidad' => 'enviados'])->assertOk()->assertDontSee($dte->numero_control);
        $this->assertNull(Dte::whereKey($dte->id)->first()->envio_conta_estado ?? null);
    }

    public function test_no_duplica_si_ya_esta_en_cola(): void
    {
        Queue::fake();
        $dte = $this->venta();
        $user = $this->usuario('contabilidad');

        $this->actingAs($user)->post(route('facturacion.reporte-contadora.enviar', $dte));
        $this->actingAs($user)->post(route('facturacion.reporte-contadora.enviar', $dte))
            ->assertSessionHas('status');

        $this->assertSame(1, DteEnvio::where('dte_id', $dte->id)->count());
        Queue::assertPushed(EnviarDteCorreo::class, 1);
    }

    public function test_reenviar_tras_un_envio_exitoso_crea_un_segundo_envio(): void
    {
        Queue::fake();
        $dte = $this->venta();
        $this->envio($dte, DteEnvio::CANAL_CONTABILIDAD, 'enviado');
        $user = $this->usuario('contabilidad');

        $this->actingAs($user)->pantalla()->assertOk()->assertSee('Reenviar');
        $this->actingAs($user)->post(route('facturacion.reporte-contadora.enviar', $dte))->assertRedirect();

        $this->assertSame(2, DteEnvio::where('dte_id', $dte->id)->count());
        $this->assertSame(2, DteEnvio::where('dte_id', $dte->id)->where('canal', DteEnvio::CANAL_CONTABILIDAD)->count());
    }

    // --- Guardas ---

    public function test_no_envia_un_dte_no_aceptado_realmente(): void
    {
        Queue::fake();
        $mock = $this->venta(sello: 'MOCK-SIMULADO-ABCD');
        $user = $this->usuario('contabilidad');

        $this->actingAs($user)->post(route('facturacion.reporte-contadora.enviar', $mock))->assertForbidden();

        $generado = $this->venta();
        $generado->update(['estado' => 'generado']);
        $this->actingAs($user)->post(route('facturacion.reporte-contadora.enviar', $generado))->assertForbidden();

        $this->assertDatabaseCount('dte_envios', 0);
        Queue::assertNothingPushed();
    }

    public function test_sin_correo_de_contabilidad_no_encola(): void
    {
        Queue::fake();
        Configuracion::set('contabilidad.correo', '');
        Configuracion::olvidarCache();
        $dte = $this->venta();

        $this->actingAs($this->usuario('contabilidad'))
            ->post(route('facturacion.reporte-contadora.enviar', $dte))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('dte_envios', 0);
        Queue::assertNothingPushed();
    }

    public function test_permisos_del_envio(): void
    {
        Queue::fake();
        $dte = $this->venta();

        foreach (['facturacion', 'jefatura'] as $rol) { // no tienen contabilidad.enviar
            $this->actingAs($this->usuario($rol))
                ->post(route('facturacion.reporte-contadora.enviar', $dte))
                ->assertForbidden();
        }
        $this->assertDatabaseCount('dte_envios', 0);

        foreach (['contabilidad', 'administrador'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->post(route('facturacion.reporte-contadora.enviar', $this->venta()))
                ->assertRedirect();
        }
        $this->assertSame(2, DteEnvio::count());
    }

    // --- JSON con reportes.ver ---

    public function test_contabilidad_descarga_el_json_con_reportes_ver(): void
    {
        $dte = $this->venta();

        // Contabilidad NO tiene dte.emitir (por eso no usa la ruta de Facturación).
        $user = $this->usuario('contabilidad');
        $this->assertFalse($user->can('dte.emitir'));

        $this->actingAs($user)
            ->get(route('facturacion.reporte-contadora.json', $dte))
            ->assertOk()
            ->assertHeader('content-type', 'application/json');
    }

    public function test_json_faltante_da_404_y_no_se_genera(): void
    {
        $dte = $this->venta(conJson: false);

        $this->actingAs($this->usuario('contabilidad'))
            ->get(route('facturacion.reporte-contadora.json', $dte))
            ->assertNotFound();

        $this->assertNull($dte->refresh()->json_generado_path); // no se generó nada
    }

    public function test_json_de_un_dte_no_aceptado_realmente_da_403(): void
    {
        $mock = $this->venta(sello: 'MOCK-SIMULADO-ZZZZ');

        $this->actingAs($this->usuario('contabilidad'))
            ->get(route('facturacion.reporte-contadora.json', $mock))
            ->assertForbidden();
    }

    // --- Filtros rápidos ---

    public function test_filtros_rapidos_de_fecha(): void
    {
        $hoy = $this->venta();
        $mesPasado = $this->venta(now()->subMonthNoOverflow()->startOfMonth()->addDay()->toDateString());
        $hace20Dias = $this->venta(now()->subDays(20)->toDateString());
        $user = $this->usuario('contabilidad');

        $este = $this->actingAs($user)->pantalla(['rango' => 'este_mes'])->assertOk();
        $este->assertSee($hoy->numero_control)->assertDontSee($mesPasado->numero_control);

        $pasado = $this->actingAs($user)->pantalla(['rango' => 'mes_pasado'])->assertOk();
        $pasado->assertSee($mesPasado->numero_control)->assertDontSee($hoy->numero_control);

        $ultimos7 = $this->actingAs($user)->pantalla(['rango' => 'ultimos_7'])->assertOk();
        $ultimos7->assertSee($hoy->numero_control)->assertDontSee($hace20Dias->numero_control);
    }

    public function test_filtros_de_contabilidad(): void
    {
        $enviado = $this->venta();
        $this->envio($enviado, DteEnvio::CANAL_CONTABILIDAD, 'enviado');
        $pendiente = $this->venta();
        $user = $this->usuario('contabilidad');

        $this->actingAs($user)->pantalla(['contabilidad' => 'enviados'])->assertOk()
            ->assertSee($enviado->numero_control)->assertDontSee($pendiente->numero_control);

        $this->actingAs($user)->pantalla(['contabilidad' => 'pendientes'])->assertOk()
            ->assertSee($pendiente->numero_control)->assertDontSee($enviado->numero_control);
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
            'destinatario' => 'cliente@calleja.com', 'destinatarios' => ['cliente@calleja.com'],
            'canal' => DteEnvio::CANAL_CLIENTE, 'estado' => 'pendiente',
        ]);

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        Mail::assertSent(DteCorreo::class, function (DteCorreo $m) use ($dte) {
            $m->assertHasSubject($dte->tipo_dte->label().' '.$dte->numero_control.' — Dulces La Negrita');
            $m->assertSeeInHtml('Estimado cliente');
            $m->assertDontSeeInHtml('registro contable');

            return $m->hasTo('cliente@calleja.com');
        });
    }

    public function test_un_envio_historico_sin_canal_conserva_el_correo_al_cliente(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $dte = $this->venta();
        $envio = $dte->envios()->create([
            'destinatario' => 'cliente@calleja.com', 'destinatarios' => ['cliente@calleja.com'],
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
            'destinatario' => 'cliente@calleja.com', 'destinatarios' => ['cliente@calleja.com'],
            'canal' => DteEnvio::CANAL_CLIENTE, 'estado' => 'pendiente',
        ]);

        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        Mail::assertSent(DteCorreo::class, fn (DteCorreo $m) => $m->hasTo('cliente@calleja.com') && $m->hasBcc(self::CORREO_CONTA));
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
            ->post(route('facturacion.reporte-contadora.enviar', $dte))->assertRedirect();
        (new EnviarDteCorreo(DteEnvio::firstOrFail()->id))->handle(app(DtePdfService::class));

        $this->assertSame($antes, $instantanea($dte->refresh()));
        $this->assertEquals($correlAntes, Correlativo::orderBy('id')->get(['id', 'ultimo_numero'])->toArray());
        $this->assertSame($dtesAntes, Dte::count());
    }
}
