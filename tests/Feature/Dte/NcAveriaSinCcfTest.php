<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Exceptions\Dte\GeneracionException;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteSchemaRepository;
use App\Services\Dte\ValidacionPreJsonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * La EXCEPCIÓN de guardar una avería sin CCF relacionado.
 *
 * Hay un solo tipo de avería y el CCF es obligatorio: en la enorme mayoría de los casos
 * existe, y ese es el flujo normal. Guardar sin él es la salida rara —todavía no se ubica
 * cuál acreditar— y por eso hay que pedirla explícitamente y explicarla por escrito.
 *
 * Lo que NO se puede hacer es emitirla así. El esquema oficial del MH declara
 * `documentoRelacionado` como requerido con `minItems: 1` en la NC (05), tanto en v3 como
 * en v4: una nota sin CCF no tiene forma válida. Por eso el borrador queda bloqueado hasta
 * que se le vincule un CCF aceptado del mismo cliente.
 *
 * Estas pruebas fijan las dos mitades: que la excepción sea deliberada, y que NO emita.
 */
class NcAveriaSinCcfTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['03', '05'] as $t) {
            Correlativo::create([
                'tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
            ]);
        }

        return compact('estab', 'pv');
    }

    private function producto(float $precio = 10): Producto
    {
        return Producto::factory()->create([
            'precio_unitario' => $precio,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
    }

    private function sala(Cliente $cliente, string $nombre): ClienteSucursal
    {
        return ClienteSucursal::factory()->create([
            'cliente_id' => $cliente->id,
            'nombre' => $nombre,
            'activo' => true,
            'permite_nota_credito' => true,
        ]);
    }

    private function ccfAceptado(Cliente $cliente, ?ClienteSucursal $sala, array $emisor): Dte
    {
        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sala?->id,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
        $this->borradores->agregarLineaDesdeProducto($ccf, $this->producto(), cantidad: 10);
        app(DteGeneracionService::class)->generar($ccf);

        return $this->aceptarCcf($ccf);
    }

    /** Avería guardada por EXCEPCIÓN, sin CCF: cliente, sala y el motivo que la justifica. */
    private function averiaSinCcf(Cliente $cliente, ClienteSucursal $sala): Dte
    {
        return $this->borradores->crearNotaCredito(null, [
            'tipo' => TipoNotaCredito::Averia->value,
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sala->id,
            'motivo' => 'Producto dañado; todavía no se ubica el CCF a acreditar.',
        ], $this->usuario());
    }

    // ------------------------------------------------- el esquema del MH lo exige

    /**
     * El candado no es una preferencia nuestra: se lee del esquema oficial guardado. Si
     * algún día el MH dejara de exigirlo, esta prueba avisa antes que un rechazo.
     */
    public function test_el_esquema_oficial_exige_documento_relacionado_en_la_nc(): void
    {
        $schema = app(DteSchemaRepository::class)->paraTipo(TipoDte::NotaCredito);
        $this->assertNotNull($schema, 'Falta el esquema oficial de la NC.');

        $json = json_decode(file_get_contents($schema['ruta']), true);

        $this->assertContains(
            'documentoRelacionado',
            $json['required'] ?? [],
            'El esquema del MH ya no exige documentoRelacionado: revisar el candado de emisión.'
        );
        $this->assertSame(1, $json['properties']['documentoRelacionado']['minItems'] ?? null);
    }

    // ------------------------------------------------- registrar

    public function test_guarda_la_averia_aunque_todavia_no_haya_ccf(): void
    {
        $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = $this->sala($cliente, 'Sucursal Norte');

        $nc = $this->averiaSinCcf($cliente, $sala);

        $this->assertNull($nc->dte_relacionado_id, 'Sin CCF conocido no puede inventarse uno.');
        $this->assertSame(TipoNotaCredito::Averia, $nc->tipo_nota_credito);
        $this->assertSame($cliente->id, $nc->cliente_id);
        $this->assertSame($sala->id, $nc->cliente_sucursal_id);
        $this->assertSame($sala->id, $nc->sucursal_averia_id);
        $this->assertStringContainsString('todavía no se ubica el CCF', $nc->motivo);
        $this->assertSame(EstadoDte::Borrador, $nc->estado);
    }

    /** Se le pueden capturar productos: es un registro operativo real, no un placeholder. */
    public function test_la_averia_sin_ccf_acepta_productos_y_calcula_totales(): void
    {
        $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $nc = $this->averiaSinCcf($cliente, $this->sala($cliente, 'Sucursal Norte'));

        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(10), 3);
        $nc->refresh();

        $this->assertSame('30.00', $nc->total_gravado);
        $this->assertSame('3.90', $nc->iva);
        $this->assertSame('33.90', $nc->total_pagar);
    }

    /** Ni con la excepción activada: fuera de la avería, el CCF no es negociable. */
    public function test_solo_la_averia_puede_guardarse_sin_ccf(): void
    {
        $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = $this->sala($cliente, 'Sucursal Norte');

        $tipos = [
            TipoNotaCredito::DevolucionProducto->value,
            TipoNotaCredito::FaltanteEntrega->value,
            TipoNotaCredito::ProntoPago->value,
            TipoNotaCredito::Otro->value,
        ];

        foreach ($tipos as $tipo) {
            try {
                $this->borradores->crearNotaCredito(null, [
                    'tipo' => $tipo,
                    'cliente_id' => $cliente->id,
                    'cliente_sucursal_id' => $sala->id,
                ], $this->usuario());
                $this->fail("El tipo {$tipo} no debería poder crearse sin CCF relacionado.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('dte_relacionado_id', $e->errors());
            }
        }
    }

    public function test_la_averia_sin_ccf_exige_cliente_y_una_sala_de_ese_cliente(): void
    {
        $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $ajeno = Cliente::factory()->contribuyente()->create();
        $salaAjena = $this->sala($ajeno, 'Sala de Otro Cliente');

        // Sin cliente.
        try {
            $this->borradores->crearNotaCredito(null, [
                'tipo' => TipoNotaCredito::Averia->value,
            ], $this->usuario());
            $this->fail('Debía exigir cliente.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cliente_id', $e->errors());
        }

        // Sala de otro cliente: nunca.
        try {
            $this->borradores->crearNotaCredito(null, [
                'tipo' => TipoNotaCredito::Averia->value,
                'cliente_id' => $cliente->id,
                'cliente_sucursal_id' => $salaAjena->id,
            ], $this->usuario());
            $this->fail('Debía rechazar una sala de otro cliente.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cliente_sucursal_id', $e->errors());
        }
    }

    /**
     * El CCF es OBLIGATORIO en la avería normal. Sin pedir la excepción, que falte es lo
     * que parece —un dato que falta— y se rechaza como en cualquier otra modalidad.
     */
    public function test_la_averia_normal_exige_ccf(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = $this->sala($cliente, 'Sucursal Norte');

        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-nota-credito'), [
                'modalidad' => 'averia',
                'cliente_id' => $cliente->id,
                'cliente_sucursal_id' => $sala->id,
                'establecimiento_id' => $emisor['estab']->id,
                'punto_venta_id' => $emisor['pv']->id,
                'motivo' => 'Producto dañado.',
            ])->assertSessionHasErrors('dte_relacionado_id');

        $this->assertSame(0, Dte::where('tipo_dte', TipoDte::NotaCredito->value)->count());
    }

    /** La excepción llega DESMARCADA: el formulario abre pidiendo el CCF. */
    public function test_la_excepcion_esta_desactivada_por_defecto(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $this->ccfAceptado($cliente, $this->sala($cliente, 'Sucursal Norte'), $emisor);

        $html = $this->actingAs($this->usuario())
            ->get(route('facturacion.create-nota-credito'))->assertOk()->getContent();

        $this->assertStringContainsString('Guardar excepcionalmente sin CCF por ahora', $html);
        // Sin `checked` en el control ni `true` en el estado inicial del componente.
        $this->assertStringNotContainsString('name="sin_ccf_excepcional" value="1" checked', $html);
        $this->assertStringContainsString('sinCcfExcepcional: false', $html);
    }

    /**
     * El campo Observación del bloque de avería se retiró: el formulario tiene UN solo
     * lugar para el motivo/observación. Dos campos para lo mismo obligan a preguntarse
     * cuál de los dos vale.
     */
    public function test_no_hay_campo_observacion_duplicado(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $this->ccfAceptado($cliente, $this->sala($cliente, 'Sucursal Norte'), $emisor);

        $html = $this->actingAs($this->usuario())
            ->get(route('facturacion.create-nota-credito'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="observaciones"', $html);
        $this->assertSame(
            1,
            substr_count($html, 'Motivo / observaciones'),
            'Debe quedar un único campo de motivo/observaciones en el formulario.'
        );
    }

    /**
     * Sin CCF solo se guarda pidiendo la excepción Y explicándola. Las dos condiciones
     * juntas: la casilla sola dejaría pasar un borrador incompleto sin rastro de por qué.
     */
    public function test_sin_ccf_solo_guarda_con_la_excepcion_activada_y_motivo(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = $this->sala($cliente, 'Sucursal Norte');

        $base = [
            'modalidad' => 'averia',
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sala->id,
            'sucursal_averia_id' => $sala->id,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ];

        // Con motivo pero SIN activar la excepción: el CCF sigue siendo obligatorio.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-nota-credito'), $base + ['motivo' => 'Producto dañado.'])
            ->assertSessionHasErrors('dte_relacionado_id');
        $this->assertSame(0, Dte::where('tipo_dte', TipoDte::NotaCredito->value)->count());

        // Con la excepción activada pero SIN motivo: se rechaza por falta de explicación.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-nota-credito'), $base + ['sin_ccf_excepcional' => '1'])
            ->assertSessionHasErrors('motivo');
        $this->assertSame(0, Dte::where('tipo_dte', TipoDte::NotaCredito->value)->count());

        // Con las dos: se guarda.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.store-nota-credito'), $base + [
                'sin_ccf_excepcional' => '1',
                'motivo' => 'Todavía no se ubica el CCF a acreditar.',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $nc = Dte::where('tipo_dte', TipoDte::NotaCredito->value)->latest('id')->firstOrFail();
        $this->assertNull($nc->dte_relacionado_id);
        $this->assertSame($sala->id, $nc->sucursal_averia_id);
    }

    // ------------------------------------------------- NO se puede emitir

    public function test_una_averia_sin_ccf_no_se_puede_generar(): void
    {
        $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $nc = $this->averiaSinCcf($cliente, $this->sala($cliente, 'Sucursal Norte'));
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(10), 2);

        $this->expectException(GeneracionException::class);
        $this->expectExceptionMessage('falta relacionar un CCF para emitir');

        app(DteGeneracionService::class)->generar($nc->refresh());
    }

    public function test_la_validacion_pre_json_tambien_la_bloquea(): void
    {
        $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $nc = $this->averiaSinCcf($cliente, $this->sala($cliente, 'Sucursal Norte'));
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(10), 2);

        $problemas = app(ValidacionPreJsonService::class)->validar($nc->refresh());

        $this->assertContains('La nota de crédito debe estar vinculada a un CCF aceptado relacionado.', $problemas);
    }

    /** El correlativo NO se consume en el intento fallido: sigue disponible. */
    public function test_el_intento_fallido_no_consume_correlativo(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $nc = $this->averiaSinCcf($cliente, $this->sala($cliente, 'Sucursal Norte'));
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(10), 2);

        $antes = Correlativo::where('tipo_dte', '05')
            ->where('punto_venta_id', $emisor['pv']->id)->value('ultimo_numero');

        try {
            app(DteGeneracionService::class)->generar($nc->refresh());
        } catch (GeneracionException) {
            // esperado
        }

        $this->assertSame(
            $antes,
            Correlativo::where('tipo_dte', '05')->where('punto_venta_id', $emisor['pv']->id)->value('ultimo_numero'),
            'Un intento bloqueado no puede gastar un número fiscal.'
        );
        $this->assertNull($nc->refresh()->numero_interno);
    }

    // ------------------------------------------------- vincular un CCF después

    public function test_vincular_un_ccf_habilita_la_emision(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = $this->sala($cliente, 'Sucursal Norte');
        $nc = $this->averiaSinCcf($cliente, $sala);
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(10), 2);

        $ccf = $this->ccfAceptado($cliente, $sala, $emisor);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.nota-credito.vincular-ccf', $nc), ['dte_relacionado_id' => $ccf->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $nc->refresh();
        $this->assertSame($ccf->id, $nc->dte_relacionado_id);
        $this->assertSame($ccf->numero_orden_compra, $nc->numero_orden_compra);

        // Y ahora sí genera.
        app(DteGeneracionService::class)->generar($nc);
        $this->assertNotNull($nc->refresh()->numero_interno);
    }

    /**
     * La sala de la avería es un HECHO y no la cambia el CCF que se vincule después. La
     * sala receptora del documento sí pasa a ser la del CCF.
     */
    public function test_vincular_un_ccf_de_otra_sala_exige_motivo_y_no_mueve_la_sala_de_la_averia(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $salaAveria = $this->sala($cliente, 'Sucursal Norte');
        $salaCcf = $this->sala($cliente, 'Sucursal Sur');

        $nc = $this->averiaSinCcf($cliente, $salaAveria);
        $ccf = $this->ccfAceptado($cliente, $salaCcf, $emisor);

        // Sin motivo: rechazado.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.nota-credito.vincular-ccf', $nc), ['dte_relacionado_id' => $ccf->id])
            ->assertSessionHasErrors('motivo');
        $this->assertNull($nc->refresh()->dte_relacionado_id);

        // Con motivo: se vincula.
        $this->actingAs($this->usuario())
            ->post(route('facturacion.nota-credito.vincular-ccf', $nc), [
                'dte_relacionado_id' => $ccf->id,
                'motivo' => 'La sala Norte no tuvo CCF en el período; se acredita contra el de Sur.',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $nc->refresh();
        $this->assertSame($ccf->id, $nc->dte_relacionado_id);
        $this->assertSame($salaCcf->id, $nc->cliente_sucursal_id, 'La sala receptora pasa a ser la del CCF.');
        $this->assertSame($salaAveria->id, $nc->sucursal_averia_id, 'A qué sala corresponde la avería no cambia.');
    }

    public function test_no_se_puede_vincular_un_ccf_de_otro_cliente(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $nc = $this->averiaSinCcf($cliente, $this->sala($cliente, 'Sucursal Norte'));

        $ajeno = Cliente::factory()->contribuyente()->create();
        $ccfAjeno = $this->ccfAceptado($ajeno, $this->sala($ajeno, 'Sala Ajena'), $emisor);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.nota-credito.vincular-ccf', $nc), [
                'dte_relacionado_id' => $ccfAjeno->id,
                'motivo' => 'Intento de cruzar de cliente.',
            ])->assertSessionHasErrors('dte_relacionado_id');

        $this->assertNull($nc->refresh()->dte_relacionado_id);
    }

    public function test_no_se_puede_vincular_un_ccf_no_aceptado_invalidado_ni_archivado(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = $this->sala($cliente, 'Sucursal Norte');

        // Solo generado, sin aceptación.
        $soloGenerado = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sala->id,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
        $this->borradores->agregarLineaDesdeProducto($soloGenerado, $this->producto(), cantidad: 5);
        app(DteGeneracionService::class)->generar($soloGenerado);

        $invalidado = $this->ccfAceptado($cliente, $sala, $emisor);
        $invalidado->update(['sello_invalidacion' => '2026SELLOINVALIDACION']);

        $archivado = $this->ccfAceptado($cliente, $sala, $emisor);
        $archivado->update(['archivado' => true]);

        foreach ([$soloGenerado, $invalidado, $archivado] as $ccf) {
            $nc = $this->averiaSinCcf($cliente, $sala);

            $this->actingAs($this->usuario())
                ->post(route('facturacion.nota-credito.vincular-ccf', $nc), ['dte_relacionado_id' => $ccf->id])
                ->assertSessionHasErrors('dte_relacionado_id');

            $this->assertNull($nc->refresh()->dte_relacionado_id);
        }
    }

    /** Vincular no sirve para MOVER una nota de un CCF a otro. */
    public function test_no_se_puede_revincular_una_nota_que_ya_tiene_ccf(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = $this->sala($cliente, 'Sucursal Norte');
        $primero = $this->ccfAceptado($cliente, $sala, $emisor);
        $segundo = $this->ccfAceptado($cliente, $sala, $emisor);

        $nc = $this->borradores->crearNotaCredito($primero, [
            'tipo' => TipoNotaCredito::Averia->value,
        ], $this->usuario());

        $this->actingAs($this->usuario())
            ->post(route('facturacion.nota-credito.vincular-ccf', $nc), ['dte_relacionado_id' => $segundo->id])
            ->assertSessionHasErrors('dte_relacionado_id');

        $this->assertSame($primero->id, $nc->refresh()->dte_relacionado_id);
    }

    /** Un documento ya emitido es inmutable, también para esto. */
    public function test_no_se_puede_vincular_un_ccf_a_una_nota_ya_generada(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = $this->sala($cliente, 'Sucursal Norte');
        $ccf = $this->ccfAceptado($cliente, $sala, $emisor);

        $nc = $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::Averia->value,
        ], $this->usuario());
        $this->borradores->agregarProductoNotaCreditoAveria($nc, $this->producto(10), 1);
        app(DteGeneracionService::class)->generar($nc);

        $otro = $this->ccfAceptado($cliente, $sala, $emisor);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.nota-credito.vincular-ccf', $nc->refresh()), ['dte_relacionado_id' => $otro->id])
            ->assertForbidden();
    }

    /**
     * El buscador que ofrece los CCF a vincular respeta el alcance que pide la pantalla:
     * por defecto la sala de la nota, y todas las del cliente cuando se marca la casilla.
     * Nunca sale del cliente.
     */
    public function test_el_buscador_acota_a_la_sala_y_se_abre_a_las_demas_a_pedido(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $salaNota = $this->sala($cliente, 'Sucursal Norte');
        $otraSala = $this->sala($cliente, 'Sucursal Sur');

        $propio = $this->ccfAceptado($cliente, $salaNota, $emisor);
        $ajenoDeSala = $this->ccfAceptado($cliente, $otraSala, $emisor);

        $otroCliente = Cliente::factory()->contribuyente()->create();
        $deOtroCliente = $this->ccfAceptado($otroCliente, $this->sala($otroCliente, 'Ajena'), $emisor);

        $usuario = $this->usuario();
        $ids = fn (array $params) => array_column(
            $this->actingAs($usuario)
                ->getJson(route('facturacion.nota-credito.buscar-ccf', $params))
                ->assertOk()->json('resultados'),
            'id'
        );

        // Por defecto (con sala): solo esa sala.
        $soloSala = $ids(['cliente_id' => $cliente->id, 'cliente_sucursal_id' => $salaNota->id]);
        $this->assertSame([$propio->id], $soloSala);

        // Marcando «otras salas»: todas las del cliente, nunca las de otro.
        $todasLasSalas = $ids(['cliente_id' => $cliente->id]);
        $this->assertContains($propio->id, $todasLasSalas);
        $this->assertContains($ajenoDeSala->id, $todasLasSalas);
        $this->assertNotContains($deOtroCliente->id, $todasLasSalas, 'Una NC nunca puede cruzar de cliente.');
    }

    public function test_vincular_exige_permiso_de_gestion(): void
    {
        $emisor = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create();
        $sala = $this->sala($cliente, 'Sucursal Norte');
        $nc = $this->averiaSinCcf($cliente, $sala);
        $ccf = $this->ccfAceptado($cliente, $sala, $emisor);

        $this->actingAs($this->usuario('jefatura'))
            ->post(route('facturacion.nota-credito.vincular-ccf', $nc), ['dte_relacionado_id' => $ccf->id])
            ->assertForbidden();

        $this->assertNull($nc->refresh()->dte_relacionado_id);
    }
}
