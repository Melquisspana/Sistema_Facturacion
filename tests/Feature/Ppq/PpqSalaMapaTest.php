<?php

namespace Tests\Feature\Ppq;

use App\Models\PpqSala;
use App\Services\Ppq\AlbaranParser;
use App\Support\PpqConciliacion;
use App\Support\Sala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre las mejoras de PPQ del 13/07: mapa auxiliar ppq_salas, distinción de
 * "albarán sin monto" vs "sin albarán", y el aviso de albarán de otra sala.
 */
class PpqSalaMapaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sala::olvidarCache();
    }

    public function test_estado_distingue_albaran_sin_monto_de_sin_albaran(): void
    {
        // Sin albarán vinculado y sin monto -> "Sin albarán".
        $this->assertSame('sin_albaran', PpqConciliacion::estado(100, null, false)['key']);
        // Con albarán vinculado pero sin monto capturado -> NO es "sin albarán".
        $this->assertSame('albaran_sin_monto', PpqConciliacion::estado(100, null, true)['key']);
        // Con monto: la clasificación de siempre.
        $this->assertSame('coincide', PpqConciliacion::estado(100, 100, true)['key']);
        $this->assertSame('posible_nc', PpqConciliacion::estado(195.24, 191.52, true)['key']);
    }

    public function test_sala_mismatch_detecta_albaran_de_otra_sala(): void
    {
        $mm = PpqConciliacion::salaMismatch('0256', '0228');
        $this->assertNotNull($mm);
        $this->assertSame('0256', $mm['sala_doc']);
        $this->assertSame('0228', $mm['sala_albaran']);

        // Misma sala (con y sin cero) -> sin aviso.
        $this->assertNull(PpqConciliacion::salaMismatch('0230', '0230'));
        $this->assertNull(PpqConciliacion::salaMismatch('230', '0230'));
        // Falta un dato -> sin aviso.
        $this->assertNull(PpqConciliacion::salaMismatch('0256', null));
    }

    public function test_estado_lote_suma_otra_sala_y_albaran_sin_monto(): void
    {
        // Albarán de otra sala cuenta como alerta ROJA (posible equivocación).
        $este = PpqConciliacion::estadoLote(0, 0, 0, 1);
        $this->assertTrue($este['alerta']);
        $this->assertStringContainsString('otra sala', $este['motivo']);

        // Albarán sin monto es incompleto (ámbar), no rojo.
        $inc = PpqConciliacion::estadoLote(0, 0, 1, 0);
        $this->assertTrue($inc['alerta']);
        $this->assertStringContainsString('sin monto', $inc['motivo']);

        // Todo cuadra.
        $this->assertFalse(PpqConciliacion::estadoLote(0, 0, 0, 0)['alerta']);
    }

    public function test_mapa_ppq_salas_resuelve_nombre_por_codigo(): void
    {
        PpqSala::recordar('0260', 'Super selectos Olocuilta la estacion', 'ccf_json');
        Sala::olvidarCache();

        // Directo y por Sala::nombre (fallback tras cliente_sucursales).
        $this->assertSame('Super selectos Olocuilta la estacion', PpqSala::nombre('0260'));
        $this->assertSame('Super selectos Olocuilta la estacion', Sala::nombre('0260'));
        // Normaliza el código (con/sin cero).
        $this->assertSame('Super selectos Olocuilta la estacion', PpqSala::nombre('260'));
        // Código desconocido -> null (no inventa).
        $this->assertNull(PpqSala::nombre('0999'));
    }

    public function test_nombre_manual_no_es_pisado_por_fuente_automatica(): void
    {
        PpqSala::recordar('0300', 'Nombre confirmado a mano', 'manual');
        PpqSala::recordar('0300', 'Nombre automatico', 'ccf_json');
        Sala::olvidarCache();

        $this->assertSame('Nombre confirmado a mano', PpqSala::nombre('0300'));
        // No duplica: un solo registro por código.
        $this->assertSame(1, PpqSala::where('codigo', '0300')->count());
    }

    public function test_albaran_parser_extrae_nombre_de_sala_si_esta_en_el_texto(): void
    {
        $parser = new AlbaranParser();
        $con = $parser->desdeTexto('ALBARAN AC01/0235/00/3837 SÚPER SELECTOS SANTO TOMAS TOTAL 116.46');
        $this->assertNotNull($con['nombre_sala']);
        $this->assertStringContainsStringIgnoringCase('SANTO TOMAS', $con['nombre_sala']);

        // Sin nombre en el texto (lo normal: va en el logo) -> null, sin romper el resto.
        $sin = $parser->desdeTexto('PRODUCTOS VARIOS TOTAL 100.00');
        $this->assertNull($sin['nombre_sala']);
    }
}
