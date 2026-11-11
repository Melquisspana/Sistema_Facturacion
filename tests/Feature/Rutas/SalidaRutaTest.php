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
use Tests\Support\SalidaDePrueba;
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
        SalidaDePrueba::participantes($salida, $personal, null);

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

    /** El modelo cierra el viaje (lo usa el cierre solo): es lo único que escribe el regreso real. */
    public function test_finalizar_escribe_el_regreso_real_y_solo_desde_en_curso(): void
    {
        $admin = $this->admin();
        $salida = $this->crearSalida($admin, $this->ruta(), [$this->vendedor('Carlos')->id]);
        $this->assertSame(EstadoSalidaRuta::EnCurso, $salida->estado);

        Carbon::setTestNow('2026-08-16 18:00:00');
        $this->assertTrue($salida->finalizar());
        $salida->refresh();
        $this->assertSame(EstadoSalidaRuta::Finalizada, $salida->estado);
        $this->assertSame('2026-08-16', $salida->fecha_fin_real->toDateString());
        Carbon::setTestNow();

        // Cerrada, no vuelve atrás.
        $this->assertFalse($salida->cancelar());
        $this->actingAs($admin)->patch(route('rutas.salidas.cancelar', $salida))->assertSessionHas('error');
        $this->assertSame(EstadoSalidaRuta::Finalizada, $salida->refresh()->estado);

        // La que nunca salió no se finaliza.
        $planificada = SalidaRuta::create(['ruta_id' => $this->ruta('Oriente')->id, 'fecha_inicio' => '2026-08-14', 'estado' => EstadoSalidaRuta::Planificada]);
        $this->assertFalse($planificada->finalizar());
        $this->assertSame(EstadoSalidaRuta::Planificada, $planificada->refresh()->estado);
        $this->assertNull($planificada->fecha_fin_real);
    }

    /** Ya nadie arma ni empieza salidas (11/11/2026): la que hubiera quedado programada solo se cancela. */
    public function test_la_que_quedo_programada_ya_no_sale_y_la_oficina_la_cancela(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $carlos = $this->vendedor('Carlos');

        $this->travelTo('2026-08-13 18:00:00');
        $planificada = $this->crearSalida($admin, $ruta, [$carlos->id]);
        $this->assertSame(EstadoSalidaRuta::Planificada, $planificada->estado);
        $this->travelTo('2026-08-14 07:00:00');
        $this->actingAs($admin)->get(route('rutas.salidas.show', $planificada))->assertOk()
            ->assertDontSee('Salir ahora')->assertSee('Quedó programada y ya no sale')->assertSee('>Cancelar</button>', false);
        $this->patch('/rutas/salidas/'.$planificada->id.'/iniciar')->assertNotFound();
        $this->assertSame(EstadoSalidaRuta::Planificada, $planificada->refresh()->estado);

        $this->patch(route('rutas.salidas.cancelar', $planificada))->assertSessionHas('status');
        $this->assertSame(EstadoSalidaRuta::Cancelada, $planificada->refresh()->estado);

        // La que está en camino también se cancela, y cancelar nunca inventa un regreso.
        $enCurso = $this->crearSalida($admin, $ruta, [$carlos->id]);
        $this->assertSame(EstadoSalidaRuta::EnCurso, $enCurso->estado);
        $this->patch(route('rutas.salidas.cancelar', $enCurso));
        $enCurso->refresh();
        $this->assertSame(EstadoSalidaRuta::Cancelada, $enCurso->estado);
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

        $this->actingAs($admin)->patch(route('rutas.salidas.cancelar', $salida));

        $descripciones = Activity::where('log_name', 'salida_ruta')->pluck('description');

        $this->assertContains('canceló la salida', $descripciones);

        $fin = Activity::where('description', 'canceló la salida')->sole();
        $this->assertSame($admin->id, $fin->causer_id);
        $this->assertSame('en_curso', $fin->properties['estado_anterior']);
        $this->assertSame('cancelada', $fin->properties['estado_nuevo']);
    }

    // ------------------------------------------------------------------ pantallas

    public function test_el_detalle_se_ve_con_su_encabezado(): void
    {
        $admin = $this->admin();
        $salida = $this->crearSalida($admin, $this->ruta('SAN MIGUEL'), [$this->vendedor('Carlos')->id, $this->vendedor('José')->id]);

        $this->actingAs($admin)->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->assertSee('SAN MIGUEL')
            ->assertSee('Abierta')
            ->assertSee('Carlos')
            ->assertSee('José')
            // La custodia del papel se retiró (26/09/2026).
            ->assertDontSee('documentación física')
            ->assertDontSee('Marcar documentación recibida');
    }
}
