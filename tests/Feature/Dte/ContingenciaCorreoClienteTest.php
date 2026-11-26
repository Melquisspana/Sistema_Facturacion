<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Facades\Ajustes;
use App\Jobs\EnviarDteCorreo;
use App\Mail\DteCorreo;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\DteEnvio;
use App\Models\Producto;
use App\Models\User;
use App\Observers\DteObserver;
use App\Services\Dte\ContingenciaService;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteFirmaService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DtePdfService;
use App\Services\Dte\EnvioDteCorreoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Simulacro del 17/11 (F8), decisión de Melqui del 26/11: en contingencia el cliente
 * recibe solo el documento provisional al emitirse y el completo cuando Hacienda lo
 * sella, con las mismas condiciones que el envío automático de siempre. Y un PDF
 * preliminar mandado a mano no frena el automático con el documento válido.
 */
class ContingenciaCorreoClienteTest extends TestCase
{
    use PreparaEmisorDte, RefreshDatabase;

    private const CORREO = 'cliente@example.test';

    private User $usuario;

    private array $emisor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-13 10:00:00', 'America/El_Salvador'));
        config(['dte.contingencia.enabled' => true, 'dte.storage.disk' => 'local',
            'dte.firma.enabled' => true, 'dte.firma.mock' => false,
            'dte.firma.nit' => '06141234561018', 'dte.firma.cert_password' => 'clave-inventada',
            'dte.transmision.enabled' => true, 'dte.transmision.real_confirmation' => true,
            'dte.transmision.dry_run' => false, 'dte.transmision.sistema_actual_activo' => false,
            'dte.transmision.modo_operacion' => 'principal', 'dte.transmision.ambiente' => 'testing',
            'dte.transmision.token' => 'Bearer INVENTADO', 'dte.transmision.usuario_testing' => 'usuario-inventado',
            'dte.transmision.password_testing' => 'clave-inventada']);
        Storage::fake('local');
        Queue::fake();
        $this->seedCatalogosDte();
        $this->emisor = $this->crearEmisorDte();
        $this->usuario = User::factory()->create()->assignRole('facturacion');
        $this->actingAs($this->usuario);
        Ajustes::guardarComoSistema('correo.auto_envio', true);
        $this->simular();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Firmador que contesta y Hacienda que no (la recepción cortada, la consulta «no lo
     * tengo»). Sin internet, la sonda tampoco contesta (contingencia tipo 3). Con
     * `$haciendaVolvio`, el login y la recepción responden: la reconexión declara el cese.
     */
    private function simular(bool $internet = true, bool $haciendaVolvio = false): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*generate_204' => $internet ? Http::response('', 204) : Http::failedConnection(),
            '*firmardocumento*' => function ($request) {
                $b64 = fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');

                return Http::response(['status' => 'OK', 'body' => $b64(['alg' => 'RS512']).'.'.$b64($request['dteJson']).'.firma-inventada']);
            },
            '*recepciondte*' => $haciendaVolvio ? Http::response(['estado' => 'RECHAZADO', 'descripcionMsg' => 'Solicitud inventada'], 400) : Http::failedConnection(),
            '*consultadte*' => Http::response([], 404),
            '*seguridad/auth' => $haciendaVolvio ? Http::response(['status' => 'OK', 'body' => ['token' => 'TOKEN-INVENTADO']]) : Http::failedConnection(),
        ]);
    }

    private function documento(bool $firmar = true, ?string $correo = self::CORREO): Dte
    {
        Correlativo::firstOrCreate(['tipo_dte' => '01', 'establecimiento_id' => $this->emisor['estab']->id,
            'punto_venta_id' => $this->emisor['pv']->id, 'ambiente' => '00'], ['ultimo_numero' => 0, 'activo' => true]);
        $borradores = app(DteBorradorService::class);
        $dte = $borradores->crearBorrador(['tipo_dte' => TipoDte::Factura,
            'cliente_id' => Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba', 'correo' => $correo])->id,
            'establecimiento_id' => $this->emisor['estab']->id, 'punto_venta_id' => $this->emisor['pv']->id]);
        $borradores->agregarLineaDesdeProducto($dte, Producto::factory()->create(['precio_unitario' => 10]), cantidad: 1);
        app(DteGeneracionService::class)->generar($dte->refresh());
        if ($firmar) {
            app(DteFirmaService::class)->firmar($dte);
        }

        return $dte->refresh();
    }

    private function emitirEnContingenciaManual(?string $correo = self::CORREO): Dte
    {
        app(ContingenciaService::class)->activar(3, 'Internet inventado', 'manual', null);
        $dte = $this->documento(false, $correo);
        $this->post(route('facturacion.firmar-transmitir', $dte))->assertRedirect()
            ->assertSessionHas('status', 'Documento emitido en contingencia, pendiente de sello de recepción.');
        $this->assertTrue($dte->refresh()->esTransitorio());

        return $dte;
    }

    /** Hacienda lo sella (como el lote o la consulta: el estado pasa a Aceptado). */
    private function sellar(Dte $dte): Dte
    {
        $dte->update(['estado' => EstadoDte::Aceptado, 'sello_recepcion' => 'SELLO-INVENTADO-'.$dte->id,
            'fecha_procesamiento_mh' => now()]);

        return $dte->refresh();
    }

    /** Corre el job real de un envío (correo falso, sin SMTP) y devuelve el envío y el correo armado. */
    private function correr(DteEnvio $envio): array
    {
        Mail::fake();
        $this->simularProduccionCorreo();
        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));
        $correo = null;
        Mail::assertSent(DteCorreo::class, function (DteCorreo $mail) use (&$correo) {
            $correo = $mail;

            return true;
        });

        return [$envio->refresh(), $correo];
    }

    /**
     * Corre el job real con el servidor de correo inalcanzable, como en la contingencia
     * tipo 3: sin internet tampoco hay SMTP (es de afuera). El job no reintenta.
     */
    private function correrSinSalida(DteEnvio $envio): DteEnvio
    {
        $this->simularProduccionCorreo();
        $real = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->andThrow(new TransportException('Connection could not be established with host "smtp.example.test:587"'));
        (new EnviarDteCorreo($envio->id))->handle(app(DtePdfService::class));
        Mail::swap($real);

        return $envio->refresh();
    }

    private function enviosAlCliente(Dte $dte)
    {
        return DteEnvio::where('dte_id', $dte->id)->orderBy('id')->get();
    }

    public function test_a_al_emitir_en_contingencia_manual_se_encola_solo_el_provisional_al_cliente(): void
    {
        $dte = $this->emitirEnContingenciaManual();

        Queue::assertPushed(EnviarDteCorreo::class, 1);
        $envios = $this->enviosAlCliente($dte);
        $this->assertCount(1, $envios);
        $this->assertSame(self::CORREO, $envios[0]->destinatario);
        $this->assertSame(DteEnvio::CANAL_CLIENTE, $envios[0]->canal);
        $this->assertSame('pendiente', $envios[0]->estado);
        $this->assertNull($envios[0]->user_id, 'Envío automático (sistema).');

        // Volver a apretar «Firmar y transmitir» no lo repite.
        $this->post(route('facturacion.firmar-transmitir', $dte))->assertRedirect();
        Queue::assertPushed(EnviarDteCorreo::class, 1);
        $this->assertCount(1, $this->enviosAlCliente($dte));
    }

    public function test_a_al_entrar_sola_en_contingencia_se_encola_el_provisional(): void
    {
        $otro = $this->documento(); // firmado antes de la contingencia: lo convierte la tarea
        $dte = $this->documento(false);
        $this->post(route('facturacion.firmar-transmitir', $dte))->assertRedirect()
            ->assertSessionHas('status', 'Documento emitido en contingencia, pendiente de sello de recepción.');
        $this->assertTrue($dte->refresh()->esTransitorio());
        $this->assertSame('automatica', $dte->contingencia->origen);

        Queue::assertPushed(EnviarDteCorreo::class, 1);
        $this->assertCount(1, $this->enviosAlCliente($dte));
        $this->assertCount(0, $this->enviosAlCliente($otro), 'Firmado sin contingencia: todavía no es provisional.');

        // Por la tarea de transmisión también.
        $this->artisan('dte:transmitir', ['dte' => $otro->id])->assertSuccessful();
        $this->assertTrue($otro->refresh()->esTransitorio());
        $this->assertCount(1, $this->enviosAlCliente($otro));
        Queue::assertPushed(EnviarDteCorreo::class, 2);
    }

    public function test_a_con_el_envio_automatico_apagado_o_sin_correo_no_se_encola_nada(): void
    {
        Ajustes::guardarComoSistema('correo.auto_envio', false);
        $dte = $this->emitirEnContingenciaManual();
        $this->assertCount(0, $this->enviosAlCliente($dte));

        Ajustes::guardarComoSistema('correo.auto_envio', true);
        $sinCorreo = $this->documento(false, null);
        $this->post(route('facturacion.firmar-transmitir', $sinCorreo))->assertRedirect();
        $this->assertTrue($sinCorreo->refresh()->esTransitorio());
        $this->assertCount(0, $this->enviosAlCliente($sinCorreo));

        // Al sellarse tampoco, por las mismas razones.
        $this->sellar($sinCorreo);
        Ajustes::guardarComoSistema('correo.auto_envio', false);
        $this->sellar($dte);
        $this->assertDatabaseCount('dte_envios', 0);
        Queue::assertNothingPushed();
    }

    public function test_a_y_f_el_provisional_lleva_pdf_y_json_firmado_y_dice_que_es_provisional(): void
    {
        $dte = $this->emitirEnContingenciaManual();
        [$envio, $correo] = $this->correr($this->enviosAlCliente($dte)->sole());

        $this->assertSame('enviado', $envio->estado);
        $this->assertSame(DteEnvio::PDF_PROVISIONAL.', JSON', $envio->adjuntos);
        $json = json_decode($correo->adjuntosExtra[0]['contenido'], true);
        $this->assertArrayHasKey('firmaElectronica', $json);
        $this->assertArrayNotHasKey('selloRecibido', $json);
        $html = $correo->render();
        $this->assertStringContainsString('Documento provisional', $html);
        $this->assertStringContainsString('le enviaremos el documento definitivo cuando Hacienda lo selle', $html);
    }

    /**
     * Contingencia tipo 3 (sin internet): el provisional sale apenas se emite y falla,
     * porque el servidor de correo también es de afuera, y el job no reintenta. Al
     * terminar la contingencia ya hay conexión: el que no salió se manda otra vez.
     */
    public function test_a_sin_internet_el_provisional_que_fallo_sale_otra_vez_al_terminar_la_contingencia(): void
    {
        $dte = $this->emitirEnContingenciaManual();
        $this->assertSame(3, $dte->contingencia->tipo);
        $fallido = $this->correrSinSalida($this->enviosAlCliente($dte)->sole());
        $this->assertSame('error', $fallido->estado);

        app(ContingenciaService::class)->terminar(null);

        Queue::assertPushed(EnviarDteCorreo::class, 2);
        $envios = $this->enviosAlCliente($dte);
        $this->assertCount(2, $envios);
        $this->assertSame('error', $envios[0]->estado, 'El intento fallido queda en el historial.');
        $this->assertSame('pendiente', $envios[1]->estado);
        $this->assertSame(self::CORREO, $envios[1]->destinatario);
        $this->assertSame(DteEnvio::CANAL_CLIENTE, $envios[1]->canal);
        $this->assertNull($envios[1]->user_id, 'Envío automático (sistema).');
        [$reintento, $correo] = $this->correr($envios[1]);
        $this->assertSame('enviado', $reintento->estado);
        $this->assertSame(DteEnvio::PDF_PROVISIONAL.', JSON', $reintento->adjuntos);
        $this->assertStringContainsString('Documento provisional', $correo->render());
    }

    /** Lo mismo cuando la contingencia la terminó sola la reconexión (entrada automática). */
    public function test_a_sin_internet_el_provisional_que_fallo_sale_otra_vez_cuando_vuelve_la_conexion(): void
    {
        $dte = $this->documento(false);
        $this->simular(internet: false);
        $this->post(route('facturacion.firmar-transmitir', $dte))->assertRedirect()
            ->assertSessionHas('status', 'Documento emitido en contingencia, pendiente de sello de recepción.');
        $this->assertTrue($dte->refresh()->esTransitorio());
        $this->assertSame(3, $dte->contingencia->tipo);
        $this->assertSame('automatica', $dte->contingencia->origen);
        $this->assertSame('error', $this->correrSinSalida($this->enviosAlCliente($dte)->sole())->estado);

        // Mientras no vuelve, nada.
        $this->artisan('dte:contingencia-reconexion')->assertSuccessful();
        $this->assertCount(1, $this->enviosAlCliente($dte));

        Carbon::setTestNow(now()->addMinutes(15));
        $this->simular(haciendaVolvio: true);
        $this->artisan('dte:contingencia-reconexion')->assertSuccessful();
        $this->assertSame('cerrada', $dte->contingencia->refresh()->estado);
        $envios = $this->enviosAlCliente($dte);
        $this->assertCount(2, $envios);
        $this->assertSame('pendiente', $envios[1]->estado);
        $this->assertNull($envios[1]->user_id);

        // Una sola vez por cese: la vuelta siguiente no lo repite.
        Carbon::setTestNow(now()->addMinutes(15));
        $this->artisan('dte:contingencia-reconexion')->assertSuccessful();
        $this->assertCount(2, $this->enviosAlCliente($dte));
        Queue::assertPushed(EnviarDteCorreo::class, 2);
    }

    /** Al terminar solo se reintenta lo que falló, y con las mismas condiciones de siempre. */
    public function test_a_al_terminar_solo_se_reintenta_el_provisional_que_fallo(): void
    {
        $salio = $this->emitirEnContingenciaManual();
        $this->correr($this->enviosAlCliente($salio)->sole());

        Ajustes::guardarComoSistema('correo.auto_envio', false);
        $nuncaSalio = $this->emitirEnContingenciaManualSinActivar();
        Ajustes::guardarComoSistema('correo.auto_envio', true);

        $fallo = $this->emitirEnContingenciaManualSinActivar();
        $this->correrSinSalida($this->enviosAlCliente($fallo)->sole());

        app(ContingenciaService::class)->terminar(null);

        $this->assertCount(1, $this->enviosAlCliente($salio), 'El que salió no se repite.');
        $this->assertCount(0, $this->enviosAlCliente($nuncaSalio), 'Sin intento al emitirse, no se inventa uno.');
        $this->assertCount(2, $this->enviosAlCliente($fallo));
        Queue::assertPushed(EnviarDteCorreo::class, 3);
    }

    public function test_a_con_el_envio_automatico_apagado_al_terminar_no_se_reintenta(): void
    {
        $dte = $this->emitirEnContingenciaManual();
        $this->correrSinSalida($this->enviosAlCliente($dte)->sole());
        Ajustes::guardarComoSistema('correo.auto_envio', false);

        app(ContingenciaService::class)->terminar(null);

        $this->assertCount(1, $this->enviosAlCliente($dte));
        Queue::assertPushed(EnviarDteCorreo::class, 1);
    }

    public function test_b_al_regularizarse_sale_el_completo_aunque_ya_salio_el_provisional(): void
    {
        $dte = $this->emitirEnContingenciaManual();
        $this->correr($this->enviosAlCliente($dte)->sole());
        app(ContingenciaService::class)->terminar(null);

        $this->sellar($dte);

        Queue::assertPushed(EnviarDteCorreo::class, 2);
        $envios = $this->enviosAlCliente($dte);
        $this->assertCount(2, $envios);
        $this->assertSame('pendiente', $envios[1]->estado);
        [$completo, $correo] = $this->correr($envios[1]);
        $this->assertSame('enviado', $completo->estado);
        $this->assertStringStartsWith('PDF', $completo->adjuntos);
        $this->assertFalse(str_starts_with($completo->adjuntos, DteEnvio::PDF_PROVISIONAL));
        $this->assertTrue($completo->llevoDocumentoCompleto());
        $html = $correo->render();
        $this->assertStringNotContainsString('Documento provisional', $html);
        $this->assertStringContainsString('SELLO-INVENTADO-'.$dte->id, $html);
    }

    public function test_b_si_el_provisional_todavia_no_salio_al_sellarse_no_se_encola_otro(): void
    {
        $dte = $this->emitirEnContingenciaManual();
        $this->sellar($dte);

        // El pendiente arma el PDF al correr: ya sale el completo, una sola vez.
        Queue::assertPushed(EnviarDteCorreo::class, 1);
        [$envio] = $this->correr($this->enviosAlCliente($dte)->sole());
        $this->assertTrue($envio->llevoDocumentoCompleto());
    }

    public function test_c_un_preliminar_mandado_a_mano_no_frena_el_completo_al_aceptarse(): void
    {
        // Documento en duda (sin contingencia): alguien le manda a mano el PDF preliminar.
        $dte = $this->documento();
        $this->assertNull($dte->contingencia_id);
        $this->assertCount(0, $this->enviosAlCliente($dte));
        $manual = app(EnvioDteCorreoService::class)->encolar($dte, [self::CORREO], $this->usuario->id);
        [$manual] = $this->correr($manual);
        $this->assertSame('enviado', $manual->estado);
        $this->assertStringStartsWith(DteEnvio::PDF_PRELIMINAR, $manual->adjuntos);
        $this->assertFalse($manual->llevoDocumentoCompleto());

        $this->sellar($dte);

        Queue::assertPushed(EnviarDteCorreo::class, 2);
        $automatico = $this->enviosAlCliente($dte)->last();
        $this->assertSame('pendiente', $automatico->estado);
        $this->assertNull($automatico->user_id);
    }

    public function test_d_nunca_dos_correos_del_documento_completo(): void
    {
        $dte = $this->emitirEnContingenciaManual();
        $this->correr($this->enviosAlCliente($dte)->sole());
        $this->sellar($dte);
        $this->correr($this->enviosAlCliente($dte)->last());
        $this->assertCount(2, $this->enviosAlCliente($dte));

        // Repetir lo que cuelga de la aceptación (la consulta que completa el sello lo hace) no lo duplica.
        app(DteObserver::class)->alQuedarAceptado($dte->refresh());
        $this->assertCount(2, $this->enviosAlCliente($dte));

        // Con el completo todavía pendiente, tampoco.
        $otro = $this->emitirEnContingenciaManualSinActivar();
        $this->correr($this->enviosAlCliente($otro)->sole());
        $this->sellar($otro);
        app(DteObserver::class)->alQuedarAceptado($otro->refresh());
        $this->assertCount(2, $this->enviosAlCliente($otro));
        Queue::assertPushed(EnviarDteCorreo::class, 4);
    }

    private function emitirEnContingenciaManualSinActivar(): Dte
    {
        $dte = $this->documento(false);
        $this->post(route('facturacion.firmar-transmitir', $dte))->assertRedirect();
        $this->assertTrue($dte->refresh()->esTransitorio());

        return $dte;
    }

    public function test_e_fuera_de_contingencia_y_con_el_interruptor_apagado_no_cambia_nada(): void
    {
        // Encendido, sin contingencia: al firmar no sale nada; al aceptarse, uno solo.
        $normal = $this->documento();
        $this->assertCount(0, $this->enviosAlCliente($normal));
        $this->sellar($normal);
        $this->assertCount(1, $this->enviosAlCliente($normal));

        // Apagado: la contingencia no existe para nadie. Al firmar un documento que quedó
        // con contingencia no sale nada, y al aceptarse, uno solo, como siempre.
        $activa = app(ContingenciaService::class)->activar(3, 'Internet inventado', 'manual', null);
        $viejo = $this->documento(false);
        $this->assertSame($activa->id, $viejo->contingencia_id);
        config(['dte.contingencia.enabled' => false]);
        app(DteFirmaService::class)->firmar($viejo);
        $this->assertFalse($viejo->refresh()->esTransitorio());
        $this->assertCount(0, $this->enviosAlCliente($viejo));
        $this->sellar($viejo);
        $this->assertCount(1, $this->enviosAlCliente($viejo));
        Queue::assertPushed(EnviarDteCorreo::class, 2);
    }
}
