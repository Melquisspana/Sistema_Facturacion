<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use Database\Seeders\CatalogosMhSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Listado de facturación: filtros, columnas (Número, Relacionado, Cliente/sala)
 * y la relación correcta de la NC con su CCF original.
 */
class DteListadoTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // El listado principal muestra SOLO producción (ambiente 01): estos DTEs de prueba
        // deben nacer en producción para aparecer en el listado que se está verificando.
        config(['dte.ambiente' => '01']);
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['01', '03', '05', '11'] as $t) {
            Correlativo::create(['tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '01', 'ultimo_numero' => 0, 'activo' => true]);
        }

        return compact('estab', 'pv');
    }

    private function ccfBorrador(array $emisor, ?Cliente $cliente = null, array $extra = []): Dte
    {
        return $this->borradores->crearBorrador(array_merge([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente ?? Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ], $extra));
    }

    private function generar(Dte $dte): Dte
    {
        $producto = Producto::factory()->create(['precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 2);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    private function ver(array $params = [])
    {
        return $this->actingAs($this->usuario('facturacion'))->get(route('facturacion.index', $params));
    }

    // --- Filtros ---

    public function test_filtra_por_tipo_ccf(): void
    {
        // Tokens de orden de compra (solo aparecen en la tabla, no en el <select> de clientes).
        $emisor = $this->emisor();
        $this->ccfBorrador($emisor, Cliente::factory()->contribuyente()->create(), ['numero_orden_compra' => 'OCTIPO-CCF']);
        $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::Factura,
            'cliente_id' => Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
            'numero_orden_compra' => 'OCTIPO-FAC',
        ]);

        $this->ver(['tipo_dte' => '03'])->assertOk()->assertSee('OCTIPO-CCF')->assertDontSee('OCTIPO-FAC');
        $this->ver(['tipo_dte' => '01'])->assertOk()->assertSee('OCTIPO-FAC')->assertDontSee('OCTIPO-CCF');
    }

    public function test_filtra_por_tipo_nota_credito(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->aceptarCcf($this->generar($this->ccfBorrador($emisor, Cliente::factory()->contribuyente()->create(['nombre' => 'GammaCorp SA']))));
        $this->borradores->crearNotaCredito($ccf, ['tipo' => 'pronto_pago']);

        // Filtro NC: aparece la NC (cuyo cliente es GammaCorp); con tipo CCF aparece el CCF.
        $this->ver(['tipo_dte' => '05'])->assertOk()->assertSee('Nota de Crédito');
    }

    public function test_filtra_por_estado_generado_y_borrador(): void
    {
        $emisor = $this->emisor();
        $this->generar($this->ccfBorrador($emisor, Cliente::factory()->contribuyente()->create(), ['numero_orden_compra' => 'OCEST-GEN']));
        $this->ccfBorrador($emisor, Cliente::factory()->contribuyente()->create(), ['numero_orden_compra' => 'OCEST-BOR']);

        $this->ver(['estado' => 'generado'])->assertOk()->assertSee('OCEST-GEN')->assertDontSee('OCEST-BOR');
        $this->ver(['estado' => 'borrador'])->assertOk()->assertSee('OCEST-BOR')->assertDontSee('OCEST-GEN');
    }

    /**
     * "pendientes_emitir" no es un estado real de EstadoDte: es un valor especial que el
     * chip del listado usa para agrupar generado+firmado+enviado (ya numerados/generados
     * localmente, pero sin resultado final de Hacienda). No debe incluir borrador ni
     * aceptado/rechazado/invalidado.
     */
    public function test_filtra_por_estado_pendientes_emitir(): void
    {
        $emisor = $this->emisor();

        $this->ccfBorrador($emisor, Cliente::factory()->contribuyente()->create(), ['numero_orden_compra' => 'OCPEND-BOR']);
        $this->generar($this->ccfBorrador($emisor, Cliente::factory()->contribuyente()->create(), ['numero_orden_compra' => 'OCPEND-GEN']));

        $firmado = $this->generar($this->ccfBorrador($emisor, Cliente::factory()->contribuyente()->create(), ['numero_orden_compra' => 'OCPEND-FIR']));
        $firmado->estado = EstadoDte::Firmado;
        $firmado->save();

        $enviado = $this->generar($this->ccfBorrador($emisor, Cliente::factory()->contribuyente()->create(), ['numero_orden_compra' => 'OCPEND-ENV']));
        $enviado->estado = EstadoDte::Enviado;
        $enviado->save();

        $this->aceptarCcf($this->generar($this->ccfBorrador($emisor, Cliente::factory()->contribuyente()->create(), ['numero_orden_compra' => 'OCPEND-ACE'])));

        $this->ver(['estado' => 'pendientes_emitir'])->assertOk()
            ->assertSee('OCPEND-GEN')
            ->assertSee('OCPEND-FIR')
            ->assertSee('OCPEND-ENV')
            ->assertDontSee('OCPEND-BOR')
            ->assertDontSee('OCPEND-ACE');
    }

    public function test_busca_por_orden_de_compra(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => true]);
        $this->ccfBorrador($emisor, $cliente, ['numero_orden_compra' => 'OC-98765']);
        $this->ccfBorrador($emisor, Cliente::factory()->contribuyente()->create(), ['numero_orden_compra' => 'OC-OTHER']);

        $this->ver(['q' => 'OC-98765'])->assertOk()->assertSee('OC-98765')->assertDontSee('OC-OTHER');
    }

    public function test_busca_por_numero_interno(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->generar($this->ccfBorrador($emisor, Cliente::factory()->contribuyente()->create(['nombre' => 'NumCorp SA'])));

        $this->ver(['q' => $ccf->numero_interno])->assertOk()->assertSee('NumCorp SA');
    }

    // --- Columnas ---

    public function test_muestra_cliente_y_sala(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
        $sucursal = ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'nombre' => 'Súper Selectos Atiquizaya']);
        $this->ccfBorrador($emisor, $cliente, ['cliente_sucursal_id' => $sucursal->id]);

        $this->ver()->assertOk()
            ->assertSee('Calleja, S.A. de C.V.')
            ->assertSee('Súper Selectos Atiquizaya');
    }

    public function test_consumidor_final_sin_cliente_se_muestra(): void
    {
        $emisor = $this->emisor();
        $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::Factura,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);

        $this->ver()->assertOk()->assertSee('Consumidor final');
    }

    public function test_ccf_muestra_su_numero_interno_en_columna_numero(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->generar($this->ccfBorrador($emisor, Cliente::factory()->contribuyente()->create()));

        // La columna "Número" prioriza el número de control oficial (la generación ahora
        // asigna numeración con el JSON atómico) y cae al interno si no existe.
        $this->assertNotNull($ccf->numero_interno);
        $this->assertNotNull($ccf->numero_control);
        $this->ver()->assertOk()->assertSee($ccf->numero_control);
    }

    // --- Relación NC ↔ CCF ---

    public function test_nc_relacionada_muestra_el_ccf_original_no_a_si_misma(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->aceptarCcf($this->generar($this->ccfBorrador($emisor, Cliente::factory()->contribuyente()->create())));
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'pronto_pago']);

        // La NC apunta al CCF, no a sí misma.
        $this->assertSame($ccf->id, $nc->dte_relacionado_id);
        $this->assertNotSame($nc->id, $nc->dte_relacionado_id);

        $this->ver()->assertOk()->assertSee($ccf->numero_control); // N° oficial del CCF relacionado
    }

    public function test_dte_no_puede_relacionarse_consigo_mismo(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfBorrador($emisor, Cliente::factory()->contribuyente()->create());

        // Defensa del observer: auto-relación se corrige a null al guardar.
        $ccf->dte_relacionado_id = $ccf->id;
        $ccf->save();

        $this->assertNull($ccf->refresh()->dte_relacionado_id);
    }

    public function test_nc_desde_ccf_guarda_relacionado_y_copia_orden(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['requiere_orden_compra' => true]);
        $ccf = $this->aceptarCcf($this->generar($this->ccfBorrador($emisor, $cliente, ['numero_orden_compra' => 'OC-111'])));

        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'devolucion_producto']);

        $this->assertSame($ccf->id, $nc->dte_relacionado_id);
        $this->assertSame('OC-111', $nc->numero_orden_compra); // orden copiada del CCF
    }

    public function test_nc_independiente_con_ccf_guarda_relacionado(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->aceptarCcf($this->generar($this->ccfBorrador($emisor, Cliente::factory()->contribuyente()->create())));

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-nota-credito'), [
                'tipo' => 'pronto_pago',
                'dte_relacionado_id' => $ccf->id,
                'establecimiento_id' => $emisor['estab']->id,
                'punto_venta_id' => $emisor['pv']->id,
            ])->assertRedirect();

        $nc = Dte::where('tipo_dte', '05')->firstOrFail();
        $this->assertSame($ccf->id, $nc->dte_relacionado_id);
        $this->assertNotSame($nc->id, $nc->dte_relacionado_id);
    }
}
