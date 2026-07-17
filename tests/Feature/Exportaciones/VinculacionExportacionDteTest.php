<?php

namespace Tests\Feature\Exportaciones;

use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Exportacion;
use App\Models\ExportacionCliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Infraestructura de vinculación Lista de Empaque <-> Cliente DTE <-> FEX.
 * Fase de INFRAESTRUCTURA solamente: NO crea la FEX desde la lista todavía, NO
 * copia líneas, NO genera JSON, NO consume correlativos, NO firma ni transmite.
 * Cubre: relaciones de modelo, unicidad, estados visibles y bloqueo de borrado.
 */
class VinculacionExportacionDteTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'contador', 'consulta'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    private function lista(?ExportacionCliente $clienteExpo = null): Exportacion
    {
        return Exportacion::create([
            'exportacion_cliente_id' => $clienteExpo?->id,
            'cliente_nombre' => $clienteExpo->nombre ?? 'CAROLINAS WHOLESALE LLC',
            'exportador_nombre' => 'Dulces La Negrita',
            'fecha' => '2026-07-17',
            'estado' => 'borrador',
        ]);
    }

    private function clienteExportacion(array $extra = []): ExportacionCliente
    {
        return ExportacionCliente::create($extra + [
            'nombre' => 'CAROLINAS WHOLESALE LLC',
            'direccion' => '11235 SOMERSET, BELTSVILLE, MD 20705 EEUU',
            'activo' => true,
        ]);
    }

    /** DTE mínimo (sin generar JSON) para probar la vinculación, no la emisión. */
    private function dte(Cliente $cliente, string $tipoDte = '11'): Dte
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();

        return Dte::create([
            'tipo_dte' => $tipoDte,
            'estado' => 'borrador',
            'ambiente' => '00',
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'cliente_id' => $cliente->id,
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => now()->toTimeString(),
        ]);
    }

    // ---------- 1-2: ExportacionCliente <-> Cliente ----------

    public function test_exportacion_cliente_puede_vincular_cliente_dte(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create();
        $clienteExpo = $this->clienteExportacion();

        $clienteExpo->update(['cliente_id' => $clienteDte->id]);

        $this->assertTrue($clienteExpo->fresh()->cliente->is($clienteDte));
    }

    public function test_cliente_dte_puede_tener_varios_exportacion_clientes(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create();
        $expo1 = $this->clienteExportacion(['nombre' => 'CAROLINAS WHOLESALE LLC', 'cliente_id' => $clienteDte->id]);
        $expo2 = $this->clienteExportacion(['nombre' => 'OTRO CLIENTE LLC', 'cliente_id' => $clienteDte->id]);

        $this->assertCount(2, $clienteDte->fresh()->exportacionClientes);
        $this->assertTrue($clienteDte->exportacionClientes->contains($expo1));
        $this->assertTrue($clienteDte->exportacionClientes->contains($expo2));
    }

    // ---------- 3-4: Exportacion <-> Dte ----------

    public function test_exportacion_puede_vincular_dte(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create();
        $dte = $this->dte($clienteDte);
        $lista = $this->lista();

        $lista->update(['dte_id' => $dte->id]);

        $this->assertTrue($lista->fresh()->dte->is($dte));
        $this->assertTrue($dte->fresh()->exportacionOrigen->is($lista));
    }

    public function test_dte_id_es_unico_en_exportaciones(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create();
        $dte = $this->dte($clienteDte);
        $this->lista()->update(['dte_id' => $dte->id]);

        $otraLista = $this->lista();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::transaction(fn () => $otraLista->update(['dte_id' => $dte->id]));
    }

    // ---------- 5: registros existentes con columnas NULL ----------

    public function test_exportaciones_existentes_sin_vinculo_siguen_funcionando(): void
    {
        $clienteExpo = $this->clienteExportacion();
        $lista = $this->lista($clienteExpo);

        $this->assertNull($lista->dte_id);
        $this->assertNull($clienteExpo->cliente_id);
        $this->assertFalse($lista->tieneFex());
        $this->assertNull($lista->dte);
        $this->assertNull($clienteExpo->cliente);
    }

    // ---------- 6: tieneFex() solo para tipo 11 ----------

    public function test_tiene_fex_true_solo_si_el_dte_es_tipo_11(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create();
        $dteFex = $this->dte($clienteDte, '11');
        $dteCcf = $this->dte(Cliente::factory()->contribuyente()->create(), '03');

        $listaConFex = $this->lista();
        $listaConFex->update(['dte_id' => $dteFex->id]);
        $this->assertTrue($listaConFex->fresh()->tieneFex());

        $listaConOtroTipo = $this->lista();
        $listaConOtroTipo->update(['dte_id' => $dteCcf->id]);
        $this->assertFalse($listaConOtroTipo->fresh()->tieneFex());
    }

    // ---------- 7-9: estados visibles en la ficha ----------

    public function test_show_muestra_cliente_dte_no_vinculado(): void
    {
        $clienteExpo = $this->clienteExportacion();
        $lista = $this->lista($clienteExpo);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.show', $lista))
            ->assertOk()
            ->assertSee('Cliente DTE no vinculado');
    }

    public function test_show_muestra_lista_lista_para_crear_fex(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create();
        $clienteExpo = $this->clienteExportacion(['cliente_id' => $clienteDte->id]);
        $lista = $this->lista($clienteExpo);
        $lista->items()->create([
            'nombre_es' => 'Canillitas 85 g', 'nombre_en' => 'Little canes 85 g',
            'unidad' => 'Bolsa', 'unidades_por_caja' => 144, 'cantidad_cajas' => 10, 'precio_caja' => 18.00,
            'gramos_por_unidad' => 85, 'onzas_por_unidad' => 3.00,
            'peso_neto_caja_kg' => 12, 'peso_bruto_caja_kg' => 13, 'peso_neto_caja_lb' => 26, 'peso_bruto_caja_lb' => 28,
        ]);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.show', $lista))
            ->assertOk()
            ->assertSee('Lista lista para crear FEX');
    }

    public function test_show_muestra_aviso_si_cliente_vinculado_pero_sin_lineas(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create();
        $clienteExpo = $this->clienteExportacion(['cliente_id' => $clienteDte->id]);
        $lista = $this->lista($clienteExpo);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.show', $lista))
            ->assertOk()
            ->assertSee('La Lista necesita productos antes de crear la FEX');
    }

    public function test_show_muestra_abrir_fex(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create();
        $clienteExpo = $this->clienteExportacion(['cliente_id' => $clienteDte->id]);
        $dte = $this->dte($clienteDte);
        $lista = $this->lista($clienteExpo);
        $lista->update(['dte_id' => $dte->id]);

        $this->actingAs($this->usuario())
            ->get(route('exportaciones.show', $lista))
            ->assertOk()
            ->assertSee('Abrir factura de exportación')
            ->assertSee('FEX #'.$dte->id.' vinculada', false);
    }

    // ---------- 10: bloquear borrado de Lista con FEX ----------

    public function test_destroy_bloqueado_si_tiene_fex_vinculada(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create();
        $dte = $this->dte($clienteDte);
        $lista = $this->lista();
        $lista->update(['dte_id' => $dte->id]);

        $this->actingAs($this->usuario('administrador'))
            ->delete(route('exportaciones.destroy', $lista))
            ->assertRedirect(route('exportaciones.show', $lista))
            ->assertSessionHas('error');

        $this->assertNotNull($lista->fresh());
        $this->assertNotNull(Dte::find($dte->id));
    }

    public function test_destroy_sigue_funcionando_sin_fex_vinculada(): void
    {
        $lista = $this->lista();

        $this->actingAs($this->usuario('administrador'))
            ->delete(route('exportaciones.destroy', $lista))
            ->assertRedirect(route('exportaciones.index'));

        $this->assertNull(Exportacion::find($lista->id));
    }

    // ---------- 11: bloquear desvincular cliente con listas FEX ----------

    public function test_desvincular_cliente_bloqueado_si_hay_listas_con_fex(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create();
        $clienteExpo = $this->clienteExportacion(['cliente_id' => $clienteDte->id]);
        $dte = $this->dte($clienteDte);
        $this->lista($clienteExpo)->update(['dte_id' => $dte->id]);

        $this->actingAs($this->usuario('administrador'))
            ->delete(route('exportaciones.clientes.desvincular-cliente-dte', $clienteExpo))
            ->assertSessionHasErrors('cliente_id');

        $this->assertSame($clienteDte->id, $clienteExpo->fresh()->cliente_id);
    }

    public function test_desvincular_cliente_permitido_sin_listas_con_fex(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create();
        $clienteExpo = $this->clienteExportacion(['cliente_id' => $clienteDte->id]);
        $this->lista($clienteExpo); // sin dte_id

        $this->actingAs($this->usuario('administrador'))
            ->delete(route('exportaciones.clientes.desvincular-cliente-dte', $clienteExpo))
            ->assertRedirect(route('exportaciones.clientes.show', $clienteExpo));

        $this->assertNull($clienteExpo->fresh()->cliente_id);
    }

    // ---------- vincular: solo clientes DTE de tipo exportación ----------

    public function test_vincular_rechaza_cliente_dte_que_no_es_de_tipo_exportacion(): void
    {
        $clienteDte = Cliente::factory()->contribuyente()->create();
        $clienteExpo = $this->clienteExportacion();

        $this->actingAs($this->usuario('administrador'))
            ->patch(route('exportaciones.clientes.vincular-cliente-dte', $clienteExpo), ['cliente_id' => $clienteDte->id])
            ->assertSessionHasErrors('cliente_id');

        $this->assertNull($clienteExpo->fresh()->cliente_id);
    }

    // ---------- 12-13: ninguna acción crea DTE ni consume correlativo ----------

    public function test_vincular_cliente_no_crea_dte_ni_consume_correlativo(): void
    {
        $clienteDte = Cliente::factory()->exportacion()->create();
        $clienteExpo = $this->clienteExportacion();

        $this->actingAs($this->usuario('administrador'))
            ->patch(route('exportaciones.clientes.vincular-cliente-dte', $clienteExpo), ['cliente_id' => $clienteDte->id])
            ->assertRedirect(route('exportaciones.clientes.show', $clienteExpo));

        $this->assertSame(0, Dte::count());
        $this->assertSame(0, Correlativo::count());
    }
}
