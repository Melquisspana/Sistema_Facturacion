<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\ResultadoEntrega;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Usuarios\CrearUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\SalidaDePrueba;
use Tests\TestCase;

/**
 * La sección Vendedores del tablero de Rutas (sección 1 de docs/PLAN_TABLERO_RUTAS.md):
 * teléfono, salida de ahora o la última, sus rutas de los últimos 90 días y «Ver ficha».
 * Las fichas de los empleados son solo de la administración: el jefe no ve «Ver ficha».
 */
class VendedoresTableroTest extends TestCase
{
    use RefreshDatabase;

    private User $jefe;

    private PersonalRuta $luis;

    private PersonalRuta $rosa;

    private Ruta $norte;

    private Ruta $oriente;

    private Ruta $costa;

    protected function setUp(): void
    {
        parent::setUp();

        config(['planilla.enabled' => true]);
        $this->travelTo('2026-11-03 09:00:00');

        $this->jefe = app(CrearUsuario::class)->crear([
            'name' => 'Jefe Inventado', 'email' => 'jefe@example.test', 'password' => 'Clave-de-prueba-123',
            'activo' => true, 'rol' => RolSistema::JefeVendedores->value,
        ]);
        $this->norte = Ruta::create(['nombre' => 'Ruta Norte']);
        $this->oriente = Ruta::create(['nombre' => 'Ruta Oriente']);
        $this->costa = Ruta::create(['nombre' => 'Ruta Costa']);
        $this->luis = PersonalRuta::create(['nombre' => 'Luis Inventado', 'telefono' => '7000-0000']);
        $this->rosa = PersonalRuta::create(['nombre' => 'Rosa Ficticia', 'telefono' => '7000-0001']);
    }

    private function salida(Ruta $ruta, PersonalRuta $persona, string $inicio, EstadoSalidaRuta $estado = EstadoSalidaRuta::Finalizada, ?string $regreso = null): SalidaRuta
    {
        $salida = SalidaRuta::create(['ruta_id' => $ruta->id, 'fecha_inicio' => $inicio, 'fecha_fin_real' => $regreso, 'estado' => $estado]);
        SalidaDePrueba::participantes($salida, [$persona->id], $persona->id);

        return $salida;
    }

    /** Ficha de Personal (Planilla) enlazada a la persona de Rutas. */
    private function fichaDePersonal(PersonalRuta $persona): PlanillaEmpleado
    {
        return PlanillaEmpleado::create([
            'clave' => 'ruta-'.$persona->id,
            'registrado_por' => $this->jefe->id,
            'nombre' => $persona->nombre, 'personal_ruta_id' => $persona->id,
            'documento_tipo' => 'pasaporte', 'dui' => 'FICTICIO-'.$persona->id,
            'con_descuentos_ley' => false, 'activo' => true,
        ]);
    }

    public function test_cada_vendedor_con_telefono_salida_y_sus_rutas(): void
    {
        // Luis: va ahora a Norte; en 90 días fue dos veces a Oriente y una a Norte.
        $this->salida($this->oriente, $this->luis, '2026-10-01', EstadoSalidaRuta::Finalizada, '2026-10-02');
        $this->salida($this->oriente, $this->luis, '2026-10-15', EstadoSalidaRuta::Finalizada, '2026-10-16');
        $this->salida($this->norte, $this->luis, '2026-11-03', EstadoSalidaRuta::EnCurso);
        // Rosa: su última salida terminó el 28/10; la de hace más de 90 días no cuenta.
        $this->salida($this->costa, $this->rosa, '2026-10-27', EstadoSalidaRuta::Finalizada, '2026-10-28');
        $this->salida($this->norte, $this->rosa, '2026-07-01', EstadoSalidaRuta::Finalizada, '2026-07-02');
        // Las canceladas no cuentan.
        $this->salida($this->norte, $this->rosa, '2026-10-30', EstadoSalidaRuta::Cancelada);

        $respuesta = $this->actingAs($this->jefe)->get(route('rutas.dashboard'))->assertOk();

        $respuesta->assertSeeInOrder(['Vendedores', 'Jefe Inventado', 'Luis Inventado', 'Rosa Ficticia']);
        $respuesta->assertSeeInOrder(['Luis Inventado', 'href="tel:70000000"', 'Tel. 7000-0000', 'Ahora: Ruta Norte, desde hoy', 'Sus zonas: Ruta Oriente, Ruta Norte'], false);
        $respuesta->assertSeeInOrder(['Rosa Ficticia', 'href="tel:70000001"', 'Última: Ruta Costa, 28/10/2026', 'Sus zonas: Ruta Costa'], false);
    }

    public function test_vendedor_sin_telefono_ni_salidas(): void
    {
        $sinNada = PersonalRuta::create(['nombre' => 'Zoe Ejemplo']);

        $this->actingAs($this->jefe)->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Zoe Ejemplo', 'Sin teléfono', 'Sin viajes en los últimos 90 días'], false)
            ->assertDontSee(route('rutas.personal.show', $sinNada))
            ->assertDontSee('Ver ficha');
    }

    /** Una entrega hecha por la persona, en un viaje en el que no figura como participante. */
    private function entregaDe(PersonalRuta $persona, string $cuando): void
    {
        $empresa = Empresa::create(['razon_social' => 'Dulces Inventados', 'ambiente' => '01', 'activo' => true]);
        $establecimiento = Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        $sala = Cliente::factory()->create()->sucursales()->create(['nombre' => 'Sala de prueba', 'activo' => true]);
        $dte = Dte::withoutEvents(fn () => Dte::create([
            'establecimiento_id' => $establecimiento->id, 'tipo_dte' => '03', 'estado' => 'aceptado', 'ambiente' => '01',
            'sello_recepcion' => Str::uuid()->toString(), 'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala->cliente_id, 'cliente_sucursal_id' => $sala->id, 'numero_control' => 'DTE-03-M001P001-000000000000001',
            'fecha_emision' => substr($cuando, 0, 10), 'hora_emision' => '10:00:00', 'subtotal' => 10, 'total_descuento' => 0, 'total_pagar' => 10,
        ]));
        $salida = SalidaRuta::create(['ruta_id' => $this->norte->id, 'fecha_inicio' => substr($cuando, 0, 10), 'fecha_fin_real' => substr($cuando, 0, 10), 'estado' => EstadoSalidaRuta::Finalizada]);
        $salida->entregas()->create([
            'dte_id' => $dte->id, 'cliente_sucursal_id' => $sala->id, 'resultado' => ResultadoEntrega::Entregado,
            'entregado_por_id' => $persona->id, 'fecha_resultado' => $cuando,
        ]);
    }

    /** Un Administrador con ficha de Rutas, como la que se le crea para entrar al menú del vendedor. */
    private function administradorConFicha(): PersonalRuta
    {
        $admin = User::factory()->create(['activo' => true])->assignRole('administrador');

        return PersonalRuta::create(['nombre' => 'Admin Inventado', 'user_id' => $admin->id]);
    }

    public function test_el_administrador_sin_viajes_ni_entregas_no_sale_entre_los_vendedores(): void
    {
        $ficha = $this->administradorConFicha();
        // Lo de hace más de 90 días no cuenta.
        $this->salida($this->norte, $ficha, '2026-07-01', EstadoSalidaRuta::Finalizada, '2026-07-02');
        $this->entregaDe($ficha, '2026-07-01 11:00:00');

        $this->actingAs($this->jefe)->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Vendedores', 'Jefe Inventado', 'Luis Inventado', 'Rosa Ficticia'])
            ->assertDontSee('Admin Inventado');
    }

    public function test_el_administrador_que_tuvo_un_viaje_sale_como_cualquiera(): void
    {
        $this->salida($this->costa, $this->administradorConFicha(), '2026-10-20', EstadoSalidaRuta::Finalizada, '2026-10-21');

        $this->actingAs($this->jefe)->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Vendedores', 'Admin Inventado', 'Última: Ruta Costa, 21/10/2026', 'Sus zonas: Ruta Costa']);
    }

    public function test_el_administrador_que_entrego_sale_como_cualquiera(): void
    {
        $this->entregaDe($this->administradorConFicha(), '2026-10-30 11:00:00');

        $this->actingAs($this->jefe)->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Vendedores', 'Admin Inventado', 'Sin viajes en los últimos 90 días', 'Última entrega']);
    }

    public function test_quien_no_es_administrador_sale_aunque_no_tenga_viajes(): void
    {
        $vendedor = User::factory()->create(['activo' => true])->assignRole('vendedor');
        PersonalRuta::create(['nombre' => 'Zoe Ejemplo', 'user_id' => $vendedor->id]);

        $this->actingAs($this->jefe)->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Zoe Ejemplo', 'Sin viajes en los últimos 90 días']);
    }

    public function test_el_jefe_no_abre_fichas_de_empleados(): void
    {
        $ficha = $this->fichaDePersonal($this->luis);

        // Ve el teléfono para llamar, pero no hay «Ver ficha» ni de campo ni de Personal.
        $this->actingAs($this->jefe)->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSee('7000-0000')
            ->assertDontSee(route('rutas.personal.show', $this->luis))
            ->assertDontSee(route('personal.empleados.show', 'p'.$ficha->id))
            ->assertDontSee('/personal/empleados');

        $this->actingAs($this->jefe)->get(route('rutas.personal.show', $this->luis))->assertForbidden();
        $this->actingAs($this->jefe)->get(route('personal.empleados.show', 'p'.$ficha->id))->assertForbidden();
    }

    public function test_el_administrador_va_a_la_ficha_de_personal(): void
    {
        $ficha = $this->fichaDePersonal($this->luis);
        $admin = User::factory()->create(['activo' => true])->assignRole('administrador');

        $this->actingAs($admin)->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Luis Inventado', 'href="'.route('personal.empleados.show', 'p'.$ficha->id).'"', 'Ver ficha'], false)
            // Rosa no está en Personal: va a su ficha de campo.
            ->assertSeeInOrder(['Rosa Ficticia', 'href="'.route('rutas.personal.show', $this->rosa).'"', 'Ver ficha'], false);
    }
}
