<?php

namespace Tests\Feature\Ppq;

use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\NcExportacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Jefatura sin «Generar formato de NC» (Melqui, 12/11/2026): no ve el botón en Documentos
 * fiscales y el servidor le da 403 en todas las rutas del formato. Conserva lo demás que le
 * da `ppq.ver` (Facturas por cobrar). Quien hoy lo usa, igual.
 */
class JefaturaSinFormatoNcTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;

    private NcExportacion $lote;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('dte.storage.disk', 'local'));

        $this->cliente = Cliente::factory()->contribuyente()->create();
        ClientePerfilDocumento::create([
            'cliente_id' => $this->cliente->id, 'activo' => true, 'codigo_proveedor' => '000123',
            'formato_export' => 'albaran_nc_v1', 'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0,
        ]);
        $this->lote = NcExportacion::create([
            'cliente_id' => $this->cliente->id, 'referencia' => 'NC-000123-20261112-01',
            'formato' => 'albaran_nc_v1', 'archivo_nombre' => '000123202611120900.xlsx',
        ]);
    }

    private function usuario(RolSistema $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol->value);
    }

    public function test_jefatura_no_ve_el_boton_y_conserva_facturas_por_cobrar(): void
    {
        $this->actingAs($this->usuario(RolSistema::Jefatura))->get(route('facturacion.index'))
            ->assertOk()
            ->assertDontSee('Generar formato de NC')
            ->assertDontSee(route('ppq.nc-exportaciones.index'), false)
            ->assertSee('Facturas por cobrar');

        $this->get(route('cobros.index'))->assertOk();
    }

    public function test_jefatura_recibe_403_en_todas_las_rutas_del_formato(): void
    {
        $this->actingAs($this->usuario(RolSistema::Jefatura));

        $this->get(route('ppq.nc-exportaciones.index'))->assertForbidden();
        $this->get(route('ppq.nc-exportaciones.show', $this->lote))->assertForbidden();
        $this->post(route('ppq.nc-exportaciones.descargar', $this->lote))->assertForbidden();
        $this->post(route('ppq.nc-exportaciones.store'), ['cliente_id' => $this->cliente->id, 'dtes' => [1]])->assertForbidden();
        $this->post(route('ppq.nc-exportaciones.presentar', $this->lote))->assertForbidden();

        $this->assertSame(0, $this->lote->fresh()->descargas);
    }

    public function test_quien_hoy_lo_usa_sigue_igual(): void
    {
        foreach ([RolSistema::Administrador, RolSistema::Facturacion, RolSistema::Contabilidad] as $rol) {
            $usuario = $this->usuario($rol);
            $this->actingAs($usuario)->get(route('facturacion.index'))->assertOk()->assertSee('Generar formato de NC');
            $this->actingAs($usuario)->get(route('ppq.nc-exportaciones.index'))->assertOk();
            $this->actingAs($usuario)->get(route('ppq.nc-exportaciones.show', $this->lote))->assertOk();
        }
    }
}
