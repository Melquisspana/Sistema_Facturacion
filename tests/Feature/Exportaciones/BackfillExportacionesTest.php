<?php

namespace Tests\Feature\Exportaciones;

use App\Enums\TipoDte;
use App\Models\Cliente;
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
 * Compatibilidad con una PRODUCCIÓN que sí podría tener listas.
 *
 * Desarrollo tiene cero listas de empaque, pero producción no se auditó, así que
 * las migraciones se escribieron pensando que las hay. Estas pruebas reproducen el
 * estado anterior a mano —fila por fila, como lo dejaría el sistema viejo— y
 * comprueban las tres reglas que las gobiernan:
 *
 *   1. Nada se pierde: ni filas, ni columnas, ni vínculos.
 *   2. Nada se reinterpreta en silencio: lo ambiguo se conserva y se MARCA.
 *   3. El resultado es determinista: los mismos datos dan siempre lo mismo.
 *
 * El backfill de las migraciones ya corrió cuando la suite montó el esquema; acá se
 * ejercita la MISMA lógica sobre filas creadas después, que es lo que permite
 * probarla sin volver a migrar.
 */
class BackfillExportacionesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{establecimiento_id: int, punto_venta_id: int}|null */
    private ?array $emisorCache = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    /** Emisor mínimo: `dtes` exige establecimiento y punto de venta. */
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
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => true]);

        return $this->emisorCache = ['establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id];
    }

    private function fex(Cliente $cliente, string $numero): Dte
    {
        return Dte::create($this->emisor() + [
            'tipo_dte' => TipoDte::FacturaExportacion->value,
            'cliente_id' => $cliente->id,
            'estado' => 'aceptado',
            'ambiente' => '01',
            'numero_control' => $numero,
            'fecha_emision' => '2026-07-16', 'hora_emision' => '10:00:00',
            'total_pagar' => 10.50,
        ]);
    }

    /** Lista heredada AMBIGUA, tal como la deja la migración: marcada y sin traducir. */
    private function congelada(?ExportacionCliente $perfil = null, array $extra = []): Exportacion
    {
        return $this->listaHistorica($extra + [
            'exportacion_cliente_id' => $perfil?->id,
            'estado' => 'aprobada',
            'requiere_revision' => true,
            'revision_estado_original' => 'aprobada',
            'revision_motivo' => 'Estado heredado sin factura vinculada.',
        ]);
    }

    /** Fila tal como la dejaría el sistema anterior: sin pivote y con `dte_id`. */
    private function listaHistorica(array $extra = []): Exportacion
    {
        return Exportacion::create($extra + [
            'cliente_nombre' => 'CAROLINAS WHOLESALE LLC',
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-07-16',
            'estado' => 'borrador',
        ]);
    }

    // ---------------------------------------- 1: la columna histórica sigue sirviendo

    public function test_una_lista_con_dte_id_y_sin_pivote_sigue_mostrando_su_factura(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $fex = $this->fex($cliente, 'DTE-11-M001P002-000000000000001');

        // Estado EXACTO anterior a la migración: columna puesta, tabla nueva vacía.
        $lista = $this->listaHistorica();
        DB::table('exportaciones')->where('id', $lista->id)->update(['dte_id' => $fex->id]);
        $this->assertSame(0, DB::table('exportacion_dte')->where('exportacion_id', $lista->id)->count());

        $lista->refresh();

        // El respaldo a la columna histórica es lo que hace que una instalación cuyo
        // backfill todavía no corrió no diga «sin facturas».
        $this->assertTrue($lista->tieneFex());
        $this->assertCount(1, $lista->facturas());
        $this->assertSame(['DTE-11-M001P002-000000000000001'], $lista->numerosFactura());
        $this->assertSame('DTE-11-M001P002-000000000000001', $lista->textoFactura());

        $this->actingAs($this->usuario())->get(route('facturacion.listas.show', $lista))->assertOk()
            ->assertSee('DTE-11-M001P002-000000000000001');
    }

    public function test_vincular_una_segunda_factura_no_mueve_la_columna_historica(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => 'CAROLINAS', 'activo' => true]);
        $primera = $this->fex($cliente, 'DTE-11-M001P002-000000000000001');
        $segunda = $this->fex($cliente, 'DTE-11-M001P002-000000000000002');

        $lista = $this->listaHistorica(['exportacion_cliente_id' => $perfil->id]);
        $lista->dtes()->attach($primera->id, ['principal' => true]);
        DB::table('exportaciones')->where('id', $lista->id)->update(['dte_id' => $primera->id]);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $segunda->id])
            ->assertSessionHas('status');

        $lista->refresh();
        $this->assertSame($primera->id, $lista->dte_id, 'la columna histórica apunta siempre a la primera factura');
        $this->assertCount(2, $lista->facturas());
    }

    // ------------------------------------------- 2: estados heredados sin reinterpretar

    public function test_un_estado_heredado_se_conserva_y_queda_congelado(): void
    {
        // 'aprobada' sin factura: el caso ambiguo. La migración lo marca y NO lo traduce.
        $lista = $this->listaHistorica([
            'estado' => 'aprobada',
            'requiere_revision' => true,
            'revision_estado_original' => 'aprobada',
            'revision_motivo' => 'Estado heredado sin factura vinculada.',
        ]);

        $this->assertSame('aprobada', $lista->estado, 'el valor original no se toca');
        $this->assertTrue($lista->estadoHeredado());
        $this->assertTrue($lista->requiereRevision());
        $this->assertFalse($lista->estaFinalizada());

        // NO se trata como borrador de trabajo: una lista que quizá se aprobó en su
        // momento no puede modificarse mientras nadie confirme qué era.
        $this->assertFalse($lista->esBorrador());
        $this->assertFalse($lista->puedeEditarse());
        $this->assertFalse($lista->puedeFinalizarse());
        $this->assertNotNull($lista->motivoBloqueo());
    }

    public function test_la_lista_ambigua_se_ve_marcada_en_pantalla_y_tiene_su_filtro(): void
    {
        $lista = $this->listaHistorica([
            'cliente_nombre' => 'LISTA HEREDADA AMBIGUA',
            'estado' => 'aprobada',
            'requiere_revision' => true,
            'revision_motivo' => 'Estado heredado sin factura vinculada.',
        ]);

        $usuario = $this->usuario();

        // Aparece en el listado por defecto: no desaparece por tener un estado raro.
        $this->actingAs($usuario)->get(route('facturacion.listas.index'))->assertOk()
            ->assertSee('LISTA HEREDADA AMBIGUA')
            ->assertSee('Revisar')
            ->assertSee('lista(s) heredada(s)');

        $this->actingAs($usuario)->get(route('facturacion.listas.index', ['revision' => 1, 'estado' => 'todas']))->assertOk()
            ->assertSee('LISTA HEREDADA AMBIGUA');

        // Y su ficha explica qué pasó, con el valor original a la vista.
        $this->actingAs($usuario)->get(route('facturacion.listas.show', $lista))->assertOk()
            ->assertSee('viene del flujo anterior')
            ->assertSee('aprobada');
    }

    /**
     * Antes de resolverla NO se puede mutar por ninguna vía. Es el punto del modo
     * seguro: bloquear editar, facturar, finalizar y borrar hasta que alguien decida.
     */
    public function test_una_lista_congelada_no_se_puede_mutar_por_ninguna_via(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => 'CAROLINAS', 'activo' => true]);
        $fex = $this->fex($cliente, 'DTE-11-M001P002-000000000000001');
        $lista = $this->congelada($perfil);
        $usuario = $this->usuario();
        // Producto REAL: con uno inexistente fallaría la validación del formulario y la
        // prueba no llegaría a comprobar el candado, que es lo que interesa.
        $producto = ExportacionProducto::create([
            'nombre_es' => 'Caja', 'nombre_en' => 'Box', 'unidad' => 'Bolsa',
            'unidades_por_caja' => 100, 'gramos_por_unidad' => 10, 'onzas_por_unidad' => 1,
            'precio_caja' => 50, 'peso_neto_caja_kg' => 1, 'peso_bruto_caja_kg' => 2,
            'peso_neto_caja_lb' => 2, 'peso_bruto_caja_lb' => 4, 'activo' => true,
        ]);

        $this->actingAs($usuario)->get(route('facturacion.listas.edit', $lista))->assertSessionHasErrors('estado');

        $this->actingAs($usuario)->put(route('facturacion.listas.update', $lista), [
            'exportacion_cliente_id' => $perfil->id,
            'cliente_nombre' => 'CAMBIADO A LA FUERZA',
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-09-02',
            'items' => [['exportacion_producto_id' => $producto->id, 'cantidad_cajas' => 1]],
        ])->assertSessionHasErrors('estado');

        $this->actingAs($usuario)->patch(route('facturacion.listas.finalizar', $lista))->assertSessionHas('error');
        $this->actingAs($usuario)->delete(route('facturacion.listas.destroy', $lista))->assertSessionHas('error');
        $this->actingAs($usuario)->get(route('facturacion.listas.facturar', $lista))->assertSessionHasErrors('estado');
        $this->actingAs($usuario)->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $fex->id])
            ->assertSessionHas('error');

        $lista->refresh();
        $this->assertSame('aprobada', $lista->estado, 'ninguna vía pudo cambiar el estado histórico');
        $this->assertSame('CAROLINAS WHOLESALE LLC', $lista->cliente_nombre);
        $this->assertTrue($lista->requiereRevision());
        $this->assertCount(0, $lista->facturas());
        $this->assertNotNull(Exportacion::find($lista->id));
    }

    public function test_solo_un_administrador_puede_clasificar_una_lista_heredada(): void
    {
        $lista = $this->congelada();

        $facturacion = User::factory()->create()->assignRole('facturacion');
        $this->assertTrue($facturacion->can('exportaciones.gestionar'));

        $this->actingAs($facturacion)
            ->post(route('facturacion.listas.resolver-revision', $lista), [
                'clasificacion' => 'borrador', 'motivo' => 'Intento sin permiso suficiente.',
            ])
            ->assertForbidden();

        $this->assertTrue($lista->fresh()->requiereRevision());
    }

    public function test_clasificar_exige_motivo_y_una_clasificacion_conocida(): void
    {
        $lista = $this->congelada();
        $admin = $this->usuario();

        $this->actingAs($admin)->post(route('facturacion.listas.resolver-revision', $lista), [
            'clasificacion' => 'borrador',
        ])->assertSessionHasErrors('motivo');

        $this->actingAs($admin)->post(route('facturacion.listas.resolver-revision', $lista), [
            'clasificacion' => 'borrador', 'motivo' => 'corto',
        ])->assertSessionHasErrors('motivo');

        $this->actingAs($admin)->post(route('facturacion.listas.resolver-revision', $lista), [
            'clasificacion' => 'lo-que-sea', 'motivo' => 'Un motivo suficientemente largo.',
        ])->assertSessionHasErrors('clasificacion');

        $this->assertTrue($lista->fresh()->requiereRevision());
    }

    public function test_clasificar_como_borrador_la_libera_y_deja_rastro(): void
    {
        $lista = $this->congelada();
        $admin = $this->usuario();
        $motivo = 'Contabilidad confirma que el embarque sigue en curso.';

        $this->actingAs($admin)
            ->post(route('facturacion.listas.resolver-revision', $lista), [
                'clasificacion' => 'borrador', 'motivo' => $motivo,
            ])
            ->assertSessionHas('status');

        $lista->refresh();
        $this->assertFalse($lista->requiereRevision());
        $this->assertSame(Exportacion::ESTADO_BORRADOR, $lista->estado);
        $this->assertTrue($lista->puedeEditarse());

        // El estado original y la decisión quedan guardados.
        $this->assertSame('aprobada', $lista->revision_estado_original);
        $this->assertSame('borrador', $lista->revision_resolucion);
        $this->assertSame($motivo, $lista->revision_motivo);
        $this->assertSame($admin->id, $lista->revision_resuelta_por_user_id);
        $this->assertNotNull($lista->revision_resuelta_en);

        $actividad = $lista->activities()->latest('id')->first();
        $this->assertSame('clasificó una lista de empaque heredada', $actividad->description);
        $this->assertSame('aprobada', $actividad->properties['estado_original'] ?? null);
        $this->assertSame($motivo, $actividad->properties['motivo'] ?? null);
    }

    /**
     * Clasificar como «archivada» no puede ser un rodeo para desbloquear la lista:
     * sale de revisión, pero sigue fuera del flujo de trabajo y no se edita. Sin
     * esto bastaban dos pasos —archivar y volver— para editar justo la lista que la
     * marca protegía.
     */
    public function test_clasificar_como_archivada_no_la_vuelve_editable(): void
    {
        $lista = $this->congelada();
        $admin = $this->usuario();

        $this->actingAs($admin)
            ->post(route('facturacion.listas.resolver-revision', $lista), [
                'clasificacion' => 'archivada',
                'motivo' => 'Embarque de prueba que nunca salió del país.',
            ])
            ->assertSessionHas('status');

        $lista->refresh();
        $this->assertFalse($lista->requiereRevision());
        $this->assertTrue($lista->archivada);
        $this->assertSame('aprobada', $lista->estado, 'el estado histórico se conserva');
        $this->assertFalse($lista->esBorrador());
        $this->assertFalse($lista->puedeEditarse());
        $this->assertNotNull($lista->motivoBloqueo());

        $this->actingAs($admin)->get(route('facturacion.listas.edit', $lista))->assertSessionHasErrors('estado');
    }

    public function test_no_se_puede_clasificar_como_finalizada_sin_factura_vigente(): void
    {
        $lista = $this->congelada();

        $this->actingAs($this->usuario())
            ->post(route('facturacion.listas.resolver-revision', $lista), [
                'clasificacion' => 'finalizada', 'motivo' => 'Creo que ya se había cerrado.',
            ])
            ->assertSessionHas('error');

        $this->assertTrue($lista->fresh()->requiereRevision());
    }

    public function test_clasificar_como_finalizada_con_factura_cierra_la_lista(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => 'CAROLINAS', 'activo' => true]);
        $fex = $this->fex($cliente, 'DTE-11-M001P002-000000000000001');
        $lista = $this->congelada($perfil);
        $lista->dtes()->attach($fex->id, ['principal' => true]);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.listas.resolver-revision', $lista->fresh()), [
                'clasificacion' => 'finalizada',
                'motivo' => 'Facturada y embarcada en julio; se confirma el cierre.',
            ])
            ->assertSessionHas('status');

        $lista->refresh();
        $this->assertSame(Exportacion::ESTADO_FINALIZADA, $lista->estado);
        $this->assertFalse($lista->requiereRevision());
        $this->assertSame('aprobada', $lista->revision_estado_original);
        $this->assertNotNull($lista->finalizada_en);
        $this->assertFalse($lista->puedeEditarse());
    }

    // ------------------------------------------------ 3: conservación de datos reales

    /**
     * Escenario de producción en miniatura: el catálogo, los perfiles y los precios que
     * hoy existen de verdad. Después de las migraciones tienen que seguir ahí, con los
     * MISMOS ids —que es lo que referencian las asignaciones y los items— y los mismos
     * importes.
     */
    public function test_catalogo_perfiles_y_precios_sobreviven_intactos(): void
    {
        $productos = collect(range(1, 48))->map(fn ($i) => ExportacionProducto::create([
            'nombre_es' => 'Producto '.$i, 'nombre_en' => 'Product '.$i, 'unidad' => 'Bolsa',
            'unidades_por_caja' => 144, 'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3,
            'precio_caja' => 100 + $i,
            'peso_neto_caja_kg' => 13, 'peso_bruto_caja_kg' => 14,
            'peso_neto_caja_lb' => 28.66, 'peso_bruto_caja_lb' => 30.86,
            'activo' => true,
        ]));

        $perfiles = collect(['CAROLINAS', 'DIAMOND ROCKS', 'SOLFI', 'CUSCATLAN', 'PILOTO'])
            ->map(function (string $nombre) {
                $cliente = Cliente::factory()->exportacion()->create(['nombre' => $nombre]);

                return ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => $nombre, 'activo' => true]);
            });

        // 27 + 16 + 15 = 58 asignaciones, igual que en la base real.
        $reparto = [27, 16, 15, 0, 0];
        foreach ($perfiles as $i => $perfil) {
            foreach ($productos->take($reparto[$i]) as $producto) {
                ExportacionClienteProducto::create([
                    'exportacion_cliente_id' => $perfil->id,
                    'exportacion_producto_id' => $producto->id,
                    'precio_caja' => (float) $producto->precio_caja + 20,
                    'activo' => true,
                ]);
            }
        }

        $idsProductos = ExportacionProducto::orderBy('id')->pluck('id')->all();
        $preciosAntes = ExportacionClienteProducto::orderBy('id')->pluck('precio_caja', 'id')->all();

        $this->assertSame(48, count($idsProductos));
        $this->assertSame(58, count($preciosAntes));

        // Operaciones normales del flujo nuevo sobre ese estado.
        $this->actingAs($this->usuario())->get(route('productos.exportacion.index'))->assertOk();
        $this->actingAs($this->usuario())->get(route('clientes.show', $perfiles->first()->cliente_id))->assertOk();

        $this->assertSame($idsProductos, ExportacionProducto::orderBy('id')->pluck('id')->all());
        $this->assertSame($preciosAntes, ExportacionClienteProducto::orderBy('id')->pluck('precio_caja', 'id')->all());
    }

    // -------------------------------------------- 4: el pivote no admite duplicados

    public function test_el_pivote_impide_vincular_dos_veces_la_misma_factura(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $perfil = ExportacionCliente::create(['cliente_id' => $cliente->id, 'nombre' => 'CAROLINAS', 'activo' => true]);
        $fex = $this->fex($cliente, 'DTE-11-M001P002-000000000000001');
        $lista = $this->listaHistorica(['exportacion_cliente_id' => $perfil->id]);

        // Vincular dos veces es idempotente, no un error ni una fila duplicada.
        foreach ([1, 2] as $_) {
            $this->actingAs($this->usuario())
                ->post(route('facturacion.listas.facturas.vincular', $lista), ['dte_id' => $fex->id])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(1, DB::table('exportacion_dte')->where('exportacion_id', $lista->id)->count());
    }

    // -------------------------------- 5: el FDA de la empresa se marca, no se borra

    public function test_el_fda_de_la_empresa_en_un_perfil_se_marca_para_revision_sin_borrarlo(): void
    {
        $cliente = Cliente::factory()->exportacion()->create();
        $perfil = ExportacionCliente::create([
            'cliente_id' => $cliente->id,
            'nombre' => 'CAROLINAS',
            'fda_reg_number' => '12015435846',
            'fda_requiere_revision' => true,
            'activo' => true,
        ]);

        // El valor SIGUE en la columna: nadie puede afirmar desde una migración que no
        // sea también, por casualidad, el del importador.
        $this->assertSame('12015435846', $perfil->fda_reg_number);
        // Pero no se devuelve como dato del importador mientras esté marcado.
        $this->assertNull($perfil->fdaImportador());

        $this->actingAs($this->usuario())->get(route('clientes.show', $cliente))->assertOk()
            ->assertSee('Revisá el FDA')
            ->assertSee('12015435846');

        // Guardar el campo a conciencia ES la revisión: la marca desaparece.
        $this->actingAs($this->usuario())->put(route('clientes.exportacion.update', $cliente), [
            'fda_reg_number' => '',
            'contacto' => '',
            'direccion' => '',
        ]);

        $perfil->refresh();
        $this->assertFalse($perfil->fda_requiere_revision);
    }
}
