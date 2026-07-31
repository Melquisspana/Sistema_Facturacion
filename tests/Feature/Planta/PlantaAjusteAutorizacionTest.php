<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoAjustePlanta;
use App\Enums\Planta\TipoAjuste;
use App\Models\Planta\PlantaAjuste;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Candados de acceso de los ajustes.
 *
 * El reparto es ASIMÉTRICO a propósito y es el más restrictivo del módulo:
 * producción VE lo que se ajustó en su bodega —necesita saber por qué su saldo
 * cambió— pero no escribe nada. Alterar la cantidad física sin contrapartida
 * documental es la operación con menos control externo que existe aquí, y por eso
 * queda en administración.
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

    // --- Rol producción: ve y nada más ---

    public function test_produccion_solo_tiene_el_permiso_de_lectura(): void
    {
        $produccion = $this->usuarioConRol('produccion');

        $this->assertTrue($produccion->can('planta.ajustes.ver'));
        $this->assertFalse($produccion->can('planta.ajustes.crear'));
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

    public function test_produccion_no_entra_al_formulario(): void
    {
        $this->encenderModulo();

        $this->actingAs($this->usuarioConRol('produccion'))
            ->get(route('planta.ajustes.create'))
            ->assertForbidden();
    }

    public function test_produccion_no_crea_ni_edita(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $ajuste = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');
        $produccion = $this->usuarioConRol('produccion');

        $this->actingAs($produccion)
            ->post(route('planta.ajustes.store'), $this->payloadAjuste($e, TipoAjuste::Positivo, '99'))
            ->assertForbidden();

        $this->actingAs($produccion)
            ->put(route('planta.ajustes.update', $ajuste), $this->payloadAjuste($e, TipoAjuste::Positivo, '99'))
            ->assertForbidden();

        $this->assertSame(1, PlantaAjuste::count());
        $this->assertSame('10.0000', $ajuste->refresh()->detalles->first()->cantidad);
    }

    public function test_produccion_no_confirma_ni_anula_ni_reversa(): void
    {
        $this->encenderModulo();
        $e = $this->escenarioConSaldo();
        $bucket = $this->bucketDeAjuste($e);
        $borrador = $this->borradorAjuste($e, TipoAjuste::Positivo, '10');
        $confirmado = $this->ajusteConfirmado($e, TipoAjuste::Positivo, '20');
        $produccion = $this->usuarioConRol('produccion');

        $this->actingAs($produccion)
            ->patch(route('planta.ajustes.confirmar', $borrador))
            ->assertForbidden();

        $this->actingAs($produccion)
            ->patch(route('planta.ajustes.anular', $borrador))
            ->assertForbidden();

        $this->actingAs($produccion)
            ->patch(route('planta.ajustes.reversar', $confirmado), ['motivo' => 'no deberia poder hacerlo'])
            ->assertForbidden();

        $this->assertSame(EstadoAjustePlanta::Borrador, $borrador->refresh()->estado);
        $this->assertSame(EstadoAjustePlanta::Confirmado, $confirmado->refresh()->estado);
        // El saldo es el de la recepción más el único ajuste que sí se aplicó.
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
