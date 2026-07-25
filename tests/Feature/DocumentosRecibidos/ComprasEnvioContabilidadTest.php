<?php

namespace Tests\Feature\DocumentosRecibidos;

use App\Jobs\EnviarDocumentoRecibidoContabilidad;
use App\Mail\DocumentoRecibidoContabilidadCorreo;
use App\Models\Configuracion;
use App\Models\DocumentoRecibido;
use App\Models\DocumentoRecibidoEnvio;
use App\Models\User;
use App\Services\DocumentosRecibidos\AdjuntosDocumentoRecibido;
use App\Services\DocumentosRecibidos\Contracts\MailboxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Compras → envío INDIVIDUAL de un documento recibido a contabilidad: adjunta los
 * archivos ORIGINALES ya guardados (PDF/JSON/otros), con límite total de 15 MB.
 *
 * Reglas verificadas: el documento pasa a 'enviado' SOLO cuando el correo termina bien
 * (simulado/error/en cola lo dejan pendiente); "Ignorar" sigue separado; ya no existe
 * el marcado manual; el buzón Yahoo NUNCA se toca; nunca sale un correo real.
 */
class ComprasEnvioContabilidadTest extends TestCase
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

        // Candado: si algo del envío intentara leer el buzón, la prueba falla.
        $this->app->instance(MailboxClient::class, new class implements MailboxClient
        {
            public function disponible(): bool
            {
                return false;
            }

            public function fuente(): string
            {
                return 'buzón-prohibido';
            }

            public function mensajesConAdjuntos(int $limite = 30, ?Carbon $desde = null): array
            {
                throw new \RuntimeException('El envío a contabilidad NO debe tocar el buzón.');
            }
        });
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /**
     * Compra con adjuntos REALES en disco. $archivos = [nombre => bytes de tamaño].
     *
     * @param  array<string, int>  $archivos
     */
    private function compra(array $archivos = ['factura.pdf' => 1024, 'dte.json' => 512], array $extra = []): DocumentoRecibido
    {
        static $n = 0;
        $n++;

        $rutas = [];
        foreach ($archivos as $nombre => $bytes) {
            $ruta = 'documentos-recibidos/m'.$n.'/'.$nombre;
            Storage::disk('local')->put($ruta, str_repeat('x', max(1, $bytes)));
            $rutas[] = $ruta;
        }

        return DocumentoRecibido::create($extra + [
            'gmail_message_id' => 'm'.$n,
            'emisor_nombre' => 'PROVEEDOR '.$n,
            'remitente' => 'proveedor'.$n.'@correo.com',
            'tipo_documento' => '03',
            'numero_control' => 'DTE-03-M001P001-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'codigo_generacion' => 'CG-'.$n,
            'estado' => 'pendiente',
            'clasificacion' => 'dte_valido',
            'total' => 112.00,
            'fecha_dte' => now()->toDateString(),
            'fecha_correo' => now(),
            'tiene_pdf' => in_array('factura.pdf', array_keys($archivos), true),
            'tiene_json' => in_array('dte.json', array_keys($archivos), true),
            'metadata_json' => ['archivos' => $rutas],
        ]);
    }

    private function envio(DocumentoRecibido $doc, string $estado, ?string $error = null): DocumentoRecibidoEnvio
    {
        return $doc->envios()->create([
            'destinatario' => self::CORREO_CONTA,
            'destinatarios' => [self::CORREO_CONTA],
            'estado' => $estado,
            'error' => $error,
        ]);
    }

    private function correr(DocumentoRecibidoEnvio $envio): void
    {
        (new EnviarDocumentoRecibidoContabilidad($envio->id))->handle(app(AdjuntosDocumentoRecibido::class));
    }

    private function pantalla(array $query = ['vista' => 'bandeja', 'rango' => 'todos']): TestResponse
    {
        return $this->get(route('documentos-recibidos.index', $query));
    }

    // --- Pantalla ---

    public function test_la_pantalla_muestra_enviar_badge_y_menu(): void
    {
        $doc = $this->compra();

        $this->actingAs($this->usuario('contabilidad'))
            ->pantalla()
            ->assertOk()
            ->assertSee($doc->numero_control)
            ->assertSee('Enviar a contabilidad')
            ->assertSee('Pendiente')
            ->assertSee('Más acciones')
            ->assertSee('Abrir PDF')
            ->assertSee('Abrir JSON')
            ->assertDontSee('Marcar enviado');
    }

    // --- Envío ---

    public function test_enviar_encola_al_correo_configurado_y_registra_todo(): void
    {
        Mail::fake();
        Queue::fake();
        $doc = $this->compra();
        $user = $this->usuario('contabilidad');

        $this->actingAs($user)
            ->post(route('documentos-recibidos.enviar-contabilidad', $doc))
            ->assertRedirect()
            ->assertSessionHas('status');

        Queue::assertPushed(EnviarDocumentoRecibidoContabilidad::class);
        $envio = DocumentoRecibidoEnvio::firstOrFail();
        $this->assertSame($doc->id, $envio->documento_recibido_id);
        $this->assertSame('pendiente', $envio->estado);          // En cola
        $this->assertSame([self::CORREO_CONTA], $envio->destinatarios);
        $this->assertSame($user->id, $envio->user_id);            // quién
        $this->assertNotNull($envio->created_at);                 // cuándo
        $this->assertSame('pendiente', $doc->refresh()->estado);  // NO se marca enviado al encolar
        Mail::assertNothingSent();
    }

    public function test_badge_en_cola_mientras_el_job_no_corre(): void
    {
        Queue::fake();
        $doc = $this->compra();
        $user = $this->usuario('contabilidad');

        $this->actingAs($user)->post(route('documentos-recibidos.enviar-contabilidad', $doc));

        $this->actingAs($user)->pantalla()->assertOk()->assertSee('En cola');
    }

    public function test_enviado_solo_cuando_el_job_termina_bien(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $doc = $this->compra();
        $envio = $this->envio($doc, 'pendiente');

        $this->correr($envio);

        $this->assertSame('enviado', $envio->refresh()->estado);
        $this->assertSame('enviado', $doc->refresh()->estado); // recién ahora
        $this->actingAs($this->usuario('contabilidad'))->pantalla()->assertOk()->assertSee('Enviado');
    }

    public function test_simulado_no_marca_el_documento_como_enviado(): void
    {
        Mail::fake(); // mailer de pruebas (array) → simulado
        $doc = $this->compra();
        $envio = $this->envio($doc, 'pendiente');

        $this->correr($envio);

        $this->assertSame('simulado', $envio->refresh()->estado);
        $this->assertSame('pendiente', $doc->refresh()->estado); // sigue pendiente
        $this->actingAs($this->usuario('contabilidad'))->pantalla()->assertOk()->assertSee('Simulado');
    }

    public function test_error_no_marca_enviado_y_se_muestra(): void
    {
        $doc = $this->compra();
        $this->envio($doc, 'error', 'SMTP connect() failed');

        $this->assertSame('pendiente', $doc->refresh()->estado);
        $this->actingAs($this->usuario('contabilidad'))->pantalla()->assertOk()
            ->assertSee('Error')
            ->assertSee('SMTP connect() failed');
    }

    public function test_no_duplica_si_ya_esta_en_cola_y_permite_reenviar(): void
    {
        Queue::fake();
        $doc = $this->compra();
        $user = $this->usuario('contabilidad');

        $this->actingAs($user)->post(route('documentos-recibidos.enviar-contabilidad', $doc));
        $this->actingAs($user)->post(route('documentos-recibidos.enviar-contabilidad', $doc))->assertSessionHas('status');
        $this->assertSame(1, DocumentoRecibidoEnvio::count());

        // Con el primero ya resuelto, un nuevo envío sí crea otro registro (reenvío).
        DocumentoRecibidoEnvio::firstOrFail()->update(['estado' => 'enviado']);
        $this->actingAs($user)->pantalla()->assertOk()->assertSee('Reenviar');
        $this->actingAs($user)->post(route('documentos-recibidos.enviar-contabilidad', $doc))->assertRedirect();
        $this->assertSame(2, DocumentoRecibidoEnvio::count());
    }

    // --- Adjuntos ---

    public function test_adjunta_los_archivos_originales_con_pdf_y_json_primero(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        // Orden en disco a propósito "al revés": la prioridad la pone el servicio.
        $doc = $this->compra(['otro.xml' => 100, 'dte.json' => 200, 'factura.pdf' => 300]);
        $envio = $this->envio($doc, 'pendiente');

        $this->correr($envio);

        Mail::assertSent(DocumentoRecibidoContabilidadCorreo::class, function (DocumentoRecibidoContabilidadCorreo $m) {
            $nombres = array_map(fn (array $a) => $a['nombre'], $m->archivos);
            $this->assertSame(['factura.pdf', 'dte.json', 'otro.xml'], $nombres);

            return true;
        });
        $this->assertSame('factura.pdf, dte.json, otro.xml', $envio->refresh()->adjuntos);
        $this->assertNull($envio->adjuntos_omitidos);
    }

    public function test_un_documento_sin_json_se_envia_solo_con_el_pdf(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $doc = $this->compra(['factura.pdf' => 1024], ['tiene_json' => false]);
        $envio = $this->envio($doc, 'pendiente');

        $this->correr($envio);

        Mail::assertSent(DocumentoRecibidoContabilidadCorreo::class, function (DocumentoRecibidoContabilidadCorreo $m) {
            $this->assertSame(['factura.pdf'], array_map(fn (array $a) => $a['nombre'], $m->archivos));
            $m->assertSeeInHtml('Adjuntos disponibles: factura.pdf.');

            return true;
        });
        $this->assertSame('enviado', $envio->refresh()->estado);
    }

    public function test_nunca_adjunta_un_archivo_que_no_existe_en_disco(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $doc = $this->compra(['factura.pdf' => 1024]);
        // Se agrega a metadata una ruta inexistente (no se inventa nada al enviar).
        $doc->update(['metadata_json' => ['archivos' => array_merge(
            (array) data_get($doc->metadata_json, 'archivos', []),
            ['documentos-recibidos/inexistente/fantasma.json'],
        )]]);

        $envio = $this->envio($doc->refresh(), 'pendiente');
        $this->correr($envio);

        Mail::assertSent(DocumentoRecibidoContabilidadCorreo::class, function (DocumentoRecibidoContabilidadCorreo $m) {
            $this->assertSame(['factura.pdf'], array_map(fn (array $a) => $a['nombre'], $m->archivos));

            return true;
        });
    }

    public function test_un_adjunto_que_no_cabe_se_omite_sin_romper_el_envio(): void
    {
        $this->simularProduccionCorreo();
        config(['documentos_recibidos.adjuntos_max_bytes' => 2048]);
        Mail::fake();
        $doc = $this->compra(['factura.pdf' => 1000, 'dte.json' => 500, 'anexo.xml' => 4000]);
        $envio = $this->envio($doc, 'pendiente');

        $this->correr($envio);

        Mail::assertSent(DocumentoRecibidoContabilidadCorreo::class, function (DocumentoRecibidoContabilidadCorreo $m) {
            $this->assertSame(['factura.pdf', 'dte.json'], array_map(fn (array $a) => $a['nombre'], $m->archivos));
            $m->assertSeeInHtml('No se adjuntaron por el límite de tamaño del correo: anexo.xml');

            return true;
        });
        $envio->refresh();
        $this->assertSame('enviado', $envio->estado);          // el envío NO falla por eso
        $this->assertSame('factura.pdf, dte.json', $envio->adjuntos);
        $this->assertSame('anexo.xml', $envio->adjuntos_omitidos);
    }

    public function test_si_no_cabe_ningun_archivo_no_envia_y_avisa(): void
    {
        Mail::fake();
        Queue::fake();
        config(['documentos_recibidos.adjuntos_max_bytes' => 100]);
        $doc = $this->compra(['factura.pdf' => 5000]);

        $this->actingAs($this->usuario('contabilidad'))
            ->post(route('documentos-recibidos.enviar-contabilidad', $doc))
            ->assertSessionHas('error');

        $this->assertSame(0, DocumentoRecibidoEnvio::count());
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_sin_adjuntos_guardados_no_envia(): void
    {
        Mail::fake();
        Queue::fake();
        $doc = $this->compra([], ['tiene_pdf' => false, 'tiene_json' => false]);

        $this->actingAs($this->usuario('contabilidad'))
            ->post(route('documentos-recibidos.enviar-contabilidad', $doc))
            ->assertSessionHas('error');

        $this->assertSame(0, DocumentoRecibidoEnvio::count());
        Mail::assertNothingSent();
    }

    // --- Asunto y cuerpo ---

    public function test_asunto_y_cuerpo_contable(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $doc = $this->compra();
        $envio = $this->envio($doc, 'pendiente');

        $this->correr($envio);

        Mail::assertSent(DocumentoRecibidoContabilidadCorreo::class, function (DocumentoRecibidoContabilidadCorreo $m) use ($doc) {
            $m->assertHasSubject('Documento recibido para contabilidad — CCF '.$doc->numero_control);
            $m->assertSeeInHtml('Se adjunta un documento recibido para registro contable.', false);
            $m->assertSeeInHtml($doc->emisor_nombre);
            $m->assertSeeInHtml($doc->numero_control);
            $m->assertSeeInHtml('112.00');
            $m->assertSeeInHtml('Adjuntos disponibles: factura.pdf, dte.json.');
            $m->assertDontSeeInHtml('Estimado cliente');

            return $m->hasTo(self::CORREO_CONTA);
        });
    }

    // --- Guardas ---

    public function test_sin_correo_de_contabilidad_no_encola(): void
    {
        Mail::fake();
        Queue::fake();
        Configuracion::set('contabilidad.correo', '');
        Configuracion::olvidarCache();
        $doc = $this->compra();

        $this->actingAs($this->usuario('contabilidad'))
            ->post(route('documentos-recibidos.enviar-contabilidad', $doc))
            ->assertSessionHas('error');

        $this->assertSame(0, DocumentoRecibidoEnvio::count());
        Queue::assertNothingPushed();
    }

    public function test_permisos_del_envio(): void
    {
        Queue::fake();
        $doc = $this->compra();

        foreach (['facturacion', 'jefatura'] as $rol) { // sin contabilidad.enviar
            $this->actingAs($this->usuario($rol))
                ->post(route('documentos-recibidos.enviar-contabilidad', $doc))
                ->assertForbidden();
        }
        $this->assertSame(0, DocumentoRecibidoEnvio::count());

        foreach (['contabilidad', 'administrador'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->post(route('documentos-recibidos.enviar-contabilidad', $this->compra()))
                ->assertRedirect();
        }
        $this->assertSame(2, DocumentoRecibidoEnvio::count());
    }

    public function test_ignorar_sigue_siendo_una_accion_separada(): void
    {
        Queue::fake();
        $doc = $this->compra();

        // Ignorar sigue exigiendo documentos-recibidos.gestionar (administrador).
        $this->actingAs($this->usuario('contabilidad'))
            ->patch(route('documentos-recibidos.ignorar', $doc))->assertForbidden();

        $this->actingAs($this->usuario('administrador'))
            ->patch(route('documentos-recibidos.ignorar', $doc))->assertRedirect();
        $this->assertSame('ignorado', $doc->refresh()->estado);
        $this->assertSame(0, DocumentoRecibidoEnvio::count()); // ignorar no envía nada
    }

    public function test_un_envio_exitoso_no_reactiva_un_documento_ignorado(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $doc = $this->compra(['factura.pdf' => 100], ['estado' => 'ignorado']);
        $envio = $this->envio($doc, 'pendiente');

        $this->correr($envio);

        $this->assertSame('enviado', $envio->refresh()->estado);
        $this->assertSame('ignorado', $doc->refresh()->estado); // el triage manual manda
    }

    // --- Descarga de adjuntos ---

    public function test_abrir_pdf_y_json_guardados(): void
    {
        $doc = $this->compra();
        $user = $this->usuario('contabilidad');

        $this->actingAs($user)->get(route('documentos-recibidos.archivo', [$doc, 'pdf']))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($user)->get(route('documentos-recibidos.archivo', [$doc, 'json']))
            ->assertOk()->assertHeader('content-type', 'application/json');
    }

    public function test_archivo_inexistente_da_404(): void
    {
        $doc = $this->compra(['factura.pdf' => 100], ['tiene_json' => false]);

        $this->actingAs($this->usuario('contabilidad'))
            ->get(route('documentos-recibidos.archivo', [$doc, 'json']))
            ->assertNotFound();
    }

    public function test_tipo_de_archivo_no_permitido_da_404(): void
    {
        $doc = $this->compra();

        $this->actingAs($this->usuario('contabilidad'))
            ->get('/documentos-recibidos/'.$doc->id.'/archivo/exe')
            ->assertNotFound();
    }

    // --- El envío no toca datos del documento ni el buzón ---

    public function test_el_envio_no_cambia_clasificacion_total_ni_adjuntos(): void
    {
        $this->simularProduccionCorreo();
        Mail::fake();
        $doc = $this->compra();
        $antes = [
            'clasificacion' => $doc->clasificacion,
            'total' => (string) $doc->total,
            'numero_control' => $doc->numero_control,
            'sello_recepcion' => (string) $doc->sello_recepcion,
            'archivos' => data_get($doc->metadata_json, 'archivos'),
        ];

        $envio = $this->envio($doc, 'pendiente');
        $this->correr($envio);

        $doc->refresh();
        $this->assertSame($antes['clasificacion'], $doc->clasificacion);
        $this->assertSame($antes['total'], (string) $doc->total);
        $this->assertSame($antes['numero_control'], $doc->numero_control);
        $this->assertSame($antes['sello_recepcion'], (string) $doc->sello_recepcion);
        $this->assertSame($antes['archivos'], data_get($doc->metadata_json, 'archivos'));
        // Los archivos siguen en disco (no se mueven ni se borran).
        foreach ((array) $antes['archivos'] as $ruta) {
            $this->assertTrue(Storage::disk('local')->exists($ruta));
        }
    }
}
