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
use App\Services\Dte\DtePdfService;
use App\Support\Sala;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaArchivoEntregaDte;
use Tests\TestCase;

/**
 * Correo de un DTE por el canal `contabilidad`: asunto y cuerpo propios, sin BCC
 * duplicado, y solo `enviado` cuando el correo sale de verdad.
 *
 * La pantalla «Ventas», que tenía el botón de envío individual, se retiró el
 * 06/11/2026 (no se usaba): las ventas van a contabilidad en el paquete mensual. Lo
 * que queda probado acá es el canal del correo, que sigue en el código. Nunca sale
 * un correo real (Mail::fake) y no se toca estado fiscal, sello ni correlativos.
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

        $envio = $this->envio($dte, DteEnvio::CANAL_CONTABILIDAD, 'pendiente');
        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));

        $this->assertSame($antes, $instantanea($dte->refresh()));
        $this->assertEquals($correlAntes, Correlativo::orderBy('id')->get(['id', 'ultimo_numero'])->toArray());
        $this->assertSame($dtesAntes, Dte::count());
    }
}
