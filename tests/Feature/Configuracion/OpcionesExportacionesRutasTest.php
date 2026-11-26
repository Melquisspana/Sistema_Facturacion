<?php

namespace Tests\Feature\Configuracion;

use App\Facades\Ajustes;
use App\Http\Controllers\Configuracion\OpcionesModuloController;
use App\Models\Cliente;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionProducto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Configuración › Módulos › Exportaciones, por su ruta: lo propio de esta página.
 *
 * El método compartido de las páginas de opciones ya se prueba en {@see OpcionesModuloTest}.
 * Acá va lo que solo tiene Exportaciones: el FDA y el nombre y la dirección del exportador
 * que se guardan tienen que salir en la PRÓXIMA lista de empaque (formulario, lista guardada
 * e impresión) sin cambiar las listas que ya existen. Datos ficticios.
 */
class OpcionesExportacionesRutasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sin nada en el .env: lo que salga en la lista viene de lo guardado acá.
        config([
            'exportaciones.fda_reg_number' => '',
            'exportaciones.exportador_nombre' => '',
            'exportaciones.exportador_direccion' => '',
        ]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    private function campo(string $clave): string
    {
        return OpcionesModuloController::campo($clave);
    }

    /** @return array<string, string> */
    private function datos(string $fda = '00000000002'): array
    {
        return [
            $this->campo('exportaciones.fda_reg_number') => $fda,
            $this->campo('exportaciones.exportador_nombre') => 'Exportador de prueba',
            $this->campo('exportaciones.exportador_direccion') => 'Dirección de prueba',
        ];
    }

    private function perfil(): ExportacionCliente
    {
        $cliente = Cliente::factory()->exportacion()->create(['nombre' => 'Cliente de prueba']);

        return ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => 'Cliente de prueba', 'activo' => true]);
    }

    private function listaVieja(ExportacionCliente $perfil): Exportacion
    {
        $lista = Exportacion::create([
            'exportacion_cliente_id' => $perfil->id,
            'cliente_nombre' => 'Cliente de prueba',
            'exportador_nombre' => 'Exportador anterior',
            'fda_reg_number' => '00000000001',
            'fecha' => '2026-10-01',
            'estado' => Exportacion::ESTADO_BORRADOR,
        ]);
        $lista->items()->create([
            'nombre_es' => 'Producto de prueba', 'nombre_en' => 'Test product', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 144, 'cantidad_cajas' => 10, 'precio_caja' => 144.00,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3,
            'peso_neto_caja_kg' => 19.40, 'peso_bruto_caja_kg' => 20.40, 'peso_neto_caja_lb' => 42.77, 'peso_bruto_caja_lb' => 44.97,
        ]);

        return $lista;
    }

    private function productoDelCatalogo(): ExportacionProducto
    {
        return ExportacionProducto::create([
            'nombre_es' => 'Producto de prueba', 'nombre_en' => 'Test product', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 144, 'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3, 'precio_caja' => 144.00,
            'peso_neto_caja_kg' => 19.40, 'peso_bruto_caja_kg' => 20.40, 'peso_neto_caja_lb' => 42.77, 'peso_bruto_caja_lb' => 44.97,
            'activo' => true,
        ]);
    }

    /** Valor de un campo del formulario tal como lo manda el navegador. */
    private function valorDelCampo(string $html, string $nombre): string
    {
        $this->assertMatchesRegularExpression('/name="'.$nombre.'" value="([^"]*)"/', $html);
        preg_match('/name="'.$nombre.'" value="([^"]*)"/', $html, $m);

        return html_entity_decode($m[1]);
    }

    public function test_lo_guardado_pide_confirmacion_queda_en_la_auditoria_y_sale_en_la_proxima_lista_sin_cambiar_las_viejas(): void
    {
        $admin = $this->usuario('administrador');
        $facturacion = $this->usuario('facturacion');
        $perfil = $this->perfil();
        $vieja = $this->listaVieja($perfil);

        // Sin confirmar, solo muestra qué cambia.
        $this->actingAs($admin)->put(route('configuracion.opciones.exportaciones.update'), $this->datos())
            ->assertOk()
            ->assertSee('Esto es lo que va a cambiar');
        $this->assertDatabaseCount('ajustes_sistema', 0);

        $this->actingAs($admin)->put(route('configuracion.opciones.exportaciones.update'), $this->datos() + ['confirmacion' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('configuracion.opciones.exportaciones'))
            ->assertSessionHas('status', 'Opciones de Exportaciones guardadas.');

        $this->assertSame('00000000002', Ajustes::texto('exportaciones.fda_reg_number'));
        $fda = Activity::query()->where('log_name', 'ajustes')->get()
            ->firstWhere(fn (Activity $a) => $a->properties['clave'] === 'exportaciones.fda_reg_number');
        $this->assertNotNull($fda, 'El cambio del FDA tiene que quedar en la auditoría.');
        $this->assertSame($admin->id, (int) $fda->causer_id);
        $this->assertSame('00000000002', (string) $fda->properties['valor_despues']);
        $this->assertSame(3, Activity::query()->where('log_name', 'ajustes')->count());

        // La próxima lista: el formulario ya trae lo guardado…
        $html = $this->actingAs($facturacion)->get(route('facturacion.listas.create'))->assertOk()->getContent();
        $form = [
            'fda_reg_number' => $this->valorDelCampo($html, 'fda_reg_number'),
            'exportador_nombre' => $this->valorDelCampo($html, 'exportador_nombre'),
            'exportador_direccion' => $this->valorDelCampo($html, 'exportador_direccion'),
        ];
        $this->assertSame(['fda_reg_number' => '00000000002', 'exportador_nombre' => 'Exportador de prueba', 'exportador_direccion' => 'Dirección de prueba'], $form);

        // …se guarda con eso y la impresión lo muestra.
        $this->actingAs($facturacion)->post(route('facturacion.listas.store'), $form + [
            'exportacion_cliente_id' => $perfil->id,
            'cliente_nombre' => 'Cliente de prueba',
            'fecha' => '2026-11-25',
            'items' => [['exportacion_producto_id' => $this->productoDelCatalogo()->id, 'cantidad_cajas' => 2]],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $nueva = Exportacion::query()->whereKeyNot($vieja->id)->sole();
        $this->assertSame('00000000002', $nueva->fda_reg_number);
        $this->actingAs($facturacion)->get(route('facturacion.listas.imprimir', $nueva))->assertOk()
            ->assertSee('00000000002')->assertSee('Exportador de prueba');

        // La lista que ya existía conserva su propio encabezado.
        $this->assertSame('00000000001', $vieja->fresh()->fda_reg_number);
        $this->actingAs($facturacion)->get(route('facturacion.listas.imprimir', $vieja))->assertOk()
            ->assertSee('00000000001')->assertDontSee('00000000002');
    }

    public function test_guardar_dos_veces_lo_mismo_no_repite_la_auditoria(): void
    {
        $admin = $this->usuario('administrador');

        $this->actingAs($admin)->put(route('configuracion.opciones.exportaciones.update'), $this->datos() + ['confirmacion' => '1'])
            ->assertRedirect(route('configuracion.opciones.exportaciones'));
        $this->actingAs($admin)->put(route('configuracion.opciones.exportaciones.update'), $this->datos() + ['confirmacion' => '1'])
            ->assertRedirect(route('configuracion.opciones.exportaciones'))
            ->assertSessionHas('status', 'No había nada que cambiar en Exportaciones.');

        $this->assertSame(3, Activity::query()->where('log_name', 'ajustes')->count());
        $this->assertDatabaseCount('ajustes_sistema', 3);
    }

    public function test_un_fda_demasiado_largo_no_se_guarda_y_un_campo_vacio_no_borra(): void
    {
        $admin = $this->usuario('administrador');
        Ajustes::guardarComoSistema('exportaciones.fda_reg_number', '00000000001');

        $this->actingAs($admin)->put(route('configuracion.opciones.exportaciones.update'), [
            $this->campo('exportaciones.fda_reg_number') => str_repeat('9', 51),
            'confirmacion' => '1',
        ])->assertSessionHasErrors($this->campo('exportaciones.fda_reg_number'));
        $this->assertSame('00000000001', Ajustes::texto('exportaciones.fda_reg_number'));

        // Vacío = «no lo toques»: volver al .env es otra acción.
        $this->actingAs($admin)->put(route('configuracion.opciones.exportaciones.update'), [
            $this->campo('exportaciones.fda_reg_number') => '',
            'confirmacion' => '1',
        ])->assertSessionHas('status', 'No había nada que cambiar en Exportaciones.');
        $this->assertSame('00000000001', Ajustes::texto('exportaciones.fda_reg_number'));
    }

    public function test_sin_permiso_de_configuracion_da_403_y_no_guarda_nada(): void
    {
        foreach (['facturacion', 'jefatura'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->put(route('configuracion.opciones.exportaciones.update'), $this->datos() + ['confirmacion' => '1'])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('ajustes_sistema', 0);
        $this->assertSame(0, Activity::query()->where('log_name', 'ajustes')->count());
    }
}
