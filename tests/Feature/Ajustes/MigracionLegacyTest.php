<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\Adaptadores\AdaptadorConfiguraciones;
use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\AuditoriaAjustes;
use App\Ajustes\CatalogoAjustes;
use App\Ajustes\ConversorValor;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\Definicion\Persistencia;
use App\Ajustes\RepositorioAjustes;
use App\Facades\Ajustes;
use App\Models\Configuracion;
use App\Models\User;
use App\Support\Contabilidad\CorreoContabilidad;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Mudanza de las cinco claves de correo/contabilidad desde `configuraciones` a
 * `ajustes_sistema`.
 *
 * Lo que se fija: que MUEVE (no copia), que es reversible, que los consumidores
 * siguen comportándose igual, que las claves ajenas no se tocan — y el efecto que
 * no se ve en pantalla: que al salir de la tabla vieja desaparece la caché
 * estática de proceso que obligaba a reiniciar el worker.
 */
class MigracionLegacyTest extends TestCase
{
    use RefreshDatabase;

    /** Las cinco que se mudan. */
    private const MIGRADAS = [
        'contabilidad.correo',
        'contabilidad.enviar_copia',
        'correo.auto_envio',
        'correo.adjuntar_jws',
        'correo.plantilla',
    ];

    /**
     * Estado de proceso que vive en la misma tabla y NO se muda: no es
     * configuración y nadie debería poder tocarla desde una pantalla.
     */
    private const AJENAS = [
        'produccion.auth_prod_validada',
        'produccion.ultimo_ccf_externo',
        'ppq.albaranes.ultimo_dia_completo',
    ];

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

    /** Deja la base como estaría ANTES de la mudanza: valores en la tabla vieja. */
    private function comoAntesDeMigrar(): void
    {
        DB::table('ajustes_sistema')->whereIn('clave', self::MIGRADAS)->delete();
        app(RepositorioAjustes::class)->invalidar();

        Configuracion::set('contabilidad.correo', 'conta@ejemplo.com');
        Configuracion::set('contabilidad.enviar_copia', true);
        Configuracion::set('correo.auto_envio', true);
        Configuracion::set('correo.adjuntar_jws', true);
        Configuracion::set('correo.plantilla', 'Hola {{cliente}}');

        foreach (self::AJENAS as $ajena) {
            Configuracion::set($ajena, 'no-me-toques');
        }

        Configuracion::olvidarCache();
    }

    /**
     * Se invoca la migración DIRECTAMENTE, no por `artisan migrate`.
     *
     * RefreshDatabase ya corrió `migrate:fresh` en el setUp, así que la migración
     * figura como aplicada —sobre una tabla vacía— y `migrate` no volvería a
     * ejecutarla. Lo que interesa probar es su LÓGICA sobre datos, y para eso hay
     * que llamarla a mano con los datos ya puestos.
     */
    private function migracion(): object
    {
        return require database_path('migrations/2026_08_20_120000_migrar_configuraciones_correo_a_ajustes.php');
    }

    private function migrar(): void
    {
        $this->migracion()->up();
        $this->olvidarCaches();
    }

    private function revertir(): void
    {
        $this->migracion()->down();
        $this->olvidarCaches();
    }

    /** Las dos capas cachean; tras mover filas a mano hay que hacérselo saber. */
    private function olvidarCaches(): void
    {
        Configuracion::olvidarCache();
        app(RepositorioAjustes::class)->invalidar();
    }

    // ------------------------------------------------------------- catálogo

    /** Las cinco declaran ya la tabla nueva como su ubicación de escritura. */
    public function test_las_claves_migradas_persisten_en_la_tabla_nueva(): void
    {
        $catalogo = app(CatalogoAjustes::class);

        foreach (self::MIGRADAS as $clave) {
            $this->assertSame(
                Persistencia::Nueva,
                $catalogo->definicion($clave)->persistencia,
                "{$clave} debería escribirse ya en ajustes_sistema.",
            );
        }
    }

    /** Conservan su clave legacy: es la lectura de transición, no una segunda escritura. */
    public function test_conservan_su_clave_legacy_como_lectura_de_transicion(): void
    {
        $catalogo = app(CatalogoAjustes::class);

        foreach (self::MIGRADAS as $clave) {
            $this->assertNotNull($catalogo->definicion($clave)->claveLegacy, "{$clave} necesita su lectura de transición.");
        }
    }

    // -------------------------------------------------------------- mudanza

    /** MUEVE: el valor queda en la tabla nueva y desaparece de la vieja. */
    public function test_la_migracion_mueve_los_valores(): void
    {
        $this->comoAntesDeMigrar();
        $this->migrar();

        $this->assertDatabaseHas('ajustes_sistema', ['clave' => 'contabilidad.correo', 'valor' => 'conta@ejemplo.com']);
        $this->assertDatabaseMissing('configuraciones', ['clave' => 'contabilidad.correo']);

        foreach (self::MIGRADAS as $clave) {
            $this->assertDatabaseMissing('configuraciones', ['clave' => $clave]);
        }
    }

    /** LA regla: nunca el mismo valor en las dos tablas a la vez. */
    public function test_nunca_queda_el_mismo_valor_en_las_dos_tablas(): void
    {
        $this->comoAntesDeMigrar();
        $this->migrar();

        $enViejas = DB::table('configuraciones')->whereIn('clave', self::MIGRADAS)->count();
        $enNuevas = DB::table('ajustes_sistema')->whereIn('clave', self::MIGRADAS)->count();

        $this->assertSame(0, $enViejas);
        $this->assertSame(5, $enNuevas);
    }

    public function test_no_toca_las_claves_ajenas(): void
    {
        $this->comoAntesDeMigrar();
        $this->migrar();

        foreach (self::AJENAS as $ajena) {
            $this->assertDatabaseHas('configuraciones', ['clave' => $ajena, 'valor' => 'no-me-toques']);
            $this->assertDatabaseMissing('ajustes_sistema', ['clave' => $ajena]);
        }
    }

    /** La tabla anterior sigue existiendo: solo se le quitaron cinco filas. */
    public function test_no_borra_la_tabla_de_configuraciones(): void
    {
        $this->comoAntesDeMigrar();
        $this->migrar();

        $this->assertTrue(Schema::hasTable('configuraciones'));
        $this->assertSame(count(self::AJENAS), DB::table('configuraciones')->count());
    }

    /** Un valor vacío era "sin configurar": no se convierte en un override que tape el fallback. */
    public function test_un_valor_vacio_no_se_convierte_en_override(): void
    {
        DB::table('ajustes_sistema')->whereIn('clave', self::MIGRADAS)->delete();
        Configuracion::set('contabilidad.correo', null);
        Configuracion::olvidarCache();

        $this->migrar();

        $this->assertDatabaseMissing('ajustes_sistema', ['clave' => 'contabilidad.correo']);
        $this->assertDatabaseMissing('configuraciones', ['clave' => 'contabilidad.correo']);
    }

    /** Si alguien ya guardó por la pantalla nueva, gana lo nuevo y no quedan dos filas. */
    public function test_si_ya_existe_en_la_tabla_nueva_gana_esa(): void
    {
        DB::table('ajustes_sistema')->whereIn('clave', self::MIGRADAS)->delete();
        app(RepositorioAjustes::class)->invalidar();

        $this->actingAs($this->admin());
        Ajustes::guardar('contabilidad.correo', 'nuevo@ejemplo.com');
        Configuracion::set('contabilidad.correo', 'viejo@ejemplo.com');
        Configuracion::olvidarCache();

        $this->migrar();

        $this->assertDatabaseHas('ajustes_sistema', ['clave' => 'contabilidad.correo', 'valor' => 'nuevo@ejemplo.com']);
        $this->assertDatabaseMissing('configuraciones', ['clave' => 'contabilidad.correo']);
        $this->assertSame(1, DB::table('ajustes_sistema')->where('clave', 'contabilidad.correo')->count());
    }

    // -------------------------------------------------------------- reversa

    public function test_la_migracion_es_reversible(): void
    {
        $this->comoAntesDeMigrar();
        $this->migrar();
        $this->revertir();

        $this->assertDatabaseHas('configuraciones', ['clave' => 'contabilidad.correo', 'valor' => 'conta@ejemplo.com']);
        $this->assertDatabaseMissing('ajustes_sistema', ['clave' => 'contabilidad.correo']);

        // Y el sistema sigue resolviendo lo mismo que antes de todo.
        $this->assertSame('conta@ejemplo.com', Ajustes::texto('contabilidad.correo'));
        $this->assertTrue(Ajustes::bool('correo.auto_envio'));
    }

    // -------------------------------------------------------- transición

    /**
     * La ventana entre subir el código y correr la migración: el valor sigue en la
     * tabla vieja y el sistema tiene que seguir leyéndolo. Sin esto, las copias a
     * contabilidad dejarían de salir sin que nadie hubiera cambiado nada.
     */
    public function test_antes_de_migrar_se_sigue_leyendo_la_tabla_anterior(): void
    {
        $this->comoAntesDeMigrar();

        $this->assertSame('conta@ejemplo.com', Ajustes::texto('contabilidad.correo'));
        $this->assertTrue(Ajustes::bool('correo.auto_envio'));
        $this->assertSame(FuenteAjuste::BaseDeDatosLegacy, Ajustes::fuente('contabilidad.correo'));
        $this->assertSame('conta@ejemplo.com', app(CorreoContabilidad::class)->direccion());
    }

    /** Después de migrar, la fuente de verdad es la tabla nueva. */
    public function test_despues_de_migrar_la_fuente_es_la_tabla_nueva(): void
    {
        $this->comoAntesDeMigrar();
        $this->migrar();

        $this->assertSame('conta@ejemplo.com', Ajustes::texto('contabilidad.correo'));
        $this->assertSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente('contabilidad.correo'));
    }

    /** Escribir va SIEMPRE a la tabla nueva, aunque el valor viejo siga ahí. */
    public function test_escribir_antes_de_migrar_va_a_la_tabla_nueva(): void
    {
        $this->comoAntesDeMigrar();
        $this->actingAs($this->admin());

        Ajustes::guardar('contabilidad.correo', 'otro@ejemplo.com');

        $this->assertDatabaseHas('ajustes_sistema', ['clave' => 'contabilidad.correo', 'valor' => 'otro@ejemplo.com']);
        $this->assertSame('otro@ejemplo.com', Ajustes::texto('contabilidad.correo'));
        $this->assertSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente('contabilidad.correo'));
    }

    // ------------------------------------------------------- consumidores

    public function test_los_consumidores_siguen_comportandose_igual(): void
    {
        $this->comoAntesDeMigrar();
        $this->migrar();

        $contabilidad = app(CorreoContabilidad::class);

        $this->assertSame('conta@ejemplo.com', $contabilidad->direccion());
        $this->assertTrue($contabilidad->enviarCopia());
        $this->assertSame('conta@ejemplo.com', $contabilidad->copiaOculta());
        $this->assertTrue(Ajustes::bool('correo.adjuntar_jws'));
        $this->assertSame('Hola {{cliente}}', Ajustes::texto('correo.plantilla'));
    }

    /** La pantalla de contabilidad sigue guardando y leyendo lo mismo. */
    public function test_la_pantalla_de_contabilidad_sigue_funcionando(): void
    {
        $this->migrar();

        $this->actingAs($this->admin())
            ->put(route('configuracion.contabilidad.update'), [
                'correo_contabilidad' => 'nueva@ejemplo.com',
                'enviar_copia_contabilidad' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('ajustes_sistema', ['clave' => 'contabilidad.correo', 'valor' => 'nueva@ejemplo.com']);
        $this->assertDatabaseMissing('configuraciones', ['clave' => 'contabilidad.correo']);
    }

    // ------------------------------------------------ el efecto invisible

    /**
     * EL MOTIVO TÉCNICO DE LA MUDANZA.
     *
     * Mientras `correo.auto_envio` vivía en `configuraciones`, lo leía una caché
     * ESTÁTICA de proceso: un worker que ya la había leído se quedaba con ese valor
     * hasta reiniciarse. Se reproduce el escenario exacto —un proceso que leyó
     * antes, otro que escribe después— y se comprueba que ahora sí se entera.
     *
     * "Otro proceso" es una instancia independiente del resolver con su propia
     * memoria y la misma caché compartida, que es la relación real entre la
     * petición web que guarda y el worker que envía.
     */
    public function test_migrar_elimina_la_necesidad_de_reiniciar_el_worker(): void
    {
        $this->migrar();
        $this->actingAs($this->admin());

        Ajustes::guardar('correo.auto_envio', false);

        $worker = $this->otroProceso();
        $this->assertFalse($worker->bool('correo.auto_envio'), 'El worker arranca leyendo false.');

        // La petición web lo activa.
        Ajustes::guardar('correo.auto_envio', true);

        $this->assertTrue(
            $worker->bool('correo.auto_envio'),
            'El worker debe ver el cambio SIN reiniciarse.',
        );
    }

    /** El contraste: la tabla vieja sí necesitaba que alguien olvidara su caché. */
    public function test_la_tabla_anterior_conservaba_el_valor_en_memoria(): void
    {
        Configuracion::set('correo.auto_envio', false);
        Configuracion::olvidarCache();

        // Un "proceso" que ya leyó.
        $this->assertFalse(Configuracion::getBool('correo.auto_envio'));

        // Otro escribe DIRECTO en la tabla, como haría un proceso distinto.
        DB::table('configuraciones')->where('clave', 'correo.auto_envio')->update(['valor' => '1']);

        $this->assertFalse(
            Configuracion::getBool('correo.auto_envio'),
            'La caché estática de la tabla anterior conserva el valor viejo: por esto se migró.',
        );

        Configuracion::olvidarCache();
        $this->assertTrue(Configuracion::getBool('correo.auto_envio'));
    }

    // ---------------------------------------------------------------- ayuda

    /** Resolver independiente: memoria propia, caché compartida. */
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
}
