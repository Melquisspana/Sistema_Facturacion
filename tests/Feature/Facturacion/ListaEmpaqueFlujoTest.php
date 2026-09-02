<?php

namespace Tests\Feature\Facturacion;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\ExportacionClienteProducto;
use App\Models\ExportacionProducto;
use App\Models\PuntoVenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Listas de empaque dentro de Ventas y facturación: el flujo corto completo.
 *
 *   borrador → una o varias FEX vinculadas → finalizada
 *
 * Sin cola, sin aduana, sin tránsito y sin estados intermedios. Lo que se
 * comprueba acá, y que antes no existía:
 *
 *  · el número de factura NO se teclea: sale de las FEX vinculadas;
 *  · una lista puede tener VARIAS facturas;
 *  · finalizar exige factura, y bloquea de verdad edición, borrado y vínculos;
 *  · corregir una lista finalizada exige reabrirla con motivo, y queda auditado.
 */
class ListaEmpaqueFlujoTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{establecimiento_id: int, punto_venta_id: int}|null */
    private ?array $emisorCache = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'jefatura'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /**
     * Emisor mínimo. `dtes` exige establecimiento y punto de venta, así que se crea
     * una sola vez por prueba y se reutiliza en cada FEX.
     *
     * @return array{establecimiento_id: int, punto_venta_id: int}
     */
    private function emisor(): array
    {
        // Propiedad de instancia y NO `static`: PHPUnit reutiliza el proceso pero
        // RefreshDatabase vacía la base entre pruebas, así que unos ids cacheados en
        // una estática apuntarían a filas que ya no existen y la FK reventaría en la
        // segunda prueba del archivo.
        if ($this->emisorCache !== null) {
            return $this->emisorCache;
        }

        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        Correlativo::create(['tipo_dte' => '11', 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        return $this->emisorCache = ['establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id];
    }

    private function producto(): ExportacionProducto
    {
        return ExportacionProducto::create([
            'nombre_es' => 'Caja de camote',
            'nombre_en' => 'Sweet potato candy box',
            'unidad' => 'Bolsa',
            'unidades_por_caja' => 144,
            'gramos_por_unidad' => 85,
            'onzas_por_unidad' => 3,
            'precio_caja' => 144.00,
            'peso_neto_caja_kg' => 19.40,
            'peso_bruto_caja_kg' => 20.40,
            'peso_neto_caja_lb' => 42.77,
            'peso_bruto_caja_lb' => 44.97,
            'activo' => true,
        ]);
    }

    /** @return array{cliente: Cliente, perfil: ExportacionCliente} */
    private function clienteHabilitado(array $extra = []): array
    {
        $cliente = Cliente::factory()->exportacion()->create($extra + ['nombre' => 'CAROLINAS WHOLESALE LLC']);
        $perfil = ExportacionCliente::create([
            'cliente_id' => $cliente->id, 'nombre' => $cliente->nombre, 'activo' => true,
        ]);

        return ['cliente' => $cliente, 'perfil' => $perfil];
    }

    private function lista(?ExportacionCliente $perfil = null, array $extra = []): Exportacion
    {
        $lista = Exportacion::create($extra + [
            'exportacion_cliente_id' => $perfil?->id,
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

    private function fex(Cliente $cliente, string $numero, string $estado = 'generado'): Dte
    {
        return Dte::create($this->emisor() + [
            'tipo_dte' => TipoDte::FacturaExportacion->value,
            'cliente_id' => $cliente->id,
            'estado' => $estado,
            'ambiente' => '00',
            'numero_control' => $numero,
            'fecha_emision' => '2026-09-01', 'hora_emision' => '10:00:00',
            'total_pagar' => 1440.00,
        ]);
    }

    // ------------------------------------------------------------ 1: borrador

    public function test_una_lista_nace_en_borrador_y_es_editable(): void
    {
        ['perfil' => $perfil] = $this->clienteHabilitado();
        $producto = $this->producto();
        ExportacionClienteProducto::create([
            'exportacion_cliente_id' => $perfil->id,
            'exportacion_producto_id' => $producto->id,
            'precio_caja' => 150.00,
            'activo' => true,
        ]);

        $this->actingAs($this->usuario())->post(route('facturacion.listas.store'), [
            'exportacion_cliente_id' => $perfil->id,
            'cliente_nombre' => 'CAROLINAS WHOLESALE LLC',
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-09-01',
            'items' => [['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 8]],
        ])->assertRedirect();

        $lista = Exportacion::firstOrFail();
        $this->assertSame(Exportacion::ESTADO_BORRADOR, $lista->estado);
        $this->assertTrue($lista->esBorrador());
        $this->assertTrue($lista->puedeEditarse());
        $this->assertFalse($lista->puedeFinalizarse(), 'sin factura no se puede finalizar');
        // El precio del cliente manda sobre el base.
        $this->assertSame('150.00', (string) $lista->items->first()->precio_caja);

        $this->actingAs($this->usuario())->get(route('facturacion.listas.edit', $lista))->assertOk();
    }

    // ---------------------------------------------- 2: una y varias FEX, números

    public function test_el_numero_de_factura_sale_del_dte_y_no_se_teclea(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil] = $this->clienteHabilitado();
        $lista = $this->lista($perfil, ['factura' => 'TEXTO VIEJO A MANO']);
        $fex = $this->fex($cliente, 'DTE-11-M001P001-000000000000001');

        // Antes de vincular, el texto histórico es lo único que hay.
        $this->assertSame('TEXTO VIEJO A MANO', $lista->textoFactura());

        $this->actingAs($this->usuario())
            ->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $fex->id])
            ->assertSessionHas('status');

        $lista->refresh();
        $this->assertSame(['DTE-11-M001P001-000000000000001'], $lista->numerosFactura());
        // Vinculada una factura, el número derivado GANA sobre el texto tecleado.
        $this->assertSame('DTE-11-M001P001-000000000000001', $lista->textoFactura());
        // Y la columna histórica queda sincronizada, para no romper consumidores viejos.
        $this->assertSame($fex->id, $lista->dte_id);
        $this->assertTrue($fex->fresh()->exportacionOrigen?->is($lista));
    }

    public function test_una_lista_puede_vincular_varias_facturas(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil] = $this->clienteHabilitado();
        $lista = $this->lista($perfil);

        $primera = $this->fex($cliente, 'DTE-11-M001P001-000000000000001');
        $segunda = $this->fex($cliente, 'DTE-11-M001P001-000000000000002');

        foreach ([$primera, $segunda] as $fex) {
            $this->actingAs($this->usuario())
                ->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $fex->id])
                ->assertSessionHas('status');
        }

        $lista->refresh();
        $this->assertCount(2, $lista->facturas());
        $this->assertSame(
            'DTE-11-M001P001-000000000000001 · DTE-11-M001P001-000000000000002',
            $lista->textoFactura()
        );

        // La columna histórica sigue apuntando a la PRIMERA, que es la marcada principal.
        $this->assertSame($primera->id, $lista->dte_id);
        $this->assertSame(1, (int) DB::table('exportacion_dte')
            ->where('exportacion_id', $lista->id)->where('principal', true)->count());

        $this->actingAs($this->usuario())->get(route('facturacion.listas.show', $lista))->assertOk()
            ->assertSee('Facturas de exportación (2)');
    }

    public function test_una_factura_no_puede_pertenecer_a_dos_listas(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil] = $this->clienteHabilitado();
        $primera = $this->lista($perfil);
        $segunda = $this->lista($perfil);
        $fex = $this->fex($cliente, 'DTE-11-M001P001-000000000000001');

        $this->actingAs($this->usuario())->post(route('facturacion.listas.facturas.vincular', $primera), ['dte_id' => $fex->id]);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.listas.facturas.vincular', $segunda), ['dte_id' => $fex->id])
            ->assertSessionHas('error');

        $this->assertCount(0, $segunda->fresh()->facturas());
    }

    public function test_un_documento_que_no_es_fex_no_se_puede_vincular(): void
    {
        ['perfil' => $perfil] = $this->clienteHabilitado();
        $lista = $this->lista($perfil);

        $ccf = Dte::create($this->emisor() + [
            'tipo_dte' => TipoDte::CreditoFiscal->value,
            'cliente_id' => Cliente::factory()->contribuyente()->create()->id,
            'estado' => 'borrador', 'ambiente' => '00', 'fecha_emision' => '2026-09-01', 'hora_emision' => '10:00:00', 'total_pagar' => 10,
        ]);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $ccf->id])
            ->assertSessionHas('error');

        $this->assertCount(0, $lista->fresh()->facturas());
    }

    public function test_desvincular_no_toca_el_documento_fiscal(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil] = $this->clienteHabilitado();
        $lista = $this->lista($perfil);
        $fex = $this->fex($cliente, 'DTE-11-M001P001-000000000000001', 'aceptado');

        $this->actingAs($this->usuario())->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $fex->id]);
        $this->actingAs($this->usuario())
            ->delete(route('facturacion.listas.facturas.desvincular', [$lista, $fex]))
            ->assertSessionHas('status');

        $lista->refresh();
        $this->assertCount(0, $lista->facturas());
        $this->assertNull($lista->dte_id);

        // El DTE sigue existiendo, con su estado y su número intactos.
        $fex->refresh();
        $this->assertSame(EstadoDte::Aceptado, $fex->estado);
        $this->assertSame('DTE-11-M001P001-000000000000001', $fex->numero_control);
    }

    /**
     * Al quitar la factura PRINCIPAL, la columna histórica pasa a la que quede, en vez
     * de apuntar a un vínculo que ya no existe.
     */
    public function test_al_desvincular_la_principal_la_columna_historica_pasa_a_la_siguiente(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil] = $this->clienteHabilitado();
        $lista = $this->lista($perfil);
        $primera = $this->fex($cliente, 'DTE-11-M001P001-000000000000001');
        $segunda = $this->fex($cliente, 'DTE-11-M001P001-000000000000002');

        $this->actingAs($this->usuario())->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $primera->id]);
        $this->actingAs($this->usuario())->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $segunda->id]);

        $this->actingAs($this->usuario())->delete(route('facturacion.listas.facturas.desvincular', [$lista, $primera]));

        $lista->refresh();
        $this->assertSame($segunda->id, $lista->dte_id);
        $this->assertCount(1, $lista->facturas());
    }

    // ------------------------------------------ 3: finalizar y corregir con motivo

    public function test_no_se_puede_finalizar_una_lista_sin_factura(): void
    {
        ['perfil' => $perfil] = $this->clienteHabilitado();
        $lista = $this->lista($perfil);

        $this->actingAs($this->usuario())
            ->patch(route('facturacion.listas.finalizar', $lista))
            ->assertSessionHas('error');

        $this->assertSame(Exportacion::ESTADO_BORRADOR, $lista->fresh()->estado);
    }

    public function test_finalizar_cierra_la_lista_y_bloquea_edicion_borrado_y_vinculos(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil] = $this->clienteHabilitado();
        $lista = $this->lista($perfil);
        $fex = $this->fex($cliente, 'DTE-11-M001P001-000000000000001');
        $usuario = $this->usuario();

        $this->actingAs($usuario)->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $fex->id]);
        $this->actingAs($usuario)->patch(route('facturacion.listas.finalizar', $lista))->assertSessionHas('status');

        $lista->refresh();
        $this->assertSame(Exportacion::ESTADO_FINALIZADA, $lista->estado);
        $this->assertTrue($lista->estaFinalizada());
        $this->assertFalse($lista->puedeEditarse());
        $this->assertNotNull($lista->finalizada_en);
        $this->assertSame($usuario->id, $lista->finalizada_por_user_id);

        // Editar: bloqueado.
        $this->actingAs($usuario)->get(route('facturacion.listas.edit', $lista))->assertSessionHasErrors('estado');
        $this->actingAs($usuario)->put(route('facturacion.listas.update', $lista), [
            'exportacion_cliente_id' => $perfil->id,
            'cliente_nombre' => 'OTRO NOMBRE',
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-09-02',
            'items' => [['id' => $lista->items->first()->id, 'cantidad_cajas' => 99]],
        ])->assertSessionHasErrors('estado');
        $this->assertSame('CAROLINAS WHOLESALE LLC', $lista->fresh()->cliente_nombre);

        // Borrar: bloqueado.
        $this->actingAs($usuario)->delete(route('facturacion.listas.destroy', $lista))->assertSessionHas('error');
        $this->assertNotNull(Exportacion::find($lista->id));

        // Desvincular la factura: bloqueado.
        $this->actingAs($usuario)->delete(route('facturacion.listas.facturas.desvincular', [$lista, $fex]))->assertSessionHas('error');
        $this->assertCount(1, $lista->fresh()->facturas());
    }

    public function test_reabrir_exige_motivo_y_queda_auditado(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil] = $this->clienteHabilitado();
        $lista = $this->lista($perfil);
        $fex = $this->fex($cliente, 'DTE-11-M001P001-000000000000001');
        $usuario = $this->usuario();

        $this->actingAs($usuario)->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $fex->id]);
        $this->actingAs($usuario)->patch(route('facturacion.listas.finalizar', $lista));

        // Sin motivo: no se reabre.
        $this->actingAs($usuario)->post(route('facturacion.listas.reabrir', $lista), [])
            ->assertSessionHasErrors('motivo');
        $this->assertTrue($lista->fresh()->estaFinalizada());

        // Motivo demasiado corto para explicar nada: tampoco.
        $this->actingAs($usuario)->post(route('facturacion.listas.reabrir', $lista), ['motivo' => 'error'])
            ->assertSessionHasErrors('motivo');
        $this->assertTrue($lista->fresh()->estaFinalizada());

        // Con motivo: se reabre y queda registrado.
        $motivo = 'Faltó una caja de camote en el contenedor 2';
        $this->actingAs($usuario)->post(route('facturacion.listas.reabrir', $lista), ['motivo' => $motivo])
            ->assertSessionHas('status');

        $lista->refresh();
        $this->assertSame(Exportacion::ESTADO_BORRADOR, $lista->estado);
        $this->assertNull($lista->finalizada_en);
        $this->assertNull($lista->finalizada_por_user_id);
        $this->assertSame($motivo, $lista->revision_motivo);
        $this->assertTrue($lista->puedeEditarse());

        // La bitácora guarda quién y por qué: eso es lo que separa «corregir con acción
        // administrativa auditada» de «editar un documento final en silencio».
        $actividad = $lista->activities()->latest('id')->first();
        $this->assertNotNull($actividad);
        $this->assertSame('reabrió la lista de empaque finalizada', $actividad->description);
        $this->assertSame($usuario->id, $actividad->causer_id);
        $this->assertSame($motivo, $actividad->properties['motivo'] ?? null);
    }

    // --------------------------------------------- 4: el flujo real de FEX se reusa

    public function test_facturar_lleva_al_formulario_real_con_la_lista_en_contexto(): void
    {
        ['cliente' => $cliente, 'perfil' => $perfil] = $this->clienteHabilitado();
        $lista = $this->lista($perfil);

        $this->actingAs($this->usuario())
            ->get(route('facturacion.listas.facturar', $lista))
            ->assertRedirect(route('facturacion.create-exportacion', ['lista' => $lista->id, 'cliente_id' => $cliente->id]));

        // El formulario es el de siempre, con la lista recordada en un campo oculto.
        $this->actingAs($this->usuario())
            ->get(route('facturacion.create-exportacion', ['lista' => $lista->id]))
            ->assertOk()
            ->assertSee('Nueva Factura de exportación')
            ->assertSee('name="lista_id"', false)
            ->assertSee('lista de empaque #'.$lista->id);
    }

    public function test_sin_cliente_del_directorio_no_se_ofrece_facturar(): void
    {
        $perfilSinCliente = ExportacionCliente::create(['nombre' => 'SIN VINCULAR', 'activo' => true]);
        $lista = $this->lista($perfilSinCliente);

        $this->actingAs($this->usuario())->get(route('facturacion.listas.show', $lista))->assertOk()
            ->assertSee('Cliente del directorio no vinculado')
            ->assertDontSee(route('facturacion.listas.facturar', $lista), false);

        $this->actingAs($this->usuario())
            ->get(route('facturacion.listas.facturar', $lista))
            ->assertSessionHas('error');
    }

    // ------------------------------------------------------------------ permisos

    public function test_lectura_sin_gestion_ve_la_lista_pero_no_puede_cambiarla(): void
    {
        ['perfil' => $perfil] = $this->clienteHabilitado();
        $lista = $this->lista($perfil);
        $jefa = $this->usuario('jefatura');

        $this->actingAs($jefa)->get(route('facturacion.listas.index'))->assertOk();
        $this->actingAs($jefa)->get(route('facturacion.listas.show', $lista))->assertOk()
            ->assertDontSee(route('facturacion.listas.edit', $lista), false);

        $this->actingAs($jefa)->get(route('facturacion.listas.create'))->assertForbidden();
        $this->actingAs($jefa)->patch(route('facturacion.listas.finalizar', $lista))->assertForbidden();
        $this->actingAs($jefa)->delete(route('facturacion.listas.destroy', $lista))->assertForbidden();
    }
}
