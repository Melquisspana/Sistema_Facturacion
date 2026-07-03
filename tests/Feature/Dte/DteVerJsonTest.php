<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Vista/descarga del JSON oficial PRELIMINAR ya generado. Es SOLO LECTURA del
 * archivo apuntado por json_generado_path: no regenera, no firma, no transmite,
 * no toca la BD. Verifica autorización (gestores) y seguridad de ruta.
 */
class DteVerJsonTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    private DteGeneracionService $generacion;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        Storage::fake('local'); // disco configurado en dte.storage.disk

        $this->borradores = app(DteBorradorService::class);
        $this->generacion = app(DteGeneracionService::class);

        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** CCF generado (sin JSON todavía). */
    private function ccfGenerado(): Dte
    {
        $cliente = Cliente::factory()->contribuyente()->create();
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);

        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal, 'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
        ]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);
        $this->generacion->generar($dte);

        return $dte->refresh();
    }

    /** CCF generado al que se le adjunta un JSON local (numeración + archivo en disco). */
    private function ccfConJson(string $contenido = '{"identificacion":{"numeroControl":"DTE-03-M001P001-000000000000001"}}'): Dte
    {
        $ccf = $this->ccfGenerado();
        $cg = '16323C76-AAAA-44AE-912C-AE8CBF880D5D';
        $ruta = 'dte/json/dte-03-'.$ccf->id.'-'.$cg.'.json';

        Storage::disk('local')->put($ruta, $contenido);

        $ccf->numero_control = 'DTE-03-M001P001-000000000000001';
        $ccf->codigo_generacion = $cg;
        $ccf->json_generado_path = $ruta;
        $ccf->save();

        return $ccf->refresh();
    }

    // --- Autorización ---

    public function test_usuario_autorizado_ve_el_json(): void
    {
        $ccf = $this->ccfConJson('{"identificacion":{"numeroControl":"DTE-03-OK"}}');

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.json', $ccf))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=utf-8')
            ->assertSee('DTE-03-OK');
    }

    public function test_usuario_no_gestor_no_puede_ver_el_json(): void
    {
        $ccf = $this->ccfConJson();

        // consulta puede ver el DTE pero NO el JSON (solo gestores).
        $this->actingAs($this->usuario('consulta'))
            ->get(route('facturacion.json', $ccf))
            ->assertForbidden();
    }

    public function test_invitado_no_puede_ver_el_json(): void
    {
        $ccf = $this->ccfConJson();

        $this->get(route('facturacion.json', $ccf))->assertRedirect('/login');
        $this->get(route('facturacion.json.descargar', $ccf))->assertRedirect('/login');
    }

    // --- Descarga ---

    public function test_la_descarga_funciona(): void
    {
        $ccf = $this->ccfConJson();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.json.descargar', $ccf))
            ->assertOk()
            ->assertDownload('dte-03-'.$ccf->id.'-16323C76-AAAA-44AE-912C-AE8CBF880D5D.json');
    }

    // --- Vista show: botón condicionado a json_generado_path ---

    public function test_show_no_muestra_botones_sin_json(): void
    {
        // La generación ahora crea el JSON oficial; para el escenario "sin JSON" (documento
        // viejo) lo quitamos explícitamente.
        $ccf = $this->ccfGenerado();
        $ccf->json_generado_path = null;
        $ccf->saveQuietly();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertDontSee('Ver JSON generado')
            ->assertDontSee('Descargar JSON generado');
    }

    public function test_show_muestra_panel_y_botones_con_json(): void
    {
        $ccf = $this->ccfConJson();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertSee('JSON generado localmente')
            ->assertSee('SIN FIRMA / SIN TRANSMISIÓN / NO ENVIADO A HACIENDA')
            ->assertSee($ccf->numero_control)
            ->assertSee($ccf->codigo_generacion)
            ->assertSee('Ver JSON generado')
            ->assertSee('Descargar JSON generado');
    }

    // --- Seguridad de ruta ---

    public function test_no_permite_leer_archivos_fuera_de_storage(): void
    {
        $ccf = $this->ccfConJson();
        // Forzar un path con traversal directamente en la BD (sin pasar por el servicio).
        $ccf->forceFill(['json_generado_path' => 'dte/json/../../../.env'])->saveQuietly();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.json', $ccf->refresh()))
            ->assertNotFound();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.json.descargar', $ccf))
            ->assertNotFound();
    }

    public function test_archivo_inexistente_da_error_claro(): void
    {
        $ccf = $this->ccfConJson();
        Storage::disk('local')->delete($ccf->json_generado_path); // el path existe en BD, el archivo no

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.json', $ccf))
            ->assertNotFound();
    }

    // --- No efectos secundarios ---

    public function test_ver_y_descargar_no_modifican_el_dte(): void
    {
        $ccf = $this->ccfConJson();
        $antes = $ccf->only(['estado', 'numero_control', 'codigo_generacion', 'json_generado_path', 'updated_at']);

        $admin = $this->usuario('administrador');
        $this->actingAs($admin)->get(route('facturacion.json', $ccf))->assertOk();
        $this->actingAs($admin)->get(route('facturacion.json.descargar', $ccf))->assertOk();

        $ccf->refresh();
        $this->assertSame($antes['estado'], $ccf->estado);
        $this->assertSame($antes['numero_control'], $ccf->numero_control);
        $this->assertSame($antes['codigo_generacion'], $ccf->codigo_generacion);
        $this->assertSame($antes['json_generado_path'], $ccf->json_generado_path);
        $this->assertEquals($antes['updated_at'], $ccf->updated_at);
        $this->assertNull($ccf->json_firmado_path);  // NO se firmó
        $this->assertNull($ccf->sello_recepcion);     // NO se transmitió
    }
}
