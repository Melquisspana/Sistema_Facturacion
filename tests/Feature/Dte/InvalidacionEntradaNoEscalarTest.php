<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;
use App\Http\Requests\Dte\TransmitirInvalidacionRequest;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ENTRADA NO ESCALAR en los campos del evento de invalidación (revisión R2 de Codex).
 *
 * `prepareForValidation()` normalizaba `reemplazo` y `motivo` con `(string)` ANTES de que
 * actuara la regla `string`. Un POST con `reemplazo[]=x` o `motivo[]=x` disparaba
 * «Array to string conversion», que bajo el manejador de errores de Laravel termina en
 * excepción —un 500— en lugar de un rechazo de validación. La misma conversión existía en
 * el controlador que usan el mock y el dry-run.
 *
 * Un 500 no es un detalle estético: oculta qué se recibió, no deja el error en el
 * formulario y, en una ruta que firma y transmite documentos fiscales, la diferencia entre
 * «rechazado por validación» y «reventó a mitad de camino» es exactamente la que hay que
 * poder demostrar.
 *
 * La corrección normaliza SOLO cadenas y deja pasar el resto tal cual para que el
 * validador lo rechace. Esta suite fija ese comportamiento en las tres vías —transmisión
 * real, mock y dry-run— y comprueba que no se firma, no se transmite y no se escribe
 * evidencia. La matriz fiscal no interviene aquí: esto es un error de ENTRADA.
 */
class InvalidacionEntradaNoEscalarTest extends TestCase
{
    use RefreshDatabase;

    private const SELLO = '2026000000000000000000000000000000000003'; // 40 chars

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('administrador', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');

        // Candados del entorno ABIERTOS: si algo se detiene en esta suite es por la
        // validación de entrada, no porque el modo seguro lo estuviera frenando antes.
        config()->set('dte.invalidacion.mock', false);
        config()->set('dte.invalidacion.real_confirmation', true);
        config()->set('dte.invalidacion.protegidos_numero_control', []);
        config()->set('dte.invalidacion.protegidos_codigo_generacion', []);
        config()->set('dte.invalidacion.responsable', ['nombre' => 'Melqui Administrador', 'tipo_doc' => '13', 'num_doc' => '040000000']);
        config()->set('dte.invalidacion.solicita', ['nombre' => 'Calleja CxP', 'tipo_doc' => '36', 'num_doc' => '06145555551015']);
        config()->set('dte.firma.enabled', true);
        config()->set('dte.firma.mock', false);
        config()->set('dte.firma.nit', '06140000000901');
        config()->set('dte.firma.cert_password', 'secreto');
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.test_enabled', true);
        config()->set('dte.ambientes.00.anulacion_url', 'https://apitest.dtes.mh.gob.sv/fesv/anulardte');
        $this->credencialesApitestFicticias();

        // Sin manejo «amable» de excepciones: si la normalización volviera a reventar,
        // este test tiene que verlo como el 500 que es, no como una página de error.
        $this->withExceptionHandling();
    }

    private function ccfAceptado(): Dte
    {
        $empresa = Empresa::create([
            'razon_social' => 'Titular de Ejemplo', 'nombre_comercial' => 'Dulces La Negrita',
            'nit' => '06140000000901', 'nrc' => '1000017', 'telefono' => '22220000',
            'correo' => 'facturacion@example.com', 'ambiente' => '00', 'activo' => true,
        ]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        $cliente = Cliente::factory()->contribuyente()->create([
            'nombre' => 'Calleja, S.A. de C.V.', 'num_documento' => '0614-555555-101-5',
            'telefono' => '22220001', 'correo' => 'responsable@example.com',
        ]);

        return Dte::create([
            'tipo_dte' => TipoDte::CreditoFiscal->value,
            'estado' => EstadoDte::Aceptado->value,
            'ambiente' => '00',
            'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id, 'cliente_id' => $cliente->id,
            'numero_control' => 'DTE-03-M001P001-000000000000001',
            'codigo_generacion' => strtoupper((string) Str::uuid()),
            'sello_recepcion' => self::SELLO,
            'respuesta_mh' => ['estado' => 'PROCESADO', 'selloRecibido' => self::SELLO],
            'fecha_procesamiento_mh' => '2026-07-20 22:55:01',
            'fecha_emision' => '2026-07-20', 'hora_emision' => '22:26:52',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    private function fakeHttp(): void
    {
        Http::fake([
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FAKE.JWS.SIGNATURE'], 200),
            '*seguridad/auth*' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer FAKE-TOKEN']], 200),
            '*anulardte*' => Http::response(['estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-X', 'descripcionMsg' => 'ok'], 200),
        ]);
    }

    /** Nada se firmó, nada se transmitió y nada quedó escrito. */
    private function assertSinEfectos(Dte $dte): void
    {
        $dte->refresh();

        $this->assertSame(EstadoDte::Aceptado, $dte->estado);
        $this->assertNull($dte->sello_invalidacion);
        $this->assertNull($dte->codigo_generacion_invalidacion);
        $this->assertNull($dte->json_invalidacion_path);
        $this->assertNull($dte->jws_invalidacion_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Http::assertNothingSent();
    }

    // ------------------------------------------------- Las tres rutas del evento

    /**
     * @return array<string, array{0: string}>
     */
    public static function rutasDelEvento(): array
    {
        return [
            'transmisión real' => ['facturacion.invalidacion.transmitir'],
            'mock' => ['facturacion.invalidacion.mock'],
            'dry-run' => ['facturacion.invalidacion.dry-run'],
        ];
    }

    /**
     * Arreglos en AMBOS campos: errores de validación en los dos, y ni un 500. La frase
     * barrera viaja correcta a propósito, para que lo único que pueda detener la petición
     * sea el tipo de los campos.
     */
    #[DataProvider('rutasDelEvento')]
    public function test_arreglos_en_reemplazo_y_motivo_dan_error_de_validacion_no_un_500(string $ruta): void
    {
        $this->fakeHttp();
        $ccf = $this->ccfAceptado();

        $respuesta = $this->actingAs($this->admin())->post(route($ruta, $ccf), [
            'tipo' => TipoAnulacionMh::ErrorInformacion->value,
            'reemplazo' => ['00000000-0000-4000-8000-000000000015'],
            'motivo' => ['texto'],
            'confirmacion_invalidacion' => TransmitirInvalidacionRequest::FRASE,
            'confirmar_sin_flag' => '1',
        ]);

        $respuesta->assertSessionHasErrors(['reemplazo', 'motivo']);
        $this->assertNotSame(500, $respuesta->getStatusCode(), 'La entrada no escalar no puede producir un 500.');

        $this->assertSinEfectos($ccf);
    }

    /** Lo mismo pedido como JSON: 422 con los dos campos, no una excepción. */
    #[DataProvider('rutasDelEvento')]
    public function test_la_peticion_json_devuelve_422_con_los_dos_campos(string $ruta): void
    {
        $this->fakeHttp();
        $ccf = $this->ccfAceptado();

        $this->actingAs($this->admin())
            ->postJson(route($ruta, $ccf), [
                'tipo' => TipoAnulacionMh::ErrorInformacion->value,
                'reemplazo' => ['x'],
                'motivo' => ['y'],
                'confirmacion_invalidacion' => TransmitirInvalidacionRequest::FRASE,
                'confirmar_sin_flag' => '1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reemplazo', 'motivo']);

        $this->assertSinEfectos($ccf);
    }

    /**
     * Un `tipo` no escalar tampoco puede deducir una celda de la matriz: `(int) ['3']`
     * vale 1, o sea el motivo 1, que no es lo que nadie envió. Se rechaza por `tipo` y
     * nunca se calcula un requisito a partir de una entrada inválida.
     */
    #[DataProvider('rutasDelEvento')]
    public function test_un_tipo_no_escalar_se_rechaza_sin_deducir_la_matriz(string $ruta): void
    {
        $this->fakeHttp();
        $ccf = $this->ccfAceptado();

        $this->actingAs($this->admin())
            ->postJson(route($ruta, $ccf), [
                'tipo' => ['3'],
                'confirmacion_invalidacion' => TransmitirInvalidacionRequest::FRASE,
                'confirmar_sin_flag' => '1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo');

        $this->assertSinEfectos($ccf);
    }

    // ------------------------------------------------- La normalización que SÍ debe seguir

    /**
     * La corrección no puede haberse llevado por delante lo que ya funcionaba: vacío y
     * espacios siguen dando null, y un UUID válido sigue llegando recortado y en
     * mayúsculas.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function valoresNormalizados(): array
    {
        return [
            'cadena vacía' => ['', null],
            'solo espacios' => ['   ', null],
            'uuid en minúsculas con espacios' => ['  a1b2c3d4-e5f6-4a8b-9c0d-1e2f3a4b5c6d  ', 'A1B2C3D4-E5F6-4A8B-9C0D-1E2F3A4B5C6D'],
        ];
    }

    #[DataProvider('valoresNormalizados')]
    public function test_la_normalizacion_de_cadenas_se_conserva(mixed $entrada, mixed $esperado): void
    {
        $this->assertSame($esperado, TransmitirInvalidacionRequest::normalizarCodigo($entrada));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function valoresNoEscalares(): array
    {
        return [
            'arreglo' => [['x']],
            'arreglo vacío' => [[]],
            'arreglo anidado' => [[['x']]],
        ];
    }

    /**
     * Lo que NO es cadena se devuelve INTACTO, sin convertir ni aplanar: el validador
     * tiene que ver el tipo que de verdad se recibió. Si esto volviera a castear, el
     * warning de conversión aparecería aquí.
     */
    #[DataProvider('valoresNoEscalares')]
    public function test_lo_que_no_es_cadena_pasa_intacto_al_validador(mixed $entrada): void
    {
        $this->assertSame($entrada, TransmitirInvalidacionRequest::normalizarCodigo($entrada));
        $this->assertSame($entrada, TransmitirInvalidacionRequest::normalizarTexto($entrada));
    }

    /**
     * Booleanos y números tampoco se maquillan: convertirlos aquí escondería el tipo
     * recibido y dejaría que `string` pasara por alto una entrada que no era texto.
     */
    public function test_booleanos_y_numeros_no_se_convierten_a_cadena(): void
    {
        foreach ([true, false, 0, 1, 3.5] as $valor) {
            $this->assertSame($valor, TransmitirInvalidacionRequest::normalizarCodigo($valor));
            $this->assertSame($valor, TransmitirInvalidacionRequest::normalizarTexto($valor));
        }

        // Y el validador los rechaza por `string` en la ruta real.
        $this->fakeHttp();
        $ccf = $this->ccfAceptado();

        $this->actingAs($this->admin())
            ->postJson(route('facturacion.invalidacion.transmitir', $ccf), [
                'tipo' => TipoAnulacionMh::Otro->value,
                'motivo' => true,
                'confirmacion_invalidacion' => TransmitirInvalidacionRequest::FRASE,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->assertSinEfectos($ccf);
    }
}
