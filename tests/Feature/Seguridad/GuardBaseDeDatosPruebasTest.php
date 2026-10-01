<?php

namespace Tests\Feature\Seguridad;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Demuestra que el CANDADO de seguridad de la suite existe y funciona: bloquea la
 * ejecución si la configuración de base no es SQLite :memory: en entorno testing,
 * evitando que RefreshDatabase toque una base real (el incidente que vació la BD).
 *
 * NO usa RefreshDatabase a propósito: es lógica de guardia, no debe depender de la BD.
 */
class GuardBaseDeDatosPruebasTest extends TestCase
{
    public function test_configuracion_de_pruebas_segura_no_bloquea(): void
    {
        $this->assertNull(TestCase::motivoBaseDeDatosInsegura(
            esEntornoTesting: true,
            conexionPorDefecto: 'sqlite',
            sqliteDatabase: ':memory:',
            driverConexionActiva: 'sqlite',
            nombreBaseActiva: ':memory:',
        ), 'La configuración real de pruebas (sqlite :memory: en testing) NO debe bloquearse.');
    }

    /**
     * Cada condición peligrosa que el candado DEBE detectar.
     *
     * @return array<string, array{0: bool, 1: string, 2: mixed, 3: string, 4: string}>
     */
    public static function configuracionesInseguras(): array
    {
        return [
            // esTesting, default, sqliteDatabase, driverActivo, nombreBaseActiva
            'entorno no es testing' => [false, 'sqlite', ':memory:', 'sqlite', ':memory:'],
            'conexión por defecto no es sqlite' => [true, 'mysql', ':memory:', 'mysql', 'dulces_negrita'],
            'sqlite no es :memory:' => [true, 'sqlite', 'C:/laragon/data/dev.sqlite', 'sqlite', 'C:/laragon/data/dev.sqlite'],
            'conexión activa apunta a mysql' => [true, 'sqlite', ':memory:', 'mysql', 'cualquier_cosa'],
            'nombre de base contiene dulces_negrita' => [true, 'sqlite', ':memory:', 'sqlite', 'dulces_negrita'],
        ];
    }

    #[DataProvider('configuracionesInseguras')]
    public function test_configuracion_insegura_es_bloqueada(
        bool $esTesting,
        string $default,
        mixed $sqliteDatabase,
        string $driver,
        string $nombre,
    ): void {
        $motivo = TestCase::motivoBaseDeDatosInsegura($esTesting, $default, $sqliteDatabase, $driver, $nombre);

        $this->assertNotNull($motivo, 'Esta configuración es peligrosa y el candado debía devolver un motivo de bloqueo.');
    }

    /**
     * La ÚNICA excepción: la MySQL desechable del job de la CI, pedida de forma explícita,
     * llamada `testing` y en el propio runner.
     */
    public function test_la_mysql_efimera_de_la_ci_no_bloquea(): void
    {
        $this->assertNull(TestCase::motivoBaseDeDatosInsegura(
            esEntornoTesting: true,
            conexionPorDefecto: 'mysql',
            sqliteDatabase: ':memory:',
            driverConexionActiva: 'mysql',
            nombreBaseActiva: 'testing',
            mysqlEfimeraDeCi: true,
            hostBaseActiva: '127.0.0.1',
        ));
    }

    /**
     * Pedir la excepción NO abre la puerta a cualquier MySQL: cada desvío del contenedor
     * de la CI se sigue bloqueando.
     *
     * @return array<string, array{0: bool, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function mysqlQueNoEsLaEfimeraDeLaCi(): array
    {
        return [
            // esTesting, default, driverActivo, nombreBaseActiva, host
            'entorno no es testing' => [false, 'mysql', 'mysql', 'testing', '127.0.0.1'],
            'la base de desarrollo' => [true, 'mysql', 'mysql', 'dulces_negrita', '127.0.0.1'],
            'otro nombre de base' => [true, 'mysql', 'mysql', 'facturacion', '127.0.0.1'],
            'un host remoto' => [true, 'mysql', 'mysql', 'testing', 'db.ejemplo.test'],
            'la conexión no es mysql' => [true, 'sqlite', 'sqlite', 'testing', '127.0.0.1'],
        ];
    }

    #[DataProvider('mysqlQueNoEsLaEfimeraDeLaCi')]
    public function test_pedir_la_mysql_de_la_ci_no_abre_otra_base(
        bool $esTesting,
        string $default,
        string $driver,
        string $nombre,
        string $host,
    ): void {
        $motivo = TestCase::motivoBaseDeDatosInsegura($esTesting, $default, ':memory:', $driver, $nombre, true, $host);

        $this->assertNotNull($motivo, 'Solo la MySQL efímera de la CI puede pasar el candado.');
    }

    /** Una URL de conexión pisa host y base al conectar: con ella no hay excepción. */
    public function test_la_mysql_de_la_ci_con_url_de_conexion_se_bloquea(): void
    {
        $this->assertNotNull(TestCase::motivoBaseDeDatosInsegura(
            esEntornoTesting: true,
            conexionPorDefecto: 'mysql',
            sqliteDatabase: ':memory:',
            driverConexionActiva: 'mysql',
            nombreBaseActiva: 'testing',
            mysqlEfimeraDeCi: true,
            hostBaseActiva: '127.0.0.1',
            urlBaseActiva: 'mysql://usuario@db.ejemplo.test/dulces_negrita',
        ));
    }

    /** Sin la bandera explícita, la MySQL de la CI se bloquea como cualquier otra. */
    public function test_sin_la_bandera_la_mysql_de_la_ci_se_bloquea(): void
    {
        $this->assertNotNull(TestCase::motivoBaseDeDatosInsegura(
            esEntornoTesting: true,
            conexionPorDefecto: 'mysql',
            sqliteDatabase: ':memory:',
            driverConexionActiva: 'mysql',
            nombreBaseActiva: 'testing',
            hostBaseActiva: '127.0.0.1',
        ));
    }

    /** Hacen falta las DOS señales del proceso; una sola no alcanza. */
    public function test_la_excepcion_exige_runner_de_github_y_bandera_explicita(): void
    {
        $antes = [getenv('GITHUB_ACTIONS'), getenv('PRUEBAS_MYSQL_EFIMERA')];

        try {
            foreach ([['true', '1', true], ['true', false, false], [false, '1', false], ['1', '1', false]] as [$gha, $bandera, $esperado]) {
                $gha === false ? putenv('GITHUB_ACTIONS') : putenv("GITHUB_ACTIONS={$gha}");
                $bandera === false ? putenv('PRUEBAS_MYSQL_EFIMERA') : putenv("PRUEBAS_MYSQL_EFIMERA={$bandera}");

                $this->assertSame($esperado, TestCase::mysqlEfimeraDeCiSolicitada());
            }
        } finally {
            $antes[0] === false ? putenv('GITHUB_ACTIONS') : putenv("GITHUB_ACTIONS={$antes[0]}");
            $antes[1] === false ? putenv('PRUEBAS_MYSQL_EFIMERA') : putenv("PRUEBAS_MYSQL_EFIMERA={$antes[1]}");
        }
    }

    public function test_el_guard_real_lanza_excepcion_con_mensaje_claro(): void
    {
        // Simula EXACTAMENTE la config envenenada del incidente (caché vieja apuntando
        // a MySQL/dulces_negrita) y ejerce el guard REAL que corre en setUp().
        config(['database.default' => 'mysql']);
        config(['database.connections.mysql.driver' => 'mysql']);
        config(['database.connections.mysql.database' => 'dulces_negrita']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PRUEBAS BLOQUEADAS: la suite no está usando SQLite :memory:. Se evitó tocar una base real.');

        $this->abortarSiLaBaseDeDatosNoEsSegura();
    }
}
