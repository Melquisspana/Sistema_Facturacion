<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\User;
use App\Services\Gastos\InstalacionGastos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Una base con las migraciones de FASE 1 y sin las de fase 2.
 *
 * Es el estado real de un servidor entre que se despliega el código y alguien
 * autoriza correr las migraciones —una ventana que puede durar días—, y es el estado
 * en el que este módulo tumbó la aplicación entera:
 *
 *   El menú lateral contaba avisos sin leer con una consulta directa a
 *   `gastos_avisos`. Esa tabla no existía todavía, la consulta reventaba, y como el
 *   menú se dibuja en TODAS las pantallas, el sistema respondía 500 en todas —
 *   incluido el dashboard, que no tiene absolutamente nada que ver con Gastos—.
 *
 * Lo que estas pruebas fijan, para que no vuelva a pasar:
 *
 *  1. EL MENÚ NO PUEDE TUMBAR LA APLICACIÓN. Un contador decorativo no decide si
 *     una pantalla existe.
 *  2. Lo de fase 1 sigue funcionando. Que falte lo nuevo no puede llevarse puesto
 *     lo que ya andaba.
 *  3. Las funciones que necesitan ese esquema responden 503 CON EXPLICACIÓN, no un
 *     error de SQL. Un 503 dice «faltan migraciones»; un 500 no dice nada.
 *  4. El menú no ofrece lo que va a fallar.
 */
class BaseSinFase2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('gastos.enabled', true);
    }

    /**
     * Deja la base como estaba antes de la fase 2: se van las seis tablas.
     *
     * Sin desactivar las claves foráneas no se puede, porque `gastos_eventos.regla_id`
     * apunta a `gastos_reglas`. Esa columna se queda —igual que se quedaría si alguien
     * revirtiera solo parte de la migración—, y da lo mismo: nada la lee cuando las
     * tablas no están.
     */
    private function borrarEsquemaFase2(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            foreach (InstalacionGastos::TABLAS_FASE2 as $tabla) {
                Schema::dropIfExists($tabla);
            }
        });

        // El servicio memoiza por petición; en una prueba hay que hacerle olvidar lo
        // que vio antes de que cambiáramos el esquema debajo.
        app(InstalacionGastos::class)->olvidar();
    }

    private function usuario(array $permisos = []): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions(array_merge([
            PermisoSistema::DashboardVer->value,
            // `dte.ver` es el permiso de entrada al área Facturación. Va acá porque el
            // 500 original lo sufría justamente quien estaba en el dashboard: sin este
            // permiso, ahora el usuario aterriza en Gastos y ni pasa por ahí.
            PermisoSistema::DteVer->value,
            PermisoSistema::GastosVer->value,
        ], $permisos));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function gasto(User $u): Gasto
    {
        $gasto = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('0', 64),
            'beneficiario' => 'Proveedor A', 'concepto' => 'Alquiler de bodega',
            'categoria' => 'Alquiler', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'importe' => '100.00', 'documentacion' => 'pendiente',
            'responsable_id' => $u->id, 'registrado_por' => $u->id,
        ]);
        $gasto->cuotas()->create(['numero' => 1, 'importe' => '100.00', 'vence' => '2026-12-01']);

        return $gasto->fresh();
    }

    // ─────────────────────── La regresión que costó el 500 ───────────────────────

    public function test_el_dashboard_abre_aunque_falten_las_migraciones_de_fase_2(): void
    {
        $u = $this->usuario();
        $this->borrarEsquemaFase2();

        $this->actingAs($u)->get(route('dashboard'))->assertOk();
    }

    /**
     * El panel es ahora la puerta del área, así que tiene que abrir en una base sin
     * las migraciones de fase 2 —la ventana entre desplegar el código y migrar—, y no
     * ofrecer el acceso a las reglas, que llevaría a un 503.
     *
     * Es la misma lección que costó el 500 del menú: la pantalla por la que entra todo
     * el mundo no puede dar por supuesto que un módulo está instalado.
     */
    public function test_el_panel_abre_y_se_comporta_sin_las_migraciones_de_fase_2(): void
    {
        $u = $this->usuario();
        $this->borrarEsquemaFase2();

        $respuesta = $this->actingAs($u)->get(route('gastos.panel'))->assertOk();

        $respuesta->assertDontSee(route('gastos.reglas.index'));
        $respuesta->assertSee(route('gastos.pagos.index'));
        $respuesta->assertSee(route('gastos.informes'));
        // Y sin reglas no hay nada programado que mostrar, pero la pantalla existe.
        $respuesta->assertSee('Se debe hoy');
    }

    public function test_el_menu_de_gastos_no_ofrece_lo_que_va_a_fallar(): void
    {
        $u = $this->usuario();
        $this->borrarEsquemaFase2();

        // El menú de Gastos se dibuja dentro del área Gastos, no en el dashboard.
        $respuesta = $this->actingAs($u)->get(route('gastos.index'))->assertOk();

        $respuesta->assertDontSee(route('gastos.reglas.index'));
        // Lo de fase 1 sigue ofreciéndose entero.
        $respuesta->assertSee(route('gastos.pagos.index'));
        $respuesta->assertSee(route('gastos.informes'));
    }

    public function test_quien_solo_tiene_gastos_aterriza_en_por_pagar(): void
    {
        // Sin `dte.ver` no ve Facturación. Antes de que Gastos fuera un área propia
        // esto daba 404; ahora aterriza donde le corresponde.
        //
        // Y ese sitio es el PANEL, que es la puerta del área. Ojo: esta prueba corre
        // con el esquema de fase 2 BORRADO, así que también comprueba que el panel
        // abre sin recurrencias instaladas en vez de reventar buscando reglas.
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions([PermisoSistema::GastosVer->value]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($u->fresh())->get(route('dashboard'))->assertRedirect(route('gastos.panel'));
    }

    public function test_lo_de_fase_1_sigue_funcionando_entero(): void
    {
        $u = $this->usuario([PermisoSistema::GastosRegistrar->value]);
        $gasto = $this->gasto($u);
        $this->borrarEsquemaFase2();

        $this->actingAs($u)->get(route('gastos.index'))->assertOk()->assertSee('Alquiler de bodega');
        $this->actingAs($u)->get(route('gastos.show', $gasto))->assertOk();
        $this->actingAs($u)->get(route('gastos.create'))->assertOk();
        $this->actingAs($u)->get(route('gastos.informes'))->assertOk();
    }

    // ─────────────────────── Las funciones que sí necesitan el esquema ───────────────────────

    public function test_recurrencias_responde_503_con_explicacion_y_no_un_error_de_sql(): void
    {
        $u = $this->usuario([PermisoSistema::GastosRecurrencias->value]);
        $this->borrarEsquemaFase2();

        $respuesta = $this->actingAs($u)->get(route('gastos.reglas.index'));

        $respuesta->assertStatus(503);
        // Y la pantalla DICE qué falta y qué hay que hacer. Un 503 genérico no sirve:
        // quien lo ve solo lee «Service Unavailable» y no sabe que faltan migraciones.
        $respuesta->assertSee('gastos_reglas');
        $respuesta->assertSee('migraciones pendientes');
        $respuesta->assertSee('php artisan migrate');
        // Y deja claro que el resto no está caído.
        $respuesta->assertSee('El resto del sistema funciona con normalidad');
    }

    public function test_avisos_responde_503_y_tampoco_revienta(): void
    {
        $u = $this->usuario();
        $this->borrarEsquemaFase2();

        $this->actingAs($u)->get(route('gastos.avisos.index'))->assertStatus(503);
        $this->actingAs($u)->get(route('gastos.avisos.preferencias'))->assertStatus(503);
    }

    public function test_todas_las_rutas_de_fase_2_quedan_cubiertas_por_el_candado(): void
    {
        $u = $this->usuario([PermisoSistema::GastosRecurrencias->value]);
        $this->borrarEsquemaFase2();

        // Si alguien agrega una ruta de fase 2 fuera del grupo protegido, acá se nota.
        foreach (['gastos.reglas.index', 'gastos.reglas.create', 'gastos.avisos.index', 'gastos.avisos.preferencias'] as $ruta) {
            $this->actingAs($u)->get(route($ruta))->assertStatus(503);
        }
    }

    public function test_los_comandos_se_niegan_con_un_mensaje_util(): void
    {
        $this->borrarEsquemaFase2();

        $this->artisan('gastos:generar-recurrentes')
            ->expectsOutputToContain('todavía no están instalados')
            ->assertFailed();

        $this->artisan('gastos:avisos')
            ->expectsOutputToContain('php artisan migrate')
            ->assertFailed();
    }

    // ─────────────────────── El servicio que lo decide ───────────────────────

    public function test_el_servicio_reconoce_el_estado_y_dice_que_falta(): void
    {
        $instalacion = app(InstalacionGastos::class);
        $this->assertTrue($instalacion->fase2Instalada());
        $this->assertSame([], $instalacion->tablasQueFaltan());

        $this->borrarEsquemaFase2();

        $this->assertFalse($instalacion->fase2Instalada());
        $this->assertSame(InstalacionGastos::TABLAS_FASE2, $instalacion->tablasQueFaltan());
        $this->assertStringContainsString('gastos_avisos', $instalacion->motivoNoInstalada());
    }

    public function test_el_contador_de_avisos_devuelve_cero_en_vez_de_reventar(): void
    {
        $u = $this->usuario();
        $this->borrarEsquemaFase2();

        // Es la línea exacta que tumbaba el sistema.
        $this->assertSame(0, app(InstalacionGastos::class)->avisosSinLeer($u));
        $this->assertSame(0, app(InstalacionGastos::class)->avisosSinLeer(null));
    }

    // ─────────────────────── Y el caso normal, para que no se rompa al revés ───────────────────────

    public function test_con_el_esquema_completo_el_menu_ofrece_los_que_se_repiten(): void
    {
        $u = $this->usuario([PermisoSistema::GastosRecurrencias->value]);

        $respuesta = $this->actingAs($u)->get(route('gastos.index'))->assertOk();

        $respuesta->assertSee(route('gastos.reglas.index'));
        $respuesta->assertSee('Gastos que se repiten');

        $this->actingAs($u)->get(route('gastos.reglas.index'))->assertOk();
        $this->actingAs($u)->get(route('gastos.avisos.index'))->assertOk();
    }

    public function test_el_menu_de_gastos_tiene_cuatro_filas_y_ninguna_accion(): void
    {
        $u = $this->usuario([PermisoSistema::GastosRecurrencias->value, PermisoSistema::GastosRegistrar->value]);

        $respuesta = $this->actingAs($u)->get(route('gastos.index'))->assertOk();

        foreach (['Por pagar', 'Historial de pagos', 'Gastos que se repiten', 'Informes'] as $fila) {
            $respuesta->assertSee($fila);
        }

        // Registrar gasto y Registrar pago son ACCIONES de la pantalla, no filas del
        // menú: sus enlaces no deben estar dentro del <nav> lateral.
        $html = $respuesta->getContent();
        $nav = mb_substr($html, mb_strpos($html, 'id="sidebar-principal"'));
        $nav = mb_substr($nav, 0, mb_strpos($nav, '</aside>') ?: mb_strlen($nav));

        $this->assertStringNotContainsString(route('gastos.create'), $nav);
        $this->assertStringNotContainsString(route('gastos.pagos.create'), $nav);
        // Y Avisos tampoco es una fila: vive dentro de Por pagar.
        $this->assertStringNotContainsString(route('gastos.avisos.index'), $nav);
    }
}
