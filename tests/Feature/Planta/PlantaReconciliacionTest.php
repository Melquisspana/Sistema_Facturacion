<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoDiferenciaReconciliacion;
use App\Exceptions\Planta\ReconciliacionBloqueadaException;
use App\Services\Planta\ReconciliacionExistenciasService;
use App\Support\Planta\BucketInventario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Reconciliación entre el libro mayor y la proyección de saldos.
 *
 * La corrupción se fabrica SIEMPRE con el query builder, saltándose el dominio.
 * Es deliberado: el motor de inventario, usado como es debido, no puede producir
 * ninguno de estos estados. Si la prueba pudiera crearlos por la vía normal,
 * sobraría la reconciliación y faltaría un candado.
 *
 * La promesa que más se repite aquí es la que más importa: `--apply` NO toca
 * `planta_movimientos`. Se comprueba con la huella del mayor —filas, suma y
 * mayor id— antes y después, que juntas detectan cualquier INSERT, DELETE o
 * UPDATE.
 */
class PlantaReconciliacionTest extends TestCase
{
    use InventarioPlantaFixtures;
    use RefreshDatabase;

    private function reconciliacion(): ReconciliacionExistenciasService
    {
        return app(ReconciliacionExistenciasService::class);
    }

    // --- Sin diferencias ---

    public function test_sin_datos_no_hay_diferencias(): void
    {
        $this->assertTrue($this->reconciliacion()->analizar()->sinDiferencias());

        $this->artisan('planta:reconciliar-existencias')->assertExitCode(0);
    }

    public function test_un_inventario_movido_solo_por_el_servicio_siempre_cuadra(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        foreach (['10.0000', '5.5000', '-3.2500', '20.0000', '-32.2500'] as $cantidad) {
            $this->aplicar($bucket, $cantidad);
        }

        $this->assertTrue($this->reconciliacion()->analizar()->sinDiferencias());

        $this->artisan('planta:reconciliar-existencias')->assertExitCode(0);
    }

    public function test_un_bucket_vaciado_a_cero_cuadra_y_conserva_su_fila(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->aplicar($bucket, '10.0000');
        $this->aplicar($bucket, '-10.0000');

        // La fila en cero tiene razón explícita para existir: el mayor la respalda.
        $this->assertSame('0.0000', $this->saldoProyectado($bucket));
        $this->assertTrue($this->reconciliacion()->analizar()->sinDiferencias());
    }

    // --- Detección ---

    public function test_detecta_un_saldo_alterado(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');

        $this->corromperExistencia($bucket, '99.0000');

        $resultado = $this->reconciliacion()->analizar();
        $diferencias = $resultado->deTipo(TipoDiferenciaReconciliacion::CantidadDistinta);

        $this->assertCount(1, $diferencias);
        $this->assertSame('10.0000', $diferencias[0]->saldoMayor);
        $this->assertSame('99.0000', $diferencias[0]->saldoProyectado);

        $this->artisan('planta:reconciliar-existencias')->assertExitCode(1);
    }

    public function test_detecta_una_fila_faltante(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');

        $this->borrarExistencia($bucket);

        $resultado = $this->reconciliacion()->analizar();
        $diferencias = $resultado->deTipo(TipoDiferenciaReconciliacion::Faltante);

        $this->assertCount(1, $diferencias);
        $this->assertSame('10.0000', $diferencias[0]->saldoMayor);
        $this->assertNull($diferencias[0]->saldoProyectado);
        $this->assertSame(1, $diferencias[0]->movimientos);
    }

    public function test_detecta_una_fila_sobrante(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        // Ni un solo movimiento detrás de este saldo.
        $this->corromperExistencia($bucket, '42.0000');

        $resultado = $this->reconciliacion()->analizar();
        $diferencias = $resultado->deTipo(TipoDiferenciaReconciliacion::Sobrante);

        $this->assertCount(1, $diferencias);
        $this->assertNull($diferencias[0]->saldoMayor);
        $this->assertSame('42.0000', $diferencias[0]->saldoProyectado);
    }

    public function test_una_fila_en_cero_sin_movimientos_tambien_sobra(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->corromperExistencia($bucket, '0.0000');

        $diferencias = $this->reconciliacion()->analizar()->deTipo(TipoDiferenciaReconciliacion::Sobrante);

        // La regla es explícita: una fila en cero se conserva SOLO si el mayor la
        // respalda. Sin movimientos no hay razón que la sostenga.
        $this->assertCount(1, $diferencias);
        $this->assertStringContainsString('sin ningún movimiento', $diferencias[0]->detalle);
    }

    public function test_agrupa_por_las_cinco_dimensiones_y_no_por_cuatro(): void
    {
        $insumo = $this->insumo();
        $lote = $this->lote($insumo);
        $ubicacion = $this->ubicacion();

        $disponible = $this->bucket($insumo, $lote, $ubicacion, EstadoDisponibilidad::Disponible);
        $retenido = $this->bucket($insumo, $lote, $ubicacion, EstadoDisponibilidad::Retenido);

        $this->aplicar($disponible, '10.0000');
        $this->aplicar($retenido, '5.0000');

        // Trampa deliberada: si se agrupara por CUATRO dimensiones —ignorando el
        // estado— el total seguiría siendo 15 y todo «cuadraría». Agrupando por las
        // cinco, saltan dos diferencias.
        $this->corromperExistencia($disponible, '15.0000');
        $this->corromperExistencia($retenido, '0.0000');

        $resultado = $this->reconciliacion()->analizar();

        $this->assertCount(2, $resultado->deTipo(TipoDiferenciaReconciliacion::CantidadDistinta));
        $this->assertSame(2, $resultado->bucketsMayor);
    }

    public function test_distingue_buckets_que_solo_difieren_en_el_traslado(): void
    {
        $insumo = $this->insumo();
        $lote = $this->lote($insumo);
        $transito = $this->transito();

        $viaje7 = $this->bucket($insumo, $lote, $transito, EstadoDisponibilidad::Disponible, 7);
        $viaje9 = $this->bucket($insumo, $lote, $transito, EstadoDisponibilidad::Disponible, 9);

        $this->aplicar($viaje7, '10.0000');
        $this->aplicar($viaje9, '20.0000');

        $this->corromperExistencia($viaje7, '30.0000');

        $resultado = $this->reconciliacion()->analizar();

        $this->assertSame(2, $resultado->bucketsMayor);
        $this->assertCount(1, $resultado->deTipo(TipoDiferenciaReconciliacion::CantidadDistinta));
    }

    public function test_detecta_un_saldo_negativo_en_el_mayor(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');

        // Solo alcanzable escribiendo en el mayor por fuera del servicio.
        DB::table('planta_movimientos')->insert(array_merge($bucket->aColumnas(), [
            'cantidad' => '-50.0000',
            'unidad_base' => 'libra',
            'tipo' => 'ajuste',
            'documento_type' => 'Tests\\Documento',
            'documento_id' => 99,
            'transicion' => 'confirmar',
            'efecto_uid' => hash('sha256', 'negativo'),
            'grupo_uuid' => (string) Str::uuid(),
            'fecha_efectiva' => '2026-07-30',
            'created_at' => now(),
        ]));

        $resultado = $this->reconciliacion()->analizar();
        $negativos = $resultado->deTipo(TipoDiferenciaReconciliacion::SaldoNegativo);

        $this->assertCount(1, $negativos);
        $this->assertFalse($negativos[0]->esCorregible());
        $this->assertCount(1, $resultado->irreparables());
    }

    public function test_detecta_una_ubicacion_fisica_con_traslado(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        // El servicio lo rechazaría; se escribe directo para probar la detección.
        $invalido = new BucketInventario(
            insumoId: $bucket->insumoId,
            loteId: $bucket->loteId,
            ubicacionId: $bucket->ubicacionId,
            estado: EstadoDisponibilidad::Disponible,
            trasladoId: 5,
        );

        $this->corromperExistencia($invalido, '10.0000');

        $diferencias = $this->reconciliacion()->analizar()->deTipo(TipoDiferenciaReconciliacion::TrasladoInvalido);

        $this->assertCount(1, $diferencias);
        $this->assertFalse($diferencias[0]->esCorregible());
    }

    public function test_detecta_transito_sin_traslado(): void
    {
        $insumo = $this->insumo();
        $lote = $this->lote($insumo);
        $transito = $this->transito();

        $invalido = $this->bucket($insumo, $lote, $transito, EstadoDisponibilidad::Disponible, 0);

        $this->corromperExistencia($invalido, '10.0000');

        $diferencias = $this->reconciliacion()->analizar()->deTipo(TipoDiferenciaReconciliacion::TrasladoInvalido);

        $this->assertCount(1, $diferencias);
    }

    public function test_el_analisis_no_escribe_nada(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');
        $this->corromperExistencia($bucket, '99.0000');

        $huella = $this->huellaMayor();

        $this->artisan('planta:reconciliar-existencias')->assertExitCode(1);

        $this->assertSame($huella, $this->huellaMayor());
        $this->assertSame('99.0000', $this->saldoProyectado($bucket), 'El dry-run no debe corregir nada.');
    }

    // --- Aplicación ---

    public function test_apply_corrige_un_saldo_alterado(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');
        $this->corromperExistencia($bucket, '99.0000');

        $this->artisan('planta:reconciliar-existencias --apply')->assertExitCode(0);

        $this->assertSame('10.0000', $this->saldoProyectado($bucket));
        $this->assertTrue($this->reconciliacion()->analizar()->sinDiferencias());
    }

    public function test_apply_recrea_una_fila_faltante(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');
        $this->borrarExistencia($bucket);

        $resultado = $this->reconciliacion()->aplicar();

        $this->assertSame(1, $resultado->correcciones['insertadas']);
        $this->assertSame('10.0000', $this->saldoProyectado($bucket));
    }

    public function test_apply_elimina_una_fila_sobrante(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->corromperExistencia($bucket, '42.0000');

        $resultado = $this->reconciliacion()->aplicar();

        $this->assertSame(1, $resultado->correcciones['eliminadas']);
        $this->assertNull($this->saldoProyectado($bucket));
    }

    public function test_apply_reconstruye_la_proyeccion_entera_desde_cero(): void
    {
        $insumo = $this->insumo();
        $lote = $this->lote($insumo);
        $ubicacion = $this->ubicacion();

        $disponible = $this->bucket($insumo, $lote, $ubicacion, EstadoDisponibilidad::Disponible);
        $retenido = $this->bucket($insumo, $lote, $ubicacion, EstadoDisponibilidad::Retenido);

        $this->aplicar($disponible, '10.0000');
        $this->aplicar($disponible, '2.5000');
        $this->aplicar($retenido, '7.0000');

        // Escenario extremo: la proyección entera desaparece. El mayor basta.
        DB::table('planta_existencias')->delete();

        $resultado = $this->reconciliacion()->aplicar();

        $this->assertSame(2, $resultado->correcciones['insertadas']);
        $this->assertSame('12.5000', $this->saldoProyectado($disponible));
        $this->assertSame('7.0000', $this->saldoProyectado($retenido));
        $this->assertTrue($this->reconciliacion()->analizar()->sinDiferencias());
    }

    public function test_apply_deja_el_libro_mayor_absolutamente_intacto(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');
        $this->aplicar($bucket, '5.0000');

        $huellaAntes = $this->huellaMayor();

        // Corrupción de los tres tipos corregibles a la vez.
        $this->corromperExistencia($bucket, '999.0000');
        $otro = $this->escenarioBasico();
        $this->corromperExistencia($otro['bucket'], '7.0000');

        $resultado = $this->reconciliacion()->aplicar();

        $huellaDespues = $this->huellaMayor();

        $this->assertSame($huellaAntes['filas'], $huellaDespues['filas'], 'count(*) del mayor debe ser idéntico');
        $this->assertSame($huellaAntes['suma'], $huellaDespues['suma'], 'SUM(cantidad) del mayor debe ser idéntico');
        $this->assertSame($huellaAntes['max_id'], $huellaDespues['max_id'], 'MAX(id) del mayor debe ser idéntico');
        $this->assertTrue($resultado->mayorIntacto());
        $this->assertSame($huellaAntes, $resultado->huellaMayorDespues);
    }

    public function test_apply_no_corrige_lo_que_tiene_el_defecto_en_el_mayor(): void
    {
        $insumo = $this->insumo();
        $lote = $this->lote($insumo);
        $transito = $this->transito();

        $invalido = $this->bucket($insumo, $lote, $transito, EstadoDisponibilidad::Disponible, 0);
        $this->corromperExistencia($invalido, '10.0000');

        // Sale con fallo aunque haya podido corregir todo lo demás.
        $this->artisan('planta:reconciliar-existencias --apply')->assertExitCode(1);
    }

    public function test_apply_registra_la_operacion_en_activitylog(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');
        $this->corromperExistencia($bucket, '99.0000');

        $this->reconciliacion()->aplicar();

        $actividad = Activity::where('log_name', 'planta_reconciliacion')->latest('id')->first();

        $this->assertNotNull($actividad, 'La reconciliación debe dejar rastro en Activitylog.');
        $this->assertStringContainsString('reconcilió las existencias', $actividad->description);

        $propiedades = $actividad->properties;

        $this->assertTrue($propiedades['aplicado']);
        $this->assertTrue($propiedades['mayor_intacto']);
        $this->assertSame(1, $propiedades['correcciones']['actualizadas']);
        $this->assertSame(
            $propiedades['huella_mayor_antes'],
            $propiedades['huella_mayor_despues'],
            'La auditoría debe poder demostrar que el mayor no cambió.'
        );
    }

    public function test_el_analisis_no_registra_actividad(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');
        $this->corromperExistencia($bucket, '99.0000');

        $this->reconciliacion()->analizar();

        $this->assertSame(0, Activity::where('log_name', 'planta_reconciliacion')->count());
    }

    // --- Producción exige --force ---

    public function test_en_produccion_apply_sin_force_se_rechaza(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');
        $this->corromperExistencia($bucket, '99.0000');

        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('planta:reconciliar-existencias --apply')->assertExitCode(2);

        // No escribió: el saldo corrupto sigue ahí, sin corregir.
        $this->assertSame('99.0000', $this->saldoProyectado($bucket));
        $this->assertSame(0, Activity::where('log_name', 'planta_reconciliacion')->count());
    }

    public function test_en_produccion_apply_con_force_si_corrige(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');
        $this->corromperExistencia($bucket, '99.0000');

        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('planta:reconciliar-existencias --apply --force')->assertExitCode(0);

        $this->assertSame('10.0000', $this->saldoProyectado($bucket));
    }

    public function test_en_produccion_el_dry_run_no_necesita_force(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');
        $this->corromperExistencia($bucket, '99.0000');

        $this->app->detectEnvironment(fn () => 'production');

        // No escribe, así que no hay nada que autorizar dos veces. Sale con 1 por
        // las diferencias encontradas, no por falta de permiso.
        $this->artisan('planta:reconciliar-existencias')->assertExitCode(1);
        $this->assertSame('99.0000', $this->saldoProyectado($bucket));
    }

    public function test_fuera_de_produccion_apply_no_necesita_force(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');
        $this->corromperExistencia($bucket, '99.0000');

        $this->assertSame('testing', $this->app->environment());

        $this->artisan('planta:reconciliar-existencias --apply')->assertExitCode(0);
        $this->assertSame('10.0000', $this->saldoProyectado($bucket));
    }

    public function test_el_comando_anuncia_entorno_base_y_diferencias_antes_de_aplicar(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');
        $this->corromperExistencia($bucket, '99.0000');

        $this->artisan('planta:reconciliar-existencias --apply')
            ->expectsOutputToContain('testing')
            ->expectsOutputToContain(':memory:')
            ->expectsOutputToContain('Diferencias detectadas')
            ->expectsOutputToContain('BLOQUEADAS')
            ->assertExitCode(0);
    }

    // --- El mayor cambia durante la reconstrucción ---

    public function test_si_el_mayor_cambia_durante_la_reconstruccion_se_deshace_todo(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');
        $this->corromperExistencia($bucket, '99.0000');

        $huellaAntes = $this->huellaMayor();
        $inyectado = false;

        // Simula lo que la reconciliación no puede permitirse: alguien escribe en
        // el MAYOR mientras ella reconstruye la proyección. Se engancha al primer
        // UPDATE de existencias, que es exactamente el momento peligroso.
        DB::listen(function ($consulta) use (&$inyectado, $bucket) {
            if ($inyectado || ! str_contains(strtolower($consulta->sql), 'planta_existencias')) {
                return;
            }

            if (! str_starts_with(strtolower(ltrim($consulta->sql)), 'update')) {
                return;
            }

            $inyectado = true;

            DB::table('planta_movimientos')->insert(array_merge($bucket->aColumnas(), [
                'cantidad' => '7.0000',
                'unidad_base' => 'libra',
                'tipo' => 'ajuste',
                'documento_type' => 'Tests\\Intruso',
                'documento_id' => 1,
                'transicion' => 'confirmar',
                'efecto_uid' => hash('sha256', 'intruso'),
                'grupo_uuid' => (string) Str::uuid(),
                'fecha_efectiva' => '2026-07-30',
                'created_at' => now(),
            ]));
        });

        $excepcion = null;

        try {
            $this->reconciliacion()->aplicar();
        } catch (\Throwable $e) {
            $excepcion = $e;
        }

        $this->assertNotNull($excepcion, 'Un mayor que cambia a media reconstrucción debe abortar.');
        $this->assertStringContainsString('El libro mayor cambió', $excepcion->getMessage());

        // ROLLBACK COMPLETO: ni la corrección ni la escritura intrusa sobreviven.
        $this->assertSame('99.0000', $this->saldoProyectado($bucket), 'La corrección debe haberse deshecho.');
        $this->assertSame($huellaAntes, $this->huellaMayor(), 'La escritura intrusa debe haberse deshecho.');
        $this->assertSame(0, Activity::where('log_name', 'planta_reconciliacion')->count());
    }

    // --- Filas en cero ---

    public function test_apply_conserva_una_fila_en_cero_respaldada_por_movimientos(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->aplicar($bucket, '10.0000');
        $this->aplicar($bucket, '-10.0000');

        $resultado = $this->reconciliacion()->aplicar();

        // El bucket existió y se vació: su historial sigue en el mayor y la fila en
        // cero es la respuesta correcta a «¿cuánto hay aquí?».
        $this->assertSame('0.0000', $this->saldoProyectado($bucket));
        $this->assertSame(0, $resultado->correcciones['eliminadas']);
        $this->assertSame(1, DB::table('planta_existencias')->where($bucket->aColumnas())->count());
    }

    public function test_apply_elimina_una_fila_en_cero_sin_respaldo_en_el_mayor(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();

        $this->corromperExistencia($bucket, '0.0000');

        $resultado = $this->reconciliacion()->aplicar();

        // Sin un solo movimiento detrás no hay razón explícita que la sostenga.
        $this->assertNull($this->saldoProyectado($bucket));
        $this->assertSame(1, $resultado->correcciones['eliminadas']);
    }

    public function test_apply_registra_la_huella_de_la_proyeccion_antes_y_despues(): void
    {
        ['bucket' => $bucket] = $this->escenarioBasico();
        $this->aplicar($bucket, '10.0000');
        $this->corromperExistencia($bucket, '99.0000');

        $resultado = $this->reconciliacion()->aplicar();

        $this->assertSame(['filas' => 1, 'suma' => '99.0000'], $resultado->huellaProyeccionAntes);
        $this->assertSame(['filas' => 1, 'suma' => '10.0000'], $resultado->huellaProyeccionDespues);

        $propiedades = Activity::where('log_name', 'planta_reconciliacion')->latest('id')->first()->properties;

        $this->assertSame('99.0000', $propiedades['huella_proyeccion_antes']['suma']);
        $this->assertSame('10.0000', $propiedades['huella_proyeccion_despues']['suma']);
    }

    // --- Ejecución concurrente ---

    public function test_apply_se_niega_a_correr_si_ya_hay_otra_pasada_en_curso(): void
    {
        // Se toma el candado a mano para simular al otro reconciliador.
        $otro = Cache::lock('planta:reconciliar-existencias', 600);
        $this->assertTrue($otro->get());

        try {
            $this->expectException(ReconciliacionBloqueadaException::class);

            $this->reconciliacion()->aplicar();
        } finally {
            $otro->release();
        }
    }

    public function test_el_comando_sale_con_codigo_2_si_el_candado_esta_tomado(): void
    {
        $otro = Cache::lock('planta:reconciliar-existencias', 600);
        $otro->get();

        try {
            $this->artisan('planta:reconciliar-existencias --apply')->assertExitCode(2);
        } finally {
            $otro->release();
        }
    }
}
