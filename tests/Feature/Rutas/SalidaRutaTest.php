<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Salidas de ruta: alta con varios participantes y el ciclo de estados
 * planificada → en_curso → finalizada, con cancelación desde los dos primeros.
 */
class SalidaRutaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true, 'name' => 'Admin'])->assignRole('administrador');
    }

    /**
     * Una persona de CAMPO. Ya no es un `User`: desde que existe el catálogo de personal
     * operativo, quien sale a ruta no necesita —ni suele tener— cuenta en el sistema.
     */
    private function vendedor(string $nombre): PersonalRuta
    {
        return PersonalRuta::create(['nombre' => $nombre]);
    }

    private function ruta(string $nombre = 'San Miguel'): Ruta
    {
        return Ruta::create(['nombre' => $nombre]);
    }

    /** @param array<string, mixed> $extra */
    private function crearSalida(User $admin, Ruta $ruta, array $personal, array $extra = []): SalidaRuta
    {
        $this->actingAs($admin)
            ->post(route('rutas.salidas.store'), [
                'ruta_id' => $ruta->id,
                'fecha_inicio' => '2026-08-14',
                'fecha_fin_estimada' => '2026-08-16',
                'personal' => $personal,
            ] + $extra)
            ->assertRedirect();

        // firstOrFail y no sole(): hay pruebas que crean más de una salida.
        return SalidaRuta::latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------------- alta

    public function test_crea_una_salida_planificada_con_su_creador(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $carlos = $this->vendedor('Carlos');

        $salida = $this->crearSalida($admin, $ruta, [$carlos->id], ['observaciones' => 'Llevar talonarios']);

        $this->assertSame($ruta->id, $salida->ruta_id);
        // Nace planificada aunque la fecha sea hoy: iniciarla es un acto aparte.
        $this->assertSame(EstadoSalidaRuta::Planificada, $salida->estado);
        $this->assertSame($admin->id, $salida->created_by);
        $this->assertNull($salida->fecha_fin_real);
        $this->assertSame('Llevar talonarios', $salida->observaciones);
    }

    public function test_una_salida_puede_llevar_varios_participantes(): void
    {
        $admin = $this->admin();
        $carlos = $this->vendedor('Carlos');
        $jose = $this->vendedor('José');
        $ana = $this->vendedor('Ana');

        $salida = $this->crearSalida($admin, $this->ruta(), [$carlos->id, $jose->id, $ana->id]);

        $this->assertEqualsCanonicalizing(
            [$carlos->id, $jose->id, $ana->id],
            $salida->personal->pluck('id')->all(),
        );
    }

    public function test_exige_al_menos_un_participante(): void
    {
        $this->actingAs($this->admin())
            ->post(route('rutas.salidas.store'), [
                'ruta_id' => $this->ruta()->id,
                'fecha_inicio' => '2026-08-14',
            ])
            ->assertSessionHasErrors('personal');

        $this->assertSame(0, SalidaRuta::count());
    }

    public function test_no_acepta_una_ruta_desactivada(): void
    {
        $ruta = Ruta::create(['nombre' => 'Apagada', 'activa' => false]);

        $this->actingAs($this->admin())
            ->post(route('rutas.salidas.store'), [
                'ruta_id' => $ruta->id,
                'fecha_inicio' => '2026-08-14',
                'personal' => [$this->vendedor('Carlos')->id],
            ])
            ->assertSessionHasErrors('ruta_id');
    }

    public function test_el_regreso_estimado_no_puede_ser_anterior_a_la_salida(): void
    {
        $this->actingAs($this->admin())
            ->post(route('rutas.salidas.store'), [
                'ruta_id' => $this->ruta()->id,
                'fecha_inicio' => '2026-08-14',
                'fecha_fin_estimada' => '2026-08-10',
                'personal' => [$this->vendedor('Carlos')->id],
            ])
            ->assertSessionHasErrors('fecha_fin_estimada');
    }

    public function test_una_salida_puede_durar_varios_dias(): void
    {
        $salida = $this->crearSalida($this->admin(), $this->ruta(), [$this->vendedor('Carlos')->id]);

        $this->assertSame('2026-08-14', $salida->fecha_inicio->toDateString());
        $this->assertSame('2026-08-16', $salida->fecha_fin_estimada->toDateString());
        $this->assertStringContainsString('14', $salida->periodoLegible());
        $this->assertStringContainsString('16', $salida->periodoLegible());
    }

    // ------------------------------------------------------------ transiciones

    public function test_ciclo_completo_planificada_en_curso_finalizada(): void
    {
        Carbon::setTestNow('2026-08-16 18:00:00');

        $admin = $this->admin();
        $salida = $this->crearSalida($admin, $this->ruta(), [$this->vendedor('Carlos')->id]);

        $this->actingAs($admin)->patch(route('rutas.salidas.iniciar', $salida))->assertRedirect();
        $this->assertSame(EstadoSalidaRuta::EnCurso, $salida->refresh()->estado);

        $this->actingAs($admin)->patch(route('rutas.salidas.finalizar', $salida))->assertRedirect();
        $salida->refresh();
        $this->assertSame(EstadoSalidaRuta::Finalizada, $salida->estado);
        // Finalizar es lo único que escribe la fecha real de regreso.
        $this->assertSame('2026-08-16', $salida->fecha_fin_real->toDateString());

        Carbon::setTestNow();
    }

    public function test_no_se_puede_finalizar_una_salida_que_nunca_inicio(): void
    {
        $admin = $this->admin();
        $salida = $this->crearSalida($admin, $this->ruta(), [$this->vendedor('Carlos')->id]);

        $this->actingAs($admin)->patch(route('rutas.salidas.finalizar', $salida))
            ->assertRedirect()
            ->assertSessionHas('error');

        $salida->refresh();
        $this->assertSame(EstadoSalidaRuta::Planificada, $salida->estado);
        $this->assertNull($salida->fecha_fin_real);
    }

    public function test_una_salida_finalizada_no_vuelve_atras_ni_se_edita(): void
    {
        $admin = $this->admin();
        $salida = $this->crearSalida($admin, $this->ruta(), [$this->vendedor('Carlos')->id]);
        $this->actingAs($admin)->patch(route('rutas.salidas.iniciar', $salida));
        $this->actingAs($admin)->patch(route('rutas.salidas.finalizar', $salida));

        $this->actingAs($admin)->patch(route('rutas.salidas.iniciar', $salida))->assertSessionHas('error');
        $this->actingAs($admin)->patch(route('rutas.salidas.cancelar', $salida))->assertSessionHas('error');
        $this->actingAs($admin)->get(route('rutas.salidas.edit', $salida))->assertForbidden();

        $this->assertSame(EstadoSalidaRuta::Finalizada, $salida->refresh()->estado);
    }

    public function test_se_puede_cancelar_desde_planificada_y_desde_en_curso(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $carlos = $this->vendedor('Carlos');

        $planificada = $this->crearSalida($admin, $ruta, [$carlos->id]);
        $this->actingAs($admin)->patch(route('rutas.salidas.cancelar', $planificada));
        $this->assertSame(EstadoSalidaRuta::Cancelada, $planificada->refresh()->estado);

        $enCurso = $this->crearSalida($admin, $ruta, [$carlos->id]);
        $this->actingAs($admin)->patch(route('rutas.salidas.iniciar', $enCurso));
        $this->actingAs($admin)->patch(route('rutas.salidas.cancelar', $enCurso));
        $enCurso->refresh();
        $this->assertSame(EstadoSalidaRuta::Cancelada, $enCurso->estado);
        // Cancelar nunca inventa un regreso: la salida no terminó, se anuló.
        $this->assertNull($enCurso->fecha_fin_real);
    }

    // ------------------------------------------------------------------ edición

    public function test_editar_cambia_los_participantes(): void
    {
        $admin = $this->admin();
        $carlos = $this->vendedor('Carlos');
        $jose = $this->vendedor('José');
        $salida = $this->crearSalida($admin, $ruta = $this->ruta(), [$carlos->id]);

        $this->actingAs($admin)
            ->put(route('rutas.salidas.update', $salida), [
                'ruta_id' => $ruta->id,
                'fecha_inicio' => '2026-08-14',
                'personal' => [$jose->id],
            ])
            ->assertRedirect();

        $this->assertSame([$jose->id], $salida->refresh()->personal->pluck('id')->all());
    }

    // ---------------------------------------------------------------- auditoría

    public function test_los_cambios_de_estado_y_de_gente_quedan_auditados(): void
    {
        $admin = $this->admin();
        $carlos = $this->vendedor('Carlos');
        $jose = $this->vendedor('José');
        $salida = $this->crearSalida($admin, $ruta = $this->ruta(), [$carlos->id]);

        $this->actingAs($admin)->put(route('rutas.salidas.update', $salida), [
            'ruta_id' => $ruta->id,
            'fecha_inicio' => '2026-08-14',
            'personal' => [$carlos->id, $jose->id],
        ]);
        $this->actingAs($admin)->patch(route('rutas.salidas.iniciar', $salida));
        $this->actingAs($admin)->patch(route('rutas.salidas.finalizar', $salida));

        $descripciones = Activity::where('log_name', 'salida_ruta')->pluck('description');

        $this->assertContains('definió los participantes de la salida', $descripciones);
        $this->assertContains('cambió los participantes de la salida', $descripciones);
        $this->assertContains('inició la salida', $descripciones);
        $this->assertContains('finalizó la salida', $descripciones);

        $inicio = Activity::where('description', 'inició la salida')->sole();
        $this->assertSame('planificada', $inicio->properties['estado_anterior']);
        $this->assertSame('en_curso', $inicio->properties['estado_nuevo']);
    }

    public function test_editar_sin_cambiar_la_gente_no_ensucia_la_auditoria(): void
    {
        $admin = $this->admin();
        $carlos = $this->vendedor('Carlos');
        $salida = $this->crearSalida($admin, $ruta = $this->ruta(), [$carlos->id]);

        $this->actingAs($admin)->put(route('rutas.salidas.update', $salida), [
            'ruta_id' => $ruta->id,
            'fecha_inicio' => '2026-08-14',
            'personal' => [$carlos->id],
        ]);

        $this->assertSame(0, Activity::where('description', 'cambió los participantes de la salida')->count());
    }

    // ------------------------------------------------------------------ pantallas

    public function test_el_detalle_se_ve_con_su_encabezado(): void
    {
        $admin = $this->admin();
        $salida = $this->crearSalida($admin, $this->ruta('SAN MIGUEL'), [$this->vendedor('Carlos')->id, $this->vendedor('José')->id]);
        $this->actingAs($admin)->patch(route('rutas.salidas.iniciar', $salida));

        $this->actingAs($admin)->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->assertSee('SAN MIGUEL')
            ->assertSee('En curso')
            ->assertSee('Carlos')
            ->assertSee('José')
            // El resumen ya no es provisional: son datos reales. Una salida recién
            // iniciada no tiene documentos todavía, y eso es lo que dice.
            ->assertSee('Documentos de la salida')
            ->assertDontSee('Próximo bloque')
            ->assertSee('Esta salida todavía no tiene documentos.');
    }
}
