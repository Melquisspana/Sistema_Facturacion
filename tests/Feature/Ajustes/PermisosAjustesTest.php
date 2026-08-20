<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\Excepciones\AjusteNoEditableException;
use App\Ajustes\Excepciones\AutorizacionAjusteException;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Facades\Ajustes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `configuracion.gestionar` deja de ser una llave maestra.
 *
 * Hasta ahora, quien podía entrar a Configuración podía cambiar cualquier cosa que
 * hubiera ahí dentro. Cuando el Centro de Configuración incorpore el ambiente
 * fiscal, las credenciales del MH y los correlativos, eso significaría que quien
 * administra la plantilla del correo puede poner el sistema a emitir en
 * producción. `configuracion.critica` separa las dos cosas.
 *
 * Estos tests fijan además que el permiso nuevo NO amplía el acceso de ningún rol
 * existente: es nuevo y solo lo tiene el administrador, que recibe todos.
 */
class PermisosAjustesTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    /** Un usuario con `configuracion.gestionar` pero SIN `configuracion.critica`. */
    private function gestorNoCritico(): User
    {
        $rol = Role::findOrCreate('gestor_configuracion', 'web');
        $rol->syncPermissions([Permission::findOrCreate(PermisoSistema::ConfiguracionGestionar->value, 'web')]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return User::factory()->create(['activo' => true])->assignRole('gestor_configuracion');
    }

    // ------------------------------------------------------- el permiso nuevo

    public function test_el_permiso_critico_existe_en_el_catalogo(): void
    {
        $this->assertContains('configuracion.critica', PermisoSistema::todos());
    }

    public function test_el_seeder_crea_el_permiso_critico(): void
    {
        $this->assertTrue(
            Permission::where('name', 'configuracion.critica')->where('guard_name', 'web')->exists()
        );
    }

    public function test_solo_el_administrador_tiene_el_permiso_critico(): void
    {
        foreach (RolSistema::cases() as $rol) {
            $usuario = $this->usuario($rol->value);
            $esperado = $rol === RolSistema::Administrador;

            $this->assertSame(
                $esperado,
                $usuario->can('configuracion.critica'),
                "El rol {$rol->value} ".($esperado ? 'debería' : 'NO debería').' tener configuracion.critica.'
            );
        }
    }

    /** El permiso nuevo no puede haber ampliado nada más de paso. */
    public function test_ningun_rol_gano_permisos_ademas_del_critico(): void
    {
        foreach (RolSistema::cases() as $rol) {
            if ($rol === RolSistema::Administrador) {
                continue;
            }

            $permisos = Role::findByName($rol->value, 'web')->permissions()->pluck('name')->all();

            $this->assertNotContains('configuracion.critica', $permisos);
            $this->assertNotContains('configuracion.gestionar', $permisos, "El rol {$rol->value} no administraba configuración y sigue sin hacerlo.");
        }
    }

    // ------------------------------------------------------------- escritura

    /**
     * EL TEST DE LA FASE: quien solo tiene `configuracion.gestionar` no puede
     * ejecutar una escritura N3.
     *
     * Falla por AUTORIZACIÓN, no por "todavía no es editable": la comprobación de
     * permiso va antes que la de editabilidad, así que el día que N3 se abra este
     * mismo código lo seguirá negando sin cambiar una línea.
     */
    public function test_sin_permiso_critico_no_se_puede_escribir_un_ajuste_n3(): void
    {
        $this->actingAs($this->gestorNoCritico());

        $this->expectException(AutorizacionAjusteException::class);

        Ajustes::guardar('dte.ambiente', '01');
    }

    public function test_el_administrador_falla_por_editabilidad_no_por_permiso(): void
    {
        $this->actingAs($this->usuario('administrador'));

        // Prueba de que el orden de comprobaciones es el declarado: el admin pasa
        // el permiso y choca contra el candado de "todavía no editable".
        $this->expectException(AjusteNoEditableException::class);

        Ajustes::guardar('dte.ambiente', '01');
    }

    public function test_sin_usuario_autenticado_no_se_puede_escribir_nada(): void
    {
        $this->expectException(AutorizacionAjusteException::class);

        Ajustes::guardar('contabilidad.correo', 'conta@ejemplo.com');
    }

    public function test_un_gestor_normal_si_puede_escribir_un_ajuste_n1(): void
    {
        $this->actingAs($this->gestorNoCritico());

        Ajustes::guardar('contabilidad.correo', 'conta@ejemplo.com');

        $this->assertSame('conta@ejemplo.com', Ajustes::texto('contabilidad.correo'));
    }

    public function test_un_gestor_normal_puede_escribir_un_ajuste_n2(): void
    {
        $this->actingAs($this->gestorNoCritico());

        // N2 es "confirmá antes de guardar", no "hace falta otro permiso".
        Ajustes::guardar('respaldos.dias_retencion', 60);

        $this->assertSame(60, Ajustes::entero('respaldos.dias_retencion'));
    }

    public function test_un_usuario_sin_permisos_no_puede_escribir_ni_un_n1(): void
    {
        $this->actingAs($this->usuario('facturacion'));

        $this->expectException(AutorizacionAjusteException::class);

        Ajustes::guardar('contabilidad.correo', 'conta@ejemplo.com');
    }

    public function test_el_administrador_conserva_el_acceso_a_configuracion(): void
    {
        $admin = $this->usuario('administrador');

        $this->assertTrue($admin->can('configuracion.gestionar'));
        $this->assertTrue($admin->can('configuracion.critica'));

        $this->actingAs($admin)
            ->get(route('configuracion.contabilidad.edit'))
            ->assertOk();
    }

    // ---------------------------------------------------------------- puedeEditar

    public function test_puede_editar_refleja_permiso_y_editabilidad(): void
    {
        $admin = $this->usuario('administrador');
        $gestor = $this->gestorNoCritico();

        $this->assertTrue(Ajustes::puedeEditar($admin, 'contabilidad.correo'));
        $this->assertTrue(Ajustes::puedeEditar($gestor, 'contabilidad.correo'));

        // N3: ni el administrador, porque todavía no es editable.
        $this->assertFalse(Ajustes::puedeEditar($admin, 'dte.ambiente'));
        $this->assertFalse(Ajustes::puedeEditar($gestor, 'dte.ambiente'));

        $this->assertFalse(Ajustes::puedeEditar(null, 'contabilidad.correo'));
    }

    /** La escritura de sistema (consola, seeders) es un camino aparte y explícito. */
    public function test_guardar_como_sistema_no_exige_usuario(): void
    {
        Ajustes::guardarComoSistema('contabilidad.correo', 'conta@ejemplo.com');

        $this->assertSame('conta@ejemplo.com', Ajustes::texto('contabilidad.correo'));
    }

    /** Pero tampoco abre los N3: seguir siendo no editable no depende de quién escriba. */
    public function test_guardar_como_sistema_no_abre_los_n3(): void
    {
        $this->expectException(AjusteNoEditableException::class);

        Ajustes::guardarComoSistema('dte.ambiente', '01');
    }
}
