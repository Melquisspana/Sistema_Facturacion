<?php

namespace Tests\Feature\Ppq;

use App\Models\Cliente;
use App\Models\PpqSala;
use App\Services\Ppq\AlbaranParser;
use App\Support\PpqConciliacion;
use App\Support\Sala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * Precargar los nombres de una lista (16/11/2026) da lo mismo que pedirlos de a uno, en
     * dos consultas para toda la lista: el código guardado con o sin cero, solo las salas del
     * cliente de PPQ, la de id menor si dos tienen el mismo código, la sucursal antes que el
     * mapa, el mapa si la sucursal no resuelve y null si nada resuelve.
     */
    public function test_precargar_una_lista_da_los_mismos_nombres_en_dos_consultas(): void
    {
        $calleja = Cliente::factory()->create();
        config(['ppq.cliente_default_id' => $calleja->id]);
        $calleja->sucursales()->create(['nombre' => 'Sala de prueba sin cero', 'codigo' => '230']);
        $calleja->sucursales()->create(['nombre' => 'Sala de prueba con cero', 'codigo' => '0240']);
        $calleja->sucursales()->create(['nombre' => 'Sala de prueba primera', 'codigo' => '250']);
        $calleja->sucursales()->create(['nombre' => 'Sala de prueba segunda', 'codigo' => '0250']);
        $calleja->sucursales()->create(['nombre' => 'Sala de prueba fiscal', 'codigo' => '0260']);
        Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala de prueba de otro cliente', 'codigo' => '0270']);
        PpqSala::recordar('0260', 'Nombre del mapa que no se usa', 'manual');
        PpqSala::recordar('0270', 'Sala de prueba del mapa', 'manual');
        PpqSala::recordar('1280', 'Sala de prueba sin ceros', 'manual');
        $esperado = [
            '0230' => 'Sala de prueba sin cero', '0240' => 'Sala de prueba con cero', '0250' => 'Sala de prueba primera',
            '0260' => 'Sala de prueba fiscal', '0270' => 'Sala de prueba del mapa', '1280' => 'Sala de prueba sin ceros', '0999' => null,
        ];

        // Solo las de las tablas de nombres (los ajustes se leen aparte).
        $consultas = 0;
        DB::listen(function ($q) use (&$consultas) {
            $consultas += preg_match('/from ["`]?(cliente_sucursales|ppq_salas)["`]?/i', $q->sql);
        });
        Sala::precargar(['230', '0240', '0250', '0260', '270', '1280', '0999', null, '', '0230']);
        $this->assertSame(2, $consultas, 'Una consulta a las sucursales y otra al mapa, para toda la lista.');
        foreach ($esperado as $codigo => $nombre) {
            $this->assertSame($nombre, Sala::nombre((string) $codigo), "Sala {$codigo}");
        }
        $this->assertSame(2, $consultas, 'Después de precargar, nombre() no vuelve a consultar.');

        // De a uno, sin precargar: los mismos nombres.
        Sala::olvidarCache();
        foreach ($esperado as $codigo => $nombre) {
            $this->assertSame($nombre, Sala::nombre((string) $codigo), "Sala {$codigo}, de a una");
        }
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
        $parser = new AlbaranParser;
        $con = $parser->desdeTexto('ALBARAN AC01/0235/00/3837 SÚPER SELECTOS SANTO TOMAS TOTAL 116.46');
        $this->assertNotNull($con['nombre_sala']);
        $this->assertStringContainsStringIgnoringCase('SANTO TOMAS', $con['nombre_sala']);

        // Sin nombre en el texto (lo normal: va en el logo) -> null, sin romper el resto.
        $sin = $parser->desdeTexto('PRODUCTOS VARIOS TOTAL 100.00');
        $this->assertNull($sin['nombre_sala']);
    }
}
