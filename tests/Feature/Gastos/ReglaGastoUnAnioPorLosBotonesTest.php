<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Ocurrencia;
use App\Models\Gastos\Pago;
use App\Models\Gastos\Regla;
use App\Models\User;
use App\Services\Gastos\PanelGastos;
use App\Services\Gastos\SaldosGastos;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Una regla mensual a lo largo de medio año, como la usa la oficina y todo por los
 * botones: se pausa en marzo, se reanuda en junio, se salta julio, se cambia el monto en
 * agosto y se cancela. Y una regla personal en manos de quien no ve lo personal.
 * Datos ficticios.
 */
class ReglaGastoUnAnioPorLosBotonesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config(['gastos.enabled' => true]);
    }

    /** @param  list<PermisoSistema>  $permisos */
    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions(array_map(fn (PermisoSistema $p) => $p->value, $permisos));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function en(string $fecha): void
    {
        $this->travelTo(CarbonImmutable::parse($fecha.' 10:00'));
    }

    /** @return array<string, mixed> */
    private function datosRegla(User $u, array $extra = []): array
    {
        return array_replace([
            'clave' => (string) Str::uuid(), 'nombre' => 'Alquiler de prueba', 'beneficiario' => 'Proveedor de prueba',
            'concepto' => 'Alquiler mensual', 'categoria' => 'Alquiler', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'documentacion' => 'pendiente', 'responsable_id' => $u->id, 'monto_modo' => 'fijo',
            'importe' => '400.00', 'frecuencia' => 'mensual', 'dia_mes' => 5, 'dias_generar_antes' => 0,
            'vigente_desde' => '2026-03-01',
        ], $extra);
    }

    /** @return array<string, string> período => importe de la obligación que generó */
    private function generados(Regla $regla): array
    {
        return Ocurrencia::where('regla_id', $regla->id)->where('estado', 'generada')->orderBy('periodo')->get()
            ->mapWithKeys(fn (Ocurrencia $o) => [$o->periodo => (string) Gasto::findOrFail($o->gasto_id)->importe])->all();
    }

    public function test_pausada_en_marzo_reanudada_en_junio_salta_julio_cambia_en_agosto_y_se_cancela(): void
    {
        $u = $this->usuario([PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar, PermisoSistema::GastosRecurrencias, PermisoSistema::GastosPagosRegistrar]);
        $this->en('2026-02-20');
        $datos = $this->datosRegla($u);
        $this->actingAs($u)->post(route('gastos.reglas.store'), $datos)->assertSessionHasNoErrors();
        $regla = Regla::where('clave', $datos['clave'])->sole();

        // Marzo se genera; el 10 se pausa.
        $this->en('2026-03-05');
        $this->actingAs($u)->post(route('gastos.reglas.generar', $regla))->assertSessionHasNoErrors();
        $this->en('2026-03-10');
        $this->actingAs($u)->post(route('gastos.reglas.pausar', $regla), ['motivo' => 'El local cierra por remodelación.'])->assertSessionHasNoErrors();

        // En abril, pausada, el botón no genera nada.
        $this->en('2026-04-05');
        $this->actingAs($u)->post(route('gastos.reglas.generar', $regla))->assertSessionHasErrors('regla');

        // Se reanuda el 1 de junio. El 5, junio sí; abril y mayo no nacen de golpe como deuda.
        $this->en('2026-06-01');
        $this->actingAs($u)->post(route('gastos.reglas.reanudar', $regla), ['motivo' => 'Volvimos al local.'])->assertSessionHasNoErrors();
        $this->en('2026-06-05');
        $this->actingAs($u)->post(route('gastos.reglas.generar', $regla))->assertSessionHasNoErrors()
            ->assertSessionHas('gastos.aviso', fn ($aviso) => str_contains($aviso, '2026-04, 2026-05'));
        // Doble clic: nada nuevo.
        $this->actingAs($u)->post(route('gastos.reglas.generar', $regla))->assertSessionHasNoErrors()
            ->assertSessionHas('gastos.aviso', fn ($aviso) => str_starts_with($aviso, 'No había ningún período pendiente'));
        $this->assertSame(['2026-03' => '400.00', '2026-06' => '400.00'], $this->generados($regla));
        // Abril y mayo no se pierden de vista: quedan para que alguien decida si se deben.
        $this->assertSame(['2026-04', '2026-05'], app(PanelGastos::class)
            ->fechasPasadasSinConfirmar($u, [], CarbonImmutable::now())->where('regla_id', $regla->id)->pluck('periodo')->all());

        // Se salta julio (con doble clic): una sola omisión, y julio no se genera.
        $this->en('2026-06-20');
        foreach ([1, 2] as $vez) {
            $this->actingAs($u)->post(route('gastos.reglas.omitir', $regla), ['periodo' => '2026-07', 'motivo' => 'Julio lo cubre el propietario.'])
                ->assertSessionHasNoErrors();
        }
        $this->assertSame(1, Ocurrencia::where('regla_id', $regla->id)->where('estado', 'omitida')->count());
        $this->en('2026-07-05');
        $this->actingAs($u)->post(route('gastos.reglas.generar', $regla))->assertSessionHasNoErrors();
        $this->assertSame(['2026-03' => '400.00', '2026-06' => '400.00'], $this->generados($regla));
        // Ni pagándolo desde el panel: julio se saltó a propósito.
        $this->actingAs($u)->post(route('gastos.panel.programado.pagar', $regla), [
            'clave' => (string) Str::uuid(), 'periodo' => '2026-07', 'fecha' => '2026-07-05', 'metodo' => 'transferencia',
        ])->assertSessionHasErrors('periodo');
        $this->assertSame(0, Pago::count());

        // Sube en agosto: rige de ahí en adelante, lo de antes queda como estaba.
        $this->en('2026-07-20');
        $this->actingAs($u)->put(route('gastos.reglas.update', $regla), $this->datosRegla($u, [
            'clave' => $regla->clave, 'importe' => '450.00', 'motivo' => 'Sube el alquiler desde agosto.',
        ]))->assertSessionHasNoErrors();
        $this->en('2026-08-05');
        $this->actingAs($u)->post(route('gastos.reglas.generar', $regla))->assertSessionHasNoErrors();
        $this->assertSame(['2026-03' => '400.00', '2026-06' => '400.00', '2026-08' => '450.00'], $this->generados($regla));

        // Se cancela: no genera más, ni por el botón ni por la tarea; lo generado se sigue debiendo.
        $this->en('2026-08-10');
        $this->actingAs($u)->post(route('gastos.reglas.cancelar', $regla), ['motivo' => 'Se terminó el contrato.'])->assertSessionHasNoErrors();
        $this->en('2026-09-05');
        $this->actingAs($u)->post(route('gastos.reglas.generar', $regla))->assertSessionHasErrors('regla');
        config(['gastos.recurrencias.generacion_automatica' => true]);
        $this->artisan('gastos:generar-recurrentes --aplicar --hoy=2026-09-05')->assertSuccessful();

        $this->assertSame(['2026-03' => '400.00', '2026-06' => '400.00', '2026-08' => '450.00'], $this->generados($regla));
        $saldos = app(SaldosGastos::class);
        $this->assertSame([40000, 40000, 45000], Gasto::orderBy('id')->get()
            ->map(fn (Gasto $g) => $saldos->resumen($g, '2026-09-05')['pendiente'])->all());
        $this->assertSame(0, Pago::count());
    }

    /** Quien no tiene `gastos.personales` no ve ni toca una regla personal por ningún botón. */
    public function test_una_regla_personal_no_se_toca_sin_alcance_personal(): void
    {
        $dueno = $this->usuario([
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar, PermisoSistema::GastosPersonales, PermisoSistema::GastosRecurrencias,
        ]);
        $this->en('2026-03-05');
        $datos = $this->datosRegla($dueno, ['nombre' => 'Cuota personal de prueba', 'ambito' => 'personal', 'persona' => 'Persona de prueba']);
        $this->actingAs($dueno)->post(route('gastos.reglas.store'), $datos)->assertSessionHasNoErrors();
        $regla = Regla::where('clave', $datos['clave'])->sole();
        $this->actingAs($dueno)->post(route('gastos.reglas.generar', $regla))->assertSessionHasNoErrors();
        $this->actingAs($dueno)->post(route('gastos.reglas.pausar', $regla), ['motivo' => 'Pausa de prueba.'])->assertSessionHasNoErrors();
        $foto = fn () => [$regla->fresh()->only(['estado', 'importe', 'version']), Ocurrencia::count(), Gasto::count(), Pago::count()];
        $antes = $foto();

        $empresa = $this->usuario([PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar, PermisoSistema::GastosRecurrencias, PermisoSistema::GastosPagosRegistrar]);
        $this->en('2026-04-05');
        $this->actingAs($empresa);
        $this->get(route('gastos.reglas.index'))->assertOk()->assertDontSee('Cuota personal de prueba');
        $this->get(route('gastos.reglas.show', $regla))->assertForbidden();
        $this->get(route('gastos.reglas.edit', $regla))->assertForbidden();
        $this->post(route('gastos.reglas.reanudar', $regla), ['motivo' => 'Reanudar sin alcance.'])->assertForbidden();
        $this->post(route('gastos.reglas.cancelar', $regla), ['motivo' => 'Cancelar sin alcance.'])->assertForbidden();
        $this->post(route('gastos.reglas.omitir', $regla), ['periodo' => '2026-04', 'motivo' => 'Saltar sin alcance.'])->assertForbidden();
        $this->post(route('gastos.reglas.generar', $regla))->assertForbidden();
        $this->post(route('gastos.reglas.activar', $regla), ['dia_mes' => 6])->assertForbidden();
        $this->post(route('gastos.panel.programado.pagar', $regla), [
            'clave' => (string) Str::uuid(), 'periodo' => '2026-03', 'fecha' => '2026-04-05', 'metodo' => 'transferencia',
        ])->assertForbidden();

        $this->assertSame($antes, $foto(), 'Nada cambió.');
    }
}
