<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Operación de la cola de correos en la UI (solo lectura):
 *  - Aviso en la ficha (solo gestores) cuando hay jobs pendientes >5 min (worker apagado).
 *  - Badge del estado del último envío de correo por documento en el listado.
 * No envía nada, no toca la cola ni el worker.
 */
class DteColaCorreosUiTest extends TestCase
{
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // El listado principal muestra SOLO producción (ambiente 01): los CCF de estas pruebas
        // (badge de correo en el listado) deben nacer en producción para aparecer.
        config(['dte.ambiente' => '01']);

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $this->estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Matriz', 'activo' => true]);
        $this->pv = PuntoVenta::create(['establecimiento_id' => $this->estab->id, 'codigo' => 'P001', 'nombre' => 'Caja', 'activo' => true]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** Borrador CCF mínimo (no se genera JSON aquí; el aviso/badge no dependen del estado). */
    private function ccf(): Dte
    {
        return app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => Cliente::factory()->contribuyente()->create()->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);
    }

    /** Inserta un job pendiente en la tabla jobs con la antigüedad dada. */
    private function jobPendiente(int $minutos): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{"displayName":"App\\\\Jobs\\\\EnviarDteCorreo"}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->subMinutes($minutos)->getTimestamp(),
        ]);
    }

    // --- P0.2: aviso de worker apagado / correos atascados (ficha) ---

    public function test_gestor_ve_aviso_con_job_pendiente_viejo(): void
    {
        $this->jobPendiente(10);
        $dte = $this->ccf();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $dte))
            ->assertOk()
            ->assertSee('correo en cola sin procesar')
            ->assertSee('start-queue.bat');
    }

    public function test_con_cola_vacia_no_hay_aviso(): void
    {
        $dte = $this->ccf();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $dte))
            ->assertOk()
            ->assertDontSee('start-queue.bat');
    }

    public function test_job_reciente_no_dispara_el_aviso(): void
    {
        $this->jobPendiente(0); // recién encolado: el worker puede estar por tomarlo
        $dte = $this->ccf();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $dte))
            ->assertOk()
            ->assertDontSee('start-queue.bat');
    }

    public function test_lector_no_ve_el_aviso_aunque_haya_jobs_viejos(): void
    {
        $this->jobPendiente(10);
        $dte = $this->ccf();

        $this->actingAs($this->usuario('consulta'))
            ->get(route('facturacion.show', $dte))
            ->assertOk()
            ->assertDontSee('start-queue.bat');
    }

    public function test_aviso_cuenta_varios_correos(): void
    {
        $this->jobPendiente(10);
        $this->jobPendiente(7);
        $dte = $this->ccf();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $dte))
            ->assertOk()
            ->assertSee('Hay 2 correos en cola sin procesar');
    }

    // --- P0.3: badge del último envío en el listado ---

    public function test_listado_muestra_estado_del_ultimo_envio_por_documento(): void
    {
        $enviado = $this->ccf();
        $enviado->envios()->create(['destinatario' => 'a@a.com', 'destinatarios' => ['a@a.com'], 'estado' => 'enviado']);

        $pendiente = $this->ccf();
        $pendiente->envios()->create(['destinatario' => 'b@b.com', 'destinatarios' => ['b@b.com'], 'estado' => 'pendiente']);

        $fallido = $this->ccf();
        $fallido->envios()->create(['destinatario' => 'c@c.com', 'destinatarios' => ['c@c.com'], 'estado' => 'error', 'error' => 'SMTP caído']);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.index'))
            ->assertOk()
            ->assertSee('Correo')     // encabezado de la columna
            ->assertSee('Enviado')
            ->assertSee('Pendiente')
            ->assertSee('Fallido');
    }

    public function test_badge_usa_el_ultimo_envio_no_el_primero(): void
    {
        $dte = $this->ccf();
        // Primero falló; el reenvío posterior salió bien → el badge debe decir Enviado.
        $dte->envios()->create(['destinatario' => 'a@a.com', 'destinatarios' => ['a@a.com'], 'estado' => 'error', 'error' => 'SMTP caído']);
        $dte->envios()->create(['destinatario' => 'a@a.com', 'destinatarios' => ['a@a.com'], 'estado' => 'enviado']);

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.index'))
            ->assertOk()
            ->assertSee('Enviado')
            ->assertDontSee('Fallido');
    }

    public function test_sin_envios_no_muestra_badge_de_correo(): void
    {
        $this->ccf(); // sin envíos → la celda Correo muestra el guion

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.index'))
            ->assertOk()
            ->assertSee('Correo')       // la columna existe
            ->assertDontSee('Pendiente')
            ->assertDontSee('Fallido')
            ->assertDontSee('Simulado');
    }
}
