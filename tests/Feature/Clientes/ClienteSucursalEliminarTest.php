<?php

namespace Tests\Feature\Clientes;

use App\Enums\EstadoPedidoSala;
use App\Enums\EstadoSalidaRuta;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\OrdenCompraRecibida;
use App\Models\PedidoSala;
use App\Models\PpqAlbaran;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreaDteVerificableCobros;
use Tests\TestCase;

/**
 * «Eliminar» de una sala en la ficha del cliente (`clientes.sucursales.destroy`).
 *
 * El borrado es suave, pero igual hace desaparecer la sala de todo lo que la busca: un
 * pedido, un CCF o una entrega de ruta que la nombraba quedaba «sin sala». Por eso una
 * sala sin movimientos se borra y una con movimientos no: se avisa y se ofrece inactivarla.
 */
class ClienteSucursalEliminarTest extends TestCase
{
    use CreaDteVerificableCobros;
    use RefreshDatabase;

    private Cliente $cliente;

    private ClienteSucursal $sala;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba']);
        $this->sala = ClienteSucursal::factory()->create(['cliente_id' => $this->cliente->id, 'nombre' => 'Sala de prueba Norte']);
    }

    private function usuario(RolSistema $rol = RolSistema::Administrador): User
    {
        return User::factory()->create()->assignRole($rol->value);
    }

    private function eliminar(User $usuario, ?Cliente $cliente = null)
    {
        return $this->actingAs($usuario)->from(route('clientes.show', $this->cliente))
            ->delete(route('clientes.sucursales.destroy', [$cliente ?? $this->cliente, $this->sala]));
    }

    public function test_una_sala_sin_movimientos_se_borra_de_forma_suave_y_queda_en_la_bitacora(): void
    {
        $admin = $this->usuario();

        $this->eliminar($admin)->assertRedirect(route('clientes.show', $this->cliente))
            ->assertSessionHas('status', 'Sala eliminada.');

        $this->assertSoftDeleted('cliente_sucursales', ['id' => $this->sala->id]);
        $this->assertSame(1, Activity::where('log_name', 'sucursal')->where('subject_id', $this->sala->id)
            ->where('description', 'eliminó la sala/sucursal')->where('causer_id', $admin->id)->count());
        $this->actingAs($admin)->get(route('clientes.show', $this->cliente))->assertOk()
            ->assertDontSee('Sala de prueba Norte');
    }

    /**
     * Cada cosa que ya se hizo con la sala y que la seguiría nombrando.
     *
     * @return array<string, array{0: string}>
     */
    public static function movimientos(): array
    {
        return [
            'un pedido' => ['pedido'],
            'un CCF' => ['dte'],
            'una NC de avería de esa sala' => ['averia'],
            'una entrega de ruta' => ['entrega'],
            'un albarán' => ['albaran'],
            'una orden de compra recibida' => ['orden'],
        ];
    }

    /** Crea el movimiento y devuelve con qué leer, después, la sala que conserva. */
    private function crearMovimiento(string $tipo): \Closure
    {
        $sala = $this->sala->id;

        switch ($tipo) {
            case 'pedido':
                $pedido = PedidoSala::create([
                    'cliente_sucursal_id' => $sala, 'registrado_por' => $this->usuario(RolSistema::Facturacion)->id,
                    'fecha' => '2026-11-09', 'estado' => EstadoPedidoSala::Registrado,
                ]);

                return fn () => $pedido->fresh()->sala;
            case 'dte':
                $dte = $this->dteVerificableCobros($this->cliente, 'DTE-03-M001P002-000000000000101', '2026-11-09', '25.00');
                // Sin eventos: un DTE aceptado ya no se edita, y acá solo se arma el caso.
                $dte->forceFill(['cliente_sucursal_id' => $sala])->saveQuietly();

                return fn () => $dte->fresh()->clienteSucursal;
            case 'averia':
                $nc = $this->dteVerificableCobros($this->cliente, 'DTE-05-M001P002-000000000000102', '2026-11-09', '5.00', '05');
                $nc->forceFill(['sucursal_averia_id' => $sala])->saveQuietly();

                return fn () => $nc->fresh()->sucursalAveria;
            case 'entrega':
                // El CCF no dice sala; la entrega sí (es la copia con que se agrupa la salida).
                $ccf = $this->dteVerificableCobros($this->cliente, 'DTE-03-M001P002-000000000000103', '2026-11-09', '25.00');
                $salida = SalidaRuta::create(['ruta_id' => Ruta::create(['nombre' => 'Zona de prueba'])->id,
                    'fecha_inicio' => '2026-11-09', 'estado' => EstadoSalidaRuta::EnCurso]);
                $entrega = $salida->entregas()->create(['dte_id' => $ccf->id, 'cliente_sucursal_id' => $sala]);

                return fn () => $entrega->fresh()->sala;
            case 'albaran':
                $albaran = PpqAlbaran::create([
                    'numero_albaran' => 'AC01/0017/00/7001', 'tipo_codigo' => 'AC01', 'fecha_albaran' => '2026-11-08',
                    'monto_albaran' => '25.00', 'numero_orden_compra' => '26110017000001', 'sala_codigo' => '0017',
                    'cliente_sucursal_id' => $sala,
                ]);

                return fn () => $albaran->fresh()->clienteSucursal;
            case 'orden':
                $orden = OrdenCompraRecibida::create([
                    'numero' => '26110017000002', 'sala_codigo' => '0017', 'cliente_sucursal_id' => $sala,
                    'pdf_path' => 'ordenes/prueba.pdf', 'pdf_hash' => str_repeat('a', 64),
                    'gmail_message_id' => 'mensaje-de-prueba', 'estado' => 'recibida',
                ]);

                return fn () => $orden->fresh()->clienteSucursal;
        }

        throw new \LogicException("Movimiento desconocido: {$tipo}");
    }

    #[DataProvider('movimientos')]
    public function test_una_sala_con_movimientos_no_se_borra_y_nada_queda_sin_sala(string $tipo): void
    {
        $salaDe = $this->crearMovimiento($tipo);
        $admin = $this->usuario();

        $this->eliminar($admin)->assertRedirect(route('clientes.show', $this->cliente))
            ->assertSessionMissing('status')
            ->assertSessionHas('error', fn (string $mensaje) => str_contains($mensaje, 'No se eliminó la sala «Sala de prueba Norte»')
                && str_contains($mensaje, 'Inactivala'));

        $this->assertNull($this->sala->fresh()->deleted_at);
        $this->assertTrue($this->sala->fresh()->activo, 'Negarse no la inactiva sola: eso lo decide quien gestiona.');
        $this->assertTrue($this->sala->is($salaDe()), 'El movimiento sigue con su sala.');
        $this->assertSame(0, Activity::where('log_name', 'sucursal')->where('description', 'eliminó la sala/sucursal')->count());
    }

    public function test_la_ficha_del_cliente_muestra_por_que_no_se_elimino(): void
    {
        $this->crearMovimiento('pedido');

        $this->eliminar($this->usuario())->assertRedirect();
        $this->get(route('clientes.show', $this->cliente))->assertOk()
            ->assertSee('No se eliminó la sala «Sala de prueba Norte»: tiene pedidos', false)
            ->assertSee('Sala de prueba Norte');
    }

    public function test_una_sala_de_otro_cliente_da_404_y_no_se_borra(): void
    {
        $otro = Cliente::factory()->contribuyente()->create(['nombre' => 'Otro cliente de prueba']);

        $this->eliminar($this->usuario(), $otro)->assertNotFound();

        $this->assertNull($this->sala->fresh()->deleted_at);
    }

    public function test_un_rol_sin_gestion_de_clientes_recibe_403_y_no_borra(): void
    {
        foreach ([RolSistema::Facturacion, RolSistema::Jefatura, RolSistema::Contabilidad, RolSistema::JefeVendedores, RolSistema::Vendedor] as $rol) {
            $this->eliminar($this->usuario($rol))->assertForbidden();
        }

        $this->assertNull($this->sala->fresh()->deleted_at);
    }
}
