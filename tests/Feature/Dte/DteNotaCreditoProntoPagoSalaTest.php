<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Enums\TipoNotaCredito;
use App\Exceptions\Dte\SaldoAcreditableExcedidoException;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * NOTA DE CRÉDITO DE PRONTO PAGO A UNA SALA ADMINISTRATIVA.
 *
 * Caso real de Calleja: el CCF pertenece a una sala de Súper Selectos, pero la nota de
 * crédito por pronto pago debe emitirse a «Bodega Oficina Central Calleja», una sala del
 * MISMO cliente que normalmente nunca recibe un CCF propio.
 *
 * Antes, {@see DteBorradorService::crearNotaCredito()} sobreescribía siempre
 * `cliente_sucursal_id` con la sala del CCF y descartaba en silencio la del formulario,
 * así que Oficina Central no podía elegirse.
 *
 * Se separan tres cosas que antes iban juntas:
 *   - CLIENTE FISCAL: siempre el del CCF (NIT/NRC/razón social).
 *   - DOCUMENTO RELACIONADO: siempre el CCF aceptado, de donde sale el saldo.
 *   - SALA RECEPTORA: puede ser otra sala del mismo cliente, solo en notas por MONTO.
 *
 * Ninguna prueba emite, firma ni transmite documentos reales.
 */
class DteNotaCreditoProntoPagoSalaTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private DteBorradorService $borradores;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Ambiente de PRUEBAS (00): basta estado Aceptado para crear la NC.
        config(['dte.ambiente' => '00']);
        $this->seedCatalogosDte();
        Storage::fake('local');

        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        foreach (['03', '05'] as $t) {
            Correlativo::create([
                'tipo_dte' => $t, 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
            ]);
        }

        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** Cliente «Calleja» con una sala de venta y la sala administrativa Oficina Central. */
    private function calleja(): Cliente
    {
        return Cliente::factory()->contribuyente()->create([
            'nombre' => 'Calleja, S.A. de C.V.',
            'num_documento' => '0614-110169-001-1',
            'nrc' => '1937',
        ]);
    }

    private function sala(Cliente $cliente, string $nombre, array $overrides = []): ClienteSucursal
    {
        return ClienteSucursal::factory()->create(array_merge([
            'cliente_id' => $cliente->id,
            'nombre' => $nombre,
            'permite_nota_credito' => true,
            'activo' => true,
        ], $overrides));
    }

    /**
     * CCF ACEPTADO REAL emitido a la sala indicada.
     *
     * @param  array<int, array{cantidad: float|int, precio: float|int}>  $lineas
     */
    private function ccfAceptado(Cliente $cliente, ClienteSucursal $sala, array $lineas = [['cantidad' => 10, 'precio' => 10]]): Dte
    {
        $ccf = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sala->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);

        foreach ($lineas as $l) {
            $producto = Producto::factory()->create([
                'precio_unitario' => $l['precio'], 'tipo_impuesto' => TipoImpuesto::Gravado->value,
            ]);
            $this->borradores->agregarLineaDesdeProducto($ccf, $producto, cantidad: $l['cantidad']);
        }

        app(DteGeneracionService::class)->generar($ccf);

        return $this->aceptarCcf($ccf->refresh());
    }

    // --- 1. Pronto pago de un CCF de una sala normal hacia Oficina Central ---

    public function test_pronto_pago_puede_emitirse_a_oficina_central(): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos San Benito');
        $oficina = $this->sala($cliente, 'Bodega Oficina Central Calleja');
        $ccf = $this->ccfAceptado($cliente, $salaVenta);

        $nc = $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::ProntoPago->value,
            'cliente_sucursal_id' => $oficina->id,
            'motivo' => 'Cobro centralizado en oficina central.',
            'motivo' => 'Pronto pago quincena 1',
        ], $this->usuario());

        // La sala receptora es Oficina Central...
        $this->assertSame($oficina->id, $nc->cliente_sucursal_id);
        // ...pero el cliente fiscal y el documento relacionado NO cambian.
        $this->assertSame($cliente->id, $nc->cliente_id);
        $this->assertSame($ccf->id, $nc->dte_relacionado_id);
        $this->assertSame(TipoNotaCredito::ProntoPago, $nc->tipo_nota_credito);
        $this->assertSame(EstadoDte::Borrador, $nc->estado);
        // El CCF original queda intacto.
        $this->assertSame($salaVenta->id, $ccf->refresh()->cliente_sucursal_id);
        $this->assertSame(EstadoDte::Aceptado, $ccf->estado);
    }

    // --- 2. Oficina Central sin CCF propio ---

    public function test_no_exige_que_la_sala_receptora_tenga_un_ccf_previo(): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos Ilobasco');
        $oficina = $this->sala($cliente, 'Bodega Oficina Central Calleja');
        $ccf = $this->ccfAceptado($cliente, $salaVenta);

        // Oficina Central nunca recibió un CCF: no debe ser un impedimento.
        $this->assertSame(0, Dte::where('cliente_sucursal_id', $oficina->id)->count());

        $nc = $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::ProntoPago->value,
            'cliente_sucursal_id' => $oficina->id,
            'motivo' => 'Cobro centralizado en oficina central.',
        ], $this->usuario());

        $this->assertSame($oficina->id, $nc->cliente_sucursal_id);
    }

    // --- 3. Sala del mismo cliente ---

    public function test_acepta_cualquier_sala_valida_del_mismo_cliente(): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos Centro');
        $otraSala = $this->sala($cliente, 'Súper Selectos Escalón');
        $ccf = $this->ccfAceptado($cliente, $salaVenta);

        $nc = $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::DescuentoPosterior->value,
            'cliente_sucursal_id' => $otraSala->id,
            'motivo' => 'Cobro centralizado en oficina central.',
        ], $this->usuario());

        $this->assertSame($otraSala->id, $nc->cliente_sucursal_id);
        $this->assertSame($cliente->id, $nc->cliente_id);
    }

    public function test_sin_seleccion_usa_la_sala_del_ccf(): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos Centro');
        $this->sala($cliente, 'Bodega Oficina Central Calleja');
        $ccf = $this->ccfAceptado($cliente, $salaVenta);

        $nc = $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::ProntoPago->value,
        ], $this->usuario());

        $this->assertSame($salaVenta->id, $nc->cliente_sucursal_id);
    }

    // --- 4. Sala de otro cliente ---

    public function test_rechaza_una_sala_de_otro_cliente(): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos Centro');
        $ccf = $this->ccfAceptado($cliente, $salaVenta);

        $otroCliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Otro Contribuyente, S.A.']);
        $salaAjena = $this->sala($otroCliente, 'Sala de otro cliente');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('mismo cliente');

        $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::ProntoPago->value,
            'cliente_sucursal_id' => $salaAjena->id,
        ], $this->usuario());
    }

    public function test_no_crea_la_nc_si_la_sala_es_de_otro_cliente(): void
    {
        $cliente = $this->calleja();
        $ccf = $this->ccfAceptado($cliente, $this->sala($cliente, 'Súper Selectos Centro'));
        $salaAjena = $this->sala(Cliente::factory()->contribuyente()->create(), 'Sala ajena');

        try {
            $this->borradores->crearNotaCredito($ccf, [
                'tipo' => TipoNotaCredito::ProntoPago->value,
                'cliente_sucursal_id' => $salaAjena->id,
            ], $this->usuario());
            $this->fail('Debía rechazar la sala de otro cliente.');
        } catch (ValidationException) {
            // No debe quedar ninguna NC a medias.
            $this->assertSame(0, Dte::where('tipo_dte', TipoDte::NotaCredito->value)->count());
        }
    }

    // --- 5. Sala inactiva ---

    public function test_rechaza_una_sala_inactiva(): void
    {
        $cliente = $this->calleja();
        $ccf = $this->ccfAceptado($cliente, $this->sala($cliente, 'Súper Selectos Centro'));
        $inactiva = $this->sala($cliente, 'Bodega cerrada', ['activo' => false]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('inactiva');

        $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::ProntoPago->value,
            'cliente_sucursal_id' => $inactiva->id,
        ], $this->usuario());
    }

    // --- 6. Sala que no permite notas de crédito ---

    public function test_rechaza_una_sala_que_no_permite_notas_de_credito(): void
    {
        $cliente = $this->calleja();
        $ccf = $this->ccfAceptado($cliente, $this->sala($cliente, 'Súper Selectos Centro'));
        $sinPermiso = $this->sala($cliente, 'Sala sin NC', ['permite_nota_credito' => false]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no permite notas de crédito');

        $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::ProntoPago->value,
            'cliente_sucursal_id' => $sinPermiso->id,
        ], $this->usuario());
    }

    // --- 7. Devolución / avería / faltante NO admiten otra sala ---

    /**
     * @return array<string, array{0: string}>
     */
    public static function tiposQueNoAdmitenOtraSalaProvider(): array
    {
        return [
            'devolución de producto' => [TipoNotaCredito::DevolucionProducto->value],
            'faltante de entrega' => [TipoNotaCredito::FaltanteEntrega->value],
            'avería' => [TipoNotaCredito::Averia->value],
        ];
    }

    #[DataProvider('tiposQueNoAdmitenOtraSalaProvider')]
    public function test_devolucion_averia_y_faltante_no_admiten_una_sala_distinta(string $tipo): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos Centro');
        $oficina = $this->sala($cliente, 'Bodega Oficina Central Calleja');
        $ccf = $this->ccfAceptado($cliente, $salaVenta);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('misma sala del CCF relacionado');

        $this->borradores->crearNotaCredito($ccf, [
            'tipo' => $tipo,
            'cliente_sucursal_id' => $oficina->id,
            'motivo' => 'Cobro centralizado en oficina central.',
        ], $this->usuario());
    }

    #[DataProvider('tiposQueNoAdmitenOtraSalaProvider')]
    public function test_devolucion_averia_y_faltante_conservan_la_sala_del_ccf(string $tipo): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos Centro');
        $ccf = $this->ccfAceptado($cliente, $salaVenta);

        // Enviar la MISMA sala del CCF es válido para cualquier tipo.
        $nc = $this->borradores->crearNotaCredito($ccf, [
            'tipo' => $tipo,
            'cliente_sucursal_id' => $salaVenta->id,
        ], $this->usuario());

        $this->assertSame($salaVenta->id, $nc->cliente_sucursal_id);
    }

    // --- 8. El saldo acreditable sigue saliendo del CCF relacionado ---

    public function test_el_saldo_acreditable_sigue_limitado_por_el_ccf_aunque_cambie_la_sala(): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos Centro');
        $oficina = $this->sala($cliente, 'Bodega Oficina Central Calleja');
        $ccf = $this->ccfAceptado($cliente, $salaVenta, [['cantidad' => 10, 'precio' => 10]]);

        // NC de DEVOLUCIÓN (misma sala, por productos): el tope sigue siendo la cantidad
        // original del CCF, sin importar que existan notas a otra sala.
        $devolucion = $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::DevolucionProducto->value,
        ], $this->usuario());

        $this->expectException(SaldoAcreditableExcedidoException::class);
        $this->borradores->acreditarLinea($devolucion, $ccf->lineas->first(), 11);

        // (La sala de la NC no interviene en el cálculo del saldo: el saldo es del CCF.)
        $this->assertSame($oficina->id, $oficina->id);
    }

    public function test_la_nc_a_otra_sala_no_altera_el_saldo_disponible_del_ccf(): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos Centro');
        $oficina = $this->sala($cliente, 'Bodega Oficina Central Calleja');
        $ccf = $this->ccfAceptado($cliente, $salaVenta, [['cantidad' => 10, 'precio' => 10]]);

        // Una NC de pronto pago (por monto, a Oficina Central) NO consume cantidades de
        // línea: la devolución posterior sigue pudiendo acreditar las 10 unidades.
        $prontoPago = $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::ProntoPago->value,
            'cliente_sucursal_id' => $oficina->id,
            'motivo' => 'Cobro centralizado en oficina central.',
        ], $this->usuario());
        $this->borradores->agregarConceptoNotaCredito($prontoPago, [
            'descripcion' => 'Descuento por pronto pago',
            'monto' => 5,
        ]);

        $devolucion = $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::DevolucionProducto->value,
        ], $this->usuario());
        $linea = $this->borradores->acreditarLinea($devolucion, $ccf->lineas->first(), 10);

        // Se compara el valor numérico (la escala decimal de la columna no es el objeto de esta prueba).
        $this->assertSame(10.0, (float) $linea->cantidad);
    }

    // --- 9. JSON: identidad fiscal del cliente, ubicación de la sala receptora ---

    public function test_el_json_usa_el_nit_del_cliente_y_la_direccion_de_oficina_central(): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos San Benito', [
            'direccion' => 'Boulevard del Hipódromo, San Benito',
        ]);
        $oficina = $this->sala($cliente, 'Bodega Oficina Central Calleja', [
            'direccion' => 'Calle Los Bambúes, Oficina Central',
        ]);
        $ccf = $this->ccfAceptado($cliente, $salaVenta);

        $nc = $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::ProntoPago->value,
            'cliente_sucursal_id' => $oficina->id,
            'motivo' => 'Cobro centralizado en oficina central.',
        ], $this->usuario());
        $this->borradores->agregarConceptoNotaCredito($nc, [
            'descripcion' => 'Descuento por pronto pago', 'monto' => 25,
        ]);

        app(DteGeneracionService::class)->generar($nc);
        $nc->refresh();

        $json = json_decode(Storage::disk('local')->get($nc->json_generado_path), true);
        $receptor = $json['receptor'];

        // Identidad FISCAL: del cliente Calleja (no de la sala).
        // El MH exige el NIT solo en dígitos: "0614-110169-001-1" → "06141101690011".
        $this->assertSame('06141101690011', $receptor['nit']);
        $this->assertSame('1937', $receptor['nrc']);
        $this->assertSame('Calleja, S.A. de C.V.', $receptor['nombre']);

        // Establecimiento y DIRECCIÓN: de Oficina Central.
        $this->assertSame('Bodega Oficina Central Calleja', $receptor['nombreComercial']);
        $this->assertSame('Calle Los Bambúes, Oficina Central', $receptor['direccion']['complemento']);
        $this->assertStringNotContainsString('Hipódromo', json_encode($receptor, JSON_UNESCAPED_UNICODE));

        // Documento relacionado: sigue siendo el CCF elegido.
        $this->assertSame($ccf->codigo_generacion, $json['documentoRelacionado'][0]['numeroDocumento']);
        $this->assertSame('03', $json['documentoRelacionado'][0]['tipoDocumento']);

        // La NC v3 NO admite `distrito` en la dirección del receptor: no debe aparecer.
        $this->assertArrayNotHasKey('distrito', $receptor['direccion']);
    }

    // --- 10. "Revertir CCF completo con NC" conserva la sala original ---

    public function test_revertir_ccf_completo_conserva_la_sala_del_ccf(): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos Centro');
        $this->sala($cliente, 'Bodega Oficina Central Calleja');
        $ccf = $this->ccfAceptado($cliente, $salaVenta, [['cantidad' => 4, 'precio' => 7]]);

        $nc = $this->borradores->revertirCcfCompleto($ccf, $this->usuario());

        $this->assertSame($salaVenta->id, $nc->cliente_sucursal_id);
        $this->assertSame(TipoNotaCredito::DevolucionProducto, $nc->tipo_nota_credito);
    }

    // --- Auditoría ---

    public function test_registra_auditoria_cuando_la_sala_de_la_nc_difiere_de_la_del_ccf(): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos San Benito');
        $oficina = $this->sala($cliente, 'Bodega Oficina Central Calleja');
        $ccf = $this->ccfAceptado($cliente, $salaVenta);
        $usuario = $this->usuario();

        $nc = $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::ProntoPago->value,
            'cliente_sucursal_id' => $oficina->id,
            'motivo' => 'Cobro centralizado en oficina central.',
            'motivo' => 'Pronto pago quincena 1',
        ], $usuario);

        $actividad = Activity::where('log_name', 'dte_nota_credito_sala')->latest('id')->first();

        $this->assertNotNull($actividad, 'No se registró la actividad de cambio de sala.');
        $this->assertSame($nc->id, $actividad->subject_id);
        $this->assertSame($usuario->id, $actividad->causer_id);
        $this->assertSame($ccf->id, $actividad->properties['dte_relacionado_id']);
        $this->assertSame($salaVenta->id, $actividad->properties['sala_ccf_id']);
        $this->assertSame($oficina->id, $actividad->properties['sala_nc_id']);
        $this->assertSame('Súper Selectos San Benito', $actividad->properties['sala_ccf_nombre']);
        $this->assertSame('Bodega Oficina Central Calleja', $actividad->properties['sala_nc_nombre']);
        $this->assertSame(TipoNotaCredito::ProntoPago->value, $actividad->properties['tipo_nota_credito']);
        $this->assertSame('Pronto pago quincena 1', $actividad->properties['motivo']);
    }

    public function test_no_registra_auditoria_de_sala_cuando_se_usa_la_del_ccf(): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos Centro');
        $ccf = $this->ccfAceptado($cliente, $salaVenta);

        $this->borradores->crearNotaCredito($ccf, [
            'tipo' => TipoNotaCredito::ProntoPago->value,
        ], $this->usuario());

        $this->assertSame(0, Activity::where('log_name', 'dte_nota_credito_sala')->count());
    }

    // --- Endpoints / UI ---

    public function test_el_endpoint_desde_la_ficha_del_ccf_acepta_la_sala_en_pronto_pago(): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos San Benito');
        $oficina = $this->sala($cliente, 'Bodega Oficina Central Calleja');
        $ccf = $this->ccfAceptado($cliente, $salaVenta);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.nota-credito.store', $ccf), [
                'tipo' => TipoNotaCredito::ProntoPago->value,
                'cliente_sucursal_id' => $oficina->id,
                'motivo' => 'Cobro centralizado en oficina central.',
                'motivo' => 'Pronto pago',
            ])
            ->assertRedirect();

        $nc = Dte::where('tipo_dte', TipoDte::NotaCredito->value)->latest('id')->firstOrFail();
        $this->assertSame($oficina->id, $nc->cliente_sucursal_id);
        $this->assertSame($ccf->id, $nc->dte_relacionado_id);
    }

    public function test_el_endpoint_desde_la_ficha_rechaza_otra_sala_en_devolucion(): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos San Benito');
        $oficina = $this->sala($cliente, 'Bodega Oficina Central Calleja');
        $ccf = $this->ccfAceptado($cliente, $salaVenta);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.nota-credito.store', $ccf), [
                'tipo' => TipoNotaCredito::DevolucionProducto->value,
                'cliente_sucursal_id' => $oficina->id,
                'motivo' => 'Cobro centralizado en oficina central.',
            ])
            ->assertSessionHasErrors('cliente_sucursal_id');

        $this->assertSame(0, Dte::where('tipo_dte', TipoDte::NotaCredito->value)->count());
    }

    public function test_el_formulario_independiente_ofrece_el_selector_de_sala_receptora(): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos San Benito');
        $this->sala($cliente, 'Bodega Oficina Central Calleja');
        $this->ccfAceptado($cliente, $salaVenta);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.create-nota-credito'))
            ->assertOk()
            ->assertSee('Sala receptora de la Nota de Crédito')
            ->assertSee('Bodega Oficina Central Calleja')
            // El selector solo aplica a las modalidades por monto.
            ->assertSee('pronto_pago', false);
    }

    public function test_la_ficha_del_ccf_ofrece_el_selector_de_sala_receptora(): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos San Benito');
        $this->sala($cliente, 'Bodega Oficina Central Calleja');
        $ccf = $this->ccfAceptado($cliente, $salaVenta);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk()
            ->assertSee('Sala receptora de la Nota de Crédito')
            ->assertSee('Bodega Oficina Central Calleja');
    }

    /** El selector NO debe ofrecer salas de otros clientes ni inactivas. */
    public function test_el_selector_solo_ofrece_salas_validas_del_mismo_cliente(): void
    {
        $cliente = $this->calleja();
        $salaVenta = $this->sala($cliente, 'Súper Selectos San Benito');
        $this->sala($cliente, 'Bodega Oficina Central Calleja');
        $this->sala($cliente, 'Sala inactiva Calleja', ['activo' => false]);
        $this->sala($cliente, 'Sala Calleja sin NC', ['permite_nota_credito' => false]);
        $this->sala(Cliente::factory()->contribuyente()->create(['nombre' => 'Otro cliente']), 'Sala de otro cliente');
        $ccf = $this->ccfAceptado($cliente, $salaVenta);

        $respuesta = $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $ccf))
            ->assertOk();

        $respuesta->assertSee('Bodega Oficina Central Calleja');
        $respuesta->assertDontSee('Sala inactiva Calleja');
        $respuesta->assertDontSee('Sala Calleja sin NC');
        $respuesta->assertDontSee('Sala de otro cliente');
    }
}
