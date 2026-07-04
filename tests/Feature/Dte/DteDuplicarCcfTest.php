<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Producto;
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
 * Duplicar CCF: crea un borrador NUEVO con los mismos datos base y copia snapshot de
 * las líneas, sin tocar el original y sin copiar nada fiscal (numeración, correlativo,
 * JSON/firma, sello/respuesta MH, correos, anulaciones).
 */
class DteDuplicarCcfTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private $estab;

    private $pv;

    private Cliente $cliente;

    private ClienteSucursal $sala;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');
        $this->seedCatalogosDte();

        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        Correlativo::create(['tipo_dte' => '03', 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id, 'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true]);

        $this->cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja Prueba SA', 'descuento_global_default' => 5]);
        $this->sala = ClienteSucursal::factory()->create(['cliente_id' => $this->cliente->id, 'nombre' => 'Sala Origen']);
    }

    private function usuario(string $rol = 'facturacion'): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** CCF ACEPTADO bien poblado: sala + OC + 2 líneas, generado de verdad y con sello. */
    private function ccfAceptado(): Dte
    {
        $b = app(DteBorradorService::class);
        $dte = $b->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $this->cliente->id,
            'cliente_sucursal_id' => $this->sala->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'condicion_operacion' => 2,
            'numero_orden_compra' => 'OC-DUP-1',
            'observaciones' => 'Entrega en bodega',
        ]);
        $p1 = Producto::factory()->create(['nombre' => 'CANILLITAS', 'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $p2 = Producto::factory()->create(['nombre' => 'MAZAPAN', 'precio_unitario' => 5, 'tipo_impuesto' => TipoImpuesto::Gravado->value]);
        $b->agregarLineaDesdeProducto($dte, $p1, cantidad: 4);
        $b->agregarLineaDesdeProducto($dte, $p2, cantidad: 2);

        app(DteGeneracionService::class)->generar($dte);
        $dte->refresh();

        // Aceptado real (fixture): sello + respuesta + fecha del MH.
        $dte->sello_recepcion = '2026SELLODUP1234567890123456789012345678';
        $dte->respuesta_mh = ['estado' => 'PROCESADO'];
        $dte->fecha_procesamiento_mh = now();
        $dte->estado = EstadoDte::Aceptado;
        Dte::withoutEvents(fn () => $dte->save());

        // Un envío de correo en el historial (no debe copiarse al duplicado).
        $dte->envios()->create(['destinatario' => 'a@a.com', 'destinatarios' => ['a@a.com'], 'estado' => 'enviado']);

        return $dte->refresh();
    }

    public function test_duplicar_ccf_aceptado_crea_borrador_nuevo_sin_tocar_el_original(): void
    {
        $original = $this->ccfAceptado();
        $estadoAntes = $original->estado;
        $lineasAntes = $original->lineas->count();

        $nuevo = app(DteBorradorService::class)->duplicarCcf($original, $this->usuario());

        $this->assertNotSame($original->id, $nuevo->id);
        $this->assertSame(EstadoDte::Borrador, $nuevo->estado);
        $this->assertTrue($nuevo->esEditable());
        // Datos base copiados.
        $this->assertSame($original->cliente_id, $nuevo->cliente_id);
        $this->assertSame($original->cliente_sucursal_id, $nuevo->cliente_sucursal_id);
        $this->assertSame($original->establecimiento_id, $nuevo->establecimiento_id);
        $this->assertSame($original->punto_venta_id, $nuevo->punto_venta_id);
        $this->assertSame($original->condicion_operacion, $nuevo->condicion_operacion);
        $this->assertSame('OC-DUP-1', $nuevo->numero_orden_compra);
        $this->assertSame('Entrega en bodega', $nuevo->observaciones);
        $this->assertSame($original->descuento_porcentaje_aplicado, $nuevo->descuento_porcentaje_aplicado);
        // El original quedó intacto.
        $original->refresh();
        $this->assertSame($estadoAntes, $original->estado);
        $this->assertSame($lineasAntes, $original->lineas->count());
        $this->assertNotNull($original->sello_recepcion);
    }

    public function test_no_copia_datos_fiscales_firma_transmision_correlativo_ni_correos(): void
    {
        $original = $this->ccfAceptado();
        $original->json_firmado_path = 'dte/firmados/dte-03-'.$original->id.'.jws';
        Dte::withoutEvents(fn () => $original->save());

        $nuevo = app(DteBorradorService::class)->duplicarCcf($original->refresh(), $this->usuario());

        $this->assertNull($nuevo->numero_interno);
        $this->assertNull($nuevo->numero_control);
        $this->assertNull($nuevo->codigo_generacion);
        $this->assertNull($nuevo->correlativo_id);
        $this->assertNull($nuevo->json_generado_path);
        $this->assertNull($nuevo->json_firmado_path);
        $this->assertNull($nuevo->sello_recepcion);
        $this->assertNull($nuevo->respuesta_mh);
        $this->assertNull($nuevo->fecha_procesamiento_mh);
        $this->assertNull($nuevo->motivo_anulacion);
        $this->assertNull($nuevo->sello_invalidacion);
        $this->assertCount(0, $nuevo->envios);
        // La fecha de emisión es la de HOY (refacturación), no la del original.
        $this->assertSame(now()->toDateString(), $nuevo->fecha_emision->toDateString());
    }

    public function test_copia_lineas_y_totales_correctamente(): void
    {
        $original = $this->ccfAceptado();

        $nuevo = app(DteBorradorService::class)->duplicarCcf($original, $this->usuario());

        $this->assertSame(
            $original->lineas->map(fn ($l) => [$l->descripcion, (string) $l->cantidad, (string) $l->precio_unitario])->all(),
            $nuevo->lineas->map(fn ($l) => [$l->descripcion, (string) $l->cantidad, (string) $l->precio_unitario])->all(),
        );
        $this->assertSame(range(1, $nuevo->lineas->count()), $nuevo->lineas->pluck('numero_linea')->map(fn ($n) => (int) $n)->all());
        // Totales idénticos (mismo % de descuento, misma retención automática).
        $this->assertSame((string) $original->total_gravado, (string) $nuevo->total_gravado);
        $this->assertSame((string) $original->total_descuento, (string) $nuevo->total_descuento);
        $this->assertSame((string) $original->iva, (string) $nuevo->iva);
        $this->assertSame((string) $original->total_pagar, (string) $nuevo->total_pagar);
    }

    public function test_conserva_el_precio_del_original_aunque_el_producto_cambie(): void
    {
        $original = $this->ccfAceptado();
        // El precio vigente del producto cambia DESPUÉS de emitir el original.
        Producto::where('nombre', 'CANILLITAS')->first()->update(['precio_unitario' => 99]);

        $nuevo = app(DteBorradorService::class)->duplicarCcf($original, $this->usuario());

        $linea = $nuevo->lineas->firstWhere('descripcion', 'CANILLITAS');
        $this->assertSame('10.000000', (string) $linea->precio_unitario); // snapshot, no el vigente
    }

    public function test_el_aviso_de_oc_duplicada_aparece_en_el_duplicado(): void
    {
        $original = $this->ccfAceptado(); // emitido con OC-DUP-1

        $nuevo = app(DteBorradorService::class)->duplicarCcf($original, $u = $this->usuario());

        $this->actingAs($u)
            ->get(route('facturacion.edit', $nuevo))
            ->assertOk()
            ->assertSee('ya se usó en el CCF')
            ->assertSee(route('facturacion.show', $original), false);
    }

    // --- Ruta / UI / permisos ---

    public function test_ruta_duplicar_crea_borrador_y_redirige_a_edicion(): void
    {
        $original = $this->ccfAceptado();

        $resp = $this->actingAs($this->usuario())
            ->post(route('facturacion.duplicar', $original));

        $nuevo = Dte::query()->where('estado', EstadoDte::Borrador->value)->orderByDesc('id')->firstOrFail();
        $resp->assertRedirect(route('facturacion.edit', $nuevo));
        $this->assertSame($original->cliente_id, $nuevo->cliente_id);
    }

    public function test_boton_duplicar_visible_en_ccf_emitido_y_no_en_borrador(): void
    {
        $original = $this->ccfAceptado();

        $this->actingAs($this->usuario())
            ->get(route('facturacion.show', $original))
            ->assertOk()
            ->assertSee(route('facturacion.duplicar', $original), false);

        // En un borrador no aparece (se edita directo, no se duplica).
        $borrador = app(DteBorradorService::class)->duplicarCcf($original, $this->usuario());
        $this->actingAs($this->usuario())
            ->get(route('facturacion.show', $borrador))
            ->assertOk()
            ->assertDontSee(route('facturacion.duplicar', $borrador), false);
    }

    public function test_consulta_no_puede_duplicar(): void
    {
        $original = $this->ccfAceptado();

        $this->actingAs($this->usuario('consulta'))
            ->post(route('facturacion.duplicar', $original))
            ->assertForbidden();

        $this->assertSame(0, Dte::where('estado', EstadoDte::Borrador->value)->count());
    }

    public function test_no_duplica_documentos_que_no_son_ccf(): void
    {
        $factura = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::Factura,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);
        $factura->estado = EstadoDte::Generado;
        Dte::withoutEvents(fn () => $factura->save());

        $this->actingAs($this->usuario())
            ->post(route('facturacion.duplicar', $factura->refresh()))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, Dte::where('estado', EstadoDte::Borrador->value)->count());
    }
}
