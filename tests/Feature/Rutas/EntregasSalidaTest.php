<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\MotivoNoEntrega;
use App\Enums\OrigenRegistroEntrega;
use App\Enums\ResultadoEntrega;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PersonalRuta;
use App\Models\PpqAlbaran;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaEntrega;
use App\Models\User;
use App\Services\Rutas\EntregasCcf;
use App\Services\Rutas\ParticipantesSalida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CcfDeLaRuta;
use Tests\TestCase;

/**
 * Etapa 2: qué CCF lleva cada salida y qué pasó con cada uno.
 */
class EntregasSalidaTest extends TestCase
{
    use CcfDeLaRuta;
    use RefreshDatabase;

    private Ruta $ruta;

    private ClienteSucursal $sala;

    private PersonalRuta $carlos;

    private int $correlativo = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Como el servidor de producción: ambiente operativo 01.
        config(['rutas.entregas_desde' => '2026-09-28', 'rutas.ambiente_ccf' => null, 'dte.ambiente' => '01']);
        $this->travelTo('2026-10-05 09:00:00');

        $this->ruta = Ruta::create(['nombre' => 'San Miguel']);
        $this->sala = Cliente::factory()->create(['nombre' => 'Calleja'])
            ->sucursales()->create(['nombre' => 'Selectos San Miguel', 'codigo' => '0232', 'ruta_id' => $this->ruta->id]);
        $this->carlos = PersonalRuta::create(['nombre' => 'Carlos']);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    private function establecimiento(): Establecimiento
    {
        return Establecimiento::firstOr(function () {
            $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '01', 'activo' => true]);

            return Establecimiento::create(['empresa_id' => $empresa->id, 'codigo' => 'M001', 'nombre' => 'Casa Matriz', 'activo' => true]);
        });
    }

    /** Un CCF real de producción. `$extra` permite volverlo de pruebas, borrador, etc. */
    private function ccf(array $extra = [], ?ClienteSucursal $sala = null): Dte
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
            'numero_orden_compra' => '2606023200'.str_pad((string) $this->correlativo, 5, '0', STR_PAD_LEFT),
            'fecha_emision' => '2026-10-01',
            'hora_emision' => '10:00:00',
            'total_pagar' => 100.00,
        ]);
    }

    private function salida(EstadoSalidaRuta $estado = EstadoSalidaRuta::EnCurso, ?Ruta $ruta = null): SalidaRuta
    {
        $salida = SalidaRuta::create([
            'ruta_id' => ($ruta ?? $this->ruta)->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => $estado,
        ]);
        app(ParticipantesSalida::class)->sincronizar($salida, [$this->carlos->id], $this->carlos->id);

        return $salida->load('ruta');
    }

    private function servicio(): EntregasCcf
    {
        return app(EntregasCcf::class);
    }

    private function pendientes(): array
    {
        return $this->pendientesDeLaRuta($this->ruta)->pluck('id')->all();
    }

    private function entregar(SalidaRutaEntrega $entrega): void
    {
        $this->servicio()->registrar($entrega, [
            'resultado' => 'entregado',
            'entregado_por_id' => $this->carlos->id,
        ], null, OrigenRegistroEntrega::Oficina);
    }

    // ══════════════════════════════ qué está pendiente

    public function test_solo_cuentan_los_ccf_vigentes_desde_la_fecha_de_corte(): void
    {
        $bueno = $this->ccf();
        $this->ccf(['fecha_emision' => '2026-09-27']);                  // antes del corte
        $this->ccf(['ambiente' => '00']);                               // pruebas
        $this->ccf(['estado' => 'borrador', 'sello_recepcion' => null]); // borrador
        $this->ccf(['sello_recepcion' => 'MOCK-1234567890']);          // sello simulado
        $this->ccf(['archivado' => true]);                              // archivado
        $this->ccf(['tipo_dte' => '05']);                               // NC: no se entrega

        $this->assertSame([$bueno->id], $this->pendientes());
    }

    /** En desarrollo (ambiente 00) cuentan los CCF de pruebas, para poder probar el módulo. */
    public function test_en_desarrollo_cuentan_los_ccf_del_ambiente_de_pruebas(): void
    {
        config(['dte.ambiente' => '00']);
        $dePruebas = $this->ccf(['ambiente' => '00']);
        $this->ccf(); // producción: no es de esta instalación

        $this->assertSame([$dePruebas->id], $this->pendientes());
    }

    /** Para probar sobre una copia de producción en desarrollo, sin tocar el ambiente fiscal. */
    public function test_el_ambiente_de_los_ccf_se_puede_fijar_aparte(): void
    {
        config(['dte.ambiente' => '00', 'rutas.ambiente_ccf' => '01']);
        $real = $this->ccf();
        $this->ccf(['ambiente' => '00']);

        $this->assertSame([$real->id], $this->pendientes());
    }

    public function test_solo_las_salas_activas_de_la_ruta(): void
    {
        $otraRuta = Ruta::create(['nombre' => 'Sonsonate']);
        $deOtra = Cliente::first()->sucursales()->create(['nombre' => 'Selectos Sonsonate', 'ruta_id' => $otraRuta->id]);
        $inactiva = Cliente::first()->sucursales()->create(['nombre' => 'Selectos cerrada', 'ruta_id' => $this->ruta->id, 'activo' => false]);

        $propio = $this->ccf();
        $this->ccf([], $deOtra);
        $this->ccf([], $inactiva);

        $this->assertSame([$propio->id], $this->pendientes());
    }

    public function test_entregado_deja_de_ser_pendiente(): void
    {
        $dte = $this->ccf();
        $salida = $this->salida();
        $this->cargarCcfDeLaRuta($salida);

        $this->entregar($salida->entregas()->sole());

        $this->assertSame([], $this->pendientes());
    }

    public function test_el_albaran_de_entrega_cuenta_como_entregado(): void
    {
        $dte = $this->ccf();
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0232/00/6715',
            'numero_orden_compra' => $dte->numero_orden_compra,
            'monto_albaran' => 100,
            'fecha_albaran' => '2026-10-03',
            'origen' => 'gmail',
        ]);

        $this->assertSame([], $this->pendientes());
    }

    public function test_un_albaran_de_averia_no_prueba_la_entrega(): void
    {
        $dte = $this->ccf();
        PpqAlbaran::create([
            'numero_albaran' => 'AC02/0232/00/6836',
            'numero_orden_compra' => $dte->numero_orden_compra,
            'monto_albaran' => 2.89,
            'fecha_albaran' => '2026-10-03',
            'origen' => 'gmail',
        ]);

        $this->assertSame([$dte->id], $this->pendientes());
    }

    public function test_un_ccf_sin_registrar_en_otra_salida_abierta_no_se_carga_dos_veces(): void
    {
        $this->ccf();
        $primera = $this->salida(EstadoSalidaRuta::Planificada);
        $segunda = $this->salida(EstadoSalidaRuta::Planificada);

        $this->assertSame(1, $this->cargarCcfDeLaRuta($primera));
        $this->assertSame(0, $this->cargarCcfDeLaRuta($segunda));
    }

    public function test_lo_no_entregado_vuelve_en_la_siguiente_salida(): void
    {
        $dte = $this->ccf();
        $primera = $this->salida();
        $this->cargarCcfDeLaRuta($primera);

        $this->servicio()->registrar($primera->entregas()->sole(), [
            'resultado' => 'no_entregado',
            'motivo_no_entrega' => MotivoNoEntrega::SinTiempo->value,
        ], null, OrigenRegistroEntrega::Oficina);
        $primera->finalizar();

        $segunda = $this->salida(EstadoSalidaRuta::Planificada);
        $this->assertSame(1, $this->cargarCcfDeLaRuta($segunda));

        // Quedan los dos intentos: la historia no se pisa.
        $this->assertSame(2, SalidaRutaEntrega::where('dte_id', $dte->id)->count());
    }

    public function test_lo_que_quedo_sin_registrar_vuelve_al_finalizar(): void
    {
        $this->ccf();
        $primera = $this->salida();
        $this->cargarCcfDeLaRuta($primera);
        $primera->finalizar();

        $this->assertSame(1, $this->cargarCcfDeLaRuta($this->salida(EstadoSalidaRuta::Planificada)));
    }

    // ══════════════════════════════ registrar

    public function test_no_se_registra_en_una_salida_finalizada(): void
    {
        $this->ccf();
        $salida = $this->salida();
        $this->cargarCcfDeLaRuta($salida);
        $entrega = $salida->entregas()->sole();
        $salida->finalizar();
        $this->actingAs($this->admin())->patch(route('rutas.salidas.entregas.update', [$salida, $entrega]), [
            'resultado' => 'entregado', 'entregado_por_id' => $this->carlos->id,
        ])->assertSessionHasErrors('salida');
        $this->assertTrue($entrega->refresh()->estaPendiente());
    }

    public function test_no_entregado_requiere_deshacer_antes_de_entregar(): void
    {
        $this->ccf();
        $salida = $this->salida();
        $this->cargarCcfDeLaRuta($salida);
        $entrega = $salida->entregas()->sole();
        $this->servicio()->registrar($entrega, ['resultado' => 'no_entregado', 'motivo_no_entrega' => MotivoNoEntrega::SinTiempo->value], null, OrigenRegistroEntrega::Oficina);
        $url = route('rutas.salidas.entregas.update', [$salida, $entrega]);
        $datos = ['resultado' => 'entregado', 'entregado_por_id' => $this->carlos->id];
        $this->actingAs($this->admin())->patch($url, $datos)->assertSessionHasErrors('entrega');
        $this->assertSame(ResultadoEntrega::NoEntregado, $entrega->refresh()->resultado);
        $this->servicio()->deshacer($entrega);
        $this->patch($url, $datos)->assertSessionHas('status');
        $this->assertSame(ResultadoEntrega::Entregado, $entrega->refresh()->resultado);
    }

    public function test_no_se_registra_dos_veces_entregado(): void
    {
        $this->ccf();
        $salida = $this->salida();
        $this->cargarCcfDeLaRuta($salida);
        $entrega = $salida->entregas()->sole();
        $this->entregar($entrega);
        $this->actingAs($this->admin())->patch(route('rutas.salidas.entregas.update', [$salida, $entrega]), [
            'resultado' => 'entregado', 'entregado_por_id' => $this->carlos->id,
        ])->assertSessionHasErrors('entrega');
    }

    public function test_no_se_entrega_un_ccf_ya_entregado_en_otra_salida(): void
    {
        $this->ccf();
        $primera = $this->salida();
        $this->cargarCcfDeLaRuta($primera);
        $entrega = $primera->entregas()->sole();
        $this->servicio()->registrar($entrega, ['resultado' => 'no_entregado', 'motivo_no_entrega' => MotivoNoEntrega::SinTiempo->value], null, OrigenRegistroEntrega::Oficina);
        $segunda = $this->salida(EstadoSalidaRuta::Planificada);
        $this->cargarCcfDeLaRuta($segunda);
        $segunda->update(['estado' => EstadoSalidaRuta::EnCurso]);
        $this->entregar($segunda->entregas()->sole());
        $this->servicio()->deshacer($entrega);
        $this->actingAs($this->admin())->patch(route('rutas.salidas.entregas.update', [$primera, $entrega]), [
            'resultado' => 'entregado', 'entregado_por_id' => $this->carlos->id,
        ])->assertSessionHasErrors(['entrega' => 'Ese CCF ya consta como entregado en otra salida.']);
        $this->assertTrue($entrega->refresh()->estaPendiente());
    }

    public function test_deshacer_no_deja_pendiente_en_dos_salidas_abiertas(): void
    {
        $this->ccf();
        $primera = $this->salida();
        $this->cargarCcfDeLaRuta($primera);
        $entrega = $primera->entregas()->sole();
        $this->servicio()->registrar($entrega, ['resultado' => 'no_entregado', 'motivo_no_entrega' => MotivoNoEntrega::SinTiempo->value], null, OrigenRegistroEntrega::Oficina);
        $this->cargarCcfDeLaRuta($this->salida(EstadoSalidaRuta::Planificada));
        $this->actingAs($this->admin())->patch(route('rutas.salidas.entregas.deshacer', [$primera, $entrega]))
            ->assertSessionHasErrors(['entrega' => 'Ese CCF ya va en otra salida abierta: no puede quedar pendiente en dos salidas.']);
        $this->assertSame(ResultadoEntrega::NoEntregado, $entrega->refresh()->resultado);
    }

    public function test_agregar_rechaza_un_ccf_en_otra_salida_abierta(): void
    {
        $dte = $this->ccf();
        $this->cargarCcfDeLaRuta($this->salida(EstadoSalidaRuta::Planificada));
        $segunda = $this->salida();
        $this->actingAs($this->admin())->post(route('rutas.salidas.entregas.store', $segunda), ['numero_control' => $dte->numero_control])
            ->assertSessionHasErrors(['numero_control' => 'Ese CCF ya va sin registrar en otra salida abierta.']);
        $this->assertSame(0, $segunda->entregas()->count());
    }

    public function test_la_hoja_marca_invalidado_y_la_sala_no_lo_entrega(): void
    {
        $dte = $this->ccf();
        $this->ccf();
        $this->ccf();
        $salida = $this->salida();
        $this->cargarCcfDeLaRuta($salida);
        $entrega = $salida->entregas()->where('dte_id', $dte->id)->sole();
        $dte->update(['estado' => 'invalidado']);
        $respuesta = $this->actingAs($this->admin())->get(route('rutas.salidas.show', $salida))
            ->assertOk()->assertSee('Invalidado · no entregar');
        $dom = new \DOMDocument;
        @$dom->loadHTML($respuesta->getContent());
        $xpath = new \DOMXPath($dom);
        $url = route('rutas.salidas.entregas.update', [$salida, $entrega]);
        $this->assertSame(0, $xpath->query('//form[@action="'.$url.'"]//input[@name="resultado"]')->length);
        $this->assertSame(2, $this->servicio()->registrarSala($salida, $this->sala->id, $this->carlos->id, null, OrigenRegistroEntrega::Oficina));
        $this->assertTrue($entrega->refresh()->estaPendiente());
    }

    public function test_no_entregado_exige_motivo_y_otro_exige_nota(): void
    {
        $this->ccf();
        $salida = $this->salida();
        $this->cargarCcfDeLaRuta($salida);
        $entrega = $salida->entregas()->sole();
        $admin = $this->admin();
        $url = route('rutas.salidas.entregas.update', [$salida, $entrega]);

        $this->actingAs($admin)->patch($url, ['resultado' => 'no_entregado'])->assertSessionHasErrors('motivo_no_entrega');
        $this->actingAs($admin)->patch($url, ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'otro'])->assertSessionHasErrors('nota');

        $this->actingAs($admin)
            ->patch($url, ['resultado' => 'no_entregado', 'motivo_no_entrega' => 'otro', 'nota' => 'Se cayó el sistema de la sala'])
            ->assertSessionHas('status');

        $entrega->refresh();
        $this->assertSame(ResultadoEntrega::NoEntregado, $entrega->resultado);
        $this->assertSame(OrigenRegistroEntrega::Oficina, $entrega->origen_registro);
        $this->assertSame($admin->id, $entrega->registrado_por);
    }

    public function test_entregado_exige_que_lo_entregue_alguien_de_la_salida(): void
    {
        $this->ccf();
        $salida = $this->salida();
        $this->cargarCcfDeLaRuta($salida);
        $entrega = $salida->entregas()->sole();
        $ajeno = PersonalRuta::create(['nombre' => 'Ajeno']);
        $url = route('rutas.salidas.entregas.update', [$salida, $entrega]);

        $this->actingAs($this->admin())
            ->patch($url, ['resultado' => 'entregado', 'entregado_por_id' => $ajeno->id])
            ->assertSessionHasErrors('entregado_por_id');

        $this->actingAs($this->admin())
            ->patch($url, ['resultado' => 'entregado', 'entregado_por_id' => $this->carlos->id, 'trae_nota_averia' => '1'])
            ->assertSessionHas('status');

        $entrega->refresh();
        $this->assertSame($this->carlos->id, $entrega->entregado_por_id);
        $this->assertTrue($entrega->trae_nota_averia);
        $this->assertNotNull($entrega->fecha_resultado);
    }

    public function test_no_se_registra_en_una_salida_planificada(): void
    {
        $this->ccf();
        $salida = $this->salida(EstadoSalidaRuta::Planificada);
        $this->cargarCcfDeLaRuta($salida);

        $this->actingAs($this->admin())
            ->patch(route('rutas.salidas.entregas.update', [$salida, $salida->entregas()->sole()]), [
                'resultado' => 'entregado', 'entregado_por_id' => $this->carlos->id,
            ])
            ->assertSessionHasErrors('salida');
    }

    public function test_deshacer_y_quitar(): void
    {
        $this->ccf();
        $salida = $this->salida();
        $this->cargarCcfDeLaRuta($salida);
        $entrega = $salida->entregas()->sole();
        $admin = $this->admin();
        $this->entregar($entrega);

        // Con resultado no se puede quitar: primero se deshace.
        $this->actingAs($admin)->delete(route('rutas.salidas.entregas.destroy', [$salida, $entrega]))->assertSessionHasErrors('entrega');

        $this->actingAs($admin)->patch(route('rutas.salidas.entregas.deshacer', [$salida, $entrega]))->assertSessionHas('status');
        $this->assertTrue($entrega->refresh()->estaPendiente());

        $this->actingAs($admin)->delete(route('rutas.salidas.entregas.destroy', [$salida, $entrega]))->assertSessionHas('status');
        $this->assertSame(0, $salida->entregas()->count());
    }

    public function test_toda_la_sala_entregada(): void
    {
        $this->ccf();
        $this->ccf();
        $salida = $this->salida();
        $this->cargarCcfDeLaRuta($salida);

        $this->actingAs($this->admin())
            ->post(route('rutas.salidas.entregas.sala', [$salida, $this->sala]), ['entregado_por_id' => $this->carlos->id])
            ->assertSessionHas('status');

        $this->assertSame(2, $salida->entregas()->entregadas()->count());
    }

    public function test_agregar_por_numero_de_control_tolera_el_numero_sin_guiones(): void
    {
        $dte = $this->ccf(['fecha_emision' => '2026-09-01']); // antes del corte: solo entra a mano
        $salida = $this->salida(EstadoSalidaRuta::Planificada);

        $this->actingAs($this->admin())
            ->post(route('rutas.salidas.entregas.store', $salida), ['numero_control' => str_replace('-', '', $dte->numero_control)])
            ->assertSessionHas('status');

        $this->assertSame($dte->id, $salida->entregas()->sole()->dte_id);

        $this->actingAs($this->admin())
            ->post(route('rutas.salidas.entregas.store', $salida), ['numero_control' => $dte->numero_control])
            ->assertSessionHasErrors('numero_control');
    }

    public function test_sin_gestionar_no_se_toca_nada(): void
    {
        $this->ccf();
        $salida = $this->salida();
        $this->cargarCcfDeLaRuta($salida);
        $mirador = User::factory()->create()->givePermissionTo('rutas.ver');

        $this->actingAs($mirador)
            ->patch(route('rutas.salidas.entregas.update', [$salida, $salida->entregas()->sole()]), ['resultado' => 'entregado'])
            ->assertForbidden();
    }

    public function test_una_entrega_de_otra_salida_no_se_toca_por_la_url(): void
    {
        $this->ccf();
        $propia = $this->salida();
        $this->cargarCcfDeLaRuta($propia);
        $otra = $this->salida();

        $this->actingAs($this->admin())
            ->patch(route('rutas.salidas.entregas.update', [$otra, $propia->entregas()->sole()]), ['resultado' => 'entregado'])
            ->assertNotFound();
    }

    // ══════════════════════════════ pantallas

    public function test_el_detalle_muestra_los_ccf_por_sala_y_su_estado(): void
    {
        $entregado = $this->ccf();
        $conAlbaran = $this->ccf();
        $sinRegistrar = $this->ccf();
        $salida = $this->salida();
        $this->cargarCcfDeLaRuta($salida);
        $this->entregar($salida->entregas()->where('dte_id', $entregado->id)->sole());
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0232/00/6715', 'numero_orden_compra' => $conAlbaran->numero_orden_compra,
            'monto_albaran' => 100, 'fecha_albaran' => '2026-10-04', 'origen' => 'gmail',
        ]);

        $this->actingAs($this->admin())
            ->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->assertSee('Selectos San Miguel')
            ->assertSee($sinRegistrar->numero_control)
            ->assertSee('Llegó el albarán')
            ->assertSee('AC01/0232/00/6715')
            // Con un solo CCF por marcar no hace falta el atajo de «todo entregado».
            ->assertDontSee('Todo entregado')
            ->assertViewHas('resumen', ['total' => 3, 'entregados' => 2, 'no_entregados' => 0, 'sin_registrar' => 1]);
    }

    public function test_cada_ccf_va_en_su_tarjeta_y_pregunta_quien_lo_entrego(): void
    {
        $entregado = $this->ccf();
        $conAlbaran = $this->ccf();
        $sinRegistrar = $this->ccf();
        $salida = $this->salida();
        $ana = PersonalRuta::create(['nombre' => 'Ana']);
        app(ParticipantesSalida::class)->sincronizar($salida, [$this->carlos->id, $ana->id], $this->carlos->id);
        $this->cargarCcfDeLaRuta($salida);
        $this->entregar($salida->entregas()->where('dte_id', $entregado->id)->sole());
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0232/00/6716', 'numero_orden_compra' => $conAlbaran->numero_orden_compra,
            'monto_albaran' => 100, 'fecha_albaran' => '2026-10-04', 'origen' => 'gmail',
        ]);
        $this->actingAs($this->admin());

        // Sin selector general ni «Todo entregado»: la tarjeta de cada CCF pregunta quién,
        // con el responsable elegido. El de albarán ya figura entregado y solo pide quién.
        $respuesta = $this->get(route('rutas.salidas.show', $salida))->assertOk()
            ->assertDontSee('¿Quién entrega?')->assertDontSee('Todo entregado')
            ->assertSee('Llegó el albarán AC01/0232/00/6716 · falta quién lo entregó')
            ->assertSee('Faltan 1')->assertSee('Entregados 2')->assertSee('Todos 3');
        $xpath = new \DOMXPath(tap(new \DOMDocument, fn ($d) => @$d->loadHTML($respuesta->getContent())));
        $fila = $salida->entregas()->where('dte_id', $sinRegistrar->id)->sole();
        $radios = $xpath->query("//input[@type='radio'][@form='entregar-{$fila->id}'][@name='entregado_por_id']");
        $this->assertSame(2, $radios->length);
        $this->assertSame((string) $this->carlos->id, $xpath->query("//input[@form='entregar-{$fila->id}'][@checked]")->item(0)->getAttribute('value'));
        $albaranFila = $salida->entregas()->where('dte_id', $conAlbaran->id)->sole();
        $this->assertSame(2, $xpath->query("//form[@action='".route('rutas.salidas.entregas.update', [$salida, $albaranFila])."']//button[@name='entregado_por_id']")->length);

        // El de albarán se guarda solo con quién.
        $this->patch(route('rutas.salidas.entregas.update', [$salida, $albaranFila]), ['resultado' => 'entregado', 'entregado_por_id' => $ana->id])
            ->assertSessionHasNoErrors();
        $albaranFila->refresh();
        $this->assertSame(ResultadoEntrega::Entregado, $albaranFila->resultado);
        $this->assertSame($ana->id, $albaranFila->entregado_por_id);

        // Quien no va en la salida no figura como «entregó».
        $otro = PersonalRuta::create(['nombre' => 'Beto']);
        $this->patch(route('rutas.salidas.entregas.update', [$salida, $fila]), ['resultado' => 'entregado', 'entregado_por_id' => $otro->id])
            ->assertSessionHasErrors('entregado_por_id');
        $this->assertTrue($fila->refresh()->estaPendiente());

        // Filtro: lo que falta y lo entregado, por separado.
        $this->get(route('rutas.salidas.show', [$salida, 'ver' => 'faltan']))->assertOk()
            ->assertSee($sinRegistrar->numero_control)->assertDontSee($entregado->numero_control)->assertDontSee($conAlbaran->numero_control);
        $this->get(route('rutas.salidas.show', [$salida, 'ver' => 'entregados']))->assertOk()
            ->assertSee($entregado->numero_control)->assertSee($conAlbaran->numero_control)->assertDontSee($sinRegistrar->numero_control);

        $this->entregar($fila);
        $this->get(route('rutas.salidas.show', [$salida, 'ver' => 'faltan']))->assertOk()->assertSee('No falta marcar ningún CCF.')->assertSee('Ver todos');
    }

    public function test_con_albaran_cuenta_como_entregado_en_toda_rutas(): void
    {
        $this->ccf();
        $conAlbaran = $this->ccf();
        $salida = $this->salida();
        $this->cargarCcfDeLaRuta($salida);
        // Llega el albarán y nadie marca nada en Rutas.
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0232/00/6717', 'numero_orden_compra' => $conAlbaran->numero_orden_compra,
            'monto_albaran' => 100, 'fecha_albaran' => '2026-10-04', 'origen' => 'gmail',
        ]);
        $this->actingAs($this->admin());

        $this->get(route('rutas.salidas.index'))->assertOk()->assertSee('1 / 2');
        $this->get(route('rutas.salidas.show', $salida))->assertOk()
            ->assertViewHas('resumen', ['total' => 2, 'entregados' => 1, 'no_entregados' => 0, 'sin_registrar' => 1]);
        $this->get(route('rutas.dashboard'))->assertOk()->assertSee('1 de 2 entregados');
    }

    public function test_el_tablero_muestra_los_ccf_por_entregar(): void
    {
        $this->ccf();
        $this->ccf();

        $this->actingAs($this->admin())
            ->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSee('2 CCF por entregar · $200.00');
    }

    /**
     * #121 · Desde 2027 el número de control se repite entre años (#42). Con dos CCF del
     * mismo número, el alta manual no elige uno a ciegas: pide el año.
     */
    public function test_agregar_un_numero_repetido_entre_anios_pide_el_anio(): void
    {
        if (! Schema::hasColumn('dtes', 'ejercicio')) {
            $this->markTestSkipped('Necesita #125: hasta entonces `dtes` no admite el mismo número en dos ejercicios.');
        }
        $this->travelTo('2027-01-20 09:00:00');
        $numero = 'DTE-03-M001P002-000000000000015';
        $de2026 = $this->ccf(['numero_control' => $numero, 'fecha_emision' => '2026-12-20', 'ejercicio' => 2026]);
        $de2027 = $this->ccf(['numero_control' => $numero, 'fecha_emision' => '2027-01-15', 'ejercicio' => 2027]);
        $salida = $this->salida();

        $this->actingAs($this->admin())->post(route('rutas.salidas.entregas.store', $salida), ['numero_control' => $numero])
            ->assertSessionHasErrors(['numero_control' => 'Ese número de control existe en 2026 y 2027: indicá el año.']);
        $this->assertSame(0, $salida->entregas()->count());

        $this->actingAs($this->admin())->post(route('rutas.salidas.entregas.store', $salida), ['numero_control' => $numero, 'ejercicio' => 2027])
            ->assertSessionHasNoErrors();
        $this->assertSame([$de2027->id], $salida->entregas()->pluck('dte_id')->all());
        $this->assertNotSame($de2026->id, $de2027->id);
    }

    public function test_agregar_con_un_anio_que_no_corresponde_no_toma_otro(): void
    {
        $dte = $this->ccf();
        $salida = $this->salida();

        $this->actingAs($this->admin())->post(route('rutas.salidas.entregas.store', $salida), ['numero_control' => $dte->numero_control, 'ejercicio' => 2027])
            ->assertSessionHasErrors(['numero_control' => 'No hay ningún documento con ese número de control.']);
        $this->assertSame(0, $salida->entregas()->count());
    }

    public function test_el_listado_de_salidas_muestra_entregados_sobre_total(): void
    {
        $this->ccf();
        $this->ccf();
        $salida = $this->salida();
        $this->cargarCcfDeLaRuta($salida);
        $this->entregar($salida->entregas()->first());

        $this->actingAs($this->admin())
            ->get(route('rutas.salidas.index'))
            ->assertOk()
            ->assertSee('1 / 2');
    }
}
