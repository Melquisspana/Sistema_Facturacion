<?php

namespace Tests;

use App\Enums\EstadoDte;
use App\Models\Dte;
use App\Services\Dte\DteStateMachine;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Bota la aplicación PRIMERO (si aún no existe) para tener la configuración
        // RESUELTA disponible. Una config cacheada puede diferir de phpunit.xml —fue
        // exactamente lo que ocurrió: una caché vieja hizo que PHPUnit usara MySQL/
        // dulces_negrita y RefreshDatabase vació la base de desarrollo—. Botar la app
        // aquí carga esa misma config (incluida la cacheada) para poder revisarla.
        if (! $this->app) {
            $this->refreshApplication();
        }

        // CANDADO DURO: corre ANTES de parent::setUp(), que es donde RefreshDatabase
        // dispara `migrate:fresh`. Si la suite no apunta a SQLite :memory: en entorno
        // testing, aborta SIN tocar ninguna base.
        $this->abortarSiLaBaseDeDatosNoEsSegura();

        parent::setUp();

        // Siembra roles/permisos (RolesSeeder) para toda prueba con base fresca, igual
        // que en producción. Sin esto, como la autorización pasó a basarse en permisos
        // (User::can), los roles sueltos de cada setUp no tendrían permisos y todo daría
        // 403 (incluido el admin).
        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            $this->seed(RolesSeeder::class);
        }
    }

    /**
     * CANDADO DE SEGURIDAD DE LA SUITE. Se ejecuta en {@see setUp()} ANTES de
     * `parent::setUp()` (y por tanto ANTES de que RefreshDatabase ejecute
     * `migrate:fresh`). Si la configuración RESUELTA de base de datos no es la de
     * pruebas (SQLite :memory: en entorno testing), lanza una excepción y NO se toca
     * ninguna base. Nace de un incidente real: una caché de configuración vieja hizo
     * que la suite usara MySQL/dulces_negrita y RefreshDatabase vació la base local.
     */
    protected function abortarSiLaBaseDeDatosNoEsSegura(): void
    {
        $config = $this->app->make('config');
        $conexionPorDefecto = (string) $config->get('database.default');

        $motivo = self::motivoBaseDeDatosInsegura(
            esEntornoTesting: $this->app->environment('testing'),
            conexionPorDefecto: $conexionPorDefecto,
            sqliteDatabase: $config->get('database.connections.sqlite.database'),
            driverConexionActiva: (string) $config->get("database.connections.{$conexionPorDefecto}.driver"),
            nombreBaseActiva: (string) $config->get("database.connections.{$conexionPorDefecto}.database"),
        );

        if ($motivo === null) {
            return;
        }

        $mensaje = 'PRUEBAS BLOQUEADAS: la suite no está usando SQLite :memory:. Se evitó tocar una base real. ('.$motivo.')';

        // Ruidoso en STDERR además de la excepción, por si algún runner captura el throw.
        fwrite(STDERR, PHP_EOL.$mensaje.PHP_EOL);

        throw new \RuntimeException($mensaje);
    }

    /**
     * Lógica PURA del candado (sin botar la app, testeable de forma aislada). Devuelve
     * `null` si la configuración de base es segura para pruebas, o un motivo si NO lo es.
     * Aborta ante CUALQUIERA de las condiciones peligrosas.
     */
    public static function motivoBaseDeDatosInsegura(
        bool $esEntornoTesting,
        string $conexionPorDefecto,
        mixed $sqliteDatabase,
        string $driverConexionActiva,
        string $nombreBaseActiva,
    ): ?string {
        if (! $esEntornoTesting) {
            return 'el entorno activo no es testing';
        }

        if ($conexionPorDefecto !== 'sqlite') {
            return "la conexión por defecto es '{$conexionPorDefecto}', no sqlite";
        }

        if ($sqliteDatabase !== ':memory:') {
            return 'la base sqlite no es :memory:';
        }

        if (strtolower($driverConexionActiva) === 'mysql') {
            return 'la conexión activa usa el driver mysql';
        }

        if (str_contains(strtolower($nombreBaseActiva), 'dulces_negrita')) {
            return "el nombre de base contiene 'dulces_negrita'";
        }

        return null;
    }

    /**
     * Deja un CCF ACEPTADO REALMENTE por Hacienda (numeración oficial, sello real y
     * fecha_procesamiento_mh), como exige la regla de negocio para crear notas de crédito
     * (Dte::aceptadoRealmentePorMh()). NO usa sello mock: representa una aceptación real del MH.
     */
    protected function aceptarCcf(Dte $ccf): Dte
    {
        if ($ccf->estado === EstadoDte::Borrador) {
            app(DteStateMachine::class)->transicionar($ccf, EstadoDte::Generado);
        }

        $ccf->numero_control = $ccf->numero_control ?: ('DTE-03-M001P001-'.str_pad((string) $ccf->id, 15, '0', STR_PAD_LEFT));
        $ccf->codigo_generacion = $ccf->codigo_generacion ?: strtoupper((string) Str::uuid());
        $ccf->sello_recepcion = '2026'.strtoupper(Str::random(36)); // sello realista (no mock)
        $ccf->fecha_procesamiento_mh = now();
        $ccf->estado = EstadoDte::Aceptado;
        $ccf->save();

        return $ccf->refresh();
    }
}
