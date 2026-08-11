<?php

namespace Tests\Feature\Ppq;

use App\Exceptions\Ppq\AlbaranDadoDeBajaException;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\PpqAlbaran;
use App\Models\PpqSala;
use App\Services\Ppq\AlbaranPersistidor;
use App\Services\Ppq\SalaSucursalResolver;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Servicio único de persistencia de albaranes: identidad/idempotencia, autocorrección de
 * monto y resolución de sala (cliente_sucursales primero, ppq_salas como fallback, y
 * excepción cuando no resuelve ninguna). Nunca crea sucursales.
 *
 * La sala se resuelve SOLO por registrarConSala() — la vía de la sincronización automática.
 * registrar(), que es la del alta desde la pantalla de PPQ, la deja intacta.
 */
class AlbaranPersistidorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Support\Sala::olvidarCache(); // el caché estático no debe filtrar entre tests
        $this->seed(DatosInicialesNegritaSeeder::class);
    }

    private function persistidor(): AlbaranPersistidor
    {
        return app(AlbaranPersistidor::class);
    }

    private function calleja(): Cliente
    {
        return Cliente::where('nombre', 'like', '%Calleja%')->firstOrFail();
    }

    /** Crea una sala de Calleja con código y acota el módulo a ese cliente. */
    private function sala(string $codigo, string $nombre): ClienteSucursal
    {
        $calleja = $this->calleja();
        config(['ppq.cliente_default_id' => $calleja->id]);

        return $calleja->sucursales()->create(['nombre' => $nombre, 'codigo' => $codigo]);
    }

    // ------------------------------------------------------------ identidad

    public function test_registrar_dos_veces_el_mismo_numero_y_oc_no_duplica(): void
    {
        $datos = [
            'numero_albaran' => 'AC01/0236/00/6359',
            'numero_orden_compra' => '26060236004586',
            'monto_albaran' => 250.75,
            'fecha_albaran' => '14/07/2026',
        ];

        $primero = $this->persistidor()->registrar($datos, 'gmail');
        $segundo = $this->persistidor()->registrar($datos, 'gmail');

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame(1, PpqAlbaran::count());
        // La fecha d/m/Y se normaliza (no cae en la lectura americana m/d).
        $this->assertSame('2026-07-14', $primero->refresh()->fecha_albaran->toDateString());
    }

    public function test_numero_sucio_se_normaliza_antes_de_identificar(): void
    {
        // El mismo albarán escrito con espacios y con el "/año" pegado es UNO solo.
        $this->persistidor()->registrar([
            'numero_albaran' => 'AC01/0236 /00 /6359', 'numero_orden_compra' => 'OC-1',
        ], 'gmail');
        $this->persistidor()->registrar([
            'numero_albaran' => 'AC01/0236/00/6359/26', 'numero_orden_compra' => 'OC-1',
        ], 'gmail');

        $this->assertSame(1, PpqAlbaran::count());
        $this->assertSame('AC01/0236/00/6359', PpqAlbaran::sole()->numero_albaran);
    }

    public function test_autocorrige_monto_null_pero_no_pisa_uno_bueno(): void
    {
        $sinMonto = PpqAlbaran::create([
            'numero_albaran' => 'AC01/0230/00/2878', 'numero_orden_compra' => '26050230001794',
            'monto_albaran' => null, 'origen' => 'gmail',
        ]);

        $this->persistidor()->registrar([
            'numero_albaran' => 'AC01/0230/00/2878', 'numero_orden_compra' => '26050230001794',
            'monto_albaran' => 138.87,
        ], 'gmail');
        $this->assertSame('138.87', (string) $sinMonto->refresh()->monto_albaran);

        // Un reparseo posterior con otro valor NO lo pisa.
        $this->persistidor()->registrar([
            'numero_albaran' => 'AC01/0230/00/2878', 'numero_orden_compra' => '26050230001794',
            'monto_albaran' => 999.99,
        ], 'gmail');
        $this->assertSame('138.87', (string) $sinMonto->refresh()->monto_albaran);
    }

    // --------------------------------------------------------- sala: fuentes

    public function test_sala_se_resuelve_por_codigo_de_cliente_sucursales(): void
    {
        $sucursal = $this->sala('0236', 'Súper Selectos Santa Elena');

        $albaran = $this->persistidor()->registrarConSala([
            'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => '26060236004586',
        ], 'gmail');

        $this->assertSame('0236', $albaran->sala_codigo);
        $this->assertSame($sucursal->id, $albaran->cliente_sucursal_id);
    }

    public function test_codigo_guardado_sin_cero_inicial_igual_resuelve(): void
    {
        $sucursal = $this->sala('230', 'Súper Selectos La Unión');

        $albaran = $this->persistidor()->registrarConSala([
            'numero_albaran' => 'AC01/0230/00/2878', 'numero_orden_compra' => '26050230001794',
        ], 'gmail');

        $this->assertSame($sucursal->id, $albaran->cliente_sucursal_id);
    }

    public function test_ppq_salas_es_solo_fallback_y_no_da_sucursal_fiscal(): void
    {
        // La sala NO está en cliente_sucursales pero sí en el mapa auxiliar de PPQ:
        // se conoce el nombre, pero NO hay vínculo fiscal (cliente_sucursal_id null).
        PpqSala::recordar('0260', 'Súper Selectos Escalón', 'ccf_json');

        $albaran = $this->persistidor()->registrarConSala([
            'numero_albaran' => 'AC01/0260/00/1111', 'numero_orden_compra' => '2606026002401',
        ], 'gmail');

        $this->assertSame('0260', $albaran->sala_codigo);
        $this->assertNull($albaran->cliente_sucursal_id);

        $resolucion = app(SalaSucursalResolver::class)->resolver('0260');
        $this->assertSame(SalaSucursalResolver::FUENTE_MAPA, $resolucion['fuente']);
        $this->assertSame('Súper Selectos Escalón', $resolucion['nombre']);
        $this->assertFalse($resolucion['excepcion']); // el fallback resolvió: no es excepción
    }

    public function test_cliente_sucursales_gana_sobre_ppq_salas(): void
    {
        $sucursal = $this->sala('0236', 'Nombre Fiscal');
        PpqSala::recordar('0236', 'Nombre Del Mapa', 'ccf_json');

        $resolucion = app(SalaSucursalResolver::class)->resolver('0236');

        $this->assertSame(SalaSucursalResolver::FUENTE_SUCURSAL, $resolucion['fuente']);
        $this->assertSame('Nombre Fiscal', $resolucion['nombre']);
        $this->assertSame($sucursal->id, $resolucion['cliente_sucursal_id']);
    }

    // ------------------------------------------------------ sala: excepción

    public function test_sala_desconocida_se_persiste_como_excepcion_sin_crear_sucursal(): void
    {
        $sucursalesAntes = ClienteSucursal::count();

        $albaran = $this->persistidor()->registrarConSala([
            'numero_albaran' => 'AC01/0999/00/7777', 'numero_orden_compra' => '26060999001234',
        ], 'gmail');

        // Se guarda igual, con el código tal cual y sin vínculo fiscal.
        $this->assertSame('0999', $albaran->sala_codigo);
        $this->assertNull($albaran->cliente_sucursal_id);

        // NUNCA crea la sucursal.
        $this->assertSame($sucursalesAntes, ClienteSucursal::count());
        $this->assertTrue(app(SalaSucursalResolver::class)->esExcepcion('0999'));

        // Y queda listable como excepción.
        $this->assertTrue(PpqAlbaran::salaSinResolver()->whereKey($albaran->id)->exists());
    }

    public function test_scope_sala_sin_resolver_excluye_las_resueltas(): void
    {
        $this->sala('0236', 'Con sucursal');
        PpqSala::recordar('0260', 'Solo en el mapa', 'ccf_json');

        $conSucursal = $this->persistidor()->registrarConSala(['numero_albaran' => 'AC01/0236/00/1', 'numero_orden_compra' => '26060236000001'], 'gmail');
        $soloMapa = $this->persistidor()->registrarConSala(['numero_albaran' => 'AC01/0260/00/2', 'numero_orden_compra' => '26060260000002'], 'gmail');
        $desconocida = $this->persistidor()->registrarConSala(['numero_albaran' => 'AC01/0999/00/3', 'numero_orden_compra' => '26060999000003'], 'gmail');

        $excepciones = PpqAlbaran::salaSinResolver()->pluck('id')->all();

        $this->assertSame([$desconocida->id], $excepciones);
        $this->assertNotContains($conSucursal->id, $excepciones);
        $this->assertNotContains($soloMapa->id, $excepciones); // el mapa lo resolvió
    }

    public function test_sala_sale_del_numero_de_albaran_cuando_no_hay_oc(): void
    {
        // Sin OC parseable, la sala se deriva del 2º segmento del número de Calleja.
        $albaran = $this->persistidor()->registrarConSala([
            'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => null,
        ], 'gmail');

        $this->assertSame('0236', $albaran->sala_codigo);
    }

    public function test_no_pisa_un_vinculo_de_sucursal_ya_establecido(): void
    {
        $corregida = $this->sala('0111', 'Corregida a mano');
        $otra = $this->sala('0236', 'La del código');

        // Alguien ya corrigió el vínculo a mano hacia otra sucursal.
        $existente = PpqAlbaran::create([
            'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => '26060236004586',
            'origen' => 'gmail', 'cliente_sucursal_id' => $corregida->id,
        ]);

        $this->persistidor()->registrarConSala([
            'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => '26060236004586',
        ], 'gmail');

        $this->assertSame($corregida->id, $existente->refresh()->cliente_sucursal_id);
        $this->assertNotSame($otra->id, $existente->cliente_sucursal_id);
    }

    // ------------------------------------------------------ dado de baja

    public function test_registrar_un_albaran_dado_de_baja_lanza_error_controlado(): void
    {
        $borrado = $this->persistidor()->registrar([
            'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => '26060236004586',
        ], 'manual');
        $borrado->delete();

        try {
            $this->persistidor()->registrar([
                'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => '26060236004586',
            ], 'manual');
            $this->fail('Se esperaba AlbaranDadoDeBajaException.');
        } catch (AlbaranDadoDeBajaException $e) {
            $this->assertSame($borrado->id, $e->albaranId);
            $this->assertStringContainsString('dado de baja', $e->getMessage());
        }

        // Ni se resucitó ni se insertó un duplicado.
        $this->assertSame(0, PpqAlbaran::count());
        $this->assertSame(1, PpqAlbaran::onlyTrashed()->count());
        $this->assertTrue($borrado->refresh()->trashed());
    }

    public function test_la_via_automatica_tambien_corta_ante_un_albaran_dado_de_baja(): void
    {
        $this->persistidor()->registrar([
            'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => '26060236004586',
        ], 'manual')->delete();

        $this->expectException(AlbaranDadoDeBajaException::class);

        $this->persistidor()->registrarConSala([
            'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => '26060236004586',
        ], 'gmail');
    }

    public function test_una_baja_con_oc_nula_tambien_se_detecta(): void
    {
        // Con OC nula el índice único no protege (los NULL no colisionan en SQL), así que
        // el guard tiene que encontrarlo igual por la consulta.
        $this->persistidor()->registrar(['numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => null], 'manual')->delete();

        $this->expectException(AlbaranDadoDeBajaException::class);

        $this->persistidor()->registrar(['numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => null], 'manual');
    }

    public function test_una_baja_de_otra_oc_no_bloquea_el_alta(): void
    {
        // La identidad es número + OC: una baja de OTRA OC no debe frenar este alta.
        $this->persistidor()->registrar(['numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => 'OC-A'], 'manual')->delete();

        $nuevo = $this->persistidor()->registrar(['numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => 'OC-B'], 'manual');

        $this->assertTrue($nuevo->exists);
        $this->assertSame(1, PpqAlbaran::count());
    }

    // ------------------------------------------------- sala: solo la automática

    public function test_el_alta_manual_no_vincula_la_sucursal_aunque_exista(): void
    {
        // La sucursal existe y el código sale limpio de la OC: la automática la
        // vincularía. Por registrar() (la vía de la pantalla) el vínculo FISCAL no se toca.
        $sucursal = $this->sala('0236', 'Súper Selectos Santa Elena');

        $albaran = $this->persistidor()->registrar([
            'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => '26060236004586',
        ], 'manual');

        $this->assertNull($albaran->cliente_sucursal_id);
        $this->assertDatabaseHas('ppq_albaranes', [
            'id' => $albaran->id, 'cliente_sucursal_id' => null,
        ]);

        // La sucursal sigue sin usarse: no se vinculó por la puerta de atrás.
        $this->assertSame(0, PpqAlbaran::where('cliente_sucursal_id', $sucursal->id)->count());

        // `sala_codigo` sí queda, pero NO lo puso el persistidor: lo deriva de la OC el
        // hook `saving` del modelo, que es independiente de esta vía.
        $this->assertSame('0236', $albaran->sala_codigo);
    }

    public function test_sin_oc_el_alta_manual_ni_siquiera_deriva_el_codigo_de_sala(): void
    {
        // Sin OC el hook del modelo no tiene de dónde derivar, y el fallback por número de
        // albarán vive en el persistidor: es exclusivo de la vía automática. La fila queda
        // sin sala de ningún tipo.
        $this->sala('0236', 'Súper Selectos Santa Elena');

        $albaran = $this->persistidor()->registrar([
            'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => null,
        ], 'manual');

        $this->assertNull($albaran->sala_codigo);
        $this->assertNull($albaran->cliente_sucursal_id);
    }

    public function test_el_alta_desde_la_pantalla_tampoco_vincula_con_origen_gmail(): void
    {
        // `origen` describe de dónde salieron los datos, NO la vía: la pantalla registra
        // con 'gmail' cuando el albarán vino de un correo. La sucursal igual queda sin
        // vincular, porque lo que decide es el método llamado.
        $this->sala('0236', 'Súper Selectos Santa Elena');

        $albaran = $this->persistidor()->registrar([
            'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => '26060236004586',
        ], 'gmail');

        $this->assertNull($albaran->cliente_sucursal_id);
    }

    public function test_el_alta_manual_no_completa_la_sucursal_de_un_albaran_ya_existente(): void
    {
        $this->sala('0236', 'Súper Selectos Santa Elena');

        $existente = PpqAlbaran::create([
            'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => '26060236004586',
            'monto_albaran' => null, 'origen' => 'gmail',
        ]);

        // Reusar la fila desde la pantalla sí autocorrige el monto, pero no la sucursal.
        $this->persistidor()->registrar([
            'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => '26060236004586',
            'monto_albaran' => 100.00,
        ], 'manual');

        $existente->refresh();
        $this->assertSame('100.00', (string) $existente->monto_albaran);
        $this->assertNull($existente->cliente_sucursal_id);
    }

    public function test_lo_que_dejo_el_alta_manual_lo_completa_despues_la_sincronizacion(): void
    {
        // Las dos vías conviven sobre la MISMA fila: la pantalla la crea sin sala y la
        // corrida automática posterior la completa, sin duplicar.
        $sucursal = $this->sala('0236', 'Súper Selectos Santa Elena');

        $manual = $this->persistidor()->registrar([
            'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => '26060236004586',
        ], 'manual');
        $this->assertNull($manual->cliente_sucursal_id);

        $sincronizado = $this->persistidor()->registrarConSala([
            'numero_albaran' => 'AC01/0236/00/6359', 'numero_orden_compra' => '26060236004586',
        ], 'gmail');

        $this->assertSame($manual->id, $sincronizado->id);
        $this->assertSame(1, PpqAlbaran::count());
        $this->assertSame('0236', $manual->refresh()->sala_codigo);
        $this->assertSame($sucursal->id, $manual->cliente_sucursal_id);
    }
}
