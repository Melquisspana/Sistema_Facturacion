<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\EstadoRecepcionPlanta;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaRecepcion;
use App\Models\Planta\PlantaUbicacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Candados de acceso de las recepciones.
 *
 * Todas las pruebas fuerzan la URL: ocultar un botón no autoriza nada, y lo que
 * importa es qué responde el servidor a una petición construida a mano.
 *
 * Tres capas, en orden:
 *   1. `auth` — el invitado va al login;
 *   2. `modulo.planta` — 404 con el módulo apagado, para TODOS los roles;
 *   3. `permission:...` — 403 por acción, no por pantalla.
 */
class PlantaRecepcionAutorizacionTest extends TestCase
{
    use RecepcionPlantaFixtures;
    use RefreshDatabase;

    // --- Módulo apagado ---

    /** @return array<string, array{0: string, 1: string}> */
    public static function rutasDeLectura(): array
    {
        return [
            'index' => ['get', 'planta.recepciones.index'],
            'create' => ['get', 'planta.recepciones.create'],
        ];
    }

    #[DataProvider('rutasDeLectura')]
    public function test_con_el_modulo_apagado_todo_responde_404(string $verbo, string $ruta): void
    {
        config()->set('planta.enabled', false);

        // Incluido el administrador: el flag apaga el área entera.
        $this->actingAs($this->admin())->$verbo(route($ruta))->assertNotFound();
    }

    public function test_con_el_modulo_apagado_confirmar_tambien_responde_404(): void
    {
        $this->encenderModulo();
        $recepcion = $this->borrador();

        config()->set('planta.enabled', false);

        $this->actingAs($this->admin())
            ->patch(route('planta.recepciones.confirmar', $recepcion))
            ->assertNotFound();

        $this->assertSame(0, PlantaMovimiento::count());
    }

    // --- Invitado ---

    public function test_un_invitado_va_al_login(): void
    {
        $this->encenderModulo();

        $this->get(route('planta.recepciones.index'))->assertRedirect(route('login'));
    }

    // --- Rol producción ---

    public function test_produccion_ve_el_listado(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuarioConRol('produccion'))
            ->get(route('planta.recepciones.index'))
            ->assertOk();
    }

    public function test_produccion_crea_un_borrador(): void
    {
        $this->encenderModulo();
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();

        $this->actingAs($this->usuarioConRol('produccion'))
            ->post(route('planta.recepciones.store'), $this->payload($ubicacion, [$this->linea($insumo)]))
            ->assertRedirect();

        $this->assertSame(1, PlantaRecepcion::count());
    }

    public function test_produccion_edita_su_borrador(): void
    {
        $this->encenderModulo();
        $operario = $this->usuarioConRol('produccion');

        // El borrador se ATRIBUYE al operario: sin eso la prueba mediría el
        // permiso y no la propiedad, y pasaría incluso sobre un documento ajeno.
        $recepcion = $this->borrador($operario);

        $this->actingAs($operario)
            ->get(route('planta.recepciones.edit', $recepcion))
            ->assertOk();
    }

    public function test_produccion_confirma_hacia_disponible(): void
    {
        $this->encenderModulo();
        $recepcion = $this->borrador();

        $this->actingAs($this->usuarioConRol('produccion'))
            ->patch(route('planta.recepciones.confirmar', $recepcion))
            ->assertRedirect();

        $this->assertSame(EstadoRecepcionPlanta::Confirmada, $recepcion->refresh()->estado);
    }

    public function test_produccion_no_puede_reversar(): void
    {
        $this->encenderModulo();
        $recepcion = $this->borrador();
        $confirmada = $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        $this->actingAs($this->usuarioConRol('produccion'))
            ->patch(route('planta.recepciones.reversar', $confirmada), ['motivo' => 'no deberia poder hacerlo'])
            ->assertForbidden();

        $this->assertSame(EstadoRecepcionPlanta::Confirmada, $confirmada->refresh()->estado);
    }

    public function test_produccion_no_puede_recibir_como_retenido(): void
    {
        $this->encenderModulo();
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();

        // La ruta la deja pasar —tiene `crear`—, pero el servicio la rechaza por
        // falta de `planta.calidad.gestionar`. El candado no está en el formulario.
        $this->actingAs($this->usuarioConRol('produccion'))
            ->post(route('planta.recepciones.store'), $this->payload($ubicacion, [
                $this->linea($insumo, ['estado_destino' => EstadoDisponibilidad::Retenido->value]),
            ]))
            ->assertSessionHasErrors('recepcion');

        $this->assertSame(0, PlantaRecepcion::count());
    }

    // --- Administrador ---

    public function test_el_administrador_puede_recibir_como_retenido(): void
    {
        $this->encenderModulo();
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();

        $this->actingAs($this->admin())
            ->post(route('planta.recepciones.store'), $this->payload($ubicacion, [
                $this->linea($insumo, ['estado_destino' => EstadoDisponibilidad::Retenido->value]),
            ]))
            ->assertRedirect();

        $this->assertSame(1, PlantaRecepcion::count());
    }

    public function test_el_administrador_puede_reversar(): void
    {
        $this->encenderModulo();
        $recepcion = $this->borrador();
        $confirmada = $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        $this->actingAs($this->admin())
            ->patch(route('planta.recepciones.reversar', $confirmada), ['motivo' => 'devolución completa al proveedor'])
            ->assertRedirect();

        $this->assertSame(EstadoRecepcionPlanta::Reversada, $confirmada->refresh()->estado);
    }

    public function test_el_administrador_recorre_todas_las_pantallas(): void
    {
        $this->encenderModulo();
        $recepcion = $this->borrador();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('planta.recepciones.index'))->assertOk();
        $this->actingAs($admin)->get(route('planta.recepciones.create'))->assertOk();
        $this->actingAs($admin)->get(route('planta.recepciones.show', $recepcion))->assertOk();
        $this->actingAs($admin)->get(route('planta.recepciones.edit', $recepcion))->assertOk();
    }

    // --- Roles ajenos al área ---

    /** @return array<string, array{0: string}> */
    public static function rolesAjenos(): array
    {
        return [
            'facturacion' => ['facturacion'],
            'contabilidad' => ['contabilidad'],
            'jefatura' => ['jefatura'],
        ];
    }

    #[DataProvider('rolesAjenos')]
    public function test_los_roles_ajenos_reciben_403(string $rol): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuarioConRol($rol))
            ->get(route('planta.recepciones.index'))
            ->assertForbidden();
    }

    #[DataProvider('rolesAjenos')]
    public function test_los_roles_ajenos_tampoco_confirman(string $rol): void
    {
        $this->encenderModulo();
        $recepcion = $this->borrador();

        $this->actingAs($this->usuarioConRol($rol))
            ->patch(route('planta.recepciones.confirmar', $recepcion))
            ->assertForbidden();

        $this->assertSame(0, PlantaMovimiento::count());
    }

    // --- No existe borrado físico ---

    public function test_no_hay_ruta_de_borrado(): void
    {
        $rutas = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains((string) $r->getName(), 'planta.recepciones.'))
            ->flatMap(fn ($r) => $r->methods())
            ->unique()
            ->values()
            ->all();

        // Un documento de inventario no se borra: se anula o se reversa.
        $this->assertNotContains('DELETE', $rutas);
    }

    // ---------------------------------------------------------------
    // Propiedad del borrador
    //
    // `planta.recepciones.crear` autoriza a PREPARAR recepciones, no a
    // manipular las de los demás. La excepción administrativa es
    // `recepciones.reversar` y NO `confirmar`, porque producción sí tiene
    // `confirmar`: usarlo como marca dejaría el candado sin efecto.
    //
    // CONFIRMAR no exige autoría a propósito: recibir la mercancía y capturarla
    // son actos que recaen en personas distintas, y quien confirma aplica
    // exactamente lo que el autor escribió porque no ha podido modificarlo.
    // ---------------------------------------------------------------

    /** Borrador creado por HTTP y atribuido al usuario indicado. */
    private function borradorDe(User $usuario, PlantaUbicacion $ubicacion, PlantaInsumo $insumo): PlantaRecepcion
    {
        $this->actingAs($usuario)
            ->post(route('planta.recepciones.store'), $this->payload($ubicacion, [$this->linea($insumo)]))
            ->assertRedirect();

        $recepcion = PlantaRecepcion::latest('id')->firstOrFail();

        $this->assertSame($usuario->id, $recepcion->creado_por);
        $this->assertSame(EstadoRecepcionPlanta::Borrador, $recepcion->estado);

        return $recepcion;
    }

    /** Huella de las dos tablas de inventario. */
    private function huellaInventario(): array
    {
        $existencias = DB::table('planta_existencias')
            ->selectRaw(
                'COUNT(*) as filas, '
                .'COALESCE(SUM(CAST(ROUND(cantidad * 10000) AS INTEGER)), 0) as suma, '
                .'COALESCE(MAX(id), 0) as max_id'
            )
            ->first();

        return [
            'mayor' => $this->huellaMayor(),
            'existencias' => [
                'filas' => (int) $existencias->filas,
                'suma' => bcdiv((string) (int) $existencias->suma, '10000', 4),
                'max_id' => (int) $existencias->max_id,
            ],
        ];
    }

    public function test_produccion_gestiona_su_propio_borrador(): void
    {
        $this->encenderModulo();
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $operario = $this->usuarioConRol('produccion');

        $suyo = $this->borradorDe($operario, $ubicacion, $insumo);

        $this->actingAs($operario)->get(route('planta.recepciones.edit', $suyo))->assertOk();

        $this->actingAs($operario)
            ->put(route('planta.recepciones.update', $suyo), $this->payload($ubicacion, [
                $this->linea($insumo, ['cantidad_recibida' => '8']),
            ]))
            ->assertRedirect();

        $this->assertSame('8.0000', $suyo->refresh()->detalles->first()->cantidad_recibida);

        $this->actingAs($operario)->patch(route('planta.recepciones.anular', $suyo))->assertRedirect();

        $this->assertSame(EstadoRecepcionPlanta::Anulada, $suyo->refresh()->estado);
    }

    public function test_produccion_no_toca_el_borrador_de_otro_operario(): void
    {
        $this->encenderModulo();
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $operarioA = $this->usuarioConRol('produccion');
        $operarioB = $this->usuarioConRol('produccion');

        $deA = $this->borradorDe($operarioA, $ubicacion, $insumo);
        $antes = $this->huellaInventario();

        $this->actingAs($operarioB)->get(route('planta.recepciones.edit', $deA))->assertForbidden();

        $this->actingAs($operarioB)
            ->put(route('planta.recepciones.update', $deA), $this->payload($ubicacion, [
                $this->linea($insumo, ['cantidad_recibida' => '999']),
            ]))
            ->assertForbidden();

        $this->actingAs($operarioB)->patch(route('planta.recepciones.anular', $deA))->assertForbidden();

        // Estado, contenido e inventario intactos.
        $deA->refresh();
        $this->assertSame(EstadoRecepcionPlanta::Borrador, $deA->estado);
        $this->assertSame('5.0000', $deA->detalles->first()->cantidad_recibida);
        $this->assertSame($operarioA->id, $deA->creado_por);
        $this->assertSame($antes, $this->huellaInventario());
    }

    public function test_produccion_no_toca_el_borrador_de_administracion(): void
    {
        $this->encenderModulo();
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $operario = $this->usuarioConRol('produccion');

        $delAdmin = $this->borradorDe($this->admin(), $ubicacion, $insumo);

        $this->actingAs($operario)->get(route('planta.recepciones.edit', $delAdmin))->assertForbidden();
        $this->actingAs($operario)
            ->put(route('planta.recepciones.update', $delAdmin), $this->payload($ubicacion, [$this->linea($insumo)]))
            ->assertForbidden();
        $this->actingAs($operario)->patch(route('planta.recepciones.anular', $delAdmin))->assertForbidden();

        $this->assertSame(EstadoRecepcionPlanta::Borrador, $delAdmin->refresh()->estado);
    }

    public function test_administracion_gestiona_el_borrador_de_produccion(): void
    {
        $this->encenderModulo();
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $operario = $this->usuarioConRol('produccion');
        $admin = $this->admin();

        $delOperario = $this->borradorDe($operario, $ubicacion, $insumo);

        $this->actingAs($admin)->get(route('planta.recepciones.edit', $delOperario))->assertOk();

        $this->actingAs($admin)
            ->put(route('planta.recepciones.update', $delOperario), $this->payload($ubicacion, [
                $this->linea($insumo, ['cantidad_recibida' => '6']),
            ]))
            ->assertRedirect();

        $this->assertSame('6.0000', $delOperario->refresh()->detalles->first()->cantidad_recibida);

        $this->actingAs($admin)->patch(route('planta.recepciones.anular', $delOperario))->assertRedirect();

        $this->assertSame(EstadoRecepcionPlanta::Anulada, $delOperario->refresh()->estado);
    }

    /**
     * CONFIRMAR NO EXIGE PROPIEDAD, y es la decisión operativa de este bloque:
     * quien recibe físicamente la mercancía puede no ser quien la capturó.
     */
    public function test_produccion_confirma_la_recepcion_que_preparo_otro_operario(): void
    {
        $this->encenderModulo();
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $operarioA = $this->usuarioConRol('produccion');
        $operarioB = $this->usuarioConRol('produccion');

        $deA = $this->borradorDe($operarioA, $ubicacion, $insumo);

        $this->actingAs($operarioB)
            ->patch(route('planta.recepciones.confirmar', $deA))
            ->assertRedirect();

        $deA->refresh();

        $this->assertSame(EstadoRecepcionPlanta::Confirmada, $deA->estado);
        // El contenido sigue siendo el que preparó A: B no pudo alterarlo.
        $this->assertSame($operarioA->id, $deA->creado_por);
        $this->assertSame('5.0000', $deA->detalles->first()->cantidad_recibida);
        // Y queda constancia de quién confirmó.
        $this->assertSame($operarioB->id, $deA->confirmado_por);
        $this->assertNotNull($deA->confirmado_en);

        // El inventario subió: 5 × 100 × 1 = 500 disponibles.
        $bucket = $this->bucketDe($deA);
        $this->assertSame('500.0000', $this->saldo($bucket));

        $this->assertSame(1, PlantaMovimiento::query()
            ->where('documento_type', PlantaRecepcion::class)
            ->where('documento_id', $deA->id)
            ->count());
    }

    public function test_produccion_sigue_sin_poder_reversar(): void
    {
        $this->encenderModulo();
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $operario = $this->usuarioConRol('produccion');

        $suya = $this->borradorDe($operario, $ubicacion, $insumo);
        $this->actingAs($operario)->patch(route('planta.recepciones.confirmar', $suya))->assertRedirect();

        $antes = $this->huellaInventario();

        // Ni sobre la suya propia: reversar no es operación.
        $this->actingAs($operario)
            ->patch(route('planta.recepciones.reversar', $suya), ['motivo' => 'devolucion completa al proveedor'])
            ->assertForbidden();

        $this->assertSame(EstadoRecepcionPlanta::Confirmada, $suya->refresh()->estado);
        $this->assertSame($antes, $this->huellaInventario());
    }

    public function test_con_el_modulo_apagado_el_guard_de_propiedad_no_llega_a_evaluarse(): void
    {
        $this->encenderModulo();
        $operario = $this->usuarioConRol('produccion');
        $suyo = $this->borradorDe($operario, $this->bodega(), $this->insumoConLotes());

        config()->set('planta.enabled', false);

        // 404, no 403: el interruptor va antes que cualquier candado de propiedad.
        $this->actingAs($operario)->get(route('planta.recepciones.edit', $suyo))->assertNotFound();
        $this->actingAs($operario)->patch(route('planta.recepciones.anular', $suyo))->assertNotFound();

        $this->assertSame(EstadoRecepcionPlanta::Borrador, $suyo->refresh()->estado);
    }

    /** La administración se identifica por PERMISO, no por el nombre del rol. */
    public function test_la_excepcion_administrativa_se_basa_en_el_permiso(): void
    {
        $this->encenderModulo();
        $operario = $this->usuarioConRol('produccion');
        $deOtro = $this->borradorDe($operario, $this->bodega(), $this->insumoConLotes());

        // Un usuario SIN rol administrador, con los permisos justos para ver y
        // reversar, gestiona el borrador ajeno igual que el administrador.
        $supervisor = User::factory()->create()->givePermissionTo([
            'planta.ver',
            'planta.recepciones.ver',
            'planta.recepciones.crear',
            'planta.recepciones.reversar',
        ]);

        $this->assertFalse($supervisor->hasRole('administrador'));

        $this->actingAs($supervisor)->get(route('planta.recepciones.edit', $deOtro))->assertOk();
        $this->actingAs($supervisor)->patch(route('planta.recepciones.anular', $deOtro))->assertRedirect();

        $this->assertSame(EstadoRecepcionPlanta::Anulada, $deOtro->refresh()->estado);
    }

    /** El guard no añade consultas: `creado_por` ya viene con el modelo enlazado. */
    public function test_el_guard_no_agrega_consultas_relevantes(): void
    {
        $this->encenderModulo();
        $operario = $this->usuarioConRol('produccion');
        $suyo = $this->borradorDe($operario, $this->bodega(), $this->insumoConLotes());

        // Calentamiento: no se mide.
        $this->actingAs($operario)->get(route('planta.recepciones.edit', $suyo))->assertOk();

        $consultas = 0;
        $midiendo = true;
        DB::listen(function () use (&$consultas, &$midiendo): void {
            if ($midiendo) {
                $consultas++;
            }
        });

        $this->actingAs($operario)->get(route('planta.recepciones.edit', $suyo))->assertOk();
        $midiendo = false;

        // Umbral holgado: lo que se comprueba es que el guard no dispara una
        // consulta por documento, no el número exacto de la pantalla.
        $this->assertLessThan(30, $consultas, "La pantalla hace {$consultas} consultas.");
    }

    public function test_la_gestion_de_borradores_no_consulta_tablas_fiscales(): void
    {
        $this->encenderModulo();
        $operario = $this->usuarioConRol('produccion');
        $suyo = $this->borradorDe($operario, $this->bodega(), $this->insumoConLotes());

        $sentencias = [];
        $midiendo = true;
        DB::listen(function ($query) use (&$sentencias, &$midiendo): void {
            if ($midiendo) {
                $sentencias[] = $query->sql;
            }
        });

        $this->actingAs($operario)->get(route('planta.recepciones.edit', $suyo))->assertOk();
        $midiendo = false;

        foreach (['dtes', 'documentos_recibidos', 'exportaciones'] as $tabla) {
            foreach ($sentencias as $sql) {
                $this->assertStringNotContainsString($tabla, $sql, "No debe consultarse «{$tabla}».");
            }
        }
    }
}
