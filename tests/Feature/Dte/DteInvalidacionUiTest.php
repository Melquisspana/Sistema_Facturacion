<?php

namespace Tests\Feature\Dte;

use App\Enums\EstadoDte;
use App\Enums\TipoAnulacionMh;
use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Support\HoraNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * UI de invalidación (evento anulardte): SOLO mock + dry-run visual. La transmisión REAL
 * a apitest NO se expone en la web (solo por consola). Verifica candados, roles y que la
 * evidencia de recepción original nunca se toque.
 */
class DteInvalidacionUiTest extends TestCase
{
    use RefreshDatabase;

    private const NC_SELLO = '2026000000000000000000000000000000000003'; // 40 chars

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');
        // NOTA: el fake HTTP se activa POR TEST (no global) para poder registrar stubs
        // específicos del firmador/auth/anulardte con la precedencia correcta. Con un
        // Http::fake() en setUp, su comodín gana sobre los stubs específicos del test.

        config()->set('dte.invalidacion.mock', true);
        // Responsable/solicitante REALES (el schema los exige); vienen de config en la UI.
        config()->set('dte.invalidacion.responsable', ['nombre' => 'Melqui Administrador', 'tipo_doc' => '13', 'num_doc' => '040000000']);
        config()->set('dte.invalidacion.solicita', ['nombre' => 'Calleja CxP', 'tipo_doc' => '36', 'num_doc' => '06145555551015']);
        // Credenciales FICTICIAS de apitest: estos tests mockean toda la red, pero
        // el servicio de autenticación aborta antes de llegar a ella si no hay
        // ninguna. Sin esto, la suite se pone roja en cualquier máquina sin
        // DTE_TEST_USER/DTE_TEST_PASSWORD en el .env — un rojo que no señala
        // ninguna regresión. Ver Tests\TestCase::credencialesApitestFicticias().
        $this->credencialesApitestFicticias();

    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** NC tipo 05 aceptada realmente por el MH (sello real + fecha de procesamiento). */
    private function ncAceptada(bool $aceptada = true): Dte
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
            'tipo_dte' => TipoDte::NotaCredito->value,
            'estado' => $aceptada ? EstadoDte::Aceptado->value : EstadoDte::Generado->value,
            'ambiente' => '00',
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'cliente_id' => $cliente->id,
            'numero_control' => 'DTE-05-M001P001-000000000000020',
            'codigo_generacion' => '00000000-0000-4000-8000-000000000010',
            'sello_recepcion' => $aceptada ? self::NC_SELLO : null,
            'respuesta_mh' => $aceptada ? ['estado' => 'PROCESADO', 'selloRecibido' => self::NC_SELLO] : null,
            'fecha_procesamiento_mh' => $aceptada ? HoraNegocio::ahora()->format('Y-m-d H:i:s') : null,
            'fecha_emision' => '2026-06-30',
            'hora_emision' => '22:26:52',
        ]);
    }

    // --- Visibilidad del bloque ---

    public function test_administrador_ve_el_bloque_de_invalidacion_en_una_nc_aceptada(): void
    {
        $nc = $this->ncAceptada();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $nc))
            ->assertOk()
            ->assertSee('Invalidación oficial (evento anulardte)')
            ->assertSee('Firmar invalidación (MOCK)')   // mock sigue disponible (avanzado)
            // Acción real presente pero DESHABILITADA: en este entorno los candados la
            // bloquean. El asistente no se monta, así que su formulario —y con él la
            // frase-barrera— no está en la página (ver el test de candados abiertos).
            ->assertSee('Invalidar oficialmente')
            ->assertSee('Botón deshabilitado')
            ->assertDontSee('Transmitir invalidación a Hacienda');
    }

    /**
     * Con los candados del entorno ABIERTOS, el asistente sí se monta: aparece el botón
     * rojo del último paso y la frase-barrera exacta. Sigue sin transmitir nada por el
     * solo hecho de renderizarse (es un GET).
     */
    public function test_con_candados_abiertos_el_asistente_expone_la_frase_barrera(): void
    {
        $this->abrirCandados();
        Http::fake();
        $nc = $this->ncAceptada();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.show', $nc))
            ->assertOk()
            ->assertSee('Invalidación oficial (evento anulardte)')
            ->assertSee('Transmitir invalidación a Hacienda')
            ->assertSee('INVALIDAR DTE')
            ->assertDontSee('Botón deshabilitado');

        Http::assertNothingSent();
    }

    /**
     * La invalidación es SOLO del administrador: ni jefatura, ni facturación, ni
     * contabilidad ven el bloque (aunque facturación sí gestione/emita DTE).
     */
    public function test_no_administradores_no_ven_el_bloque_de_invalidacion(): void
    {
        $nc = $this->ncAceptada();

        foreach (['jefatura', 'facturacion', 'contabilidad'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('facturacion.show', $nc))
                ->assertOk()
                ->assertDontSee('Invalidación oficial (evento anulardte)')
                ->assertDontSee('Firmar invalidación (MOCK)');
        }
    }

    // --- Dry-run visual (solo lectura) ---

    public function test_dry_run_no_persiste_ni_transmite(): void
    {
        Http::fake();
        $nc = $this->ncAceptada();

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.dry-run', $nc), ['tipo' => TipoAnulacionMh::RescindirOperacion->value])
            ->assertRedirect(route('facturacion.show', $nc))
            ->assertSessionHas('dry_run_invalidacion');

        Http::assertNothingSent();
        $nc->refresh();
        $this->assertFalse($nc->tieneEventoInvalidacion());
        $this->assertNull($nc->sello_invalidacion);
        $this->assertSame(EstadoDte::Aceptado, $nc->estado);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    // --- Firma MOCK (Fase C) ---

    public function test_mock_persiste_columnas_sin_cambiar_estado_ni_evidencia(): void
    {
        Http::fake();
        $nc = $this->ncAceptada();
        $selloOriginal = $nc->sello_recepcion;

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.mock', $nc), ['tipo' => TipoAnulacionMh::RescindirOperacion->value])
            ->assertRedirect(route('facturacion.show', $nc))
            ->assertSessionHas('status');

        Http::assertNothingSent();
        $nc->refresh();
        $this->assertStringStartsWith('MOCK-INVAL-', (string) $nc->sello_invalidacion);
        $this->assertSame(TipoAnulacionMh::RescindirOperacion, $nc->tipo_anulacion);
        // No cambia el estado ni toca la evidencia de recepción original.
        $this->assertSame(EstadoDte::Aceptado, $nc->estado);
        $this->assertSame($selloOriginal, $nc->sello_recepcion);
    }

    public function test_mock_apagado_sin_confirmar_muestra_error_y_no_persiste(): void
    {
        config()->set('dte.invalidacion.mock', false);
        $nc = $this->ncAceptada();

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.mock', $nc), ['tipo' => TipoAnulacionMh::RescindirOperacion->value])
            ->assertRedirect(route('facturacion.show', $nc))
            ->assertSessionHas('error');

        $nc->refresh();
        $this->assertFalse($nc->tieneEventoInvalidacion());
    }

    public function test_mock_apagado_con_confirmacion_explicita_persiste(): void
    {
        config()->set('dte.invalidacion.mock', false);
        $nc = $this->ncAceptada();

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.mock', $nc), [
                'tipo' => TipoAnulacionMh::RescindirOperacion->value,
                'confirmar_sin_flag' => '1',
            ])
            ->assertRedirect(route('facturacion.show', $nc))
            ->assertSessionHas('status');

        $nc->refresh();
        $this->assertStringStartsWith('MOCK-INVAL-', (string) $nc->sello_invalidacion);
    }

    // --- Validación de campos CAT-024 ---

    public function test_tipo_otro_exige_motivo(): void
    {
        $nc = $this->ncAceptada();

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.mock', $nc), ['tipo' => TipoAnulacionMh::Otro->value])
            ->assertSessionHasErrors('motivo');

        $nc->refresh();
        $this->assertFalse($nc->tieneEventoInvalidacion());
    }

    /**
     * CAMBIO DE EXPECTATIVA (manual funcional, págs. impresas 13-16): este caso es una
     * NOTA DE CRÉDITO, y la fila NC de la matriz lleva `codigoGeneracionR` en null en los
     * TRES motivos. Antes el test exigía reemplazo para el motivo 1 aplicando la regla
     * general de CAT-024 a todos los tipos; ahora el motivo 1 sobre una NC NO pide
     * sustituto y, si alguien lo manda, se rechaza. La exigencia de sustituto para el
     * motivo 1 se prueba sobre FE/CCF/FEX en MatrizInvalidacionHaciendaTest.
     */
    public function test_nc_motivo_1_no_pide_reemplazo_y_rechaza_el_que_se_envie(): void
    {
        $nc = $this->ncAceptada();
        $usuario = $this->usuario('administrador');

        // Con reemplazo: la matriz lo PROHÍBE para la NC, así que se rechaza en servidor
        // y no se firma nada.
        $this->actingAs($usuario)
            ->post(route('facturacion.invalidacion.mock', $nc), [
                'tipo' => TipoAnulacionMh::ErrorInformacion->value,
                'reemplazo' => '00000000-0000-4000-8000-000000000015',
            ])
            ->assertSessionHasErrors('reemplazo');

        $this->assertFalse($nc->fresh()->tieneEventoInvalidacion());

        // Sin reemplazo: no hay error de validación y el evento se firma en mock.
        $this->actingAs($usuario)
            ->post(route('facturacion.invalidacion.mock', $nc), ['tipo' => TipoAnulacionMh::ErrorInformacion->value])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertStringStartsWith('MOCK-INVAL-', (string) $nc->fresh()->sello_invalidacion);
    }

    // --- Candados (policy) ---

    public function test_no_se_puede_invalidar_una_nc_no_aceptada_realmente(): void
    {
        $nc = $this->ncAceptada(aceptada: false); // generado, sin sello real

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.mock', $nc), ['tipo' => TipoAnulacionMh::RescindirOperacion->value])
            ->assertForbidden();
    }

    public function test_no_se_invalida_dos_veces(): void
    {
        $nc = $this->ncAceptada();
        // Primera invalidación mock.
        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.mock', $nc), ['tipo' => TipoAnulacionMh::RescindirOperacion->value]);
        $nc->refresh();
        $this->assertTrue($nc->tieneEventoInvalidacion());

        // Segunda: bloqueada por policy (ya tiene evento).
        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.mock', $nc), ['tipo' => TipoAnulacionMh::RescindirOperacion->value])
            ->assertForbidden();
    }

    public function test_no_administradores_no_pueden_dry_run_ni_mock(): void
    {
        Http::fake();
        $nc = $this->ncAceptada();

        // Ni jefatura, ni facturación, ni contabilidad pueden invalidar (solo admin).
        foreach (['jefatura', 'facturacion', 'contabilidad'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->post(route('facturacion.invalidacion.dry-run', $nc), ['tipo' => TipoAnulacionMh::RescindirOperacion->value])
                ->assertForbidden();

            $this->actingAs($this->usuario($rol))
                ->post(route('facturacion.invalidacion.mock', $nc), ['tipo' => TipoAnulacionMh::RescindirOperacion->value])
                ->assertForbidden();
        }

        Http::assertNothingSent();
    }

    // --- Transmisión REAL desde la web: existe pero está fuertemente candada ---

    public function test_rutas_de_invalidacion_disponibles(): void
    {
        $this->assertTrue(Route::has('facturacion.invalidacion.mock'));
        $this->assertTrue(Route::has('facturacion.invalidacion.dry-run'));
        // La transmisión real ahora SÍ existe en la web (candada); el nombre .real nunca existió.
        $this->assertTrue(Route::has('facturacion.invalidacion.transmitir'));
        $this->assertFalse(Route::has('facturacion.invalidacion.real'));
    }

    /** Abre TODOS los candados del entorno (como el comando real contra apitest mockeado). */
    private function abrirCandados(): void
    {
        config()->set('dte.invalidacion.mock', false);
        config()->set('dte.invalidacion.real_confirmation', true);
        config()->set('dte.firma.enabled', true);
        config()->set('dte.firma.mock', false);
        config()->set('dte.firma.nit', '06140000000901');
        config()->set('dte.firma.cert_password', 'secreto');
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.test_enabled', true);
        config()->set('dte.ambientes.00.anulacion_url', 'https://apitest.dtes.mh.gob.sv/fesv/anulardte');
    }

    /** Firmador + auth + anulardte mockeados (no sale nada real a la red). */
    private function fakeHttp(array $anulardteResponse): void
    {
        Http::fake([
            '*firmardocumento*' => Http::response(['status' => 'OK', 'body' => 'FAKE.JWS.SIGNATURE'], 200),
            '*seguridad/auth*' => Http::response(['status' => 'OK', 'body' => ['token' => 'Bearer FAKE-TOKEN']], 200),
            '*anulardte*' => Http::response($anulardteResponse, $anulardteResponse['_http'] ?? 200),
        ]);
    }

    private function payloadReal(array $override = []): array
    {
        return array_merge([
            'tipo' => TipoAnulacionMh::RescindirOperacion->value,
            'confirmacion_invalidacion' => 'INVALIDAR DTE',
        ], $override);
    }

    public function test_modo_seguro_bloquea_y_no_hace_llamadas(): void
    {
        // setUp deja el mock activo → candados cerrados: aunque la frase sea correcta,
        // el servicio bloquea y no transmite nada.
        Http::fake();
        $nc = $this->ncAceptada();

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.transmitir', $nc), $this->payloadReal())
            ->assertRedirect(route('facturacion.show', $nc))
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $nc->refresh();
        $this->assertSame(EstadoDte::Aceptado, $nc->estado);
        $this->assertNull($nc->sello_invalidacion);
        $this->assertFalse($nc->tieneEventoInvalidacion());
    }

    public function test_frase_incorrecta_bloquea(): void
    {
        $this->abrirCandados();
        Http::fake();
        $nc = $this->ncAceptada();

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.transmitir', $nc), $this->payloadReal(['confirmacion_invalidacion' => 'INVALIDAR']))
            ->assertSessionHasErrors('confirmacion_invalidacion');

        Http::assertNothingSent();
        $nc->refresh();
        $this->assertSame(EstadoDte::Aceptado, $nc->estado);
        $this->assertFalse($nc->tieneEventoInvalidacion());
    }

    public function test_rol_sin_permiso_recibe_403(): void
    {
        $this->abrirCandados();
        Http::fake();
        $nc = $this->ncAceptada();

        foreach (['jefatura', 'facturacion', 'contabilidad'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->post(route('facturacion.invalidacion.transmitir', $nc), $this->payloadReal())
                ->assertForbidden();
        }

        Http::assertNothingSent();
    }

    public function test_aceptacion_simulada_cambia_aceptado_a_invalidado(): void
    {
        $this->abrirCandados();
        $this->fakeHttp([
            'estado' => 'PROCESADO',
            'selloRecibido' => 'SELLO-INVAL-REAL-XYZ',
            'descripcionMsg' => 'Recibido',
            'fhProcesamiento' => '01/07/2026 10:00:00',
        ]);
        $nc = $this->ncAceptada();
        $selloOriginal = $nc->sello_recepcion;

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.transmitir', $nc), $this->payloadReal())
            ->assertRedirect(route('facturacion.show', $nc))
            ->assertSessionHas('status');

        $nc->refresh();
        $this->assertSame(EstadoDte::Invalidado, $nc->estado);
        $this->assertSame('SELLO-INVAL-REAL-XYZ', $nc->sello_invalidacion);
        // El sello de recepción ORIGINAL del DTE queda intacto.
        $this->assertSame($selloOriginal, $nc->sello_recepcion);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'anulardte'));
    }

    public function test_rechazo_conserva_aceptado(): void
    {
        $this->abrirCandados();
        $this->fakeHttp([
            '_http' => 400,
            'estado' => 'RECHAZADO',
            'descripcionMsg' => 'Documento ya invalidado',
        ]);
        $nc = $this->ncAceptada();
        $selloOriginal = $nc->sello_recepcion;

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.transmitir', $nc), $this->payloadReal())
            ->assertRedirect(route('facturacion.show', $nc))
            ->assertSessionHas('error');

        $nc->refresh();
        $this->assertSame(EstadoDte::Aceptado, $nc->estado);       // estado conservado
        $this->assertNull($nc->sello_invalidacion);                 // rechazo no trae sello
        $this->assertSame($selloOriginal, $nc->sello_recepcion);    // evidencia original intacta
        $this->assertIsArray($nc->respuesta_mh_invalidacion);       // pero sí guarda el motivo
    }

    public function test_doble_invalidacion_bloqueada(): void
    {
        $this->abrirCandados();
        $this->fakeHttp([
            'estado' => 'PROCESADO', 'selloRecibido' => 'SELLO-INVAL-REAL-XYZ',
            'descripcionMsg' => 'ok', 'fhProcesamiento' => '01/07/2026 10:00:00',
        ]);
        $nc = $this->ncAceptada();

        // Primera invalidación real: acepta.
        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.transmitir', $nc), $this->payloadReal());
        $nc->refresh();
        $this->assertTrue($nc->tieneEventoInvalidacion());

        // Segunda: la policy la bloquea (ya tiene evento) → 403.
        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.transmitir', $nc), $this->payloadReal())
            ->assertForbidden();
    }

    public function test_evidencia_protegida_bloqueada(): void
    {
        $this->abrirCandados();
        Http::fake();
        $nc = $this->ncAceptada();
        // Protegido como evidencia (por número de control): la policy lo bloquea → 403.
        config()->set('dte.invalidacion.protegidos_numero_control', [$nc->numero_control]);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.transmitir', $nc->refresh()), $this->payloadReal())
            ->assertForbidden();

        Http::assertNothingSent();
        $nc->refresh();
        $this->assertSame(EstadoDte::Aceptado, $nc->estado);
    }

    public function test_documento_no_candidato_no_puede_transmitir(): void
    {
        // Una NC solo GENERADA (sin aceptación real) no es candidata: 403, sin importar flags.
        $this->abrirCandados();
        Http::fake();
        $nc = $this->ncAceptada(aceptada: false);

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.invalidacion.transmitir', $nc), $this->payloadReal())
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
