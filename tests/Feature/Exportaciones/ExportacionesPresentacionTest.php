<?php

namespace Tests\Feature\Exportaciones;

use App\Models\Cliente;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionProducto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Presentación de las pantallas reubicadas: móvil, claro/oscuro, teclado y ausencia
 * de scroll horizontal.
 *
 * Son comprobaciones sobre el MARCADO, no capturas: se verifica que existan las
 * decisiones que producen ese comportamiento —el contenedor con scroll propio, el
 * `aria-current`, las etiquetas asociadas, la ausencia de colores fijos que ignoren
 * el tema—. Un navegador de verdad no cabe en la suite, pero una tabla ancha sin
 * `overflow-x-auto` sí se puede detectar acá, y es exactamente el fallo que rompe
 * la página en un teléfono.
 */
class ExportacionesPresentacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'jefatura'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function producto(): ExportacionProducto
    {
        return ExportacionProducto::create([
            'nombre_es' => 'Caja de camote', 'nombre_en' => 'Sweet potato candy box', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 144, 'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3,
            'precio_caja' => 144.00,
            'peso_neto_caja_kg' => 19.40, 'peso_bruto_caja_kg' => 20.40,
            'peso_neto_caja_lb' => 42.77, 'peso_bruto_caja_lb' => 44.97,
            'activo' => true,
        ]);
    }

    private function lista(): Exportacion
    {
        $lista = Exportacion::create([
            'cliente_nombre' => 'CAROLINAS WHOLESALE LLC',
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-09-01',
            'estado' => Exportacion::ESTADO_BORRADOR,
        ]);

        $lista->items()->create([
            'nombre_es' => 'Caja de camote', 'nombre_en' => 'Sweet potato candy box', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 144, 'cantidad_cajas' => 10, 'precio_caja' => 144.00,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3,
            'peso_neto_caja_kg' => 19.40, 'peso_bruto_caja_kg' => 20.40,
            'peso_neto_caja_lb' => 42.77, 'peso_bruto_caja_lb' => 44.97,
        ]);

        return $lista->fresh();
    }

    /** @return array<string, string> ruta ⇒ html */
    private function pantallas(): array
    {
        $usuario = $this->usuario();
        $this->producto();
        $lista = $this->lista();
        $productoId = ExportacionProducto::firstOrFail()->id;

        $cliente = Cliente::factory()->exportacion()->create();
        ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => $cliente->nombre, 'activo' => true]);

        return [
            'productos nacionales' => $this->actingAs($usuario)->get(route('productos.index'))->assertOk()->getContent(),
            'productos de exportación' => $this->actingAs($usuario)->get(route('productos.exportacion.index'))->assertOk()->getContent(),
            'ficha de producto de exportación' => $this->actingAs($usuario)->get(route('productos.exportacion.show', $productoId))->assertOk()->getContent(),
            'ficha de cliente de exportación' => $this->actingAs($usuario)->get(route('clientes.show', $cliente))->assertOk()->getContent(),
            'listas de empaque' => $this->actingAs($usuario)->get(route('facturacion.listas.index'))->assertOk()->getContent(),
            'ficha de lista de empaque' => $this->actingAs($usuario)->get(route('facturacion.listas.show', $lista))->assertOk()->getContent(),
        ];
    }

    // ------------------------------------------------- sin scroll horizontal (móvil)

    /**
     * Toda tabla ancha vive dentro de un contenedor con scroll propio. Sin eso, en un
     * teléfono se desplaza la PÁGINA entera y la barra lateral y la cabecera se van
     * con ella.
     */
    public function test_cada_tabla_va_dentro_de_un_contenedor_con_scroll_propio(): void
    {
        foreach ($this->pantallas() as $nombre => $html) {
            $tablas = substr_count($html, '<table');

            if ($tablas === 0) {
                continue;
            }

            $contenedores = substr_count($html, 'overflow-x-auto');

            $this->assertGreaterThanOrEqual(
                $tablas,
                $contenedores,
                "«{$nombre}»: hay {$tablas} tabla(s) y solo {$contenedores} contenedor(es) con overflow-x-auto."
            );
        }
    }

    public function test_el_selector_de_productos_se_desplaza_solo_en_pantallas_estrechas(): void
    {
        $html = $this->actingAs($this->usuario())->get(route('productos.index'))->assertOk()->getContent();

        // La tira de pestañas tiene su propio scroll y sus rótulos no se parten.
        $this->assertStringContainsString('flex gap-6 overflow-x-auto', $html);
        $this->assertStringContainsString('whitespace-nowrap', $html);
    }

    // -------------------------------------------------------------- claro / oscuro

    /**
     * El tema oscuro se resuelve con los overrides globales de `resources/css/app.css`
     * sobre las utilidades estándar (`bg-white`, `text-gray-700`, …). Por eso lo que
     * hay que comprobar es que las pantallas nuevas usan ESE vocabulario y no colores
     * fijos escritos a mano, que el override no puede alcanzar.
     */
    public function test_las_pantallas_nuevas_usan_el_vocabulario_de_color_del_tema(): void
    {
        foreach ($this->pantallas() as $nombre => $html) {
            $this->assertMatchesRegularExpression(
                '/class="[^"]*bg-white/',
                $html,
                "«{$nombre}»: no usa bg-white, que es la clase que el tema oscuro reescribe."
            );

            // Colores fijos en el atributo style: el override por clase nunca los alcanza,
            // así que quedarían iguales en claro y en oscuro.
            $this->assertDoesNotMatchRegularExpression(
                '/style="[^"]*(background(-color)?|color)\s*:\s*#[0-9a-fA-F]{3,6}/',
                $this->sinPlantillaDeImpresion($html),
                "«{$nombre}»: tiene un color fijo en un atributo style; el tema oscuro no puede cambiarlo."
            );
        }
    }

    /**
     * La versión imprimible es la ÚNICA que lleva colores fijos, y a propósito: es un
     * documento independiente con su propio <html>, nunca recibe la clase `dark` y se
     * imprime en blanco y negro.
     */
    public function test_la_version_imprimible_es_deliberadamente_de_un_solo_tema(): void
    {
        $html = $this->actingAs($this->usuario())
            ->get(route('facturacion.listas.imprimir', $this->lista()))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('@media print', $html);
        $this->assertStringNotContainsString('x-app-layout', $html);
    }

    // ----------------------------------------------------------------- teclado

    public function test_las_pestanas_son_enlaces_navegables_con_teclado_y_declaran_la_activa(): void
    {
        $html = $this->actingAs($this->usuario())->get(route('productos.index'))->assertOk()->getContent();

        // Enlaces reales: tabulables, abribles en pestaña nueva y sin depender de JS.
        $this->assertMatchesRegularExpression('/<a href="[^"]*\/productos"[^>]*aria-current="page"/', $html);
        // Y con foco visible propio, porque el borde inferior se come el outline por defecto.
        $this->assertStringContainsString('focus-visible:outline', $html);
    }

    public function test_los_campos_del_perfil_de_exportacion_tienen_etiqueta_asociada(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => $cliente->nombre, 'activo' => true]);

        $html = $this->actingAs($this->usuario())->get(route('clientes.show', $cliente))->assertOk()->getContent();

        foreach (['exp_fda', 'exp_contacto', 'exp_direccion'] as $campo) {
            $this->assertMatchesRegularExpression(
                '/<label[^>]*for="'.$campo.'"/',
                $html,
                "El campo «{$campo}» no tiene una etiqueta asociada por for/id."
            );
            $this->assertMatchesRegularExpression('/id="'.$campo.'"/', $html);
        }
    }

    public function test_los_campos_de_precio_en_linea_llevan_etiqueta_aunque_no_se_vea(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => $cliente->nombre, 'activo' => true]);
        $producto = $this->producto();
        $perfil->productos()->create([
            'exportacion_producto_id' => $producto->id, 'precio_caja' => 120, 'activo' => true,
        ]);

        $html = $this->actingAs($this->usuario())->get(route('clientes.show', $cliente))->assertOk()->getContent();

        // Un input de precio por fila sin etiqueta se anuncia como «edición» a secas.
        $this->assertMatchesRegularExpression('/<label class="sr-only" for="precio_\d+">/', $html);
    }

    public function test_las_tablas_de_datos_tienen_encabezados_y_titulo_para_lectores_de_pantalla(): void
    {
        $html = $this->actingAs($this->usuario())
            ->get(route('facturacion.listas.show', $this->lista()))->assertOk()->getContent();

        $this->assertStringContainsString('<caption class="sr-only">', $html);
        $this->assertStringContainsString('scope="col"', $html);
    }

    /** Quita el bloque <style> para no confundir CSS legítimo con estilos en línea. */
    private function sinPlantillaDeImpresion(string $html): string
    {
        return (string) preg_replace('/<style\b[^>]*>.*?<\/style>/s', '', $html);
    }
}
