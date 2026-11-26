<?php

namespace Tests\Feature\Clientes;

use App\Models\Cliente;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionProducto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as Ruta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Clientes › ficha › Exportación: las direcciones de la lista de precios del cliente
 * (clientes.exportacion.productos.*) se retiraron por decisión de Melqui. Ningún botón
 * las usaba: los productos y precios de cada cliente se asignan en Productos › «De
 * exportación», con el botón «Clientes» de cada presentación, y cada precio se
 * actualiza solo al finalizar una lista de empaque.
 *
 * Se sacan del enrutador y no de una lista escrita a mano. Con el permiso de gestionar
 * responden 409 explicando dónde se hace eso ahora y ninguna tabla cambia, también con
 * doble envío y con el administrador; sin el permiso, 403; sin sesión, al ingreso.
 * Habilitar y deshabilitar el cliente siguen vivas. Datos ficticios.
 */
class ClienteExportacionPreciosRetiradosTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $cliente = Cliente::factory()->exportacion()->create(['nombre' => 'Cliente de prueba']);
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => 'Cliente de prueba', 'activo' => true]);
        $otroCliente = Cliente::factory()->exportacion()->create(['nombre' => 'Otro cliente de prueba']);
        $otroPerfil = ExportacionCliente::create(['cliente_id' => $otroCliente->id, 'nombre' => 'Otro cliente de prueba', 'activo' => true]);
        $producto = ExportacionProducto::create([
            'nombre_es' => 'Producto de prueba', 'nombre_en' => 'Test product', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 144, 'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3, 'precio_caja' => 144.00,
            'peso_neto_caja_kg' => 19.40, 'peso_bruto_caja_kg' => 20.40, 'peso_neto_caja_lb' => 42.77, 'peso_bruto_caja_lb' => 44.97,
            'activo' => true,
        ]);
        $asignacion = ExportacionClienteProducto::create([
            'exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $producto->id, 'precio_caja' => 150.00, 'activo' => true,
        ]);
        ExportacionClienteProducto::create([
            'exportacion_cliente_id' => $otroPerfil->id, 'exportacion_producto_id' => $producto->id, 'precio_caja' => 160.00, 'activo' => true,
        ]);
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $perfil->id, 'cliente_nombre' => 'Cliente de prueba',
            'exportador_nombre' => 'Exportador de prueba', 'fecha' => '2026-11-01', 'estado' => Exportacion::ESTADO_BORRADOR,
        ]);
        $lista->items()->create(['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 10, 'precio_caja' => 150.00] + $producto->datosSnapshot());

        $this->ids = [
            'cliente' => $cliente->id,
            'asignacion' => $asignacion->id,
            'producto' => $producto->id,
            'otro_perfil' => $otroPerfil->id,
        ];
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    /** @return list<Ruta> */
    private function rutas(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (Ruta $r) => str_starts_with((string) $r->getName(), 'clientes.exportacion.productos.'))
            ->values()
            ->all();
    }

    private function url(Ruta $ruta): string
    {
        $parametros = [];
        foreach ($ruta->parameterNames() as $nombre) {
            $this->assertArrayHasKey($nombre, $this->ids, "Falta un dato de prueba para {{$nombre}} en {$ruta->getName()}.");
            $parametros[$nombre] = $this->ids[$nombre];
        }

        return route($ruta->getName(), $parametros);
    }

    private function metodo(Ruta $ruta): string
    {
        return collect($ruta->methods())->reject(fn ($m) => $m === 'HEAD')->first();
    }

    /** Lo que mandaba el formulario viejo: datos que, si la ruta escribiera, cambiarían algo. */
    private function datos(): array
    {
        return [
            'exportacion_producto_id' => $this->ids['producto'], 'precio_caja' => 1, 'confirmar_cero' => 1,
            'toggle_activo' => 1, 'origen_id' => $this->ids['otro_perfil'], 'modo' => 'sobrescribir',
        ];
    }

    /**
     * Huella de toda la base: filas y contenido de cada tabla, ordenadas para que no
     * dependa del orden en que la base devuelve las filas.
     *
     * @return array<string, array{filas: int, contenido: string}>
     */
    private function fotoDeLaBase(): array
    {
        $foto = [];
        foreach (array_column(Schema::getTables(), 'name') as $tabla) {
            $filas = DB::table($tabla)->get()->map(fn ($fila) => json_encode($fila))->sort()->values();
            $foto[$tabla] = ['filas' => $filas->count(), 'contenido' => md5($filas->implode("\n"))];
        }
        ksort($foto);

        return $foto;
    }

    public function test_las_5_direcciones_responden_409_sin_cambiar_ninguna_tabla(): void
    {
        $rutas = $this->rutas();
        // Agregar, asignar el catálogo, copiar de otro cliente, cambiar y quitar.
        $this->assertCount(5, $rutas);

        $facturacion = $this->usuario('facturacion');
        $admin = $this->usuario('administrador');
        $antes = $this->fotoDeLaBase();

        foreach ($rutas as $ruta) {
            foreach ([$facturacion, $facturacion, $admin] as $quien) {
                $this->actingAs($quien)->call($this->metodo($ruta), $this->url($ruta), $this->datos())
                    ->assertStatus(409);
            }
            $this->assertSame($antes, $this->fotoDeLaBase(), "{$ruta->getName()} no debe cambiar ninguna tabla.");
        }
    }

    public function test_el_409_dice_donde_se_hace_ahora(): void
    {
        $facturacion = $this->usuario('facturacion');
        $inicio = 'Esta dirección pertenece al flujo anterior de Exportaciones y ya no realiza cambios. ';
        $fin = ' Las reglas de revisión, finalización, vínculo con la factura y permisos '
            .'viven ahora en un solo sitio, y mantener esta vía en paralelo permitía saltárselas.';

        $this->actingAs($facturacion)->postJson(route('clientes.exportacion.productos.store', $this->ids['cliente']), $this->datos())
            ->assertStatus(409)
            ->assertJsonFragment(['message' => $inicio
                .'Los productos y precios de cada cliente se asignan en Productos → pestaña «De exportación», con el botón '
                .'«Clientes» de cada presentación, y cada precio se actualiza solo al finalizar una lista de empaque.'.$fin]);

        $this->actingAs($facturacion)->deleteJson(route('clientes.exportacion.productos.destroy', [$this->ids['cliente'], $this->ids['asignacion']]))
            ->assertStatus(409)
            ->assertJsonFragment(['message' => $inicio
                .'Para dejar de venderle una presentación, desmarcá al cliente con el botón «Clientes» de esa presentación '
                .'en Productos → pestaña «De exportación»: queda apagada y su precio guardado.'.$fin]);
    }

    public function test_sin_permiso_de_gestionar_dan_403_y_sin_sesion_llevan_al_ingreso(): void
    {
        $sinPermiso = [$this->usuario('jefatura'), $this->usuario('contabilidad'), $this->usuario('produccion')];
        $antes = $this->fotoDeLaBase();

        foreach ($this->rutas() as $ruta) {
            foreach ($sinPermiso as $quien) {
                $this->actingAs($quien)->call($this->metodo($ruta), $this->url($ruta), $this->datos())
                    ->assertForbidden();
            }
        }

        $this->assertSame($antes, $this->fotoDeLaBase());

        // Cerrar la sesión escribe en users y en la bitácora: la foto se saca después.
        auth()->logout();
        $sinSesion = $this->fotoDeLaBase();
        foreach ($this->rutas() as $ruta) {
            $this->call($this->metodo($ruta), $this->url($ruta), $this->datos())->assertRedirect(route('login'));
        }

        $this->assertSame($sinSesion, $this->fotoDeLaBase());
    }

    /** Habilitar y deshabilitar el cliente para exportación no se retiraron. */
    public function test_habilitar_y_deshabilitar_siguen_funcionando(): void
    {
        $facturacion = $this->usuario('facturacion');

        $this->actingAs($facturacion)->post(route('clientes.exportacion.deshabilitar', $this->ids['cliente']))
            ->assertRedirect(route('clientes.show', $this->ids['cliente']))
            ->assertSessionHas('status');
        $this->assertFalse(ExportacionCliente::where('cliente_id', $this->ids['cliente'])->sole()->activo);

        $this->actingAs($facturacion)->post(route('clientes.exportacion.habilitar', $this->ids['cliente']))
            ->assertSessionHas('status');
        $this->assertTrue(ExportacionCliente::where('cliente_id', $this->ids['cliente'])->sole()->activo);
    }
}
