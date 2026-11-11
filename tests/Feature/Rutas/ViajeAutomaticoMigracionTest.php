<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\PropositoSalida;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Revertir la migración del viaje automático: la columna `automatica` se borra, así que antes
 * se anota en `observaciones` cuáles eran viajes. Sin eso, tras un rollback quedarían como
 * salidas armadas a mano. Datos ficticios.
 */
class ViajeAutomaticoMigracionTest extends TestCase
{
    use RefreshDatabase;

    private const NOTA = 'Era un viaje que se abrió solo (automático), no una salida armada a mano. Anotado al revertir la migración del viaje.';

    private function migracion(): object
    {
        return require database_path('migrations/2026_11_10_200000_viaje_automatico_en_salidas_ruta.php');
    }

    public function test_al_revertir_los_viajes_quedan_anotados_en_observaciones(): void
    {
        $ruta = Ruta::create(['nombre' => 'Zona de prueba']);
        $base = ['ruta_id' => $ruta->id, 'fecha_inicio' => '2026-11-10', 'estado' => EstadoSalidaRuta::EnCurso];
        $viaje = SalidaRuta::create($base + ['proposito' => PropositoSalida::Ambos, 'automatica' => true, 'suma_pedidos' => true]);
        $viajeConNota = SalidaRuta::create($base + ['proposito' => PropositoSalida::Ambos, 'automatica' => true, 'observaciones' => 'Nota de la oficina']);
        $deEntregar = SalidaRuta::create($base + ['proposito' => PropositoSalida::Entregar, 'automatica' => true, 'observaciones' => SalidaRuta::OBSERVACION_AUTOMATICA]);
        $aMano = SalidaRuta::create($base + ['proposito' => PropositoSalida::Entregar, 'observaciones' => 'Armada a mano']);
        $aManoSinNota = SalidaRuta::create($base + ['proposito' => PropositoSalida::Entregar]);

        $this->migracion()->down();

        try {
            $this->assertFalse(Schema::hasColumn('salidas_ruta', 'automatica'));
            $observaciones = DB::table('salidas_ruta')->pluck('observaciones', 'id');
            $this->assertSame(self::NOTA, $observaciones[$viaje->id]);
            $this->assertSame("Nota de la oficina\n".self::NOTA, $observaciones[$viajeConNota->id]);
            // La de «Por entregar» ya lo dice con su observación de siempre: no se toca.
            $this->assertSame(SalidaRuta::OBSERVACION_AUTOMATICA, $observaciones[$deEntregar->id]);
            // Las armadas a mano quedan como estaban.
            $this->assertSame('Armada a mano', $observaciones[$aMano->id]);
            $this->assertNull($observaciones[$aManoSinNota->id]);
        } finally {
            $this->migracion()->up();
        }
        $this->assertTrue(Schema::hasColumn('salidas_ruta', 'automatica'));
    }
}
