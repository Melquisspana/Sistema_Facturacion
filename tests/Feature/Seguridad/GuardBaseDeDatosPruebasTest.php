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
