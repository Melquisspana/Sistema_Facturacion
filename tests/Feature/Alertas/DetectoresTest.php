<?php

namespace Tests\Feature\Alertas;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Cliente;
use App\Models\Contingencia;
use App\Models\DocumentoRecibido;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\GmailCuenta;
use App\Models\PuntoVenta;
use App\Models\RespaldoEjecucion;
use App\Models\User;
use App\Services\Alertas\Avisador;
use App\Services\Alertas\Detectores\ContingenciaPorVencer;
use App\Services\Alertas\Detectores\ContingenciaVencida;
use App\Services\Alertas\Detectores\DteRechazados;
use App\Services\Alertas\Detectores\FirmadoSinTransmitir;
use App\Services\Alertas\Detectores\GmailDesconectado;
use App\Services\Alertas\Detectores\LectorSinPing;
use App\Services\Alertas\Detectores\PaqueteMensualPendiente;
use App\Services\Alertas\Detectores\RespaldoFallido;
use App\Services\Alertas\Detectores\TrabajosFallidos;
use App\Services\Contabilidad\AuditoriaPaquete;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Qué cuenta como falla para cada aviso, y que el texto no lleve datos sensibles. */
class DetectoresTest extends TestCase
{
    use RefreshDatabase;

    public function test_trabajos_fallidos_nombra_el_trabajo_sin_sus_datos(): void
    {
        $this->assertNull(app(TrabajosFallidos::class)->revisar());

        foreach ([1, 2] as $i) {
            DB::table('failed_jobs')->insert([
                'uuid' => 'uuid-'.$i, 'connection' => 'database', 'queue' => 'default',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\EnviarDteCorreo', 'data' => 'cliente@secreto.test']),
                'exception' => 'Error con cliente@secreto.test', 'failed_at' => now()->subMinutes(10 * $i),
            ]);
        }

        $h = app(TrabajosFallidos::class)->revisar();

        $this->assertSame('2 trabajos de la cola fallaron', $h->titulo);
        $this->assertSame(2, $h->conteo);
        $this->assertStringContainsString('EnviarDteCorreo (2)', $h->detalle);
        $this->assertStringNotContainsString('secreto', $h->detalle.$h->titulo.$h->queHacer);
    }

    public function test_dte_rechazados_cuenta_solo_los_sin_archivar_del_ambiente_actual(): void
    {
        config(['dte.ambiente' => '00']);
        $this->dte(['estado' => EstadoDte::Rechazado->value]);
        $this->dte(['estado' => EstadoDte::Rechazado->value, 'archivado' => true]);
        $this->dte(['estado' => EstadoDte::Rechazado->value, 'ambiente' => '01']);
        $this->dte(['estado' => EstadoDte::Aceptado->value]);

        $h = app(DteRechazados::class)->revisar();

        $this->assertSame(1, $h->conteo);
        $this->assertStringNotContainsString('CLIENTE', $h->detalle.$h->titulo);
    }

    public function test_contingencia_vencida_pasadas_24_horas_del_cese_sin_sello(): void
    {
        config(['dte.contingencia.enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-02 10:00', 'America/El_Salvador'));

        $contingencia = Contingencia::create([
            'tipo' => 1, 'origen' => 'manual', 'estado' => 'cerrada',
            'inicio' => now()->subHours(30), 'cese' => now()->subHours(23),
        ]);
        $this->dte(['estado' => EstadoDte::Firmado->value, 'contingencia_id' => $contingencia->id]);

        $this->assertTrue(app(ContingenciaVencida::class)->aplica());
        $this->assertNull(app(ContingenciaVencida::class)->revisar(), 'Dentro del plazo no avisa.');

        $this->travel(2)->hours();
        $this->assertSame(1, app(ContingenciaVencida::class)->revisar()->conteo);

        config(['dte.contingencia.enabled' => false]);
        $this->assertFalse(app(ContingenciaVencida::class)->aplica());
    }

    public function test_contingencia_vencida_cuenta_72_horas_del_evento_recibido_y_24_del_rechazo(): void
    {
        // Simulacro del 17/11 (F7): con el aviso recibido y el lote en plazo, a las 24 h
        // del cese llegaba «pasaron el plazo» con 71 h por delante.
        config(['dte.contingencia.enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-02 10:00', 'America/El_Salvador'));
        $contingencia = Contingencia::create([
            'tipo' => 1, 'origen' => 'manual', 'estado' => 'informada',
            'inicio' => now()->subHours(30), 'cese' => now()->subHours(25),
        ]);
        $recibido = $contingencia->eventos()->create(['parte' => 1, 'codigo_generacion' => '00000000-0000-4000-8000-000000000001',
            'estado' => 'recibido', 'fecha_transmision' => now()->subHour(), 'fecha_procesamiento' => now()->subHour(),
            'sello_recibido' => 'SELLO-INVENTADO']);
        $this->dte(['estado' => EstadoDte::Firmado->value, 'contingencia_id' => $contingencia->id, 'contingencia_evento_id' => $recibido->id]);

        $this->assertNull(app(ContingenciaVencida::class)->revisar(), 'Con el aviso recibido corren las 72 h del lote.');
        $this->travel(71)->hours();
        $this->assertNull(app(ContingenciaVencida::class)->revisar(), 'Justo a las 72 h todavía está en plazo.');
        $this->travel(1)->seconds();
        $this->assertSame(1, app(ContingenciaVencida::class)->revisar()?->conteo);

        // Un aviso rechazado da 24 h desde el rechazo para corregirlo, no desde el cese.
        $this->travelTo(Carbon::parse('2026-11-02 10:00', 'America/El_Salvador'));
        $otra = Contingencia::create([
            'tipo' => 1, 'origen' => 'manual', 'estado' => 'cerrada',
            'inicio' => now()->subHours(30), 'cese' => now()->subHours(25),
        ]);
        $rechazado = $otra->eventos()->create(['parte' => 1, 'codigo_generacion' => '00000000-0000-4000-8000-000000000002',
            'estado' => 'rechazado', 'fecha_transmision' => now()->subHours(2), 'rechazado_en' => now()->subHours(2)]);
        Dte::query()->update(['contingencia_id' => $otra->id, 'contingencia_evento_id' => $rechazado->id]);
        $this->assertNull(app(ContingenciaVencida::class)->revisar(), 'Rechazado hace 2 h: le quedan 22 h.');
        $this->travel(22)->hours();
        $this->travel(1)->seconds();
        $this->assertSame(1, app(ContingenciaVencida::class)->revisar()?->conteo);
    }

    public function test_contingencia_por_vencer_avisa_6_horas_antes_de_cada_plazo(): void
    {
        config(['dte.contingencia.enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-02 10:00', 'America/El_Salvador'));
        $contingencia = Contingencia::create([
            'tipo' => 3, 'origen' => 'manual', 'estado' => 'cerrada',
            'inicio' => now()->subHours(20), 'cese' => now()->subHours(18),
        ]);
        $this->dte(['estado' => EstadoDte::Firmado->value, 'contingencia_id' => $contingencia->id]);
        $detector = collect(app(Avisador::class)->detectores())->first(fn ($d) => $d instanceof ContingenciaPorVencer);
        $this->assertNotNull($detector, 'El aviso por correo está registrado.');
        $this->assertTrue($detector->aplica());

        $this->assertNull($detector->revisar(), 'Con 6 h por delante todavía no avisa.');
        $this->travel(1)->seconds();
        $h = $detector->revisar();
        $this->assertSame(1, $h?->conteo);
        $this->assertStringNotContainsString('SECRETO', $h->titulo.$h->detalle.$h->queHacer);
        $this->assertNull(app(ContingenciaVencida::class)->revisar(), 'Por vencer no es vencido.');

        // Con el aviso recibido el plazo pasa a 72 h y deja de estar por vencer.
        $evento = $contingencia->eventos()->create(['parte' => 1, 'codigo_generacion' => '00000000-0000-4000-8000-000000000003',
            'estado' => 'recibido', 'fecha_transmision' => now(), 'fecha_procesamiento' => now(), 'sello_recibido' => 'SELLO-INVENTADO']);
        Dte::query()->update(['contingencia_evento_id' => $evento->id]);
        $this->assertNull($detector->revisar());
        $this->travel(66)->hours();
        $this->assertNull($detector->revisar());
        $this->travel(1)->seconds();
        $this->assertSame(1, $detector->revisar()?->conteo);

        config(['dte.contingencia.enabled' => false]);
        $this->assertFalse($detector->aplica());
    }

    /**
     * Simulacro del 26/11 (F20): el correo «por vencer» no decía a qué hora vencía (su
     * «Desde:» es seis horas antes) y contaba también los vencidos, que tienen su propio
     * correo («pasaron el plazo»): llegaban dos por lo mismo.
     */
    public function test_contingencia_por_vencer_dice_a_que_hora_vence_y_deja_los_vencidos_a_su_aviso(): void
    {
        config(['dte.contingencia.enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-02 11:00', 'America/El_Salvador'));
        foreach (['2026-11-01 16:30', '2026-11-01 08:00'] as $cese) {
            $contingencia = Contingencia::create(['tipo' => 3, 'origen' => 'manual', 'estado' => 'cerrada',
                'inicio' => Carbon::parse($cese, 'America/El_Salvador')->subHour(), 'cese' => Carbon::parse($cese, 'America/El_Salvador')]);
            $this->dte(['estado' => EstadoDte::Firmado->value, 'contingencia_id' => $contingencia->id]);
        }
        $detector = app(ContingenciaPorVencer::class);
        $avisador = app(Avisador::class);

        // Uno vence hoy a las 16:30; el otro venció a las 08:00 y lo avisa ContingenciaVencida.
        $h = $detector->revisar();
        $this->assertSame(1, $h?->conteo);
        $this->assertSame('Sigue sin sello de Hacienda y le quedan menos de 6 horas de plazo: vence el 02/11/2026 a las 16:30.', $h->detalle);
        $this->assertSame(1, app(ContingenciaVencida::class)->revisar()?->conteo);
        $this->assertSame('falla', $avisador->procesar($detector));

        // Al vencer el segundo no llega un «Ya se normalizó» falso: de eso avisa el otro correo.
        $this->travelTo(Carbon::parse('2026-11-02 16:30:01', 'America/El_Salvador'));
        $this->assertNull($detector->revisar());
        $this->assertFalse($detector->aplica());
        $this->assertNull($avisador->procesar($detector));
        $this->assertSame(2, app(ContingenciaVencida::class)->revisar()?->conteo);

        // Con el sello, sí se normaliza.
        DB::table('dtes')->update(['sello_recepcion' => 'SELLO-INVENTADO']);
        $this->assertTrue($detector->aplica());
        $this->assertSame('normalizado', $avisador->procesar($detector));
    }

    public function test_firmado_sin_transmitir_avisa_solo_los_de_produccion_viejos_fuera_de_contingencia(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 09:00', 'America/El_Salvador'));
        $firmadoReal = ['estado' => EstadoDte::Firmado->value, 'ambiente' => '01', 'json_firmado_path' => 'dte/firmados/dte-03.jws'];
        $contingencia = Contingencia::create([
            'tipo' => 1, 'origen' => 'manual', 'estado' => 'activa', 'inicio' => now()->subHour(),
        ]);

        $viejo = $this->dte($firmadoReal);
        $this->dte(array_merge($firmadoReal, ['ambiente' => '00']));
        $this->dte(array_merge($firmadoReal, ['json_firmado_path' => 'dte/firmados/dte-03.mock.jws']));
        $this->dte(array_merge($firmadoReal, ['contingencia_id' => $contingencia->id]));
        $this->dte(array_merge($firmadoReal, ['estado' => EstadoDte::Aceptado->value, 'sello_recepcion' => '2026SELLOREAL0001']));
        $this->dte(array_merge($firmadoReal, ['estado' => EstadoDte::Rechazado->value]));

        $detector = app(FirmadoSinTransmitir::class);
        $this->assertTrue($detector->aplica());

        $this->travel(29)->minutes();
        $this->assertNull($detector->revisar(), 'Antes del plazo no avisa: puede estar transmitiéndose.');

        $this->travel(2)->minutes();
        $this->dte($firmadoReal); // recién firmado: todavía no cuenta

        $h = $detector->revisar();

        $this->assertSame(1, $h->conteo);
        $this->assertSame('1 documento firmado sigue sin transmitir', $h->titulo);
        $this->assertStringContainsString($viejo->numero_control.' del 10/11/2026', $h->detalle);
        $this->assertSame('Abrí cada documento y probá transmitir de nuevo; si Hacienda no contesta, revisá la contingencia.', $h->queHacer);
        $this->assertSame('facturacion.index', $h->enlace);
        $this->assertStringNotContainsString('SECRETO', $h->titulo.$h->detalle.$h->queHacer);
        $this->assertStringNotContainsString('$', $h->titulo.$h->detalle.$h->queHacer, 'Sin montos.');
    }

    public function test_firmado_sin_transmitir_nombra_los_primeros_y_no_repite_el_aviso(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 09:00', 'America/El_Salvador'));
        foreach (range(1, 4) as $i) {
            $this->dte(['estado' => EstadoDte::Firmado->value, 'ambiente' => '01', 'json_firmado_path' => 'dte/firmados/dte-'.$i.'.jws']);
        }
        $this->travel(1)->hour();

        $detector = collect(app(Avisador::class)->detectores())->first(fn ($d) => $d instanceof FirmadoSinTransmitir);
        $this->assertNotNull($detector, 'Está entre los detectores que corre alertas:revisar.');

        $h = $detector->revisar();
        $this->assertSame('4 documentos firmados siguen sin transmitir', $h->titulo);
        $this->assertStringEndsWith('y 1 más.', $h->detalle);

        Mail::fake();
        $this->assertSame('falla', app(Avisador::class)->procesar($detector));
        $this->travel(10)->minutes();
        $this->assertNull(app(Avisador::class)->procesar($detector), 'La misma situación no vuelve a avisar.');
    }

    public function test_respaldo_avisa_si_el_ultimo_fallo_o_si_no_hay_de_hoy_a_la_hora_limite(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 05:00', 'America/El_Salvador'));
        $this->assertNull(app(RespaldoFallido::class)->revisar(), 'Antes de la hora límite no avisa.');

        $this->travelTo(Carbon::parse('2026-11-02 06:30', 'America/El_Salvador'));
        $this->assertSame('No hay respaldo de la base de hoy', app(RespaldoFallido::class)->revisar()->titulo);

        $this->respaldo(true, now()->subMinutes(10));
        $this->assertNull(app(RespaldoFallido::class)->revisar());

        $this->respaldo(false, now()->subMinutes(5));
        $this->assertSame('El respaldo de la base de datos falló', app(RespaldoFallido::class)->revisar()->titulo);
    }

    public function test_gmail_desconectado_avisa_solo_con_la_integracion_prendida(): void
    {
        config(['ppq.gmail.enabled' => false]);
        $this->assertFalse(app(GmailDesconectado::class)->aplica(), 'Con Gmail apagado no opina.');

        config(['ppq.gmail.enabled' => true]);
        $this->assertTrue(app(GmailDesconectado::class)->aplica());
        $this->assertSame('Gmail está desconectado', app(GmailDesconectado::class)->revisar()->titulo, 'Sin cuenta conectada avisa.');

        $cuenta = GmailCuenta::create(['email' => 'correo@example.com', 'refresh_token' => 'token-de-prueba']);
        $this->assertNull(app(GmailDesconectado::class)->revisar(), 'Con la cuenta conectada, todo bien.');

        // Google respondió invalid_grant: GmailClient borra el token.
        $cuenta->marcarDesconectada();
        $h = app(GmailDesconectado::class)->revisar();
        $this->assertSame('Gmail está desconectado', $h->titulo);
        $this->assertStringNotContainsString('correo@example.com', $h->detalle.$h->queHacer, 'El aviso no lleva la cuenta.');
    }

    public function test_paquete_mensual_avisa_si_el_mes_anterior_no_se_envio(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06 10:00', 'America/El_Salvador'));
        $detector = app(PaqueteMensualPendiente::class);

        $this->assertTrue($detector->aplica());
        $this->assertSame('paquete_mensual_pendiente.2026-10', $detector->tipo());
        $this->assertNull($detector->revisar(), 'Un mes sin compras ni ventas no tiene nada que mandar.');

        DocumentoRecibido::create([
            'gmail_message_id' => 'm-octubre', 'emisor_nombre' => 'PROVEEDOR SECRETO', 'tipo_documento' => '03',
            'estado' => 'pendiente', 'total' => 100, 'tiene_pdf' => true, 'tiene_json' => true,
            'fecha_correo' => Carbon::parse('2026-11-01 08:00'), 'fecha_dte' => '2026-10-31',
        ]);

        $h = $detector->revisar();
        $this->assertSame('Falta mandar el paquete de octubre 2026 a contabilidad', $h->titulo);
        $this->assertSame('contabilidad.paquete', $h->enlace);
        $this->assertStringNotContainsString('SECRETO', $h->titulo.$h->detalle.$h->queHacer);

        // Simulado, fallido o de medio mes no cuentan como enviado.
        $usuario = User::factory()->create();
        $auditoria = app(AuditoriaPaquete::class);
        $resumen = ['compras_cantidad' => 1, 'compras_total' => 100, 'ventas_cantidad' => 0, 'ventas_total' => 0];
        $octubre = ['desde' => '2026-10-01', 'hasta' => '2026-10-31', 'etiqueta' => '2026-10'];
        $auditoria->registrar($usuario, 'simulado', 'conta@example.com', $octubre, $resumen, 'p.zip', 'candado');
        $auditoria->registrar($usuario, 'fallido', 'conta@example.com', $octubre, $resumen, 'p.zip', 'drive');
        $auditoria->registrar($usuario, 'enviado', 'conta@example.com', ['desde' => '2026-10-01', 'hasta' => '2026-10-15', 'etiqueta' => '2026-10'], $resumen, 'p.zip', null, 1);
        $this->assertNotNull($detector->revisar());

        $auditoria->registrar($usuario, 'enviado', 'conta@example.com', $octubre, $resumen, 'p.zip', null, 1);
        $this->assertNull($detector->revisar(), 'Con el paquete del mes completo enviado, todo bien.');

        // En diciembre el mes que toca es noviembre: aviso propio, con sus ventas.
        $this->travelTo(Carbon::parse('2026-12-01 09:00', 'America/El_Salvador'));
        $this->assertSame('paquete_mensual_pendiente.2026-11', $detector->tipo());
        $this->assertNull($detector->revisar());
        $this->dte([
            'estado' => EstadoDte::Aceptado->value, 'ambiente' => '01', 'sello_recepcion' => '2026SELLOREAL0001',
            'fecha_procesamiento_mh' => now(), 'fecha_emision' => '2026-11-20',
        ]);
        $this->assertSame('Falta mandar el paquete de noviembre 2026 a contabilidad', $detector->revisar()->titulo);
    }

    public function test_lector_sin_ping_solo_en_horario_laboral(): void
    {
        config(['asistencia.enabled' => true]);
        $lector = AsistenciaDispositivo::create([
            'codigo' => 'lector-entrada', 'nombre' => 'Entrada principal',
            'token_hash' => AsistenciaDispositivo::hashDeToken('token-de-prueba'), 'activo' => true,
        ]);

        // Lunes 2 de noviembre de 2026.
        $this->travelTo(Carbon::parse('2026-11-02 08:10', 'America/El_Salvador'));
        $this->assertFalse(app(LectorSinPing::class)->aplica(), 'Al abrir tiene un margen para reportarse.');

        $this->travelTo(Carbon::parse('2026-11-02 10:00', 'America/El_Salvador'));
        $this->assertTrue(app(LectorSinPing::class)->aplica());
        $h = app(LectorSinPing::class)->revisar();
        $this->assertStringContainsString('«Entrada principal»: nunca se reportó', $h->detalle);

        $lector->forceFill(['ultima_conexion_at' => now()->subMinutes(2)])->save();
        $this->assertNull(app(LectorSinPing::class)->revisar());

        $this->travelTo(Carbon::parse('2026-11-02 17:30', 'America/El_Salvador'));
        $this->assertFalse(app(LectorSinPing::class)->aplica(), 'Lunes después de las 17 no opina.');

        $this->travelTo(Carbon::parse('2026-11-07 11:00', 'America/El_Salvador'));
        $this->assertTrue(app(LectorSinPing::class)->aplica(), 'Sábado por la mañana sí.');

        $this->travelTo(Carbon::parse('2026-11-07 12:30', 'America/El_Salvador'));
        $this->assertFalse(app(LectorSinPing::class)->aplica(), 'Sábado después de las 12 no.');

        $this->travelTo(Carbon::parse('2026-11-08 10:00', 'America/El_Salvador'));
        $this->assertFalse(app(LectorSinPing::class)->aplica(), 'Domingo no.');

        config(['asistencia.enabled' => false]);
        $this->travelTo(Carbon::parse('2026-11-02 10:00', 'America/El_Salvador'));
        $this->assertFalse(app(LectorSinPing::class)->aplica());
    }

    private function respaldo(bool $exitoso, Carbon $cuando): void
    {
        RespaldoEjecucion::create([
            'iniciado_en' => $cuando, 'terminado_en' => $cuando, 'exitoso' => $exitoso,
            'mensaje' => $exitoso ? 'ok' : 'falló', 'origen' => 'automatico',
        ]);
    }

    private function dte(array $override): Dte
    {
        static $n = 0;
        $n++;
        $empresa = Empresa::create(['razon_social' => 'Empresa '.$n, 'ambiente' => '00', 'activo' => true]);
        // Los códigos son de 4 caracteres en la base: «M00» + $n se pasaba desde el décimo
        // documento, y MySQL estricto lo rechaza (SQLite no mira el largo).
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'nombre' => 'Casa', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'nombre' => 'Caja', 'activo' => true]);
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'CLIENTE SECRETO SA']);

        $dte = (new Dte)->forceFill(array_merge([
            'tipo_dte' => TipoDte::CreditoFiscal->value,
            'ambiente' => '00',
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'cliente_id' => $cliente->id,
            'numero_control' => 'DTE-03-M001P001-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => now()->toTimeString(),
            'total_pagar' => 100,
        ], $override));
        $dte->save();

        return $dte;
    }
}
