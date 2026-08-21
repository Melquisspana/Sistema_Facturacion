<?php

namespace Tests;

use App\Enums\EstadoDte;
use App\Models\Configuracion;
use App\Models\Distrito;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Municipio;
use App\Services\Dte\DteStateMachine;
use App\Support\Correo\CandadoCorreoReal;
use App\Support\Ubicacion\UbicacionCoherenteFactory;
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

        // La caché de `Configuracion` es una propiedad STATIC: vive en el proceso de
        // PHPUnit, no en la aplicación. `RefreshDatabase` borra la FILA entre pruebas
        // pero no la estática, así que una clave escrita por un test seguía
        // resolviéndose en el siguiente —de otra clase, incluso de otra carpeta— como
        // un valor fantasma que ya no está en ninguna base.
        //
        // No es hipotético: `PreflightEmisionProduccionTest` deja
        // `produccion.ultimo_ccf_externo` en '1093' y `PreparacionProduccionTest`, que
        // afirma que esa clave está SIN configurar, fallaba al correr la suite entera y
        // pasaba en aislado. Un fallo que solo aparece según el orden de ejecución es
        // el que enseña a ignorar el rojo.
        //
        // Se limpia acá, para TODA la suite, en vez de en cada setUp que se acuerde:
        // olvidar hacerlo no da un error, da un test que miente.
        Configuracion::olvidarCache();

        // Siembra roles/permisos (RolesSeeder) para toda prueba con base fresca, igual
        // que en producción. Sin esto, como la autorización pasó a basarse en permisos
        // (User::can), los roles sueltos de cada setUp no tendrían permisos y todo daría
        // 403 (incluido el admin).
        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            $this->seed(RolesSeeder::class);
        }

        $this->completarDistritoDelEmisorEnPruebas();
    }

    /**
     * Completa el DISTRITO de la empresa emisora creada en pruebas.
     *
     * Por qué existe: el CCF (v4), la Factura (v2) y la FEX (v3) llevan
     * `emisor.direccion.distrito`, y desde que la validación previa exige ese campo (para
     * no enviar `distrito: ""`, que Hacienda rechaza) todo emisor debe tener uno coherente
     * con su municipio. Decenas de pruebas construyen su emisor con el mismo bloque
     * copiado que solo fija departamento y municipio; en vez de repetir el arreglo en cada
     * archivo, se completa acá una sola vez.
     *
     * Es deliberadamente conservador:
     *  - No toca una empresa SIN departamento: esos casos prueban justamente la ausencia
     *    de ubicación y deben seguir fallando la validación.
     *  - No toca una empresa que ya trae distrito.
     *  - Respeta el municipio elegido si existe un distrito suyo; solo lo realinea cuando
     *    ese municipio no tiene ningún distrito (catálogo incompleto para ese caso).
     *
     * Solo aplica en la suite: no hay ningún equivalente en producción.
     */
    protected function completarDistritoDelEmisorEnPruebas(): void
    {
        // Se registra en CADA setUp a propósito: la aplicación se rebota por prueba y con
        // ella el dispatcher de eventos de Eloquent, así que un listener registrado una
        // sola vez solo viviría en la primera prueba del proceso.
        Empresa::created(function (Empresa $empresa) {
            if (blank($empresa->departamento_id) || filled($empresa->distrito_id)) {
                return;
            }

            $municipio = $empresa->municipio_id ? Municipio::find($empresa->municipio_id) : null;

            $distrito = $municipio && filled($municipio->codigo)
                ? Distrito::where('departamento_id', $empresa->departamento_id)
                    ->where('municipio_codigo', $municipio->codigo)->orderBy('id')->first()
                : null;

            if (! $distrito) {
                $tercia = UbicacionCoherenteFactory::tercia((int) $empresa->departamento_id);
                if (blank($tercia['distrito_id'])) {
                    return; // catálogo sin distritos vinculados: no se inventa nada
                }
                $empresa->municipio_id = $tercia['municipio_id'];
                $distrito = Distrito::find($tercia['distrito_id']);
            }

            $empresa->distrito_id = $distrito->id;
            $empresa->saveQuietly();
        });
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
     * Credenciales FICTICIAS del ambiente de pruebas del Ministerio de Hacienda.
     *
     * POR QUÉ EXISTE. Varios tests de transmisión e invalidación mockean toda la
     * red con `Http::fake()` pero morían antes de llegar a ella, porque
     * `DteTransmisionAuthService` aborta —con razón— cuando no hay credenciales de
     * apitest. El resultado era una suite que se ponía roja en las máquinas sin
     * DTE_TEST_USER/DTE_TEST_PASSWORD en el .env: quince fallos que no señalaban
     * ninguna regresión y que enseñaban a ignorar el rojo.
     *
     * Estas credenciales NO abren ninguna puerta: son texto inventado, la red
     * sigue mockeada y phpunit.xml mantiene apagados firma, transmisión y
     * producción. Lo único que hacen es dejar que el test llegue a la parte que
     * quiere probar.
     *
     * Si algún día hiciera falta un test que hable de verdad con apitest, ese sí
     * tendría que exigir credenciales reales y saltarse cuando falten — pero
     * entonces sería un test de integración, no de la suite.
     */
    protected function credencialesApitestFicticias(): void
    {
        config([
            'dte.transmision.usuario_testing' => 'usuario-apitest-de-prueba',
            'dte.transmision.password_testing' => 'password-apitest-de-prueba',
        ]);
    }

    /**
     * Declara que ESTE test simula PRODUCCIÓN a efectos del CORREO: el candado
     * {@see CandadoCorreoReal} solo permite envío real cuando el
     * entorno es `production`, así que sin esto todo envío queda 'simulado'.
     *
     * Sustituye el candado en el contenedor en vez de cambiar el entorno REAL de la app:
     * con `app()->environment('production')` los comandos de base de datos (seeders,
     * migraciones) pedirían confirmación interactiva y la suite quedaría inutilizable.
     * Que `permiteEnvioReal()` lea de verdad el entorno se prueba aparte, en
     * CandadoCorreoRealTest.
     *
     * El correo nunca sale de verdad: los tests usan Mail::fake().
     */
    protected function simularProduccionCorreo(): void
    {
        config(['mail.default' => 'smtp']); // transporte real configurado

        $this->app->instance(CandadoCorreoReal::class, new class extends CandadoCorreoReal
        {
            public function permiteEnvioReal(): bool
            {
                return true;
            }

            public function entorno(): string
            {
                return 'production';
            }
        });
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
