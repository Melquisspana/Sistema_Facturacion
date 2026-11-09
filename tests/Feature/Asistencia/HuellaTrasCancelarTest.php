<?php

namespace Tests\Feature\Asistencia;

use App\Enums\Asistencia\EstadoOrdenEnrolamiento;
use App\Enums\Asistencia\MotivoFalloEnrolamiento;
use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\Asistencia\AsistenciaHuella;
use App\Models\Asistencia\AsistenciaOrdenEnrolamiento;
use App\Models\User;
use App\Services\Asistencia\CrearOrdenEnrolamiento;
use Database\Factories\Asistencia\AsistenciaDispositivoFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Issue #84: cancelar desde la web mientras el lector ya captura. El lector graba
 * igual y su «completado» llega sobre una orden cancelada: no se crea la huella
 * (la orden ya terminó), pero queda anotado que el sensor tiene esa plantilla, y la
 * ficha ofrece anotarla a la persona.
 */
class HuellaTrasCancelarTest extends TestCase
{
    use RefreshDatabase;

    private AsistenciaDispositivo $lector;

    private AsistenciaEmpleado $ana;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('asistencia.enabled', true);

        $this->lector = AsistenciaDispositivo::factory()->create(['codigo' => 'lector-prueba', 'nombre' => 'Lector de prueba']);
        $this->lector->sincronizarIndice(162, []);
        $this->ana = AsistenciaEmpleado::factory()->create(['nombres' => 'Ana', 'apellidos' => 'Prueba']);
    }

    private function gestor(): User
    {
        return User::factory()->create(['activo' => true])
            ->givePermissionTo([PermisoSistema::AsistenciaVer->value, PermisoSistema::AsistenciaGestionar->value]);
    }

    private function comoElLector(): static
    {
        return $this->withHeaders([
            'X-Dispositivo' => $this->lector->codigo,
            'X-Dispositivo-Token' => AsistenciaDispositivoFactory::TOKEN_DE_PRUEBA,
        ]);
    }

    /** El lector se lleva la orden y alguien la cancela desde la ficha mientras captura. */
    private function cancelarMientrasCaptura(): array
    {
        $orden = app(CrearOrdenEnrolamiento::class)($this->ana, $this->lector->refresh());
        $token = $this->comoElLector()->getJson('/api/asistencia/enrolamiento/pendiente')->json('orden.token');

        $this->actingAs($this->gestor())
            ->delete(route('asistencia.empleados.enrolamiento.destroy', [$this->ana, $orden]))
            ->assertSessionHas('status');

        return [$orden->refresh(), $token];
    }

    private function informarGrabada(AsistenciaOrdenEnrolamiento $orden, string $token, int $ranura): TestResponse
    {
        return $this->comoElLector()->postJson("/api/asistencia/enrolamiento/{$orden->id}/resultado", [
            'token' => $token, 'exito' => true, 'fingerprint_id' => $ranura,
        ]);
    }

    public function test_si_el_lector_graba_tras_cancelar_queda_anotado_sin_crear_la_huella(): void
    {
        [$orden, $token] = $this->cancelarMientrasCaptura();

        $primera = $this->informarGrabada($orden, $token, $orden->ranura_reservada)
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('estado', 'cancelada')
            ->assertJsonPath('motivo', 'grabada_tras_cancelar');

        // La orden sigue cancelada y no nace ninguna asignación.
        $orden->refresh();
        $this->assertSame(EstadoOrdenEnrolamiento::Cancelada, $orden->estado);
        $this->assertSame(MotivoFalloEnrolamiento::GrabadaTrasCancelar, $orden->motivo_fallo);
        $this->assertSame(0, AsistenciaHuella::count());

        // El sensor tiene esa plantilla: la próxima elección ya no la toma por libre.
        $this->assertContains($orden->ranura_reservada, $this->lector->refresh()->ranurasOcupadasEnSensor());
        $beto = AsistenciaEmpleado::factory()->create();
        $otra = app(CrearOrdenEnrolamiento::class)($beto, $this->lector);
        $this->assertNotSame($orden->ranura_reservada, $otra->ranura_reservada);

        // Idempotente: el reintento del lector da el mismo desenlace.
        $this->assertSame($primera->json(), $this->informarGrabada($orden, $token, $orden->ranura_reservada)->json());
    }

    /** La ficha lo avisa y ofrece anotarla; al anotarla nace la huella y el aviso se va. */
    public function test_la_ficha_ofrece_anotar_la_huella_grabada_tras_cancelar(): void
    {
        [$orden, $token] = $this->cancelarMientrasCaptura();
        $this->informarGrabada($orden, $token, $orden->ranura_reservada)->assertOk();
        $ficha = route('personal.empleados.show', 'a'.$this->ana->id);
        $gestor = $this->gestor();

        $html = $this->actingAs($gestor)->get($ficha)->assertOk()
            ->assertSee('quedó sin dueño')
            ->assertSee('Anotar esta ranura')
            ->assertSee('<input type="hidden" name="fingerprint_id" value="'.$orden->ranura_reservada.'">', false)
            ->assertSee('<input type="hidden" name="asistencia_dispositivo_id" value="'.$this->lector->id.'">', false)
            ->getContent();
        // Lo técnico sigue en «Avanzado», que se abre solo para que el aviso se vea.
        $this->assertStringNotContainsString('ranura', mb_strtolower(preg_replace('/<details\b[^>]*>.*?<\/details>/si', '', $html)));
        $this->assertMatchesRegularExpression('/<details\b[^>]*\bopen\b/i', $html);

        $this->actingAs($gestor)->post(route('asistencia.empleados.huellas.store', $this->ana), [
            'asistencia_dispositivo_id' => $this->lector->id,
            'fingerprint_id' => $orden->ranura_reservada,
        ])->assertSessionHas('status');

        $huella = AsistenciaHuella::sole();
        $this->assertSame($this->ana->id, $huella->asistencia_empleado_id);
        $this->assertSame($orden->ranura_reservada, $huella->fingerprint_id);
        $this->actingAs($gestor)->get($ficha)->assertOk()->assertDontSee('Anotar esta ranura')->assertDontSee('quedó sin dueño');
    }

    /** Quien solo mira no ve el botón: anotar una huella es gestionar. */
    public function test_sin_permiso_de_gestionar_no_se_ofrece_anotarla(): void
    {
        [$orden, $token] = $this->cancelarMientrasCaptura();
        $this->informarGrabada($orden, $token, $orden->ranura_reservada)->assertOk();
        $mirona = User::factory()->create(['activo' => true])->givePermissionTo(PermisoSistema::AsistenciaVer->value);

        $this->actingAs($mirona)->get(route('personal.empleados.show', 'a'.$this->ana->id))->assertOk()
            ->assertDontSee('Anotar esta ranura');
    }

    /** Si la borran en el lector, el aviso se va cuando el lector vuelve a informar lo que tiene. */
    public function test_el_aviso_se_va_cuando_el_lector_informa_que_ya_no_la_tiene(): void
    {
        [$orden, $token] = $this->cancelarMientrasCaptura();
        $this->informarGrabada($orden, $token, $orden->ranura_reservada)->assertOk();

        $this->comoElLector()->postJson('/api/asistencia/enrolamiento/indice-sensor', ['capacidad' => 162, 'ocupadas' => []])->assertOk();

        $this->actingAs($this->gestor())->get(route('personal.empleados.show', 'a'.$this->ana->id))->assertOk()
            ->assertDontSee('Anotar esta ranura');
        $this->assertSame(0, AsistenciaHuella::count());
    }

    /** Si dice haber grabado en otra ranura no se le cree: no se anota nada. */
    public function test_tras_cancelar_una_ranura_distinta_de_la_reservada_no_se_anota(): void
    {
        [$orden, $token] = $this->cancelarMientrasCaptura();

        $this->informarGrabada($orden, $token, 77)->assertOk()->assertJsonPath('motivo', 'cancelada_por_operador');

        $this->assertSame(MotivoFalloEnrolamiento::CanceladaPorOperador, $orden->refresh()->motivo_fallo);
        $this->assertSame([], $this->lector->refresh()->ranurasOcupadasEnSensor());
    }
}
