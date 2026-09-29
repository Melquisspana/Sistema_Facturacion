<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Models\Cliente;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Rutas\RitmoRutas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El tablero «¿a dónde toca ir?»: días desde la última salida contra los días objetivo.
 */
class RitmoRutasTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = CarbonImmutable::parse('2026-09-26');
    }

    private function salida(Ruta $ruta, string $inicio, EstadoSalidaRuta $estado, ?string $regreso = null): SalidaRuta
    {
        return SalidaRuta::create([
            'ruta_id' => $ruta->id,
            'fecha_inicio' => $inicio,
            'fecha_fin_real' => $regreso,
            'estado' => $estado,
        ]);
    }

    /** @return array<string, array> fila por nombre de ruta */
    private function filas(): array
    {
        return (new RitmoRutas)->porRuta($this->hoy)->keyBy(fn ($f) => $f['ruta']->nombre)->all();
    }

    public function test_el_semaforo_compara_los_dias_con_el_objetivo(): void
    {
        $atrasada = Ruta::create(['nombre' => 'San Miguel', 'frecuencia_objetivo_dias' => 15]);
        $pronto = Ruta::create(['nombre' => 'Sonsonate', 'frecuencia_objetivo_dias' => 15]);
        $alDia = Ruta::create(['nombre' => 'Santa Ana', 'frecuencia_objetivo_dias' => 15]);

        $this->salida($atrasada, '2026-09-08', EstadoSalidaRuta::Finalizada, '2026-09-09'); // 17 días
        $this->salida($pronto, '2026-09-13', EstadoSalidaRuta::Finalizada, '2026-09-14');   // 12 días
        $this->salida($alDia, '2026-09-20', EstadoSalidaRuta::Finalizada, '2026-09-21');    // 5 días

        $filas = $this->filas();

        $this->assertSame([RitmoRutas::ATRASADA, 17], [$filas['San Miguel']['estado'], $filas['San Miguel']['dias']]);
        $this->assertSame([RitmoRutas::PRONTO, 12], [$filas['Sonsonate']['estado'], $filas['Sonsonate']['dias']]);
        $this->assertSame([RitmoRutas::AL_DIA, 5], [$filas['Santa Ana']['estado'], $filas['Santa Ana']['dias']]);
    }

    /** Se cuenta desde el regreso: una salida de tres días no «envejece» mientras dura. */
    public function test_los_dias_se_cuentan_desde_el_regreso_o_desde_el_inicio_si_no_hay_regreso(): void
    {
        $conRegreso = Ruta::create(['nombre' => 'San Miguel']);
        $sinRegreso = Ruta::create(['nombre' => 'Usulután']);

        $this->salida($conRegreso, '2026-09-20', EstadoSalidaRuta::Finalizada, '2026-09-23');
        $this->salida($sinRegreso, '2026-09-20', EstadoSalidaRuta::Finalizada);

        $filas = $this->filas();

        $this->assertSame(3, $filas['San Miguel']['dias']);
        $this->assertSame(6, $filas['Usulután']['dias']);
        // Sin objetivo: se informan los días, sin semáforo.
        $this->assertSame(RitmoRutas::SIN_OBJETIVO, $filas['San Miguel']['estado']);
    }

    public function test_en_ruta_sin_salidas_y_canceladas(): void
    {
        $enRuta = Ruta::create(['nombre' => 'San Miguel', 'frecuencia_objetivo_dias' => 15]);
        $nunca = Ruta::create(['nombre' => 'Chalatenango', 'frecuencia_objetivo_dias' => 15]);

        $this->salida($enRuta, '2026-09-01', EstadoSalidaRuta::Finalizada, '2026-09-02');
        $this->salida($enRuta, '2026-09-25', EstadoSalidaRuta::EnCurso);
        // Una cancelada no cuenta como visita: nadie fue.
        $this->salida($nunca, '2026-09-20', EstadoSalidaRuta::Cancelada);
        $planificada = $this->salida($nunca, '2026-09-29', EstadoSalidaRuta::Planificada);

        $filas = $this->filas();

        $this->assertSame(RitmoRutas::EN_RUTA, $filas['San Miguel']['estado']);
        $this->assertSame(RitmoRutas::SIN_SALIDAS, $filas['Chalatenango']['estado']);
        $this->assertNull($filas['Chalatenango']['ultima']);
        $this->assertSame($planificada->id, $filas['Chalatenango']['proxima']?->id);
    }

    public function test_lo_atrasado_va_primero_y_las_rutas_inactivas_no_aparecen(): void
    {
        $alDia = Ruta::create(['nombre' => 'A al día', 'frecuencia_objetivo_dias' => 30]);
        $atrasada = Ruta::create(['nombre' => 'Z atrasada', 'frecuencia_objetivo_dias' => 7]);
        Ruta::create(['nombre' => 'Inactiva', 'activa' => false]);

        $this->salida($alDia, '2026-09-20', EstadoSalidaRuta::Finalizada, '2026-09-20');
        $this->salida($atrasada, '2026-09-01', EstadoSalidaRuta::Finalizada, '2026-09-01');

        $nombres = (new RitmoRutas)->porRuta($this->hoy)->map(fn ($f) => $f['ruta']->nombre)->all();

        $this->assertSame(['Z atrasada', 'A al día'], $nombres);
    }

    public function test_el_tablero_muestra_las_rutas_y_las_salas_sin_ruta(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel', 'frecuencia_objetivo_dias' => 15]);
        $this->salida($ruta, now()->subDays(20)->toDateString(), EstadoSalidaRuta::Finalizada, now()->subDays(20)->toDateString());
        Cliente::factory()->create()->sucursales()->create(['nombre' => 'Selectos Soyapango', 'activo' => true]);

        $this->actingAs(User::factory()->create()->assignRole('administrador'))
            ->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSee('San Miguel')
            ->assertSee('Toca ir')
            ->assertSee('5 días tarde')
            ->assertSee('cada 15 días')
            // Sin vendedores no se puede salir: se dice qué falta.
            ->assertSee('Agregá un vendedor para salir')
            ->assertSee('1 sala sin ruta.');

        PersonalRuta::create(['nombre' => 'Carlos']);

        $this->actingAs(User::factory()->create()->assignRole('administrador'))
            ->get(route('rutas.dashboard'))
            ->assertSee('Salir a esta ruta')
            ->assertSee('Carlos');
    }
}
