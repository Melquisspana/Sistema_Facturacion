<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoTrasladoPlanta;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaTraslado;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Candados de acceso de los traslados.
 *
 * Los permisos son por ACCIÓN, no por pantalla, porque enviar y recibir son
 * actos físicos distintos que pueden recaer en personas distintas. Producción
 * opera el día a día —ver, crear, enviar, recibir— y NO puede reversar: deshacer
 * inventario ya contabilizado no es operación.
 *
 * Todas las pruebas fuerzan la URL: ocultar un botón no autoriza nada.
 */
class PlantaTrasladoAutorizacionTest extends TestCase
{
    use RefreshDatabase;
    use TrasladoPlantaFixtures;

    // --- Módulo apagado ---

    /** @return array<string, array{0: string, 1: string}> */
    public static function rutasDeLectura(): array
    {
        return [
            'index' => ['get', 'planta.traslados.index'],
            'create' => ['get', 'planta.traslados.create'],
        ];
    }

    #[DataProvider('rutasDeLectura')]
    public function test_con_el_modulo_apagado_responde_404(string $verbo, string $ruta): void
    {
        config()->set('planta.enabled', false);

        // Incluido el administrador: el flag apaga el área entera.
        $this->actingAs($this->admin())->$verbo(route($ruta))->assertNotFound();
    }

    public function test_con_el_modulo_apagado_enviar_tambien_responde_404(): void
    {
        $this->encenderModulo();
        $traslado = $this->borradorTraslado($this->escenarioTraslado());

        config()->set('planta.enabled', false);

        $this->actingAs($this->admin())
            ->patch(route('planta.traslados.enviar', $traslado))
            ->assertNotFound();

        $this->assertSame(EstadoTrasladoPlanta::Borrador, $traslado->refresh()->estado);
    }

    // --- Invitado ---

    public function test_un_invitado_va_al_login(): void
    {
        $this->encenderModulo();

        $this->get(route('planta.traslados.index'))->assertRedirect(route('login'));
    }

    // --- Rol producción: opera todo menos reversar ---

    public function test_produccion_tiene_los_permisos_de_operacion_y_no_el_de_reversar(): void
    {
        $produccion = $this->usuarioConRol('produccion');

        $this->assertTrue($produccion->can('planta.traslados.ver'));
        $this->assertTrue($produccion->can('planta.traslados.crear'));
        $this->assertTrue($produccion->can('planta.traslados.enviar'));
        $this->assertTrue($produccion->can('planta.traslados.recibir'));
        $this->assertFalse($produccion->can('planta.traslados.reversar'));
    }

    public function test_produccion_ve_listado_y_detalle(): void
    {
        $this->encenderModulo();
        $traslado = $this->borradorTraslado($this->escenarioTraslado());
        $produccion = $this->usuarioConRol('produccion');

        $this->actingAs($produccion)->get(route('planta.traslados.index'))->assertOk();
        $this->actingAs($produccion)->get(route('planta.traslados.show', $traslado))->assertOk();
    }

    public function test_produccion_crea_un_borrador(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();

        $this->actingAs($this->usuarioConRol('produccion'))
            ->post(route('planta.traslados.store'), $this->payloadTraslado($e, '100'))
            ->assertRedirect();

        $this->assertSame(1, PlantaTraslado::count());
    }

    public function test_produccion_envia_y_recibe(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');
        $produccion = $this->usuarioConRol('produccion');

        $this->actingAs($produccion)
            ->patch(route('planta.traslados.enviar', $traslado))
            ->assertRedirect();
        $this->assertSame(EstadoTrasladoPlanta::Enviado, $traslado->refresh()->estado);

        $this->actingAs($produccion)
            ->patch(route('planta.traslados.recibir', $traslado))
            ->assertRedirect();
        $this->assertSame(EstadoTrasladoPlanta::Recibido, $traslado->refresh()->estado);
    }

    public function test_produccion_no_puede_reversar(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');
        $admin = $this->admin();

        $this->servicioTraslado()->enviar($traslado, $admin);
        $this->servicioTraslado()->recibir($traslado, $admin);

        $this->actingAs($this->usuarioConRol('produccion'))
            ->patch(route('planta.traslados.reversar', $traslado), ['motivo' => 'no deberia poder hacerlo'])
            ->assertForbidden();

        $this->assertSame(EstadoTrasladoPlanta::Recibido, $traslado->refresh()->estado);
    }

    public function test_produccion_cancela_un_borrador(): void
    {
        $this->encenderModulo();
        $operario = $this->usuarioConRol('produccion');

        // El borrador se ATRIBUYE al operario que lo cancela: `borradorTraslado()`
        // lo crearía como administrador y la prueba mediría el permiso en vez de
        // la propiedad, pasando incluso sobre un documento ajeno.
        $traslado = $this->borradorDe($operario, $this->escenarioTraslado());

        $this->actingAs($operario)
            ->patch(route('planta.traslados.cancelar', $traslado))
            ->assertRedirect();

        $this->assertSame(EstadoTrasladoPlanta::Cancelado, $traslado->refresh()->estado);
    }

    // --- Administrador ---

    public function test_el_administrador_recorre_todas_las_pantallas(): void
    {
        $this->encenderModulo();
        $traslado = $this->borradorTraslado($this->escenarioTraslado());
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('planta.traslados.index'))->assertOk();
        $this->actingAs($admin)->get(route('planta.traslados.create'))->assertOk();
        $this->actingAs($admin)->get(route('planta.traslados.show', $traslado))->assertOk();
        $this->actingAs($admin)->get(route('planta.traslados.edit', $traslado))->assertOk();
    }

    public function test_el_administrador_reversa(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');
        $admin = $this->admin();

        $this->servicioTraslado()->enviar($traslado, $admin);

        $this->actingAs($admin)
            ->patch(route('planta.traslados.reversar', $traslado), ['motivo' => 'el camión no llegó a salir'])
            ->assertRedirect();

        $this->assertSame(EstadoTrasladoPlanta::Reversado, $traslado->refresh()->estado);
    }

    public function test_el_motivo_corto_se_rechaza(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $traslado = $this->borradorTraslado($e, '200');
        $admin = $this->admin();

        $this->servicioTraslado()->enviar($traslado, $admin);

        $this->actingAs($admin)
            ->patch(route('planta.traslados.reversar', $traslado), ['motivo' => 'error'])
            ->assertSessionHasErrors('motivo');

        $this->assertSame(EstadoTrasladoPlanta::Enviado, $traslado->refresh()->estado);
    }

    public function test_el_destino_igual_al_origen_se_rechaza_en_el_formulario(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();

        $this->actingAs($this->admin())
            ->post(route('planta.traslados.store'), $this->payloadTraslado($e, '100', [
                'planta_ubicacion_destino_id' => $e['origen']->id,
            ]))
            ->assertSessionHasErrors('planta_ubicacion_destino_id');

        $this->assertSame(0, PlantaTraslado::count());
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
            ->get(route('planta.traslados.index'))
            ->assertForbidden();
    }

    #[DataProvider('rolesAjenos')]
    public function test_los_roles_ajenos_tampoco_envian(string $rol): void
    {
        $this->encenderModulo();
        $traslado = $this->borradorTraslado($this->escenarioTraslado());

        $this->actingAs($this->usuarioConRol($rol))
            ->patch(route('planta.traslados.enviar', $traslado))
            ->assertForbidden();

        $this->assertSame(0, PlantaMovimiento::where('tipo', 'traslado_envio')->count());
    }

    // --- Superficie ---

    public function test_no_hay_ruta_de_borrado(): void
    {
        $metodos = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains((string) $r->getName(), 'planta.traslados.'))
            ->flatMap(fn ($r) => $r->methods())
            ->unique()
            ->values()
            ->all();

        // Un documento de inventario no se borra: se cancela o se reversa.
        $this->assertNotContains('DELETE', $metodos);
    }

    // ---------------------------------------------------------------
    // Propiedad del borrador
    //
    // La propiedad protege el CONTENIDO, no el acto físico. Editar, actualizar
    // y cancelar exigen ser el autor —o tener `traslados.reversar`—; ENVIAR y
    // RECIBIR no, porque la salida en Casa y la llegada en Fábrica recaen a
    // menudo en personas distintas y tienen permisos propios.
    //
    // La excepción administrativa es `reversar` y NO `enviar`: producción tiene
    // `enviar` y `recibir`, así que cualquiera de los dos como marca dejaría el
    // candado sin efecto.
    // ---------------------------------------------------------------

    /** Borrador creado por HTTP y atribuido al usuario indicado. */
    private function borradorDe(User $usuario, array $e, string $cantidad = '200'): PlantaTraslado
    {
        $this->actingAs($usuario)
            ->post(route('planta.traslados.store'), $this->payloadTraslado($e, $cantidad))
            ->assertRedirect();

        $traslado = PlantaTraslado::latest('id')->firstOrFail();

        $this->assertSame($usuario->id, $traslado->creado_por);
        $this->assertSame(EstadoTrasladoPlanta::Borrador, $traslado->estado);

        return $traslado;
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
        $e = $this->escenarioTraslado();
        $operario = $this->usuarioConRol('produccion');

        $suyo = $this->borradorDe($operario, $e);

        $this->actingAs($operario)->get(route('planta.traslados.edit', $suyo))->assertOk();

        $this->actingAs($operario)
            ->put(route('planta.traslados.update', $suyo), $this->payloadTraslado($e, '150'))
            ->assertRedirect();

        $this->assertSame('150.0000', $suyo->refresh()->detalles->first()->cantidad);

        $this->actingAs($operario)->patch(route('planta.traslados.cancelar', $suyo))->assertRedirect();

        $this->assertSame(EstadoTrasladoPlanta::Cancelado, $suyo->refresh()->estado);
    }

    public function test_produccion_puede_enviar_su_propio_borrador(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $operario = $this->usuarioConRol('produccion');

        $suyo = $this->borradorDe($operario, $e);

        $this->actingAs($operario)->patch(route('planta.traslados.enviar', $suyo))->assertRedirect();

        $this->assertSame(EstadoTrasladoPlanta::Enviado, $suyo->refresh()->estado);
    }

    public function test_produccion_no_toca_el_borrador_de_otro_operario(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $operarioA = $this->usuarioConRol('produccion');
        $operarioB = $this->usuarioConRol('produccion');

        $deA = $this->borradorDe($operarioA, $e);
        $antes = $this->huellaInventario();

        $this->actingAs($operarioB)->get(route('planta.traslados.edit', $deA))->assertForbidden();

        $this->actingAs($operarioB)
            ->put(route('planta.traslados.update', $deA), $this->payloadTraslado($e, '999'))
            ->assertForbidden();

        $this->actingAs($operarioB)->patch(route('planta.traslados.cancelar', $deA))->assertForbidden();

        $deA->refresh();
        $this->assertSame(EstadoTrasladoPlanta::Borrador, $deA->estado);
        $this->assertSame('200.0000', $deA->detalles->first()->cantidad);
        $this->assertSame($operarioA->id, $deA->creado_por);
        $this->assertSame($antes, $this->huellaInventario());
    }

    public function test_produccion_no_toca_el_borrador_de_administracion(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $operario = $this->usuarioConRol('produccion');

        $delAdmin = $this->borradorDe($this->admin(), $e);

        $this->actingAs($operario)->get(route('planta.traslados.edit', $delAdmin))->assertForbidden();
        $this->actingAs($operario)
            ->put(route('planta.traslados.update', $delAdmin), $this->payloadTraslado($e, '10'))
            ->assertForbidden();
        $this->actingAs($operario)->patch(route('planta.traslados.cancelar', $delAdmin))->assertForbidden();

        $this->assertSame(EstadoTrasladoPlanta::Borrador, $delAdmin->refresh()->estado);
    }

    public function test_administracion_gestiona_el_borrador_de_produccion(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $operario = $this->usuarioConRol('produccion');
        $admin = $this->admin();

        $delOperario = $this->borradorDe($operario, $e);

        $this->actingAs($admin)->get(route('planta.traslados.edit', $delOperario))->assertOk();

        $this->actingAs($admin)
            ->put(route('planta.traslados.update', $delOperario), $this->payloadTraslado($e, '120'))
            ->assertRedirect();

        $this->assertSame('120.0000', $delOperario->refresh()->detalles->first()->cantidad);

        $this->actingAs($admin)->patch(route('planta.traslados.cancelar', $delOperario))->assertRedirect();

        $this->assertSame(EstadoTrasladoPlanta::Cancelado, $delOperario->refresh()->estado);
    }

    /**
     * EL CIRCUITO REAL, con tres personas distintas: A prepara en Casa, B
     * despacha y C recibe en Fábrica. Ninguno de los tres actos exige ser el
     * autor, y sin embargo el contenido nunca deja de ser el que escribió A.
     */
    public function test_enviar_y_recibir_no_exigen_ser_el_autor(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $operarioA = $this->usuarioConRol('produccion');
        $operarioB = $this->usuarioConRol('produccion');
        $operarioC = $this->usuarioConRol('produccion');

        $deA = $this->borradorDe($operarioA, $e);

        // B despacha lo que preparó A.
        $this->actingAs($operarioB)->patch(route('planta.traslados.enviar', $deA))->assertRedirect();

        $deA->refresh();
        $this->assertSame(EstadoTrasladoPlanta::Enviado, $deA->estado);
        $this->assertSame($operarioA->id, $deA->creado_por, 'B no pudo alterar el contenido ni la autoría.');
        $this->assertSame($operarioB->id, $deA->enviado_por);
        $this->assertNotNull($deA->enviado_en);
        $this->assertSame('200.0000', $deA->detalles->first()->cantidad);

        // El saldo salió del origen y está en TRÁNSITO, atado a ESTE traslado.
        $this->assertSame('300.0000', $this->saldo($this->bucketOrigen($e)));
        $this->assertSame('200.0000', $this->saldo($this->bucketTransito($e, $deA)));

        // C, que ni lo preparó ni lo envió, lo recibe en Fábrica.
        $this->actingAs($operarioC)->patch(route('planta.traslados.recibir', $deA))->assertRedirect();

        $deA->refresh();
        $this->assertSame(EstadoTrasladoPlanta::Recibido, $deA->estado);
        $this->assertSame($operarioC->id, $deA->recibido_por);
        $this->assertNotNull($deA->recibido_en);

        // Y el saldo llegó al destino.
        $this->assertSame('0.0000', $this->saldo($this->bucketTransito($e, $deA)));
        $this->assertSame('200.0000', $this->saldo($this->bucketDestino($e)));
    }

    public function test_produccion_sigue_sin_poder_reversar(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $operario = $this->usuarioConRol('produccion');

        $suyo = $this->borradorDe($operario, $e);
        $this->actingAs($operario)->patch(route('planta.traslados.enviar', $suyo))->assertRedirect();

        $antes = $this->huellaInventario();

        // Ni sobre el suyo propio.
        $this->actingAs($operario)
            ->patch(route('planta.traslados.reversar', $suyo), ['motivo' => 'salio el lote equivocado'])
            ->assertForbidden();

        $this->assertSame(EstadoTrasladoPlanta::Enviado, $suyo->refresh()->estado);
        $this->assertSame($antes, $this->huellaInventario());
    }

    public function test_con_el_modulo_apagado_el_guard_de_propiedad_no_llega_a_evaluarse(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $operario = $this->usuarioConRol('produccion');
        $suyo = $this->borradorDe($operario, $e);

        config()->set('planta.enabled', false);

        $this->actingAs($operario)->get(route('planta.traslados.edit', $suyo))->assertNotFound();
        $this->actingAs($operario)->patch(route('planta.traslados.cancelar', $suyo))->assertNotFound();

        $this->assertSame(EstadoTrasladoPlanta::Borrador, $suyo->refresh()->estado);
    }

    /** La administración se identifica por PERMISO, no por el nombre del rol. */
    public function test_la_excepcion_administrativa_se_basa_en_el_permiso(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $operario = $this->usuarioConRol('produccion');
        $deOtro = $this->borradorDe($operario, $e);

        $supervisor = User::factory()->create()->givePermissionTo([
            'planta.ver',
            'planta.traslados.ver',
            'planta.traslados.crear',
            'planta.traslados.reversar',
        ]);

        $this->assertFalse($supervisor->hasRole('administrador'));

        $this->actingAs($supervisor)->get(route('planta.traslados.edit', $deOtro))->assertOk();
        $this->actingAs($supervisor)->patch(route('planta.traslados.cancelar', $deOtro))->assertRedirect();

        $this->assertSame(EstadoTrasladoPlanta::Cancelado, $deOtro->refresh()->estado);
    }

    public function test_la_gestion_de_borradores_no_consulta_tablas_fiscales(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioTraslado();
        $operario = $this->usuarioConRol('produccion');
        $suyo = $this->borradorDe($operario, $e);

        $sentencias = [];
        $midiendo = true;
        DB::listen(function ($query) use (&$sentencias, &$midiendo): void {
            if ($midiendo) {
                $sentencias[] = $query->sql;
            }
        });

        $this->actingAs($operario)->get(route('planta.traslados.edit', $suyo))->assertOk();
        $midiendo = false;

        foreach (['dtes', 'documentos_recibidos', 'exportaciones'] as $tabla) {
            foreach ($sentencias as $sql) {
                $this->assertStringNotContainsString($tabla, $sql, "No debe consultarse «{$tabla}».");
            }
        }
    }
}
