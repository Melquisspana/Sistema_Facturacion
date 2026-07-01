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

    private const NC_SELLO = '2026A77BCED2A5C249999ECD1C51427B05A5ERRH'; // 40 chars

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');
        Http::fake(); // nada debe salir a la red desde la UI de invalidación

        config()->set('dte.invalidacion.mock', true);
        // Responsable/solicitante REALES (el schema los exige); vienen de config en la UI.
        config()->set('dte.invalidacion.responsable', ['nombre' => 'Melqui Administrador', 'tipo_doc' => '13', 'num_doc' => '040000000']);
        config()->set('dte.invalidacion.solicita', ['nombre' => 'Calleja CxP', 'tipo_doc' => '36', 'num_doc' => '06141101690011']);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    /** NC tipo 05 aceptada realmente por el MH (sello real + fecha de procesamiento). */
    private function ncAceptada(bool $aceptada = true): Dte
    {
        $empresa = Empresa::create([
            'razon_social' => 'Elsa Fidelina Hernández Cañas', 'nombre_comercial' => 'Dulces La Negrita',
            'nit' => '10132512610012', 'nrc' => '1014765', 'telefono' => '71276473',
            'correo' => 'dulceslanegrita@yahoo.com', 'ambiente' => '00', 'activo' => true,
        ]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);
        $cliente = Cliente::factory()->contribuyente()->create([
            'nombre' => 'Calleja, S.A. de C.V.', 'num_documento' => '0614-110169-001-1',
            'telefono' => '67652343', 'correo' => 'melquicedeespana@gmail.com',
        ]);

        return Dte::create([
            'tipo_dte' => TipoDte::NotaCredito->value,
            'estado' => $aceptada ? EstadoDte::Aceptado->value : EstadoDte::Generado->value,
            'ambiente' => '00',
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'cliente_id' => $cliente->id,
            'numero_control' => 'DTE-05-M001P001-000000000000020',
            'codigo_generacion' => '437F5D8B-A746-46E1-8A60-BF74C17FE309',
            'sello_recepcion' => $aceptada ? self::NC_SELLO : null,
            'respuesta_mh' => $aceptada ? ['estado' => 'PROCESADO', 'selloRecibido' => self::NC_SELLO] : null,
            'fecha_procesamiento_mh' => $aceptada ? '2026-06-30 22:48:44' : null,
            'fecha_emision' => '2026-06-30',
            'hora_emision' => '22:26:52',
        ]);
    }

    // --- Visibilidad del bloque ---

    public function test_gestor_ve_el_bloque_de_invalidacion_en_una_nc_aceptada(): void
    {
        $nc = $this->ncAceptada();

        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.show', $nc))
            ->assertOk()
            ->assertSee('Invalidación oficial (evento anulardte)')
            ->assertSee('Firmar invalidación (MOCK)')
            ->assertSee('NO SE TRANSMITE A HACIENDA DESDE LA WEB');
    }

    public function test_lector_no_ve_el_bloque_de_invalidacion(): void
    {
        $nc = $this->ncAceptada();

        $this->actingAs($this->usuario('consulta'))
            ->get(route('facturacion.show', $nc))
            ->assertOk()
            ->assertDontSee('Invalidación oficial (evento anulardte)')
            ->assertDontSee('Firmar invalidación (MOCK)');
    }

    // --- Dry-run visual (solo lectura) ---

    public function test_dry_run_no_persiste_ni_transmite(): void
    {
        $nc = $this->ncAceptada();

        $this->actingAs($this->usuario('facturacion'))
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
        $nc = $this->ncAceptada();
        $selloOriginal = $nc->sello_recepcion;

        $this->actingAs($this->usuario('facturacion'))
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

        $this->actingAs($this->usuario('facturacion'))
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

        $this->actingAs($this->usuario('facturacion'))
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

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.invalidacion.mock', $nc), ['tipo' => TipoAnulacionMh::Otro->value])
            ->assertSessionHasErrors('motivo');

        $nc->refresh();
        $this->assertFalse($nc->tieneEventoInvalidacion());
    }

    public function test_tipo_error_info_exige_codigo_de_reemplazo(): void
    {
        $nc = $this->ncAceptada();

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.invalidacion.mock', $nc), ['tipo' => TipoAnulacionMh::ErrorInformacion->value])
            ->assertSessionHasErrors('reemplazo');
    }

    // --- Candados (policy) ---

    public function test_no_se_puede_invalidar_una_nc_no_aceptada_realmente(): void
    {
        $nc = $this->ncAceptada(aceptada: false); // generado, sin sello real

        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.invalidacion.mock', $nc), ['tipo' => TipoAnulacionMh::RescindirOperacion->value])
            ->assertForbidden();
    }

    public function test_no_se_invalida_dos_veces(): void
    {
        $nc = $this->ncAceptada();
        // Primera invalidación mock.
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.invalidacion.mock', $nc), ['tipo' => TipoAnulacionMh::RescindirOperacion->value]);
        $nc->refresh();
        $this->assertTrue($nc->tieneEventoInvalidacion());

        // Segunda: bloqueada por policy (ya tiene evento).
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.invalidacion.mock', $nc), ['tipo' => TipoAnulacionMh::RescindirOperacion->value])
            ->assertForbidden();
    }

    public function test_lector_no_puede_dry_run_ni_mock(): void
    {
        $nc = $this->ncAceptada();

        $this->actingAs($this->usuario('consulta'))
            ->post(route('facturacion.invalidacion.dry-run', $nc), ['tipo' => TipoAnulacionMh::RescindirOperacion->value])
            ->assertForbidden();

        $this->actingAs($this->usuario('consulta'))
            ->post(route('facturacion.invalidacion.mock', $nc), ['tipo' => TipoAnulacionMh::RescindirOperacion->value])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    // --- Seguridad: no hay transmisión real desde la web ---

    public function test_no_existe_ruta_de_transmision_real_de_invalidacion(): void
    {
        $this->assertTrue(Route::has('facturacion.invalidacion.mock'));
        $this->assertTrue(Route::has('facturacion.invalidacion.dry-run'));
        $this->assertFalse(Route::has('facturacion.invalidacion.real'));
        $this->assertFalse(Route::has('facturacion.invalidacion.transmitir'));
    }
}
