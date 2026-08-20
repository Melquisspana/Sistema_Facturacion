<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\Ceremonias\AccionCriticaN3;
use App\Ajustes\Ceremonias\CeremoniaN3;
use App\Ajustes\Excepciones\AjusteNoEditableException;
use App\Enums\RolSistema;
use App\Facades\Ajustes;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ceremonia N3: permiso + precondiciones + frase exacta + contraseña.
 *
 * SE PRUEBA CON UNA ACCIÓN DE MENTIRA, no con un cambio fiscal real. En esta fase
 * el ambiente del Ministerio de Hacienda, la firma y la transmisión siguen siendo
 * de solo lectura, y usar uno de ellos como banco de pruebas significaría abrir
 * justo lo que no toca abrir todavía. La acción de este archivo solo escribe en
 * una variable local: alcanza para demostrar que la ceremonia deja pasar cuando
 * debe y bloquea cuando debe.
 */
class CeremoniaN3Test extends TestCase
{
    use RefreshDatabase;

    /** La que crea UserFactory. */
    private const PASSWORD = 'password';

    private const FRASE = 'CAMBIAR A PRODUCCION';

    /** Se pone a true si la acción llega a ejecutarse. */
    private bool $ejecutada = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ejecutada = false;
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        RateLimiter::clear('ceremonia-n3:accion.de.prueba:1');
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    /** Usuario con `configuracion.gestionar` pero SIN `configuracion.critica`. */
    private function gestorNoCritico(): User
    {
        $rol = Role::findOrCreate('gestor_configuracion', 'web');
        $rol->syncPermissions([Permission::findOrCreate('configuracion.gestionar', 'web')]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return User::factory()->create(['activo' => true])->assignRole('gestor_configuracion');
    }

    /** @param  array<int, \Closure>  $precondiciones */
    private function accion(array $precondiciones = []): AccionCriticaN3
    {
        return new AccionCriticaN3(
            clave: 'accion.de.prueba',
            titulo: 'Acción de prueba',
            consecuencia: 'No hace nada real: existe para probar la ceremonia.',
            frase: self::FRASE,
            ejecutar: function () {
                $this->ejecutada = true;

                return 'listo';
            },
            precondiciones: $precondiciones,
        );
    }

    private function ceremonia(): CeremoniaN3
    {
        return app(CeremoniaN3::class);
    }

    // ------------------------------------------------------------- permiso

    public function test_sin_permiso_critico_lanza_403(): void
    {
        $this->actingAs($this->gestorNoCritico());

        $this->expectException(AuthorizationException::class);

        $this->ceremonia()->ejecutar($this->accion(), self::FRASE, self::PASSWORD);
    }

    public function test_sin_permiso_critico_la_accion_no_se_ejecuta(): void
    {
        $this->actingAs($this->gestorNoCritico());

        try {
            $this->ceremonia()->ejecutar($this->accion(), self::FRASE, self::PASSWORD);
        } catch (AuthorizationException) {
            // Esperado.
        }

        $this->assertFalse($this->ejecutada);
    }

    public function test_sin_usuario_autenticado_lanza_403(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->ceremonia()->ejecutar($this->accion(), self::FRASE, self::PASSWORD);
    }

    public function test_solo_el_administrador_puede_ejecutar_acciones_criticas(): void
    {
        foreach (RolSistema::cases() as $rol) {
            $usuario = User::factory()->create(['activo' => true])->assignRole($rol->value);

            $this->assertSame(
                $rol === RolSistema::Administrador,
                $this->ceremonia()->puedeEjecutar($usuario),
                "El rol {$rol->value} no debería poder ejecutar acciones N3.",
            );
        }
    }

    // ------------------------------------------------------- precondiciones

    public function test_una_precondicion_incumplida_rechaza_la_accion(): void
    {
        $this->actingAs($this->admin());

        $resultado = $this->ceremonia()->ejecutar(
            $this->accion([fn () => 'no hay un respaldo válido de hoy']),
            self::FRASE,
            self::PASSWORD,
        );

        $this->assertFalse($resultado->ejecutada);
        $this->assertStringContainsString('no hay un respaldo válido de hoy', $resultado->mensaje);
        $this->assertFalse($this->ejecutada);
    }

    /** Se comprueban ANTES de la frase: no se hace escribir para después negar. */
    public function test_la_precondicion_se_comprueba_antes_que_la_frase(): void
    {
        $this->actingAs($this->admin());

        $resultado = $this->ceremonia()->ejecutar(
            $this->accion([fn () => 'falta algo']),
            'frase incorrecta',
            'password incorrecta',
        );

        $this->assertStringContainsString('falta algo', $resultado->mensaje);
    }

    public function test_las_precondiciones_cumplidas_dejan_pasar(): void
    {
        $this->actingAs($this->admin());

        $resultado = $this->ceremonia()->ejecutar(
            $this->accion([fn () => null, fn () => null]),
            self::FRASE,
            self::PASSWORD,
        );

        $this->assertTrue($resultado->ejecutada);
    }

    // -------------------------------------------------------------- frase

    public function test_una_frase_incorrecta_rechaza_la_accion(): void
    {
        $this->actingAs($this->admin());

        $resultado = $this->ceremonia()->ejecutar($this->accion(), 'cambiar a produccion', self::PASSWORD);

        $this->assertFalse($resultado->ejecutada);
        $this->assertSame('frase', $resultado->campo);
        $this->assertFalse($this->ejecutada);
    }

    public function test_la_frase_se_compara_con_mayusculas_y_acentos(): void
    {
        $this->actingAs($this->admin());

        foreach (['CAMBIAR A PRODUCCIÓN', 'cambiar a produccion', 'CAMBIARAPRODUCCION', ''] as $intento) {
            $resultado = $this->ceremonia()->ejecutar($this->accion(), $intento, self::PASSWORD);
            $this->assertFalse($resultado->ejecutada, "«{$intento}» no debería aceptarse.");
        }
    }

    /** Los espacios de los extremos sí se perdonan: pegar la frase no debe castigar. */
    public function test_la_frase_admite_espacios_alrededor(): void
    {
        $this->actingAs($this->admin());

        $resultado = $this->ceremonia()->ejecutar($this->accion(), '  '.self::FRASE.'  ', self::PASSWORD);

        $this->assertTrue($resultado->ejecutada);
    }

    // ----------------------------------------------------------- contraseña

    public function test_una_contrasena_incorrecta_rechaza_la_accion(): void
    {
        $this->actingAs($this->admin());

        $resultado = $this->ceremonia()->ejecutar($this->accion(), self::FRASE, 'no-es-mi-password');

        $this->assertFalse($resultado->ejecutada);
        $this->assertSame('password', $resultado->campo);
        $this->assertFalse($this->ejecutada);
    }

    public function test_una_contrasena_vacia_rechaza_la_accion(): void
    {
        $this->actingAs($this->admin());

        $resultado = $this->ceremonia()->ejecutar($this->accion(), self::FRASE, null);

        $this->assertFalse($resultado->ejecutada);
        $this->assertFalse($this->ejecutada);
    }

    /** El formulario acepta contraseñas: no puede ser un oráculo sin límite. */
    public function test_los_intentos_fallidos_se_limitan(): void
    {
        $this->actingAs($this->admin());

        for ($i = 0; $i < 5; $i++) {
            $this->ceremonia()->ejecutar($this->accion(), self::FRASE, 'mal');
        }

        $resultado = $this->ceremonia()->ejecutar($this->accion(), self::FRASE, 'mal');

        $this->assertFalse($resultado->ejecutada);
        $this->assertStringContainsString('Demasiados intentos', $resultado->mensaje);
    }

    // --------------------------------------------------------------- éxito

    public function test_la_ceremonia_completa_ejecuta_el_callback(): void
    {
        $this->actingAs($this->admin());

        $resultado = $this->ceremonia()->ejecutar($this->accion(), self::FRASE, self::PASSWORD);

        $this->assertTrue($resultado->ejecutada);
        $this->assertTrue($this->ejecutada);
        $this->assertSame('listo', $resultado->valor);
    }

    public function test_devuelve_el_aviso_persistente_de_la_accion(): void
    {
        $this->actingAs($this->admin());

        $accion = new AccionCriticaN3(
            clave: 'accion.con.aviso',
            titulo: 'Acción con aviso',
            consecuencia: 'Prueba.',
            frase: self::FRASE,
            ejecutar: static fn () => null,
            avisoPersistente: 'El sistema quedó en un estado que hay que revisar.',
        );

        $resultado = $this->ceremonia()->ejecutar($accion, self::FRASE, self::PASSWORD);

        $this->assertTrue($resultado->ejecutada);
        $this->assertSame('El sistema quedó en un estado que hay que revisar.', $resultado->avisoPersistente);
    }

    // ------------------------------------------------------------ auditoría

    public function test_la_ejecucion_queda_auditada(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->ceremonia()->ejecutar($this->accion(), self::FRASE, self::PASSWORD);

        $actividad = Activity::query()->where('log_name', 'ajustes')->latest('id')->firstOrFail();

        $this->assertStringContainsString('ejecutó la acción crítica', (string) $actividad->description);
        $this->assertSame('n3', $actividad->getExtraProperty('nivel'));
        $this->assertSame('ejecutada', $actividad->getExtraProperty('resultado'));
        $this->assertSame($admin->id, $actividad->causer_id);
    }

    public function test_el_rechazo_tambien_queda_auditado(): void
    {
        $this->actingAs($this->admin());

        $this->ceremonia()->ejecutar($this->accion(), 'frase mala', self::PASSWORD);

        $actividad = Activity::query()->where('log_name', 'ajustes')->latest('id')->firstOrFail();

        $this->assertStringContainsString('intentó la acción crítica', (string) $actividad->description);
        $this->assertSame('rechazada', $actividad->getExtraProperty('resultado'));
    }

    /** LA regla: la contraseña de reautenticación no se guarda ni se registra. */
    public function test_la_contrasena_de_reautenticacion_no_se_audita(): void
    {
        $this->actingAs($this->admin());

        $this->ceremonia()->ejecutar($this->accion(), self::FRASE, self::PASSWORD);
        $this->ceremonia()->ejecutar($this->accion(), self::FRASE, 'otra-password-secreta');

        foreach (Activity::all() as $actividad) {
            $volcado = $actividad->description.' '.json_encode($actividad->properties, JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString(self::PASSWORD, $volcado);
            $this->assertStringNotContainsString('otra-password-secreta', $volcado);
        }
    }

    // ------------------------------------------------- lo fiscal sigue cerrado

    /** La ceremonia existe, pero no se ha abierto ningún ajuste fiscal con ella. */
    public function test_los_ajustes_fiscales_siguen_siendo_de_solo_lectura(): void
    {
        $admin = $this->admin();
        config(['dte.ambiente' => '00', 'dte.transmision.ambiente' => 'testing']);

        foreach (['dte.ambiente', 'dte.transmision.ambiente', 'dte.firma.enabled', 'dte.transmision.enabled'] as $clave) {
            $this->assertFalse(Ajustes::puedeEditar($admin, $clave));
        }

        $this->actingAs($admin);
        $this->expectException(AjusteNoEditableException::class);
        Ajustes::guardar('dte.ambiente', '01');
    }
}
