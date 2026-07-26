<?php

namespace Tests\Feature;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\DocumentoRecibido;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PuntoVenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Dashboard operativo (reemplaza el "You're logged in!" vacío de Breeze).
 * Todo lo que muestra sale de datos ya existentes (sin llamadas a Hacienda, sin
 * firmar, sin secretos). Cubre: carga para usuario autenticado, estadísticas
 * básicas, enlaces rápidos según permisos, y que no se filtre nada sensible.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'jefatura', 'contabilidad'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisor(): array
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);
        $estab = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $pv = PuntoVenta::create(['establecimiento_id' => $estab->id, 'codigo' => 'P001', 'nombre' => 'Caja 1', 'activo' => true]);

        return ['estab' => $estab, 'pv' => $pv];
    }

    private function dte(array $override = []): Dte
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'CLIENTE DASHBOARD SA']);

        return Dte::create(array_merge([
            'tipo_dte' => TipoDte::CreditoFiscal->value,
            'estado' => EstadoDte::Aceptado->value,
            'ambiente' => '00',
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
            'cliente_id' => $cliente->id,
            'numero_control' => 'DTE-03-M001P001-000000000000001',
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => now()->toTimeString(),
            'total_pagar' => 150.00,
        ], $override));
    }

    public function test_dashboard_carga_para_usuario_autenticado(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dashboard');
    }

    public function test_invitado_no_accede_al_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_encabezado_saluda_con_el_nombre_del_usuario_y_la_fecha(): void
    {
        $usuario = $this->usuario('administrador');

        $resp = $this->actingAs($usuario)->get(route('dashboard'))->assertOk();

        $resp->assertSee($usuario->name);
        $resp->assertSee('Resumen operativo de Dulces La Negrita');
        $resp->assertSeeText(now()->year);
    }

    public function test_estadisticas_basicas_reflejan_datos_reales(): void
    {
        $this->dte(['total_pagar' => 100, 'ambiente' => '01']);
        $this->dte(['total_pagar' => 250, 'numero_control' => 'DTE-03-M001P001-000000000000002', 'ambiente' => '01']);
        DocumentoRecibido::create(['gmail_message_id' => 'm1', 'estado' => 'pendiente', 'fecha_correo' => now()]);
        DocumentoRecibido::create(['gmail_message_id' => 'm2', 'estado' => 'enviado', 'fecha_correo' => now()]);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('DTE aceptados (mes)');
        $resp->assertSeeInOrder(['DTE aceptados (mes)', '2']); // 2 DTE aceptados creados arriba
        $resp->assertSee('350.00'); // suma de ventas del mes (100 + 250)
        $resp->assertSee('Compras pendientes');
        $resp->assertSeeInOrder(['Compras pendientes', '1']); // solo 1 en estado pendiente
    }

    public function test_actividad_reciente_muestra_el_dte_con_enlace_para_abrir(): void
    {
        $dte = $this->dte(['ambiente' => '01']);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Documentos reales de producción');
        $resp->assertSee('CLIENTE DASHBOARD SA');
        $resp->assertSee($dte->numero_control);
        $resp->assertSee(route('facturacion.show', $dte), false);
    }

    public function test_actividad_reciente_muestra_la_sala_del_documento(): void
    {
        // Igual que el listado de Facturación y el PDF: nombre fiscal + sala debajo.
        $dte = $this->dte(['ambiente' => '01']);
        $sala = \App\Models\ClienteSucursal::factory()->create([
            'cliente_id' => $dte->cliente_id,
            'nombre' => 'Súper Selectos San Benito',
        ]);
        $dte->cliente_sucursal_id = $sala->id;
        Dte::withoutEvents(fn () => $dte->save());

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('CLIENTE DASHBOARD SA');      // cliente fiscal
        $resp->assertSee('Súper Selectos San Benito'); // sala efectiva
    }

    public function test_actividad_reciente_sin_sala_muestra_solo_el_cliente(): void
    {
        $dte = $this->dte(['ambiente' => '01']);
        $this->assertNull($dte->cliente_sucursal_id);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('CLIENTE DASHBOARD SA');
        $resp->assertDontSee('Súper Selectos San Benito');
    }

    public function test_actividad_reciente_precarga_cliente_y_sala_sin_n_mas_1(): void
    {
        // 5 documentos, cada uno con su cliente y su sala: con eager loading debe haber
        // UNA sola consulta a `clientes` y UNA a `cliente_sucursales`, no una por fila.
        for ($i = 1; $i <= 5; $i++) {
            $dte = $this->dte(['ambiente' => '01', 'numero_control' => 'DTE-03-M001P001-00000000000010'.$i]);
            $sala = \App\Models\ClienteSucursal::factory()->create(['cliente_id' => $dte->cliente_id, 'nombre' => 'Sala '.$i]);
            $dte->cliente_sucursal_id = $sala->id;
            Dte::withoutEvents(fn () => $dte->save());
        }

        $consultas = [];
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$consultas) {
            $consultas[] = $q->sql;
        });

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $contar = fn (string $tabla) => count(array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, 'from "'.$tabla.'"') || str_contains($sql, 'from `'.$tabla.'`')
        ));

        $this->assertLessThanOrEqual(1, $contar('clientes'), 'Hay N+1 sobre `clientes` en el dashboard.');
        $this->assertLessThanOrEqual(1, $contar('cliente_sucursales'), 'Hay N+1 sobre `cliente_sucursales` en el dashboard.');
        // Y las 5 salas se muestran (no es que no consulte porque no renderiza).
        foreach (range(1, 5) as $i) {
            $resp->assertSee('Sala '.$i);
        }
    }

    // ---------- Tope de filas (la tarjeta debe cerrar a la altura de la columna derecha) ----------

    /**
     * Crea $cantidad documentos de producción aceptados, del más ANTIGUO al más reciente,
     * reutilizando un solo emisor. Devuelve los números de control en ese mismo orden.
     *
     * @return array<int, string>
     */
    private function documentosProduccion(int $cantidad): array
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisor();
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'CLIENTE DASHBOARD SA']);
        $sala = \App\Models\ClienteSucursal::factory()->create(['cliente_id' => $cliente->id, 'nombre' => 'Sala Tope']);

        $numeros = [];
        for ($i = 1; $i <= $cantidad; $i++) {
            $numero = 'DTE-03-M001P001-'.str_pad((string) $i, 15, '0', STR_PAD_LEFT);
            Dte::create([
                'tipo_dte' => TipoDte::CreditoFiscal->value,
                'estado' => EstadoDte::Aceptado->value,
                'ambiente' => '01',
                'establecimiento_id' => $estab->id,
                'punto_venta_id' => $pv->id,
                'cliente_id' => $cliente->id,
                'cliente_sucursal_id' => $sala->id,
                'numero_control' => $numero,
                // El más antiguo primero: el índice 1 es el de fecha más vieja.
                'fecha_emision' => now()->subDays($cantidad - $i)->toDateString(),
                'hora_emision' => now()->toTimeString(),
                'total_pagar' => 100 + $i,
            ]);
            $numeros[] = $numero;
        }

        return $numeros;
    }

    public function test_actividad_reciente_se_limita_a_doce_documentos(): void
    {
        $this->documentosProduccion(20);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        // El tope se aplica en la CONSULTA, no ocultando filas con CSS.
        $this->assertCount(12, $resp->viewData('actividad'));
    }

    public function test_actividad_reciente_muestra_los_mas_recientes_y_descarta_los_viejos(): void
    {
        $numeros = $this->documentosProduccion(15); // [0] = el más antiguo

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        // Los 12 más recientes (índices 3..14) están; los 3 más antiguos no se renderizan.
        foreach (array_slice($numeros, 3) as $numero) {
            $resp->assertSee($numero);
        }
        foreach (array_slice($numeros, 0, 3) as $numeroViejo) {
            $resp->assertDontSee($numeroViejo);
        }

        // Y siguen mostrándose cliente fiscal y sala.
        $resp->assertSee('CLIENTE DASHBOARD SA');
        $resp->assertSee('Sala Tope');
    }

    public function test_la_tabla_del_dashboard_no_tiene_scroll_vertical_interno(): void
    {
        $this->documentosProduccion(12);

        $html = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->getContent();

        // Sin altura máxima ni scroll vertical en el contenedor de la tabla. (No se
        // asserta `overflow-y-auto` a secas: la sidebar del layout lo usa legítimamente.)
        $this->assertStringNotContainsString('max-h-96', $html);
        $this->assertStringNotContainsString('overflow-x-auto overflow-y-auto', $html);
        // El scroll horizontal se conserva: es lo que salva la tabla en pantallas angostas.
        $this->assertStringContainsString('overflow-x-auto', $html);
    }

    public function test_con_muchas_filas_tampoco_hay_n_mas_1(): void
    {
        $this->documentosProduccion(20);

        $consultas = [];
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$consultas) {
            $consultas[] = $q->sql;
        });

        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $contar = fn (string $tabla) => count(array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, 'from "'.$tabla.'"') || str_contains($sql, 'from `'.$tabla.'`')
        ));

        $this->assertLessThanOrEqual(1, $contar('clientes'));
        $this->assertLessThanOrEqual(1, $contar('cliente_sucursales'));
    }

    public function test_actividad_reciente_no_incluye_borradores(): void
    {
        $this->dte(['estado' => EstadoDte::Borrador->value, 'numero_control' => null, 'ambiente' => '01']);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Todavía no hay documentos enviados o aceptados este período.');
    }

    // ---------- Documentos reales de producción (ambiente 01 fijo, NO el ambiente activo de la instalación) ----------

    public function test_solo_cuenta_documentos_aceptados_de_ambiente_produccion(): void
    {
        // La instalación puede estar en cualquier ambiente activo (aquí '00', típico de
        // desarrollo): las cifras de negocio SIEMPRE son de producción real ('01').
        config(['dte.ambiente' => '00']);
        $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000001', 'ambiente' => '01', 'total_pagar' => 100]);
        $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000002', 'ambiente' => '00', 'total_pagar' => 999]);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSeeInOrder(['DTE aceptados (mes)', '1']); // solo el de producción (01)
        $resp->assertSee('100.00');
        $resp->assertDontSee('999.00'); // el de pruebas (00/APITEST) nunca suma
    }

    public function test_las_cifras_de_produccion_no_cambian_segun_el_ambiente_activo_de_la_instalacion(): void
    {
        $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000001', 'ambiente' => '01', 'total_pagar' => 250]);

        config(['dte.ambiente' => '00']);
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['DTE aceptados (mes)', '1']);

        config(['dte.ambiente' => '01']);
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['DTE aceptados (mes)', '1']);
    }

    public function test_actividad_reciente_no_mezcla_ambientes(): void
    {
        $apitest = $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000001', 'ambiente' => '00', 'estado' => EstadoDte::Aceptado->value]);
        $produccion = $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000002', 'ambiente' => '01', 'estado' => EstadoDte::Aceptado->value]);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertDontSee($apitest->numero_control);
        $resp->assertSee($produccion->numero_control);
    }

    public function test_actividad_reciente_solo_muestra_aceptados_reales(): void
    {
        // Enviado/Rechazado: eventos reales del ciclo de vida, pero NO son "documentos
        // reales de producción" todavía (no confirmados/aceptados por Hacienda).
        $enviado = $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000001', 'ambiente' => '01', 'estado' => EstadoDte::Enviado->value]);
        $rechazado = $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000002', 'ambiente' => '01', 'estado' => EstadoDte::Rechazado->value]);
        $aceptado = $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000003', 'ambiente' => '01', 'estado' => EstadoDte::Aceptado->value]);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertDontSee($enviado->numero_control);
        $resp->assertDontSee($rechazado->numero_control);
        $resp->assertSee($aceptado->numero_control);
    }

    public function test_rechazados_no_inflan_ventas_aceptadas(): void
    {
        $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000001', 'ambiente' => '01', 'estado' => EstadoDte::Aceptado->value, 'total_pagar' => 100]);
        $this->dte(['numero_control' => 'DTE-03-M001P001-000000000000002', 'ambiente' => '01', 'estado' => EstadoDte::Rechazado->value, 'total_pagar' => 5000]);
        $this->dte(['numero_control' => null, 'ambiente' => '01', 'estado' => EstadoDte::Borrador->value, 'total_pagar' => 8000]);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $this->assertSame(1, $resp->viewData('stats')['dte_aceptados_mes']); // solo el aceptado cuenta
        $this->assertSame(100.0, $resp->viewData('stats')['ventas_mes']);   // rechazado/borrador no suman
        $resp->assertSeeInOrder(['Ventas del mes', '100.00']);
    }

    public function test_dte_145_aparece_sin_importar_el_ambiente_activo_de_la_instalacion(): void
    {
        // Reproduce el caso real: el CCF #145 (ambiente 01, aceptado real) debe verse
        // siempre en el panel del negocio, incluso si esta instalación corre en modo
        // pruebas/APITEST ('00').
        $produccion = $this->dte([
            'numero_control' => 'DTE-03-M001P002-000000000000001', 'ambiente' => '01',
            'estado' => EstadoDte::Aceptado->value, 'total_pagar' => 1.02,
        ]);

        config(['dte.ambiente' => '00']);
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()
            ->assertSee($produccion->numero_control);

        config(['dte.ambiente' => '01']);
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()
            ->assertSee($produccion->numero_control);
    }

    // ---------- Enlaces rápidos y permisos ----------

    public function test_gestor_dte_ve_acciones_de_creacion(): void
    {
        $resp = $this->actingAs($this->usuario('facturacion'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Nuevo CCF');
        $resp->assertSee('Nueva Factura');
        $resp->assertSee(route('facturacion.create-ccf'), false);
    }

    public function test_consulta_no_ve_acciones_de_creacion(): void
    {
        $resp = $this->actingAs($this->usuario('jefatura'))->get(route('dashboard'))->assertOk();

        $resp->assertDontSee('Nuevo CCF');
        $resp->assertDontSee('Nueva Factura');
        $resp->assertDontSee('Nueva lista de empaque');
    }

    public function test_solo_administrador_ve_tarjeta_de_jobs_fallidos(): void
    {
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->assertSee('Jobs fallidos');
        $this->actingAs($this->usuario('facturacion'))->get(route('dashboard'))->assertOk()->assertDontSee('Jobs fallidos');
    }

    public function test_solo_gestor_dte_ve_el_estado_tecnico(): void
    {
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()->assertSee('Estado técnico');
        $this->actingAs($this->usuario('jefatura'))->get(route('dashboard'))->assertOk()->assertDontSee('Estado técnico');
    }

    // ---------- Nada sensible, nada de emisión real ----------

    public function test_no_expone_secretos_ni_credenciales(): void
    {
        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertDontSee(config('app.key'));
        $resp->assertDontSee(env('DB_PASSWORD') ?: '__sin_password__');
        $resp->assertDontSeeText('DTE_FIRMADOR_MOCK');
        $resp->assertDontSeeText('APP_KEY');
    }

    public function test_dry_run_se_muestra_como_activo_y_no_se_apaga(): void
    {
        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('Dry-run');
        $resp->assertSee('ACTIVO');
        $this->assertTrue((bool) config('dte.transmision.dry_run'), 'DTE_TRANSMISION_DRY_RUN debe seguir activo.');
    }

    public function test_el_estado_tecnico_refleja_la_config_actual_y_no_se_cachea(): void
    {
        $usuario = $this->usuario('administrador');

        // Primera visita con la config de pruebas de la suite.
        config(['dte.ambiente' => '00', 'dte.transmision.dry_run' => true]);
        $this->actingAs($usuario)->get(route('dashboard'))->assertOk()
            ->assertSee('Pruebas')
            ->assertSee('ACTIVO');

        // Cambia la config: la MISMA pantalla debe reflejarlo de inmediato. El caché de
        // 60s del dashboard solo cubre las tarjetas de conteos, nunca el estado técnico.
        config(['dte.ambiente' => '01', 'dte.transmision.dry_run' => false]);
        $resp = $this->actingAs($usuario)->get(route('dashboard'))->assertOk();

        $resp->assertSee('Producción');
        $resp->assertDontSee('Pruebas');
    }

    public function test_el_estado_tecnico_no_depende_de_app_env(): void
    {
        // Son ejes independientes: APP_ENV no debe alterar el ambiente DTE ni el dry-run.
        config(['dte.ambiente' => '00', 'dte.transmision.dry_run' => true, 'app.env' => 'production']);

        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk()
            ->assertSee('Pruebas')
            ->assertSee('ACTIVO');
    }

    // ---------- Diagnóstico real (Parte 4: dashboard y "atención inmediata") ----------

    public function test_gestor_ve_el_bloque_de_diagnostico_consulta_no(): void
    {
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))
            ->assertOk()->assertSee('Diagnóstico');
        $this->actingAs($this->usuario('jefatura'))->get(route('dashboard'))
            ->assertOk()->assertDontSee('Diagnóstico');
    }

    public function test_todo_en_orden_sin_datos_ni_problemas_reales(): void
    {
        \App\Support\WorkerHeartbeat::pulse();
        \App\Models\RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true,
            'archivo_ruta' => 'auto-test.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'mensaje' => 'ok', 'origen' => 'automatico',
        ]);

        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))
            ->assertOk()->assertSee('Todo en orden');
    }

    public function test_advertencia_en_desarrollo_se_ve_como_entorno_seguro_azul(): void
    {
        // Backup de hoy presente (sin crítico) y worker sin latido con cola vacía:
        // el estado global queda en "advertencia", que en desarrollo es el estado
        // seguro esperado. Debe verse como "Entorno seguro de desarrollo" en azul
        // (sky), no como alerta naranja/roja.
        \App\Support\WorkerHeartbeat::olvidar();
        \App\Models\RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true,
            'archivo_ruta' => 'auto-test.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'mensaje' => 'ok', 'origen' => 'automatico',
        ]);

        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Entorno seguro de desarrollo')
            ->assertSee('text-sky-700')            // color informativo aplicado
            ->assertDontSee('Atención inmediata')  // no es crítico
            ->assertDontSee('Advertencia operativa'); // no es el texto de producción
    }

    public function test_failed_jobs_muestra_atencion_inmediata(): void
    {
        \App\Support\WorkerHeartbeat::pulse();
        \App\Models\RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true,
            'archivo_ruta' => 'auto-test.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'mensaje' => 'ok', 'origen' => 'automatico',
        ]);
        \Illuminate\Support\Facades\DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'fake', 'failed_at' => now(),
        ]);

        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))
            ->assertOk()->assertSee('Atención inmediata');
    }

    public function test_backup_vencido_muestra_atencion_inmediata_pero_cola_vacia_no(): void
    {
        // Sin ningún RespaldoEjecucion (nunca corrió el backup): crítico por backup,
        // NO por cola vacía (el worker sin datos + cola vacía es solo advertencia).
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))
            ->assertOk()->assertSee('Atención inmediata');
    }

    public function test_estado_critico_muestra_el_motivo_no_solo_atencion_inmediata(): void
    {
        // El caso real reportado: solo "Atención inmediata" sin decir POR QUÉ obligaba a
        // adivinar cuál de los 10 checks disparó la alerta. Sin backup de hoy, el badge
        // debe nombrar el check crítico.
        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Atención inmediata')
            ->assertSee('1 problema crítico: Backup del día');
    }

    public function test_varios_criticos_se_enumeran_en_el_motivo(): void
    {
        // Sin backup de hoy + un job fallido: varios críticos (jobs fallidos, backup, y
        // el worker también pasa a crítico con failed_jobs > 0), todos nombrados. No se
        // fija el número exacto para no acoplar el test al detalle de cada check.
        \Illuminate\Support\Facades\DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'fake', 'failed_at' => now(),
        ]);

        $resp = $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))->assertOk();

        $resp->assertSee('problemas críticos:');
        $resp->assertSee('Trabajos fallidos');
        $resp->assertSee('Backup del día');
    }

    public function test_sin_criticos_no_se_muestra_motivo(): void
    {
        \App\Support\WorkerHeartbeat::pulse();
        \App\Models\RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true,
            'archivo_ruta' => 'auto-test.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('a', 64), 'mensaje' => 'ok', 'origen' => 'automatico',
        ]);

        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))
            ->assertOk()->assertDontSee('problema crítico');
    }

    public function test_backup_manual_fallido_no_deja_critico_si_hay_otro_valido_hoy(): void
    {
        // El caso real de hoy: dos intentos manuales fallidos (error intermitente de
        // socket) + un backup exitoso el mismo día. El sistema NO debe quedar en
        // "Atención inmediata": hay un backup válido del día.
        \App\Support\WorkerHeartbeat::pulse();
        \App\Models\RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => false,
            'archivo_ruta' => null, 'archivo_tamano_bytes' => null,
            'sha256' => null, 'mensaje' => 'mysqldump terminó con código 2.', 'origen' => 'manual',
        ]);
        \App\Models\RespaldoEjecucion::create([
            'iniciado_en' => now(), 'terminado_en' => now(), 'exitoso' => true,
            'archivo_ruta' => 'auto-hoy.sql', 'archivo_tamano_bytes' => 100,
            'sha256' => str_repeat('b', 64), 'mensaje' => 'ok', 'origen' => 'manual',
        ]);

        $this->actingAs($this->usuario('administrador'))->get(route('dashboard'))
            ->assertOk()->assertDontSee('Atención inmediata');
    }

    // ---------- Rutas existentes siguen funcionando ----------

    public function test_rutas_de_navegacion_existentes_siguen_respondiendo(): void
    {
        $admin = $this->usuario('administrador');

        foreach ([
            'dashboard', 'clientes.index', 'productos.index', 'facturacion.index',
            'documentos-recibidos.index', 'exportaciones.index', 'exportaciones.clientes.index',
        ] as $ruta) {
            $this->actingAs($admin)->get(route($ruta))->assertOk();
        }
    }
}
