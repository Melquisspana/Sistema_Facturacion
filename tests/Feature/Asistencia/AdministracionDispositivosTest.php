<?php

namespace Tests\Feature\Asistencia;

use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaDispositivo;
use App\Models\User;
use Database\Factories\Asistencia\AsistenciaDispositivoFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Administración de lectores biométricos desde la web.
 *
 * LA GARANTÍA CENTRAL: el token en claro sale del servidor UNA vez y nunca más, y
 * el `token_hash` no sale jamás. Todo lo demás de esta pantalla es un CRUD; esto
 * es lo que hay que blindar con pruebas, porque es lo único irreversible.
 *
 * La segunda: `asistencia.dispositivos.gestionar` es un permiso APARTE de
 * `asistencia.gestionar`. Quien administra al personal no puede dejar el lector de
 * la puerta sin autenticar.
 */
class AdministracionDispositivosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        Role::findOrCreate('administrador', 'web')->syncPermissions(PermisoSistema::todos());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('asistencia.enabled', true);
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    /** Administra PERSONAS pero no lectores: la separación que hay que probar. */
    private function gestorDePersonal(): User
    {
        return User::factory()->create(['activo' => true])->givePermissionTo([
            PermisoSistema::AsistenciaVer->value,
            PermisoSistema::AsistenciaGestionar->value,
        ]);
    }

    // ─────────────────────────────── Alta ───────────────────────────────

    public function test_dar_de_alta_un_lector_genera_su_token_y_lo_muestra_una_vez(): void
    {
        $respuesta = $this->actingAs($this->admin())
            ->post(route('asistencia.dispositivos.store'), [
                'codigo' => 'lector-entrada', 'nombre' => 'Entrada principal',
            ]);

        $respuesta->assertSessionHasNoErrors()->assertSessionHas('token_generado');

        $token = session('token_generado');
        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));

        // Lo que quedó en base es el HASH del token que se mostró, nunca el valor.
        $lector = AsistenciaDispositivo::sole();
        $this->assertTrue($lector->tokenCoincide($token));
        $this->assertNotSame($token, $lector->token_hash);
    }

    /**
     * «Una vez» significa una petición. El token llega por `flash`: la pantalla
     * siguiente ya no lo tiene, y no hay ninguna ruta que pueda devolverlo.
     */
    public function test_el_token_desaparece_en_la_peticion_siguiente(): void
    {
        $this->actingAs($this->admin())
            ->post(route('asistencia.dispositivos.store'), ['codigo' => 'l1', 'nombre' => 'L1'])
            ->assertRedirect(route('asistencia.dispositivos.index'));

        // Primera visita: se ve.
        $this->actingAs($this->admin())
            ->get(route('asistencia.dispositivos.index'))
            ->assertOk()
            ->assertSee('copialo ahora', escape: false);

        // Recarga: ya no está.
        $this->actingAs($this->admin())
            ->get(route('asistencia.dispositivos.index'))
            ->assertOk()
            ->assertDontSee('copialo ahora', escape: false);
    }

    public function test_el_listado_nunca_publica_el_hash_del_token(): void
    {
        $lector = AsistenciaDispositivo::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('asistencia.dispositivos.index'))
            ->assertOk()
            ->assertDontSee($lector->token_hash)
            ->assertDontSee(AsistenciaDispositivoFactory::TOKEN_DE_PRUEBA);
    }

    public function test_el_codigo_debe_ser_un_identificador_de_maquina(): void
    {
        $this->actingAs($this->admin())
            ->post(route('asistencia.dispositivos.store'), ['codigo' => 'Lector Entrada', 'nombre' => 'X'])
            ->assertSessionHasErrors('codigo');

        $this->assertSame(0, AsistenciaDispositivo::count());
    }

    public function test_el_codigo_no_se_puede_repetir(): void
    {
        AsistenciaDispositivo::factory()->create(['codigo' => 'lector-entrada']);

        $this->actingAs($this->admin())
            ->post(route('asistencia.dispositivos.store'), ['codigo' => 'lector-entrada', 'nombre' => 'Otro'])
            ->assertSessionHasErrors('codigo');

        $this->assertSame(1, AsistenciaDispositivo::count());
    }

    /** El formulario no puede FIJAR un token: si pudiera, se podría poner uno conocido. */
    public function test_el_formulario_no_puede_fijar_el_token(): void
    {
        $this->actingAs($this->admin())
            ->post(route('asistencia.dispositivos.store'), [
                'codigo' => 'l1', 'nombre' => 'L1',
                'token_hash' => AsistenciaDispositivo::hashDeToken('token-elegido'),
            ]);

        $this->assertFalse(AsistenciaDispositivo::sole()->tokenCoincide('token-elegido'));
    }

    // ─────────────────────────────── Edición ───────────────────────────────

    public function test_editar_no_toca_el_token(): void
    {
        $lector = AsistenciaDispositivo::factory()->create(['codigo' => 'l1', 'nombre' => 'Viejo']);
        $hashAntes = $lector->token_hash;

        $this->actingAs($this->admin())
            ->put(route('asistencia.dispositivos.update', $lector), ['codigo' => 'l1', 'nombre' => 'Nuevo'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Nuevo', $lector->fresh()->nombre);
        $this->assertSame($hashAntes, $lector->fresh()->token_hash);
        $this->assertTrue($lector->fresh()->tokenCoincide(AsistenciaDispositivoFactory::TOKEN_DE_PRUEBA));
    }

    /** Cambiar el código deja al firmware sin autenticar: hay que decirlo. */
    public function test_cambiar_el_codigo_avisa_de_que_hay_que_tocar_el_firmware(): void
    {
        $lector = AsistenciaDispositivo::factory()->create(['codigo' => 'viejo', 'nombre' => 'L1']);

        $this->actingAs($this->admin())
            ->put(route('asistencia.dispositivos.update', $lector), ['codigo' => 'nuevo', 'nombre' => 'L1']);

        $this->assertStringContainsString('firmware', session('status'));
    }

    public function test_desactivar_un_lector_conserva_su_historial(): void
    {
        $lector = AsistenciaDispositivo::factory()->create();

        $this->actingAs($this->admin())
            ->patch(route('asistencia.dispositivos.toggle-activo', $lector))
            ->assertSessionHas('status');

        $this->assertFalse($lector->fresh()->activo);
        $this->assertDatabaseHas('asistencia_dispositivos', ['id' => $lector->id]);
    }

    // ─────────────────────────── Rotación del token ───────────────────────────

    public function test_la_rotacion_exige_escribir_el_codigo_del_lector(): void
    {
        $lector = AsistenciaDispositivo::factory()->create(['codigo' => 'lector-entrada']);
        $hashAntes = $lector->token_hash;

        // Confirmación equivocada: no rota nada.
        $this->actingAs($this->admin())
            ->post(route('asistencia.dispositivos.rotar-token.ejecutar', $lector), ['confirmacion' => 'lector-bodega'])
            ->assertSessionHasErrors('confirmacion');

        $this->assertSame($hashAntes, $lector->fresh()->token_hash);
        $this->assertNull(session('token_generado'));
    }

    public function test_la_rotacion_sin_confirmacion_no_rota(): void
    {
        $lector = AsistenciaDispositivo::factory()->create(['codigo' => 'lector-entrada']);
        $hashAntes = $lector->token_hash;

        $this->actingAs($this->admin())
            ->post(route('asistencia.dispositivos.rotar-token.ejecutar', $lector), [])
            ->assertSessionHasErrors('confirmacion');

        $this->assertSame($hashAntes, $lector->fresh()->token_hash);
    }

    public function test_con_la_confirmacion_correcta_el_token_se_rota_y_el_anterior_deja_de_servir(): void
    {
        $lector = AsistenciaDispositivo::factory()->create(['codigo' => 'lector-entrada']);

        $this->actingAs($this->admin())
            ->post(route('asistencia.dispositivos.rotar-token.ejecutar', $lector), ['confirmacion' => 'lector-entrada'])
            ->assertSessionHas('token_generado');

        $nuevo = session('token_generado');
        $lector->refresh();

        $this->assertTrue($lector->tokenCoincide($nuevo));
        $this->assertFalse(
            $lector->tokenCoincide(AsistenciaDispositivoFactory::TOKEN_DE_PRUEBA),
            'El token anterior tiene que dejar de autenticar en el acto.'
        );
    }

    /** La pantalla de confirmación explica el coste antes de dejar pulsar. */
    public function test_la_pantalla_de_rotacion_explica_lo_que_va_a_pasar(): void
    {
        $lector = AsistenciaDispositivo::factory()->create(['codigo' => 'lector-entrada']);

        $this->actingAs($this->admin())
            ->get(route('asistencia.dispositivos.rotar-token', $lector))
            ->assertOk()
            ->assertSee('nadie podrá marcar', escape: false)
            ->assertSee('no se puede recuperar', escape: false)
            ->assertDontSee($lector->token_hash);
    }

    /**
     * EL HUECO QUE SE ENCONTRÓ AL EMPEZAR ESTA FASE: `logOnlyDirty` sobre una lista
     * que —correctamente— no incluye `token_hash` producía un diff vacío, y
     * `dontSubmitEmptyLogs` descartaba la entrada entera. El acto más sensible del
     * lector no dejaba rastro. Ahora el HECHO se registra explícitamente.
     */
    public function test_la_rotacion_queda_auditada_sin_guardar_el_token(): void
    {
        $lector = AsistenciaDispositivo::factory()->create(['codigo' => 'lector-entrada']);

        $this->actingAs($this->admin())
            ->post(route('asistencia.dispositivos.rotar-token.ejecutar', $lector), ['confirmacion' => 'lector-entrada']);

        $nuevo = session('token_generado');

        $rotacion = Activity::query()
            ->where('log_name', 'asistencia')
            ->where('description', 'rotó el token del lector de asistencia')
            ->sole();

        $this->assertSame($lector->id, (int) $rotacion->subject_id);
        $this->assertNotNull($rotacion->causer_id, 'Tiene que quedar quién la hizo.');

        $propiedades = json_encode($rotacion->properties);
        $this->assertStringContainsString('lector-entrada', $propiedades);
        $this->assertStringNotContainsString($nuevo, $propiedades);
        $this->assertStringNotContainsString($lector->fresh()->token_hash, $propiedades);
    }

    /** Telemetría del lector: NO ensucia la auditoría, ni desde la web ni desde el lector. */
    public function test_la_telemetria_sigue_fuera_de_la_auditoria(): void
    {
        $lector = AsistenciaDispositivo::factory()->create();
        $antes = Activity::query()->where('subject_type', AsistenciaDispositivo::class)->count();

        $lector->registrarConexion('192.168.1.50');

        $this->assertSame($antes, Activity::query()->where('subject_type', AsistenciaDispositivo::class)->count());
    }

    // ─────────────────────────── Autorización ───────────────────────────

    /**
     * Quien administra PERSONAS no administra LECTORES. Es la separación por la que
     * el permiso existe aparte.
     *
     * @param  array<string, mixed>  $datos
     */
    #[DataProvider('accionesDeLectores')]
    public function test_gestionar_personal_no_alcanza_para_los_lectores(string $verbo, string $ruta, array $datos): void
    {
        $lector = AsistenciaDispositivo::factory()->create(['codigo' => 'lector-entrada', 'nombre' => 'Original']);
        $hashAntes = $lector->token_hash;

        $url = str_contains($ruta, '{d}')
            ? route(str_replace('{d}', '', $ruta), $lector)
            : route($ruta);

        $this->actingAs($this->gestorDePersonal())->$verbo($url, $datos)->assertForbidden();

        $lector->refresh();
        $this->assertSame('Original', $lector->nombre);
        $this->assertSame($hashAntes, $lector->token_hash);
        $this->assertTrue($lector->activo);
    }

    /** Pero SÍ puede ver el listado: `asistencia.ver` alcanza para consultar. */
    public function test_gestionar_personal_alcanza_para_consultar_los_lectores(): void
    {
        AsistenciaDispositivo::factory()->create(['nombre' => 'Entrada principal']);

        $this->actingAs($this->gestorDePersonal())
            ->get(route('asistencia.dispositivos.index'))
            ->assertOk()
            ->assertSee('Entrada principal')
            // Sin los botones que no puede usar.
            ->assertDontSee('Rotar token');
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function accionesDeLectores(): array
    {
        return [
            'formulario de alta' => ['get', 'asistencia.dispositivos.create', []],
            'crear' => ['post', 'asistencia.dispositivos.store', ['codigo' => 'x', 'nombre' => 'X']],
            'formulario de edición' => ['get', 'asistencia.dispositivos.edit{d}', []],
            'editar' => ['put', 'asistencia.dispositivos.update{d}', ['codigo' => 'x', 'nombre' => 'X']],
            'activar o desactivar' => ['patch', 'asistencia.dispositivos.toggle-activo{d}', []],
            'pantalla de rotación' => ['get', 'asistencia.dispositivos.rotar-token{d}', []],
            'rotar el token' => ['post', 'asistencia.dispositivos.rotar-token.ejecutar{d}', ['confirmacion' => 'lector-entrada']],
        ];
    }
}
