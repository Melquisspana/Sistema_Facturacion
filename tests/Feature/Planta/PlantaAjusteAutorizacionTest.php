<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoAjustePlanta;
use App\Enums\Planta\TipoAjuste;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Models\Planta\PlantaAjuste;
use App\Models\Planta\PlantaMovimiento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Candados de acceso de los ajustes.
 *
 * El reparto es ASIMÉTRICO a propósito: producción VE lo que se ajustó en su
 * bodega y PREPARA el borrador de lo que encuentra —la merma la anota quien está
 * ahí, con el motivo fresco—, pero el acto que ALTERA la cantidad física, que es
 * `confirmar`, queda en administración. Un borrador no escribe en el mayor ni
 * toca `planta_existencias`: hasta que alguien lo confirma, no ha pasado nada.
 *
 * Esa separación es la que impide que quien pudiera ser responsable de un
 * faltante lo aplique solo, sin dejar de permitirle registrarlo.
 *
 * Todas las pruebas fuerzan la URL: ocultar un botón no autoriza nada.
 */
class PlantaAjusteAutorizacionTest extends TestCase
{
    use AjustePlantaFixtures;
    use RefreshDatabase;

    // --- Módulo apagado ---

    /** @return array<string, array{0: string, 1: string}> */
    public static function rutasDeLectura(): array
    {
        return [
            'index' => ['get', 'planta.ajustes.index'],
            'create' => ['get', 'planta.ajustes.create'],
        ];
    }

    #[DataProvider('rutasDeLectura')]
    public function test_con_el_modulo_apagado_responde_404(string $verbo, string $ruta): void
    {
        config()->set('planta.enabled', false);

        // Incluido el administrador: el flag apaga el área entera.
        $this->actingAs($this->admin())->$verbo(route($ruta))->assertNotFound();
    }

    public function test_con_el_modulo_apagado_confirmar_tambien_responde_404(): void
    {
        $this->encenderModulo();
        $ajuste = $this->borradorAjuste($this->escenarioConSaldo(), TipoAjuste::Positivo, '10');

        config()->set('planta.enabled', false);

        $this->actingAs($this->admin())
            ->patch(route('planta.ajustes.confirmar', $ajuste))
            ->assertNotFound();

        $this->assertSame(EstadoAjustePlanta::Borrador, $ajuste->refresh()->estado);
    }

    // --- Invitado ---

    public function test_un_invitado_va_al_login(): void
    {
        $this->encenderModulo();

        $this->get(route('planta.ajustes.index'))->assertRedirect(route('login'));
    }

    // --- Rol producción: prepara, no aplica ---

    public function test_produccion_puede_preparar_pero_no_aplicar(): void
    {
        $produccion = $this->usuarioConRol('produccion');

        $this->assertTrue($produccion->can('planta.ajustes.ver'));
        $this->assertTrue($produccion->can('planta.ajustes.crear'));
        $this->assertFalse($produccion->can('planta.ajustes.confirmar'));
        $this->assertFalse($produccion->can('planta.ajustes.reversar'));
    }

    public function test_produccion_ve_listado_y_detalle(): void
    {
        $this->encenderModulo();
        $ajuste = $this->borradorAjuste($this->escenarioConSaldo(), TipoAjuste::Positivo, '10');
        $produccion = $this->usuarioConRol('produccion');

        $this->actingAs($produccion)->get(route('planta.ajustes.index'))->assertOk();
        $this->actingAs($produccion)->get(route('planta.ajustes.show', $ajuste))->assertOk();
    }

    public function test_produccion_entra_al_formulario_de_creacion(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuarioConRol('produccion'))
            ->get(route('planta.ajustes.create'))
            ->assertOk();
    }

    /**
     * El caso que motiva todo este reparto: quien está en la bodega encuentra
     * producto dañado y lo registra en el momento, con el motivo fresco.
     *
     * Lo importante no es que pueda guardarlo, sino que guardarlo NO MUEVA NADA:
     * el mayor y la proyección quedan exactamente igual que antes.
     */
    public function test_produccion_prepara_una_merma_sin_mover_inventario(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $bucket = $this->bucketDeAjuste($e);
        $produccion = $this->usuarioConRol('produccion');

        $mayorAntes = $this->huellaMayor();
        $saldoAntes = $this->saldo($bucket);
        $existenciasAntes = $this->huellaExistencias();

        $this->actingAs($produccion)
            ->post(route('planta.ajustes.store'), $this->payloadAjuste(
                $e,
                TipoAjuste::Merma,
                '15',
                extraCabecera: ['motivo' => 'Se rompieron dos sacos al descargar del camión'],
            ))
            ->assertRedirect();

        $ajuste = PlantaAjuste::latest('id')->firstOrFail();

        $this->assertSame(EstadoAjustePlanta::Borrador, $ajuste->estado);
        $this->assertSame(TipoAjuste::Merma, $ajuste->tipo);
        $this->assertSame($produccion->id, $ajuste->creado_por);

        // Y NADA se movió: ni una fila del mayor, ni una milésima del saldo.
        $this->assertSame($mayorAntes, $this->huellaMayor(), 'Un borrador no puede escribir en el libro mayor.');
        $this->assertSame($existenciasAntes, $this->huellaExistencias(), 'Un borrador no puede tocar las existencias.');
        $this->assertSame($saldoAntes, $this->saldo($bucket));
    }

    // Editar y anular el borrador PROPIO se prueba en
    // `test_produccion_gestiona_su_propio_borrador`, junto al resto de las
    // reglas de propiedad: sin atribuir el borrador al usuario que lo edita, la
    // prueba mediría el permiso y no la propiedad, que es lo que importa aquí.

    /** El motivo sigue siendo obligatorio, también para quien solo prepara. */
    public function test_produccion_no_puede_preparar_una_merma_sin_motivo(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();

        $payload = $this->payloadAjuste($e, TipoAjuste::Merma, '15', extraCabecera: ['motivo' => '']);

        $this->actingAs($this->usuarioConRol('produccion'))
            ->post(route('planta.ajustes.store'), $payload)
            ->assertSessionHasErrors('motivo');

        $this->assertSame(0, PlantaAjuste::count());
    }

    public function test_produccion_no_confirma_ni_reversa(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $bucket = $this->bucketDeAjuste($e);
        $borrador = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');
        $confirmado = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '20');
        $produccion = $this->usuarioConRol('produccion');

        $mayorAntes = $this->huellaMayor();

        $this->actingAs($produccion)
            ->patch(route('planta.ajustes.confirmar', $borrador))
            ->assertForbidden();

        $this->actingAs($produccion)
            ->patch(route('planta.ajustes.reversar', $confirmado), ['motivo' => 'no deberia poder hacerlo'])
            ->assertForbidden();

        $this->assertSame(EstadoAjustePlanta::Borrador, $borrador->refresh()->estado);
        $this->assertSame(EstadoAjustePlanta::Confirmado, $confirmado->refresh()->estado);
        // El saldo es el de la recepción más el único ajuste que sí se aplicó.
        $this->assertSame('520.0000', $this->saldo($bucket));
        $this->assertSame($mayorAntes, $this->huellaMayor(), 'Un intento rechazado no deja rastro.');
    }

    /**
     * El circuito completo de una merma, repartido entre dos personas: producción
     * la prepara y el administrador la aplica. Es el flujo que este reparto de
     * permisos existe para permitir.
     */
    public function test_el_administrador_confirma_la_merma_que_preparo_produccion(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $bucket = $this->bucketDeAjuste($e);
        $produccion = $this->usuarioConRol('produccion');
        $admin = $this->admin();

        $this->actingAs($produccion)
            ->post(route('planta.ajustes.store'), $this->payloadAjuste(
                $e,
                TipoAjuste::Merma,
                '15',
                extraCabecera: ['motivo' => 'Se rompieron dos sacos al descargar del camión'],
            ))
            ->assertRedirect();

        $ajuste = PlantaAjuste::latest('id')->firstOrFail();
        $saldoAntes = $this->saldo($bucket);

        // Hasta aquí no se ha movido nada.
        $this->assertSame('500.0000', $saldoAntes);

        $this->actingAs($admin)
            ->patch(route('planta.ajustes.confirmar', $ajuste))
            ->assertRedirect();

        $ajuste->refresh();

        $this->assertSame(EstadoAjustePlanta::Confirmado, $ajuste->estado);
        // Confirmó otra persona distinta de la que lo preparó: eso es el control.
        $this->assertSame($produccion->id, $ajuste->creado_por);
        $this->assertSame($admin->id, $ajuste->confirmado_por);
        $this->assertNotNull($ajuste->confirmado_en);

        // Ahora sí bajó el saldo, y el mayor tiene su movimiento con el motivo.
        $this->assertSame('485.0000', $this->saldo($bucket));

        $movimiento = PlantaMovimiento::query()
            ->where('documento_type', PlantaAjuste::class)
            ->where('documento_id', $ajuste->id)
            ->firstOrFail();

        $this->assertSame('-15.0000', (string) $movimiento->cantidad);
        $this->assertSame(TipoMovimientoPlanta::Ajuste, $movimiento->tipo);
        $this->assertSame($admin->id, $movimiento->user_id);
    }

    // --- Propiedad del borrador ---
    //
    // `planta.ajustes.crear` autoriza a preparar ajustes, NO a manipular los de
    // los demás. Quien puede confirmar sí gestiona cualquiera: si puede aplicar
    // el ajuste al inventario, puede con más razón corregir o descartar un
    // borrador ajeno.

    /** Borrador ya guardado por HTTP, atribuido al usuario indicado. */
    private function borradorDe(User $usuario, array $e, TipoAjuste $tipo = TipoAjuste::Merma, string $cantidad = '10'): PlantaAjuste
    {
        $this->actingAs($usuario)
            ->post(route('planta.ajustes.store'), $this->payloadAjuste(
                $e,
                $tipo,
                $cantidad,
                extraCabecera: ['motivo' => 'Producto dañado durante la descarga'],
            ))
            ->assertRedirect();

        $ajuste = PlantaAjuste::latest('id')->firstOrFail();

        $this->assertSame($usuario->id, $ajuste->creado_por);

        return $ajuste;
    }

    public function test_produccion_gestiona_su_propio_borrador(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $operario = $this->usuarioConRol('produccion');

        $suyo = $this->borradorDe($operario, $e);

        // Abrir el formulario.
        $this->actingAs($operario)->get(route('planta.ajustes.edit', $suyo))->assertOk();

        // Actualizarlo.
        $this->actingAs($operario)
            ->put(route('planta.ajustes.update', $suyo), $this->payloadAjuste(
                $e,
                TipoAjuste::Merma,
                '18',
                extraCabecera: ['motivo' => 'Al recontar eran tres sacos'],
            ))
            ->assertRedirect();

        $this->assertSame('18.0000', $suyo->refresh()->detalles->first()->cantidad);

        // Y anularlo.
        $this->actingAs($operario)->patch(route('planta.ajustes.anular', $suyo))->assertRedirect();

        $this->assertSame(EstadoAjustePlanta::Anulado, $suyo->refresh()->estado);
    }

    public function test_produccion_no_toca_el_borrador_de_otro_operario(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $operarioA = $this->usuarioConRol('produccion');
        $operarioB = $this->usuarioConRol('produccion');

        $deA = $this->borradorDe($operarioA, $e);
        $mayorAntes = $this->huellaMayor();

        $this->actingAs($operarioB)->get(route('planta.ajustes.edit', $deA))->assertForbidden();

        $this->actingAs($operarioB)
            ->put(route('planta.ajustes.update', $deA), $this->payloadAjuste(
                $e,
                TipoAjuste::Merma,
                '999',
                extraCabecera: ['motivo' => 'no deberia poder cambiar el motivo de otro'],
            ))
            ->assertForbidden();

        $this->actingAs($operarioB)->patch(route('planta.ajustes.anular', $deA))->assertForbidden();

        // El borrador de A quedó exactamente como estaba.
        $deA->refresh();
        $this->assertSame(EstadoAjustePlanta::Borrador, $deA->estado);
        $this->assertSame('10.0000', $deA->detalles->first()->cantidad);
        $this->assertSame($operarioA->id, $deA->creado_por);
        $this->assertSame($mayorAntes, $this->huellaMayor());
    }

    public function test_produccion_no_toca_el_borrador_de_administracion(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $admin = $this->admin();
        $operario = $this->usuarioConRol('produccion');

        $delAdmin = $this->borradorDe($admin, $e);

        $this->actingAs($operario)->get(route('planta.ajustes.edit', $delAdmin))->assertForbidden();
        $this->actingAs($operario)
            ->put(route('planta.ajustes.update', $delAdmin), $this->payloadAjuste($e, TipoAjuste::Merma, '999'))
            ->assertForbidden();
        $this->actingAs($operario)->patch(route('planta.ajustes.anular', $delAdmin))->assertForbidden();

        $this->assertSame(EstadoAjustePlanta::Borrador, $delAdmin->refresh()->estado);
    }

    /** Ver sí puede: el candado es sobre la escritura, no sobre la consulta. */
    public function test_produccion_sigue_viendo_los_borradores_ajenos(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $operarioA = $this->usuarioConRol('produccion');
        $operarioB = $this->usuarioConRol('produccion');

        $deA = $this->borradorDe($operarioA, $e);

        $this->actingAs($operarioB)->get(route('planta.ajustes.index'))->assertOk();
        $this->actingAs($operarioB)->get(route('planta.ajustes.show', $deA))->assertOk();
    }

    public function test_administracion_gestiona_el_borrador_que_preparo_produccion(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $operario = $this->usuarioConRol('produccion');
        $admin = $this->admin();

        $delOperario = $this->borradorDe($operario, $e);

        $this->actingAs($admin)->get(route('planta.ajustes.edit', $delOperario))->assertOk();

        $this->actingAs($admin)
            ->put(route('planta.ajustes.update', $delOperario), $this->payloadAjuste(
                $e,
                TipoAjuste::Merma,
                '12',
                extraCabecera: ['motivo' => 'Se corrige la cantidad tras revisar en bodega'],
            ))
            ->assertRedirect();

        $this->assertSame('12.0000', $delOperario->refresh()->detalles->first()->cantidad);

        $this->actingAs($admin)->patch(route('planta.ajustes.anular', $delOperario))->assertRedirect();

        $this->assertSame(EstadoAjustePlanta::Anulado, $delOperario->refresh()->estado);
    }

    /**
     * Gestionar borradores —propios o ajenos— nunca toca el inventario. Es la
     * separación que hace que este candado sea de acceso y no de integridad.
     */
    public function test_gestionar_borradores_no_mueve_inventario(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $bucket = $this->bucketDeAjuste($e);
        $operarioA = $this->usuarioConRol('produccion');
        $operarioB = $this->usuarioConRol('produccion');
        $admin = $this->admin();

        $deA = $this->borradorDe($operarioA, $e);

        $mayorAntes = $this->huellaMayor();
        $existenciasAntes = $this->huellaExistencias();

        // Lo permitido, lo rechazado y lo administrativo: nada de esto mueve nada.
        $this->actingAs($operarioA)->get(route('planta.ajustes.edit', $deA))->assertOk();
        $this->actingAs($operarioB)->patch(route('planta.ajustes.anular', $deA))->assertForbidden();
        $this->actingAs($admin)->get(route('planta.ajustes.edit', $deA))->assertOk();
        $this->actingAs($admin)->patch(route('planta.ajustes.anular', $deA))->assertRedirect();

        $this->assertSame($mayorAntes, $this->huellaMayor());
        $this->assertSame($existenciasAntes, $this->huellaExistencias());
        $this->assertSame('500.0000', $this->saldo($bucket));
    }

    /**
     * El candado de acceso y el de estado son cosas distintas: sobre su PROPIO
     * ajuste ya confirmado, producción no recibe un 403 sino el mensaje de que
     * eso ya no es un borrador.
     */
    public function test_un_ajuste_confirmado_no_se_edita_ni_se_anula_como_borrador(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $operario = $this->usuarioConRol('produccion');
        $admin = $this->admin();

        $ajuste = $this->borradorDe($operario, $e);

        $this->actingAs($admin)->patch(route('planta.ajustes.confirmar', $ajuste))->assertRedirect();
        $this->assertSame(EstadoAjustePlanta::Confirmado, $ajuste->refresh()->estado);

        // Su autor: pasa el candado de acceso y lo frena el de estado.
        $this->actingAs($operario)
            ->get(route('planta.ajustes.edit', $ajuste))
            ->assertRedirect(route('planta.ajustes.show', $ajuste));

        $this->actingAs($operario)
            ->patch(route('planta.ajustes.anular', $ajuste))
            ->assertSessionHasErrors('ajuste');

        // Y el administrador tampoco lo edita: el estado manda para todos.
        $this->actingAs($admin)
            ->get(route('planta.ajustes.edit', $ajuste))
            ->assertRedirect(route('planta.ajustes.show', $ajuste));

        $this->assertSame(EstadoAjustePlanta::Confirmado, $ajuste->refresh()->estado);
    }

    public function test_con_el_modulo_apagado_la_gestion_del_borrador_responde_404(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $operario = $this->usuarioConRol('produccion');
        $suyo = $this->borradorDe($operario, $e);

        config()->set('planta.enabled', false);

        // El interruptor va ANTES que cualquier candado de propiedad.
        $this->actingAs($operario)->get(route('planta.ajustes.edit', $suyo))->assertNotFound();
        $this->actingAs($operario)->patch(route('planta.ajustes.anular', $suyo))->assertNotFound();

        $this->assertSame(EstadoAjustePlanta::Borrador, $suyo->refresh()->estado);
    }

    /** Huella de la proyección: demuestra que un borrador no la toca. */
    private function huellaExistencias(): array
    {
        $fila = DB::table('planta_existencias')
            ->selectRaw(
                'COUNT(*) as filas, '
                .'COALESCE(SUM(CAST(ROUND(cantidad * 10000) AS INTEGER)), 0) as suma, '
                .'COALESCE(MAX(id), 0) as max_id'
            )
            ->first();

        return [
            'filas' => (int) $fila->filas,
            'suma' => bcdiv((string) (int) $fila->suma, '10000', 4),
            'max_id' => (int) $fila->max_id,
        ];
    }

    // --- Ciclo repartido entre personas ---

    public function test_quien_solo_prepara_ajustes_no_los_confirma_ni_los_reversa(): void
    {
        // El reparto en su forma más pura, con permisos concedidos a mano y sin
        // rol de por medio: quien prepara no confirma. El rol `produccion` ya
        // tiene exactamente este set, pero la prueba lo comprueba también así
        // para que un cambio futuro en el reparto de roles no pueda ocultar que
        // `confirmar` dejó de exigir su propio permiso.
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $bucket = $this->bucketDeAjuste($e);
        $borrador = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');
        $confirmado = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '20');

        $preparador = User::factory()->create()->givePermissionTo([
            'planta.ver',
            'planta.ajustes.ver',
            'planta.ajustes.crear',
        ]);
        $antes = $this->huellaMayor();

        // Lo suyo sí puede.
        $this->actingAs($preparador)->get(route('planta.ajustes.index'))->assertOk();
        $this->actingAs($preparador)->get(route('planta.ajustes.create'))->assertOk();

        // Lo que mueve inventario, no.
        $this->actingAs($preparador)
            ->patch(route('planta.ajustes.confirmar', $borrador))
            ->assertForbidden();

        $this->actingAs($preparador)
            ->patch(route('planta.ajustes.reversar', $confirmado), ['motivo' => 'no deberia poder hacerlo'])
            ->assertForbidden();

        $this->assertSame(EstadoAjustePlanta::Borrador, $borrador->refresh()->estado);
        $this->assertSame(EstadoAjustePlanta::Confirmado, $confirmado->refresh()->estado);
        $this->assertSame($antes, $this->huellaMayor());
        $this->assertSame('520.0000', $this->saldo($bucket));
    }

    // --- Administrador ---

    public function test_el_administrador_recorre_todas_las_pantallas(): void
    {
        $this->encenderModulo();
        $ajuste = $this->borradorAjuste($this->escenarioConSaldo(), TipoAjuste::Positivo, '10');
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('planta.ajustes.index'))->assertOk();
        $this->actingAs($admin)->get(route('planta.ajustes.create'))->assertOk();
        $this->actingAs($admin)->get(route('planta.ajustes.show', $ajuste))->assertOk();
        $this->actingAs($admin)->get(route('planta.ajustes.edit', $ajuste))->assertOk();
    }

    public function test_el_administrador_completa_el_ciclo_por_http(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $bucket = $this->bucketDeAjuste($e);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('planta.ajustes.store'), $this->payloadAjuste($e, TipoAjuste::Merma, '60'))
            ->assertRedirect();

        $ajuste = PlantaAjuste::sole();

        $this->actingAs($admin)
            ->patch(route('planta.ajustes.confirmar', $ajuste))
            ->assertRedirect();
        $this->assertSame('440.0000', $this->saldo($bucket));

        $this->actingAs($admin)
            ->patch(route('planta.ajustes.reversar', $ajuste), ['motivo' => 'la merma era de otro lote'])
            ->assertRedirect();
        $this->assertSame('500.0000', $this->saldo($bucket));
    }

    public function test_el_administrador_no_edita_lo_ya_confirmado_aunque_fuerce_la_url(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '10');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('planta.ajustes.edit', $ajuste))
            ->assertRedirect(route('planta.ajustes.show', $ajuste));

        $this->actingAs($admin)
            ->put(route('planta.ajustes.update', $ajuste), $this->payloadAjuste($e, TipoAjuste::Positivo, '999'))
            ->assertSessionHasErrors('ajuste');

        $this->assertSame('10.0000', $ajuste->refresh()->detalles->first()->cantidad);
    }

    // --- Validación de forma ---

    public function test_el_motivo_corto_se_rechaza_al_crear(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();

        $this->actingAs($this->admin())
            ->post(route('planta.ajustes.store'), $this->payloadAjuste(
                $e, TipoAjuste::Positivo, '10', extraCabecera: ['motivo' => 'error']
            ))
            ->assertSessionHasErrors('motivo');

        $this->assertSame(0, PlantaAjuste::count());
    }

    public function test_una_cantidad_de_cero_se_rechaza_en_los_tipos_de_signo_fijo(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();

        $this->actingAs($this->admin())
            ->post(route('planta.ajustes.store'), $this->payloadAjuste($e, TipoAjuste::Positivo, '0'))
            ->assertSessionHasErrors('detalles.0.cantidad');

        $this->assertSame(0, PlantaAjuste::count());
    }

    public function test_una_cantidad_negativa_se_rechaza_porque_el_signo_lo_pone_el_tipo(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();

        $this->actingAs($this->admin())
            ->post(route('planta.ajustes.store'), $this->payloadAjuste($e, TipoAjuste::Negativo, '-30'))
            ->assertSessionHasErrors('detalles.0.cantidad');

        $this->assertSame(0, PlantaAjuste::count());
    }

    public function test_una_correccion_sin_cantidad_contada_se_rechaza(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();

        $this->actingAs($this->admin())
            ->post(route('planta.ajustes.store'), $this->payloadAjuste($e, TipoAjuste::CorreccionConteo, '0'))
            ->assertSessionHasErrors('detalles.0.cantidad_conteo');

        $this->assertSame(0, PlantaAjuste::count());
    }

    public function test_la_cantidad_del_sistema_enviada_por_el_formulario_se_ignora(): void
    {
        // No es un error de forma —no se valida— sino un dato que no se acepta:
        // el servidor lo recalcula con el saldo bloqueado al confirmar.
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();

        $this->actingAs($this->admin())
            ->post(route('planta.ajustes.store'), $this->payloadAjuste(
                $e, TipoAjuste::CorreccionConteo, '0', extraLinea: [
                    'cantidad_conteo' => '480',
                    'cantidad_sistema' => '1',
                    'diferencia' => '479',
                ]
            ))
            ->assertRedirect();

        $detalle = PlantaAjuste::sole()->detalles->first();

        $this->assertNull($detalle->cantidad_sistema);
        $this->assertNull($detalle->diferencia);
    }

    public function test_el_motivo_de_la_reversion_se_exige_por_http(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $ajuste = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '10');

        $this->actingAs($this->admin())
            ->patch(route('planta.ajustes.reversar', $ajuste), ['motivo' => 'corto'])
            ->assertSessionHasErrors('motivo');

        $this->assertSame(EstadoAjustePlanta::Confirmado, $ajuste->refresh()->estado);
    }

    public function test_una_regla_de_dominio_rota_vuelve_con_mensaje_y_no_con_error_500(): void
    {
        // Falta de saldo, bucket con historial, lote sin vencer: son situaciones
        // esperables, y el usuario debe poder leerlas.
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Merma, '9999');

        $this->actingAs($this->admin())
            ->patch(route('planta.ajustes.confirmar', $ajuste))
            ->assertRedirect()
            ->assertSessionHasErrors('ajuste');

        $this->assertSame(EstadoAjustePlanta::Borrador, $ajuste->refresh()->estado);
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
            ->get(route('planta.ajustes.index'))
            ->assertForbidden();
    }

    #[DataProvider('rolesAjenos')]
    public function test_los_roles_ajenos_tampoco_confirman(string $rol): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');
        $antes = $this->huellaMayor();

        $this->actingAs($this->usuarioConRol($rol))
            ->patch(route('planta.ajustes.confirmar', $ajuste))
            ->assertForbidden();

        $this->assertSame($antes, $this->huellaMayor());
    }

    // --- Superficie ---

    /**
     * Permisos que debe exigir cada ruta, incluidos los de los grupos que la
     * envuelven. Se declara el conjunto COMPLETO y se compara exacto: así una
     * ruta no puede quedarse ni corta —exigiendo menos de lo debido— ni pedir de
     * más por copiar el middleware de su vecina.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function permisosPorRuta(): array
    {
        $area = ['permission:planta.ver', 'permission:planta.ajustes.ver'];

        return [
            'index' => ['planta.ajustes.index', $area],
            'show' => ['planta.ajustes.show', $area],
            'create' => ['planta.ajustes.create', [...$area, 'permission:planta.ajustes.crear']],
            'store' => ['planta.ajustes.store', [...$area, 'permission:planta.ajustes.crear']],
            'edit' => ['planta.ajustes.edit', [...$area, 'permission:planta.ajustes.crear']],
            'update' => ['planta.ajustes.update', [...$area, 'permission:planta.ajustes.crear']],
            'anular' => ['planta.ajustes.anular', [...$area, 'permission:planta.ajustes.crear']],
            'confirmar' => ['planta.ajustes.confirmar', [...$area, 'permission:planta.ajustes.confirmar']],
            'reversar' => ['planta.ajustes.reversar', [...$area, 'permission:planta.ajustes.reversar']],
        ];
    }

    /**
     * Un permiso que existe en PermisoSistema pero que ninguna ruta exige
     * equivale a no tenerlo: la matriz de roles diría una cosa y el backend haría
     * otra. Esta prueba ata las dos.
     *
     * @param  list<string>  $esperados
     */
    #[DataProvider('permisosPorRuta')]
    public function test_cada_ruta_exige_exactamente_sus_permisos(string $ruta, array $esperados): void
    {
        $middleware = app('router')->getRoutes()->getByName($ruta)->gatherMiddleware();

        $permisos = array_values(array_filter(
            $middleware,
            fn ($capa) => is_string($capa) && str_starts_with($capa, 'permission:')
        ));

        $this->assertEqualsCanonicalizing($esperados, $permisos);
    }

    public function test_no_hay_ruta_de_borrado(): void
    {
        $metodos = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains((string) $r->getName(), 'planta.ajustes.'))
            ->flatMap(fn ($r) => $r->methods())
            ->unique()
            ->values()
            ->all();

        // Un ajuste confirmado ya escribió en el mayor: se anula o se reversa.
        $this->assertNotContains('DELETE', $metodos);
    }
}
