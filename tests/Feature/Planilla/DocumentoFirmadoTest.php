<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Planilla\Planilla;
use App\Models\Planilla\PlanillaDocumento;
use App\Models\User;
use App\Services\Planilla\PrepararPlanilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Adjuntar un documento firmado a la planilla: o queda todo (archivo, registro y
 * evento) o no queda nada. Nunca un «adjuntado» sin archivo, ni un archivo sin dueño.
 */
class DocumentoFirmadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('planilla.enabled', true);
        config()->set('gastos.enabled', true);
        config()->set('asistencia.enabled', false);

        Storage::fake('local');
    }

    private function gestor(): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions(array_map(fn ($p) => $p->value, [
            PermisoSistema::PlanillaVer, PermisoSistema::PlanillaSalarios, PermisoSistema::PlanillaGestionar,
            PermisoSistema::PlanillaDocumentos, PermisoSistema::DashboardVer,
        ]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function planilla(User $u): Planilla
    {
        return app(PrepararPlanilla::class)->abrir($u, [
            'tipo_periodo' => 'quincenal', 'fecha' => '2026-09-10',
            'clase' => 'regular', 'moneda' => 'USD', 'fecha_pago' => '2026-09-16',
        ]);
    }

    private function subir(User $u, Planilla $planilla)
    {
        return $this->actingAs($u)->from(route('planilla.show', $planilla))
            ->post(route('planilla.documentos.store', $planilla), [
                'tipo' => 'hoja_firmada',
                'documento' => UploadedFile::fake()->create('hoja-firmada.pdf', 20, 'application/pdf'),
            ]);
    }

    private function eventos(): int
    {
        return DB::table('gastos_eventos')->where('accion', 'planilla_documento_adjuntado')->count();
    }

    public function test_adjuntar_guarda_el_archivo_el_registro_y_el_evento(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);

        $this->subir($u, $planilla)
            ->assertRedirect(route('planilla.show', $planilla))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('planilla.aviso', 'Documento firmado adjuntado.');

        $documento = PlanillaDocumento::sole();
        $this->assertSame($planilla->id, $documento->planilla_id);
        $this->assertSame('hoja-firmada.pdf', $documento->nombre);
        $this->assertStringStartsWith('planilla/firmados/', $documento->ruta);
        Storage::disk('local')->assertExists($documento->ruta);
        $this->assertSame(1, $this->eventos());
    }

    public function test_si_el_archivo_no_se_guarda_no_se_registra_nada_y_se_avisa(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);

        // El disco local no lanza excepción cuando no puede escribir: devuelve false.
        $disco = Mockery::mock(Storage::disk('local'))->makePartial();
        $disco->shouldReceive('putFileAs')->andReturn(false);
        Storage::set('local', $disco);

        $this->subir($u, $planilla)
            ->assertRedirect(route('planilla.show', $planilla))
            ->assertSessionHasErrors(['documento' => 'No se pudo guardar el archivo. No se registró nada.'])
            ->assertSessionMissing('planilla.aviso');

        $this->assertSame(0, PlanillaDocumento::count());
        $this->assertSame(0, $this->eventos());
    }

    public function test_si_falla_la_base_despues_de_guardar_no_queda_archivo_ni_registro(): void
    {
        $u = $this->gestor();
        $planilla = $this->planilla($u);

        // Falla al dejar el evento, o sea DESPUÉS de guardar el archivo y crear el registro.
        DB::listen(function ($consulta) {
            if (str_starts_with($consulta->sql, 'insert') && str_contains($consulta->sql, 'gastos_eventos')
                && in_array('planilla_documento_adjuntado', $consulta->bindings, true)) {
                throw new \RuntimeException('falla simulada de la base');
            }
        });

        $this->subir($u, $planilla)
            ->assertRedirect(route('planilla.show', $planilla))
            ->assertSessionHasErrors('documento')
            ->assertSessionMissing('planilla.aviso');

        $this->assertSame(0, PlanillaDocumento::count(), 'El registro se deshace junto con el evento.');
        $this->assertSame(0, $this->eventos());
        $this->assertSame([], Storage::disk('local')->allFiles('planilla/firmados'), 'No queda archivo sin dueño.');
    }
}
