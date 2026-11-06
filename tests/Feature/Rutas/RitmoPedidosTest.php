<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\User;
use App\Services\Rutas\AvisoRutas;
use App\Services\Rutas\Ritmo;
use App\Services\Rutas\RitmoPedidos;
use App\Services\Rutas\RitmoRutas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El ritmo de compra de cada sala y de cada ruta, con los CCF como fuente
 * (sección 2 de docs/PLAN_TABLERO_RUTAS.md).
 */
class RitmoPedidosTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $hoy;

    private Ruta $ruta;

    private ClienteSucursal $sala;

    private int $correlativo = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rutas.ambiente_ccf' => null, 'dte.ambiente' => '01']);
        $this->hoy = CarbonImmutable::parse('2026-11-03');
        $this->travelTo($this->hoy->setTime(9, 0));

        $this->ruta = Ruta::create(['nombre' => 'Oriente']);
        $this->sala = $this->nuevaSala('Súper Ficticio Centro');
    }

    private function nuevaSala(string $nombre, ?Ruta $ruta = null): ClienteSucursal
    {
        return Cliente::factory()->create()->sucursales()->create(['nombre' => $nombre, 'ruta_id' => ($ruta ?? $this->ruta)->id, 'activo' => true]);
    }

    private function establecimiento(): Establecimiento
    {
        return Establecimiento::firstOr(function () {
            $empresa = Empresa::create(['razon_social' => 'Dulces Inventados', 'ambiente' => '01', 'activo' => true]);

            return Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        });
    }

    /** Un CCF aceptado de producción, emitido ese día. */
    private function ccf(string $fecha, ?ClienteSucursal $sala = null, array $extra = []): Dte
    {
        $sala ??= $this->sala;
        $this->correlativo++;

        return Dte::create($extra + [
            'establecimiento_id' => $this->establecimiento()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)),
            'fecha_procesamiento_mh' => now(),
            'cliente_id' => $sala->cliente_id,
            'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-'.str_pad((string) $this->correlativo, 15, '0', STR_PAD_LEFT),
            'fecha_emision' => $fecha,
            'hora_emision' => '10:00:00',
            'total_pagar' => 100.00,
        ]);
    }

    /** @param  array<int, string>  $fechas */
    private function ccfs(array $fechas, ?ClienteSucursal $sala = null): void
    {
        foreach ($fechas as $fecha) {
            $this->ccf($fecha, $sala);
        }
    }

    private function ritmoDeSala(?ClienteSucursal $sala = null): Ritmo
    {
        $sala ??= $this->sala;

        return app(RitmoPedidos::class)->porSala([$sala->id], $this->hoy)[$sala->id];
    }

    /** @param  array<int, string>  $fechas */
    private function ritmo(array $fechas): Ritmo
    {
        return app(RitmoPedidos::class)->deSala(array_map(fn ($f) => CarbonImmutable::parse($f), $fechas));
    }

    /** @return array<string, array> fila por nombre de ruta */
    private function filas(): array
    {
        return app(RitmoRutas::class)->porRuta($this->hoy)->keyBy(fn ($f) => $f['ruta']->nombre)->all();
    }

    // ══════════════════════════════ sala

    /** El ejemplo del plan: intervalos 14, 13, 15 y 14; la mediana es 14. */
    public function test_el_ritmo_es_la_mediana_de_los_intervalos(): void
    {
        $this->ccfs(['2026-09-01', '2026-09-15', '2026-09-28', '2026-10-13', '2026-10-27']);

        $ritmo = $this->ritmoDeSala();

        $this->assertSame(14, $ritmo->dias);
        $this->assertSame(Ritmo::CONFIABLE, $ritmo->confianza());
        $this->assertSame('2026-10-27', $ritmo->ultima()->toDateString());
        $this->assertSame(7, $ritmo->diasDesde($this->hoy));
        // Un mes raro no mueve la mediana.
        $this->assertSame(14, $this->ritmo(['2026-01-01', '2026-01-15', '2026-03-16', '2026-03-30', '2026-04-13'])->dias);
    }

    public function test_solo_cuentan_los_ultimos_diez_intervalos(): void
    {
        // Seis intervalos viejos de 30 días y diez nuevos de 7: manda la costumbre nueva.
        $fechas = [];
        $dia = CarbonImmutable::parse('2026-01-01');
        for ($i = 0; $i < 6; $i++) {
            $fechas[] = $dia->toDateString();
            $dia = $dia->addDays(30);
        }
        for ($i = 0; $i <= 10; $i++) {
            $fechas[] = $dia->toDateString();
            $dia = $dia->addDays(7);
        }

        $this->assertSame(7, $this->ritmo($fechas)->dias);
    }

    public function test_varios_ccf_del_mismo_dia_o_del_dia_siguiente_son_un_solo_pedido(): void
    {
        // Pedido del 01/10: dos CCF ese día y uno al día siguiente.
        $this->ccfs(['2026-10-01', '2026-10-01', '2026-10-02', '2026-10-15', '2026-10-29']);

        $ritmo = $this->ritmoDeSala();

        $this->assertSame(['2026-10-01', '2026-10-15', '2026-10-29'], array_map(fn ($f) => $f->toDateString(), $ritmo->fechas));
        $this->assertSame(14, $ritmo->dias);
    }

    public function test_confiable_aproximado_y_sin_ritmo_segun_los_pedidos(): void
    {
        $confiable = $this->ritmo(['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22']);
        $aprox = $this->ritmo(['2026-09-01', '2026-09-08', '2026-09-15']);
        $dos = $this->ritmo(['2026-09-01', '2026-09-08']);
        $ninguno = $this->ritmo([]);

        $this->assertSame([7, Ritmo::CONFIABLE], [$confiable->dias, $confiable->confianza()]);
        // Aproximado: se usa igual para avisar.
        $this->assertSame([7, Ritmo::APROXIMADO], [$aprox->dias, $aprox->confianza()]);
        $this->assertSame(Ritmo::ATRASADA, $aprox->estadoEn($this->hoy));
        $this->assertSame([null, Ritmo::SIN_RITMO, Ritmo::SIN_RITMO], [$dos->dias, $dos->confianza(), $dos->estadoEn($this->hoy)]);
        $this->assertSame([null, null], [$ninguno->ultima(), $ninguno->diasDesde($this->hoy)]);
    }

    public function test_el_margen_es_el_veinte_por_ciento_y_como_minimo_dos_dias(): void
    {
        $this->assertSame(2, Ritmo::margen(7));   // 1.4 → mínimo 2
        $this->assertSame(3, Ritmo::margen(14));  // 2.8 → 3
        $this->assertSame(6, Ritmo::margen(30));

        // Compra cada 7: al día hasta faltar 3; toca pronto desde faltar 2 hasta 2 días tarde.
        $this->assertSame(Ritmo::AL_DIA, Ritmo::estado(7, 4));
        $this->assertSame(Ritmo::PRONTO, Ritmo::estado(7, 5));
        $this->assertSame(Ritmo::PRONTO, Ritmo::estado(7, 9));
        $this->assertSame(Ritmo::ATRASADA, Ritmo::estado(7, 10));

        // El ejemplo del plan: cada 14, último pedido hace 18 → 4 tarde, más que el margen de 3.
        $this->assertSame(Ritmo::PRONTO, Ritmo::estado(14, 17));
        $this->assertSame(Ritmo::ATRASADA, Ritmo::estado(14, 18));
    }

    public function test_los_ccf_que_no_estan_aceptados_de_verdad_no_cuentan(): void
    {
        $this->ccfs(['2026-09-29', '2026-10-06', '2026-10-13']);
        $this->ccf('2026-10-20', null, ['estado' => 'invalidado']);
        $this->ccf('2026-10-21', null, ['archivado' => true]);
        $this->ccf('2026-10-22', null, ['ambiente' => '00']);
        $this->ccf('2026-10-23', null, ['estado' => 'borrador', 'sello_recepcion' => null]);
        $this->ccf('2026-10-24', null, ['sello_recepcion' => 'MOCK-123']);
        // Más de 12 meses atrás.
        $this->ccf('2025-10-01');

        $ritmo = $this->ritmoDeSala();

        $this->assertSame(['2026-09-29', '2026-10-06', '2026-10-13'], array_map(fn ($f) => $f->toDateString(), $ritmo->fechas));
        $this->assertSame(7, $ritmo->dias);
    }

    public function test_pregunta_si_dejo_de_comprar_pasado_tres_veces_su_ritmo(): void
    {
        $ritmo = $this->ritmo(['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22']); // cada 7

        $this->assertFalse($ritmo->dejoDeComprar(CarbonImmutable::parse('2026-10-13'))); // 21 días: justo 3 veces
        $this->assertTrue($ritmo->dejoDeComprar(CarbonImmutable::parse('2026-10-14')));  // 22 días
        $this->assertFalse($this->ritmo(['2026-01-01'])->dejoDeComprar($this->hoy));      // sin ritmo no pregunta
    }

    // ══════════════════════════════ ruta

    public function test_la_ruta_junta_los_pedidos_de_sus_salas_en_vueltas(): void
    {
        $otra = $this->nuevaSala('Tienda La Inventada');

        // Cuatro vueltas, cada una de varios días (cada sala pide un día distinto).
        $this->ccfs(['2026-09-01', '2026-09-11', '2026-09-21', '2026-10-01']);
        $this->ccfs(['2026-09-03', '2026-09-14', '2026-09-23', '2026-10-04'], $otra);

        $pedidos = app(RitmoPedidos::class);
        $ritmo = $pedidos->deRuta($pedidos->porSala([$this->sala->id, $otra->id], $this->hoy));

        $this->assertSame(['2026-09-01', '2026-09-11', '2026-09-21', '2026-10-01'], array_map(fn ($f) => $f->toDateString(), $ritmo->fechas));
        $this->assertSame([10, Ritmo::CONFIABLE], [$ritmo->dias, $ritmo->confianza()]);
    }

    public function test_un_hueco_de_mas_de_tres_dias_empieza_otra_vuelta(): void
    {
        $pedidos = app(RitmoPedidos::class);
        $vueltas = fn (array $fechas) => array_map(
            fn ($f) => $f->toDateString(),
            $pedidos->deRuta([new Ritmo(array_map(fn ($f) => CarbonImmutable::parse($f), $fechas))])->fechas,
        );

        // 3 días entre uno y otro: la misma vuelta, aunque dure una semana.
        $this->assertSame(['2026-09-01'], $vueltas(['2026-09-01', '2026-09-04', '2026-09-07']));
        // 4 días: otra vuelta.
        $this->assertSame(['2026-09-01', '2026-09-05'], $vueltas(['2026-09-01', '2026-09-05']));
    }

    /** Encargo del tablero para jefes (06/11/2026): los días cargados en la ruta mandan. */
    public function test_los_dias_cargados_a_mano_mandan_sobre_el_ritmo_calculado(): void
    {
        $this->ruta->update(['frecuencia_objetivo_dias' => 30]);
        // Vueltas cada 10 días; la última salida volvió hace 13 días: con el calculado serían
        // 3 tarde, pero la ruta dice cada 30: faltan 17.
        $this->ccfs(['2026-09-21', '2026-10-01', '2026-10-11', '2026-10-21']);
        SalidaRuta::create(['ruta_id' => $this->ruta->id, 'fecha_inicio' => '2026-10-20', 'fecha_fin_real' => '2026-10-21', 'estado' => EstadoSalidaRuta::Finalizada]);

        $fila = $this->filas()['Oriente'];

        $this->assertSame([30, RitmoRutas::MANUAL], [$fila['ritmo'], $fila['origenRitmo']]);
        $this->assertSame([13, 17, RitmoRutas::AL_DIA], [$fila['dias'], $fila['faltan'], $fila['estado']]);
        $this->assertArrayHasKey($this->sala->id, $fila['ritmoSalas']);

        $this->ruta->update(['frecuencia_objetivo_dias' => null]);
        $fila = $this->filas()['Oriente'];

        $this->assertSame([10, Ritmo::CONFIABLE], [$fila['ritmo'], $fila['origenRitmo']]);
        $this->assertSame([13, -3, RitmoRutas::ATRASADA], [$fila['dias'], $fila['faltan'], $fila['estado']]);
    }

    public function test_sin_ritmo_se_usan_los_dias_cargados_a_mano_y_sin_salidas_la_ultima_vuelta(): void
    {
        $this->ruta->update(['frecuencia_objetivo_dias' => 7]);
        // Dos vueltas: todavía sin ritmo. Nunca hubo salida: se cuenta desde la última vuelta.
        $this->ccfs(['2026-10-15', '2026-10-25']);

        $fila = $this->filas()['Oriente'];

        $this->assertSame([7, RitmoRutas::MANUAL], [$fila['ritmo'], $fila['origenRitmo']]);
        $this->assertSame([9, RitmoRutas::PRONTO], [$fila['dias'], $fila['estado']]);
    }

    public function test_el_aviso_del_panel_principal_usa_el_ritmo_calculado(): void
    {
        // La ruta no tiene días cargados: antes no avisaba nunca.
        $this->ccfs(['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22']);

        $aviso = app(AvisoRutas::class)->para(User::factory()->create()->assignRole('facturacion'));

        $this->assertCount(1, $aviso);
        $this->assertSame(['Oriente', RitmoRutas::ATRASADA], [$aviso[0]['ruta'], $aviso[0]['estado']]);
        $this->assertSame('35 días tarde', $aviso[0]['texto']);
    }
}
