<?php

namespace Tests\Feature\Exportaciones;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Empresa;
use App\Models\Exportacion;
use App\Models\PuntoVenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Archivado de Listas de empaque de PRUEBA (no real): oculta del listado normal
 * sin borrar nada ni tocar la FEX vinculada. Caso real que motivó esto: la
 * Lista #8 es una copia de prueba APITEST vinculada a la FEX #143 (evidencia
 * real, aceptada por Hacienda) que debe permanecer intacta.
 */
class ExportacionArchivadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol = 'administrador'): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    private function lista(array $override = []): Exportacion
    {
        return Exportacion::create(array_merge([
            'cliente_nombre' => 'Cliente Piloto Exportación USA',
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-07-21',
            'estado' => 'aprobada',
        ], $override));
    }

    /** DTE mínimo tipo FEX ya aceptado, como la #143 real. */
    private function fexAceptada(): Dte
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P002', 'nombre' => 'Caja 2', 'activo' => true]);
        $cliente = Cliente::factory()->exportacion()->create();

        return Dte::create([
            'tipo_dte' => TipoDte::FacturaExportacion->value,
            'estado' => 'aceptado',
            'ambiente' => '00',
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'cliente_id' => $cliente->id,
            'numero_control' => 'DTE-11-M001P002-000000000000001',
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => now()->toTimeString(),
            'sello_recepcion' => '2026TEST-SELLO-REAL',
            'total_pagar' => 10.50,
        ]);
    }

    // ---------- 1: no aparece por defecto ----------

    public function test_lista_archivada_no_aparece_en_el_listado_por_defecto(): void
    {
        $normal = $this->lista(['cliente_nombre' => 'CLIENTE NORMAL VISIBLE']);
        $archivada = $this->lista(['cliente_nombre' => 'PRUEBA APITEST ARCHIVADA', 'archivada' => true, 'archivada_en' => now(), 'observaciones' => 'PRUEBA APITEST - no es real']);

        $resp = $this->actingAs($this->usuario())->get(route('facturacion.listas.index'))->assertOk();

        $resp->assertSee('CLIENTE NORMAL VISIBLE');
        $resp->assertDontSee('PRUEBA APITEST ARCHIVADA');
    }

    // ---------- 2: el filtro permite verla ----------

    public function test_filtro_mostrar_archivadas_la_revela(): void
    {
        $archivada = $this->lista(['cliente_nombre' => 'PRUEBA APITEST ARCHIVADA', 'archivada' => true, 'archivada_en' => now(), 'observaciones' => 'PRUEBA APITEST - no es real']);

        $resp = $this->actingAs($this->usuario())
            ->get(route('facturacion.listas.index', ['estado' => 'archivadas']))
            ->assertOk();

        $resp->assertSee('PRUEBA APITEST ARCHIVADA');
        $resp->assertSee('Prueba APITEST / Archivada');
    }

    public function test_filtro_archivadas_muestra_solo_las_archivadas(): void
    {
        $this->lista(['cliente_nombre' => 'CLIENTE ACTIVO FILTRO']);
        $this->lista(['cliente_nombre' => 'CLIENTE ARCHIVADO FILTRO', 'archivada' => true, 'archivada_en' => now()]);

        $resp = $this->actingAs($this->usuario())
            ->get(route('facturacion.listas.index', ['estado' => 'archivadas']))
            ->assertOk();

        $resp->assertSee('CLIENTE ARCHIVADO FILTRO');
        $resp->assertDontSee('CLIENTE ACTIVO FILTRO');
    }

    public function test_filtro_todas_muestra_activas_y_archivadas(): void
    {
        $this->lista(['cliente_nombre' => 'CLIENTE ACTIVO FILTRO']);
        $this->lista(['cliente_nombre' => 'CLIENTE ARCHIVADO FILTRO', 'archivada' => true, 'archivada_en' => now()]);

        $resp = $this->actingAs($this->usuario())
            ->get(route('facturacion.listas.index', ['estado' => 'todas']))
            ->assertOk();

        $resp->assertSee('CLIENTE ACTIVO FILTRO');
        $resp->assertSee('CLIENTE ARCHIVADO FILTRO');
    }

    public function test_filtro_invalido_cae_al_default_activas(): void
    {
        $this->lista(['cliente_nombre' => 'CLIENTE ACTIVO FILTRO']);
        $this->lista(['cliente_nombre' => 'CLIENTE ARCHIVADO FILTRO', 'archivada' => true, 'archivada_en' => now()]);

        $resp = $this->actingAs($this->usuario())
            ->get(route('facturacion.listas.index', ['estado' => 'lo-que-sea']))
            ->assertOk();

        $resp->assertSee('CLIENTE ACTIVO FILTRO');
        $resp->assertDontSee('CLIENTE ARCHIVADO FILTRO');
    }

    public function test_lista_archivada_sigue_accesible_por_url_directa(): void
    {
        $archivada = $this->lista(['archivada' => true, 'archivada_en' => now()]);

        $this->actingAs($this->usuario())
            ->get(route('facturacion.listas.show', $archivada))
            ->assertOk()
            ->assertSee('Archivada');
    }

    // ---------- 3: el vínculo con la FEX se conserva ----------

    /**
     * Archivar dejó de ser una acción suelta: es una de las tres clasificaciones de
     * la resolución administrativa de una lista heredada. Lo que se comprueba sigue
     * siendo lo mismo — archivar no toca el vínculo con la factura ni el documento.
     */
    public function test_clasificar_como_archivada_no_toca_el_vinculo_con_la_fex(): void
    {
        $dte = $this->fexAceptada();
        $lista = $this->lista([
            'dte_id' => $dte->id,
            'estado' => 'aprobada',
            'requiere_revision' => true,
            'revision_estado_original' => 'aprobada',
        ]);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.listas.resolver-revision', $lista), [
                'clasificacion' => 'archivada',
                'motivo' => 'Embarque de prueba: no salió del país.',
            ])
            ->assertRedirect(route('facturacion.listas.show', $lista));

        $lista->refresh();
        $this->assertTrue($lista->archivada);
        $this->assertSame($dte->id, $lista->dte_id); // vínculo intacto

        // La FEX en sí no se tocó: mismo estado, mismo sello, mismo total.
        $dte->refresh();
        $this->assertSame('aceptado', $dte->estado->value);
        $this->assertSame('2026TEST-SELLO-REAL', $dte->sello_recepcion);
        $this->assertSame('10.50', number_format((float) $dte->total_pagar, 2, '.', ''));
    }

    public function test_clasificar_como_archivada_conserva_snapshot_y_estado_original(): void
    {
        $lista = $this->lista([
            'cliente_nombre' => 'NOMBRE SNAPSHOT',
            'cliente_direccion' => 'DIRECCION SNAPSHOT',
            'estado' => 'aprobada',
            'requiere_revision' => true,
            'revision_estado_original' => 'aprobada',
        ]);

        $this->actingAs($this->usuario())->post(route('facturacion.listas.resolver-revision', $lista), [
            'clasificacion' => 'archivada',
            'motivo' => 'Confirmado con contabilidad: se descartó.',
        ]);

        $lista->refresh();
        $this->assertSame('NOMBRE SNAPSHOT', $lista->cliente_nombre);
        $this->assertSame('DIRECCION SNAPSHOT', $lista->cliente_direccion);
        // Archivar NO reescribe el estado: es un eje aparte y el valor histórico es
        // justamente lo que había que conservar.
        $this->assertSame('aprobada', $lista->estado);
        $this->assertSame('aprobada', $lista->revision_estado_original);
        $this->assertSame('archivada', $lista->revision_resolucion);
        $this->assertTrue($lista->archivada);
    }

    /** Las rutas antiguas de archivado existen pero ya no cambian nada. */
    public function test_las_rutas_antiguas_de_archivado_ya_no_escriben(): void
    {
        $lista = $this->lista(['cliente_nombre' => 'NO SE ARCHIVA POR LA VIA VIEJA']);

        $this->actingAs($this->usuario())->patch(route('exportaciones.archivar', $lista))->assertStatus(409);
        $this->assertFalse($lista->fresh()->archivada);

        $archivada = $this->lista(['archivada' => true, 'archivada_en' => now()]);
        $this->actingAs($this->usuario())->patch(route('exportaciones.desarchivar', $archivada))->assertStatus(409);
        $this->assertTrue($archivada->fresh()->archivada);
    }

    // ---------- 4: no se puede borrar una lista vinculada (archivada o no) ----------

    public function test_no_se_puede_eliminar_una_lista_archivada_vinculada_a_fex(): void
    {
        $dte = $this->fexAceptada();
        $lista = $this->lista(['dte_id' => $dte->id, 'archivada' => true, 'archivada_en' => now()]);

        $this->actingAs($this->usuario())
            ->delete(route('facturacion.listas.destroy', $lista))
            ->assertRedirect(route('facturacion.listas.show', $lista))
            ->assertSessionHas('error');

        $this->assertNotNull(Exportacion::find($lista->id));
        $this->assertNotNull(Dte::find($dte->id));
    }

    // ---------- Rutas principales siguen cargando ----------

    /**
     * Las pantallas se reubicaron y ninguna quedó sin puerta: las URL antiguas
     * redirigen a su destino nuevo y ese destino carga. Se sigue la redirección de
     * verdad en vez de dar por buena la cabecera: un 302 hacia un 404 también es un
     * destino inaccesible.
     */
    public function test_las_urls_antiguas_llevan_a_las_pantallas_nuevas(): void
    {
        $admin = $this->usuario();

        $destinos = [
            'exportaciones.index' => 'facturacion.listas.index',
            'exportaciones.create' => 'facturacion.listas.create',
            'exportaciones.productos.index' => 'productos.exportacion.index',
            'exportaciones.productos.create' => 'productos.exportacion.create',
            'exportaciones.productos.importar' => 'productos.exportacion.importar',
        ];

        foreach ($destinos as $antigua => $nueva) {
            $this->actingAs($admin)->get(route($antigua))
                ->assertRedirect(route($nueva));

            $this->actingAs($admin)->get(route($nueva))->assertOk();
        }

        // Clientes: el destino es el directorio único, filtrado por tipo exportación.
        $this->actingAs($admin)->get(route('exportaciones.clientes.index'))
            ->assertRedirect(route('clientes.index', ['tipo_cliente' => 'exportacion']));
        $this->actingAs($admin)->get(route('clientes.index', ['tipo_cliente' => 'exportacion']))->assertOk();
    }

    public function test_la_url_antigua_de_una_lista_lleva_a_su_ficha_nueva(): void
    {
        $lista = $this->lista();

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.show', $lista))
            ->assertRedirect(route('facturacion.listas.show', $lista));
    }
}
