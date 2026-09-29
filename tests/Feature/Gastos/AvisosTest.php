<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Mail\Gastos\ResumenGastosCorreo;
use App\Models\Gastos\Aviso;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\Gastos\PreferenciaAvisos;
use App\Models\Gastos\Resumen;
use App\Models\User;
use App\Services\Gastos\Avisos\ArmarAvisos;
use App\Services\Gastos\Avisos\EnviarResumenes;
use App\Services\Gastos\RegistrarPago;
use App\Support\Correo\CandadoCorreoReal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fase 2 — avisos internos y resúmenes.
 *
 * Las garantías que se defienden acá:
 *
 *  1. SOLO PENDIENTES REALES. Sin saldo no hay aviso y no hay correo. Un resumen
 *     vacío no se manda NI SE GUARDA: mandar «no hay nada que pagar» entrena a la
 *     gente a no abrir estos correos.
 *  2. NUNCA SE INVENTA UNA DEUDA. Un gasto sin monto produce «falta el monto», no
 *     «vencido». Es la diferencia entre recordar un trámite y reclamar una cifra
 *     que nadie calculó.
 *  3. NO SE REPITE. La misma obligación, para la misma persona y la misma ventana,
 *     genera un aviso y no uno por corrida.
 *  4. NO SE MODIFICA NADA HISTÓRICO. Marcar leído no toca un saldo, y una deuda que
 *     se paga deja de generar avisos NUEVOS sin borrar los viejos.
 *  5. EL PERMISO MANDA. Quien no alcanza lo personal no recibe ni un aviso ni una
 *     línea de correo de ese ámbito.
 *  6. FUERA DE PRODUCCIÓN NO SALE CORREO. Queda como «simulado», que no es lo mismo
 *     que «enviado».
 */
class AvisosTest extends TestCase
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

    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions($permisos);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function operador(): User
    {
        return $this->usuario(array_map(fn ($p) => $p->value, [
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar, PermisoSistema::GastosPersonales,
            PermisoSistema::GastosPagosRegistrar,
        ]));
    }

    private function gasto(User $u, array $extra = [], array $cuotas = [['100.00', null]]): Gasto
    {
        $gasto = Gasto::create(array_replace([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('0', 64),
            'beneficiario' => 'Proveedor A', 'concepto' => 'Concepto de prueba',
            'categoria' => 'Servicios', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'importe' => '100.00', 'documentacion' => 'pendiente',
            'responsable_id' => $u->id, 'registrado_por' => $u->id,
        ], $extra));

        foreach ($cuotas as $i => [$importe, $vence]) {
            $gasto->cuotas()->create(['numero' => $i + 1, 'importe' => $importe, 'vence' => $vence]);
        }

        return $gasto->fresh();
    }

    private function preferencias(User $u, array $extra = []): PreferenciaAvisos
    {
        return PreferenciaAvisos::updateOrCreate(
            ['usuario_id' => $u->id],
            array_replace(PreferenciaAvisos::PREDETERMINADAS, $extra),
        );
    }

    private function armar(string $hoy): array
    {
        return app(ArmarAvisos::class)->armar(CarbonImmutable::parse($hoy)->startOfDay());
    }

    private function enviar(string $hoy): array
    {
        return app(EnviarResumenes::class)->enviar(CarbonImmutable::parse($hoy)->startOfDay());
    }

    // ──────────────────────────── Solo pendientes reales ────────────────────────────

    public function test_sin_pendientes_no_se_arma_ningun_aviso(): void
    {
        $u = $this->operador();
        $this->preferencias($u);

        $resultado = $this->armar('2026-03-10');

        $this->assertSame(0, $resultado['avisos']);
        $this->assertSame(0, Aviso::count());
    }

    public function test_un_gasto_ya_pagado_no_genera_avisos(): void
    {
        $u = $this->operador();
        $this->preferencias($u);
        $gasto = $this->gasto($u, [], [['100.00', '2026-03-01']]);

        app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(),
            'importe' => '100.00', 'fecha' => '2026-03-01', 'metodo' => 'efectivo',
            'pagado_por' => $u->id, 'sin_comprobante' => 'Pago en caja, sin recibo del proveedor.',
        ], [['cuota_id' => $gasto->cuotas->first()->id, 'importe' => '100.00']]);

        $this->armar('2026-03-10');

        $this->assertSame(0, Aviso::count());
    }

    public function test_una_cuota_vencida_genera_un_aviso_de_vencido(): void
    {
        $u = $this->operador();
        $this->preferencias($u);
        $this->gasto($u, [], [['100.00', '2026-03-01']]);

        $this->armar('2026-03-10');

        $aviso = Aviso::firstOrFail();

        $this->assertSame('vencido', $aviso->tipo);
        $this->assertSame($u->id, $aviso->usuario_id);
        $this->assertStringContainsString('9 días de atraso', $aviso->detalle);
    }

    public function test_avisa_solo_con_los_dias_de_anticipacion_configurados(): void
    {
        $u = $this->operador();
        $this->preferencias($u, ['dias_anticipacion' => [3]]);

        $this->gasto($u, ['concepto' => 'Vence en 3'], [['100.00', '2026-03-13']]);
        $this->gasto($u, ['concepto' => 'Vence en 5'], [['100.00', '2026-03-15']]);

        $this->armar('2026-03-10');

        $this->assertSame(1, Aviso::count());
        $this->assertStringContainsString('Vence en 3', Aviso::firstOrFail()->titulo);
    }

    public function test_una_cuota_sin_fecha_nunca_genera_aviso_de_vencimiento(): void
    {
        $u = $this->operador();
        $this->preferencias($u);
        $this->gasto($u, [], [['100.00', null]]);

        $this->armar('2026-03-10');

        // No se puede reclamar lo que no vence.
        $this->assertSame(0, Aviso::where('tipo', 'vencido')->count());
        $this->assertSame(0, Aviso::where('tipo', 'vence')->count());
    }

    // ──────────────────── Sin monto no es deuda ────────────────────

    public function test_un_gasto_sin_monto_avisa_falta_de_monto_y_nunca_vencido(): void
    {
        $u = $this->operador();
        $this->preferencias($u);
        $this->gasto($u, ['importe' => null, 'concepto' => 'Energía eléctrica'], []);

        $this->armar('2026-03-10');

        $aviso = Aviso::firstOrFail();

        $this->assertSame('falta_monto', $aviso->tipo);
        $this->assertNull($aviso->importe);
        $this->assertStringContainsString('No se cuenta como deuda', $aviso->detalle);
        $this->assertSame(0, Aviso::where('tipo', 'vencido')->count());
    }

    // ──────────────────────────── Sin repeticiones ────────────────────────────

    public function test_armar_dos_veces_el_mismo_dia_no_duplica_el_aviso(): void
    {
        $u = $this->operador();
        $this->preferencias($u);
        $this->gasto($u, [], [['100.00', '2026-03-01']]);

        $primera = $this->armar('2026-03-10');
        $segunda = $this->armar('2026-03-10');

        $this->assertSame(1, $primera['avisos']);
        $this->assertSame(0, $segunda['avisos']);
        $this->assertSame(1, Aviso::count());
    }

    public function test_un_vencido_se_recuerda_a_la_semana_siguiente_y_no_todos_los_dias(): void
    {
        $u = $this->operador();
        $this->preferencias($u);
        $this->gasto($u, [], [['100.00', '2026-03-01']]);

        $this->armar('2026-03-10'); // semana 11
        $this->armar('2026-03-11'); // misma semana: no repite
        $this->assertSame(1, Aviso::count());

        $this->armar('2026-03-17'); // semana 12: vuelve a recordar
        $this->assertSame(2, Aviso::count());
    }

    // ──────────────────────────── Nada histórico se toca ────────────────────────────

    public function test_pagar_deja_de_generar_avisos_nuevos_pero_no_borra_los_viejos(): void
    {
        $u = $this->operador();
        $this->preferencias($u);
        $gasto = $this->gasto($u, [], [['100.00', '2026-03-01']]);

        $this->armar('2026-03-10');
        $this->assertSame(1, Aviso::count());

        app(RegistrarPago::class)->registrar($u, [
            'clave' => (string) Str::uuid(),
            'importe' => '100.00', 'fecha' => '2026-03-12', 'metodo' => 'transferencia',
            'pagado_por' => $u->id, 'sin_comprobante' => 'Transferencia sin comprobante todavía.',
        ], [['cuota_id' => $gasto->cuotas->first()->id, 'importe' => '100.00']]);

        $this->armar('2026-03-17');

        // El aviso del 10 sigue: ese día la deuda existía de verdad.
        $this->assertSame(1, Aviso::count());
    }

    public function test_marcar_leido_no_cambia_ningun_saldo(): void
    {
        $u = $this->operador();
        $this->preferencias($u);
        $gasto = $this->gasto($u, [], [['100.00', '2026-03-01']]);
        $this->armar('2026-03-10');

        $aviso = Aviso::firstOrFail();

        $this->actingAs($u)->post(route('gastos.avisos.leer', $aviso))->assertRedirect();

        $this->assertNotNull($aviso->fresh()->leido_at);
        $this->assertSame('100.00', $gasto->fresh()->cuotas->first()->importe);
        $this->assertSame(0, Pago::count());
    }

    // ──────────────────────────── Alcance ────────────────────────────

    public function test_quien_no_alcanza_lo_personal_no_recibe_avisos_personales(): void
    {
        $conAlcance = $this->operador();
        $soloEmpresa = $this->usuario([PermisoSistema::GastosVer->value]);

        $this->preferencias($conAlcance, ['ambitos' => ['empresarial', 'personal']]);
        $this->preferencias($soloEmpresa, ['ambitos' => ['empresarial', 'personal']]);

        $this->gasto($conAlcance, [
            'ambito' => 'personal', 'persona' => 'Melqui', 'concepto' => 'Colegiatura',
        ], [['100.00', '2026-03-01']]);

        $this->armar('2026-03-10');

        $this->assertSame(1, Aviso::where('usuario_id', $conAlcance->id)->count());
        $this->assertSame(0, Aviso::where('usuario_id', $soloEmpresa->id)->count());
    }

    public function test_un_usuario_desactivado_no_recibe_avisos(): void
    {
        $u = $this->operador();
        $this->preferencias($u);
        $this->gasto($u, [], [['100.00', '2026-03-01']]);

        $u->update(['activo' => false]);

        $this->armar('2026-03-10');

        $this->assertSame(0, Aviso::count());
    }

    public function test_quien_apago_los_avisos_no_recibe_ninguno(): void
    {
        $u = $this->operador();
        $this->preferencias($u, ['activo' => false]);
        $this->gasto($u, [], [['100.00', '2026-03-01']]);

        $this->armar('2026-03-10');

        $this->assertSame(0, Aviso::count());
    }

    // ──────────────────────────── Resúmenes por correo ────────────────────────────

    public function test_sin_pendientes_no_se_manda_ni_se_guarda_resumen(): void
    {
        Mail::fake();
        $u = $this->operador();
        $this->preferencias($u, ['correo' => true, 'resumen' => 'diario']);

        $resultado = $this->enviar('2026-03-10');

        $this->assertSame(1, $resultado['sin_pendientes']);
        $this->assertSame(0, $resultado['preparados']);
        $this->assertSame(0, Resumen::count());
        Mail::assertNothingSent();
    }

    public function test_con_pendientes_se_prepara_el_resumen_y_queda_simulado_fuera_de_produccion(): void
    {
        Mail::fake();
        $u = $this->operador();
        $this->preferencias($u, ['correo' => true, 'resumen' => 'diario']);
        $this->gasto($u, [], [['100.00', '2026-03-01']]);

        $resultado = $this->enviar('2026-03-10');

        $this->assertSame(1, $resultado['preparados']);
        $this->assertSame(1, $resultado['simulados']);
        $this->assertSame(0, $resultado['enviados']);

        $resumen = Resumen::firstOrFail();
        $this->assertSame('simulado', $resumen->estado);
        $this->assertSame(1, $resumen->obligaciones);

        // Lo importante: NO se llamó al transporte.
        Mail::assertNothingSent();
    }

    public function test_cuando_el_entorno_lo_permite_el_resumen_sale_de_verdad(): void
    {
        Mail::fake();
        $u = $this->operador();
        $this->preferencias($u, ['correo' => true, 'resumen' => 'diario']);
        $this->gasto($u, [], [['100.00', '2026-03-01']]);

        // Se sustituye el candado, no el entorno: así se prueba el camino de envío sin
        // que la suite pueda mandar correo real por accidente.
        $this->app->instance(CandadoCorreoReal::class, new class extends CandadoCorreoReal
        {
            public function debeSimular(): bool
            {
                return false;
            }
        });

        $resultado = $this->enviar('2026-03-10');

        $this->assertSame(1, $resultado['enviados']);
        $this->assertSame('enviado', Resumen::firstOrFail()->estado);

        Mail::assertSent(ResumenGastosCorreo::class, fn ($correo) => $correo->hasTo($u->email));
    }

    public function test_el_resumen_no_se_duplica_en_la_misma_ventana(): void
    {
        Mail::fake();
        $u = $this->operador();
        $this->preferencias($u, ['correo' => true, 'resumen' => 'diario']);
        $this->gasto($u, [], [['100.00', '2026-03-01']]);

        $this->enviar('2026-03-10');
        $segunda = $this->enviar('2026-03-10');

        $this->assertSame(0, $segunda['preparados']);
        $this->assertSame(1, Resumen::count());
    }

    public function test_el_resumen_semanal_sale_solo_el_dia_elegido(): void
    {
        Mail::fake();
        $u = $this->operador();
        // 2026-03-10 es martes; se pide el lunes.
        $this->preferencias($u, ['correo' => true, 'resumen' => 'semanal', 'resumen_dia_semana' => 1]);
        $this->gasto($u, [], [['100.00', '2026-03-01']]);

        $martes = $this->enviar('2026-03-10');
        $this->assertSame(0, $martes['preparados']);
        $this->assertSame(1, $martes['fuera_de_ventana']);

        $lunes = $this->enviar('2026-03-16');
        $this->assertSame(1, $lunes['preparados']);
    }

    public function test_quien_no_pidio_correo_no_recibe_resumen(): void
    {
        Mail::fake();
        $u = $this->operador();
        $this->preferencias($u, ['correo' => false, 'resumen' => 'diario']);
        $this->gasto($u, [], [['100.00', '2026-03-01']]);

        $this->assertSame(0, $this->enviar('2026-03-10')['preparados']);
        $this->assertSame(0, Resumen::count());
    }

    public function test_el_resumen_no_incluye_lo_personal_de_quien_no_lo_alcanza(): void
    {
        $conAlcance = $this->operador();
        $soloEmpresa = $this->usuario([PermisoSistema::GastosVer->value]);

        $this->gasto($conAlcance, [
            'ambito' => 'personal', 'persona' => 'Melqui', 'concepto' => 'Colegiatura',
        ], [['500.00', '2026-03-01']]);
        $this->gasto($conAlcance, ['concepto' => 'Internet'], [['40.00', '2026-03-01']]);

        $servicio = app(EnviarResumenes::class);
        $armar = app(ArmarAvisos::class);
        $hoy = CarbonImmutable::parse('2026-03-10');

        $prefsEmpresa = $this->preferencias($soloEmpresa, ['ambitos' => ['empresarial', 'personal']]);
        $contenido = $servicio->contenido($armar->ambitosDe($soloEmpresa, $prefsEmpresa), $hoy);

        $this->assertSame(1, $contenido['obligaciones']);
        // Ni la fila ni el total delatan los 500 personales.
        $this->assertSame(['USD' => '40.00'], $contenido['totales']);
        $this->assertStringNotContainsString('Colegiatura', json_encode($contenido));
    }

    // ──────────────────────────── Pantallas ────────────────────────────

    public function test_la_bandeja_muestra_los_avisos_propios(): void
    {
        $u = $this->operador();
        $this->preferencias($u);
        $this->gasto($u, ['concepto' => 'Agua potable'], [['100.00', '2026-03-01']]);
        $this->armar('2026-03-10');

        $this->actingAs($u)->get(route('gastos.avisos.index'))
            ->assertOk()
            ->assertSee('Agua potable');
    }

    public function test_no_se_puede_marcar_leido_el_aviso_de_otra_persona(): void
    {
        $duena = $this->operador();
        $otra = $this->operador();

        $this->preferencias($duena);
        $this->gasto($duena, [], [['100.00', '2026-03-01']]);
        $this->armar('2026-03-10');

        $aviso = Aviso::firstOrFail();

        $this->actingAs($otra)->post(route('gastos.avisos.leer', $aviso))->assertNotFound();
        $this->assertNull($aviso->fresh()->leido_at);
    }

    public function test_la_bandeja_vacia_lo_dice_sin_parecer_un_error(): void
    {
        $u = $this->operador();

        $this->actingAs($u)->get(route('gastos.avisos.index'))
            ->assertOk()
            ->assertSee('No tenés avisos sin leer.');
    }

    public function test_las_preferencias_no_guardan_personal_sin_alcance(): void
    {
        $u = $this->usuario([PermisoSistema::GastosVer->value]);

        $this->actingAs($u)->put(route('gastos.avisos.preferencias.guardar'), [
            'activo' => 1,
            'dias_anticipacion' => [7, 3],
            'resumen' => 'semanal',
            'resumen_dia_semana' => 1,
            'ambitos' => ['empresarial', 'personal'],
        ])->assertRedirect();

        $this->assertSame(['empresarial'], PreferenciaAvisos::where('usuario_id', $u->id)->firstOrFail()->ambitos);
    }

    public function test_las_preferencias_guardan_los_dias_sin_repetir_y_ordenados(): void
    {
        $u = $this->operador();

        $this->actingAs($u)->put(route('gastos.avisos.preferencias.guardar'), [
            'activo' => 1,
            'dias_anticipacion' => [3, 7, 3],
            'resumen' => 'nunca',
            'resumen_dia_semana' => 1,
            'ambitos' => ['empresarial'],
        ])->assertRedirect();

        $this->assertSame([7, 3], PreferenciaAvisos::where('usuario_id', $u->id)->firstOrFail()->dias_anticipacion);
    }

    public function test_el_modulo_apagado_responde_404_en_los_avisos(): void
    {
        config()->set('gastos.enabled', false);
        $u = $this->operador();

        $this->actingAs($u)->get(route('gastos.avisos.index'))->assertNotFound();
    }

    // ──────────────────────────── El comando ────────────────────────────

    public function test_el_comando_de_avisos_sin_aplicar_no_escribe_nada(): void
    {
        $u = $this->operador();
        $this->preferencias($u);
        $this->gasto($u, [], [['100.00', '2026-03-01']]);

        $this->artisan('gastos:avisos --hoy=2026-03-10')->assertSuccessful();

        $this->assertSame(0, Aviso::count());
        $this->assertSame(0, Resumen::count());
    }

    public function test_el_comando_con_aplicar_no_corre_si_los_avisos_automaticos_estan_apagados(): void
    {
        config()->set('gastos.avisos.automaticos', false);

        $this->artisan('gastos:avisos --aplicar')->assertFailed();

        $this->assertSame(0, Aviso::count());
    }

    public function test_el_comando_con_aplicar_arma_la_bandeja(): void
    {
        Mail::fake();
        $u = $this->operador();
        $this->preferencias($u);
        $this->gasto($u, [], [['100.00', '2026-03-01']]);

        config()->set('gastos.avisos.automaticos', true);

        $this->artisan('gastos:avisos --aplicar --hoy=2026-03-10')->assertSuccessful();

        $this->assertSame(1, Aviso::count());
        Mail::assertNothingSent();
    }
}
