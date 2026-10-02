<?php

namespace Tests\Feature\Seguridad;

use App\Enums\PermisoSistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sesiones, inicio de sesión y contraseñas.
 *
 * Cada prueba cubre algo que antes no se cumplía: un usuario desactivado seguía dentro,
 * cambiarle la contraseña no cerraba sus sesiones, la política de contraseñas no regía en el
 * perfil ni en el restablecimiento, el límite de intentos no leía su configuración ni tenía
 * techo por IP, y los accesos no quedaban en la bitácora.
 */
class SesionesYAccesoTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE_FUERTE = 'Clave#Segura2026';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        RateLimiter::clear('login-ip|127.0.0.1');
    }

    private function admin(): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions(['usuarios.gestionar']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function sesionGuardada(User $usuario, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $usuario->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'prueba',
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    // ------------------------------------------------------------ usuario desactivado

    public function test_un_usuario_desactivado_con_la_sesion_abierta_queda_fuera_en_la_peticion_siguiente(): void
    {
        $usuario = User::factory()->create(['activo' => true]);
        $usuario->forceFill(['activo' => false])->saveQuietly();

        $this->actingAs($usuario->fresh())
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_desactivar_a_un_usuario_borra_sus_sesiones_y_su_token_de_recordarme(): void
    {
        config(['session.driver' => 'database']);
        $usuario = User::factory()->create(['activo' => true, 'remember_token' => 'token-viejo']);
        $this->sesionGuardada($usuario, 'sesion-del-usuario');

        $this->actingAs($this->admin())
            ->patch(route('usuarios.toggle-activo', $usuario))
            ->assertRedirect();

        $this->assertFalse($usuario->fresh()->activo);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->id)->count());
        $this->assertNotSame('token-viejo', $usuario->fresh()->remember_token);
    }

    public function test_cambiarle_la_contrasena_a_otro_usuario_cierra_todas_sus_sesiones(): void
    {
        config(['session.driver' => 'database']);
        $usuario = User::factory()->create(['activo' => true, 'remember_token' => 'token-viejo']);
        $this->sesionGuardada($usuario, 'sesion-del-usuario');

        $this->actingAs($this->admin())
            ->put(route('usuarios.password.update', $usuario), [
                'password' => self::CLAVE_FUERTE,
                'password_confirmation' => self::CLAVE_FUERTE,
            ])->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->id)->count());
        $this->assertNotSame('token-viejo', $usuario->fresh()->remember_token);
    }

    public function test_cambiar_la_contrasena_propia_conserva_la_sesion_actual_y_cierra_las_demas(): void
    {
        config(['session.driver' => 'database']);
        $usuario = User::factory()->create(['activo' => true, 'password' => Hash::make('password')]);
        $this->sesionGuardada($usuario, 'otro-dispositivo');

        $this->actingAs($usuario)
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => self::CLAVE_FUERTE,
                'password_confirmation' => self::CLAVE_FUERTE,
            ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($usuario);
        $this->assertSame(0, DB::table('sessions')->where('id', 'otro-dispositivo')->count());
    }

    // ------------------------------------------------------------ política de contraseñas

    public function test_cambiar_la_contrasena_propia_exige_la_politica_del_sistema(): void
    {
        $usuario = User::factory()->create(['password' => Hash::make('password')]);

        $this->actingAs($usuario)
            ->from('/profile')
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'clavecorta1',
                'password_confirmation' => 'clavecorta1',
            ])->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->assertTrue(Hash::check('password', $usuario->fresh()->password));
    }

    // ------------------------------------------------------------ límite de intentos

    public function test_el_limite_de_intentos_por_cuenta_sale_de_la_configuracion(): void
    {
        config(['security.login_throttle.max_attempts' => 2]);
        $usuario = User::factory()->create();

        foreach (range(1, 2) as $_) {
            $this->post('/login', ['email' => $usuario->email, 'password' => 'mala']);
        }

        $this->post('/login', ['email' => $usuario->email, 'password' => 'mala'])
            ->assertSessionHasErrors('email');
        $this->assertNotSame(trans('auth.failed'), session('errors')->first('email'));
    }

    public function test_probar_muchas_cuentas_desde_una_ip_toca_el_techo_por_ip(): void
    {
        config(['security.login_throttle.max_attempts' => 5, 'security.login_throttle.max_attempts_por_ip' => 6]);

        foreach (range(1, 6) as $i) {
            $this->post('/login', ['email' => "cuenta{$i}@example.com", 'password' => 'mala']);
        }

        $respuesta = $this->post('/login', ['email' => 'otra-cuenta@example.com', 'password' => 'mala']);

        // Bloqueado aunque esta cuenta no haya fallado nunca: el mensaje es el de bloqueo.
        $respuesta->assertSessionHasErrors('email');
        $this->assertNotSame(trans('auth.failed'), session('errors')->first('email'));
    }

    /**
     * Detrás del túnel de Cloudflare (o de Tailscale Serve) TODO llega desde 127.0.0.1, el
     * proxy de confianza. El techo por IP tiene que contar la IP del visitante que el proxy
     * pone al final de X-Forwarded-For; si contara 127.0.0.1, veinte fallos de cualquiera
     * dejarían sin login a todos los que entran por Internet.
     */
    public function test_detras_del_proxy_de_confianza_el_techo_cuenta_la_ip_real_del_visitante(): void
    {
        config(['security.login_throttle.max_attempts' => 5, 'security.login_throttle.max_attempts_por_ip' => 6]);
        $desdeProxy = fn (string $reenviada) => $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-For', $reenviada);

        foreach (range(1, 6) as $i) {
            $desdeProxy('203.0.113.10')->post('/login', ['email' => "cuenta{$i}@example.com", 'password' => 'mala']);
        }

        // Otro visitante, mismo proxy: no hereda el bloqueo.
        $desdeProxy('203.0.113.20')->post('/login', ['email' => 'nueva@example.com', 'password' => 'mala'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        // El atacante no se libra anteponiendo una IP inventada: manda la del final.
        $desdeProxy('198.51.100.99, 203.0.113.10')->post('/login', ['email' => 'otra@example.com', 'password' => 'mala'])
            ->assertSessionHasErrors('email');
        $this->assertNotSame(trans('auth.failed'), session('errors')->first('email'));
    }

    public function test_una_cuenta_inactiva_recibe_el_mismo_mensaje_que_una_contrasena_equivocada(): void
    {
        $usuario = User::factory()->create(['activo' => false, 'password' => Hash::make('password')]);

        $this->post('/login', ['email' => $usuario->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
    }

    // ------------------------------------------------------------ bitácora de accesos

    public function test_los_accesos_y_los_intentos_fallidos_quedan_en_la_bitacora_sin_la_contrasena(): void
    {
        $usuario = User::factory()->create(['password' => Hash::make('password')]);

        $this->post('/login', ['email' => $usuario->email, 'password' => 'mala-clave-xyz']);
        $this->post('/login', ['email' => $usuario->email, 'password' => 'password']);
        $this->post('/logout');

        $descripciones = Activity::where('log_name', 'acceso')->pluck('description')->all();

        $this->assertContains('Intento de inicio de sesión fallido', $descripciones);
        $this->assertContains('Inicio de sesión', $descripciones);
        $this->assertContains('Cierre de sesión', $descripciones);
        $this->assertStringNotContainsString(
            'mala-clave-xyz',
            Activity::where('log_name', 'acceso')->get()->toJson(),
        );
    }
}
