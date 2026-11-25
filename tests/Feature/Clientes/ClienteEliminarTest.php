<?php

namespace Tests\Feature\Clientes;

use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoPpq;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\PedidoSala;
use App\Models\PpqLote;
use App\Models\Producto;
use App\Models\User;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreaDteVerificableCobros;
use Tests\TestCase;

/**
 * «Eliminar cliente» en la lista de clientes (`clientes.destroy`).
 *
 * El borrado es suave, pero el cliente borrado desaparece de todo lo que lo busca: sus CCF,
 * sus salas, sus cobros y sus PPQ quedaban «sin cliente». Como con las salas, un cliente sin
 * nada se borra y uno con algo no: se avisa en su ficha y se ofrece inactivarlo.
 */
class ClienteEliminarTest extends TestCase
{
    use CreaDteVerificableCobros;
    use RefreshDatabase;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba']);
    }

    private function usuario(RolSistema $rol = RolSistema::Administrador): User
    {
        return User::factory()->create()->assignRole($rol->value);
    }

    private function eliminar(User $usuario)
    {
        return $this->actingAs($usuario)->from(route('clientes.index'))->delete(route('clientes.destroy', $this->cliente));
    }

    public function test_un_cliente_sin_nada_se_borra_de_forma_suave_como_hasta_hoy(): void
    {
        $this->eliminar($this->usuario())->assertRedirect(route('clientes.index'))
            ->assertSessionHas('status', 'Cliente eliminado.');

        $this->assertSoftDeleted('clientes', ['id' => $this->cliente->id]);
    }

    /** @return array<string, array{0: string, 1: string}> lo que tiene y cómo lo nombra el aviso */
    public static function movimientos(): array
    {
        return [
            'un CCF' => ['dte', 'documentos'],
            'una sala' => ['sala', 'salas'],
            'un pedido de una sala ya borrada' => ['pedido', 'pedidos'],
            'un documento en cobro sin CCF local' => ['cobro', 'documentos en cobro'],
            'una solicitud de cobro' => ['solicitud', 'solicitudes de cobro'],
            'un PPQ' => ['ppq', 'PPQ'],
            'un precio especial' => ['precio', 'precios especiales'],
            'un precio especial inactivo' => ['precio_inactivo', 'precios especiales'],
        ];
    }

    private function crearMovimiento(string $tipo): void
    {
        $id = $this->cliente->id;

        match ($tipo) {
            'dte' => $this->dteVerificableCobros($this->cliente, 'DTE-03-M001P002-000000000000201', '2026-11-09', '25.00'),
            'sala' => ClienteSucursal::factory()->create(['cliente_id' => $id, 'nombre' => 'Sala de prueba Sur']),
            'pedido' => (function () use ($id) {
                $sala = ClienteSucursal::factory()->create(['cliente_id' => $id]);
                PedidoSala::create(['cliente_sucursal_id' => $sala->id, 'registrado_por' => $this->usuario(RolSistema::Facturacion)->id,
                    'fecha' => '2026-11-09', 'estado' => EstadoPedidoSala::Registrado]);
                $sala->delete(); // de antes de la regla de las salas
            })(),
            'cobro' => CobroDocumento::create(['cliente_id' => $id, 'origen' => OrigenCobroDocumento::Externo->value,
                'tipo_dte' => '03', 'numero_control' => 'DTE-03-M001P001-000000000000202', 'fecha_emision' => '2026-09-10',
                'monto' => '25.00']),
            'solicitud' => CobroSolicitud::create(['cliente_id' => $id, 'referencia' => 'SOL-PRUEBA-20261109-01',
                'formato' => 'carga_masiva_v1', 'archivo_nombre' => 'prueba.xlsx', 'estado' => 'generada']),
            'ppq' => PpqLote::create(['referencia' => 'PPQ de prueba', 'fecha' => '2026-11-09',
                'estado' => EstadoPpq::Borrador->value, 'cliente_id' => $id]),
            'precio', 'precio_inactivo' => (function () use ($id, $tipo) {
                $this->seed(CatalogosMhSeeder::class);
                Producto::factory()->create()->preciosCliente()->create(['cliente_id' => $id, 'precio' => '1.40',
                    'activo' => $tipo === 'precio']);
            })(),
        };
    }

    #[DataProvider('movimientos')]
    public function test_un_cliente_con_movimientos_no_se_borra_y_la_ficha_dice_por_que(string $tipo, string $nombre): void
    {
        $this->crearMovimiento($tipo);
        $admin = $this->usuario();

        $this->eliminar($admin)->assertRedirect(route('clientes.show', $this->cliente))
            ->assertSessionMissing('status')
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'No se eliminó el cliente «Cliente de prueba»')
                && str_contains($m, $nombre) && str_contains($m, 'Inactivalo'));

        $this->assertNull($this->cliente->fresh()->deleted_at);
        $this->assertTrue($this->cliente->fresh()->activo, 'Negarse no lo inactiva solo.');
        $this->assertSame(0, Activity::where('subject_type', $this->cliente->getMorphClass())
            ->where('subject_id', $this->cliente->id)->where('event', 'deleted')->count());

        $this->get(route('clientes.show', $this->cliente))->assertOk()
            ->assertSee('No se eliminó el cliente «Cliente de prueba»', false);
    }

    public function test_inactivarlo_sigue_permitido_aunque_tenga_movimientos(): void
    {
        $this->crearMovimiento('dte');

        $this->actingAs($this->usuario())->from(route('clientes.show', $this->cliente))
            ->patch(route('clientes.toggle-activo', $this->cliente))->assertSessionHas('status', 'Cliente inactivado.');

        $this->assertFalse($this->cliente->fresh()->activo);
        $this->assertNull($this->cliente->fresh()->deleted_at);
    }

    public function test_un_rol_sin_gestion_de_clientes_recibe_403_y_no_borra(): void
    {
        foreach ([RolSistema::Facturacion, RolSistema::Jefatura, RolSistema::Contabilidad, RolSistema::Vendedor] as $rol) {
            $this->eliminar($this->usuario($rol))->assertForbidden();
        }

        $this->assertNull($this->cliente->fresh()->deleted_at);
    }
}
