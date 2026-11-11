<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Rutas\ParticipantesSalida;
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

    protected function setUp(): void
    {
        parent::setUp();

        // Las salidas de estas pruebas salen el 14/08/2026: se arman ese mismo día, porque
        // desde el 03/11/2026 no se arma una salida para un día pasado.
        $this->travelTo('2026-08-14 09:00:00');
    }

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
        // Una salida armada a mano de antes: desde el 10/11/2026 no hay pantalla que las cree
        // (el viaje se abre solo). Para un día que viene quedaba planificada.
        $salida = SalidaRuta::create([
            'ruta_id' => $ruta->id,
            'fecha_inicio' => '2026-08-14',
            'fecha_fin_estimada' => '2026-08-16',
            'estado' => now()->toDateString() < '2026-08-14' ? EstadoSalidaRuta::Planificada : EstadoSalidaRuta::EnCurso,
            'created_by' => $admin->id,
        ] + $extra);
        app(ParticipantesSalida::class)->sincronizar($salida, $personal, null);

        return $salida;
    }

    // ------------------------------------------------------------------- alta

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
        $this->travelTo('2026-08-13 18:00:00');

        $admin = $this->admin();
        // Armada la víspera: queda planificada hasta el 14/08.
        $salida = $this->crearSalida($admin, $this->ruta(), [$this->vendedor('Carlos')->id]);
        $this->assertSame(EstadoSalidaRuta::Planificada, $salida->estado);

        // Sale el 14/08, no antes: ese día se inicia.
        $this->travelTo('2026-08-14 07:00:00');
        $this->actingAs($admin)->patch(route('rutas.salidas.iniciar', $salida))->assertRedirect();
        $this->assertSame(EstadoSalidaRuta::EnCurso, $salida->refresh()->estado);

        Carbon::setTestNow('2026-08-16 18:00:00');
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
        // Las planificadas ya no se crean desde la pantalla, pero las viejas existen.
        $salida = SalidaRuta::create(['ruta_id' => $this->ruta()->id, 'fecha_inicio' => '2026-08-14', 'estado' => EstadoSalidaRuta::Planificada]);

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
        // La pantalla de editar se retiró: el enlace viejo lleva a la hoja.
        $this->actingAs($admin)->get('/rutas/salidas/'.$salida->id.'/editar')->assertRedirect(route('rutas.salidas.show', $salida));

        $this->assertSame(EstadoSalidaRuta::Finalizada, $salida->refresh()->estado);
    }

    public function test_se_puede_cancelar_desde_planificada_y_desde_en_curso(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $carlos = $this->vendedor('Carlos');

        $this->travelTo('2026-08-13 18:00:00');
        $planificada = $this->crearSalida($admin, $ruta, [$carlos->id]);
        $this->assertSame(EstadoSalidaRuta::Planificada, $planificada->estado);
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

    // ---------------------------------------------------------------- auditoría

    public function test_los_cambios_de_estado_y_de_gente_quedan_auditados(): void
    {
        $admin = $this->admin();
        $carlos = $this->vendedor('Carlos');
        $jose = $this->vendedor('José');
        $salida = $this->crearSalida($admin, $this->ruta(), [$carlos->id, $jose->id]);

        $this->actingAs($admin)->patch(route('rutas.salidas.finalizar', $salida));

        $descripciones = Activity::where('log_name', 'salida_ruta')->pluck('description');

        $this->assertContains('finalizó la salida', $descripciones);

        $fin = Activity::where('description', 'finalizó la salida')->sole();
        $this->assertSame('en_curso', $fin->properties['estado_anterior']);
        $this->assertSame('finalizada', $fin->properties['estado_nuevo']);
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
            // La custodia del papel se retiró (26/09/2026).
            ->assertDontSee('documentación física')
            ->assertDontSee('Marcar documentación recibida');
    }
}
