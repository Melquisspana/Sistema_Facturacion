<?php

namespace Tests\Feature\Clientes;

use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaDteVerificableCobros;
use Tests\TestCase;

/**
 * Recorrido de las salas de un cliente en su ficha, como lo hace la oficina: borrar una sala
 * vacía, intentar borrar una con una NC, inactivarla en su lugar, y recién sin salas ni
 * documentos poder eliminar el cliente. Complementa {@see ClienteSucursalEliminarTest} y
 * {@see ClienteEliminarTest}. Datos inventados.
 */
class SalaEliminarRecorridoTest extends TestCase
{
    use CreaDteVerificableCobros;
    use RefreshDatabase;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba']);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole(RolSistema::Administrador->value);
    }

    private function eliminarSala(User $usuario, ClienteSucursal $sala)
    {
        return $this->actingAs($usuario)->from(route('clientes.show', $this->cliente))
            ->delete(route('clientes.sucursales.destroy', [$this->cliente, $sala]));
    }

    public function test_sala_vacia_se_borra_la_que_tiene_una_nc_no_y_se_inactiva_en_su_lugar(): void
    {
        $admin = $this->admin();
        $vacia = ClienteSucursal::factory()->create(['cliente_id' => $this->cliente->id, 'nombre' => 'Sala de prueba Vacía']);
        $conNc = ClienteSucursal::factory()->create(['cliente_id' => $this->cliente->id, 'nombre' => 'Sala de prueba Centro']);
        $nc = $this->dteVerificableCobros($this->cliente, 'DTE-05-M001P002-000000000000301', '2026-11-09', '5.00', '05');
        $nc->forceFill(['cliente_sucursal_id' => $conNc->id])->saveQuietly();

        // La vacía se borra; el doble clic ya no la encuentra.
        $this->eliminarSala($admin, $vacia)->assertSessionHas('status', 'Sala eliminada.');
        $this->assertSoftDeleted('cliente_sucursales', ['id' => $vacia->id]);
        $this->eliminarSala($admin, $vacia)->assertNotFound();

        // La de la NC no, y la ficha lo dice.
        $this->eliminarSala($admin, $conNc)->assertSessionHas('error', fn (string $m) => str_contains($m, 'tiene documentos'));
        $this->actingAs($admin)->get(route('clientes.show', $this->cliente))->assertOk()
            ->assertSee('No se eliminó la sala «Sala de prueba Centro»: tiene documentos', false);
        $this->assertTrue($nc->fresh()->clienteSucursal->is($conNc));

        // Inactivarla sí deja; inactiva tampoco se borra.
        $this->actingAs($admin)->from(route('clientes.show', $this->cliente))
            ->patch(route('clientes.sucursales.toggle-activo', [$this->cliente, $conNc]))->assertSessionHas('status', 'Sala inactivada.');
        $this->assertFalse($conNc->refresh()->activo);
        $this->eliminarSala($admin, $conNc)->assertSessionHas('error');
        $this->assertNull($conNc->fresh()->deleted_at);

        // El cliente, con su NC y su sala, tampoco se elimina: se inactiva.
        $this->actingAs($admin)->delete(route('clientes.destroy', $this->cliente))
            ->assertRedirect(route('clientes.show', $this->cliente))->assertSessionHas('error');
        $this->assertNull($this->cliente->fresh()->deleted_at);
    }

    public function test_un_cliente_cuya_unica_sala_vacia_se_borro_ya_se_puede_eliminar(): void
    {
        $admin = $this->admin();
        $sala = ClienteSucursal::factory()->create(['cliente_id' => $this->cliente->id]);

        $this->actingAs($admin)->delete(route('clientes.destroy', $this->cliente))->assertSessionHas('error', fn (string $m) => str_contains($m, 'salas'));
        $this->eliminarSala($admin, $sala)->assertSessionHas('status', 'Sala eliminada.');

        $this->actingAs($admin)->delete(route('clientes.destroy', $this->cliente))->assertRedirect(route('clientes.index'))
            ->assertSessionHas('status', 'Cliente eliminado.');
        $this->assertSoftDeleted('clientes', ['id' => $this->cliente->id]);
    }

    public function test_quien_no_gestiona_clientes_no_inactiva_ni_borra_salas(): void
    {
        $sala = ClienteSucursal::factory()->create(['cliente_id' => $this->cliente->id]);

        foreach ([RolSistema::Facturacion, RolSistema::Jefatura, RolSistema::Vendedor] as $rol) {
            $quien = User::factory()->create()->assignRole($rol->value);
            $this->actingAs($quien)->patch(route('clientes.sucursales.toggle-activo', [$this->cliente, $sala]))->assertForbidden();
            $this->eliminarSala($quien, $sala)->assertForbidden();
        }

        $this->assertTrue($sala->refresh()->activo);
        $this->assertNull($sala->deleted_at);
    }
}
