<?php

namespace Tests\Feature;

use App\Enums\Cobros\EstadoPagoCobro;
use App\Enums\EstadoPedidoWeb;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\ClienteSucursal;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PedidoWeb;
use App\Models\PpqAlbaran;
use App\Models\Ruta;
use App\Models\User;
use App\Services\Cobros\BandejaCobros;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Rutas\EntregasCcf;
use App\Support\Dinero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * «Por cobrar» y «Por entregar» en el Inicio (16/11/2026): los mismos números que sus
 * pantallas, solo para quien puede abrirlas, sin consultas por documento y sin tumbar el
 * Inicio si la cuenta falla. Datos inventados.
 */
class InicioPorCobrarPorEntregarTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private ?Establecimiento $estab = null;

    private int $n = 0;

    private Cliente $cliente;

    private ClienteSucursal $sala;

    private Ruta $zona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 10:00:00');
        $this->seedCatalogosDte();
        config(['dte.ambiente' => '01', 'rutas.ambiente_ccf' => null, 'rutas.entregas_desde' => '2026-09-28']);

        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente Inventado S.A.']);
        ClientePerfilDocumento::create([
            'cliente_id' => $this->cliente->id,
            'activo' => true,
            'codigo_proveedor' => '000123',
            'formato_export' => 'albaran_nc_v1',
            'exige_albaran_en_nc' => false,
            'tolerancia_albaran' => 0,
        ]);
        app(PerfilDocumentoResolver::class)->olvidar();
        $this->zona = Ruta::create(['nombre' => 'Zona Inventada']);
        $this->sala = $this->cliente->sucursales()->create(['nombre' => 'Sala Inventada', 'ruta_id' => $this->zona->id]);
    }

    private function dte(string $tipo, array $extra = []): Dte
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $this->n++;

        return Dte::create($extra + [
            'establecimiento_id' => $this->estab->id,
            'tipo_dte' => $tipo,
            'estado' => 'aceptado',
            'ambiente' => '01',
            'cliente_id' => $this->cliente->id,
            'cliente_sucursal_id' => $this->sala->id,
            'numero_control' => 'DTE-'.$tipo.'-M001P002-'.str_pad((string) $this->n, 15, '0', STR_PAD_LEFT),
            'numero_orden_compra' => '2611'.str_pad((string) $this->n, 6, '0', STR_PAD_LEFT),
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLO'.Str::random(30),
            'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-11-10',
            'hora_emision' => '08:00:00',
            'total_pagar' => '100.00',
        ]);
    }

    /** Un CCF de $100.00 de la sala, con su seguimiento de cobro. */
    private function ccf(): CobroDocumento
    {
        $dte = $this->dte('03');

        return CobroDocumento::create([
            'cliente_id' => $this->cliente->id, 'dte_id' => $dte->id, 'tipo_dte' => '03',
            'numero_control' => $dte->numero_control, 'fecha_emision' => '2026-11-10', 'monto' => '100.00',
        ]);
    }

    private function nc(CobroDocumento $ccf, string $total): void
    {
        $this->dte('05', ['dte_relacionado_id' => $ccf->dte_id, 'total_pagar' => $total]);
    }

    /** El albarán de entrega, enlazado en el seguimiento como lo deja Cobros. */
    private function conAlbaran(CobroDocumento $documento): void
    {
        $albaran = PpqAlbaran::create(['numero_albaran' => 'AC01/0232/00/'.(6000 + $this->n), 'monto_albaran' => 100,
            'fecha_albaran' => '2026-11-11', 'origen' => 'gmail']);
        $documento->forceFill(['ppq_albaran_id' => $albaran->id])->save();
    }

    private function pagar(CobroDocumento $documento, string $monto): void
    {
        CobroEvento::create(['cobro_documento_id' => $documento->id, 'tipo' => 'pago', 'origen' => 'manual',
            'monto' => $monto, 'fecha' => '2026-11-14', 'detalle' => 'Pago inventado.']);
        $documento->refresh()->recalcularPago();
    }

    /** Un pedido web con ruta, ya mandado por la fábrica y con su factura aceptada. */
    private function pedidoWeb(): void
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $this->n++;
        $pedido = PedidoSala::create(['origen' => 'web', 'ruta_id' => $this->zona->id, 'fecha' => '2026-11-16', 'estado' => 'enviado']);
        $dte = Dte::withoutEvents(fn () => Dte::create([
            'tipo_dte' => '01', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => str_repeat('A', 40), 'fecha_procesamiento_mh' => now(),
            'establecimiento_id' => $this->estab->id, 'fecha_emision' => '2026-11-16', 'hora_emision' => '09:00:00',
            'numero_control' => 'DTE-01-M001P001-'.str_pad((string) $this->n, 15, '0', STR_PAD_LEFT), 'total_pagar' => '25.00',
        ]));
        PedidoWeb::create([
            'pedido_sala_id' => $pedido->id, 'dte_id' => $dte->id, 'web_id' => $this->n, 'codigo' => 'WEB-INVENTADO-'.$this->n,
            'idioma' => 'es', 'pagado_en' => now(), 'total_centavos' => 2500, 'moneda' => 'USD',
            'cliente_nombre' => 'Compradora Inventada', 'cliente_correo' => 'inventada@example.test',
            'cliente_telefono' => '7000-1234', 'direccion' => 'Calle Inventada 123', 'municipio' => 'Municipio Inventado',
            'entrega_tipo' => 'ruta', 'pasarela' => 'prueba', 'estado' => EstadoPedidoWeb::Facturado, 'informado_preparando_en' => now(),
            'factura_correo_en' => now(), 'informado_factura_en' => now(), 'lista_en' => now(), 'estado_por_informar' => 'en_ruta',
            'lineas' => [], 'cajitas_detalle' => [['cantidad' => 1]],
        ]);
    }

    /**
     * Una de cada: pendiente sin albarán, con albarán (por presentar), saldada por su NC,
     * parcial ($60 de $100), pagada, con NC de $20 y un pedido web con ruta.
     * Por cobrar: 4 facturas, $100 + $100 + $40 + $80 = $320. Por entregar: 3 CCF sin
     * albarán y 1 pedido web.
     */
    private function escenario(): void
    {
        $this->ccf();
        $this->conAlbaran($this->ccf());
        $this->nc($this->ccf(), '100.00');
        $parcial = $this->ccf();
        $this->conAlbaran($parcial);
        $this->pagar($parcial, '60.00');
        $pagada = $this->ccf();
        $this->conAlbaran($pagada);
        $this->pagar($pagada, '100.00');
        $this->nc($this->ccf(), '20.00');
        $this->pedidoWeb();
    }

    private function administrador(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    public function test_los_numeros_son_los_de_facturas_por_cobrar_y_por_entregar(): void
    {
        $this->escenario();
        $admin = $this->administrador();

        $inicio = $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Por cobrar', '$320.00', '4 facturas', 'con IVA'])
            ->assertSeeInOrder(['Por entregar', '4', '3 CCF y 1 pedido web'])
            ->assertDontSee('Calculado a las');
        $this->assertSame(['cantidad' => 4, 'saldo' => '320.00'], array_intersect_key($inicio->viewData('porCobrar'), ['cantidad' => 0, 'saldo' => 0]));
        $this->assertSame(['total' => 4, 'web' => 1], array_intersect_key($inicio->viewData('porEntregar'), ['total' => 0, 'web' => 0]));

        // Facturas por cobrar: las pestañas menos «Pagados» y el saldo de cada factura sin pagar.
        $cobros = $this->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))->assertOk();
        $etapas = $cobros->viewData('etapas');
        $this->assertSame(4, $etapas['no_entregados'] + $etapas['falta_albaran'] + $etapas['listos'] + $etapas['presentados'] + $etapas['diferencias']);
        $this->assertSame(4, $etapas[''] - $etapas['pagados']);
        $saldo = '0';
        foreach ($cobros->viewData('documentos')->getCollection() as $documento) {
            if ($documento->pago_estado !== EstadoPagoCobro::Pagado) {
                $saldo = Dinero::sumar($saldo, $documento->saldo());
            }
        }
        $this->assertSame('320.00', Dinero::redondear($saldo));

        // Por entregar: la misma cuenta, con los pedidos web.
        $porEntregar = $this->get(route('rutas.por-entregar.index'))->assertOk();
        $this->assertSame(4, $porEntregar->viewData('total'));
        $this->assertSame(1, $porEntregar->viewData('cuantosWeb'));

        // La segunda vez sale de lo guardado y dice de qué hora es.
        $this->get(route('dashboard'))->assertOk()->assertSee('Calculado a las 10:00');
    }

    public function test_cada_tarjeta_se_ve_solo_con_el_permiso_de_su_pantalla(): void
    {
        $this->escenario();
        foreach (['dte.ver', 'ppq.ver', 'rutas.ver'] as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        $cobra = User::factory()->create(['activo' => true]);
        $cobra->givePermissionTo('dte.ver', 'ppq.ver');
        $this->actingAs($cobra)->get(route('rutas.por-entregar.index'))->assertForbidden();
        $inicio = $this->get(route('dashboard'))->assertOk()
            ->assertSee('Por cobrar')->assertSee('$320.00')
            ->assertDontSee('Por entregar')->assertDontSee(route('rutas.por-entregar.index'), false);
        $this->assertNull($inicio->viewData('porEntregar'));

        $entrega = User::factory()->create(['activo' => true]);
        $entrega->givePermissionTo('dte.ver', 'rutas.ver');
        $this->actingAs($entrega)->get(route('cobros.index'))->assertForbidden();
        $inicio = $this->get(route('dashboard'))->assertOk()
            ->assertSee('Por entregar')->assertSee('3 CCF y 1 pedido web')
            ->assertDontSee('Por cobrar')->assertDontSee('$320.00');
        $this->assertNull($inicio->viewData('porCobrar'));
    }

    /** Consultas del Inicio, con lo guardado borrado para que las tarjetas cuenten de nuevo. */
    private function consultasDelInicio(User $usuario): int
    {
        Cache::flush();
        $this->actingAs($usuario);
        $total = 0;
        DB::listen(function () use (&$total) {
            $total++;
        });
        $this->get(route('dashboard'))->assertOk();

        return $total;
    }

    public function test_las_consultas_del_inicio_no_crecen_con_los_documentos(): void
    {
        $this->escenario();
        $admin = $this->administrador();
        // La primera vez carga los permisos: no se cuenta.
        $this->consultasDelInicio($admin);
        $antes = $this->consultasDelInicio($admin);

        $this->escenario();
        $this->escenario();
        $this->escenario();
        $despues = $this->consultasDelInicio($admin);

        $this->assertSame($antes, $despues, "Inicio: {$antes} consultas con pocos documentos y {$despues} con más");
        $this->get(route('dashboard'))->assertSeeInOrder(['$1,280.00', '16 facturas'])->assertSee('12 CCF y 4 pedidos web');
    }

    public function test_si_falla_la_cuenta_el_inicio_carga_igual_y_lo_reporta(): void
    {
        Log::spy();
        $this->mock(BandejaCobros::class)->shouldReceive('porCobrar')->andThrow(new RuntimeException('falla inventada de cobros'));
        $this->mock(EntregasCcf::class)->shouldReceive('porEntregarConWeb')->andThrow(new RuntimeException('falla inventada de entregas'));

        $html = $this->actingAs($this->administrador())->get(route('dashboard'))->assertOk()
            ->assertSee('Por cobrar')->assertSee('Por entregar')->assertSee('Ventas del mes')
            ->getContent();

        $this->assertSame(2, substr_count($html, 'No se pudo calcular'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($mensaje, $contexto = []) => str_contains($mensaje, 'Por cobrar')
            && ($contexto['error'] ?? null) === 'falla inventada de cobros')->once();
        Log::shouldHaveReceived('warning')->withArgs(fn ($mensaje, $contexto = []) => str_contains($mensaje, 'Por entregar')
            && ($contexto['error'] ?? null) === 'falla inventada de entregas')->once();
        // La falla no se guarda: la próxima visita vuelve a intentar.
        $this->assertFalse(Cache::has('inicio.por_cobrar'));
        $this->assertFalse(Cache::has('inicio.por_entregar'));
    }
}
