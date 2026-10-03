<?php

namespace Tests\Feature\Cobros;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\ClienteSucursal;
use App\Models\Cobros\CobroAjuste;
use App\Models\Dte;
use App\Models\User;
use App\Services\Cobros\AplicadorPagosTxt;
use App\Services\Cobros\NotaCreditoAjuste;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\SaldoMontoCcf;
use App\Services\Ppq\ArchivoConciliacion;
use App\Services\Ppq\ConciliacionTxtParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

class CobroAjusteNotaCreditoTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private Cliente $cliente;

    private Dte $ccf;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['dte.ambiente' => '00', 'dte.retencion_iva_umbral' => 100]);
        $this->seedCatalogosDte();
        $emisor = $this->crearEmisorDte();
        $this->usuario = User::factory()->create()->assignRole('administrador');
        $this->actingAs($this->usuario);
        $this->cliente = Cliente::factory()->contribuyente()->create(['es_agente_retencion' => true, 'descuento_global_default' => 0]);
        ClientePerfilDocumento::create([
            'cliente_id' => $this->cliente->id, 'activo' => true,
            'codigo_proveedor' => '000123', 'formato_export' => 'albaran_nc_v1',
            'exige_albaran_en_nc' => false, 'tolerancia_albaran' => 0,
        ]);
        $ccf = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $this->cliente->id,
            'establecimiento_id' => $emisor['estab']->id,
            'punto_venta_id' => $emisor['pv']->id,
        ]);
        $ccf->update(['aplica_retencion_iva' => true]);
        $this->ccf = $this->aceptarCcf($ccf);
        // Saldo holgado: estas pruebas miden el importe, no el tope del CCF (ver las de
        // saldo más abajo).
        DB::table('dtes')->where('id', $this->ccf->id)->update(['monto_total_operacion' => '1000.00', 'total_gravado' => '884.96']);
        $this->ccf->refresh();
    }

    private function ajuste(array $datos = []): CobroAjuste
    {
        return CobroAjuste::create(array_merge([
            'cliente_id' => $this->cliente->id, 'referencia' => 'PPQ/33762',
            'referencia_calleja' => '33762', 'monto' => '-153.01',
            'evidencia_hash' => hash('sha256', uniqid()), 'evidencia_nombre' => 'pagos.txt',
            'referencia_linea' => '1',
        ], $datos));
    }

    private function nc(array $datos = []): Dte
    {
        return app(DteBorradorService::class)->crearNotaCredito($this->ccf, array_merge(['tipo' => 'pronto_pago'], $datos), $this->usuario);
    }

    private function datos(CobroAjuste $ajuste, array $datos = []): array
    {
        return array_merge([
            'cobro_ajuste_id' => $ajuste->id, 'modalidad' => 'pronto_pago',
            'cliente_id' => $this->cliente->id, 'dte_relacionado_id' => $this->ccf->id,
        ], $datos);
    }

    public function test_formulario_prellena_ultima_sala_vigente_sin_elegir_ccf(): void
    {
        $sala = ClienteSucursal::factory()->create(['cliente_id' => $this->cliente->id, 'activo' => true, 'permite_nota_credito' => true]);
        $this->nc(['cliente_sucursal_id' => $sala->id]);
        $invalida = $this->nc();
        $invalida->update(['estado' => EstadoDte::Invalidado]);
        $ajuste = $this->ajuste();

        $this->get(route('facturacion.create-nota-credito', ['cobro_ajuste' => $ajuste->id, 'ccf' => $this->ccf->id]))
            ->assertOk()->assertSee('pagos.txt')->assertSee('153.01')->assertSee('name="cobro_ajuste_id"', false)
            ->assertViewHas('datosNc', fn ($d) => $d['clienteId'] === (string) $this->cliente->id
                && $d['clienteSalaId'] === (string) $sala->id && $d['modalidad'] === 'pronto_pago'
                && $d['motivo'] === 'Pronto pago PPQ/33762' && $d['ccfId'] === '');
    }

    public function test_formulario_sin_nc_anterior_no_inventa_sala(): void
    {
        $this->get(route('facturacion.create-nota-credito', ['cobro_ajuste' => $this->ajuste()->id]))
            ->assertOk()->assertViewHas('datosNc', fn ($d) => $d['clienteSalaId'] === '');
    }

    public static function importes(): array
    {
        return [
            'retencion' => ['153.01', '136.62', '17.76', '1.37'],
            'bajo umbral' => ['56.50', '50.00', '6.50', '0.00'],
            'umbral exacto' => ['113.00', null, null, null],
            'cerca del umbral' => ['112.02', null, null, null],
        ];
    }

    #[DataProvider('importes')]
    public function test_crea_concepto_por_neto_del_txt_y_vincula(string $neto, ?string $base, ?string $iva, ?string $retenido): void
    {
        $ajuste = $this->ajuste(['monto' => '-'.$neto]);
        $respuesta = $this->post(route('facturacion.store-nota-credito'), $this->datos($ajuste))->assertSessionHasNoErrors();
        $nc = $ajuste->fresh()->notaCredito;
        $this->assertNotNull($nc);
        $respuesta->assertRedirect(route('facturacion.edit', $nc));
        $this->assertSame(EstadoDte::Borrador, $nc->estado);
        $this->assertSame($neto, $nc->total_pagar);
        $this->assertCount(1, $nc->lineas);
        $this->assertSame('Nota de crédito de pronto pago #33762', $nc->lineas->first()->descripcion);
        if ($base !== null) {
            $this->assertEquals((float) $base, (float) $nc->lineas->first()->precio_unitario);
            $this->assertSame($iva, $nc->iva);
            $this->assertSame($retenido, $nc->iva_retenido);
        }
        $this->assertNull(app(NotaCreditoAjuste::class)->avisoImporte($nc));
    }

    public function test_importe_inalcanzable_muestra_aviso_persistente_en_editor(): void
    {
        DB::table('dtes')->where('id', $this->ccf->id)->update(['aplica_retencion_iva' => false]);
        $ajuste = $this->ajuste(['monto' => '-0.04']); // 0.03 + IVA = 0.03; 0.04 + IVA = 0.05.
        $this->post(route('facturacion.store-nota-credito'), $this->datos($ajuste))->assertSessionHasNoErrors();
        $nc = $ajuste->fresh()->notaCredito;
        $this->assertEquals(0.01, round(abs((float) $nc->total_pagar - 0.04), 2));
        $this->get(route('facturacion.edit', $nc))->assertOk()->assertSee('El total a pagar difiere del TXT en $0.01 por redondeo');
    }

    public function test_rechaza_ajuste_ajeno_sin_crear_borrador(): void
    {
        $ajuste = $this->ajuste(['cliente_id' => Cliente::factory()->contribuyente()->create()->id]);
        $this->post(route('facturacion.store-nota-credito'), $this->datos($ajuste))->assertSessionHasErrors('cobro_ajuste_id');
        $this->assertSame(0, Dte::where('tipo_dte', '05')->count());
        $this->assertNull($ajuste->fresh()->nc_dte_id);
    }

    public function test_rechaza_otra_modalidad_y_ajuste_con_nc_activa(): void
    {
        $ajuste = $this->ajuste();
        $this->post(route('facturacion.store-nota-credito'), $this->datos($ajuste, ['modalidad' => 'otro_ajuste']))->assertSessionHasErrors('cobro_ajuste_id');
        $ajuste->update(['nc_dte_id' => $this->nc()->id]);
        $this->post(route('facturacion.store-nota-credito'), $this->datos($ajuste))->assertSessionHasErrors('cobro_ajuste_id');
        $this->getJson(route('facturacion.create-nota-credito', ['cobro_ajuste' => $ajuste->id]))->assertUnprocessable();
        $this->assertSame(1, Dte::where('tipo_dte', '05')->count());
    }

    public function test_estado_derivado_y_borrador_eliminado(): void
    {
        $ajuste = $this->ajuste();
        $this->assertSame('pendiente_nc', $ajuste->estadoEfectivo());
        $nc = $this->nc();
        $ajuste->update(['nc_dte_id' => $nc->id]);
        $this->assertSame('nc_en_proceso', $ajuste->fresh()->estadoEfectivo());
        $this->assertSame('NC en proceso', $ajuste->fresh()->label());
        DB::table('dtes')->where('id', $nc->id)->update(['estado' => 'aceptado', 'sello_recepcion' => 'MOCK-prueba', 'fecha_procesamiento_mh' => now()]);
        $this->assertSame('nc_en_proceso', $ajuste->fresh()->estadoEfectivo());
        DB::table('dtes')->where('id', $nc->id)->update(['sello_recepcion' => 'SELLO-REAL']);
        $this->assertSame('resuelto', $ajuste->fresh()->estadoEfectivo());
        $this->assertSame('pendiente_nc', $ajuste->fresh()->estado);
        DB::table('dtes')->where('id', $nc->id)->update(['sello_invalidacion' => 'INVALIDACION']);
        $this->assertSame('pendiente_nc', $ajuste->fresh()->estadoEfectivo());
        $ajuste->update(['estado' => 'descartado']);
        $this->assertSame('descartado', $ajuste->fresh()->estadoEfectivo());
        $borrador = $this->nc();
        $ajuste->update(['estado' => 'pendiente_nc', 'nc_dte_id' => $borrador->id]);
        $borrador->delete();
        $this->assertSame('pendiente_nc', $ajuste->fresh()->estadoEfectivo());
        $borrador->forceDelete();
        $this->assertNull($ajuste->fresh()->nc_dte_id);
        $this->assertSame('pendiente_nc', $ajuste->fresh()->estadoEfectivo());
    }

    public function test_vincula_y_desvincula_nc_existente_y_protege_otras_deducciones(): void
    {
        $ajuste = $this->ajuste();
        $nc = $this->nc();
        $this->post(route('cobros.ajustes.vincular-nc', $ajuste), ['nc_dte_id' => $nc->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($nc->id, $ajuste->fresh()->nc_dte_id);
        $otro = $this->ajuste(['referencia_calleja' => '99999']);
        $this->post(route('cobros.ajustes.vincular-nc', $otro), ['nc_dte_id' => $nc->id])->assertSessionHasErrors('nc_dte_id');
        $this->assertNull($otro->fresh()->nc_dte_id);
        $this->post(route('cobros.ajustes.desvincular-nc', $ajuste))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNull($ajuste->fresh()->nc_dte_id);
    }

    public function test_lista_prioriza_coincidencia_y_excluye_nc_ajenas_o_invalidas(): void
    {
        $ajuste = $this->ajuste();
        $coincide = $this->nc();
        $coincide->update(['total_pagar' => '153.01']);
        $otra = $this->nc();
        $invalida = $this->nc();
        $invalida->update(['estado' => EstadoDte::Invalidado]);
        $usada = $this->nc();
        $this->ajuste(['referencia_calleja' => '99999', 'nc_dte_id' => $usada->id]);
        $this->get(route('cobros.ajustes.notas-credito', $ajuste))->assertOk()->assertSee('coincide con el TXT')
            ->assertViewHas('notas', fn ($notas) => $notas->pluck('id')->all() === [$coincide->id, $otra->id]);
        foreach ([$invalida, $this->nc(['tipo' => 'otro'])] as $rechazada) {
            $this->post(route('cobros.ajustes.vincular-nc', $ajuste), ['nc_dte_id' => $rechazada->id])->assertSessionHasErrors('nc_dte_id');
        }
        $ajeno = $this->ajuste(['cliente_id' => Cliente::factory()->contribuyente()->create()->id]);
        $this->post(route('cobros.ajustes.vincular-nc', $ajeno), ['nc_dte_id' => $coincide->id])->assertSessionHasErrors('nc_dte_id');
    }

    public function test_mismo_qd_en_otro_txt_hereda_nc_sin_duplicar_al_reaplicar(): void
    {
        $nc = $this->nc();
        $this->ajuste(['nc_dte_id' => $nc->id]);
        $contenido = "000123;Proveedor;QD;PPQ/33762;;-153.01\n";
        $archivo = ArchivoConciliacion::desdeContenido($contenido, 'otro.txt');
        $filas = app(ConciliacionTxtParser::class)->parse($contenido);
        $servicio = app(AplicadorPagosTxt::class);
        $informe = $servicio->aplicar($this->cliente, $filas, $archivo, $this->usuario);
        $ajuste = $informe['ajustes'][0]['ajuste'];
        $this->assertSame($nc->id, $ajuste->nc_dte_id);
        $this->assertStringContainsString('Misma deducción', $ajuste->motivo);
        $servicio->aplicar($this->cliente, $filas, $archivo, $this->usuario);
        $this->assertSame(2, CobroAjuste::count());
        $distinto = "000123;Proveedor;QD;PPQ/33762;;-154.01\n";
        $informe = $servicio->aplicar($this->cliente, app(ConciliacionTxtParser::class)->parse($distinto), ArchivoConciliacion::desdeContenido($distinto, 'distinto.txt'));
        $this->assertNull($informe['ajustes'][0]['ajuste']->nc_dte_id);
    }

    public function test_bandeja_y_resultado_txt_muestran_acciones_y_nc_en_proceso(): void
    {
        $ajuste = $this->ajuste();
        $this->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))->assertOk()->assertSee('Crear nota de crédito')->assertSee('Vincular NC existente');
        $ajuste->update(['nc_dte_id' => $this->nc()->id]);
        $this->get(route('cobros.index', ['cliente_id' => $this->cliente->id]))->assertOk()->assertSee('NC en proceso')->assertSee('Desvincular NC');
        $this->post(route('cobros.pagos', $this->cliente), [
            'archivo' => UploadedFile::fake()->createWithContent('pagos.txt', "000123;Proveedor;QD;PPQ/33762;;-153.01\n"),
        ])->assertOk()->assertSee('NC en proceso')->assertSee('Desvincular NC');
    }

    // ------------------------------------------------ sala propuesta y saldo del CCF

    private function aceptarNc(Dte $nc): void
    {
        DB::table('dtes')->where('id', $nc->id)->update([
            'estado' => 'aceptado', 'sello_recepcion' => 'SELLO-REAL', 'fecha_procesamiento_mh' => now(),
        ]);
    }

    private function saldoCcf(string $total): void
    {
        DB::table('dtes')->where('id', $this->ccf->id)->update(['monto_total_operacion' => $total]);
        $this->ccf->refresh();
    }

    public function test_la_sala_propuesta_es_la_mas_usada_entre_las_aceptadas(): void
    {
        $sala = fn () => ClienteSucursal::factory()->create(['cliente_id' => $this->cliente->id, 'activo' => true, 'permite_nota_credito' => true]);
        $oficina = $sala();
        $tienda = $sala();
        $panaderia = $sala();
        $this->aceptarNc($this->nc(['cliente_sucursal_id' => $oficina->id]));
        $this->aceptarNc($this->nc(['cliente_sucursal_id' => $oficina->id]));
        $this->aceptarNc($this->nc(['cliente_sucursal_id' => $tienda->id]));
        // Un borrador suelto, más reciente, a otra sala: no manda.
        $this->nc(['cliente_sucursal_id' => $panaderia->id]);

        $this->assertSame($oficina->id, app(NotaCreditoAjuste::class)->salaPropuesta($this->cliente->id));
        $this->get(route('facturacion.create-nota-credito', ['cobro_ajuste' => $this->ajuste()->id]))
            ->assertOk()->assertViewHas('datosNc', fn ($d) => $d['clienteSalaId'] === (string) $oficina->id);
    }

    public function test_el_buscador_marca_los_ccf_cuyo_saldo_no_alcanza_la_nota_del_ajuste(): void
    {
        $ajuste = $this->ajuste(); // neto 153.01 → bruto estimado 154.38 (retiene)
        $this->saldoCcf('154.00');
        $fila = $this->getJson(route('facturacion.nota-credito.buscar-ccf', ['cliente_id' => $this->cliente->id, 'cobro_ajuste' => $ajuste->id]))
            ->assertOk()->json('resultados.0');
        $this->assertFalse($fila['alcanza']);
        $this->assertSame('154.00', $fila['saldo']);
        $this->assertSame('154.38', $fila['monto_nota']);

        $this->saldoCcf('154.38');
        $this->assertTrue($this->getJson(route('facturacion.nota-credito.buscar-ccf', ['cliente_id' => $this->cliente->id, 'cobro_ajuste' => $ajuste->id]))
            ->json('resultados.0.alcanza'));
        // Sin ajuste no se marca nada: el formulario manual no conoce todavía el monto.
        $this->assertArrayNotHasKey('alcanza', $this->getJson(route('facturacion.nota-credito.buscar-ccf', ['cliente_id' => $this->cliente->id]))->json('resultados.0'));
    }

    public function test_crear_desde_el_ajuste_con_ccf_sin_saldo_no_deja_borrador_ni_vinculo(): void
    {
        $ajuste = $this->ajuste();
        $this->saldoCcf('154.00');
        $this->post(route('facturacion.store-nota-credito'), $this->datos($ajuste))->assertSessionHasErrors('dte_relacionado_id');
        $this->assertSame(0, Dte::where('tipo_dte', '05')->count());
        $this->assertNull($ajuste->fresh()->nc_dte_id);
    }

    public function test_una_nc_previa_vigente_reduce_el_saldo_y_una_invalidada_no(): void
    {
        $previa = $this->nc();
        app(DteBorradorService::class)->agregarConceptoNotaCredito($previa, ['descripcion' => 'Previa', 'monto' => '100.00']);
        $saldos = app(SaldoMontoCcf::class);
        // Borrador: todavía no existe para Hacienda.
        $this->assertSame('1000.00', $saldos->saldo($this->ccf->fresh()));
        DB::table('dtes')->where('id', $previa->id)->update(['estado' => 'generado']);
        $this->assertSame('887.00', $saldos->saldo($this->ccf->fresh()));
        DB::table('dtes')->where('id', $previa->id)->update(['estado' => 'invalidado']);
        $this->assertSame('1000.00', $saldos->saldo($this->ccf->fresh()));
    }

    public function test_un_concepto_manual_que_supera_el_saldo_se_rechaza(): void
    {
        $nc = $this->nc();
        $this->saldoCcf('50.00');
        $this->post(route('facturacion.conceptos.store', $nc), ['descripcion' => 'Pronto pago', 'monto' => '100.00'])
            ->assertSessionHasErrors('monto');
        $this->assertCount(0, $nc->fresh()->lineas);

        $this->post(route('facturacion.conceptos.store', $nc), ['descripcion' => 'Pronto pago', 'monto' => '40.00'])
            ->assertSessionHasNoErrors();
        $this->assertSame('45.20', $nc->fresh()->monto_total_operacion);
    }

    public function test_generar_una_nc_que_supera_el_saldo_se_bloquea_y_el_editor_lo_dice(): void
    {
        $nc = $this->nc();
        app(DteBorradorService::class)->agregarConceptoNotaCredito($nc, ['descripcion' => 'Pronto pago', 'monto' => '100.00']);
        $this->saldoCcf('100.00'); // la nota es de 113.00

        $this->get(route('facturacion.edit', $nc))->assertOk()->assertSee('menor que la nota ($113.00)', false);
        $this->post(route('facturacion.generar', $nc))->assertSessionHasErrors('generar');
        $this->assertSame(EstadoDte::Borrador, $nc->fresh()->estado);
    }

    public function test_vinculacion_exige_permiso_de_gestion(): void
    {
        $ajuste = $this->ajuste();
        $nc = $this->nc();
        $lector = User::factory()->create();
        $lector->givePermissionTo('ppq.ver');
        $this->actingAs($lector);
        $this->get(route('cobros.ajustes.notas-credito', $ajuste))->assertForbidden();
        $this->post(route('cobros.ajustes.vincular-nc', $ajuste), ['nc_dte_id' => $nc->id])->assertForbidden();
        $this->post(route('cobros.ajustes.desvincular-nc', $ajuste))->assertForbidden();
        $this->get(route('facturacion.create-nota-credito', ['cobro_ajuste' => $ajuste->id]))->assertForbidden();
    }
}
