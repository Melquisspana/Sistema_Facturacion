<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\MotivoRevisionDocumento;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PpqAlbaran;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaDocumento;
use App\Models\User;
use App\Services\Rutas\SeguimientoDocumentos;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Seguimiento documental de una salida: qué documentos lleva, si se entregaron
 * (albarán), si volvió el papel y si hay que revisarlos por una NC.
 *
 * Lo que estas pruebas defienden por encima de todo:
 *
 *  - un documento NO puede estar en dos salidas abiertas, ni por la puerta de
 *    adelante (el servicio) ni saltándose el servicio (el índice único);
 *  - la ENTREGA no es un dato guardado: sale del albarán, y si el albarán no
 *    está, dice pendiente;
 *  - marcar «requiere NC» NO crea ninguna nota de crédito, y el estado de una NC
 *    real nunca se copia a esta tabla;
 *  - nada de esto toca el DTE original.
 */
class SalidaDocumentosTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    /** Emisor mínimo: los DTE de estas pruebas solo se consultan, nunca se emiten. */
    private function establecimiento(): Establecimiento
    {
        return Establecimiento::firstOr(function () {
            $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'ambiente' => '00', 'activo' => true]);

            return Establecimiento::create([
                'empresa_id' => $empresa->id,
                'codigo' => 'M001',
                'nombre' => 'Casa Matriz',
                'activo' => true,
            ]);
        });
    }

    private function ruta(string $nombre = 'San Miguel'): Ruta
    {
        return Ruta::create(['nombre' => $nombre]);
    }

    private function sala(Ruta $ruta, string $nombre = 'Selectos San Miguel'): ClienteSucursal
    {
        $cliente = Cliente::factory()->create(['nombre' => 'Calleja']);

        return $cliente->sucursales()->create([
            'nombre' => $nombre,
            'codigo' => substr(md5($nombre), 0, 4),
            'ruta_id' => $ruta->id,
        ]);
    }

    private function salida(Ruta $ruta, EstadoSalidaRuta $estado = EstadoSalidaRuta::Planificada): SalidaRuta
    {
        return SalidaRuta::create([
            'ruta_id' => $ruta->id,
            'fecha_inicio' => '2026-08-14',
            'fecha_fin_estimada' => '2026-08-16',
            'estado' => $estado,
        ]);
    }

    /**
     * CCF de prueba. `$extra` va a la IZQUIERDA del `+` a propósito: en PHP el
     * operando izquierdo gana, así que es la única forma de que un override real
     * pise el valor por defecto.
     *
     * @param  array<string, mixed>  $extra
     */
    private function ccf(?ClienteSucursal $sala, string $control, array $extra = []): Dte
    {
        return Dte::create($extra + [
            'establecimiento_id' => $this->establecimiento()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            // Fiscalmente VIGENTE. Desde la Fase 0, a una salida de ruta solo entra un
            // CCF que existe de verdad ante Hacienda: produccion, aceptado y con sello
            // real. Un CCF de prueba tiene que parecerse a uno real o no entra.
            'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(substr(md5($control), 0, 12)),
            'fecha_procesamiento_mh' => '2026-08-14 12:00:00',
            'cliente_id' => $sala?->cliente_id,
            'cliente_sucursal_id' => $sala?->id,
            'numero_control' => $control,
            'numero_orden_compra' => '260600232002345',
            'fecha_emision' => '2026-08-14',
            'hora_emision' => '10:00:00',
            'total_pagar' => 113.58,
        ]);
    }

    // ============================================================ 1) P002

    public function test_un_ccf_p002_se_asigna_con_su_dte_id(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000001');

        $this->actingAs($admin)
            ->post(route('rutas.salidas.documentos.store', $salida), ['dtes' => [$ccf->id]])
            ->assertRedirect(route('rutas.salidas.show', $salida));

        $documento = SalidaRutaDocumento::sole();
        $this->assertSame($ccf->id, $documento->dte_id);
        $this->assertSame('DTE-03-M001P002-000000000000001', $documento->numero_control);
        $this->assertSame(SalidaRutaDocumento::ORIGEN_P002, $documento->origen);
        $this->assertFalse($documento->esHistorico());
        $this->assertFalse($documento->asignacion_automatica);
        // Los datos visibles se leen del DTE, no de una copia.
        $this->assertSame(113.58, $documento->monto());
        $this->assertSame($sala->nombre, $documento->salaNombre());
    }

    // ============================================================ 2) P001

    public function test_un_documento_historico_vive_sin_dte_id(): void
    {
        $admin = $this->admin();
        $salida = $this->salida($this->ruta());

        $this->actingAs($admin)
            ->post(route('rutas.salidas.documentos.historico.store', $salida), [
                'numero_control' => 'DTE-03-M001P001-000000000000986',
                'numero_orden_compra' => '26060236004586',
                'sala_nombre' => 'Selectos Metrocentro',
                'monto' => '250.75',
                'fecha_documento' => '2026-07-30',
            ])
            ->assertRedirect();

        $documento = SalidaRutaDocumento::sole();
        $this->assertNull($documento->dte_id);
        $this->assertTrue($documento->esHistorico());
        $this->assertSame(SalidaRutaDocumento::ORIGEN_P001, $documento->origen);
        $this->assertSame('DTE-03-M001P001-000000000000986', $documento->numero_control);
        // Sin DTE de dónde leer, el snapshot es lo que se muestra.
        $this->assertSame(250.75, $documento->monto());
        $this->assertSame('Selectos Metrocentro', $documento->salaNombre());
        $this->assertSame('2026-07-30', $documento->fecha()->toDateString());

        // Y no se creó ningún DTE para "acomodarlo".
        $this->assertSame(0, Dte::count());
    }

    public function test_si_el_numero_historico_existe_en_dtes_se_agrega_con_sus_datos_reales(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000007');

        $this->actingAs($admin)
            ->post(route('rutas.salidas.documentos.historico.store', $salida), [
                'numero_control' => 'DTE-03-M001P002-000000000000007',
                'monto' => '1.00', // dato equivocado: no debe usarse
            ])
            ->assertRedirect();

        $documento = SalidaRutaDocumento::sole();
        $this->assertSame($ccf->id, $documento->dte_id);
        $this->assertFalse($documento->esHistorico());
        $this->assertSame(113.58, $documento->monto());
    }

    public function test_un_numero_con_espacios_sobrantes_igual_encuentra_su_dte(): void
    {
        // Pegar el número desde otra pantalla suele arrastrar un espacio. Antes, ese
        // espacio hacía fallar la búsqueda y el CCF se guardaba como HISTÓRICO —con el
        // número ya recortado, o sea idéntico al real— sin ningún aviso: la pantalla
        // quedaba sin sala, sin fecha, sin albarán y sin PPQ, y el número se veía bien.
        $admin = $this->admin();
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000007');

        $this->actingAs($admin)
            ->post(route('rutas.salidas.documentos.historico.store', $salida), [
                'numero_control' => "  DTE-03-M001P002-000000000000007\t",
            ])
            ->assertRedirect();

        $documento = SalidaRutaDocumento::sole();
        $this->assertSame($ccf->id, $documento->dte_id, 'tenía que entrar por el camino P002');
        $this->assertFalse($documento->esHistorico());
        $this->assertSame('DTE-03-M001P002-000000000000007', $documento->numero_control);
    }

    public function test_un_historico_real_con_espacios_se_guarda_recortado(): void
    {
        $salida = $this->salida($this->ruta());

        $this->actingAs($this->admin())
            ->post(route('rutas.salidas.documentos.historico.store', $salida), [
                'numero_control' => '  DTE-03-M001P001-000000000000986  ',
            ])
            ->assertRedirect();

        $documento = SalidaRutaDocumento::sole();
        $this->assertTrue($documento->esHistorico());
        $this->assertSame('DTE-03-M001P001-000000000000986', $documento->numero_control);
    }

    public function test_el_historico_exige_al_menos_el_numero_de_control(): void
    {
        $salida = $this->salida($this->ruta());

        $this->actingAs($this->admin())
            ->post(route('rutas.salidas.documentos.historico.store', $salida), ['numero_control' => ''])
            ->assertSessionHasErrors('numero_control');

        $this->assertSame(0, SalidaRutaDocumento::count());
    }

    // ================================================== 3) unicidad

    public function test_un_documento_no_puede_estar_en_dos_salidas_abiertas(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000010');

        $primera = $this->salida($ruta);
        $segunda = $this->salida($ruta);

        $this->actingAs($admin)->post(route('rutas.salidas.documentos.store', $primera), ['dtes' => [$ccf->id]]);
        $this->actingAs($admin)
            ->post(route('rutas.salidas.documentos.store', $segunda), ['dtes' => [$ccf->id]])
            ->assertRedirect();

        // Sigue habiendo UNA sola asignación, y es la primera.
        $this->assertSame(1, SalidaRutaDocumento::count());
        $this->assertSame($primera->id, SalidaRutaDocumento::sole()->salida_ruta_id);
    }

    public function test_el_indice_unico_lo_impide_aunque_se_esquive_el_servicio(): void
    {
        $ruta = $this->ruta();
        $primera = $this->salida($ruta);
        $segunda = $this->salida($ruta);

        $base = [
            'numero_control' => 'DTE-03-M001P002-000000000000020',
            'origen' => SalidaRutaDocumento::ORIGEN_P001,
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ];

        SalidaRutaDocumento::create($base + ['salida_ruta_id' => $primera->id]);

        // Escribiendo directo en el modelo, sin pasar por el asignador: la base tiene
        // que negarse igual. Este es el candado que sobrevive a una carrera.
        $this->expectException(QueryException::class);
        SalidaRutaDocumento::create($base + ['salida_ruta_id' => $segunda->id]);
    }

    public function test_una_salida_terminada_libera_el_documento_sin_perder_la_historia(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000030');

        $primera = $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $this->actingAs($admin)->post(route('rutas.salidas.documentos.store', $primera), ['dtes' => [$ccf->id]]);

        // Al finalizar, el candado se suelta pero la fila queda.
        $primera->finalizar();
        $this->assertNull(SalidaRutaDocumento::sole()->fresh()->bloqueo_asignacion);

        // El mismo documento puede salir otra vez la semana siguiente.
        $segunda = $this->salida($ruta);
        $this->actingAs($admin)
            ->post(route('rutas.salidas.documentos.store', $segunda), ['dtes' => [$ccf->id]])
            ->assertRedirect();

        $this->assertSame(2, SalidaRutaDocumento::count());
        $this->assertSame(1, SalidaRutaDocumento::where('salida_ruta_id', $primera->id)->count());
        $this->assertSame(1, SalidaRutaDocumento::where('salida_ruta_id', $segunda->id)->count());
    }

    public function test_una_salida_finalizada_no_admite_documentos_nuevos(): void
    {
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000040');
        $salida = $this->salida($ruta, EstadoSalidaRuta::Finalizada);

        $this->actingAs($this->admin())
            ->post(route('rutas.salidas.documentos.store', $salida), ['dtes' => [$ccf->id]])
            ->assertForbidden();

        $this->assertSame(0, SalidaRutaDocumento::count());
    }

    // ================================================== 8-9) albarán

    public function test_con_albaran_el_documento_aparece_entregado(): void
    {
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000050');

        $this->actingAs($this->admin())->post(route('rutas.salidas.documentos.store', $salida), ['dtes' => [$ccf->id]]);

        // Albarán vinculado por ORDEN DE COMPRA, que es como llegan de Calleja.
        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0232/00/6359',
            'numero_orden_compra' => '260600232002345',
            'fecha_albaran' => '2026-08-15',
            'monto_albaran' => 113.58,
            'origen' => 'gmail',
        ]);

        $documento = SalidaRutaDocumento::sole();
        $this->assertTrue($documento->entregado());
        $this->assertSame('2026-08-15', $documento->fechaEntrega()->toDateString());

        $this->actingAs($this->admin())
            ->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->assertSee('Entregado');
    }

    public function test_el_albaran_tambien_se_encuentra_por_dte_id_directo(): void
    {
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        // Sin OC: el único vínculo posible es el explícito.
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000051', ['numero_orden_compra' => null]);

        $this->actingAs($this->admin())->post(route('rutas.salidas.documentos.store', $salida), ['dtes' => [$ccf->id]]);

        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0232/00/9999',
            'numero_orden_compra' => null,
            'dte_id' => $ccf->id,
            'fecha_albaran' => '2026-08-15',
            'origen' => 'manual',
        ]);

        $this->assertTrue(SalidaRutaDocumento::sole()->entregado());
    }

    public function test_sin_albaran_el_documento_queda_esperando(): void
    {
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000060');

        $this->actingAs($this->admin())->post(route('rutas.salidas.documentos.store', $salida), ['dtes' => [$ccf->id]]);

        $documento = SalidaRutaDocumento::sole();
        $this->assertFalse($documento->entregado());
        $this->assertNull($documento->fechaEntrega());

        $this->actingAs($this->admin())
            ->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->assertSee('Esperando albarán');
    }

    public function test_un_albaran_de_otra_orden_no_marca_entregado(): void
    {
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000061');

        $this->actingAs($this->admin())->post(route('rutas.salidas.documentos.store', $salida), ['dtes' => [$ccf->id]]);

        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0999/00/1111',
            'numero_orden_compra' => '999999999999999',
            'origen' => 'gmail',
        ]);

        $this->assertFalse(SalidaRutaDocumento::sole()->entregado());
    }

    // ========================================== 10-11) documentación física

    public function test_marcar_documentacion_fisica_guarda_fecha_y_usuario(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000070');

        $this->actingAs($admin)->post(route('rutas.salidas.documentos.store', $salida), ['dtes' => [$ccf->id]]);
        $documento = SalidaRutaDocumento::sole();

        $this->actingAs($admin)
            ->post(route('rutas.salidas.documentos.documentacion-fisica', $salida), ['documentos' => [$documento->id]])
            ->assertRedirect();

        $documento->refresh();
        $this->assertTrue($documento->documentacionFisicaRecibida());
        $this->assertNotNull($documento->documentacion_fisica_recibida_at);
        $this->assertSame($admin->id, $documento->documentacion_fisica_recibida_por);

        $this->assertTrue(Activity::where('log_name', 'salida_documento')
            ->where('description', 'registró la documentación física recibida')->exists());
    }

    public function test_desmarcar_documentacion_fisica_queda_auditado(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000071');

        $this->actingAs($admin)->post(route('rutas.salidas.documentos.store', $salida), ['dtes' => [$ccf->id]]);
        $documento = SalidaRutaDocumento::sole();
        $this->actingAs($admin)->post(route('rutas.salidas.documentos.documentacion-fisica', $salida), ['documentos' => [$documento->id]]);

        $this->actingAs($admin)
            ->delete(route('rutas.salidas.documentos.documentacion-fisica.destroy', [$salida, $documento]))
            ->assertRedirect();

        $documento->refresh();
        $this->assertFalse($documento->documentacionFisicaRecibida());
        $this->assertNull($documento->documentacion_fisica_recibida_por);

        $this->assertTrue(Activity::where('log_name', 'salida_documento')
            ->where('description', 'desmarcó la documentación física recibida')->exists());
    }

    // ========================================== 12-13) requiere NC / NC real

    public function test_marcar_requiere_nc_no_crea_ninguna_nota_de_credito(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000080');

        $this->actingAs($admin)->post(route('rutas.salidas.documentos.store', $salida), ['dtes' => [$ccf->id]]);
        $documento = SalidaRutaDocumento::sole();

        $this->actingAs($admin)
            ->patch(route('rutas.salidas.documentos.requiere-nc', [$salida, $documento]), [
                'motivo_revision' => 'averia',
                'motivo_revision_nota' => 'Dos cajas golpeadas',
            ])
            ->assertRedirect();

        $documento->refresh();
        $this->assertTrue($documento->requiere_nc);
        $this->assertSame(MotivoRevisionDocumento::Averia, $documento->motivo_revision);
        $this->assertSame('Dos cajas golpeadas', $documento->motivo_revision_nota);

        // Lo esencial: NO apareció ninguna NC, y el CCF quedó intacto.
        $this->assertSame(0, Dte::where('tipo_dte', '05')->count());
        $this->assertSame('aceptado', $ccf->refresh()->estado->value ?? $ccf->estado);
        $this->assertNull($documento->notaCredito());
    }

    public function test_quitar_la_marca_requiere_nc(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000081');

        $this->actingAs($admin)->post(route('rutas.salidas.documentos.store', $salida), ['dtes' => [$ccf->id]]);
        $documento = SalidaRutaDocumento::sole();
        $this->actingAs($admin)->patch(route('rutas.salidas.documentos.requiere-nc', [$salida, $documento]), ['motivo_revision' => 'faltante']);

        $this->actingAs($admin)
            ->delete(route('rutas.salidas.documentos.requiere-nc.destroy', [$salida, $documento]))
            ->assertRedirect();

        $documento->refresh();
        $this->assertFalse($documento->requiere_nc);
        $this->assertNull($documento->motivo_revision);
    }

    public function test_la_nc_real_se_detecta_por_el_vinculo_fiscal_sin_copiar_su_estado(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000090');

        $this->actingAs($admin)->post(route('rutas.salidas.documentos.store', $salida), ['dtes' => [$ccf->id]]);

        $nc = Dte::create([
            'establecimiento_id' => $this->establecimiento()->id,
            'tipo_dte' => '05',
            'estado' => 'aceptado',
            'dte_relacionado_id' => $ccf->id,
            'numero_control' => 'DTE-05-M001P002-000000000000001',
            'fecha_emision' => '2026-08-20',
            'hora_emision' => '09:00:00',
            'total_pagar' => 20.00,
        ]);

        $documento = SalidaRutaDocumento::sole();
        $this->assertNotNull($documento->notaCredito());
        $this->assertSame($nc->id, $documento->notaCredito()->id);

        // El estado se LEE de la NC. Si cambia allá, cambia acá sin sincronizar nada,
        // porque no hay ninguna columna de estado de NC en salida_ruta_documentos.
        $this->assertArrayNotHasKey('estado_nc', $documento->getAttributes());
        $nc->update(['estado' => 'invalidado']);
        $this->assertSame('invalidado', $documento->fresh()->notaCredito()->estado->value);
    }

    public function test_en_los_historicos_la_nc_se_detecta_por_orden_de_compra(): void
    {
        $admin = $this->admin();
        $salida = $this->salida($this->ruta(), EstadoSalidaRuta::EnCurso);

        $this->actingAs($admin)->post(route('rutas.salidas.documentos.historico.store', $salida), [
            'numero_control' => 'DTE-03-M001P001-000000000000500',
            'numero_orden_compra' => '260600232002345',
        ]);

        Dte::create([
            'establecimiento_id' => $this->establecimiento()->id,
            'tipo_dte' => '05',
            'estado' => 'aceptado',
            'numero_control' => 'DTE-05-M001P001-000000000000002',
            'numero_orden_compra' => '260600232002345',
            'fecha_emision' => '2026-08-21',
            'hora_emision' => '09:00:00',
            'total_pagar' => 15.00,
        ]);

        $this->assertNotNull(SalidaRutaDocumento::sole()->notaCredito());
    }

    // ============================================ 13-14) mover / quitar

    public function test_mover_un_documento_a_otra_salida_deja_auditoria_en_las_dos(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $origen = $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $destino = $this->salida($ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000100');

        $this->actingAs($admin)->post(route('rutas.salidas.documentos.store', $origen), ['dtes' => [$ccf->id]]);
        $documento = SalidaRutaDocumento::sole();

        $this->actingAs($admin)
            ->patch(route('rutas.salidas.documentos.mover', [$origen, $documento]), ['salida_destino_id' => $destino->id])
            ->assertRedirect(route('rutas.salidas.show', $destino));

        $this->assertSame($destino->id, $documento->refresh()->salida_ruta_id);
        // Sigue habiendo una sola fila: mover conserva el papel recibido y la marca de NC.
        $this->assertSame(1, SalidaRutaDocumento::count());

        $logs = Activity::where('log_name', 'salida_documento')->get();
        $this->assertTrue($logs->contains(fn ($l) => $l->description === 'movió el documento de salida' && $l->subject_id === $origen->id));
        $this->assertTrue($logs->contains(fn ($l) => $l->description === 'recibió un documento movido desde otra salida' && $l->subject_id === $destino->id));
    }

    public function test_quitar_un_documento_no_toca_el_dte(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000110');
        // Foto completa de la fila (fresh, no el modelo en memoria) para poder
        // comparar columna por columna después de quitar el documento.
        $antes = $ccf->fresh()->toArray();

        $this->actingAs($admin)->post(route('rutas.salidas.documentos.store', $salida), ['dtes' => [$ccf->id]]);
        $documento = SalidaRutaDocumento::sole();

        $this->actingAs($admin)
            ->delete(route('rutas.salidas.documentos.destroy', [$salida, $documento]))
            ->assertRedirect();

        $this->assertSame(0, SalidaRutaDocumento::count());
        $this->assertSame(1, Dte::count());
        $this->assertEquals($antes, $ccf->refresh()->toArray());

        $this->assertTrue(Activity::where('log_name', 'salida_documento')
            ->where('description', 'quitó el documento de la salida')->exists());

        // Y al quedar libre, se puede volver a agregar.
        $this->actingAs($admin)
            ->post(route('rutas.salidas.documentos.store', $salida), ['dtes' => [$ccf->id]])
            ->assertRedirect();
        $this->assertSame(1, SalidaRutaDocumento::count());
    }

    public function test_no_se_puede_tocar_un_documento_de_otra_salida(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $propia = $this->salida($ruta);
        $ajena = $this->salida($ruta);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000120');

        $this->actingAs($admin)->post(route('rutas.salidas.documentos.store', $propia), ['dtes' => [$ccf->id]]);
        $documento = SalidaRutaDocumento::sole();

        $this->actingAs($admin)
            ->delete(route('rutas.salidas.documentos.destroy', [$ajena, $documento]))
            ->assertNotFound();

        $this->assertSame(1, SalidaRutaDocumento::count());
    }

    // ============================================ 4) candidatos

    public function test_los_candidatos_solo_ofrecen_ccf_de_la_ruta_libres_y_en_ventana(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $otraRuta = $this->ruta('Sonsonate');
        $sala = $this->sala($ruta);
        $salaAjena = $this->sala($otraRuta, 'Selectos Sonsonate');
        $salida = $this->salida($ruta);

        $enRuta = $this->ccf($sala, 'DTE-03-M001P002-000000000000200');
        $fueraDeRuta = $this->ccf($salaAjena, 'DTE-03-M001P002-000000000000201');
        $viejo = $this->ccf($sala, 'DTE-03-M001P002-000000000000202', ['fecha_emision' => '2026-01-01']);
        $archivado = $this->ccf($sala, 'DTE-03-M001P002-000000000000203', ['archivado' => true, 'estado' => 'rechazado']);
        $yaAsignado = $this->ccf($sala, 'DTE-03-M001P002-000000000000204');

        $otraSalida = $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $this->actingAs($admin)->post(route('rutas.salidas.documentos.store', $otraSalida), ['dtes' => [$yaAsignado->id]]);

        $this->actingAs($admin)
            ->get(route('rutas.salidas.documentos.candidatos', $salida))
            ->assertOk()
            ->assertSee($enRuta->numero_control)
            ->assertDontSee($fueraDeRuta->numero_control)
            ->assertDontSee($viejo->numero_control)
            ->assertDontSee($archivado->numero_control)
            ->assertDontSee($yaAsignado->numero_control);
    }

    public function test_los_candidatos_no_se_agregan_solos(): void
    {
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);
        $this->ccf($sala, 'DTE-03-M001P002-000000000000210');

        $this->actingAs($this->admin())->get(route('rutas.salidas.documentos.candidatos', $salida))->assertOk();

        // Solo mirar la pantalla no asigna nada.
        $this->assertSame(0, SalidaRutaDocumento::count());
    }

    // ============================================ 12) resumen

    public function test_el_resumen_cuenta_lo_mismo_que_muestra_la_lista(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta, EstadoSalidaRuta::EnCurso);

        $conAlbaran = $this->ccf($sala, 'DTE-03-M001P002-000000000000300', ['numero_orden_compra' => '260600232000001']);
        $sinAlbaran = $this->ccf($sala, 'DTE-03-M001P002-000000000000301', ['numero_orden_compra' => '260600232000002']);

        $this->actingAs($admin)->post(route('rutas.salidas.documentos.store', $salida), [
            'dtes' => [$conAlbaran->id, $sinAlbaran->id],
        ]);

        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0232/00/1000',
            'numero_orden_compra' => '260600232000001',
            'fecha_albaran' => '2026-08-15',
            'origen' => 'gmail',
        ]);

        $documento = SalidaRutaDocumento::where('dte_id', $sinAlbaran->id)->sole();
        $this->actingAs($admin)->post(route('rutas.salidas.documentos.documentacion-fisica', $salida), ['documentos' => [$documento->id]]);
        $this->actingAs($admin)->patch(route('rutas.salidas.documentos.requiere-nc', [$salida, $documento]), ['motivo_revision' => 'devolucion']);

        $seguimiento = app(SeguimientoDocumentos::class);
        $documentos = $seguimiento->documentosDe($salida->refresh());
        $resumen = $seguimiento->resumen($documentos);

        $this->assertSame(2, $resumen['total']);
        $this->assertSame(1, $resumen['entregados']);
        $this->assertSame(1, $resumen['sin_albaran']);
        $this->assertSame(1, $resumen['documentacion_fisica']);
        $this->assertSame(1, $resumen['requieren_nc']);
        $this->assertSame(0, $resumen['nc_reales']);
        $this->assertSame(0, $resumen['en_ppq']);

        // Y la pantalla ya no muestra el «0» fijo del bloque anterior.
        $this->actingAs($admin)
            ->get(route('rutas.salidas.show', $salida))
            ->assertOk()
            ->assertDontSee('Próximo bloque')
            ->assertSee('Documentos de la salida');
    }

    // ============================================ 16-17) P001 en pantalla

    public function test_un_historico_no_rompe_ninguna_pantalla(): void
    {
        $admin = $this->admin();
        $ruta = $this->ruta();
        $salida = $this->salida($ruta, EstadoSalidaRuta::EnCurso);

        // Lo mínimo posible: solo el número de control, sin nada más.
        $this->actingAs($admin)->post(route('rutas.salidas.documentos.historico.store', $salida), [
            'numero_control' => 'DTE-03-M001P001-000000000000777',
        ]);

        $this->actingAs($admin)->get(route('rutas.salidas.show', $salida))->assertOk()->assertSee('Histórico P001');
        $this->actingAs($admin)->get(route('rutas.salidas.index'))->assertOk();
        $this->actingAs($admin)->get(route('rutas.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('rutas.salidas.documentos.historico', $salida))->assertOk();
    }

    // ============================================ 15) permisos

    public function test_ver_sin_gestionar_no_puede_tocar_documentos(): void
    {
        $admin = $this->admin();
        $usuario = User::factory()->create(['activo' => true])->assignRole('jefatura')->givePermissionTo('rutas.ver');

        $ruta = $this->ruta();
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta, EstadoSalidaRuta::EnCurso);
        $ccf = $this->ccf($sala, 'DTE-03-M001P002-000000000000400');

        $this->actingAs($admin)->post(route('rutas.salidas.documentos.store', $salida), ['dtes' => [$ccf->id]]);
        $documento = SalidaRutaDocumento::sole();

        // Leer la salida sí; tocar sus documentos no.
        $this->actingAs($usuario)->get(route('rutas.salidas.show', $salida))->assertOk();

        $this->actingAs($usuario)->get(route('rutas.salidas.documentos.candidatos', $salida))->assertForbidden();
        $this->actingAs($usuario)->get(route('rutas.salidas.documentos.historico', $salida))->assertForbidden();
        $this->actingAs($usuario)->post(route('rutas.salidas.documentos.store', $salida), ['dtes' => [$ccf->id]])->assertForbidden();
        $this->actingAs($usuario)->post(route('rutas.salidas.documentos.documentacion-fisica', $salida), ['documentos' => [$documento->id]])->assertForbidden();
        $this->actingAs($usuario)->patch(route('rutas.salidas.documentos.requiere-nc', [$salida, $documento]), [])->assertForbidden();
        $this->actingAs($usuario)->delete(route('rutas.salidas.documentos.destroy', [$salida, $documento]))->assertForbidden();

        // Nada cambió por los intentos.
        $documento->refresh();
        $this->assertFalse($documento->requiere_nc);
        $this->assertFalse($documento->documentacionFisicaRecibida());
        $this->assertSame(1, SalidaRutaDocumento::count());
    }

    public function test_un_invitado_no_llega_a_los_documentos(): void
    {
        $salida = $this->salida($this->ruta());

        $this->get(route('rutas.salidas.documentos.candidatos', $salida))->assertRedirect(route('login'));
        $this->post(route('rutas.salidas.documentos.store', $salida), ['dtes' => [1]])->assertRedirect(route('login'));
    }
}
