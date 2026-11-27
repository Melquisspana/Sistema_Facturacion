<?php

namespace Tests\Feature\Asistencia;

use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\User;
use App\Services\Asistencia\NumeroParaMarcar;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * «Número para marcar» en la ficha de la persona (aprobado el 26/11/2026).
 *
 * Lo pone la oficina o el sistema propone uno libre; dos personas nunca tienen el mismo;
 * después de guardarlo NO se vuelve a mostrar: solo «Tiene número» o «Sin número».
 */
class NumeroParaMarcarFichaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        Role::findOrCreate('administrador', 'web')->syncPermissions(PermisoSistema::todos());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config()->set('asistencia.enabled', true);
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function soloVer(): User
    {
        return User::factory()->create(['activo' => true])
            ->givePermissionTo(PermisoSistema::AsistenciaVer->value);
    }

    private function empleado(string $nombres = 'Empleado', string $apellidos = 'de prueba'): AsistenciaEmpleado
    {
        return AsistenciaEmpleado::factory()->create(['nombres' => $nombres, 'apellidos' => $apellidos]);
    }

    private function ficha(AsistenciaEmpleado $empleado, ?User $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->admin())->get(route('personal.empleados.show', 'a'.$empleado->id));
    }

    private function poner(AsistenciaEmpleado $empleado, mixed $numero, ?User $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->admin())
            ->from(route('personal.empleados.show', 'a'.$empleado->id))
            ->put(route('asistencia.empleados.numero.update', $empleado), ['numero' => $numero]);
    }

    // ───────────────────────────── Poner y ocultar ─────────────────────────────

    public function test_la_oficina_pone_un_numero_y_despues_no_se_vuelve_a_mostrar(): void
    {
        $empleado = $this->empleado();

        $this->poner($empleado, '4821')
            ->assertRedirect(route('personal.empleados.show', 'a'.$empleado->id))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $empleado->refresh();
        $this->assertTrue($empleado->tieneNumeroMarcar());
        $this->assertSame('4821', $empleado->numero_marcar, 'Se guarda cifrado y se recupera igual.');

        // En la base va cifrado, nunca en claro.
        $crudo = DB::table('asistencia_empleados')->where('id', $empleado->id)->value('numero_marcar');
        $this->assertNotSame('4821', $crudo);
        $this->assertStringNotContainsString('4821', (string) $crudo);
        $this->assertSame(NumeroParaMarcar::indice('4821'), $empleado->numero_marcar_indice);

        $this->ficha($empleado)
            ->assertOk()
            ->assertSee('Número para marcar')
            ->assertSee('Tiene número')
            ->assertDontSee('4821');

        // Ni el mensaje de éxito ni el modelo serializado lo cuentan.
        $this->assertStringNotContainsString('4821', (string) session('status'));
        $this->assertArrayNotHasKey('numero_marcar', $empleado->toArray());
        $this->assertArrayNotHasKey('numero_marcar_indice', $empleado->toArray());
    }

    public function test_sin_numero_la_ficha_lo_dice_y_propone_uno_libre(): void
    {
        $ocupado = $this->empleado('Otro', 'de prueba');
        app(NumeroParaMarcar::class)->asignar($ocupado, '1111');
        $empleado = $this->empleado();

        $respuesta = $this->ficha($empleado)->assertOk()->assertSee('Sin número')->assertSee('Guardar número');

        $propuesto = $respuesta->viewData('numeroPropuesto');
        $this->assertMatchesRegularExpression('/^[0-9]{4}$/D', $propuesto);
        $this->assertNotSame('1111', $propuesto, 'Propone uno LIBRE.');

        // Lo propuesto se puede guardar tal cual.
        $this->poner($empleado, $propuesto)->assertSessionHasNoErrors();
        $this->assertTrue($empleado->fresh()->tieneNumeroMarcar());
    }

    public function test_el_propuesto_nunca_es_uno_ocupado(): void
    {
        $servicio = app(NumeroParaMarcar::class);
        // Todos ocupados menos uno: el único libre es el que tiene que salir.
        $filas = [];
        foreach (range(0, 9999) as $n) {
            $numero = str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            if ($numero !== '0420') {
                $filas[] = ['nombres' => 'Empleado', 'apellidos' => 'de prueba '.$n, 'activo' => true,
                    'numero_marcar_indice' => NumeroParaMarcar::indice($numero), 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($filas, 500) as $lote) {
            DB::table('asistencia_empleados')->insert($lote);
        }

        $this->assertSame('0420', $servicio->proponer());
    }

    public function test_cambiar_el_numero_deja_de_valer_el_anterior(): void
    {
        $empleado = $this->empleado();
        $this->poner($empleado, '4821')->assertSessionHasNoErrors();
        $this->poner($empleado, '5930')->assertSessionHasNoErrors();

        $servicio = app(NumeroParaMarcar::class);
        $this->assertNull($servicio->buscar('4821'));
        $this->assertTrue($servicio->buscar('5930')->is($empleado));
    }

    public function test_quitar_el_numero_la_deja_solo_con_huella(): void
    {
        $empleado = $this->empleado();
        $this->poner($empleado, '4821');

        $this->actingAs($this->admin())
            ->from(route('personal.empleados.show', 'a'.$empleado->id))
            ->delete(route('asistencia.empleados.numero.destroy', $empleado))
            ->assertRedirect()
            ->assertSessionHas('status');

        $empleado->refresh();
        $this->assertFalse($empleado->tieneNumeroMarcar());
        $this->assertNull($empleado->numero_marcar);
        $this->assertNull($empleado->numero_marcar_indice);
        $this->ficha($empleado)->assertSee('Sin número')->assertDontSee('Quitar número');
    }

    // ─────────────────────────────── Validación ───────────────────────────────

    public function test_rechaza_lo_que_no_son_exactamente_cuatro_digitos(): void
    {
        $empleado = $this->empleado();

        foreach (['123', '12345', '12 34', 'abcd', '12a4', '', '١٢٣٤', '12.4', '-123', ['1234']] as $malo) {
            $this->poner($empleado, $malo)->assertSessionHasErrors('numero');
        }

        $this->assertFalse($empleado->fresh()->tieneNumeroMarcar());
    }

    public function test_dos_personas_nunca_tienen_el_mismo_numero(): void
    {
        $primera = $this->empleado('Primera', 'de prueba');
        $segunda = $this->empleado('Segunda', 'de prueba');
        $this->poner($primera, '4821')->assertSessionHasNoErrors();

        $this->poner($segunda, '4821')->assertSessionHasErrors(['numero' => 'Ese número ya lo tiene otra persona. Elegí otro.']);

        $this->assertFalse($segunda->fresh()->tieneNumeroMarcar());
        $this->assertTrue(app(NumeroParaMarcar::class)->buscar('4821')->is($primera));
    }

    public function test_volver_a_poner_su_propio_numero_no_choca_consigo_misma(): void
    {
        $empleado = $this->empleado();
        $this->poner($empleado, '4821')->assertSessionHasNoErrors();
        $this->poner($empleado, '4821')->assertSessionHasNoErrors();

        $this->assertTrue(app(NumeroParaMarcar::class)->buscar('4821')->is($empleado));
    }

    public function test_la_base_impide_el_duplicado_aunque_se_salte_la_comprobacion(): void
    {
        $this->empleado('Primera', 'de prueba')->forceFill(['numero_marcar_indice' => NumeroParaMarcar::indice('4821')])->save();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->empleado('Segunda', 'de prueba')->forceFill(['numero_marcar_indice' => NumeroParaMarcar::indice('4821')])->save();
    }

    // ───────────────────────────── Permiso y auditoría ─────────────────────────────

    public function test_quien_solo_ve_no_puede_poner_ni_quitar_y_no_ve_el_formulario(): void
    {
        $empleado = $this->empleado();
        $miron = $this->soloVer();

        $this->poner($empleado, '4821', $miron)->assertForbidden();
        $this->actingAs($miron)->delete(route('asistencia.empleados.numero.destroy', $empleado))->assertForbidden();
        $this->assertFalse($empleado->fresh()->tieneNumeroMarcar());

        $respuesta = $this->ficha($empleado, $miron)->assertOk()->assertSee('Sin número')->assertDontSee('Guardar número');
        $this->assertNull($respuesta->viewData('numeroPropuesto'));
    }

    public function test_un_invitado_va_al_login(): void
    {
        $empleado = $this->empleado();

        $this->put(route('asistencia.empleados.numero.update', $empleado), ['numero' => '4821'])->assertRedirect(route('login'));
        $this->delete(route('asistencia.empleados.numero.destroy', $empleado))->assertRedirect(route('login'));
    }

    public function test_poner_y_quitar_quedan_auditados_sin_el_numero(): void
    {
        $empleado = $this->empleado();
        $admin = $this->admin();

        $this->poner($empleado, '4821', $admin);
        $this->actingAs($admin)->delete(route('asistencia.empleados.numero.destroy', $empleado));

        $registros = Activity::query()->where('log_name', 'asistencia')
            ->where('subject_type', AsistenciaEmpleado::class)->where('subject_id', $empleado->id)
            ->whereIn('description', ['puso un número para marcar', 'quitó el número para marcar'])
            ->get();

        $this->assertCount(2, $registros);
        $this->assertTrue($registros->every(fn ($a) => $a->causer_id === $admin->id));

        $todo = Activity::query()->get()->toJson();
        $this->assertStringNotContainsString('4821', $todo);
        $this->assertStringNotContainsString(NumeroParaMarcar::indice('4821'), $todo);
    }

    // ─────────────────────────────── Textos de la ficha ───────────────────────────────

    public function test_sin_huellas_pero_con_numero_la_ficha_no_dice_que_no_puede_marcar(): void
    {
        $empleado = $this->empleado();
        app(NumeroParaMarcar::class)->asignar($empleado, '4821');

        $this->ficha($empleado)
            ->assertOk()
            ->assertDontSee('no puede marcar todavía', escape: false)
            ->assertSee('marca solo con su número');
    }
}
