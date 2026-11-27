<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Regla;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * #16 · Un dato manipulado —una lista donde va un texto, un texto donde va una lista— es un
 * aviso de validación, nunca un error del sistema. Cada caso daba 500.
 *
 * Los filtros de pantalla con un valor que no se entiende: el de ámbito se ignora, como en
 * el historial de pagos; los demás avisan, como en el listado.
 */
class EntradasManipuladasTest extends TestCase
{
    use RefreshDatabase;

    private User $u;

    private Gasto $gasto;

    private Regla $regla;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['gastos.enabled' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));

        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions(PermisoSistema::todos());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->u = $u->fresh();

        $this->gasto = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('0', 64),
            'beneficiario' => 'Proveedor A', 'concepto' => 'Concepto de prueba', 'categoria' => 'Servicios',
            'ambito' => 'empresarial', 'naturaleza' => 'operativo', 'moneda' => 'USD', 'importe' => '100.00',
            'documentacion' => 'pendiente', 'responsable_id' => $this->u->id, 'registrado_por' => $this->u->id,
        ]);
        $this->gasto->cuotas()->create(['numero' => 1, 'importe' => '100.00', 'vence' => '2026-10-10']);

        $this->actingAs($this->u)->post(route('gastos.reglas.store'), $this->regla())->assertSessionHasNoErrors();
        $this->regla = Regla::firstOrFail();
    }

    /** @return array<string, mixed> */
    private function regla(array $extra = []): array
    {
        return array_replace([
            'clave' => (string) Str::uuid(), 'nombre' => 'Alquiler de prueba', 'beneficiario' => 'Proveedor B',
            'concepto' => 'Alquiler', 'categoria' => 'Alquiler', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'documentacion' => 'pendiente', 'responsable_id' => $this->u->id, 'monto_modo' => 'fijo',
            'importe' => '400.00', 'frecuencia' => 'mensual', 'dia_mes' => 5, 'dias_generar_antes' => 0,
            'vigente_desde' => '2026-10-01', 'vigente_hasta' => '2027-12-31',
        ], $extra);
    }

    /** @return array<string, mixed> */
    private function gastoNuevo(array $extra = []): array
    {
        return array_replace([
            'clave' => (string) Str::uuid(), 'beneficiario' => 'Proveedor A', 'concepto' => 'Recibo de luz',
            'categoria' => 'Servicios', 'ambito' => 'empresarial', 'naturaleza' => 'operativo', 'moneda' => 'USD',
            'monto_pendiente' => '0', 'importe' => '50.00', 'cuotas' => [['importe' => '50.00', 'vence' => '2026-11-01']],
            'periodo_desde' => '2026-10-01', 'periodo_hasta' => '2026-10-31',
            'responsable_id' => $this->u->id, 'documentacion' => 'pendiente', 'ya_pagado' => '0',
        ], $extra);
    }

    public function test_un_formulario_con_una_lista_donde_va_un_dato_avisa_en_ese_campo(): void
    {
        $lista = ['x', ['y']];
        $enviar = fn (string $metodo, string $url, array $datos, string $campo) => $this->actingAs($this->u)
            ->call($metodo, $url, $datos)->assertRedirect()->assertSessionHasErrors($campo);

        $enviar('POST', route('gastos.store'), $this->gastoNuevo(['responsable_id' => $lista]), 'responsable_id');
        $enviar('POST', route('gastos.store'), $this->gastoNuevo(['periodo_desde' => $lista]), 'periodo_desde');

        $enviar('POST', route('gastos.pagos.store'), [
            'clave' => (string) Str::uuid(), 'importe' => '10.00', 'fecha' => '2026-10-20', 'metodo' => 'efectivo',
            'pagado_por' => $lista, 'sin_comprobante' => 'sin recibo',
            'aplicar' => [$this->gasto->cuotas()->first()->id => '10.00'],
        ], 'pagado_por');

        $enviar('POST', route('gastos.reglas.store'), $this->regla(['vigente_desde' => $lista]), 'vigente_desde');
        $enviar('PUT', route('gastos.reglas.update', $this->regla), $this->regla(['vigente_desde' => $lista, 'motivo' => 'Cambio de prueba']), 'vigente_desde');

        // Un texto donde va la lista de la repetición.
        $enviar('POST', route('gastos.repetir', $this->gasto), ['repeticion' => 'texto'], 'frecuencia');

        $this->assertSame(1, Gasto::count(), 'Nada se guardó.');
        $this->assertSame(1, Regla::count());
    }

    public function test_un_filtro_con_una_lista_no_rompe_la_pantalla(): void
    {
        $ver = fn (string $ruta, array $consulta) => $this->actingAs($this->u)->get(route($ruta).'?'.http_build_query($consulta));
        $lista = ['x', ['y']];

        // El ámbito que no se entiende se ignora, como en el historial de pagos.
        $ver('gastos.panel', ['ambito' => $lista])->assertOk();

        $ver('gastos.informes', ['desde' => $lista, 'hasta' => '2026-10-20'])->assertRedirect()->assertSessionHasErrors('desde');
        $ver('gastos.informes.exportar', ['informe' => 'pagos', 'desde' => $lista, 'hasta' => '2026-10-20'])
            ->assertRedirect()->assertSessionHasErrors('desde');

        foreach (['q', 'desde', 'hasta', 'metodo', 'moneda'] as $campo) {
            $ver('gastos.pagos.index', [$campo => $lista])->assertRedirect()->assertSessionHasErrors($campo);
        }

        foreach (['proveedor', 'moneda'] as $campo) {
            $ver('gastos.cuentas.show', array_replace(['proveedor' => 'Proveedor A', 'moneda' => 'USD'], [$campo => $lista]))
                ->assertRedirect()->assertSessionHasErrors($campo);
        }

        $ver('gastos.reglas.index', ['estado' => $lista])->assertRedirect()->assertSessionHasErrors('estado');
    }

    /** Lo que sí se entiende sigue funcionando igual. */
    public function test_los_filtros_bien_escritos_siguen_abriendo(): void
    {
        $ver = fn (string $ruta, array $consulta) => $this->actingAs($this->u)->get(route($ruta).'?'.http_build_query($consulta));

        $ver('gastos.panel', ['ambito' => 'empresarial'])->assertOk();
        $ver('gastos.informes', ['desde' => '2026-10-01', 'hasta' => '2026-10-20'])->assertOk();
        $ver('gastos.pagos.index', ['q' => 'Proveedor', 'desde' => '2026-10-01', 'hasta' => '2026-10-20', 'metodo' => 'efectivo', 'moneda' => 'USD'])->assertOk();
        $ver('gastos.pagos.index', ['q' => '', 'desde' => '', 'hasta' => '', 'metodo' => '', 'moneda' => ''])->assertOk();
        $ver('gastos.cuentas.show', ['proveedor' => 'Proveedor A', 'moneda' => 'USD'])->assertOk();
        $ver('gastos.cuentas.show', ['proveedor' => ''])->assertNotFound();
        $ver('gastos.reglas.index', ['estado' => 'activa'])->assertOk()->assertSee('Alquiler de prueba');
    }
}
