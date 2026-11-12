<?php

namespace Tests\Feature\Dte;

use App\Models\Dte;
use App\Support\Dte\CandadoEmisionDte;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * EL CANDADO DE EMISIÓN DURA MÁS QUE EL PEDIDO MÁS LARGO.
 *
 * El candado no se renueva. Si durara menos que un ciclo de emisión, vencería a mitad y
 * un segundo pedido entraría con el primero todavía hablando con Hacienda. Los tiempos de
 * espera y la cantidad de reenvíos se configuran, así que la duración sale de ellos.
 *
 * Peor ciclo: revisar el firmador + firmar + pedir el token + (envío y consulta) por cada
 * intento + sonda de internet + volver a firmar en contingencia.
 */
class DuracionCandadoEmisionTest extends TestCase
{
    private function peorCiclo(int $firma, int $hacienda, int $reenvios): int
    {
        return 3 * $firma + $hacienda * (2 * $reenvios + 3) + 3;
    }

    public function test_con_la_configuracion_de_fabrica_cubre_el_doble_del_peor_ciclo(): void
    {
        // 10 s el firmador, 8 s Hacienda, 2 reenvíos: 3×10 + 8×7 + 3 = 89 s.
        $this->assertSame(89, $this->peorCiclo(10, 8, 2));
        $this->assertGreaterThanOrEqual(2 * 89, CandadoEmisionDte::segundos());
        $this->assertSame(180, CandadoEmisionDte::segundos());
    }

    public function test_con_esperas_mas_largas_y_mas_reenvios_el_candado_dura_mas(): void
    {
        config(['dte.firma.timeout' => 20, 'dte.transmision.timeout' => 30, 'dte.transmision.reintentos.max_reenvios' => 5]);

        // 3×20 + 30×13 + 3 = 453 s: con 180 fijos el candado vencía a mitad del ciclo.
        $this->assertSame(453, $this->peorCiclo(20, 30, 5));
        $this->assertGreaterThanOrEqual(2 * 453, CandadoEmisionDte::segundos());
    }

    public function test_nunca_dura_menos_que_el_piso(): void
    {
        config(['dte.firma.timeout' => 1, 'dte.transmision.timeout' => 1, 'dte.transmision.reintentos.max_reenvios' => 0]);

        $this->assertSame(180, CandadoEmisionDte::segundos());
    }

    public function test_el_candado_se_toma_con_esa_duracion(): void
    {
        config(['dte.firma.timeout' => 20, 'dte.transmision.timeout' => 30, 'dte.transmision.reintentos.max_reenvios' => 5]);
        $dte = new class extends Dte
        {
            public function refresh()
            {
                return $this; // no hace falta la base: solo se mira el candado
            }
        };
        $dte->id = 987654;

        $vence = CandadoEmisionDte::ejecutar($dte, fn () => Cache::getStore()->locks[CandadoEmisionDte::clave($dte)]['expiresAt']);

        $this->assertGreaterThanOrEqual(CandadoEmisionDte::segundos() - 2, now()->diffInSeconds($vence, false));
    }
}
