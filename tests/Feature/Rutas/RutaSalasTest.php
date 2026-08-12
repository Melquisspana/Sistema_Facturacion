<?php

namespace Tests\Feature\Rutas;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Ruta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Asignación de la ruta HABITUAL de una sala.
 *
 * Lo único que puede cambiar acá es `cliente_sucursales.ruta_id`. El resto de la
 * sala —nombre, código, cliente, permisos de documento, activo— es intocable, y
 * las salas dadas de baja no se tocan ni por accidente.
 */
class RutaSalasTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function cliente(): Cliente
    {
        return Cliente::factory()->create(['nombre' => 'Calleja']);
    }

    /** @param array<string, mixed> $extra */
    private function sala(Cliente $cliente, string $nombre, array $extra = []): ClienteSucursal
    {
        return $cliente->sucursales()->create(['nombre' => $nombre, 'codigo' => substr(md5($nombre), 0, 4)] + $extra);
    }

    // ------------------------------------------------------------- asignación

    public function test_asigna_una_sala_a_la_ruta(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($this->cliente(), 'Selectos San Miguel');

        $this->actingAs($this->admin())
            ->post(route('rutas.rutas.salas.store', $ruta), ['sucursales' => [$sala->id]])
            ->assertRedirect();

        $this->assertSame($ruta->id, $sala->refresh()->ruta_id);
    }

    public function test_asigna_varias_salas_de_una_vez(): void
    {
        $ruta = Ruta::create(['nombre' => 'Santa Ana']);
        $cliente = $this->cliente();
        $salas = collect(['A', 'B', 'C'])->map(fn ($n) => $this->sala($cliente, "Sala {$n}"));

        $this->actingAs($this->admin())
            ->post(route('rutas.rutas.salas.store', $ruta), ['sucursales' => $salas->pluck('id')->all()])
            ->assertRedirect();

        foreach ($salas as $sala) {
            $this->assertSame($ruta->id, $sala->refresh()->ruta_id);
        }
    }

    public function test_asignar_a_otra_ruta_la_mueve_sin_duplicar(): void
    {
        $origen = Ruta::create(['nombre' => 'Origen']);
        $destino = Ruta::create(['nombre' => 'Destino']);
        $sala = $this->sala($this->cliente(), 'Sala que se muda', ['ruta_id' => $origen->id]);

        $this->actingAs($this->admin())
            ->post(route('rutas.rutas.salas.store', $destino), ['sucursales' => [$sala->id]]);

        // La ruta habitual es UNA sola: mudarse implica dejar la anterior.
        $this->assertSame($destino->id, $sala->refresh()->ruta_id);
        $this->assertSame(0, $origen->sucursales()->count());
        $this->assertSame(1, $destino->sucursales()->count());
    }

    public function test_quita_la_sala_de_la_ruta_sin_borrarla(): void
    {
        $ruta = Ruta::create(['nombre' => 'Sonsonate']);
        $sala = $this->sala($this->cliente(), 'Selectos Sonsonate', ['ruta_id' => $ruta->id]);

        $this->actingAs($this->admin())
            ->delete(route('rutas.rutas.salas.destroy', [$ruta, $sala]))
            ->assertRedirect();

        $sala->refresh();
        $this->assertNull($sala->ruta_id);
        // La sala sigue existiendo y entera.
        $this->assertFalse($sala->trashed());
        $this->assertSame('Selectos Sonsonate', $sala->nombre);
    }

    public function test_no_se_puede_quitar_una_sala_que_es_de_otra_ruta(): void
    {
        $ruta = Ruta::create(['nombre' => 'Una']);
        $otra = Ruta::create(['nombre' => 'Otra']);
        $sala = $this->sala($this->cliente(), 'Sala de otra ruta', ['ruta_id' => $otra->id]);

        $this->actingAs($this->admin())
            ->delete(route('rutas.rutas.salas.destroy', [$ruta, $sala]))
            ->assertNotFound();

        $this->assertSame($otra->id, $sala->refresh()->ruta_id);
    }

    // ------------------------------------------------------------- salvaguardas

    public function test_no_toca_las_salas_dadas_de_baja(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $cliente = $this->cliente();
        $viva = $this->sala($cliente, 'Sala viva');
        $borrada = $this->sala($cliente, 'Sala dada de baja');
        $borrada->delete();

        $this->actingAs($this->admin())
            ->post(route('rutas.rutas.salas.store', $ruta), ['sucursales' => [$viva->id, $borrada->id]]);

        $this->assertSame($ruta->id, $viva->refresh()->ruta_id);
        // La dada de baja queda igual: sin ruta y sin resucitar.
        $borrada = ClienteSucursal::withTrashed()->find($borrada->id);
        $this->assertNull($borrada->ruta_id);
        $this->assertTrue($borrada->trashed());
    }

    public function test_asignar_no_modifica_ningun_otro_dato_de_la_sala(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($this->cliente(), 'Selectos Centro', [
            'codigo' => '0236',
            'activo' => true,
            'permite_ccf' => true,
        ]);
        $antes = $sala->only(['cliente_id', 'codigo', 'nombre', 'activo', 'permite_ccf']);

        $this->actingAs($this->admin())
            ->post(route('rutas.rutas.salas.store', $ruta), ['sucursales' => [$sala->id]]);

        $this->assertSame($antes, $sala->refresh()->only(['cliente_id', 'codigo', 'nombre', 'activo', 'permite_ccf']));
    }

    public function test_reasignar_a_la_misma_ruta_no_hace_nada(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($this->cliente(), 'Ya asignada', ['ruta_id' => $ruta->id]);

        $this->actingAs($this->admin())
            ->post(route('rutas.rutas.salas.store', $ruta), ['sucursales' => [$sala->id]])
            ->assertSessionHas('status', 'Esas salas ya estaban en la ruta.');

        // Y no ensucia la auditoría con un movimiento que no ocurrió.
        $this->assertSame(0, Activity::where('log_name', 'ruta_sala')->count());
    }

    public function test_borrar_la_ruta_deja_la_sala_sin_ruta_pero_no_la_borra(): void
    {
        $ruta = Ruta::create(['nombre' => 'Efímera']);
        $sala = $this->sala($this->cliente(), 'Sala huérfana', ['ruta_id' => $ruta->id]);

        $ruta->delete();

        $this->assertNull($sala->refresh()->ruta_id);
        $this->assertFalse($sala->trashed());
    }

    // ------------------------------------------------------------- auditoría

    public function test_asignar_y_quitar_quedan_auditados(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($this->cliente(), 'Selectos San Miguel');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('rutas.rutas.salas.store', $ruta), ['sucursales' => [$sala->id]]);
        $this->actingAs($admin)->delete(route('rutas.rutas.salas.destroy', [$ruta, $sala]));

        $eventos = Activity::where('log_name', 'ruta_sala')->orderBy('id')->get();

        $this->assertCount(2, $eventos);
        $this->assertSame('asignó la sala a la ruta', $eventos[0]->description);
        $this->assertSame('quitó la sala de la ruta', $eventos[1]->description);
        $this->assertSame($ruta->nombre, $eventos[0]->properties['ruta']);
        $this->assertSame($admin->id, $eventos[0]->causer_id);
    }

    // ------------------------------------------------------------- buscador

    public function test_el_detalle_no_lista_salas_hasta_que_se_busca(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $this->sala($this->cliente(), 'Sala sin asignar');

        // Sin criterio no se vuelcan las 135 sucursales.
        $sinBuscar = $this->actingAs($this->admin())->get(route('rutas.rutas.show', $ruta));
        $sinBuscar->assertOk();
        $this->assertNull($sinBuscar->viewData('candidatas'));

        // Con criterio sí aparecen.
        $buscando = $this->actingAs($this->admin())->get(route('rutas.rutas.show', [$ruta, 'q' => 'Sala']));
        $this->assertNotNull($buscando->viewData('candidatas'));
        $this->assertSame(1, $buscando->viewData('candidatas')->total());
    }

    public function test_el_buscador_no_ofrece_las_salas_que_ya_estan_en_la_ruta(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $cliente = $this->cliente();
        $this->sala($cliente, 'Sala ya asignada', ['ruta_id' => $ruta->id]);
        $libre = $this->sala($cliente, 'Sala libre');

        $respuesta = $this->actingAs($this->admin())->get(route('rutas.rutas.show', [$ruta, 'q' => 'Sala']));

        $ids = $respuesta->viewData('candidatas')->pluck('id')->all();
        $this->assertSame([$libre->id], $ids);
    }
}
