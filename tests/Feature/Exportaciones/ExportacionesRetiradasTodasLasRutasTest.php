<?php

namespace Tests\Feature\Exportaciones;

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
 * TODAS las direcciones de escritura del flujo anterior de /exportaciones, sacadas del
 * enrutador y no de una lista escrita a mano: catálogo, clientes y precios, y listas.
 * Si mañana alguien agrega una, entra sola en esta prueba.
 *
 * Con el permiso de gestionar responden 409 y ninguna tabla cambia; sin él, 403; sin
 * sesión, al ingreso. Retirarlas del todo es una decisión de Melqui. Datos ficticios.
 */
class ExportacionesRetiradasTodasLasRutasTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $cliente = Cliente::factory()->exportacion()->create(['nombre' => 'Cliente de prueba']);
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => 'Cliente de prueba', 'activo' => true]);
        $producto = ExportacionProducto::create([
            'nombre_es' => 'Producto de prueba', 'nombre_en' => 'Test product', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 144, 'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3, 'precio_caja' => 136.80,
            'peso_neto_caja_kg' => 13, 'peso_bruto_caja_kg' => 14, 'peso_neto_caja_lb' => 28.66, 'peso_bruto_caja_lb' => 30.86,
            'activo' => true,
        ]);
        $asignacion = ExportacionClienteProducto::create([
            'exportacion_cliente_id' => $perfil->id, 'exportacion_producto_id' => $producto->id, 'precio_caja' => 140, 'activo' => true,
        ]);
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $perfil->id, 'cliente_nombre' => 'Cliente de prueba',
            'exportador_nombre' => 'Exportador de prueba', 'fecha' => '2026-11-20', 'estado' => Exportacion::ESTADO_BORRADOR,
        ]);
        $lista->items()->create([
            'exportacion_producto_id' => $producto->id, 'nombre_es' => 'Producto de prueba', 'nombre_en' => 'Test product',
            'unidad' => 'Bolsa', 'unidades_por_caja' => 144, 'cantidad_cajas' => 10, 'precio_caja' => 140,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3,
            'peso_neto_caja_kg' => 13, 'peso_bruto_caja_kg' => 14, 'peso_neto_caja_lb' => 28.66, 'peso_bruto_caja_lb' => 30.86,
        ]);

        $this->ids = [
            'exportacion' => $lista->id,
            'producto' => $producto->id,
            'cliente' => $perfil->id,
            'asignacion' => $asignacion->id,
        ];
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    /** @return list<Ruta> */
    private function rutasDeEscritura(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (Ruta $r) => str_starts_with((string) $r->getName(), 'exportaciones.'))
            ->reject(fn (Ruta $r) => in_array('GET', $r->methods(), true))
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

    /** Lo que mandaría un formulario viejo: datos que, si la ruta escribiera, cambiarían algo. */
    private function datos(): array
    {
        return [
            'cliente_nombre' => 'Otro nombre de prueba', 'nombre' => 'Otro nombre de prueba', 'estado' => 'aprobada',
            'precio_caja' => 1, 'exportacion_producto_id' => $this->ids['producto'], 'cliente_id' => 1,
            'origen_id' => $this->ids['cliente'], 'modo' => 'sobrescribir', 'items' => [['cantidad_cajas' => 3]],
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

    public function test_hay_25_direcciones_viejas_de_escritura_y_todas_responden_409_sin_cambiar_ninguna_tabla(): void
    {
        $rutas = $this->rutasDeEscritura();
        // Catálogo (5), clientes y precios (11) y listas (9). Si cambia el número, mirá qué ruta se agregó o se quitó.
        $this->assertCount(25, $rutas);

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

    public function test_sin_permiso_de_gestionar_todas_dan_403_y_sin_sesion_llevan_al_ingreso(): void
    {
        $jefatura = $this->usuario('jefatura');
        $produccion = $this->usuario('produccion');
        $antes = $this->fotoDeLaBase();

        foreach ($this->rutasDeEscritura() as $ruta) {
            foreach ([$jefatura, $produccion] as $quien) {
                $this->actingAs($quien)->call($this->metodo($ruta), $this->url($ruta), $this->datos())
                    ->assertForbidden();
            }
        }

        $this->assertSame($antes, $this->fotoDeLaBase());

        // Cerrar la sesión escribe en users y en la bitácora: la foto se saca después.
        auth()->logout();
        $sinSesion = $this->fotoDeLaBase();
        foreach ($this->rutasDeEscritura() as $ruta) {
            $this->call($this->metodo($ruta), $this->url($ruta), $this->datos())->assertRedirect(route('login'));
        }

        $this->assertSame($sinSesion, $this->fotoDeLaBase());
    }
}
