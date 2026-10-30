<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PpqAlbaran;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Ppq\AlbaranParser;
use App\Support\Dinero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\Concerns\RepresentacionPdfDte;
use Tests\TestCase;

class DteNotaCreditoAc04Test extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;
    use RepresentacionPdfDte;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
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
        foreach (['03', '05'] as $t) {
            Correlativo::create(['tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);
        }

        return compact('estab', 'pv');
    }

    private function ccfGenerado(array $emisor, ?Cliente $cliente = null): Dte
    {
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente ?? Cliente::factory()->contribuyente()->create(),
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
        $producto = Producto::factory()->create(['codigo' => '45678', 'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($dte);

        // La NC exige un CCF ACEPTADO por Hacienda (regla de negocio).
        return $this->aceptarCcf($dte);
    }

    public function test_parser_extrae_las_lineas_de_ac04(): void
    {
        $datos = app(AlbaranParser::class)->desdeTexto(file_get_contents(base_path('tests/Fixtures/Ppq/ac04-ficticia.txt')));
        $this->assertSame('45678', $datos['lineas'][0]['codigo']);
        $this->assertSame('12.000', $datos['lineas'][0]['cantidad']);
        $this->assertSame('2.3500', $datos['lineas'][0]['costo']);
    }

    private function ac04(Dte $ccf, string $cantidad = '3.000', bool $legible = true, bool $ajeno = false): PpqAlbaran
    {
        Storage::fake('local');
        $linea = $ccf->lineas()->firstOrFail();
        $texto = file_get_contents(base_path('tests/Fixtures/Ppq/ac04-ficticia.txt'));
        $texto = str_replace('45678 9876543210123', ($ajeno ? '999999' : $linea->codigo).' '.($ajeno ? '9999999999999' : ($linea->codigo_barra ?: '9876543210123')), $texto);
        $texto = str_replace('12.000 0.000 2.3500', $cantidad.' 0.000 10.0000', $texto);
        $datos = app(AlbaranParser::class)->desdeTexto($texto);
        $this->mock(AlbaranParser::class, function ($mock) use ($datos, $legible) {
            $mock->shouldReceive('desdePdf')->andReturn($legible ? $datos : ['lineas' => [], 'debug' => ['error' => 'PDF ilegible']]);
        });
        Storage::disk('local')->put('ac04.pdf', 'pdf ficticio');

        return PpqAlbaran::create([
            'tipo_codigo' => 'AC04', 'numero_albaran' => 'AC04/0148/00/8427',
            'dte_id' => $ccf->id, 'archivo_path' => 'ac04.pdf', 'sala_codigo' => '0148',
            'monto_albaran' => '31.87', 'fecha_albaran' => today(),
        ]);
    }

    #[DataProvider('cantidades')]
    public function test_precarga_y_guarda_por_el_flujo_normal_sin_emitir(string $cantidad): void
    {
        $ccf = $this->ccfGenerado($this->emisor());
        $nota = $this->ac04($ccf, $cantidad);
        $respuesta = $this->actingAs($this->usuario('facturacion'))->get(route('facturacion.create-nota-credito', ['ccf' => $ccf->id, 'ac04' => $nota->id]))->assertOk();
        $this->assertSame('devolucion_producto', $respuesta->viewData('datosNc')['tipo']);
        $this->assertCount(1, $respuesta->viewData('precargaAc04')['lineas']);
        $this->assertDatabaseMissing('dtes', ['tipo_dte' => '05']);
        $this->post(route('facturacion.store-nota-credito'), ['tipo' => 'devolucion_producto', 'dte_relacionado_id' => $ccf->id, 'ac04_id' => $nota->id])->assertSessionHasNoErrors()->assertRedirect();
        $nc = Dte::where('tipo_dte', '05')->firstOrFail();
        $this->assertSame('borrador', $nc->estado->value);
        $this->assertSame(0, Dinero::comparar($cantidad, $nc->lineas()->sole()->cantidad));
        $this->assertSame($nota->numero_albaran, $nc->albaran->numero_canonico);
        $this->assertSame($nota->id, $nc->albaran->ppq_albaran_id);
        $this->post(route('facturacion.store-nota-credito'), ['tipo' => 'devolucion_producto', 'dte_relacionado_id' => $ccf->id, 'ac04_id' => $nota->id])->assertSessionHasErrors('numero');
        $this->assertSame(1, Dte::where('tipo_dte', '05')->count());
    }

    public static function cantidades(): array
    {
        return [['3.000'], ['10.000']];
    }

    public function test_vincula_la_ac04_elegida_aunque_otra_sala_tenga_el_mismo_correlativo(): void
    {
        $ccf = $this->ccfGenerado($this->emisor());
        PpqAlbaran::create(['tipo_codigo' => 'AC04', 'numero_albaran' => 'AC04/0999/00/8427']);
        $nota = $this->ac04($ccf);
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-nota-credito'), ['tipo' => 'devolucion_producto', 'dte_relacionado_id' => $ccf->id, 'ac04_id' => $nota->id])->assertSessionHasNoErrors();
        $this->assertSame($nota->id, Dte::where('tipo_dte', '05')->sole()->albaran->ppq_albaran_id);
    }

    public function test_pdf_ilegible_abre_sin_lineas_y_avisa(): void
    {
        $ccf = $this->ccfGenerado($this->emisor());
        $nota = $this->ac04($ccf, legible: false);
        $respuesta = $this->actingAs($this->usuario('facturacion'))->get(route('facturacion.create-nota-credito', ['ccf' => $ccf->id, 'ac04' => $nota->id]))->assertOk()->assertSee('No se pudieron leer');
        $this->assertSame([], $respuesta->viewData('precargaAc04')['lineas']);
    }

    public function test_producto_ajeno_se_excluye_y_avisa(): void
    {
        $ccf = $this->ccfGenerado($this->emisor());
        $nota = $this->ac04($ccf, ajeno: true);
        $respuesta = $this->actingAs($this->usuario('facturacion'))->get(route('facturacion.create-nota-credito', ['ccf' => $ccf->id, 'ac04' => $nota->id]))->assertOk()->assertSee('no pertenece');
        $this->assertSame([], $respuesta->viewData('precargaAc04')['lineas']);
        $this->post(route('facturacion.store-nota-credito'), ['tipo' => 'devolucion_producto', 'dte_relacionado_id' => $ccf->id, 'ac04_id' => $nota->id])->assertSessionHasNoErrors();
        $this->assertCount(0, Dte::where('tipo_dte', '05')->sole()->lineas);
    }

    public function test_cantidad_ya_acreditada_se_rechaza_sin_dejar_otro_borrador(): void
    {
        $ccf = $this->ccfGenerado($this->emisor());
        $nc = $this->borradores->crearNotaCredito($ccf);
        $this->borradores->acreditarLinea($nc, $ccf->lineas()->sole(), '8');
        app(DteGeneracionService::class)->generar($nc);
        $nota = $this->ac04($ccf, '3.000');
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-nota-credito'), ['tipo' => 'devolucion_producto', 'dte_relacionado_id' => $ccf->id, 'ac04_id' => $nota->id])->assertSessionHasErrors('cantidad');
        $this->assertSame(1, Dte::where('tipo_dte', '05')->count());
        $this->assertDatabaseCount('dte_albaranes', 0);
    }

    public function test_servidor_revalida_el_ccf_y_tipo_de_la_ac04(): void
    {
        $emisor = $this->emisor();
        $ccf = $this->ccfGenerado($emisor);
        $nota = $this->ac04($ccf);
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.store-nota-credito'), ['tipo' => 'averia', 'dte_relacionado_id' => $ccf->id, 'ac04_id' => $nota->id])->assertSessionHasErrors('ac04_id');
        $this->assertDatabaseMissing('dtes', ['tipo_dte' => '05']);
    }

    public function test_una_fila_truncada_deja_toda_la_precarga_sin_lineas(): void
    {
        $texto = file_get_contents(base_path('tests/Fixtures/Ppq/ac04-ficticia.txt'));
        $texto .= "\n99999 9999999999999 PRODUCTO FICTICIO SIN COSTO 1.000\n";
        $this->assertSame([], app(AlbaranParser::class)->desdeTexto($texto)['lineas']);
    }

    public function test_sin_permiso_no_abre_la_precarga(): void
    {
        $ccf = $this->ccfGenerado($this->emisor());
        $nota = $this->ac04($ccf);
        $this->actingAs($this->usuario('contabilidad'))->get(route('facturacion.create-nota-credito', ['ccf' => $ccf->id, 'ac04' => $nota->id]))->assertForbidden();
        $this->post(route('facturacion.store-nota-credito'), ['tipo' => 'devolucion_producto', 'dte_relacionado_id' => $ccf->id, 'ac04_id' => $nota->id])->assertForbidden();
    }
}
