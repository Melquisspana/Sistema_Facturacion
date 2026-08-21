<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\Adaptadores\AdaptadorConfiguraciones;
use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\AuditoriaAjustes;
use App\Ajustes\CatalogoAjustes;
use App\Ajustes\ConversorValor;
use App\Ajustes\Correo\ConfiguracionCorreoRuntime;
use App\Ajustes\Correo\PruebaConexionSmtp;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\Excepciones\AlmacenAjustesNoDisponibleException;
use App\Ajustes\RepositorioAjustes;
use App\Ajustes\Verificaciones\RegistroVerificaciones;
use App\Ajustes\Verificaciones\ResultadoVerificacion;
use App\Facades\Ajustes;
use App\Models\Configuracion;
use App\Models\GmailCuenta;
use App\Models\User;
use App\Support\Contabilidad\CorreoContabilidad;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/**
 * LO QUE DESTAPÓ EL ENSAYO DE DESPLIEGUE (Fase 6).
 *
 * {@see VentanaDespliegueTest} prueba que LEER configuración sobrevive a un
 * despliegue a medias. Este archivo fija las dos cosas que ese test no miraba y
 * que solo aparecieron al ejecutar el ciclo completo —esquema viejo, datos reales,
 * `migrate`, rollback— contra una base de verdad:
 *
 *  1. LA MUDANZA DE DATOS TENÍA QUE INVALIDAR LA CACHÉ. La migración escribe con
 *     `DB::table()`, por detrás del repositorio, así que la huella que versiona la
 *     caché compartida no cambiaba. Durante los 5 minutos de la TTL, todo proceso
 *     seguía sirviendo el mapa que cacheó ANTES de la mudanza —vacío— mientras la
 *     migración ya había borrado las filas de la tabla anterior. Resultado medido:
 *     `correo.auto_envio` se resolvía a `false` y los DTE aceptados dejaban de
 *     encolar el correo al cliente. Una pérdida de configuración real, silenciosa
 *     y con efecto sobre lo que sale por correo.
 *
 *  2. ESCRIBIR EN LA VENTANA DABA 500 DE SQL. Leer estaba resuelto; guardar desde
 *     la pantalla, no: la excepción de «tabla inexistente» llegaba cruda al
 *     navegador, con el nombre de la base dentro. Ahora es un error de formulario
 *     que dice qué falta y que no se perdió nada.
 */
class EnsayoDespliegueTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACION_DATOS = 'migrations/2026_08_20_120000_migrar_configuraciones_correo_a_ajustes.php';

    private const MIGRACION_TABLA = 'migrations/2026_08_19_090000_create_ajustes_sistema_table.php';

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('administrador', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Configuracion::olvidarCache();
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function migracionDeDatos(): object
    {
        return require database_path(self::MIGRACION_DATOS);
    }

    /** Estado de producción HOY: las cinco claves viven en la tabla anterior. */
    private function comoAntesDeMigrar(): void
    {
        DB::table('ajustes_sistema')->delete();
        app(RepositorioAjustes::class)->invalidar();

        Configuracion::set('contabilidad.correo', 'conta@ejemplo.com');
        Configuracion::set('contabilidad.enviar_copia', true);
        Configuracion::set('correo.auto_envio', true);
        Configuracion::olvidarCache();
    }

    /**
     * Resolver independiente con memoria propia y la MISMA caché compartida: la
     * relación real entre el worker de colas y la petición web que guarda.
     */
    private function otroProceso(): ServicioAjustes
    {
        return new ServicioAjustes(
            app(CatalogoAjustes::class),
            new RepositorioAjustes(app(CacheRepository::class)),
            app(AdaptadorConfiguraciones::class),
            app(ConversorValor::class),
            app(AuditoriaAjustes::class),
        );
    }

    /**
     * Un proceso que ARRANCA AHORA: sin la caché estática de `Configuracion`, que
     * es de proceso y muere con él. Es el caso que importa —cada petición web es un
     * proceso nuevo— y el único en el que una caché compartida desactualizada no
     * queda tapada por la memoria del proceso anterior.
     */
    private function procesoRecienArrancado(): ServicioAjustes
    {
        Configuracion::olvidarCache();

        return $this->otroProceso();
    }

    /** Simula el esquema ANTERIOR: las tablas nuevas todavía no existen. */
    private function sinTablasNuevas(): void
    {
        Schema::drop('verificaciones_configuracion');
        Schema::drop('ajustes_sistema');
        app(RepositorioAjustes::class)->invalidar();
    }

    // ============================================================= (1) caché

    /**
     * EL FALLO MEDIDO EN EL ENSAYO. Sin la invalidación dentro de la migración, la
     * mudanza apagaba el envío automático de correo durante minutos.
     */
    public function test_la_mudanza_de_datos_invalida_la_cache_compartida(): void
    {
        $this->comoAntesDeMigrar();

        // Un proceso lee ANTES de migrar y deja el mapa (vacío) en la caché compartida.
        $this->assertSame(FuenteAjuste::BaseDeDatosLegacy, $this->otroProceso()->fuente('correo.auto_envio'));

        // `php artisan migrate`: mueve las filas y no avisa a nadie más.
        $this->migracionDeDatos()->up();

        $worker = $this->procesoRecienArrancado();

        $this->assertTrue(
            $worker->bool('correo.auto_envio'),
            'Tras la mudanza el envío automático debe seguir encendido: apagarlo solo es una pérdida de configuración.',
        );
        $this->assertSame('conta@ejemplo.com', $worker->texto('contabilidad.correo'));
        $this->assertSame(FuenteAjuste::BaseDeDatos, $worker->fuente('contabilidad.correo'));
    }

    /** Y los consumidores reales, que es donde se nota: la copia a contabilidad. */
    public function test_tras_la_mudanza_los_consumidores_no_pierden_su_valor(): void
    {
        $this->comoAntesDeMigrar();
        $this->assertSame('conta@ejemplo.com', app(CorreoContabilidad::class)->direccion());

        $this->migracionDeDatos()->up();
        Configuracion::olvidarCache();

        $this->assertSame('conta@ejemplo.com', app(CorreoContabilidad::class)->direccion());
        $this->assertSame('conta@ejemplo.com', app(CorreoContabilidad::class)->copiaOculta());
    }

    /** La reversa tiene el mismo problema al revés: también invalida. */
    public function test_la_reversa_de_la_mudanza_invalida_la_cache_compartida(): void
    {
        $this->comoAntesDeMigrar();
        $this->migracionDeDatos()->up();

        // Un proceso lee ya migrado: deja en caché el mapa CON las cinco filas.
        $this->assertSame(FuenteAjuste::BaseDeDatos, $this->procesoRecienArrancado()->fuente('correo.auto_envio'));

        $this->migracionDeDatos()->down();

        $despues = $this->procesoRecienArrancado();

        $this->assertTrue($despues->bool('correo.auto_envio'));
        $this->assertSame(
            FuenteAjuste::BaseDeDatosLegacy,
            $despues->fuente('correo.auto_envio'),
            'Tras la reversa la fuente vuelve a ser la tabla anterior; seguir diciendo «base de datos» es leer filas que ya no existen.',
        );
    }

    /** Borrar la tabla tampoco puede dejar overrides fantasma cacheados. */
    public function test_borrar_la_tabla_nueva_invalida_la_cache_compartida(): void
    {
        $this->actingAs($this->admin());
        Ajustes::guardar('contabilidad.correo', 'conta@ejemplo.com');
        $this->assertSame(FuenteAjuste::BaseDeDatos, $this->procesoRecienArrancado()->fuente('contabilidad.correo'));

        (require database_path(self::MIGRACION_TABLA))->down();

        $this->assertSame(
            FuenteAjuste::NoConfigurado,
            $this->procesoRecienArrancado()->fuente('contabilidad.correo'),
            'Sin la tabla no hay overrides: seguir sirviendo el valor cacheado es servir una fila borrada.',
        );
    }

    // ========================================================= (2) escritura

    /** Guardar sin la tabla es una excepción PROPIA, no una de SQL. */
    public function test_guardar_sin_la_tabla_nueva_no_es_un_error_de_sql(): void
    {
        $this->actingAs($this->admin());
        $this->sinTablasNuevas();

        $this->expectException(AlmacenAjustesNoDisponibleException::class);

        Ajustes::guardar('contabilidad.correo', 'otra@ejemplo.com');
    }

    /** Y la pantalla devuelve un error de formulario, no un 500. */
    public function test_la_pantalla_de_contabilidad_no_da_500_durante_la_ventana(): void
    {
        Configuracion::set('contabilidad.correo', 'conta@ejemplo.com');
        Configuracion::olvidarCache();

        $admin = $this->admin();
        $this->sinTablasNuevas();

        $this->actingAs($admin)
            ->from(route('configuracion.contabilidad.edit'))
            ->put(route('configuracion.contabilidad.update'), [
                'correo_contabilidad' => 'otra@ejemplo.com',
                'enviar_copia_contabilidad' => '1',
            ])
            ->assertRedirect(route('configuracion.contabilidad.edit'))
            ->assertSessionHasErrors('configuracion');
    }

    /** El intento fallido no puede haberse llevado por delante lo que había. */
    public function test_el_guardado_rechazado_no_pierde_la_configuracion_anterior(): void
    {
        Configuracion::set('contabilidad.correo', 'conta@ejemplo.com');
        Configuracion::set('correo.auto_envio', true);
        Configuracion::olvidarCache();

        $this->actingAs($this->admin());
        $this->sinTablasNuevas();

        try {
            Ajustes::guardar('contabilidad.correo', 'otra@ejemplo.com');
        } catch (AlmacenAjustesNoDisponibleException) {
            // Esperado: lo que se prueba es qué queda después.
        }

        Configuracion::olvidarCache();

        $this->assertSame('conta@ejemplo.com', Ajustes::texto('contabilidad.correo'));
        $this->assertTrue(Ajustes::bool('correo.auto_envio'));
        $this->assertDatabaseHas('configuraciones', ['clave' => 'contabilidad.correo', 'valor' => 'conta@ejemplo.com']);
    }

    /** Quitar un override tampoco puede reventar por una tabla que no está. */
    public function test_quitar_override_sin_la_tabla_nueva_no_es_un_error_de_sql(): void
    {
        $this->actingAs($this->admin());
        $this->sinTablasNuevas();

        $this->expectException(AlmacenAjustesNoDisponibleException::class);

        Ajustes::quitarOverride('contabilidad.correo');
    }

    // ========================================================== (3) secretos

    /**
     * La mudanza NO CIFRA NADA. Las cinco claves son configuración pública de la
     * empresa y se mudan en claro, igual que estaban.
     *
     * Importa porque `cifrado` es lo que usa la rotación de APP_KEY para saber qué
     * filas hay que volver a cifrar: una fila marcada como cifrada que en realidad
     * lleva texto plano haría fallar la rotación entera, y una cifrada sin marcar
     * quedaría ilegible para siempre al cambiar la clave.
     */
    public function test_la_mudanza_no_cifra_ninguna_clave(): void
    {
        $this->comoAntesDeMigrar();
        Configuracion::set('correo.adjuntar_jws', true);
        Configuracion::set('correo.plantilla', 'Hola {{cliente}}');
        Configuracion::olvidarCache();

        $this->migracionDeDatos()->up();

        $this->assertSame(
            0,
            DB::table('ajustes_sistema')->where('cifrado', true)->count(),
            'Ninguna de las cinco claves mudadas es un secreto.',
        );
        $this->assertSame(5, DB::table('ajustes_sistema')->count());
    }

    /**
     * Los tokens OAuth de Gmail viven en `gmail_cuentas`, cifrados con la APP_KEY.
     * Ninguna de las tres migraciones los toca, así que tienen que seguir
     * descifrándose igual: ni la mudanza ni su reversa pueden obligar a reconectar
     * la cuenta desde Google.
     */
    public function test_la_mudanza_no_toca_los_tokens_de_gmail(): void
    {
        $cuenta = GmailCuenta::create([
            'email' => 'cuenta@ejemplo.com',
            'access_token' => 'access-token-de-prueba',
            'refresh_token' => 'refresh-token-de-prueba',
        ]);

        $criptogramaAntes = DB::table('gmail_cuentas')->where('id', $cuenta->id)->value('access_token');

        $this->comoAntesDeMigrar();
        $this->migracionDeDatos()->up();
        $this->migracionDeDatos()->down();

        $recargada = GmailCuenta::findOrFail($cuenta->id);

        $this->assertSame('access-token-de-prueba', $recargada->access_token);
        $this->assertSame('refresh-token-de-prueba', $recargada->refresh_token);
        $this->assertSame(
            $criptogramaAntes,
            DB::table('gmail_cuentas')->where('id', $cuenta->id)->value('access_token'),
            'El criptograma no debe haberse reescrito: nadie tocó esta tabla.',
        );
    }

    /**
     * Un secreto sin override sigue saliendo del .env después de mudar. La tabla
     * nueva se crea VACÍA: la mudanza no copia secretos desde ningún sitio.
     */
    public function test_los_secretos_siguen_saliendo_del_env_despues_de_mudar(): void
    {
        config(['mail.mailers.smtp.password' => 'clave-del-env']);

        $this->comoAntesDeMigrar();
        $this->migracionDeDatos()->up();
        Configuracion::olvidarCache();

        $this->assertSame('clave-del-env', Ajustes::secretoParaRuntime('mail.smtp.password'));
        $this->assertSame(FuenteAjuste::Configuracion, Ajustes::fuente('mail.smtp.password'));
    }

    // ===================================================== (4) verificaciones

    /**
     * El historial es un dato de segundo orden: sin su tabla no se guarda, pero la
     * comprobación se hace igual y su resultado se muestra igual.
     */
    public function test_probar_conexion_funciona_sin_la_tabla_del_historial(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.ejemplo.com']);
        $this->actingAs($this->admin());
        $this->sinTablasNuevas();

        $prueba = new PruebaConexionSmtp(
            app(ServicioAjustes::class),
            app(RegistroVerificaciones::class),
            app(AuditoriaAjustes::class),
            app(ConfiguracionCorreoRuntime::class),
            app(),
            fn () => new class('smtp.ejemplo.com', 587) extends EsmtpTransport
            {
                public function start(): void {}

                public function stop(): void {}
            },
        );

        $resultado = $prueba->ejecutar();

        $this->assertTrue($resultado->exito);
    }

    public function test_registrar_una_verificacion_sin_su_tabla_no_falla(): void
    {
        $this->sinTablasNuevas();

        $registro = app(RegistroVerificaciones::class);

        $this->assertNull($registro->registrar('smtp', ResultadoVerificacion::Exito, 'sin tabla'));
        $this->assertNull($registro->ultima('smtp'));
    }
}
