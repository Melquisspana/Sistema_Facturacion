<?php

namespace Tests\Feature\Dte;

use App\Enums\TipoCliente;
use App\Models\ActividadEconomica;
use App\Models\CatalogoMh;
use App\Models\Cliente;
use App\Services\Importacion\ImportadorCatalogosMh;
use App\Services\Importacion\SincronizadorActividadesEconomicas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sincroniza actividades_economicas (CAT-019, usado por Cliente.actividad_economica_id)
 * desde catalogos_mh (cat='019'), ya cargado con el catálogo oficial completo del
 * MH (774 códigos). Usa el Excel oficial REAL del proyecto (mismo que
 * CatalogosMhImportTest) como fuente de verdad, no fixtures inventados.
 */
class SincronizadorActividadesEconomicasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Carga el catálogo oficial REAL (774 filas CAT-019 entre otros) en catalogos_mh.
        app(ImportadorCatalogosMh::class)->importar();
    }

    private function sincronizador(): SincronizadorActividadesEconomicas
    {
        return app(SincronizadorActividadesEconomicas::class);
    }

    // ---------- 1-2: dry-run vs apply ----------

    public function test_dry_run_no_escribe(): void
    {
        $r = $this->sincronizador()->sincronizar(aplicar: false);

        $this->assertSame(774, $r['total_fuente']);
        $this->assertGreaterThan(0, $r['nuevos']);
        $this->assertFalse($r['aplicado']);
        $this->assertSame(0, ActividadEconomica::count());
    }

    public function test_apply_inserta_codigos_nuevos(): void
    {
        $r = $this->sincronizador()->sincronizar(aplicar: true);

        $this->assertSame(774, $r['total_fuente']);
        $this->assertSame(774, ActividadEconomica::count());
        $this->assertTrue($r['aplicado']);
        $this->assertTrue(ActividadEconomica::where('codigo', '46301')->exists());
        $this->assertTrue(ActividadEconomica::where('codigo', '46371')->exists());
    }

    // ---------- 3-4: actualiza descripción, conserva ID ----------

    public function test_actualiza_descripcion_conservando_el_id(): void
    {
        $existente = ActividadEconomica::create(['codigo' => '46900', 'nombre' => 'DESCRIPCION DESACTUALIZADA', 'activo' => true]);
        $idOriginal = $existente->id;
        $oficial = CatalogoMh::where('cat', '019')->where('codigo', '46900')->value('valor');

        $r = $this->sincronizador()->sincronizar(aplicar: true);

        $existente->refresh();
        $this->assertSame($idOriginal, $existente->id, 'El ID no debe cambiar al actualizar.');
        $this->assertSame($oficial, $existente->nombre);
        $this->assertGreaterThanOrEqual(1, $r['actualizados']);
    }

    // ---------- 5: no elimina actividades ----------

    public function test_no_elimina_actividades_que_no_esten_en_la_fuente(): void
    {
        // Código de prueba garantizado ausente del catálogo oficial real.
        $ajena = ActividadEconomica::create(['codigo' => '99999-TEST', 'nombre' => 'Actividad de prueba ajena al catálogo', 'activo' => true]);

        $this->sincronizador()->sincronizar(aplicar: true);

        $this->assertNotNull(ActividadEconomica::find($ajena->id));
        $this->assertSame('Actividad de prueba ajena al catálogo', $ajena->fresh()->nombre);
    }

    // ---------- 6: no duplica códigos ----------

    public function test_no_duplica_codigos_ya_existentes(): void
    {
        ActividadEconomica::create(['codigo' => '46900', 'nombre' => 'Antigua', 'activo' => true]);

        $this->sincronizador()->sincronizar(aplicar: true);

        $this->assertSame(1, ActividadEconomica::where('codigo', '46900')->count());
    }

    // ---------- 7-8: fuente inválida (todo o nada) ----------

    public function test_rechaza_fuente_con_codigo_duplicado(): void
    {
        CatalogoMh::create(['cat' => '019', 'codigo' => '46301', 'valor' => 'Duplicado a propósito']);

        $r = $this->sincronizador()->sincronizar(aplicar: true);

        $this->assertGreaterThan(0, $r['duplicados']);
        $this->assertFalse($r['aplicado']);
        $this->assertSame(0, ActividadEconomica::count());
    }

    public function test_rechaza_codigo_o_descripcion_vacios(): void
    {
        CatalogoMh::create(['cat' => '019', 'codigo' => '', 'valor' => 'Sin código']);

        $r = $this->sincronizador()->sincronizar(aplicar: true);

        $this->assertGreaterThan(0, $r['invalidos']);
        $this->assertFalse($r['aplicado']);
        $this->assertSame(0, ActividadEconomica::count());
    }

    // ---------- 9: rollback completo si una fila falla ----------

    public function test_rollback_completo_si_una_fila_falla(): void
    {
        // Simula un fallo real a mitad de la sincronización (p. ej. un error de BD
        // en la fila 3) mediante el evento 'creating' de Eloquent, sin depender de
        // que SQLite imponga límites de columna (no lo hace, a diferencia de MySQL).
        $intentos = 0;
        ActividadEconomica::creating(function () use (&$intentos) {
            $intentos++;
            if ($intentos === 3) {
                throw new \RuntimeException('Fallo simulado a mitad de la sincronización.');
            }
        });

        try {
            $this->sincronizador()->sincronizar(aplicar: true);
            $this->fail('Debió propagar la excepción simulada.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fallo simulado a mitad de la sincronización.', $e->getMessage());
        }

        $this->assertSame(0, ActividadEconomica::count(), 'Rollback completo: no debe quedar ninguna fila a medias.');
    }

    // ---------- 10: solo procesa CAT-019 ----------

    public function test_solo_procesa_cat_019(): void
    {
        $totalCat014 = CatalogoMh::where('cat', '014')->count();
        $this->assertGreaterThan(0, $totalCat014, 'Precondición: el Excel real trae CAT-014.');

        $r = $this->sincronizador()->sincronizar(aplicar: true);

        $this->assertSame(774, $r['total_fuente']);
        // Ninguna actividad económica debe coincidir con códigos de unidad de medida
        // que no sean también códigos CAT-019 válidos (verificación indirecta: el
        // total sincronizado es exactamente el de CAT-019, no CAT-019 + CAT-014).
        $this->assertSame(774, ActividadEconomica::count());
    }

    // ---------- 11: clientes existentes conservan sus relaciones ----------

    public function test_clientes_existentes_conservan_su_relacion(): void
    {
        $actividad = ActividadEconomica::create(['codigo' => '46900', 'nombre' => 'Antigua', 'activo' => true]);
        $cliente = Cliente::factory()->exportacion()->create(['actividad_economica_id' => $actividad->id]);

        $this->sincronizador()->sincronizar(aplicar: true);

        $cliente->refresh();
        $this->assertSame($actividad->id, $cliente->actividad_economica_id);
        $this->assertSame('46900', $cliente->actividadEconomica->codigo);
    }

    // ---------- 12: el serializador FEX sigue resolviendo código y descripción ----------

    public function test_serializador_fex_resuelve_codigo_y_descripcion_tras_sincronizar(): void
    {
        $this->sincronizador()->sincronizar(aplicar: true);

        $actividad46371 = ActividadEconomica::where('codigo', '46371')->first();
        $cliente = Cliente::factory()->exportacion()->create(['actividad_economica_id' => $actividad46371->id]);

        // Misma resolución que usa MapeaCatalogosMh::descActividad(): código del
        // Cliente -> catalogos_mh (cat=019) -> descripción oficial.
        $codigo = $cliente->actividadEconomica->codigo;
        $descripcionOficial = CatalogoMh::where('cat', '019')->where('codigo', $codigo)->value('valor');

        $this->assertSame('46371', $codigo);
        $this->assertSame('Venta al por mayor de frutas, hortalizas (verduras), legumbres y tubérculos', $descripcionOficial);
    }

    // ---------- comando Artisan literal ----------

    public function test_comando_dry_run_no_escribe_y_avisa(): void
    {
        $this->artisan('dte:sincronizar-actividades')
            ->assertExitCode(0)
            ->expectsOutputToContain('DRY-RUN — no se modificó la base de datos.');

        $this->assertSame(0, ActividadEconomica::count());
    }

    public function test_comando_apply_escribe(): void
    {
        $this->artisan('dte:sincronizar-actividades', ['--apply' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Aplicado');

        $this->assertSame(774, ActividadEconomica::count());
    }

    public function test_comando_falla_con_codigo_distinto_de_cero_si_hay_duplicados(): void
    {
        CatalogoMh::create(['cat' => '019', 'codigo' => '46301', 'valor' => 'Duplicado a propósito']);

        $this->artisan('dte:sincronizar-actividades', ['--apply' => true])
            ->assertExitCode(1);

        $this->assertSame(0, ActividadEconomica::count());
    }
}
