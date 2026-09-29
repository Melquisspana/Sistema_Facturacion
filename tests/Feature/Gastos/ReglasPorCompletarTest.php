<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Ocurrencia;
use App\Models\Gastos\Regla;
use App\Models\User;
use App\Services\Gastos\PanelGastos;
use App\Services\Gastos\Recurrencia\AdministrarReglas;
use App\Services\Gastos\Recurrencia\CalendarioRecurrencia;
use App\Services\Gastos\Recurrencia\GenerarObligaciones;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Las pantallas de «Gastos que se repiten» con reglas en los cuatro estados.
 *
 * ═══════ De dónde sale este archivo ═══════
 *
 * Agregar el estado «borrador» al modelo rompió el listado Y el detalle con un 500:
 * la vista resolvía la insignia con `$insignia[$regla->estado]`, un acceso directo a un
 * mapa que solo tenía tres estados. Nada lo avisó —ni el compilador, ni las pruebas—
 * hasta que alguien abrió la pantalla.
 *
 * La lección es que un `match` o un mapa por estado es una lista que hay que mantener, y
 * la única forma de que no se olvide es que una prueba abra las pantallas con TODOS los
 * estados. Eso es lo que hace este archivo, y por eso recorre los cuatro y no solo el
 * que se agregó.
 */
class ReglasPorCompletarTest extends TestCase
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

    private function gestor(): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions(array_map(fn ($p) => $p->value, [
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar,
            PermisoSistema::GastosRecurrencias, PermisoSistema::GastosPagosRegistrar,
            PermisoSistema::DteVer, PermisoSistema::DashboardVer,
        ]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** @param  array<string, mixed>  $extra */
    private function regla(User $u, string $nombre, array $extra = []): Regla
    {
        return app(AdministrarReglas::class)->crear($u, array_merge([
            'clave' => (string) Str::uuid(), 'nombre' => $nombre,
            'beneficiario' => $nombre, 'concepto' => 'Servicio',
            'categoria' => 'Servicios', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'documentacion' => 'pendiente', 'responsable_id' => $u->id,
            'monto_modo' => 'fijo', 'importe' => '14.00', 'frecuencia' => 'mensual',
            'dias_generar_antes' => 5, 'vigente_desde' => now()->toDateString(),
        ], $extra));
    }

    // ═══════════ El caso que rompió ═══════════

    public function test_el_listado_abre_con_una_activa_y_una_por_completar(): void
    {
        $u = $this->gestor();
        $this->regla($u, 'Starlink', ['dia_mes' => 28, 'importe' => '48.00']);
        $this->regla($u, 'Google One', ['incompleta' => true]);

        $r = $this->actingAs($u)->get(route('gastos.reglas.index'))->assertOk();

        // Las dos aparecen, cada una con su estado dicho en palabras.
        $r->assertSee('Starlink')->assertSee('Activa');
        $r->assertSee('Google One')->assertSee('Por completar');
    }

    public function test_las_dos_se_pueden_abrir(): void
    {
        $u = $this->gestor();
        $activa = $this->regla($u, 'Starlink', ['dia_mes' => 28, 'importe' => '48.00']);
        $borrador = $this->regla($u, 'Google One', ['incompleta' => true]);

        $this->actingAs($u)->get(route('gastos.reglas.show', $activa))->assertOk()
            ->assertSee('Starlink')
            ->assertSee('Activa');

        $r = $this->actingAs($u)->get(route('gastos.reglas.show', $borrador))->assertOk();

        $r->assertSee('Google One');
        $r->assertSee('Por completar');
        // Dice QUÉ le falta, y ofrece completarlo.
        $r->assertSee('Falta completar', false);
        $r->assertSee('todavía no sabe qué día', false);
        $r->assertSee('Completar y activar', false);
    }

    public function test_el_detalle_por_completar_no_promete_vencimientos(): void
    {
        $u = $this->gestor();
        $borrador = $this->regla($u, 'Google One', ['incompleta' => true]);

        $r = $this->actingAs($u)->get(route('gastos.reglas.show', $borrador))->assertOk();

        // Una regla que no genera nada no puede anunciar «el próximo vence»: sería
        // prometer una obligación que nunca va a existir.
        $r->assertDontSee('El próximo vence', false);
        // Tampoco ofrece generar.
        $r->assertDontSee('Crear los que ya tocan', false);
    }

    // ═══════════ Los cuatro estados, para que no vuelva a pasar ═══════════

    public function test_las_pantallas_abren_con_reglas_en_todos_los_estados(): void
    {
        $u = $this->gestor();

        $reglas = [
            'borrador' => $this->regla($u, 'Por completar', ['incompleta' => true]),
            'activa' => $this->regla($u, 'Activa', ['dia_mes' => 10]),
            'pausada' => $this->regla($u, 'Pausada', ['dia_mes' => 11]),
            'cancelada' => $this->regla($u, 'Cancelada', ['dia_mes' => 12]),
        ];

        app(AdministrarReglas::class)->pausar($u, $reglas['pausada'], 'Pausada para la prueba.');
        app(AdministrarReglas::class)->cancelar($u, $reglas['cancelada'], 'Cancelada para la prueba.');

        // Cada estado del enum tiene que poder dibujarse. Si mañana se agrega otro y
        // nadie toca el mapa de insignias, esta prueba lo caza antes que un usuario.
        $this->assertSame(
            array_keys(Regla::ESTADOS),
            array_keys($reglas),
            'Hay un estado sin cubrir en esta prueba.'
        );

        $this->actingAs($u)->get(route('gastos.reglas.index'))->assertOk();

        foreach ($reglas as $estado => $regla) {
            $this->actingAs($u)
                ->get(route('gastos.reglas.show', $regla->fresh()))
                ->assertOk()
                ->assertSee(Regla::ESTADOS[$estado]);
        }
    }

    // ═══════════ Completar desde la pantalla ═══════════

    public function test_completar_desde_el_detalle_la_activa(): void
    {
        $u = $this->gestor();
        $borrador = $this->regla($u, 'Google One', ['incompleta' => true]);

        $this->actingAs($u)
            ->post(route('gastos.reglas.activar', $borrador), ['dia_mes' => 14])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $activa = $borrador->fresh();
        $this->assertSame('activa', $activa->estado);
        $this->assertSame(14, $activa->dia_mes);

        // Y ahora sí dice cuándo cae el próximo.
        $this->actingAs($u)->get(route('gastos.reglas.show', $activa))->assertOk()
            ->assertSee('El próximo vence', false)
            ->assertDontSee('Falta completar', false);
    }

    // ═══════════ Completar una regla con fecha final ═══════════

    /**
     * Una regla con fecha de fin no se podía activar. Nunca.
     *
     * El formulario mandaba bien su parte, pero el servicio rearmaba el resto de la
     * regla con `Regla::only()`, que devuelve los atributos YA CASTEADOS: `vigente_hasta`
     * salía como Carbon, y `date_format:Y-m-d` rechaza todo lo que no sea string o
     * número. Resultado: «The vigente hasta field must match the format Y-m-d» sobre una
     * fecha guardada correctamente, y ninguna forma de activarla desde la pantalla.
     *
     * Sin fecha de fin el valor era null y `nullable` lo dejaba pasar, así que el módulo
     * entero parecía sano: el fallo solo aparecía en las reglas con final, que son
     * justamente las que más importa no perder —las que tienen un número de cuotas.
     */
    public function test_completar_una_regla_con_fecha_final_no_falla_por_el_formato(): void
    {
        $u = $this->gestor();
        $regla = $this->regla($u, 'Universidad', [
            'beneficiario' => 'Universidad (nombre por confirmar)',
            'importe' => '110.00', 'dia_mes' => 15, 'incompleta' => true,
            'vigente_desde' => '2026-09-01', 'vigente_hasta' => '2026-10-15',
        ]);

        $this->actingAs($u)
            ->post(route('gastos.reglas.activar', $regla), ['dia_mes' => 15])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $activa = $regla->fresh();
        $this->assertSame('activa', $activa->estado);
        // Y el límite sigue ahí: es lo que impide que se genere noviembre.
        $this->assertSame('2026-10-15', $activa->vigente_hasta->toDateString());
    }

    /** La validación NO se aflojó: una fecha mal formada sigue siendo un error. */
    public function test_una_fecha_final_invalida_sigue_siendo_rechazada(): void
    {
        $u = $this->gestor();
        $regla = $this->regla($u, 'Cualquiera', ['dia_mes' => 15]);

        $this->expectException(ValidationException::class);

        app(AdministrarReglas::class)->actualizar($u, $regla, [
            'clave' => $regla->clave, 'nombre' => 'Cualquiera', 'beneficiario' => 'Cualquiera',
            'concepto' => 'Servicio', 'categoria' => 'Servicios', 'ambito' => 'empresarial',
            'naturaleza' => 'operativo', 'moneda' => 'USD', 'documentacion' => 'pendiente',
            'responsable_id' => $u->id, 'monto_modo' => 'fijo', 'importe' => '14.00',
            'frecuencia' => 'mensual', 'dia_mes' => 15, 'dias_generar_antes' => 5,
            'vigente_desde' => '2026-09-01', 'vigente_hasta' => '15/10/2026',
        ], 'Prueba de formato de fecha.');
    }

    /**
     * Completar NO mueve la vigencia, y por eso no se pierde el primer período.
     *
     * Activar reescribía `vigente_desde` a HOY. Con una regla guardada el 1 de septiembre
     * y completada el 16, septiembre desaparecía sin que nada lo dijera: quedaba una sola
     * mensualidad de las dos acordadas. El resguardo contra generar meses viejos de golpe
     * no vive acá —vive en la ventana de recuperación de GenerarObligaciones—, así que
     * mover la fecha no protegía de nada y sí borraba un período real.
     */
    public function test_completar_conserva_la_vigencia_y_los_dos_periodos_acordados(): void
    {
        $u = $this->gestor();
        $this->travelTo(CarbonImmutable::parse('2026-09-16 10:00:00'));

        $regla = $this->regla($u, 'Universidad', [
            'importe' => '110.00', 'dia_mes' => 15, 'incompleta' => true,
            'vigente_desde' => '2026-09-01', 'vigente_hasta' => '2026-10-15',
        ]);

        $this->actingAs($u)
            ->post(route('gastos.reglas.activar', $regla), ['dia_mes' => 15])
            ->assertRedirect()->assertSessionHasNoErrors();

        $activa = $regla->fresh();
        $this->assertSame('2026-09-01', $activa->vigente_desde->toDateString());

        // Activar no crea deuda: eso lo pide una persona, después.
        $this->assertSame(0, Ocurrencia::where('regla_id', $activa->id)->count());

        // Septiembre sigue en pie y se genera cuando se lo pide.
        $generador = app(GenerarObligaciones::class);
        $septiembre = $generador->paraRegla($activa, CarbonImmutable::parse('2026-09-16'), $u);
        $this->assertSame(['2026-09'], $septiembre['periodos']);
        $this->assertSame([], $septiembre['fuera_de_ventana']);

        // Octubre, cuando toca.
        $octubre = $generador->paraRegla($activa->fresh(), CarbonImmutable::parse('2026-10-10'), $u);
        $this->assertSame(['2026-10'], $octubre['periodos']);

        // Y noviembre NO: para eso estaba la fecha de fin que el error impedía guardar.
        $noviembre = $generador->paraRegla($activa->fresh(), CarbonImmutable::parse('2026-11-12'), $u);
        $this->assertSame([], $noviembre['periodos']);

        $this->assertSame(
            ['2026-09', '2026-10'],
            Ocurrencia::where('regla_id', $activa->id)->orderBy('periodo')->pluck('periodo')->all(),
        );
    }

    // ═══════════ Cuando lo que falta NO es el día ═══════════

    /** @param  array<string, mixed>  $extra */
    private function reglaConNombrePendiente(User $u, array $extra = []): Regla
    {
        return $this->regla($u, 'Universidad', array_merge([
            'beneficiario' => 'Universidad (nombre por confirmar)',
            'importe' => '110.00', 'dia_mes' => 15, 'incompleta' => true,
            'observaciones' => 'Falta el nombre de la institución.',
        ], $extra));
    }

    /**
     * El detalle decía SIEMPRE que faltaba el día de cobro, aun con el día guardado.
     *
     * Era un texto fijo dentro del bloque «Falta completar», no una lectura de la regla.
     * Reclamar un dato que sí está enseña a ignorar el cartel, y el día que falte de
     * verdad nadie va a mirarlo.
     */
    public function test_el_detalle_no_reclama_el_dia_cuando_la_regla_ya_lo_tiene(): void
    {
        $u = $this->gestor();
        $regla = $this->reglaConNombrePendiente($u);

        $r = $this->actingAs($u)->get(route('gastos.reglas.show', $regla))->assertOk();

        $r->assertSee('Falta completar', false);
        $r->assertDontSee('todavía no sabe qué día', false);
        // Dice lo que SÍ está, y muestra el pendiente real tal como quedó anotado.
        $r->assertSee('ya están', false);
        $r->assertSee('Falta el nombre de la institución.', false);
    }

    /** Y el panel dice lo mismo: las dos pantallas leen la misma lista. */
    public function test_el_panel_y_el_detalle_coinciden_en_lo_que_falta(): void
    {
        $u = $this->gestor();
        $conDia = $this->reglaConNombrePendiente($u);
        $sinDia = $this->regla($u, 'Agua de fábrica', ['monto_modo' => 'variable', 'incompleta' => true]);

        $this->assertSame([], $conDia->faltantes());
        $this->assertSame(['el día de cobro'], $sinDia->faltantes());

        $porRegla = app(PanelGastos::class)->porCompletar($u, [])->keyBy('regla_id');

        $this->assertSame('un dato por confirmar', $porRegla[$conDia->id]->falta);
        $this->assertSame('el día de cobro', $porRegla[$sinDia->id]->falta);
    }

    /**
     * El nombre se corrige DESDE la pantalla y SOBRE la misma regla.
     *
     * Sin campo para el beneficiario, completar una regla «nombre por confirmar» obligaba
     * a irse a Editar —o, peor, a crear otra regla al lado, que es como aparecen dos
     * universidades cobrando la misma cuota—.
     */
    public function test_completar_corrige_el_nombre_sin_duplicar_la_regla(): void
    {
        $u = $this->gestor();
        $regla = $this->reglaConNombrePendiente($u);
        $antes = Regla::count();

        $this->actingAs($u)
            ->post(route('gastos.reglas.activar', $regla), [
                'dia_mes' => 15,
                'beneficiario' => 'Universidad Don Bosco',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $activa = $regla->fresh();
        $this->assertSame('Universidad Don Bosco', $activa->beneficiario);
        $this->assertSame('activa', $activa->estado);
        $this->assertSame($antes, Regla::count(), 'Completar no puede crear una regla nueva.');

        // El cambio queda versionado: la versión anterior conserva el nombre viejo.
        $this->assertSame(2, $activa->version);
        $this->assertSame(
            'Universidad (nombre por confirmar)',
            $activa->versiones()->where('version', 1)->value('datos')['beneficiario'],
        );
    }

    /** Sin beneficiario no se activa: el campo es obligatorio, no decorativo. */
    public function test_completar_con_el_nombre_en_blanco_no_activa_nada(): void
    {
        $u = $this->gestor();
        $regla = $this->reglaConNombrePendiente($u);

        $this->actingAs($u)
            ->post(route('gastos.reglas.activar', $regla), ['dia_mes' => 15, 'beneficiario' => ''])
            ->assertSessionHasErrors('beneficiario');

        $this->assertSame('borrador', $regla->fresh()->estado);
    }

    public function test_una_quincenal_por_completar_pide_sus_dos_fechas(): void
    {
        $u = $this->gestor();
        $borrador = $this->regla($u, 'Quincenal', ['frecuencia' => 'quincenal', 'incompleta' => true]);

        $r = $this->actingAs($u)->get(route('gastos.reglas.show', $borrador))->assertOk();
        $r->assertSee('Primera fecha del mes', false);
        $r->assertSee('Segunda fecha del mes', false);

        $this->actingAs($u)
            ->post(route('gastos.reglas.activar', $borrador), ['dia_mes' => 15, 'dia_mes_2' => 30])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('activa', $borrador->fresh()->estado);
    }

    /**
     * La rama anual usa `CalendarioRecurrencia::nombreMes`, que era PRIVADO.
     *
     * La pantalla habría reventado al abrir una regla anual a medio llenar, y no se
     * habría notado con una mensual: cada frecuencia dibuja campos distintos.
     */
    public function test_una_anual_por_completar_pide_dia_y_mes(): void
    {
        $u = $this->gestor();
        $borrador = $this->regla($u, 'Anual', ['frecuencia' => 'anual', 'incompleta' => true]);

        $r = $this->actingAs($u)->get(route('gastos.reglas.show', $borrador))->assertOk();
        $r->assertSee('¿Qué mes?', false);
        // En minúscula: es como los nombra `CalendarioRecurrencia`.
        $r->assertSee('septiembre');

        $this->actingAs($u)
            ->post(route('gastos.reglas.activar', $borrador), ['dia_mes' => 5, 'mes' => 3])
            ->assertRedirect()->assertSessionHasNoErrors();

        $activa = $borrador->fresh();
        $this->assertSame('activa', $activa->estado);
        $this->assertSame(3, $activa->mes);
    }

    public function test_una_semanal_por_completar_pide_el_dia_de_la_semana(): void
    {
        $u = $this->gestor();
        $borrador = $this->regla($u, 'Semanal', ['frecuencia' => 'semanal', 'incompleta' => true]);

        $r = $this->actingAs($u)->get(route('gastos.reglas.show', $borrador))->assertOk();
        $r->assertSee('¿Qué día de la semana?', false);

        $this->actingAs($u)
            ->post(route('gastos.reglas.activar', $borrador), ['dia_semana' => 3])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('activa', $borrador->fresh()->estado);
    }

    // ═══════════ El texto del calendario ═══════════

    public function test_el_calendario_incompleto_dice_que_falta_el_dia(): void
    {
        $calendario = app(CalendarioRecurrencia::class);

        // «Cada mes, el día » —con el hueco al final— parece un error de programa.
        $this->assertSame('Cada mes, falta el día', $calendario->enPalabras([
            'frecuencia' => 'mensual', 'dia_semana' => null, 'dia_mes' => null,
            'dia_mes_2' => null, 'mes' => null,
        ]));

        // Y con el día puesto, lo de siempre.
        $this->assertSame('Cada mes, el día 28', $calendario->enPalabras([
            'frecuencia' => 'mensual', 'dia_semana' => null, 'dia_mes' => 28,
            'dia_mes_2' => null, 'mes' => null,
        ]));
    }
}
