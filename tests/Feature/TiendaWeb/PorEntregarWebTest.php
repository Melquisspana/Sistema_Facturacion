<?php

namespace Tests\Feature\TiendaWeb;

use App\Enums\EstadoPedidoWeb;
use App\Enums\EstadoSalidaRuta;
use App\Enums\FormaCobroEntrega;
use App\Enums\OrigenRegistroEntrega;
use App\Enums\PropositoSalida;
use App\Enums\RolEnSalida;
use App\Facades\Ajustes;
use App\Http\Requests\Clientes\ClienteRequest;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PedidoSala;
use App\Models\PedidoWeb;
use App\Models\PersonalRuta;
use App\Models\PuntoVenta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use App\Services\Rutas\DiaVendedor;
use App\Services\Rutas\EntregasCcf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Etapa 4 de la tienda web (16/11/2026): el pedido web con ruta, ya mandado por la fábrica y
 * con su factura aceptada, entra a «Por entregar» de su zona como las salas. Está pagado: el
 * vendedor solo dice quién lo entregó y marca «Entregado», sin cobro; la entrega queda en el
 * viaje y la página recibe «entregado». Datos inventados.
 */
class PorEntregarWebTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private User $vendedor;

    private PersonalRuta $persona;

    private Ruta $zona;

    /** @var array{estab: Establecimiento, pv: PuntoVenta, empresa: Empresa}|null */
    private ?array $emisor = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 09:00:00');
        $this->seedCatalogosDte();
        config(['dte.ambiente' => '00', 'rutas.ambiente_ccf' => null]);
        $this->vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        $this->persona = PersonalRuta::create(['nombre' => 'Vendedor Inventado', 'user_id' => $this->vendedor->id]);
        $this->zona = Ruta::create(['nombre' => 'Zona Inventada']);
        $this->actingAs($this->vendedor);
    }

    private function pedido(array $extra = [], array $factura = [], ?int $zona = null): PedidoWeb
    {
        $n = PedidoWeb::count() + 1;
        $pedido = PedidoSala::create(['origen' => 'web', 'ruta_id' => $zona ?? $this->zona->id, 'fecha' => '2026-11-16', 'estado' => 'enviado']);
        $this->emisor ??= $this->crearEmisorDte();
        $dte = Dte::withoutEvents(fn () => Dte::create($factura + [
            'tipo_dte' => '01', 'estado' => 'aceptado', 'ambiente' => '00',
            'sello_recepcion' => str_repeat('A', 40), 'fecha_procesamiento_mh' => now(),
            'establecimiento_id' => $this->emisor['estab']->id, 'fecha_emision' => '2026-11-16', 'hora_emision' => '09:00:00',
            'numero_control' => 'DTE-01-M001P001-'.str_pad((string) $n, 15, '0', STR_PAD_LEFT), 'total_pagar' => '123.45',
        ]));

        return PedidoWeb::create($extra + [
            'pedido_sala_id' => $pedido->id, 'dte_id' => $dte->id, 'web_id' => $n, 'codigo' => 'WEB-INVENTADO-'.$n,
            'idioma' => 'es', 'pagado_en' => now(), 'total_centavos' => 12345, 'moneda' => 'USD',
            'cliente_nombre' => 'Compradora Inventada', 'cliente_correo' => 'inventada@example.test',
            'cliente_telefono' => '7000-1234', 'direccion' => 'Calle Inventada 123', 'municipio' => 'Municipio Inventado',
            'entrega_tipo' => 'ruta', 'pasarela' => 'prueba', 'estado' => EstadoPedidoWeb::Facturado, 'informado_preparando_en' => now(),
            'factura_correo_en' => now(), 'informado_factura_en' => now(), 'lista_en' => now(), 'estado_por_informar' => 'en_ruta',
            'lineas' => [], 'cajitas_detalle' => [['cantidad' => 2]],
        ]);
    }

    /** Un CCF de sala, de la misma zona, para comprobar que las salas siguen igual. */
    private function ccfDeSala(): Dte
    {
        $cliente = Cliente::factory()->create(['nombre' => 'Súper Inventado', 'cobro_en_entrega' => 'efectivo']);
        $sala = $cliente->sucursales()->create(['nombre' => 'Sala Inventada', 'ruta_id' => $this->zona->id]);
        $this->emisor ??= $this->crearEmisorDte();

        return Dte::withoutEvents(fn () => Dte::create([
            'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '00',
            'sello_recepcion' => str_repeat('B', 40), 'fecha_procesamiento_mh' => now(),
            'establecimiento_id' => $this->emisor['estab']->id, 'fecha_emision' => '2026-11-16', 'hora_emision' => '08:00:00',
            'cliente_id' => $cliente->id, 'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M900P001-000000000000900', 'total_pagar' => '50.00',
        ]));
    }

    private function marcar(PedidoWeb|Dte $documento, array $datos = [])
    {
        $dteId = $documento instanceof PedidoWeb ? $documento->dte_id : $documento->id;

        return $this->post(route('rutas.por-entregar.marcar', $dteId), $datos + [
            'resultado' => 'entregado', 'entregado_por_id' => $this->persona->id,
        ]);
    }

    private function viaje(): SalidaRuta
    {
        $viaje = SalidaRuta::create(['ruta_id' => $this->zona->id, 'fecha_inicio' => '2026-11-16',
            'estado' => EstadoSalidaRuta::EnCurso, 'proposito' => PropositoSalida::Ambos, 'automatica' => true]);
        $viaje->participantes()->create(['rutas_personal_id' => $this->persona->id, 'rol' => RolEnSalida::Responsable]);

        return $viaje;
    }

    public function test_aparece_en_su_zona_con_datos_descifrados_y_sin_pedir_cobro(): void
    {
        $web = $this->pedido();
        $this->get(route('rutas.por-entregar.index', ['ruta' => $this->zona->id]))->assertOk()
            ->assertSee('0 CCF de los últimos 45 días y 1 pedido web.')
            ->assertSee('Pedido web · Compradora I.')->assertSee('WEB')->assertSee('Ya pagado')
            ->assertSee('Calle Inventada 123, Municipio Inventado')->assertSee('Tel. 7000-1234')->assertSee('2 cajitas')
            ->assertSee('¿Quién lo entregó?')->assertSee('>Entregado</button>', false)
            ->assertDontSee('¿Cómo te pagaron?')->assertDontSee('name="forma_cobro"', false)->assertDontSee('$123.45')
            ->assertDontSee('No se pudo entregar')->assertDontSee('Entregar a:')
            ->assertDontSee(route('rutas.por-entregar.pdf', $web->dte_id));
        // Cifrados en la base; el PDF de la factura no se ofrece al vendedor.
        $this->assertNotSame('Calle Inventada 123', $web->getRawOriginal('direccion'));
        $this->assertNotSame('7000-1234', $web->getRawOriginal('cliente_telefono'));
        $this->get(route('rutas.por-entregar.pdf', $web->dte_id))->assertNotFound();
        // El inicio del vendedor lo cuenta, como la lista.
        $this->get(route('rutas.mi-salida.index'))->assertOk()->assertViewHas('porEntregar', 1);

        $web->pedidoSala->update(['ruta_id' => null]);
        $this->get(route('rutas.por-entregar.index', ['ruta' => 'sin']))->assertOk()->assertSee('Sin zona')->assertSee('Pedido web · Compradora I.');
    }

    public function test_excluye_no_mandado_no_aceptado_entregado_recoger_y_ambiente_ajeno(): void
    {
        $pedidos = [
            $this->pedido(['lista_en' => null]),
            $this->pedido(factura: ['estado' => 'enviado']),
            $this->pedido(['entregado_en' => now()]),
            $this->pedido(['entrega_tipo' => 'recoger']),
            $this->pedido(factura: ['ambiente' => '01']),
            $this->pedido(factura: ['sello_recepcion' => null]),
        ];
        $this->get(route('rutas.por-entregar.index'))->assertOk()->assertDontSee('Pedido web · Compradora I.');
        foreach ($pedidos as $web) {
            $this->marcar($web)->assertNotFound();
        }
        $this->assertDatabaseCount('salida_ruta_entregas', 0);
    }

    public function test_regalo_muestra_destinatario_y_nota_sin_precios_y_escapa_html(): void
    {
        $this->pedido(['regalo' => ['nombre' => '<b>Destinataria Ficticia</b>', 'telefono' => '7000-5678']]);
        $this->get(route('rutas.por-entregar.index'))->assertOk()
            ->assertSee('Entregar a: <b>Destinataria Ficticia</b> · 7000-5678')
            ->assertDontSee('<b>Destinataria Ficticia</b>', false)->assertSee('Regalo: el paquete va sin precios');
    }

    public function test_entrega_en_viaje_sin_cobro_auditoria_idempotencia_y_deshacer(): void
    {
        $web = $this->pedido();
        $viaje = $this->viaje();
        $this->get(route('rutas.por-entregar.index', ['ver' => 'zona']))->assertOk()->assertSee('Pedido web · Compradora I.');
        $this->marcar($web, ['forma_cobro' => 'efectivo', 'quedan_numero' => 'NO-GUARDAR', 'nota' => 'NO-GUARDAR', 'trae_nota_averia' => '1'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->marcar($web)->assertRedirect()->assertSessionHasNoErrors();

        $entrega = SalidaRutaEntrega::sole();
        $this->assertSame($viaje->id, $entrega->salida_ruta_id);
        $this->assertSame($web->dte_id, $entrega->dte_id);
        $this->assertNull($entrega->cliente_sucursal_id);
        $this->assertSame(FormaCobroEntrega::YaPagado, $entrega->forma_cobro);
        $this->assertNull($entrega->monto_cobrado);
        $this->assertNull($entrega->monto_esperado);
        $this->assertNull($entrega->quedan_numero);
        $this->assertNull($entrega->nota);
        $this->assertFalse($entrega->trae_nota_averia);
        $this->assertSame($this->persona->id, $entrega->entregado_por_id);
        $this->assertNotNull($web->refresh()->entregado_en);
        $this->assertSame('entregado', $web->estado_por_informar);
        $auditoria = Activity::where('description', 'entregó el pedido web')->sole();
        $this->assertSame(collect(['estado' => 'entregado', 'entrega_id' => $entrega->id, 'entregado_por_id' => $this->persona->id])->sortKeys()->all(), $auditoria->properties->sortKeys()->all());
        $this->assertSame($this->vendedor->id, $auditoria->causer_id);
        $this->assertSame($web->id, $auditoria->subject_id);

        // Sin cobro: no suma al dinero del día ni queda como quedan; el PDF lo nombra.
        $this->assertFalse(SalidaRutaEntrega::quedanPorCobrar()->exists());
        $dia = app(DiaVendedor::class)->resumen($this->persona, $this->vendedor, '2026-11-16');
        $this->assertSame(0, $dia['cobrado']);
        $this->assertSame(0, $dia['quedan']['cantidad']);
        $this->assertCount(1, $dia['entregadas']);
        $pdf = view('rutas.mi-salida.dia-pdf', ['resumen' => $dia, 'persona' => $this->persona, 'empresa' => 'Empresa Inventada', 'logo' => null, 'generado' => now()])->render();
        $this->assertStringContainsString('Pedido web · Compradora I.', $pdf);
        $this->assertStringContainsString('Ya pagado', $pdf);
        $this->assertStringNotContainsString('Sala sin identificar', $pdf);

        $this->get(route('rutas.por-entregar.index'))->assertOk()->assertSee('Marcados hoy')
            ->assertSee('Pedido web · Compradora I.')->assertSee('Ya pagado')->assertSee('Deshacer')->assertDontSee('$123.45');
        $this->patch(route('rutas.mi-salida.deshacer', [$viaje, $entrega]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($web->refresh()->entregado_en);
        $this->assertSame('en_ruta', $web->estado_por_informar);
        $deshecha = Activity::where('description', 'deshizo la entrega del pedido web')->sole();
        $this->assertSame(collect(['estado' => 'en_ruta', 'entrega_id' => $entrega->id])->sortKeys()->all(), $deshecha->properties->sortKeys()->all());
        $this->assertDatabaseCount('salida_ruta_entregas', 0);
        $this->get(route('rutas.por-entregar.index'))->assertOk()->assertSee('Pedido web · Compradora I.');
    }

    public function test_sin_viaje_crea_automatica_en_la_zona_del_pedido(): void
    {
        $web = $this->pedido();
        $this->marcar($web)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($this->zona->id, SalidaRutaEntrega::sole()->salida->ruta_id);
    }

    public function test_sin_zona_se_entrega_en_una_automatica_sin_zona(): void
    {
        $web = $this->pedido();
        $web->pedidoSala->update(['ruta_id' => null]);
        $this->marcar($web)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(SalidaRutaEntrega::sole()->salida->ruta_id);
    }

    public function test_sin_permiso_devuelve_403_y_no_escribe_entrega(): void
    {
        $web = $this->pedido();
        $this->actingAs(User::factory()->create(['activo' => true])->givePermissionTo('rutas.ver'));
        $this->marcar($web)->assertForbidden();
        $this->assertNull($web->refresh()->entregado_en);
        $this->assertDatabaseCount('salida_ruta_entregas', 0);
    }

    public function test_quien_lo_entrego_es_obligatorio_como_en_las_salas(): void
    {
        $web = $this->pedido();
        $this->marcar($web, ['entregado_por_id' => ''])->assertSessionHasErrors('entregado_por_id');
        $this->assertNull($web->refresh()->entregado_en);
        $this->assertDatabaseCount('salida_ruta_entregas', 0);
    }

    public function test_ya_pagado_no_se_elige_en_salas_ni_en_clientes(): void
    {
        $this->assertArrayNotHasKey('ya_pagado', FormaCobroEntrega::opciones());

        // La forma habitual del cliente no puede ser «Ya pagado»: sería una entrega sin cobro.
        $regla = ['cobro_en_entrega' => ClienteRequest::create('/', 'POST')->rules()['cobro_en_entrega']];
        $this->assertTrue(Validator::make(['cobro_en_entrega' => 'ya_pagado'], $regla)->fails());
        $this->assertFalse(Validator::make(['cobro_en_entrega' => 'efectivo'], $regla)->fails());

        // Una sala no se marca «Ya pagado» aunque se mande a mano.
        $ccf = $this->ccfDeSala();
        $this->marcar($ccf, ['forma_cobro' => 'ya_pagado'])->assertSessionHasErrors('forma_cobro');
        $this->assertDatabaseCount('salida_ruta_entregas', 0);
        $this->marcar($ccf, ['forma_cobro' => 'efectivo'])->assertSessionHasNoErrors();
        $this->assertSame(FormaCobroEntrega::Efectivo, SalidaRutaEntrega::sole()->forma_cobro);
        $this->assertSame('50.00', SalidaRutaEntrega::sole()->monto_cobrado);
    }

    public function test_la_oficina_no_registra_un_pedido_web_que_ya_no_esta_por_entregar(): void
    {
        $web = $this->pedido();
        $salida = SalidaRuta::create(['ruta_id' => $this->zona->id, 'fecha_inicio' => '2026-11-16', 'estado' => EstadoSalidaRuta::EnCurso]);
        $salida->participantes()->create(['rutas_personal_id' => $this->persona->id, 'rol' => RolEnSalida::Responsable]);
        $entrega = app(EntregasCcf::class)->meterCcf($salida, $web->dte_id);
        $web->update(['entregado_en' => now()]);

        try {
            app(EntregasCcf::class)->registrar($entrega, ['resultado' => 'entregado', 'entregado_por_id' => $this->persona->id], $this->vendedor, OrigenRegistroEntrega::Oficina);
            $this->fail('Se registró un pedido web que ya no estaba por entregar.');
        } catch (ValidationException $e) {
            $this->assertSame(['entrega' => ['Ese pedido web no está por entregar.']], $e->errors());
        }
        $this->assertNull($entrega->refresh()->resultado);
    }

    public function test_mas_pedidos_web_no_suman_consultas(): void
    {
        $this->pedido();
        $this->get(route('rutas.por-entregar.index'))->assertOk();
        $uno = $this->consultas();
        $this->pedido(['regalo' => ['nombre' => 'Destinataria Ficticia', 'telefono' => '7000-5678']]);
        $this->pedido(zona: Ruta::create(['nombre' => 'Otra Zona Inventada'])->id);
        $this->assertSame($uno, $this->consultas());
    }

    private function consultas(): int
    {
        $total = 0;
        DB::listen(function () use (&$total) {
            $total++;
        });
        $this->get(route('rutas.por-entregar.index'))->assertOk();

        return $total;
    }

    /** @return array<string, array{0: bool, 1: list<string>}> */
    public static function avisosConRuta(): array
    {
        return [
            'en ruta todavía sin avisar' => [false, ['en_ruta', 'entregado']],
            'en ruta ya avisado' => [true, ['entregado']],
        ];
    }

    #[DataProvider('avisosConRuta')]
    public function test_comando_avisa_entregado_a_la_pagina_una_sola_vez(bool $enRutaAvisado, array $esperados): void
    {
        $web = $this->pedido($enRutaAvisado ? ['estado_por_informar' => null, 'estado_informado_en' => now()] : []);
        Ajustes::guardarComoSistema('tienda.url', 'https://tienda.example.test');
        Ajustes::guardarComoSistema('tienda.secreto', str_repeat('inventado', 8));
        $this->travel(1)->minutes();
        $this->marcar($web)->assertRedirect()->assertSessionHasNoErrors();
        Http::fake([
            '*/pedidos-pagados*' => Http::response(['pedidos' => []]),
            '*/estado' => Http::response([], 200),
            '*/limpieza' => Http::response([], 200),
        ]);
        $this->artisan('tienda:traer-pedidos', ['--aplicar' => true])->assertSuccessful();
        $this->artisan('tienda:traer-pedidos', ['--aplicar' => true])->assertSuccessful();
        $estados = Http::recorded()->filter(fn ($par) => str_ends_with($par[0]->url(), '/estado'))->map(fn ($par) => $par[0]['estado'])->values()->all();
        $this->assertSame($esperados, $estados);
        $this->assertNull($web->refresh()->estado_por_informar);
    }
}
