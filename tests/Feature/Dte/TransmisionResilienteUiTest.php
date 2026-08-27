<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Exceptions\Dte\DteFirmaException;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteFirmaService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\DteTransmisionResiliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * QUE EL FLUJO REAL USE LA POLÍTICA OFICIAL — y el CASO 2 del Manual V2.0.
 *
 * La política de reintentos no sirve de nada si vive en un comando de consola mientras
 * el botón que usa la gente sigue enviando a pelo. Eso era exactamente la situación:
 * `dte:transmitir` la aplicaba y `DteController` no. Estas pruebas fijan que **toda**
 * transmisión —01, 03, 05 y 11, por la UI— pasa por `DteTransmisionResiliente`, y que
 * no hay una segunda forma de enviar un DTE.
 *
 * La prueba de que pasa por ahí es observable y no depende de mocks del contenedor: si
 * recepción no responde, la política **consulta**; el servicio pelado no consultaría
 * nada. Que exista una petición a `consultadte` es la huella.
 *
 * CASO 2 DEL MANUAL. «Si al momento de enviar un DTE el servicio del firmador falla y
 * no procesa la respuesta del servicio de recepción»: consultar antes de reenviar. Acá
 * se materializa como un documento que llega YA FIRMADO de un intento anterior cuyo
 * desenlace nadie conoce —el usuario que vuelve a pulsar el botón—. Se distingue del
 * fallo PURO de firma, donde no hubo envío que consultar y preguntar sería una petición
 * inútil.
 *
 * Nada sale a la red: candado global de {@see TestCase} más `Http::fake()`.
 */
class TransmisionResilienteUiTest extends TestCase
{
    use \Tests\Concerns\PreparaEmisorDte;
    use RefreshDatabase;

    private Establecimiento $estab;

    private PuntoVenta $pv;

    private DteBorradorService $borradores;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        foreach (['01', '03', '05', '11'] as $t) {
            Correlativo::create([
                'tipo_dte' => $t, 'establecimiento_id' => $this->estab->id,
                'punto_venta_id' => $this->pv->id, 'ambiente' => '00',
                'ultimo_numero' => 0, 'activo' => true,
            ]);
        }
        $this->borradores = app(DteBorradorService::class);
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    /**
     * Candados abiertos contra APITEST. Producción no se toca: para eso están los
     * tests de candados, y abrirla aquí no aportaría nada a lo que se está probando.
     */
    private function habilitar(): void
    {
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.sistema_actual_activo', false);
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.allow_production', false);
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.token', 'Bearer TOKEN_FAKE_NO_REAL');
        config()->set('dte.firma.enabled', true);
        config()->set('dte.firma.mock', true);   // firma simulada: no hay firmador local
    }

    private function generado(TipoDte $tipo): Dte
    {
        $datos = [
            'tipo_dte' => $tipo,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
        ];
        if ($tipo === TipoDte::CreditoFiscal) {
            $datos['cliente_id'] = Cliente::factory()->contribuyente()->create()->id;
        }
        if ($tipo === TipoDte::FacturaExportacion) {
            $datos['cliente_id'] = Cliente::factory()->exportacion()->create()->id;
            // Datos aduaneros obligatorios de la FEX; códigos reales del catálogo que
            // siembra seedCatalogosDte().
            $datos['tipo_item_expor'] = 1;
            $datos['recinto_fiscal'] = '01';
            $datos['tipo_regimen'] = 'EX-1';
            $datos['regimen'] = '1000.000';
            $datos['cod_incoterms'] = '09';
        }
        $dte = $this->borradores->crearBorrador($datos);
        $producto = Producto::factory()->create([
            'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    /** Deja el documento FIRMADO, como si un intento anterior hubiera llegado hasta ahí. */
    private function firmado(TipoDte $tipo): Dte
    {
        $dte = $this->generado($tipo);
        $ruta = 'dte/firmados/dte-'.$dte->tipo_dte->value.'-'.$dte->id.'-'.$dte->codigo_generacion.'.jws';
        Storage::disk('local')->put($ruta, 'eyJ.fake.jws.compacta');
        $dte->json_firmado_path = $ruta;
        $dte->estado = EstadoDte::Firmado;
        $dte->save();

        return $dte->refresh();
    }

    /**
     * Recepción muda + consulta que responde lo que se le pida. Devuelve los contadores
     * por referencia para poder afirmar sobre cuántos envíos y consultas hubo.
     *
     * @param  array<string, mixed>|null  $consultaCuerpo  null = 404 (no encontrado)
     */
    private function fakeRecepcionMuda(&$envios, &$consultas, ?array $consultaCuerpo = null): void
    {
        $envios = 0;
        $consultas = 0;
        Http::fake(function ($request) use (&$envios, &$consultas, $consultaCuerpo) {
            if (str_contains($request->url(), 'consultadte')) {
                $consultas++;

                return $consultaCuerpo === null
                    ? Http::response([], 404)
                    : Http::response($consultaCuerpo, 200);
            }
            $envios++;
            throw new ConnectionException('recepción no responde');
        });
    }

    // ============================ 1) LA UI PASA POR LA POLÍTICA (01/03/05/11)

    /**
     * @return array<string, array{0: TipoDte}>
     */
    public static function tiposHabilitados(): array
    {
        return [
            'Factura (01)' => [TipoDte::Factura],
            'CCF (03)' => [TipoDte::CreditoFiscal],
            'Exportación (11)' => [TipoDte::FacturaExportacion],
        ];
    }

    /**
     * El botón "firmar y transmitir" de la UI, para CADA tipo habilitado, tiene que
     * consultar cuando recepción no responde. Si no consultara, estaría usando el
     * servicio pelado y un reenvío podría duplicar el documento.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tiposHabilitados')]
    public function test_la_ui_transmite_por_la_politica_resiliente(TipoDte $tipo): void
    {
        // La NC (05) no entra en este proveedor: exige un CCF aceptado detrás y tiene
        // su propia prueba justo debajo. Un test saltado es ruido que con el tiempo
        // deja de leerse.
        $this->habilitar();
        $this->fakeRecepcionMuda($envios, $consultas);
        $dte = $this->generado($tipo);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.firmar-transmitir', $dte), [])
            ->assertRedirect();

        $this->assertGreaterThan(0, $consultas, 'La UI no consultó: no está usando la política.');
        $this->assertSame(3, $envios, 'Debía agotar los 3 envíos (inicial + 2 reenvíos).');
    }

    /** La nota de crédito (05), con su CCF aceptado detrás, sigue el mismo camino. */
    public function test_la_ui_transmite_la_nota_de_credito_por_la_politica_resiliente(): void
    {
        $this->habilitar();
        $ccf = $this->aceptarCcf($this->generado(TipoDte::CreditoFiscal));
        $nc = $this->borradores->crearNotaCredito($ccf, ['tipo' => 'pronto_pago']);
        $this->borradores->agregarConceptoNotaCredito($nc, [
            'descripcion' => 'Pronto pago', 'monto' => 5, 'tipo_impuesto' => 'gravado',
        ]);
        app(DteGeneracionService::class)->generar($nc);
        $nc->refresh();

        $this->fakeRecepcionMuda($envios, $consultas);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.firmar-transmitir', $nc), [])
            ->assertRedirect();

        $this->assertGreaterThan(0, $consultas);
        $this->assertSame(3, $envios);
    }

    /** Y jamás más de 2 reenvíos, venga de donde venga la transmisión. */
    public function test_la_ui_nunca_hace_mas_de_dos_reenvios(): void
    {
        $this->habilitar();
        $this->fakeRecepcionMuda($envios, $consultas);
        $dte = $this->generado(TipoDte::CreditoFiscal);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.firmar-transmitir', $dte), [])
            ->assertRedirect();

        $this->assertSame(3, $envios);
        $this->assertLessThanOrEqual(3, $consultas);
        // Y el documento no quedó aceptado ni con sello inventado.
        $dte->refresh();
        $this->assertNull($dte->sello_recepcion);
        $this->assertNotSame(EstadoDte::Aceptado, $dte->estado);
    }

    /** Al agotarse, la UI avisa; y NO se activó ningún evento de contingencia. */
    public function test_al_agotar_reintentos_la_ui_avisa_sin_activar_contingencia(): void
    {
        $this->habilitar();
        $this->fakeRecepcionMuda($envios, $consultas);
        $dte = $this->generado(TipoDte::CreditoFiscal);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.firmar-transmitir', $dte), [])
            ->assertRedirect();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fesv/contingencia')
            || str_contains($request->url(), '/fesv/recepcionlote'));
        $this->assertSame(EstadoDte::Firmado, $dte->refresh()->estado);
    }

    // ================================== 2) CASO 2 DEL MANUAL (estado incierto)

    /**
     * EL CASO QUE EVITA EL DUPLICADO MÁS PROBABLE: el usuario vuelve a pulsar el botón
     * sobre un documento que quedó firmado, sin saber que el intento anterior SÍ había
     * entrado. Se consulta ANTES de enviar y no se envía nada.
     */
    public function test_documento_ya_firmado_consulta_antes_de_enviar_y_no_reenvia_si_ya_entro(): void
    {
        $this->habilitar();
        $envios = 0;
        $consultas = 0;
        Http::fake(function ($request) use (&$envios, &$consultas) {
            if (str_contains($request->url(), 'consultadte')) {
                $consultas++;

                return Http::response([
                    'estado' => 'PROCESADO',
                    'selloRecibido' => '2026SELLOYAEXISTENTE',
                    'descripcionMsg' => 'Recibido',
                ], 200);
            }
            $envios++;

            return Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'OTRO'], 200);
        });

        $dte = $this->firmado(TipoDte::CreditoFiscal);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.firmar-transmitir', $dte), [])
            ->assertRedirect();

        $this->assertSame(1, $consultas, 'Debía consultar antes del primer envío.');
        $this->assertSame(0, $envios, 'No debía enviarse: Hacienda ya lo tenía.');

        $dte->refresh();
        $this->assertSame('2026SELLOYAEXISTENTE', $dte->sello_recepcion);
        $this->assertSame(EstadoDte::Aceptado, $dte->estado);
    }

    /** Si la consulta previa dice que NO entró, entonces sí se envía. */
    public function test_documento_ya_firmado_se_envia_si_la_consulta_dice_que_no_entro(): void
    {
        $this->habilitar();
        $envios = 0;
        $consultas = 0;
        Http::fake(function ($request) use (&$envios, &$consultas) {
            if (str_contains($request->url(), 'consultadte')) {
                $consultas++;

                return Http::response([], 404);
            }
            $envios++;

            return Http::response([
                'estado' => 'PROCESADO', 'selloRecibido' => '2026SELLONUEVO',
            ], 200);
        });

        $dte = $this->firmado(TipoDte::CreditoFiscal);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.firmar-transmitir', $dte), [])
            ->assertRedirect();

        $this->assertSame(1, $consultas);
        $this->assertSame(1, $envios);
        $this->assertSame('2026SELLONUEVO', $dte->refresh()->sello_recepcion);
    }

    /**
     * Un documento GENERADO se firma ahora: la firma es nueva, no hubo envío anterior y
     * NO se consulta antes del primer envío. Preguntar ahí sería una petición inútil.
     */
    public function test_documento_generado_no_consulta_antes_del_primer_envio(): void
    {
        $this->habilitar();
        $consultas = 0;
        Http::fake(function ($request) use (&$consultas) {
            if (str_contains($request->url(), 'consultadte')) {
                $consultas++;
            }

            return Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO'], 200);
        });

        $dte = $this->generado(TipoDte::CreditoFiscal);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.firmar-transmitir', $dte), [])
            ->assertRedirect();

        $this->assertSame(0, $consultas, 'No había intento previo: no hay nada que consultar.');
    }

    /**
     * FALLO PURO DE FIRMA, antes de que exista un JWS que pudiera haberse enviado. No
     * es el Caso 2: no hubo recepción. Se devuelve el error de firma y NO se consulta
     * ni se transmite nada.
     */
    public function test_un_fallo_de_firma_previo_al_envio_no_consulta_a_hacienda(): void
    {
        $this->habilitar();
        config()->set('dte.firma.mock', false);   // firma real → la mockeamos abajo
        Http::fake();

        // Firmador que revienta antes de producir ningún JWS.
        $this->app->instance(DteFirmaService::class, new class extends DteFirmaService
        {
            public function __construct() {}

            public function firmar(Dte $dte, bool $force = false): array
            {
                throw new DteFirmaException('firmador caído (simulado)');
            }
        });

        $dte = $this->generado(TipoDte::CreditoFiscal);

        $this->actingAs($this->usuario())
            ->post(route('facturacion.firmar-transmitir', $dte), [])
            ->assertRedirect()
            ->assertSessionHas('error');

        // Ni consulta ni recepción: no hubo nada que preguntar.
        Http::assertNothingSent();
        $this->assertSame(EstadoDte::Generado, $dte->refresh()->estado);
    }

    // ============================================ 3) UNA SOLA FORMA DE ENVIAR

    /**
     * No queda ningún camino que transmita saltándose la política. Se comprueba sobre
     * el CÓDIGO porque es la única forma de detectar que alguien añada mañana una
     * segunda vía: un test de comportamiento solo cubre los caminos que ya conoce.
     */
    public function test_no_existe_otra_via_de_transmision_fuera_de_la_politica(): void
    {
        $llamadas = [];
        foreach (['app/Http/Controllers', 'app/Console/Commands', 'app/Services', 'app/Jobs'] as $dir) {
            $ruta = base_path($dir);
            if (! is_dir($ruta)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($ruta));
            foreach ($it as $archivo) {
                if ($archivo->getExtension() !== 'php') {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($archivo->getPathname(), strlen(base_path()) + 1));
                // El propio servicio de la política sí llama al de transmisión: es su trabajo.
                if ($rel === 'app/Services/Dte/DteTransmisionResiliente.php') {
                    continue;
                }
                $contenido = (string) file_get_contents($archivo->getPathname());
                if (preg_match('/\$\w*transmision\w*->transmitir\(/i', $contenido)) {
                    $llamadas[] = $rel;
                }
            }
        }

        $this->assertSame([], $llamadas,
            'Estos archivos llaman a transmitir() sin pasar por DteTransmisionResiliente: '
            .implode(', ', $llamadas));
    }

    /** Y el servicio resiliente está resoluble desde el contenedor con sus dependencias. */
    public function test_la_politica_es_resoluble_desde_el_contenedor(): void
    {
        $this->assertInstanceOf(DteTransmisionResiliente::class, app(DteTransmisionResiliente::class));
    }
}
