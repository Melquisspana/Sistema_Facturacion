<?php

namespace Tests\Feature\Asistencia;

use App\Enums\Asistencia\ResultadoMarcacion;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Asistencia\AsistenciaDispositivo;
use Database\Factories\Asistencia\AsistenciaDispositivoFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Las tres garantías que no encajan en «marcar» ni en «reutilizar ranuras»:
 *
 *  1. dar de alta o rotar un LECTOR queda auditado, y el log NUNCA se queda con
 *     el hash del token;
 *  2. el contrato con el firmware está COMPLETO en un enum, no repartido en
 *     literales por tres archivos;
 *  3. los permisos del módulo existen y no ensancharon a ningún rol.
 */
class AuditoriaYContratoAsistenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('asistencia.enabled', true);
    }

    // ───────────────────────── Auditoría de lectores ─────────────────────────

    public function test_dar_de_alta_un_lector_queda_auditado(): void
    {
        $lector = AsistenciaDispositivo::factory()->create(['codigo' => 'lector-entrada']);

        $actividad = Activity::query()
            ->where('log_name', 'asistencia')
            ->where('subject_type', AsistenciaDispositivo::class)
            ->where('subject_id', $lector->id)
            ->sole();

        $this->assertSame('dio de alta un lector de asistencia', $actividad->description);
    }

    /**
     * EL PUNTO DELICADO. `token_hash` está en `$fillable`, así que un
     * `logFillable()` lo copiaría al log de auditoría en cada alta y en cada
     * rotación. El log es de solo-añadir y lo consulta más gente que la tabla: no
     * hay ninguna razón para dejar ahí copias permanentes de un secreto derivado.
     */
    public function test_la_auditoria_del_lector_no_guarda_el_hash_del_token(): void
    {
        $lector = AsistenciaDispositivo::factory()->create();
        $lector->update(['token_hash' => AsistenciaDispositivo::hashDeToken('token-rotado')]);

        $propiedades = Activity::query()
            ->where('subject_type', AsistenciaDispositivo::class)
            ->get()
            ->map(fn (Activity $a) => json_encode($a->properties))
            ->implode(' ');

        $this->assertStringNotContainsString('token_hash', $propiedades);
        $this->assertStringNotContainsString(AsistenciaDispositivo::hashDeToken('token-rotado'), $propiedades);
        $this->assertStringNotContainsString(AsistenciaDispositivoFactory::TOKEN_DE_PRUEBA, $propiedades);
    }

    /** Desactivar un lector sí tiene que verse: es lo que lo deja sin autenticar. */
    public function test_desactivar_un_lector_queda_auditado(): void
    {
        $lector = AsistenciaDispositivo::factory()->create();
        $lector->update(['activo' => false]);

        // La última, no la única: el alta del lector ya dejó la suya.
        $this->assertSame(
            'modificó un lector de asistencia',
            Activity::query()->where('subject_type', AsistenciaDispositivo::class)->latest('id')->firstOrFail()->description
        );
    }

    /**
     * La telemetría de cada marcación NO es un cambio de datos. Sin `saveQuietly`,
     * el log de auditoría tendría una entrada por cada dedo que toca el sensor y
     * dejaría de servir para lo que existe.
     */
    public function test_la_telemetria_del_lector_no_ensucia_la_auditoria(): void
    {
        $lector = AsistenciaDispositivo::factory()->create();
        $antes = Activity::query()->where('subject_type', AsistenciaDispositivo::class)->count();

        $lector->registrarConexion('192.168.1.50');

        $this->assertSame($antes, Activity::query()->where('subject_type', AsistenciaDispositivo::class)->count());
        $this->assertSame('192.168.1.50', $lector->fresh()->ultima_ip);
    }

    // ────────────────────── Contrato con el firmware ──────────────────────

    /**
     * Los seis estados documentados salen del enum. Antes, dos de ellos eran
     * cadenas escritas a mano en el middleware y en el FormRequest: «qué estados
     * existen» solo se podía averiguar leyendo tres archivos, y nada impedía que
     * uno escribiera `dispositivo_no_autorizado` y otro `no_autorizado`.
     */
    public function test_el_enum_declara_los_seis_estados_del_contrato(): void
    {
        $estados = array_map(fn (ResultadoMarcacion $r) => $r->value, ResultadoMarcacion::cases());
        sort($estados);

        $this->assertSame([
            'cooldown',
            'dispositivo_no_autorizado',
            'empleado_inactivo',
            'huella_desconocida',
            'payload_invalido',
            'registrada',
        ], $estados);
    }

    public function test_cada_estado_declara_su_codigo_http(): void
    {
        $this->assertSame(200, ResultadoMarcacion::Registrada->httpStatus());
        $this->assertSame(404, ResultadoMarcacion::HuellaDesconocida->httpStatus());
        $this->assertSame(403, ResultadoMarcacion::EmpleadoInactivo->httpStatus());
        $this->assertSame(409, ResultadoMarcacion::Cooldown->httpStatus());
        $this->assertSame(422, ResultadoMarcacion::PayloadInvalido->httpStatus());
        $this->assertSame(401, ResultadoMarcacion::DispositivoNoAutorizado->httpStatus());
    }

    /** Qué decide la regla de marcación y qué se resuelve antes de llegar a ella. */
    public function test_el_enum_distingue_lo_que_decide_la_regla(): void
    {
        foreach ([ResultadoMarcacion::Registrada, ResultadoMarcacion::HuellaDesconocida,
            ResultadoMarcacion::EmpleadoInactivo, ResultadoMarcacion::Cooldown] as $estado) {
            $this->assertTrue($estado->loDecideLaRegla(), $estado->value.' lo devuelve RegistrarMarcacion.');
        }

        foreach ([ResultadoMarcacion::PayloadInvalido, ResultadoMarcacion::DispositivoNoAutorizado] as $estado) {
            $this->assertFalse($estado->loDecideLaRegla(), $estado->value.' se decide antes del servicio.');
        }
    }

    /** Y lo que devuelve el endpoint de verdad sigue siendo exactamente eso. */
    public function test_las_respuestas_reales_usan_los_valores_del_enum(): void
    {
        $sinCredencial = $this->postJson('/api/asistencia/marcar', ['fingerprint_id' => 1]);
        $sinCredencial->assertStatus(ResultadoMarcacion::DispositivoNoAutorizado->httpStatus())
            ->assertJsonPath('estado', ResultadoMarcacion::DispositivoNoAutorizado->value);

        $lector = AsistenciaDispositivo::factory()->create();

        $payloadMalo = $this->withHeaders([
            'X-Dispositivo' => $lector->codigo,
            'X-Dispositivo-Token' => AsistenciaDispositivoFactory::TOKEN_DE_PRUEBA,
        ])->postJson('/api/asistencia/marcar', ['fingerprint_id' => 'no-es-un-numero']);

        $payloadMalo->assertStatus(ResultadoMarcacion::PayloadInvalido->httpStatus())
            ->assertJsonPath('estado', ResultadoMarcacion::PayloadInvalido->value);
    }

    // ──────────────────────────── Permisos ────────────────────────────

    public function test_el_modulo_declara_sus_tres_permisos(): void
    {
        $todos = PermisoSistema::todos();

        $this->assertContains('asistencia.ver', $todos);
        $this->assertContains('asistencia.gestionar', $todos);
        $this->assertContains('asistencia.dispositivos.gestionar', $todos);
    }

    /**
     * Los permisos nuevos NO ensanchan a nadie salvo al administrador, que por
     * diseño recibe todos. Un permiso nuevo que se cuele en otro rol es acceso
     * regalado, y es el error más fácil de cometer al ampliar este enum.
     */
    public function test_ningun_rol_salvo_administrador_recibe_permisos_de_asistencia(): void
    {
        foreach (RolSistema::cases() as $rol) {
            $deAsistencia = array_values(array_filter(
                $rol->permisos(),
                fn (string $p) => str_starts_with($p, 'asistencia.')
            ));

            if ($rol === RolSistema::Administrador) {
                $this->assertCount(3, $deAsistencia, 'El administrador recibe todos los permisos.');

                continue;
            }

            $this->assertSame([], $deAsistencia, "El rol «{$rol->value}» no debería tener permisos de asistencia.");
        }
    }
}
