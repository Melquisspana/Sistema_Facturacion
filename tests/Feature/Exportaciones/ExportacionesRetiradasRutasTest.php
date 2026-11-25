<?php

namespace Tests\Feature\Exportaciones;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\PuntoVenta;
use App\Models\User;
use Database\Seeders\CatalogosMhSeeder;
use Database\Seeders\CatalogosMhTablaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rutas de escritura del flujo anterior de listas de empaque (/exportaciones), por su ruta.
 *
 * Quedaron vivas a propósito para que un enlace viejo no dé 404, pero ya no escriben:
 * responden 409 y dicen dónde se hace ahora. Lo que se comprueba es eso mismo: con el
 * permiso de gestionar, ninguna tabla cambia (ni filas ni contenido) y «crear FEX» no
 * crea ningún documento ni consume un número; sin el permiso, 403. Retirarlas del todo
 * es una decisión de Melqui, no de esta prueba. Datos ficticios.
 */
class ExportacionesRetiradasRutasTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    /** Lista lista para facturar: cliente del directorio vinculado, productos y emisor con correlativo de FEX. */
    private function listaParaFacturar(): Exportacion
    {
        $empresa = Empresa::create(['razon_social' => 'Empresa de prueba', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa de prueba', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja de prueba', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        $cliente = Cliente::factory()->exportacion()->create(['nombre' => 'Cliente de prueba']);
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => 'Cliente de prueba', 'activo' => true]);

        $lista = Exportacion::create([
            'exportacion_cliente_id' => $perfil->id,
            'cliente_nombre' => 'Cliente de prueba',
            'exportador_nombre' => 'Exportador de prueba',
            'fecha' => '2026-11-20',
            'estado' => Exportacion::ESTADO_BORRADOR,
        ]);
        $lista->items()->create([
            'nombre_es' => 'Producto de prueba', 'nombre_en' => 'Test product', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 144, 'cantidad_cajas' => 10, 'precio_caja' => 18.00,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3.00,
            'peso_neto_caja_kg' => 12, 'peso_bruto_caja_kg' => 13, 'peso_neto_caja_lb' => 26, 'peso_bruto_caja_lb' => 28,
        ]);

        return $lista->fresh();
    }

    /**
     * Huella de TODA la base: filas y contenido de cada tabla. Si una ruta escribiera
     * algo —una fila nueva, un estado cambiado, una entrada de bitácora—, cambia.
     *
     * @return array<string, array{filas: int, contenido: string}>
     */
    private function fotoDeLaBase(): array
    {
        $foto = [];
        foreach (array_column(Schema::getTables(), 'name') as $tabla) {
            $foto[$tabla] = [
                'filas' => DB::table($tabla)->count(),
                'contenido' => md5(json_encode(DB::table($tabla)->get())),
            ];
        }
        ksort($foto);

        return $foto;
    }

    /**
     * Ruta, método y cuerpo que mandaba el formulario viejo.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function retiradas(): array
    {
        return [
            'crear lista (store)' => ['exportaciones.store', 'post', ['cliente_nombre' => 'Cliente de prueba', 'exportador_nombre' => 'Exportador de prueba', 'fecha' => '2026-11-20', 'items' => [['cantidad_cajas' => 3]]]],
            'editar lista (update)' => ['exportaciones.update', 'put', ['cliente_nombre' => 'Otro nombre', 'estado' => 'aprobada', 'items' => []]],
            'aprobar' => ['exportaciones.aprobar', 'patch', []],
            'desaprobar' => ['exportaciones.desaprobar', 'patch', []],
            'crear FEX' => ['exportaciones.crear-fex', 'post', []],
            'duplicar' => ['exportaciones.duplicar', 'post', []],
            'borrar (destroy)' => ['exportaciones.destroy', 'delete', []],
        ];
    }

    private function url(string $ruta, Exportacion $lista): string
    {
        return $ruta === 'exportaciones.store' ? route($ruta) : route($ruta, $lista);
    }

    /** @param  array<string, mixed>  $datos */
    #[DataProvider('retiradas')]
    public function test_con_permiso_de_gestionar_responde_409_dos_veces_y_no_cambia_ninguna_tabla(string $ruta, string $metodo, array $datos): void
    {
        $lista = $this->listaParaFacturar();
        $facturacion = $this->usuario('facturacion');
        $admin = $this->usuario('administrador');
        $antes = $this->fotoDeLaBase();

        // Doble clic y también el administrador: siempre 409, nunca escribe.
        $this->actingAs($facturacion)->{$metodo}($this->url($ruta, $lista), $datos)->assertStatus(409);
        $this->actingAs($facturacion)->{$metodo}($this->url($ruta, $lista), $datos)->assertStatus(409);
        $this->actingAs($admin)->{$metodo}($this->url($ruta, $lista), $datos)->assertStatus(409);

        $this->assertSame($antes, $this->fotoDeLaBase(), "{$ruta} no debe cambiar ninguna tabla.");
        $this->assertSame(Exportacion::ESTADO_BORRADOR, $lista->fresh()->estado);
    }

    /** @param  array<string, mixed>  $datos */
    #[DataProvider('retiradas')]
    public function test_sin_permiso_de_gestionar_da_403_y_tampoco_cambia_nada(string $ruta, string $metodo, array $datos): void
    {
        $lista = $this->listaParaFacturar();
        // Jefatura ve las listas pero no las gestiona; Producción ni siquiera las ve.
        $jefatura = $this->usuario('jefatura');
        $produccion = $this->usuario('produccion');
        $antes = $this->fotoDeLaBase();

        $this->actingAs($jefatura)->{$metodo}($this->url($ruta, $lista), $datos)->assertForbidden();
        $this->actingAs($produccion)->{$metodo}($this->url($ruta, $lista), $datos)->assertForbidden();

        $this->assertSame($antes, $this->fotoDeLaBase());
    }

    public function test_el_409_dice_donde_se_hace_ahora(): void
    {
        $lista = $this->listaParaFacturar();
        $facturacion = $this->usuario('facturacion');

        $this->actingAs($facturacion)->putJson(route('exportaciones.update', $lista), [])
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Esta dirección pertenece al flujo anterior de Exportaciones y ya no realiza cambios. '
                .'Usá Ventas y facturación → «Listas de empaque». Las reglas de revisión, finalización, vínculo con la factura y permisos '
                .'viven ahora en un solo sitio, y mantener esta vía en paralelo permitía saltárselas.']);

        $fex = $this->actingAs($facturacion)->postJson(route('exportaciones.crear-fex', $lista))->assertStatus(409);
        $this->assertStringContainsString('Facturá desde la ficha de la lista', (string) $fex->json('message'));
    }

    /**
     * «Crear FEX» por la vía vieja no crea ningún documento ni gasta un número de
     * control. La misma lista SÍ se puede facturar por la ruta de hoy: eso prueba que
     * no era la lista la que impedía crear la FEX.
     */
    public function test_crear_fex_por_la_via_vieja_no_crea_ningun_dte_y_la_ruta_de_hoy_si(): void
    {
        $this->seed(CatalogosMhSeeder::class);
        $this->seed(CatalogosMhTablaSeeder::class);
        $lista = $this->listaParaFacturar();
        $facturacion = $this->usuario('facturacion');

        $this->actingAs($facturacion)->post(route('exportaciones.crear-fex', $lista))->assertStatus(409);

        $this->assertDatabaseCount('dtes', 0);
        $this->assertDatabaseCount('dte_lineas', 0);
        $this->assertDatabaseCount('exportacion_dte', 0);
        $this->assertNull($lista->fresh()->dte_id);
        $this->assertSame(0, (int) Correlativo::query()->where('tipo_dte', '11')->value('ultimo_numero'));

        // Control: por «Facturar» de la ficha de la lista sí nace el borrador de la FEX.
        $this->actingAs($facturacion)->post(route('facturacion.listas.facturar-rapido', $lista))->assertRedirect();

        $this->assertSame(1, Dte::query()->where('tipo_dte', TipoDte::FacturaExportacion->value)->count());
    }

    public function test_una_lista_que_no_existe_tambien_responde_409_sin_escribir(): void
    {
        $this->listaParaFacturar();
        $facturacion = $this->usuario('facturacion');
        $antes = $this->fotoDeLaBase();

        $this->actingAs($facturacion)->delete(route('exportaciones.destroy', 999999))->assertStatus(409);

        $this->assertSame($antes, $this->fotoDeLaBase());
    }

    public function test_sin_sesion_lleva_al_ingreso(): void
    {
        $lista = $this->listaParaFacturar();

        $this->post(route('exportaciones.crear-fex', $lista))->assertRedirect(route('login'));
        $this->delete(route('exportaciones.destroy', $lista))->assertRedirect(route('login'));

        $this->assertModelExists($lista);
    }
}
