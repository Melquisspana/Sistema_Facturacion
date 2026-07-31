<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\TipoAjuste;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaUbicacion;
use App\Models\User;
use App\Support\Planta\RastroActivityLog;
use Database\Seeders\PlantaUbicacionesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use RuntimeException;
use Spatie\Activitylog\Traits\LogsActivity;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Las herramientas de verificación de Planta no deben dejar rastro.
 *
 * EL DEFECTO QUE ESTAS PRUEBAS FIJAN. El script de verificación manual y el
 * diagnóstico de concurrencia crean catálogos y documentos temporales y al
 * terminar vacían sus tablas. Pero los modelos auditados escriben además en
 * `activity_log`, y esa fila sobrevive al sujeto. La limpieza enumeraba a mano
 * los modelos a barrer, la lista solo cubría los documentos, y cada corrida
 * dejaba huérfanas las filas de insumos, lotes, ubicaciones y líneas de ajuste.
 * Peor: el comando informaba «datos temporales restantes: ninguno» porque solo
 * miraba las tablas de dominio, así que el residuo se acumulaba en silencio.
 *
 * LO QUE SE EXIGE AQUÍ. Que una ejecución no deje rastro ni cuando va bien ni
 * cuando revienta; y —tan importante como lo anterior— que al limpiar no se
 * lleve por delante auditoría que no es suya: ni la de otros módulos, ni la
 * histórica de Planta, ni la de las ubicaciones de sistema.
 */
class PlantaLimpiezaActivityLogTest extends TestCase
{
    use AjustePlantaFixtures;
    use RefreshDatabase;

    /** Marca de los datos que fabrica esta prueba, como `VERIF9-` o `DIAGCONC-`. */
    private const PREFIJO = 'PRUEBALIMP-';

    /** Filas que purgó el último {@see ejecutarHerramienta()}. */
    private int $purgadas = 0;

    private ?User $administrador = null;

    protected function setUp(): void
    {
        parent::setUp();

        // El usuario se crea AQUÍ, antes de cualquier medición. `User` también
        // audita, así que dejar que los fixtures lo creasen dentro de la
        // ejecución metería en la ventana una fila que el purgador —con razón—
        // no toca, y ensuciaría la lectura de lo que sí es rastro de Planta.
        $this->admin();
    }

    /**
     * Un único administrador para toda la prueba. El de los fixtures crea un
     * usuario en cada llamada, y aquí hace falta que el censo no se mueva.
     */
    protected function admin(): User
    {
        return $this->administrador ??= $this->usuarioConRol('administrador');
    }

    // --- Andamiaje: una herramienta de verificación en miniatura -------------

    /**
     * Reproduce el patrón que ahora siguen las dos herramientas reales: abrir el
     * rastro antes de crear nada, y en `finally` vaciar las tablas y purgar el
     * rastro. Deja escapar la excepción a propósito, que es justo lo que hay que
     * poder comprobar.
     */
    private function ejecutarHerramienta(callable $trabajo): void
    {
        $rastro = RastroActivityLog::abrir();

        try {
            $trabajo();
        } finally {
            $this->vaciarTablasTemporales();
            $this->purgadas = $rastro->purgar();
        }
    }

    /** Catálogo y documento temporales, los dos canales que el defecto mezclaba. */
    private function crearDatosTemporales(): void
    {
        $insumo = $this->insumoConLotes(['codigo' => self::PREFIJO.'INS']);

        $this->borradorAjuste([
            'ubicacion' => $this->bodega(['codigo' => self::PREFIJO.'BOD']),
            'insumo' => $insumo,
            'lote' => PlantaLote::factory()->create(['planta_insumo_id' => $insumo->id]),
        ], TipoAjuste::CargaInicial, '100');
    }

    /** Vacía SOLO lo marcado con el prefijo. El `activity_log` no se toca aquí. */
    private function vaciarTablasTemporales(): void
    {
        $insumos = DB::table('planta_insumos')->where('codigo', 'like', self::PREFIJO.'%')->pluck('id');
        $ubicaciones = DB::table('planta_ubicaciones')->where('codigo', 'like', self::PREFIJO.'%')->pluck('id');

        $ajustes = DB::table('planta_ajuste_detalles')->whereIn('planta_insumo_id', $insumos)
            ->distinct()->pluck('planta_ajuste_id');

        DB::table('planta_ajuste_detalles')->whereIn('planta_ajuste_id', $ajustes)->delete();
        DB::table('planta_ajustes')->whereIn('id', $ajustes)
            ->update(['reversion_de_id' => null, 'revertido_por_id' => null]);
        DB::table('planta_ajustes')->whereIn('id', $ajustes)->delete();

        DB::table('planta_movimientos')->whereIn('planta_insumo_id', $insumos)
            ->update(['movimiento_revertido_id' => null]);
        DB::table('planta_movimientos')->whereIn('planta_insumo_id', $insumos)->delete();
        DB::table('planta_existencias')->whereIn('planta_insumo_id', $insumos)->delete();
        DB::table('planta_lotes')->whereIn('planta_insumo_id', $insumos)->delete();
        DB::table('planta_insumos')->whereIn('id', $insumos)->delete();
        DB::table('planta_ubicaciones')->whereIn('id', $ubicaciones)->delete();
    }

    /** Filas de `activity_log` cuyo sujeto es un modelo de Planta. */
    private function logsDePlanta(): int
    {
        return DB::table('activity_log')
            ->whereIn('subject_type', array_keys(RastroActivityLog::modelos()))
            ->count();
    }

    /**
     * Ids de `activity_log` que NO son de Planta. Ninguno puede desaparecer:
     * es la frontera que el purgador tiene prohibido cruzar.
     *
     * @return array<int, int>
     */
    private function idsAjenos(): array
    {
        return DB::table('activity_log')
            ->where(fn ($q) => $q->whereNotIn('subject_type', array_keys(RastroActivityLog::modelos()))
                ->orWhereNull('subject_type'))
            ->pluck('id')->sort()->values()->all();
    }

    /** Comprueba que la ejecución no se llevó por delante ninguna fila ajena. */
    private function assertNoSePerdioNadaAjeno(array $antes): void
    {
        $this->assertSame([], array_values(array_diff($antes, $this->idsAjenos())),
            'El purgador borró filas de activity_log que no eran de Planta.');
    }

    // --- 1. La ejecución feliz ----------------------------------------------

    public function test_una_ejecucion_exitosa_no_deja_activity_log_temporal(): void
    {
        $plantaAntes = $this->logsDePlanta();
        $ajenosAntes = $this->idsAjenos();
        $total = DB::table('activity_log')->count();

        $this->ejecutarHerramienta(fn () => $this->crearDatosTemporales());

        $this->assertGreaterThan(0, $this->purgadas, 'La ejecución tenía que haber escrito rastro que purgar.');
        $this->assertSame($plantaAntes, $this->logsDePlanta());
        $this->assertSame($total, DB::table('activity_log')->count(),
            'El activity_log debe quedar exactamente como estaba antes de la ejecución.');
        $this->assertNoSePerdioNadaAjeno($ajenosAntes);
    }

    // --- 2. La ejecución que revienta a media faena -------------------------

    public function test_una_ejecucion_con_excepcion_tampoco_deja_activity_log_temporal(): void
    {
        $plantaAntes = $this->logsDePlanta();
        $ajenosAntes = $this->idsAjenos();
        $total = DB::table('activity_log')->count();

        try {
            $this->ejecutarHerramienta(function () {
                $this->crearDatosTemporales();

                // Una comprobación que falla a mitad: los datos ya están escritos
                // y su rastro también, pero el `finally` todavía no ha corrido.
                throw new RuntimeException('La verificación falló a media ejecución');
            });
            $this->fail('La excepción tenía que propagarse después de limpiar.');
        } catch (RuntimeException) {
            // Se esperaba: lo que importa es lo que quedó en la base.
        }

        $this->assertGreaterThan(0, $this->purgadas);
        $this->assertSame($plantaAntes, $this->logsDePlanta());
        $this->assertSame($total, DB::table('activity_log')->count(),
            'Una ejecución que revienta debe limpiar igual que una que termina bien.');
        $this->assertNoSePerdioNadaAjeno($ajenosAntes);
    }

    // --- 3. Lo que ya estaba, se queda --------------------------------------

    public function test_los_logs_preexistentes_de_planta_permanecen_intactos(): void
    {
        // Un insumo de verdad, anterior a la ejecución, con su fila de auditoría.
        $previo = $this->insumoConLotes(['codigo' => 'REAL-001']);
        $logPrevio = DB::table('activity_log')
            ->where('subject_type', PlantaInsumo::class)->where('subject_id', $previo->id)->sole();

        $this->ejecutarHerramienta(fn () => $this->crearDatosTemporales());

        $this->assertDatabaseHas('activity_log', ['id' => $logPrevio->id]);
        $this->assertDatabaseHas('planta_insumos', ['id' => $previo->id, 'codigo' => 'REAL-001']);
    }

    public function test_un_huerfano_historico_anterior_a_la_marca_no_se_toca(): void
    {
        // Auditoría legítima de un sujeto dado de baja hace tiempo: es huérfana,
        // pero NO es nuestra. Que su sujeto no exista no autoriza a borrarla; lo
        // único que autoriza es haberla escrito durante esta ejecución.
        $viejo = $this->insumoConLotes(['codigo' => 'REAL-002']);
        $logViejo = DB::table('activity_log')
            ->where('subject_type', PlantaInsumo::class)->where('subject_id', $viejo->id)->sole();
        DB::table('planta_insumos')->where('id', $viejo->id)->delete();

        $this->ejecutarHerramienta(fn () => $this->crearDatosTemporales());

        $this->assertDatabaseHas('activity_log', ['id' => $logViejo->id]);
    }

    // --- 4. Las ubicaciones de sistema --------------------------------------

    public function test_los_logs_legitimos_de_casa_fabrica_y_transito_permanecen(): void
    {
        $this->seed(PlantaUbicacionesSeeder::class);

        $sistema = PlantaUbicacion::whereIn('codigo', ['CASA', 'FABRICA', 'TRANSITO'])->pluck('id');
        $this->assertCount(3, $sistema);

        $logsAntes = DB::table('activity_log')
            ->where('subject_type', PlantaUbicacion::class)
            ->whereIn('subject_id', $sistema)->pluck('id')->sort()->values()->all();

        $this->ejecutarHerramienta(fn () => $this->crearDatosTemporales());

        $logsDespues = DB::table('activity_log')
            ->where('subject_type', PlantaUbicacion::class)
            ->whereIn('subject_id', $sistema)->pluck('id')->sort()->values()->all();

        $this->assertSame($logsAntes, $logsDespues, 'Los logs del seeder no son rastro de la herramienta.');
        $this->assertSame(3, PlantaUbicacion::whereIn('codigo', ['CASA', 'FABRICA', 'TRANSITO'])->count());
    }

    public function test_un_sujeto_que_sigue_vivo_conserva_su_log_aunque_se_escriba_durante_la_ejecucion(): void
    {
        // El caso del usuario real que trabaja mientras corre el diagnóstico: su
        // registro cae dentro de la ventana, pero su sujeto sigue en pie.
        $superviviente = null;

        $this->ejecutarHerramienta(function () use (&$superviviente) {
            $this->crearDatosTemporales();
            $superviviente = $this->insumoConLotes(['codigo' => 'REAL-003']);
        });

        $this->assertDatabaseHas('planta_insumos', ['id' => $superviviente->id]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => PlantaInsumo::class,
            'subject_id' => $superviviente->id,
        ]);
    }

    // --- 5. Las tablas de inventario ----------------------------------------

    public function test_los_datos_temporales_de_inventario_quedan_en_cero(): void
    {
        $this->ejecutarHerramienta(fn () => $this->crearDatosTemporales());

        foreach (['planta_ajustes', 'planta_ajuste_detalles', 'planta_movimientos',
            'planta_existencias', 'planta_lotes'] as $tabla) {
            $this->assertSame(0, DB::table($tabla)->count(), "La tabla {$tabla} tenía que quedar vacía.");
        }

        $this->assertSame(0, DB::table('planta_insumos')->where('codigo', 'like', self::PREFIJO.'%')->count());
        $this->assertSame(0, DB::table('planta_ubicaciones')->where('codigo', 'like', self::PREFIJO.'%')->count());
    }

    // --- 6. Dos veces seguidas ----------------------------------------------

    public function test_ejecutar_dos_veces_no_acumula_residuos(): void
    {
        $antes = DB::table('activity_log')->count();
        $plantaAntes = $this->logsDePlanta();
        $ajenosAntes = $this->idsAjenos();

        $this->ejecutarHerramienta(fn () => $this->crearDatosTemporales());
        $primera = DB::table('activity_log')->count();

        $this->ejecutarHerramienta(fn () => $this->crearDatosTemporales());
        $segunda = DB::table('activity_log')->count();

        $this->assertSame($antes, $primera);
        $this->assertSame($antes, $segunda, 'La segunda corrida no puede dejar lo que la primera sí limpió.');
        $this->assertSame($plantaAntes, $this->logsDePlanta());
        $this->assertNoSePerdioNadaAjeno($ajenosAntes);
    }

    // --- 7. Lo ajeno no se toca ---------------------------------------------

    public function test_la_limpieza_no_toca_registros_fiscales_ni_activity_log_ajeno_a_planta(): void
    {
        // Un registro de auditoría de otro módulo, escrito a mano para no
        // depender de ningún flujo fiscal: lo que se comprueba es que el
        // purgador lo ignora por su `subject_type`, no por su contenido.
        $ajeno = DB::table('activity_log')->insertGetId([
            'log_name' => 'dte_firma',
            'description' => 'firmó el documento',
            'subject_type' => 'App\Models\Dte',
            'subject_id' => 999999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $usuarios = DB::table('users')->count();
        $plantaAntes = $this->logsDePlanta();
        $ajenosAntes = $this->idsAjenos();

        $this->ejecutarHerramienta(fn () => $this->crearDatosTemporales());

        $this->assertDatabaseHas('activity_log', ['id' => $ajeno]);
        $this->assertSame($usuarios, DB::table('users')->count(), 'La limpieza no borra usuarios.');
        $this->assertSame($plantaAntes, $this->logsDePlanta(),
            'Solo debía irse el rastro de Planta de esta ejecución.');
        $this->assertNoSePerdioNadaAjeno($ajenosAntes);
    }

    // --- 8. La causa raíz: la lista escrita a mano --------------------------

    public function test_el_purgador_cubre_todos_los_modelos_auditados_de_planta(): void
    {
        $cubiertos = array_keys(RastroActivityLog::modelos());
        $auditados = [];

        foreach (Finder::create()->files()->in(app_path('Models/Planta'))->name('*.php') as $archivo) {
            $clase = 'App\\Models\\Planta\\'.$archivo->getFilenameWithoutExtension();

            if (class_exists($clase) && in_array(LogsActivity::class, class_uses_recursive($clase), true)) {
                $auditados[] = $clase;
            }
        }

        sort($cubiertos);
        sort($auditados);

        $this->assertNotEmpty($auditados);
        $this->assertSame($auditados, $cubiertos,
            'Un modelo de Planta que audita y no está cubierto volvería a dejar huérfanos.');
    }

    public function test_cada_modelo_cubierto_apunta_a_una_tabla_que_existe(): void
    {
        foreach (RastroActivityLog::modelos() as $clase => $tabla) {
            $this->assertSame((new ReflectionClass($clase))->getName(), $clase);
            $this->assertTrue(
                Schema::hasTable($tabla),
                "El modelo {$clase} dice usar la tabla {$tabla}, que no existe."
            );
        }
    }
}
