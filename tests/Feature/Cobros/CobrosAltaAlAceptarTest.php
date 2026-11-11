<?php

namespace Tests\Feature\Cobros;

use App\Enums\EstadoDte;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroDocumento;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Cobros\AltaCobrosService;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteTransmisionService;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Support\Dte\EndpointsHacienda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * El CCF (y la NC) entra al seguimiento EN EL MOMENTO en que Hacienda lo acepta.
 *
 * Antes entraba con la tarea de cada diez minutos: quien facturaba y abría «Facturas por
 * cobrar» no veía lo que acababa de emitir, y una factura que no se ve es la que se pierde.
 * La tarea sigue corriendo como red de seguridad; estas pruebas fijan que no hace falta
 * esperarla, que las dos no se pisan y que el alta nunca puede romper la emisión.
 *
 * Nada sale a la red: Hacienda se simula con Http::fake.
 */
class CobrosAltaAlAceptarTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedCatalogosDte();

        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        foreach (['03', '05'] as $tipo) {
            Correlativo::create([
                'tipo_dte' => $tipo, 'establecimiento_id' => $this->estab->id, 'punto_venta_id' => $this->pv->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
            ]);
        }

        // Transmisión abierta contra el host oficial de pruebas, que Http::fake intercepta.
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.sistema_actual_activo', false);
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.allow_production', false);
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.url_base', EndpointsHacienda::HOST_PRUEBAS);
        config()->set('dte.transmision.endpoint_recepcion', '/fesv/recepciondte');
        config()->set('dte.transmision.token', 'TOKEN_FAKE_NO_REAL');

        config()->set('cobros.alta.automatica', true);
    }

    private function cliente(bool $conPerfil = true): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Cliente de prueba, S.A. de C.V.']);

        if ($conPerfil) {
            ClientePerfilDocumento::create([
                'cliente_id' => $cliente->id,
                'activo' => true,
                'codigo_proveedor' => '000123',
                'formato_export' => 'albaran_nc_v1',
                'exige_albaran_en_nc' => false,
                'tolerancia_albaran' => 0,
            ]);
            app(PerfilDocumentoResolver::class)->olvidar();
        }

        return $cliente;
    }

    /** Deja un documento firmado, con su JWS en disco: listo para transmitir. */
    private function firmar(Dte $dte): Dte
    {
        $ruta = 'dte/firmados/dte-'.$dte->tipo_dte->value.'-'.$dte->id.'.jws';
        Storage::disk('local')->put($ruta, 'eyJhbGciOiJSUzI1NiJ9.eyJkdGUiOiJmYWtlIn0.firma-falsa');

        $dte->json_firmado_path = $ruta;
        $dte->estado = EstadoDte::Firmado;
        $dte->save();

        return $dte->refresh();
    }

    private function ccfFirmado(Cliente $cliente): Dte
    {
        $producto = Producto::factory()->create([
            'nombre' => 'PRODUCTO DE PRUEBA',
            'precio_unitario' => 10,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);

        $ccf = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ]);
        app(DteBorradorService::class)->agregarLineaDesdeProducto($ccf, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($ccf);

        return $this->firmar($ccf->refresh());
    }

    /** Respuesta de Hacienda que ACEPTA de verdad: sello real y fecha de procesamiento. */
    private function haciendaAcepta(): void
    {
        Http::fake(['*' => Http::response([
            'estado' => 'PROCESADO',
            'selloRecibido' => '2026'.str_repeat('A', 36),
            'fhProcesamiento' => now()->format('d/m/Y H:i:s'),
            'descripcionMsg' => 'RECIBIDO',
        ], 200)]);
    }

    /** Transmite el documento firmado y devuelve cómo quedó. */
    private function transmitir(Dte $dte): Dte
    {
        $this->haciendaAcepta();
        app(DteTransmisionService::class)->transmitir($dte);

        return $dte->refresh();
    }

    // ------------------------------------------------------------------ dorada

    /** DORADA · el CCF aceptado ya está en el seguimiento, sin esperar a la tarea programada. */
    public function test_el_ccf_entra_al_seguimiento_en_el_momento_en_que_hacienda_lo_acepta(): void
    {
        $cliente = $this->cliente();

        $ccf = $this->transmitir($this->ccfFirmado($cliente));

        $this->assertSame(EstadoDte::Aceptado, $ccf->estado);
        $documento = CobroDocumento::where('dte_id', $ccf->id)->first();
        $this->assertNotNull($documento, 'El CCF aceptado no entró al seguimiento en el momento.');
        $this->assertSame($cliente->id, (int) $documento->cliente_id);
        $this->assertSame($ccf->numero_control, $documento->numero_control);
        $this->assertSame($ccf->sello_recepcion, $documento->sello_recepcion);
    }

    /** La tarea de cada diez minutos sigue como red de seguridad y no lo duplica. */
    public function test_la_tarea_programada_no_duplica_lo_que_entro_al_aceptarse(): void
    {
        $cliente = $this->cliente();
        $ccf = $this->transmitir($this->ccfFirmado($cliente));
        $this->assertSame(1, CobroDocumento::where('dte_id', $ccf->id)->count());

        $resumen = app(AltaCobrosService::class)->sincronizar($cliente);
        $repetido = app(AltaCobrosService::class)->darDeAlta($ccf);

        $this->assertSame(0, $resumen['creados']);
        $this->assertSame(1, $resumen['sin_cambio']);
        $this->assertSame('sin_cambio', $repetido);
        $this->assertSame(1, CobroDocumento::count());
    }

    /**
     * Si el alta al aceptar se le adelanta a la tarea EN PLENA CORRIDA (la tarea ya leyó qué
     * había en el seguimiento), la tarea no intenta crear el mismo documento otra vez: la
     * llave es única y eso tumbaba la corrida entera.
     */
    public function test_la_tarea_no_se_cae_si_el_alta_al_aceptar_se_le_adelanta_en_plena_corrida(): void
    {
        $cliente = $this->cliente();
        config()->set('cobros.alta.automatica', false);
        $ccf = $this->transmitir($this->ccfFirmado($cliente));
        config()->set('cobros.alta.automatica', true);
        $this->assertSame(0, CobroDocumento::count());

        $adelantado = false;
        Dte::retrieved(function (Dte $dte) use (&$adelantado, $ccf) {
            if (! $adelantado && $dte->id === $ccf->id) {
                $adelantado = true;
                app(AltaCobrosService::class)->darDeAlta($dte);
            }
        });

        $resumen = app(AltaCobrosService::class)->sincronizar($cliente);

        $this->assertTrue($adelantado);
        $this->assertSame(0, $resumen['creados']);
        $this->assertSame(1, $resumen['sin_cambio']);
        $this->assertSame(1, CobroDocumento::count());
    }

    // ------------------------------------------------------------------ quién NO entra

    public function test_el_ccf_de_un_cliente_sin_perfil_documental_no_entra(): void
    {
        $ccf = $this->transmitir($this->ccfFirmado($this->cliente(conPerfil: false)));

        $this->assertTrue($ccf->aceptadoRealmentePorMh());
        $this->assertSame(0, CobroDocumento::count());
    }

    public function test_con_el_alta_automatica_apagada_no_entra(): void
    {
        config()->set('cobros.alta.automatica', false);

        $ccf = $this->transmitir($this->ccfFirmado($this->cliente()));

        $this->assertTrue($ccf->aceptadoRealmentePorMh());
        $this->assertSame(0, CobroDocumento::count());
    }

    /** Un sello simulado no es una factura que se cobre: no entra al seguimiento. */
    public function test_una_aceptacion_simulada_no_entra(): void
    {
        config()->set('dte.transmision.mock', true);
        $ccf = $this->ccfFirmado($this->cliente());

        app(DteTransmisionService::class)->transmitir($ccf);

        $ccf->refresh();
        $this->assertSame(EstadoDte::Aceptado, $ccf->estado);
        $this->assertStringStartsWith('MOCK', (string) $ccf->sello_recepcion);
        $this->assertSame(0, CobroDocumento::count());
    }

    // ------------------------------------------------------------------ nunca rompe la emisión

    /**
     * Si el alta falla, el documento queda ACEPTADO igual (Hacienda ya lo selló) y el error se
     * reporta; lo recoge la tarea programada.
     */
    public function test_si_el_alta_falla_el_documento_queda_aceptado_y_el_error_se_reporta(): void
    {
        Exceptions::fake();
        CobroDocumento::creating(function (): void {
            throw new RuntimeException('Falla simulada al guardar el seguimiento.');
        });
        $ccf = $this->ccfFirmado($this->cliente());
        $this->haciendaAcepta();

        $resultado = app(DteTransmisionService::class)->transmitir($ccf);

        $this->assertSame('aceptado', $resultado['resultado']);
        $ccf->refresh();
        $this->assertTrue($ccf->aceptadoRealmentePorMh());
        $this->assertSame(0, CobroDocumento::count());
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Falla simulada al guardar el seguimiento.');
    }

    // ------------------------------------------------------------------ nota de crédito

    /** La NC entra igual que el CCF, también cuando la aceptación llega por la CONSULTA. */
    public function test_la_nota_de_credito_aceptada_tambien_entra(): void
    {
        $cliente = $this->cliente();
        $ccf = $this->transmitir($this->ccfFirmado($cliente));

        $nc = app(DteBorradorService::class)->crearNotaCredito($ccf, ['tipo' => 'pronto_pago']);
        $nc->numero_control = 'DTE-05-M001P001-000000000000001';
        $nc->codigo_generacion = '00000000-0000-4000-8000-000000000005';
        $nc = $this->firmar($nc);

        $sello = '2026'.str_repeat('B', 36);
        app(DteTransmisionService::class)->aplicarResultadoDeConsulta($nc, [
            'resultado' => 'aceptado',
            'http_status' => 200,
            'mensaje' => 'RECIBIDO',
            'sello' => $sello,
            'cuerpo' => ['estado' => 'PROCESADO', 'selloRecibido' => $sello, 'fhProcesamiento' => now()->format('d/m/Y H:i:s')],
        ]);

        $documento = CobroDocumento::where('dte_id', $nc->id)->first();
        $this->assertNotNull($documento, 'La NC aceptada no entró al seguimiento en el momento.');
        $this->assertSame('05', $documento->tipo_dte);
        $this->assertSame($sello, $documento->sello_recepcion);
    }

    // ------------------------------------------------------------------ aviso en pantalla

    private function administrador(): User
    {
        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return User::factory()->create()->assignRole(RolSistema::Administrador->value);
    }

    /**
     * Si una factura recién aceptada todavía no está en el seguimiento (el alta falló y la
     * recoge la tarea), la pantalla lo dice en vez de mostrar la lista como si estuviera completa.
     */
    public function test_la_pantalla_avisa_de_las_facturas_recien_aceptadas_que_todavia_no_entraron(): void
    {
        $cliente = $this->cliente();
        config()->set('cobros.alta.automatica', false);
        $this->transmitir($this->ccfFirmado($cliente));
        config()->set('cobros.alta.automatica', true);
        $this->assertSame(0, CobroDocumento::count());
        $usuario = $this->administrador();

        $this->actingAs($usuario)->get(route('cobros.index'))
            ->assertOk()
            ->assertSee('Hay 1 factura recién aceptada que está entrando al seguimiento');

        // Pasados los 15 minutos ya no es «recién aceptada»: el aviso no se queda para siempre.
        $this->travel(16)->minutes();
        $this->actingAs($usuario)->get(route('cobros.index'))
            ->assertOk()
            ->assertDontSee('recién aceptada');
    }

    public function test_la_pantalla_no_avisa_cuando_la_factura_ya_esta_en_el_seguimiento(): void
    {
        $cliente = $this->cliente();
        $ccf = $this->transmitir($this->ccfFirmado($cliente));
        $this->assertSame(1, CobroDocumento::where('dte_id', $ccf->id)->count());

        $this->actingAs($this->administrador())->get(route('cobros.index'))
            ->assertOk()
            ->assertDontSee('recién aceptada');
    }
}
