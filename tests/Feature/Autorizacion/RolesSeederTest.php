<?php

namespace Tests\Feature\Autorizacion;

use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Verifica que RolesSeeder deje cada rol EXACTAMENTE con su set de permisos
 * (fuente: PermisoSistema) y que el administrador tenga todos.
 */
class RolesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_existen_los_roles_del_catalogo(): void
    {
        foreach (RolSistema::nombres() as $rol) {
            $this->assertTrue(Role::where('name', $rol)->where('guard_name', 'web')->exists(), "Falta el rol {$rol}.");
        }
    }

    public function test_todos_los_permisos_del_catalogo_existen(): void
    {
        foreach (PermisoSistema::todos() as $permiso) {
            $this->assertTrue(Permission::where('name', $permiso)->where('guard_name', 'web')->exists(), "Falta el permiso {$permiso}.");
        }
    }

    public function test_cada_rol_tiene_su_set_exacto_de_permisos(): void
    {
        foreach (RolSistema::cases() as $rol) {
            $esperados = PermisoSistema::paraRol($rol);
            sort($esperados);

            $reales = Role::findByName($rol->value, 'web')
                ->permissions()
                ->pluck('name')
                ->sort()
                ->values()
                ->all();

            $this->assertSame($esperados, $reales, "El rol {$rol->value} no tiene el set de permisos esperado.");
        }
    }

    public function test_administrador_tiene_todos_los_permisos(): void
    {
        $admin = User::factory()->create()->assignRole(RolSistema::Administrador->value);

        foreach (PermisoSistema::todos() as $permiso) {
            $this->assertTrue($admin->can($permiso), "El administrador debería tener {$permiso}.");
        }
    }

    public function test_contabilidad_lee_pero_no_gestiona(): void
    {
        $conta = User::factory()->create()->assignRole(RolSistema::Contabilidad->value);

        // Puede: ver DTE, ver productos, enviar paquete, ver auditoría.
        $this->assertTrue($conta->can('dte.ver'));
        $this->assertTrue($conta->can('productos.ver'));
        $this->assertTrue($conta->can('contabilidad.enviar'));
        $this->assertTrue($conta->can('auditoria.ver'));

        // No puede: emitir, gestionar, invalidar, sincronizar, administrar.
        $this->assertFalse($conta->can('dte.emitir'));
        $this->assertFalse($conta->can('dte.invalidar'));
        $this->assertFalse($conta->can('ppq.gestionar'));
        $this->assertFalse($conta->can('exportaciones.gestionar'));
        $this->assertFalse($conta->can('documentos-recibidos.gestionar'));
        $this->assertFalse($conta->can('usuarios.gestionar'));
    }

    public function test_facturacion_emite_pero_no_administra(): void
    {
        $fact = User::factory()->create()->assignRole(RolSistema::Facturacion->value);

        $this->assertTrue($fact->can('dte.emitir'));
        $this->assertTrue($fact->can('ppq.gestionar'));
        $this->assertTrue($fact->can('exportaciones.gestionar'));
        $this->assertTrue($fact->can('clientes.ver'));

        // Solo lectura en clientes/productos; nada de admin, invalidar ni enviar paquete.
        $this->assertFalse($fact->can('clientes.gestionar'));
        $this->assertFalse($fact->can('productos.gestionar'));
        $this->assertFalse($fact->can('dte.invalidar'));
        $this->assertFalse($fact->can('contabilidad.enviar'));
        $this->assertFalse($fact->can('documentos-recibidos.gestionar'));
        $this->assertFalse($fact->can('usuarios.gestionar'));
    }

    public function test_produccion_solo_entra_a_su_area(): void
    {
        $prod = User::factory()->create()->assignRole(RolSistema::Produccion->value);

        // Su área y nada más.
        $this->assertTrue($prod->can('planta.ver'));
        $this->assertTrue($prod->can('dashboard.ver'));

        // Ni un permiso fiscal, comercial ni administrativo. `dte.ver` es además
        // el permiso de ENTRADA al área Facturación (AreaSistema::permiso()): sin
        // él, el área ni siquiera aparece en el selector.
        foreach ([
            'dte.ver', 'dte.gestionar', 'dte.emitir', 'dte.enviar-correo', 'dte.invalidar',
            'clientes.ver', 'clientes.gestionar', 'productos.ver', 'productos.gestionar',
            'ppq.ver', 'ppq.gestionar', 'exportaciones.ver', 'exportaciones.gestionar',
            'documentos-recibidos.ver', 'reportes.ver', 'contabilidad.enviar',
            'auditoria.ver', 'usuarios.gestionar', 'configuracion.gestionar',
            'importaciones.gestionar', 'sistema.salud', 'preparacion.ver',
            'planta.gestionar',
        ] as $prohibido) {
            $this->assertFalse($prod->can($prohibido), "Producción no debería tener {$prohibido}.");
        }
    }

    public function test_jefatura_es_solo_lectura(): void
    {
        $jefa = User::factory()->create()->assignRole(RolSistema::Jefatura->value);

        $this->assertTrue($jefa->can('dte.ver'));
        $this->assertTrue($jefa->can('ppq.ver'));
        $this->assertTrue($jefa->can('exportaciones.ver'));
        $this->assertTrue($jefa->can('documentos-recibidos.ver'));
        $this->assertTrue($jefa->can('reportes.ver'));

        foreach (['dte.gestionar', 'dte.emitir', 'dte.invalidar', 'ppq.gestionar', 'exportaciones.gestionar', 'clientes.gestionar', 'contabilidad.enviar', 'usuarios.gestionar', 'auditoria.ver'] as $prohibido) {
            $this->assertFalse($jefa->can($prohibido), "Jefatura no debería tener {$prohibido}.");
        }
    }
}
