<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\Definicion\FuenteAjuste;
use App\Facades\Ajustes;
use App\Models\AjusteSistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PRIMER SECRETO EDITABLE DEL SISTEMA: la contraseña del servidor SMTP.
 *
 * Este archivo es el que hay que poder releer dentro de un año y seguir confiando.
 * Cubre las cuatro salidas por las que la contraseña podría escaparse —el HTML, un
 * JSON, la base de datos y la auditoría— y las dos formas de perderla por
 * descuido: guardar el formulario en blanco y quitar el override sin querer.
 */
class PasswordSmtpTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'c0ntr4s3n4-smtp-de-prueba';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    // ------------------------------------------------------------- pantalla

    public function test_la_pantalla_no_precarga_la_contrasena(): void
    {
        $this->actingAs($this->admin());
        Ajustes::guardar('mail.smtp.password', self::SECRETO);

        $html = $this->get(route('configuracion.correo.smtp.password.edit'))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::SECRETO, $html);
        // El campo existe pero llega sin valor.
        $this->assertStringNotContainsString('name="password" value=', $html);
        $this->assertStringContainsString('autocomplete="new-password"', $html);
    }

    /** Ni el valor ni nada que insinúe su longitud. */
    public function test_la_pantalla_no_revela_la_longitud(): void
    {
        $this->actingAs($this->admin());
        Ajustes::guardar('mail.smtp.password', self::SECRETO);

        $html = $this->get(route('configuracion.correo.smtp.password.edit'))->assertOk()->getContent();

        // El relleno decorativo es SIEMPRE de ocho puntos, mida lo que mida.
        $this->assertStringContainsString('••••••••', $html);
        $this->assertStringNotContainsString(str_repeat('•', mb_strlen(self::SECRETO)), $html);
    }

    public function test_la_pantalla_de_correo_no_muestra_la_contrasena(): void
    {
        $this->actingAs($this->admin());
        Ajustes::guardar('mail.smtp.password', self::SECRETO);

        $this->get(route('configuracion.correo.edit'))
            ->assertOk()
            ->assertDontSee(self::SECRETO)
            ->assertSee('Configurada');
    }

    // ------------------------------------------------------------ reemplazo

    public function test_reemplazar_guarda_la_contrasena_cifrada(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.correo.smtp.password.update'), [
                'password' => self::SECRETO,
                'confirmacion' => '1',
            ])
            ->assertRedirect(route('configuracion.correo.edit'))
            ->assertSessionHas('status');

        $fila = AjusteSistema::query()->where('clave', 'mail.smtp.password')->firstOrFail();

        $this->assertTrue((bool) $fila->cifrado);
        $this->assertNotSame(self::SECRETO, $fila->valor);
        $this->assertStringNotContainsString(self::SECRETO, (string) $fila->valor);

        // Y se recupera correctamente para quien tiene que usarla.
        $this->assertSame(self::SECRETO, Ajustes::secretoParaRuntime('mail.smtp.password'));
    }

    /** LA regla del formulario: en blanco no borra nada. */
    public function test_un_envio_vacio_no_borra_la_contrasena(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Ajustes::guardar('mail.smtp.password', self::SECRETO);

        $this->actingAs($admin)
            ->put(route('configuracion.correo.smtp.password.update'), [
                'password' => '',
                'confirmacion' => '1',
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame(self::SECRETO, Ajustes::secretoParaRuntime('mail.smtp.password'));
        $this->assertDatabaseCount('ajustes_sistema', 1);
    }

    /** Y tampoco crea un override vacío cuando no había ninguno. */
    public function test_un_envio_vacio_no_crea_un_override_vacio(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.correo.smtp.password.update'), ['password' => '', 'confirmacion' => '1'])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('ajustes_sistema', 0);
    }

    /** N2: sin marcar la confirmación no se escribe, aunque la contraseña sea válida. */
    public function test_sin_confirmacion_no_se_reemplaza(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.correo.smtp.password.update'), ['password' => self::SECRETO])
            ->assertSessionHasErrors('confirmacion');

        $this->assertDatabaseCount('ajustes_sistema', 0);
    }

    // -------------------------------------------------------- quitar override

    public function test_quitar_el_override_vuelve_al_valor_de_config(): void
    {
        config(['mail.mailers.smtp.password' => 'clave-del-env']);
        $admin = $this->admin();

        $this->actingAs($admin);
        Ajustes::guardar('mail.smtp.password', self::SECRETO);
        $this->assertSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente('mail.smtp.password'));

        $this->actingAs($admin)
            ->delete(route('configuracion.correo.smtp.password.destroy'), ['confirmacion' => '1'])
            ->assertRedirect(route('configuracion.correo.edit'));

        $this->assertSame('clave-del-env', Ajustes::secretoParaRuntime('mail.smtp.password'));
        $this->assertSame(FuenteAjuste::Configuracion, Ajustes::fuente('mail.smtp.password'));
        $this->assertDatabaseMissing('ajustes_sistema', ['clave' => 'mail.smtp.password']);
    }

    public function test_quitar_el_override_exige_confirmacion(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Ajustes::guardar('mail.smtp.password', self::SECRETO);

        $this->actingAs($admin)
            ->delete(route('configuracion.correo.smtp.password.destroy'))
            ->assertSessionHasErrors('confirmacion');

        $this->assertDatabaseCount('ajustes_sistema', 1);
    }

    /** Quitar el override NO toca el archivo de configuración. */
    public function test_quitar_el_override_no_toca_config(): void
    {
        config(['mail.mailers.smtp.password' => 'clave-del-env']);
        $admin = $this->admin();

        $this->actingAs($admin);
        Ajustes::guardar('mail.smtp.password', self::SECRETO);
        $this->actingAs($admin)->delete(route('configuracion.correo.smtp.password.destroy'), ['confirmacion' => '1']);

        $this->assertSame('clave-del-env', config('mail.mailers.smtp.password'));
    }

    /** El botón de quitar solo aparece si hay algo guardado que quitar. */
    public function test_sin_override_no_se_ofrece_quitarlo(): void
    {
        config(['mail.mailers.smtp.password' => 'clave-del-env']);

        $this->actingAs($this->admin())
            ->get(route('configuracion.correo.smtp.password.edit'))
            ->assertOk()
            ->assertDontSee('Quitar valor guardado');
    }

    // -------------------------------------------------------------- fugas

    public function test_la_contrasena_nunca_vuelve_en_el_html(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Ajustes::guardar('mail.smtp.password', self::SECRETO);

        foreach ([
            route('configuracion.correo.edit'),
            route('configuracion.correo.smtp.password.edit'),
            route('configuracion.resumen'),
        ] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString(self::SECRETO, $html, "Se filtró la contraseña en {$url}.");
        }
    }

    public function test_la_contrasena_nunca_vuelve_en_json(): void
    {
        $this->actingAs($this->admin());
        Ajustes::guardar('mail.smtp.password', self::SECRETO);

        $json = json_encode([
            'estado' => Ajustes::estadoParaPantalla('mail.smtp.password'),
            'seccion' => Ajustes::estadosDeSeccion('correo_saliente'),
            'modelo' => AjusteSistema::query()->where('clave', 'mail.smtp.password')->first(),
        ], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::SECRETO, (string) $json);
    }

    public function test_la_contrasena_nunca_aparece_en_la_auditoria(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('configuracion.correo.smtp.password.update'), [
            'password' => self::SECRETO,
            'confirmacion' => '1',
        ])->assertRedirect();

        $this->actingAs($admin)->delete(route('configuracion.correo.smtp.password.destroy'), [
            'confirmacion' => '1',
        ])->assertRedirect();

        $this->assertGreaterThan(0, Activity::query()->where('log_name', 'ajustes')->count());

        foreach (Activity::all() as $actividad) {
            $volcado = $actividad->description.' '.json_encode($actividad->properties, JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString(self::SECRETO, $volcado);
            $this->assertStringNotContainsString(md5(self::SECRETO), $volcado);
            $this->assertStringNotContainsString(sha1(self::SECRETO), $volcado);
        }
    }

    /** El reemplazo queda auditado como hecho, con su clave y sin valores. */
    public function test_el_reemplazo_queda_auditado(): void
    {
        $this->actingAs($this->admin())->put(route('configuracion.correo.smtp.password.update'), [
            'password' => self::SECRETO,
            'confirmacion' => '1',
        ])->assertRedirect();

        $actividad = Activity::query()->where('log_name', 'ajustes')->latest('id')->firstOrFail();

        $this->assertStringContainsString('reemplazó el secreto', (string) $actividad->description);
        $this->assertSame('mail.smtp.password', $actividad->getExtraProperty('clave'));
        $this->assertNull($actividad->getExtraProperty('valor_antes'));
        $this->assertNull($actividad->getExtraProperty('valor_despues'));
    }

    // -------------------------------------------------------------- accesos

    public function test_un_rol_sin_permiso_no_llega_a_la_pantalla(): void
    {
        $usuario = User::factory()->create(['activo' => true])->assignRole('facturacion');

        $this->actingAs($usuario)->get(route('configuracion.correo.smtp.password.edit'))->assertForbidden();
        $this->actingAs($usuario)->put(route('configuracion.correo.smtp.password.update'), [
            'password' => self::SECRETO, 'confirmacion' => '1',
        ])->assertForbidden();

        $this->assertDatabaseCount('ajustes_sistema', 0);
    }
}
