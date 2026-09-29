<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\DocumentoRecibido;
use App\Models\Gastos\Cuota;
use App\Models\Gastos\Fuente;
use App\Models\Gastos\Gasto;
use App\Models\User;
use App\Services\Gastos\VincularCompra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Un documento de Compras origina UNA sola deuda, por los dos caminos que llevan a eso.
 *
 * ═══════ De dónde sale este archivo ═══════
 *
 * El módulo siempre tuvo el candado: un índice único sobre `gastos_fuentes.deuda_unica`
 * que impide que el mismo papel genere dos obligaciones. Lo que faltaba era ENCHUFARLO.
 * Ninguna pantalla creaba el vínculo `deuda`: el formulario se prellenaba desde el
 * documento y después se olvidaba de dónde venía, así que la columna que el índice
 * vigila quedaba siempre en NULL y el candado no llegaba a proteger nada. En la base de
 * desarrollo, `gastos_fuentes` tenía cero filas.
 *
 * El síntoma era registrar el mismo recibo dos veces y quedar debiendo el doble, sin que
 * ninguna pantalla avisara. Un candado que nadie cierra es exactamente igual a no tenerlo,
 * y por eso estas pruebas repiten la operación en vez de comprobar que el índice existe.
 */
class DocumentoNoDuplicaDeudaTest extends TestCase
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

    private function operador(): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions([
            PermisoSistema::GastosVer->value,
            PermisoSistema::GastosRegistrar->value,
            PermisoSistema::GastosPagosRegistrar->value,
        ]);
        $u->givePermissionTo('documentos-recibidos.ver');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function documento(): DocumentoRecibido
    {
        return DocumentoRecibido::create([
            'identidad' => (string) Str::uuid(), 'origen_email' => 'proveedor@ejemplo.test',
            'asunto' => 'CCF', 'remitente' => 'Proveedor A', 'fecha_correo' => now(),
            'fecha_dte' => '2026-09-01', 'tipo_documento' => '03', 'numero_control' => 'DTE-03-0001',
            'codigo_generacion' => strtoupper((string) Str::uuid()), 'emisor_nombre' => 'Proveedor A',
            'total' => 115.27, 'estado' => 'pendiente', 'clasificacion' => 'dte_valido',
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function datos(User $u, array $extra = []): array
    {
        return array_replace([
            'clave' => (string) Str::uuid(),
            'concepto' => 'Luz de la fábrica',
            'beneficiario' => 'Proveedor A',
            'categoria' => 'Servicios',
            'ambito' => 'empresarial',
            'naturaleza' => 'compra',
            'moneda' => 'USD',
            'monto_pendiente' => 0,
            'importe' => '115.27',
            'responsable_id' => $u->id,
            'documentacion' => 'pendiente',
            'ya_pagado' => 0,
            'cuotas' => [['importe' => '115.27', 'vence' => '2026-09-30', 'aplicar' => '']],
        ], $extra);
    }

    // ═══════════ La fecha de emisión NO es la fecha de pago ═══════════

    /**
     * El prellenado proponía `fecha_dte` como vencimiento. Son dos fechas distintas y
     * en un recibo de servicio casi nunca coinciden: DELSUR emite el 3 y da hasta el
     * 20 y pico para pagar. Proponer la de emisión no es una aproximación cómoda: es
     * una fecha límite falsa y MÁS TEMPRANA que la real, que dispara avisos de
     * vencimiento sobre algo que todavía no vence y ensucia la cifra de vencido.
     *
     * El modelo de Compras no guarda fecha límite, así que lo correcto es no proponer
     * ninguna y que una persona la escriba mirando el papel.
     */
    public function test_el_prellenado_no_propone_la_fecha_de_emision_como_vencimiento(): void
    {
        $documento = $this->documento();

        $pre = app(VincularCompra::class)->prellenado($documento);

        $this->assertNull($pre['vence'], 'Emitido y vence no son la misma fecha.');
        $this->assertNotSame('2026-09-01', $pre['vence'] ?? null);

        // La fecha de emisión sí viaja, con su propio nombre, como dato del documento.
        $this->assertSame('2026-09-01', $pre['fecha_documento']);

        // Lo que el documento sí sabe se sigue proponiendo.
        $this->assertSame('115.27', $pre['importe']);
        $this->assertSame('Proveedor A', $pre['beneficiario']);
    }

    public function test_el_alta_desde_un_documento_llega_sin_vencimiento_puesto(): void
    {
        $u = $this->operador();
        $documento = $this->documento();

        $this->actingAs($u)->get(route('gastos.create', ['documento' => $documento->id]))
            ->assertOk()
            // Se dice de dónde sale la fecha que se ve, y que no es un vencimiento.
            ->assertSee('esa no es su fecha de pago', false);
    }

    // ═══════════ Camino 1: crear el gasto desde el documento ═══════════

    public function test_crear_desde_un_documento_deja_el_vinculo_de_deuda(): void
    {
        $u = $this->operador();
        $documento = $this->documento();

        $this->actingAs($u)
            ->post(route('gastos.store'), $this->datos($u, ['documento' => $documento->id]))
            ->assertRedirect();

        $this->assertSame(1, Gasto::count());

        $fuente = Fuente::sole();
        $this->assertSame('deuda', $fuente->papel);
        // La columna que el índice vigila queda OCUPADA. Era justo lo que faltaba.
        $this->assertSame($documento->id, (int) $fuente->deuda_unica);
        $this->assertSame('DTE-03-0001', $fuente->snapshot['numero_control']);
    }

    public function test_repetir_el_alta_con_el_mismo_documento_no_crea_otra_deuda(): void
    {
        $u = $this->operador();
        $documento = $this->documento();

        $this->actingAs($u)
            ->post(route('gastos.store'), $this->datos($u, ['documento' => $documento->id]))
            ->assertRedirect();

        // Segundo envío: formulario nuevo (otra clave), mismo papel. Es el caso real:
        // alguien vuelve a abrir el documento y lo registra otra vez sin acordarse.
        $this->actingAs($u)
            ->post(route('gastos.store'), $this->datos($u, ['documento' => $documento->id]))
            ->assertSessionHasErrors('documento');

        $this->assertSame(1, Gasto::count(), 'El mismo papel no puede deber dos veces.');
        $this->assertSame(1, Fuente::count());
    }

    /**
     * Lo importante no es solo que no haya DOS gastos: es que no quede UNO A MEDIAS.
     *
     * El vínculo se guarda dentro de la misma transacción que el gasto, así que cuando
     * el índice rechaza el segundo intento se deshace todo: no queda una obligación
     * creada y suelta, sin papel y sin que nadie sepa de dónde salió.
     */
    public function test_el_intento_rechazado_no_deja_un_gasto_huerfano(): void
    {
        $u = $this->operador();
        $documento = $this->documento();

        $this->actingAs($u)
            ->post(route('gastos.store'), $this->datos($u, ['documento' => $documento->id]))
            ->assertRedirect();

        $primero = Gasto::sole();

        $this->actingAs($u)->post(route('gastos.store'), $this->datos($u, [
            'documento' => $documento->id,
            'concepto' => 'Otro concepto para que no sea el mismo envío',
        ]))->assertSessionHasErrors('documento');

        $this->assertSame(1, Gasto::count());
        $this->assertSame($primero->id, Gasto::sole()->id);
        $this->assertSame(0, Cuota::where('gasto_id', '!=', $primero->id)->count());
    }

    // ═══════════ Camino 2: completar una obligación que esperaba el recibo ═══════════

    public function test_completar_el_monto_desde_un_documento_deja_el_vinculo_de_deuda(): void
    {
        $u = $this->operador();
        $documento = $this->documento();

        // Una obligación de servicio variable: existe, pero todavía no debe nada.
        $gasto = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('0', 64),
            'beneficiario' => 'Proveedor A', 'concepto' => 'Luz de la fábrica',
            'categoria' => 'Servicios', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'importe' => null, 'documentacion' => 'pendiente',
            'responsable_id' => $u->id, 'registrado_por' => $u->id,
        ]);

        $this->actingAs($u)->post(route('gastos.completar', $gasto), [
            'cuotas' => [['importe' => '115.27', 'vence' => '2026-09-30']],
            'documento' => $documento->id,
        ])->assertRedirect(route('gastos.show', $gasto));

        $this->assertSame('115.27', $gasto->fresh()->importe);

        $fuente = Fuente::sole();
        $this->assertSame('deuda', $fuente->papel);
        $this->assertSame($documento->id, (int) $fuente->deuda_unica);
    }

    public function test_el_recibo_que_ya_completo_una_obligacion_no_puede_originar_otra(): void
    {
        $u = $this->operador();
        $documento = $this->documento();

        $gasto = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('0', 64),
            'beneficiario' => 'Proveedor A', 'concepto' => 'Luz de la fábrica',
            'categoria' => 'Servicios', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'importe' => null, 'documentacion' => 'pendiente',
            'responsable_id' => $u->id, 'registrado_por' => $u->id,
        ]);

        $this->actingAs($u)->post(route('gastos.completar', $gasto), [
            'cuotas' => [['importe' => '115.27', 'vence' => '2026-09-30']],
            'documento' => $documento->id,
        ])->assertRedirect();

        // El mismo recibo, ahora por el otro camino. No puede volver a deber.
        $this->actingAs($u)
            ->post(route('gastos.store'), $this->datos($u, ['documento' => $documento->id]))
            ->assertSessionHasErrors('documento');

        $this->assertSame(1, Gasto::count());
        $this->assertSame(1, Fuente::count());
    }

    // ═══════════ Lo que el candado NO debe impedir ═══════════

    public function test_adjuntar_como_respaldo_sigue_libre(): void
    {
        $u = $this->operador();
        $documento = $this->documento();

        $this->actingAs($u)
            ->post(route('gastos.store'), $this->datos($u, ['documento' => $documento->id]))
            ->assertRedirect();

        $otro = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('1', 64),
            'beneficiario' => 'Proveedor A', 'concepto' => 'Otra cosa',
            'categoria' => 'Servicios', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'importe' => '10.00', 'documentacion' => 'pendiente',
            'responsable_id' => $u->id, 'registrado_por' => $u->id,
        ]);

        // El mismo papel puede acompañar a otra obligación como respaldo: eso no
        // inventa deuda, solo pone el documento donde ayuda a entender.
        $this->actingAs($u)->post(route('gastos.compras.vincular', $documento), [
            'gasto_id' => $otro->id, 'papel' => 'respaldo',
        ])->assertRedirect(route('gastos.show', $otro));

        $this->assertSame(2, Fuente::count());
        $this->assertSame(1, Fuente::whereNotNull('deuda_unica')->count());
    }

    public function test_un_gasto_sin_documento_no_crea_ningun_vinculo(): void
    {
        $u = $this->operador();

        $this->actingAs($u)->post(route('gastos.store'), $this->datos($u))->assertRedirect();

        $this->assertSame(1, Gasto::count());
        $this->assertSame(0, Fuente::count(), 'Compras sigue siendo una fuente opcional.');
    }
}
