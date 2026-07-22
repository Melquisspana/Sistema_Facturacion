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
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
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

        $resp = $this->actingAs($this->usuario())->get(route('exportaciones.index'))->assertOk();

        $resp->assertSee('CLIENTE NORMAL VISIBLE');
        $resp->assertDontSee('PRUEBA APITEST ARCHIVADA');
    }

    // ---------- 2: el filtro permite verla ----------

    public function test_filtro_mostrar_archivadas_la_revela(): void
    {
        $archivada = $this->lista(['cliente_nombre' => 'PRUEBA APITEST ARCHIVADA', 'archivada' => true, 'archivada_en' => now(), 'observaciones' => 'PRUEBA APITEST - no es real']);

        $resp = $this->actingAs($this->usuario())
            ->get(route('exportaciones.index', ['archivadas' => 1]))
            ->assertOk();

        $resp->assertSee('PRUEBA APITEST ARCHIVADA');
        $resp->assertSee('Prueba APITEST / Archivada');
    }

    public function test_lista_archivada_sigue_accesible_por_url_directa(): void
    {
        $archivada = $this->lista(['archivada' => true, 'archivada_en' => now()]);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.show', $archivada))
            ->assertOk()
            ->assertSee('Archivada');
    }

    // ---------- 3: el vínculo con la FEX se conserva ----------

    public function test_archivar_no_toca_el_vinculo_con_la_fex(): void
    {
        $dte = $this->fexAceptada();
        $lista = $this->lista(['dte_id' => $dte->id]);

        $this->actingAs($this->usuario())
            ->patch(route('exportaciones.archivar', $lista))
            ->assertRedirect(route('exportaciones.show', $lista));

        $lista->refresh();
        $this->assertTrue($lista->archivada);
        $this->assertSame($dte->id, $lista->dte_id); // vínculo intacto

        // La FEX en sí no se tocó: mismo estado, mismo sello, mismo total.
        $dte->refresh();
        $this->assertSame('aceptado', $dte->estado->value);
        $this->assertSame('2026TEST-SELLO-REAL', $dte->sello_recepcion);
        $this->assertSame('10.50', number_format((float) $dte->total_pagar, 2, '.', ''));
    }

    public function test_archivar_no_modifica_snapshot_ni_estado_ni_otros_campos(): void
    {
        $lista = $this->lista(['cliente_nombre' => 'NOMBRE SNAPSHOT', 'cliente_direccion' => 'DIRECCION SNAPSHOT', 'estado' => 'aprobada']);

        $this->actingAs($this->usuario())->patch(route('exportaciones.archivar', $lista));

        $lista->refresh();
        $this->assertSame('NOMBRE SNAPSHOT', $lista->cliente_nombre);
        $this->assertSame('DIRECCION SNAPSHOT', $lista->cliente_direccion);
        $this->assertSame('aprobada', $lista->estado); // estado no cambia al archivar
    }

    public function test_desarchivar_revierte_y_vuelve_a_aparecer(): void
    {
        $lista = $this->lista(['cliente_nombre' => 'VUELVE A APARECER', 'archivada' => true, 'archivada_en' => now()]);

        $this->actingAs($this->usuario())->patch(route('exportaciones.desarchivar', $lista));

        $this->assertFalse($lista->fresh()->archivada);
        $this->assertNull($lista->fresh()->archivada_en);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.index'))
            ->assertOk()
            ->assertSee('VUELVE A APARECER');
    }

    // ---------- 4: no se puede borrar una lista vinculada (archivada o no) ----------

    public function test_no_se_puede_eliminar_una_lista_archivada_vinculada_a_fex(): void
    {
        $dte = $this->fexAceptada();
        $lista = $this->lista(['dte_id' => $dte->id, 'archivada' => true, 'archivada_en' => now()]);

        $this->actingAs($this->usuario())
            ->delete(route('exportaciones.destroy', $lista))
            ->assertRedirect(route('exportaciones.show', $lista))
            ->assertSessionHas('error');

        $this->assertNotNull(Exportacion::find($lista->id));
        $this->assertNotNull(Dte::find($dte->id));
    }

    // ---------- Rutas principales siguen cargando ----------

    public function test_pantallas_de_exportaciones_cargan(): void
    {
        $admin = $this->usuario();

        foreach (['exportaciones.index', 'exportaciones.create', 'exportaciones.productos.index', 'exportaciones.clientes.index'] as $ruta) {
            $this->actingAs($admin)->get(route($ruta))->assertOk();
        }
    }
}
