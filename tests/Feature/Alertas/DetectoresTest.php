<?php

namespace Tests\Feature\Alertas;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Cliente;
use App\Models\Contingencia;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\GmailCuenta;
use App\Models\PuntoVenta;
use App\Models\RespaldoEjecucion;
use App\Services\Alertas\Detectores\ContingenciaVencida;
use App\Services\Alertas\Detectores\DteRechazados;
use App\Services\Alertas\Detectores\GmailDesconectado;
use App\Services\Alertas\Detectores\LectorSinPing;
use App\Services\Alertas\Detectores\RespaldoFallido;
use App\Services\Alertas\Detectores\TrabajosFallidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M00'.$n, 'nombre' => 'Casa', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P00'.$n, 'nombre' => 'Caja', 'activo' => true]);
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
