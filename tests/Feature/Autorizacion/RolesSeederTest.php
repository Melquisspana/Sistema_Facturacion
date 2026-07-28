<?php

namespace Tests\Feature\Autorizacion;

use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\User;
use Database\Seeders\RolesSeeder;
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

    /**
     * Los 12 permisos `planta.*` del rol `produccion`: la OPERACIÓN DIARIA del
     * área. Fuente: §11 del plan de la Fase 2.
     */
    private const PLANTA_OPERATIVOS = [
        'planta.ver',
        'planta.catalogos.ver',
        'planta.recepciones.ver',
        'planta.recepciones.crear',
        'planta.recepciones.confirmar',
        'planta.traslados.ver',
        'planta.traslados.crear',
        'planta.traslados.enviar',
        'planta.traslados.recibir',
        'planta.ajustes.ver',
        'planta.existencias.ver',
        'planta.movimientos.ver',
    ];

    /**
     * Los 8 permisos `planta.*` reservados a administrador (y a un supervisor
     * futuro, que NO se crea en Fase 2).
     */
    private const PLANTA_RESERVADOS = [
        'planta.gestionar',
        'planta.catalogos.gestionar',
        'planta.recepciones.reversar',
        'planta.traslados.reversar',
        'planta.ajustes.crear',
        'planta.ajustes.confirmar',
        'planta.ajustes.reversar',
        'planta.calidad.gestionar',
    ];

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
        ] as $prohibido) {
            $this->assertFalse($prod->can($prohibido), "Producción no debería tener {$prohibido}.");
        }
    }

    public function test_produccion_tiene_la_operacion_diaria_de_planta(): void
    {
        $prod = User::factory()->create()->assignRole(RolSistema::Produccion->value);

        foreach (self::PLANTA_OPERATIVOS as $operativo) {
            $this->assertTrue($prod->can($operativo), "Producción debería tener {$operativo}.");
        }
    }

    public function test_produccion_no_gestiona_catalogos_ajustes_reversiones_ni_calidad(): void
    {
        $prod = User::factory()->create()->assignRole(RolSistema::Produccion->value);

        // Decisión de control deliberada: estas acciones alteran el marco de
        // trabajo o deshacen inventario ya contabilizado. La auditoría (motivo
        // obligatorio + Activitylog + mayor inmutable) NO sustituye a la
        // autorización: son capas complementarias.
        foreach (self::PLANTA_RESERVADOS as $reservado) {
            $this->assertFalse($prod->can($reservado), "Producción no debería tener {$reservado}.");
        }
    }

    public function test_produccion_ve_los_ajustes_pero_no_los_registra(): void
    {
        $prod = User::factory()->create()->assignRole(RolSistema::Produccion->value);

        // Consulta lo que se ajustó en su área...
        $this->assertTrue($prod->can('planta.ajustes.ver'));

        // ...pero crear, confirmar y reversar son de administrador. `confirmar`
        // es el acto que MUEVE inventario y por eso está separado de `crear`.
        $this->assertFalse($prod->can('planta.ajustes.crear'));
        $this->assertFalse($prod->can('planta.ajustes.confirmar'));
        $this->assertFalse($prod->can('planta.ajustes.reversar'));
    }

    public function test_produccion_lee_catalogos_pero_no_los_gestiona(): void
    {
        $prod = User::factory()->create()->assignRole(RolSistema::Produccion->value);

        $this->assertTrue($prod->can('planta.catalogos.ver'));
        $this->assertFalse($prod->can('planta.catalogos.gestionar'));
    }

    public function test_el_catalogo_de_planta_tiene_exactamente_veinte_permisos(): void
    {
        $planta = array_values(array_filter(
            PermisoSistema::todos(),
            fn (string $p) => str_starts_with($p, 'planta.')
        ));

        $this->assertCount(20, $planta);

        // Cuadre explícito: 12 operativos + 8 reservados = 20. Si alguien añade
        // un permiso `planta.*` sin decidir a qué lado pertenece, esto falla.
        $this->assertEqualsCanonicalizing(
            array_merge(self::PLANTA_OPERATIVOS, self::PLANTA_RESERVADOS),
            $planta
        );
    }

    public function test_el_set_de_planta_del_rol_produccion_es_exacto(): void
    {
        $suyos = array_values(array_filter(
            PermisoSistema::paraRol(RolSistema::Produccion),
            fn (string $p) => str_starts_with($p, 'planta.')
        ));

        $this->assertCount(12, $suyos);
        $this->assertEqualsCanonicalizing(self::PLANTA_OPERATIVOS, $suyos);
    }

    public function test_ningun_otro_rol_recibe_permisos_de_planta(): void
    {
        // El aislamiento va en los dos sentidos: producción no ve lo fiscal y
        // los roles fiscales no ven Planta. El administrador es la excepción
        // deliberada (recibe todo).
        foreach ([RolSistema::Jefatura, RolSistema::Facturacion, RolSistema::Contabilidad] as $rol) {
            $dePlanta = array_filter(
                PermisoSistema::paraRol($rol),
                fn (string $p) => str_starts_with($p, 'planta.')
            );

            $this->assertSame([], $dePlanta, "El rol {$rol->value} no debería tener permisos de Planta.");
        }
    }

    public function test_el_administrador_recibe_los_permisos_reservados_de_planta(): void
    {
        $admin = User::factory()->create()->assignRole(RolSistema::Administrador->value);

        foreach (self::PLANTA_RESERVADOS as $reservado) {
            $this->assertTrue($admin->can($reservado), "El administrador debería tener {$reservado}.");
        }
    }

    public function test_el_seeder_es_idempotente_con_los_permisos_de_planta(): void
    {
        // El seeder ya corre una vez en el setUp de TestCase. Volver a
        // ejecutarlo no debe duplicar permisos ni alterar el set de ningún rol:
        // es lo que permite reejecutarlo tras un despliegue para incorporar los
        // 18 permisos nuevos sin tocar las asignaciones usuario<->rol.
        $antes = Permission::where('name', 'like', 'planta.%')->count();

        $this->seed(RolesSeeder::class);

        $this->assertSame($antes, Permission::where('name', 'like', 'planta.%')->count());
        $this->assertSame(20, $antes);

        $reales = Role::findByName(RolSistema::Produccion->value, 'web')
            ->permissions()
            ->pluck('name')
            ->filter(fn (string $p) => str_starts_with($p, 'planta.'))
            ->sort()
            ->values()
            ->all();

        $esperados = self::PLANTA_OPERATIVOS;
        sort($esperados);

        $this->assertSame($esperados, $reales);
    }

    public function test_el_seeder_retira_un_permiso_asignado_de_mas(): void
    {
        // `syncPermissions` deja el set EXACTO. Si un despliegue intermedio dejó
        // a producción con `planta.ajustes.crear`, reejecutar el seeder se lo
        // quita solo: no hace falta migración de datos para corregir el reparto.
        $rol = Role::findByName(RolSistema::Produccion->value, 'web');
        $rol->givePermissionTo('planta.ajustes.crear');

        $this->assertTrue($rol->fresh()->hasPermissionTo('planta.ajustes.crear'));

        $this->seed(RolesSeeder::class);

        $this->assertFalse($rol->fresh()->hasPermissionTo('planta.ajustes.crear'));
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
