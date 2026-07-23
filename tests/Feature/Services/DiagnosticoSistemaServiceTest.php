<?php

namespace Tests\Feature\Services;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\RespaldoEjecucion;
use App\Services\Sistema\DiagnosticoSistemaService;
use App\Support\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Diagnóstico real del Dashboard: nunca hace red, y clasifica correcto/advertencia/
 * crítico por señal real (no por "cola vacía" ni "correo automático desactivado").
 */
class DiagnosticoSistemaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkerHeartbeat::olvidar();
    }

    private function respaldoValidoHoy(): void
    {
        RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true,
            'archivo_ruta' => 'auto-test.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'mensaje' => 'ok', 'origen' => 'automatico',
        ]);
    }

    private function servicio(): DiagnosticoSistemaService
    {
        return app(DiagnosticoSistemaService::class);
    }

    public function test_todo_verde_da_nivel_correcto(): void
    {
        WorkerHeartbeat::pulse();
        $this->respaldoValidoHoy();

        $d = $this->servicio()->evaluar();

        $this->assertSame('correcto', $d['nivel'], implode(' | ', array_map(fn ($c) => $c['clave'].'='.$c['nivel'], $d['checks'])));
        foreach ($d['checks'] as $c) {
            $this->assertSame('correcto', $c['nivel'], $c['clave'].': '.$c['detalle']);
        }
    }

    public function test_failed_jobs_es_critico_y_domina_el_nivel_global(): void
    {
        WorkerHeartbeat::pulse();
        $this->respaldoValidoHoy();
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'fake', 'failed_at' => now(),
        ]);

        $d = $this->servicio()->evaluar();

        $this->assertSame('critico', $d['nivel']);
        $check = collect($d['checks'])->firstWhere('clave', 'jobs_fallidos');
        $this->assertSame('critico', $check['nivel']);
    }

    public function test_backup_vencido_es_critico(): void
    {
        WorkerHeartbeat::pulse();
        RespaldoEjecucion::create([
            'iniciado_en' => now()->subDay(), 'terminado_en' => now()->subDay(), 'exitoso' => true,
            'archivo_ruta' => 'auto-ayer.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'mensaje' => 'ok', 'origen' => 'automatico',
        ]);

        $d = $this->servicio()->evaluar();

        $this->assertSame('critico', $d['nivel']);
        $this->assertSame('critico', collect($d['checks'])->firstWhere('clave', 'backup')['nivel']);
    }

    public function test_worker_sin_datos_con_cola_vacia_no_es_critico(): void
    {
        // Sin pulso nunca (sin_datos) pero cola vacía: advertencia, nunca crítico ni
        // falso verde (ver WorkerHeartbeat::diagnostico()).
        $this->respaldoValidoHoy();

        $d = $this->servicio()->evaluar();

        $this->assertNotSame('critico', $d['nivel']);
        $this->assertSame('advertencia', collect($d['checks'])->firstWhere('clave', 'worker')['nivel']);
    }

    public function test_migracion_pendiente_es_critica(): void
    {
        WorkerHeartbeat::pulse();
        $this->respaldoValidoHoy();
        DB::table('migrations')->where('id', DB::table('migrations')->max('id'))->delete();

        $d = $this->servicio()->evaluar();

        $this->assertSame('critico', $d['nivel']);
        $this->assertSame('critico', collect($d['checks'])->firstWhere('clave', 'migraciones')['nivel']);
    }

    public function test_correo_automatico_desactivado_no_afecta_el_diagnostico(): void
    {
        // No existe ningún check de correo en este servicio: confirmamos que activar/
        // desactivar ese flag no cambia el nivel global (nunca debería, no se lee acá).
        WorkerHeartbeat::pulse();
        $this->respaldoValidoHoy();
        \App\Models\Configuracion::set('correo.auto_envio', false);

        $d = $this->servicio()->evaluar();

        $this->assertSame('correcto', $d['nivel']);
    }

    public function test_ambiente_y_punto_de_venta_nunca_son_criticos_aunque_p002_este_separado_de_conta(): void
    {
        WorkerHeartbeat::pulse();
        $this->respaldoValidoHoy();
        config(['dte.punto_venta_predeterminado' => 'P002']);

        $d = $this->servicio()->evaluar();

        $this->assertSame('correcto', collect($d['checks'])->firstWhere('clave', 'ambiente')['nivel']);
    }

    public function test_correlativo_p002_critico_si_se_emitio_en_produccion_sin_correlativo_activo(): void
    {
        WorkerHeartbeat::pulse();
        $this->respaldoValidoHoy();
        config(['dte.punto_venta_predeterminado' => '']); // único PV activo se resuelve solo

        $empresa = Empresa::create(['razon_social' => 'Test', 'ambiente' => '01', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P002', 'nombre' => 'Caja', 'activo' => true]);
        $cliente = Cliente::factory()->contribuyente()->create();

        Dte::create([
            'tipo_dte' => TipoDte::CreditoFiscal->value, 'estado' => EstadoDte::Aceptado->value, 'ambiente' => '01',
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $cliente->id,
            'numero_control' => 'DTE-03-M001P002-000000000000001', 'sello_recepcion' => 'SELLO-REAL-XYZ',
            'fecha_emision' => now(), 'hora_emision' => '10:00:00', 'total_pagar' => 10,
        ]);
        // Sin ninguna fila Correlativo para tipo 03/ambiente 01: inconsistencia real.

        $d = $this->servicio()->evaluar();

        $this->assertSame('critico', $d['nivel']);
        $this->assertSame('critico', collect($d['checks'])->firstWhere('clave', 'correlativos_p002')['nivel']);
    }
}
