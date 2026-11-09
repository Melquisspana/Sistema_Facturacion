<?php

namespace Tests\Feature\Configuracion;

use App\Ajustes\Excepciones\ValorAjusteInvalidoException;
use App\Enums\TipoDte;
use App\Facades\Ajustes;
use App\Models\User;
use App\Support\Dte\CalendarioInhabiles;
use App\Support\Dte\PlazoInvalidacion;
use App\Support\HoraNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Configuración → Facturación electrónica → Feriados de Hacienda (issue #49).
 *
 * Los días inhábiles del MH se cargan por año desde la pantalla, con la misma
 * confirmación N2 y la misma auditoría que el resto del centro. Un año que la
 * pantalla no tiene sale de config/dte.php (el oficial 2026).
 */
class FeriadosHaciendaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function ultimaAuditoria(): Activity
    {
        return Activity::query()->where('log_name', 'ajustes')->latest('id')->firstOrFail();
    }

    /** @param  array<int, string>  $fechas */
    private function cargar(array $fechas, array $completos = []): void
    {
        Ajustes::guardarComoSistema(CalendarioInhabiles::FECHAS, $fechas);
        Ajustes::guardarComoSistema(CalendarioInhabiles::COMPLETOS, $completos);
    }

    // ---------------------------------------------------------------- pantalla

    public function test_muestra_el_calendario_oficial_2026_con_el_dia_de_la_semana(): void
    {
        $this->actingAs($this->admin())
            ->get(route('configuracion.fiscal.feriados', ['anio' => 2026]))
            ->assertOk()
            ->assertSee('Feriados de Hacienda')
            ->assertSeeInOrder(['01/05/2026', 'viernes', '17/06/2026', 'miércoles', '15/09/2026', 'martes'])
            ->assertSee('26 días')
            ->assertSee('Calendario Tributario 2026')
            // El botón principal dice verbo + qué (docs/GUIA_PANTALLAS.md, Botones).
            ->assertSee('Guardar marca de completo');
    }

    public function test_un_anio_sin_calendario_lo_dice(): void
    {
        $this->actingAs($this->admin())
            ->get(route('configuracion.fiscal.feriados', ['anio' => 2027]))
            ->assertOk()
            ->assertSee('Todavía no hay días cargados para 2027.');
    }

    public function test_un_anio_fuera_de_rango_no_existe(): void
    {
        $this->actingAs($this->admin())
            ->get(route('configuracion.fiscal.feriados', ['anio' => 'abc']))
            ->assertNotFound();
    }

    public function test_quien_no_administra_la_configuracion_recibe_403(): void
    {
        $usuario = User::factory()->create(['activo' => true])->assignRole('facturacion');

        $this->actingAs($usuario)->get(route('configuracion.fiscal.feriados'))->assertForbidden();
        $this->actingAs($usuario)->post(route('configuracion.fiscal.feriados.agregar'), ['anio' => 2027, 'desde' => '2027-05-01', 'confirmacion' => '1'])->assertForbidden();
        $this->actingAs($usuario)->delete(route('configuracion.fiscal.feriados.quitar'), ['anio' => 2026, 'fecha' => '2026-05-01', 'confirmacion' => '1'])->assertForbidden();
        $this->actingAs($usuario)->put(route('configuracion.fiscal.feriados.completo'), ['anio' => 2027, 'completo' => '1', 'confirmacion' => '1'])->assertForbidden();

        $this->assertDatabaseCount('ajustes_sistema', 0);
    }

    // ---------------------------------------------------------------- agregar

    public function test_agregar_un_dia_pide_confirmacion_y_despues_guarda_con_auditoria(): void
    {
        $admin = $this->admin();
        $datos = ['anio' => 2027, 'desde' => '2027-05-01'];

        $this->actingAs($admin)
            ->post(route('configuracion.fiscal.feriados.agregar'), $datos)
            ->assertOk()
            ->assertSee('Esto es lo que va a cambiar')
            ->assertSee('sábado 01/05/2027');
        $this->assertDatabaseCount('ajustes_sistema', 0);

        $this->actingAs($admin)
            ->post(route('configuracion.fiscal.feriados.agregar'), $datos + ['confirmacion' => '1'])
            ->assertRedirect(route('configuracion.fiscal.feriados', ['anio' => 2027]));

        $this->assertSame(['2027-05-01'], Ajustes::lista(CalendarioInhabiles::FECHAS));
        // 2027 no viene del archivo: no hay nada que tapar.
        $this->assertSame([], Ajustes::lista(CalendarioInhabiles::EN_CONFIGURACION));

        $auditoria = $this->ultimaAuditoria();
        $this->assertSame($admin->id, $auditoria->causer_id);
        $this->assertSame(CalendarioInhabiles::FECHAS, $auditoria->getExtraProperty('clave'));
        $this->assertSame(['2027-05-01'], $auditoria->getExtraProperty('valor_despues'));
    }

    public function test_un_rango_se_guarda_como_dias_sueltos_ordenados(): void
    {
        $this->cargar(['2027-04-05']);

        $this->actingAs($this->admin())
            ->post(route('configuracion.fiscal.feriados.agregar'), ['anio' => 2027, 'desde' => '2027-04-01', 'hasta' => '2027-04-03', 'confirmacion' => '1'])
            ->assertRedirect();

        $this->assertSame(
            ['2027-04-01', '2027-04-02', '2027-04-03', '2027-04-05'],
            Ajustes::lista(CalendarioInhabiles::FECHAS),
        );
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function datosInvalidos(): array
    {
        return [
            'fecha que no existe' => [['anio' => 2027, 'desde' => '2027-02-30'], 'desde'],
            'otro formato' => [['anio' => 2027, 'desde' => '01/05/2027'], 'desde'],
            'de otro año' => [['anio' => 2027, 'desde' => '2026-05-01'], 'desde'],
            'rango que se sale del año' => [['anio' => 2027, 'desde' => '2027-12-30', 'hasta' => '2028-01-02'], 'hasta'],
            'hasta antes que desde' => [['anio' => 2027, 'desde' => '2027-05-03', 'hasta' => '2027-05-01'], 'hasta'],
            'año fuera de rango' => [['anio' => 1999, 'desde' => '1999-05-01'], 'anio'],
            'sin fecha' => [['anio' => 2027], 'desde'],
            'más días de los razonables' => [['anio' => 2027, 'desde' => '2027-01-01', 'hasta' => '2027-03-31'], 'hasta'],
        ];
    }

    #[DataProvider('datosInvalidos')]
    public function test_agregar_rechaza_fechas_invalidas(array $datos, string $campo): void
    {
        $this->actingAs($this->admin())
            ->post(route('configuracion.fiscal.feriados.agregar'), $datos + ['confirmacion' => '1'])
            ->assertSessionHasErrors($campo);

        $this->assertDatabaseCount('ajustes_sistema', 0);
    }

    public function test_una_fecha_repetida_se_rechaza(): void
    {
        $this->cargar(['2027-05-01']);

        $this->actingAs($this->admin())
            ->post(route('configuracion.fiscal.feriados.agregar'), ['anio' => 2027, 'desde' => '2027-05-01', 'confirmacion' => '1'])
            ->assertSessionHasErrors('desde');

        $this->assertSame(['2027-05-01'], Ajustes::lista(CalendarioInhabiles::FECHAS));
    }

    // ---------------------------------------------------------------- quitar

    public function test_quitar_una_fecha_con_confirmacion(): void
    {
        $this->cargar(['2027-05-01', '2027-09-15'], ['2027']);
        $admin = $this->admin();
        $datos = ['anio' => 2027, 'fecha' => '2027-05-01'];

        $this->actingAs($admin)
            ->delete(route('configuracion.fiscal.feriados.quitar'), $datos)
            ->assertOk()
            ->assertSee('Esto es lo que va a cambiar');
        $this->assertSame(['2027-05-01', '2027-09-15'], Ajustes::lista(CalendarioInhabiles::FECHAS));

        $this->actingAs($admin)
            ->delete(route('configuracion.fiscal.feriados.quitar'), $datos + ['confirmacion' => '1'])
            ->assertRedirect(route('configuracion.fiscal.feriados', ['anio' => 2027]));

        $this->assertSame(['2027-09-15'], Ajustes::lista(CalendarioInhabiles::FECHAS));
        $this->assertSame(['2027-05-01', '2027-09-15'], $this->ultimaAuditoria()->getExtraProperty('valor_antes'));
    }

    public function test_quitar_una_fecha_que_no_esta_se_rechaza(): void
    {
        $this->cargar(['2027-09-15']);

        $this->actingAs($this->admin())
            ->delete(route('configuracion.fiscal.feriados.quitar'), ['anio' => 2027, 'fecha' => '2027-05-01', 'confirmacion' => '1'])
            ->assertSessionHasErrors('fecha');

        $this->assertSame(['2027-09-15'], Ajustes::lista(CalendarioInhabiles::FECHAS));
    }

    /** Cambiar un año que viene del archivo lo copia entero a Configuración, ya completo. */
    public function test_cambiar_el_anio_del_archivo_lo_copia_entero(): void
    {
        $this->actingAs($this->admin())
            ->delete(route('configuracion.fiscal.feriados.quitar'), ['anio' => 2026, 'fecha' => '2026-06-17', 'confirmacion' => '1'])
            ->assertRedirect();

        $fechas = Ajustes::lista(CalendarioInhabiles::FECHAS);
        $this->assertCount(25, $fechas);
        $this->assertNotContains('2026-06-17', $fechas);
        $this->assertContains('2026-05-01', $fechas);
        $this->assertSame(['2026'], Ajustes::lista(CalendarioInhabiles::COMPLETOS));
        $this->assertSame(['2026'], Ajustes::lista(CalendarioInhabiles::EN_CONFIGURACION));
    }

    /**
     * Un año del archivo que se vacía desde la pantalla queda vacío: lo que dice la
     * confirmación es lo que se guarda, lo que queda en la auditoría y lo que usa el
     * plazo. No vuelve el calendario del archivo.
     */
    public function test_vaciar_un_anio_del_archivo_no_lo_devuelve_al_archivo(): void
    {
        $admin = $this->admin();
        $archivo = config('dte.invalidacion.dias_inhabiles.2026');
        $ultima = array_pop($archivo);

        // Se desmarca 2026 (copia las 26 fechas, sin marcar) y se quitan una por una.
        $this->actingAs($admin)
            ->put(route('configuracion.fiscal.feriados.completo'), ['anio' => 2026, 'confirmacion' => '1'])
            ->assertRedirect();
        foreach ($archivo as $fecha) {
            $this->actingAs($admin)
                ->delete(route('configuracion.fiscal.feriados.quitar'), ['anio' => 2026, 'fecha' => $fecha, 'confirmacion' => '1'])
                ->assertRedirect();
        }

        $this->actingAs($admin)
            ->delete(route('configuracion.fiscal.feriados.quitar'), ['anio' => 2026, 'fecha' => $ultima])
            ->assertOk()
            ->assertSee('2026: 1 día')
            ->assertSee('2026: 0 días (se quita el jueves 31/12/2026)');
        $this->actingAs($admin)
            ->delete(route('configuracion.fiscal.feriados.quitar'), ['anio' => 2026, 'fecha' => $ultima, 'confirmacion' => '1'])
            ->assertRedirect();

        $this->assertSame(
            ['fechas' => [], 'completo' => false, 'en_configuracion' => true],
            app(CalendarioInhabiles::class)->delAnio('2026'),
        );
        $auditoria = $this->ultimaAuditoria();
        $this->assertSame(CalendarioInhabiles::FECHAS, $auditoria->getExtraProperty('clave'));
        $this->assertNull($auditoria->getExtraProperty('valor_despues'));

        $this->actingAs($admin)
            ->get(route('configuracion.fiscal.feriados', ['anio' => 2026]))
            ->assertOk()
            ->assertSee('0 días. Sin marcar como completo')
            ->assertDontSee('26 días');

        // El plazo cuenta de lunes a viernes, sin los feriados del archivo, y avisa.
        $plazo = app(PlazoInvalidacion::class);
        $this->assertEquals(HoraNegocio::finDelDiaUtc('2026-04-14'), $plazo->limite(TipoDte::CreditoFiscal, '2026-03-19'));
        $this->assertFalse($plazo->calendarioCompleto(TipoDte::CreditoFiscal, '2026-03-19'));
    }

    /** Y al revés: un año del archivo sin días y marcado completo se puede desmarcar. */
    public function test_desmarcar_un_anio_del_archivo_sin_dias_no_lo_devuelve_al_archivo(): void
    {
        $admin = $this->admin();
        foreach (config('dte.invalidacion.dias_inhabiles.2026') as $fecha) {
            $this->actingAs($admin)
                ->delete(route('configuracion.fiscal.feriados.quitar'), ['anio' => 2026, 'fecha' => $fecha, 'confirmacion' => '1'])
                ->assertRedirect();
        }
        $this->assertSame(
            ['fechas' => [], 'completo' => true, 'en_configuracion' => true],
            app(CalendarioInhabiles::class)->delAnio('2026'),
        );

        $this->actingAs($admin)
            ->put(route('configuracion.fiscal.feriados.completo'), ['anio' => 2026])
            ->assertOk()
            ->assertSeeInOrder(['2026: completo', '2026: sin marcar']);
        $this->actingAs($admin)
            ->put(route('configuracion.fiscal.feriados.completo'), ['anio' => 2026, 'confirmacion' => '1'])
            ->assertRedirect();

        $this->assertSame(
            ['fechas' => [], 'completo' => false, 'en_configuracion' => true],
            app(CalendarioInhabiles::class)->delAnio('2026'),
        );
        $this->actingAs($admin)
            ->get(route('configuracion.fiscal.feriados', ['anio' => 2026]))
            ->assertOk()
            ->assertSee('0 días. Sin marcar como completo')
            ->assertDontSee('26 días');
    }

    // ---------------------------------------------------------------- completo

    public function test_marcar_el_anio_completo(): void
    {
        $this->cargar(['2027-05-01']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('configuracion.fiscal.feriados.completo'), ['anio' => 2027, 'completo' => '1'])
            ->assertOk()
            ->assertSee('Esto es lo que va a cambiar');

        $this->actingAs($admin)
            ->put(route('configuracion.fiscal.feriados.completo'), ['anio' => 2027, 'completo' => '1', 'confirmacion' => '1'])
            ->assertRedirect(route('configuracion.fiscal.feriados', ['anio' => 2027]));

        $this->assertSame(['2027'], Ajustes::lista(CalendarioInhabiles::COMPLETOS));
        $this->assertSame(CalendarioInhabiles::COMPLETOS, $this->ultimaAuditoria()->getExtraProperty('clave'));

        // Y desmarcarlo.
        $this->actingAs($admin)
            ->put(route('configuracion.fiscal.feriados.completo'), ['anio' => 2027, 'confirmacion' => '1'])
            ->assertRedirect();
        $this->assertSame([], Ajustes::lista(CalendarioInhabiles::COMPLETOS));
    }

    public function test_sin_cambios_no_guarda_nada(): void
    {
        $this->actingAs($this->admin())
            ->put(route('configuracion.fiscal.feriados.completo'), ['anio' => 2026, 'completo' => '1'])
            ->assertRedirect(route('configuracion.fiscal.feriados', ['anio' => 2026]))
            ->assertSessionHas('status', 'No había nada que cambiar en el calendario de 2026.');

        $this->assertDatabaseCount('ajustes_sistema', 0);
    }

    // ---------------------------------------------------------------- segunda barrera

    /** Cualquier otro llamador del registro tampoco puede guardar fechas inválidas o repetidas. */
    public function test_el_registro_rechaza_fechas_invalidas_o_repetidas_y_las_ordena(): void
    {
        $this->actingAs($this->admin());

        foreach ([['2027-02-30'], ['01/05/2027'], ['2027-05-01', '2027-05-01']] as $invalida) {
            try {
                Ajustes::guardar(CalendarioInhabiles::FECHAS, $invalida);
                $this->fail('Se aceptó '.json_encode($invalida));
            } catch (ValorAjusteInvalidoException) {
                $this->assertDatabaseCount('ajustes_sistema', 0);
            }
        }

        Ajustes::guardar(CalendarioInhabiles::FECHAS, ['2027-05-02', '2027-05-01']);
        $this->assertSame(['2027-05-01', '2027-05-02'], Ajustes::lista(CalendarioInhabiles::FECHAS));

        $this->expectException(ValorAjusteInvalidoException::class);
        Ajustes::guardar(CalendarioInhabiles::COMPLETOS, ['27']);
    }
}
