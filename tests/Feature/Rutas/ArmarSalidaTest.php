<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Rutas\ParticipantesSalida;
use App\Services\Usuarios\CrearUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * «Armar salida» con fecha (docs/PLAN_TABLERO_RUTAS.md, PR 6): ruta, día (hoy u otro que
 * viene), quiénes van y quién la encabeza. Para hoy sale en camino; para otro día queda
 * planificada. La arma el jefe de vendedores con `rutas.salidas.armar` o la oficina con
 * `rutas.gestionar`. Todos los datos son inventados.
 */
class ArmarSalidaTest extends TestCase
{
    use RefreshDatabase;

    private User $jefe;

    private PersonalRuta $fichaJefe;

    private PersonalRuta $marlon;

    private Ruta $ruta;

    private ClienteSucursal $sala;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rutas.entregas_desde' => '2026-09-28', 'rutas.ambiente_ccf' => null, 'dte.ambiente' => '01']);
        $this->travelTo('2026-11-03 09:00:00');

        $this->jefe = app(CrearUsuario::class)->crear([
            'name' => 'Javier Inventado', 'email' => 'jefe-armar@example.test', 'password' => 'Clave-de-prueba-123',
            'activo' => true, 'rol' => RolSistema::JefeVendedores->value,
        ]);
        $this->fichaJefe = PersonalRuta::where('user_id', $this->jefe->id)->firstOrFail();
        $this->marlon = PersonalRuta::create(['nombre' => 'Marlon Ficticio']);
        $this->ruta = Ruta::create(['nombre' => 'San Miguel']);
        $this->sala = Cliente::factory()->create(['nombre' => 'Cliente Inventado'])
            ->sucursales()->create(['nombre' => 'Sala de Prueba', 'codigo' => '9001', 'ruta_id' => $this->ruta->id]);
    }

    /** @param  array<string, mixed>  $extra */
    private function armar(User $quien, array $extra = []): TestResponse
    {
        return $this->actingAs($quien)->post(route('rutas.salidas.store'), $extra + [
            'ruta_id' => (string) $this->ruta->id,
            'dia' => 'hoy',
            'personal' => [(string) $this->marlon->id, (string) $this->fichaJefe->id],
            'responsable_id' => (string) $this->marlon->id,
        ]);
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    /** Un CCF aceptado de producción, de la sala de la ruta. */
    private function ccf(): Dte
    {
        $empresa = Empresa::create(['razon_social' => 'Empresa Inventada', 'ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);

        return Dte::create([
            'establecimiento_id' => $establecimiento->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)),
            'fecha_procesamiento_mh' => now(),
            'cliente_id' => $this->sala->cliente_id,
            'cliente_sucursal_id' => $this->sala->id,
            'numero_control' => 'DTE-03-M001P002-000000000000001',
            'numero_orden_compra' => '260602320000001',
            'fecha_emision' => '2026-11-02',
            'hora_emision' => '10:00:00',
            'total_pagar' => 100.00,
        ]);
    }

    // ══════════════════════════════ quién arma

    public function test_el_jefe_arma_para_hoy_y_sale_en_camino(): void
    {
        $this->armar($this->jefe)->assertSessionHasNoErrors()->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_starts_with($m, 'Salida en camino'));

        $salida = SalidaRuta::sole();
        $this->assertSame(EstadoSalidaRuta::EnCurso, $salida->estado);
        $this->assertSame('2026-11-03', $salida->fecha_inicio->toDateString());
        $this->assertSame($this->jefe->id, $salida->created_by);
        $this->assertEqualsCanonicalizing([$this->marlon->id, $this->fichaJefe->id], $salida->personal->pluck('id')->all());
        $this->assertSame($this->marlon->id, $salida->responsable?->rutas_personal_id);
    }

    public function test_el_jefe_arma_para_otro_dia_y_queda_planificada(): void
    {
        $this->armar($this->jefe, ['dia' => 'otro', 'fecha_inicio' => '2026-11-05'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'planificada para el 05/11/2026'));

        $salida = SalidaRuta::sole();
        $this->assertSame(EstadoSalidaRuta::Planificada, $salida->estado);
        $this->assertSame('2026-11-05', $salida->fecha_inicio->toDateString());
    }

    public function test_con_hoy_elegido_vale_hoy_aunque_el_campo_de_fecha_diga_otra_cosa(): void
    {
        $this->armar($this->jefe, ['dia' => 'hoy', 'fecha_inicio' => '2026-11-10'])->assertSessionHasNoErrors();

        $this->assertSame(EstadoSalidaRuta::EnCurso, SalidaRuta::sole()->estado);
        $this->assertSame('2026-11-03', SalidaRuta::sole()->fecha_inicio->toDateString());
    }

    public function test_la_oficina_con_rutas_gestionar_tambien_arma(): void
    {
        $this->actingAs($this->usuario('facturacion'))->get(route('rutas.salidas.create'))->assertOk()->assertSee('Armar salida');

        $this->armar($this->usuario('facturacion'), ['dia' => 'otro', 'fecha_inicio' => '2026-11-04'])->assertSessionHasNoErrors();

        $this->assertSame(EstadoSalidaRuta::Planificada, SalidaRuta::sole()->estado);
    }

    public function test_el_jefe_ve_el_formulario_y_los_botones_para_armar(): void
    {
        $this->actingAs($this->jefe)->get(route('rutas.salidas.create'))
            ->assertOk()
            ->assertSee('¿Quiénes van?')
            ->assertSee('¿Quién la encabeza?')
            ->assertSee('Otro día')
            ->assertSee('Marlon Ficticio');

        $this->actingAs($this->jefe)->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSee(route('rutas.salidas.create'))
            ->assertSee('Salir a esta ruta');
    }

    public function test_vendedor_contabilidad_y_sin_permiso_reciben_403(): void
    {
        $vendedor = User::factory()->create(['activo' => true])->assignRole(RolSistema::Vendedor->value);

        foreach ([$vendedor, $this->usuario('contabilidad'), User::factory()->create(['activo' => true])] as $usuario) {
            $this->actingAs($usuario)->get(route('rutas.salidas.create'))->assertForbidden();
            $this->armar($usuario)->assertForbidden();
        }

        $this->assertSame(0, SalidaRuta::count());
    }

    // ══════════════════════════════ rechazos

    public function test_no_se_arma_para_un_dia_pasado(): void
    {
        $this->armar($this->jefe, ['dia' => 'otro', 'fecha_inicio' => '2026-11-02'])->assertSessionHasErrors('fecha_inicio');
        $this->armar($this->jefe, ['dia' => 'otro', 'fecha_inicio' => ''])->assertSessionHasErrors('fecha_inicio');
        // Sin «dia» (como el «Salir a esta ruta» del tablero) también vale la regla.
        $this->armar($this->jefe, ['dia' => null, 'fecha_inicio' => '2026-10-30'])->assertSessionHasErrors('fecha_inicio');

        $this->assertSame(0, SalidaRuta::count());
    }

    public function test_no_se_arma_con_una_persona_inactiva(): void
    {
        $this->marlon->update(['activo' => false]);

        $this->armar($this->jefe, ['responsable_id' => null])->assertSessionHasErrors('personal.0');

        $this->assertSame(0, SalidaRuta::count());
    }

    public function test_quien_la_encabeza_tiene_que_ir(): void
    {
        $this->armar($this->jefe, ['personal' => [(string) $this->fichaJefe->id]])->assertSessionHasErrors('responsable_id');

        $this->assertSame(0, SalidaRuta::count());
    }

    public function test_no_se_arma_en_una_ruta_desactivada(): void
    {
        $this->ruta->update(['activa' => false]);

        $this->armar($this->jefe)->assertSessionHasErrors('ruta_id');

        $this->assertSame(0, SalidaRuta::count());
    }

    public function test_hace_falta_al_menos_una_persona(): void
    {
        $this->armar($this->jefe, ['personal' => [], 'responsable_id' => null])->assertSessionHasErrors('personal');

        $this->assertSame(0, SalidaRuta::count());
    }

    // ══════════════════════════════ CCF y planificada

    public function test_los_ccf_se_cargan_igual_y_la_planificada_no_deja_registrar_entregas(): void
    {
        $this->ccf();

        $this->armar($this->jefe, ['dia' => 'otro', 'fecha_inicio' => '2026-11-05'])
            ->assertSessionHas('status', 'Salida planificada para el 05/11/2026 con 1 CCF por entregar.');

        $salida = SalidaRuta::sole();
        $entrega = $salida->entregas()->sole();

        // El jefe va en ella, pero hasta que empiece no registra nada desde «Mi salida».
        $this->actingAs($this->jefe)
            ->patch(route('rutas.mi-salida.update', [$salida, $entrega]), ['resultado' => 'entregado', 'entregado_por_id' => $this->fichaJefe->id])
            ->assertForbidden();

        // Ni la oficina desde la hoja.
        $this->actingAs($this->usuario('administrador'))
            ->patch(route('rutas.salidas.entregas.update', [$salida, $entrega]), ['resultado' => 'entregado', 'entregado_por_id' => $this->marlon->id])
            ->assertSessionHasErrors('salida');

        $this->assertNull($entrega->refresh()->resultado);
    }

    // ══════════════════════════════ armar no da lo demás

    public function test_el_jefe_sigue_sin_editar_cancelar_ni_reabrir(): void
    {
        $salida = SalidaRuta::create(['ruta_id' => $this->ruta->id, 'fecha_inicio' => '2026-11-03', 'estado' => EstadoSalidaRuta::EnCurso]);
        app(ParticipantesSalida::class)->sincronizar($salida, [$this->marlon->id], null);

        $this->actingAs($this->jefe)->get(route('rutas.salidas.edit', $salida))->assertForbidden();
        $this->actingAs($this->jefe)->put(route('rutas.salidas.update', $salida), [
            'ruta_id' => $this->ruta->id, 'fecha_inicio' => '2026-11-03', 'personal' => [$this->fichaJefe->id],
        ])->assertForbidden();
        $this->actingAs($this->jefe)->patch(route('rutas.salidas.cancelar', $salida))->assertForbidden();
        $this->actingAs($this->jefe)->patch(route('rutas.salidas.finalizar', $salida))->assertForbidden();
        $this->actingAs($this->jefe)->post(route('rutas.salidas.reabrir', [$salida, '2026-11-03']))->assertForbidden();
        $this->actingAs($this->jefe)->get(route('rutas.rutas.edit', $this->ruta))->assertForbidden();

        $this->actingAs($this->jefe)->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->assertDontSee(route('rutas.salidas.edit', $salida))
            ->assertDontSee(route('rutas.salidas.cancelar', $salida));

        $this->assertSame(EstadoSalidaRuta::EnCurso, $salida->refresh()->estado);
        $this->assertSame([$this->marlon->id], $salida->personal->pluck('id')->all());
    }
}
