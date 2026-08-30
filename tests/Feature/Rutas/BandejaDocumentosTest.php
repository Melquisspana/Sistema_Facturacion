<?php

namespace Tests\Feature\Rutas;

use App\Enums\EstadoPpq;
use App\Enums\EstadoSalidaRuta;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaDocumento;
use App\Models\User;
use App\Services\Rutas\BandejaDocumentos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Bandeja transversal: los documentos de todas las salidas en una sola lista.
 *
 * Lo que estas pruebas defienden:
 *
 *  - los documentos NO desaparecen cuando la salida se finaliza: el cobro sigue
 *    después y por eso el historial tiene que seguir consultable;
 *  - los filtros derivados (entrega, cobro) contestan lo MISMO que muestra la fila;
 *  - la ventana de fechas siempre acota, y se puede mover pero no quitar;
 *  - mirar la bandeja no escribe nada ni audita nada.
 */
class BandejaDocumentosTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

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

    private function sala(Ruta $ruta, string $nombre = 'Selectos San Miguel'): ClienteSucursal
    {
        $cliente = Cliente::firstOrCreate(['nombre' => 'Calleja'], Cliente::factory()->raw(['nombre' => 'Calleja']));

        return $cliente->sucursales()->create([
            'nombre' => $nombre,
            'codigo' => substr(md5($nombre), 0, 4),
            'ruta_id' => $ruta->id,
        ]);
    }

    private function salida(Ruta $ruta, string $inicio = '2026-08-14', EstadoSalidaRuta $estado = EstadoSalidaRuta::Planificada): SalidaRuta
    {
        return SalidaRuta::create([
            'ruta_id' => $ruta->id,
            'fecha_inicio' => $inicio,
            'fecha_fin_estimada' => $inicio,
            'estado' => $estado,
        ]);
    }

    /** @param array<string, mixed> $extra */
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

    private function documento(SalidaRuta $salida, Dte $ccf): SalidaRutaDocumento
    {
        return SalidaRutaDocumento::create([
            'salida_ruta_id' => $salida->id,
            'dte_id' => $ccf->id,
            'numero_control' => $ccf->numero_control,
            'numero_orden_compra' => $ccf->numero_orden_compra,
            'cliente_sucursal_id' => $ccf->cliente_sucursal_id,
            'origen' => SalidaRutaDocumento::ORIGEN_P002,
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ]);
    }

    private function itemPpq(string $control, ?string $estado = null): PpqItem
    {
        $lote = PpqLote::create(['referencia' => 'PPQ prueba', 'fecha' => '2026-08-20', 'estado' => EstadoPpq::Listo]);

        return PpqItem::create([
            'ppq_lote_id' => $lote->id,
            'dte_id' => null,
            'origen' => 'gmail',
            'numero_control' => $control,
            'tipo_dte' => '03',
            'monto_dte' => 113.58,
            'conciliacion_estado' => $estado,
            'fecha_pago' => $estado === 'pagado' ? '2026-08-25' : null,
            'monto_pagado' => $estado === 'pagado' ? 113.58 : null,
        ]);
    }

    /** @param array<string, mixed> $filtros */
    private function bandeja(array $filtros = []): Collection
    {
        return app(BandejaDocumentos::class)->consultar($filtros)['documentos'];
    }

    // ================================================== acceso y navegación

    public function test_la_bandeja_se_ve_con_permiso_de_lectura_sin_gestionar(): void
    {
        // Es una pantalla de consulta: `rutas.ver` alcanza y no debe exigir
        // `rutas.gestionar`, que es el permiso de los actos.
        $usuario = User::factory()->create(['activo' => true])
            ->assignRole('jefatura')
            ->givePermissionTo('rutas.ver');

        $this->actingAs($usuario)->get(route('rutas.documentos.index'))
            ->assertOk()
            ->assertSee('Documentos');
    }

    public function test_sin_permiso_de_rutas_no_se_entra(): void
    {
        $usuario = User::factory()->create(['activo' => true])->assignRole('facturacion');

        $this->actingAs($usuario)->get(route('rutas.documentos.index'))->assertForbidden();
    }

    public function test_un_invitado_no_llega_a_la_bandeja(): void
    {
        $this->get(route('rutas.documentos.index'))->assertRedirect(route('login'));
    }

    public function test_la_bandeja_aparece_en_la_navegacion_de_rutas(): void
    {
        $this->actingAs($this->admin())->get(route('rutas.dashboard'))
            ->assertOk()
            ->assertSee(route('rutas.documentos.index'));
    }

    // ============================================ documentos de varias salidas

    public function test_reune_documentos_de_todas_las_salidas(): void
    {
        $sonsonate = Ruta::create(['nombre' => 'Sonsonate']);
        $sanMiguel = Ruta::create(['nombre' => 'San Miguel']);

        $a = $this->salida($sonsonate);
        $b = $this->salida($sanMiguel);

        $this->documento($a, $this->ccf($this->sala($sonsonate, 'Sala A'), 'DTE-03-M001P002-000000000000001'));
        $this->documento($b, $this->ccf($this->sala($sanMiguel, 'Sala B'), 'DTE-03-M001P002-000000000000002'));

        $this->assertCount(2, $this->bandeja());
        $this->assertCount(1, $this->bandeja(['ruta_id' => $sanMiguel->id]));
        $this->assertCount(1, $this->bandeja(['salida_id' => $a->id]));
    }

    public function test_los_documentos_de_una_salida_finalizada_siguen_apareciendo(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta, '2026-08-14', EstadoSalidaRuta::Finalizada);
        $this->documento($salida, $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001'));

        // Es el punto entero de la bandeja: el cobro llega DESPUÉS de que la salida
        // terminó, así que esconderla al finalizar la volvería inútil.
        $this->assertCount(1, $this->bandeja());

        $this->actingAs($this->admin())->get(route('rutas.documentos.index'))
            ->assertOk()
            ->assertSee('DTE-03-M001P002-000000000000001');
    }

    public function test_una_salida_cancelada_tambien_se_consulta(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta, '2026-08-14', EstadoSalidaRuta::Cancelada);
        $this->documento($salida, $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001'));

        $this->assertCount(1, $this->bandeja());
    }

    // ==================================================== ventana de fechas

    public function test_la_ventana_por_defecto_deja_fuera_lo_viejo(): void
    {
        config(['rutas.bandeja_dias' => 60]);
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);

        $reciente = $this->salida($ruta, now()->subDays(5)->toDateString());
        $vieja = $this->salida($ruta, now()->subDays(200)->toDateString());

        $this->documento($reciente, $this->ccf($sala, 'DTE-03-M001P002-000000000000001'));
        $this->documento($vieja, $this->ccf($sala, 'DTE-03-M001P002-000000000000002'));

        $this->assertCount(1, $this->bandeja());

        // Se puede mover, y entonces aparece.
        $this->assertCount(2, $this->bandeja([
            'desde' => now()->subDays(365)->toDateString(),
            'hasta' => now()->toDateString(),
        ]));
    }

    public function test_una_salida_planificada_a_futuro_se_ve_desde_el_dia_que_se_arma(): void
    {
        // Se planifica para la semana que viene y se le cargan los documentos HOY. Si
        // la ventana cortara en «hoy», no aparecerían hasta el día de la salida, que
        // es justo cuando ya no hace falta buscarlos.
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta, now()->addDays(7)->toDateString());
        $this->documento($salida, $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001'));

        $this->assertCount(1, $this->bandeja());
    }

    public function test_un_rango_invertido_se_endereza_en_vez_de_devolver_vacio(): void
    {
        $bandeja = app(BandejaDocumentos::class);
        [$desde, $hasta] = $bandeja->ventana(['desde' => '2026-08-31', 'hasta' => '2026-08-01']);

        $this->assertSame('2026-08-01', $desde->toDateString());
        $this->assertSame('2026-08-31', $hasta->toDateString());
    }

    public function test_una_fecha_ilegible_cae_en_el_valor_por_defecto(): void
    {
        config(['rutas.bandeja_dias' => 60]);
        $bandeja = app(BandejaDocumentos::class);
        [$desde, $hasta] = $bandeja->ventana(['desde' => 'no-es-fecha', 'hasta' => null]);

        // Un tipeo no puede vaciar la pantalla: se ignora y manda el valor por defecto.
        $this->assertSame(now()->startOfDay()->subDays(60)->toDateString(), $desde->toDateString());
        $this->assertSame(now()->startOfDay()->addDays(60)->toDateString(), $hasta->toDateString());
    }

    // ================================================== filtros derivados

    public function test_filtra_por_entrega_con_la_misma_regla_que_muestra_la_fila(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);

        $conAlbaran = $this->ccf($sala, 'DTE-03-M001P002-000000000000001', ['numero_orden_compra' => '260600232002345']);
        $sinAlbaran = $this->ccf($sala, 'DTE-03-M001P002-000000000000002', ['numero_orden_compra' => '260600232009999']);
        $this->documento($salida, $conAlbaran);
        $this->documento($salida, $sinAlbaran);

        PpqAlbaran::create([
            'numero_albaran' => 'AC01/0232/00/1234',
            'numero_orden_compra' => '260600232002345',
            'fecha_albaran' => '2026-08-15',
        ]);

        $entregados = $this->bandeja(['entrega' => BandejaDocumentos::ENTREGA_ENTREGADO]);
        $this->assertCount(1, $entregados);
        $this->assertSame($conAlbaran->id, $entregados->first()->dte_id);
        // El filtro y la fila dicen lo mismo.
        $this->assertTrue($entregados->first()->entregado());

        $sin = $this->bandeja(['entrega' => BandejaDocumentos::ENTREGA_SIN_ALBARAN]);
        $this->assertCount(1, $sin);
        $this->assertFalse($sin->first()->entregado());
    }

    public function test_los_tres_estados_de_cobro_se_filtran_por_separado(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);

        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001')); // fuera
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000002')); // en PPQ
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000003')); // pagado

        $this->itemPpq('DTE-03-M001P002-000000000000002', null);
        $this->itemPpq('DTE-03-M001P002-000000000000003', 'pagado');

        $fuera = $this->bandeja(['ppq' => BandejaDocumentos::PPQ_FUERA]);
        $this->assertCount(1, $fuera);
        $this->assertFalse($fuera->first()->enPpq());

        $pendiente = $this->bandeja(['ppq' => BandejaDocumentos::PPQ_PENDIENTE]);
        $this->assertCount(1, $pendiente);
        $this->assertTrue($pendiente->first()->enPpq());
        $this->assertFalse($pendiente->first()->pagado());

        $pagado = $this->bandeja(['ppq' => BandejaDocumentos::PPQ_PAGADO]);
        $this->assertCount(1, $pagado);
        $this->assertTrue($pagado->first()->pagado());
    }

    public function test_fuera_de_ppq_y_en_ppq_sin_pagar_no_se_mezclan(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);

        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001'));
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000002'));
        $this->itemPpq('DTE-03-M001P002-000000000000002', null);

        $resumen = app(BandejaDocumentos::class)->consultar([])['resumen'];

        // Los dos están sin cobrar, pero son problemas distintos y se cuentan aparte.
        $this->assertSame(1, $resumen['sin_ppq']);
        $this->assertSame(1, $resumen['en_ppq']);
        $this->assertSame(0, $resumen['pagados']);
    }

    public function test_un_documento_pagado_en_un_lote_retirado_no_vuelve_a_la_bandeja_de_pendientes(): void
    {
        // «Fuera de PPQ» es la bandeja de trabajo: lo que hay que ingresar. Un documento
        // ya cobrado no es trabajo pendiente por más que su lote se haya retirado, y
        // ponerlo ahí sería mandar a reclamar algo que ya se pagó.
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);

        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001')); // nunca entró
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000002')); // cobrado, lote retirado

        $item = $this->itemPpq('DTE-03-M001P002-000000000000002', 'pagado');
        $item->lote->delete();

        $fuera = $this->bandeja(['ppq' => BandejaDocumentos::PPQ_FUERA]);
        $this->assertCount(1, $fuera);
        $this->assertSame('DTE-03-M001P002-000000000000001', $fuera->first()->numeroLegible());

        // El cobrado sigue estando donde le corresponde, aunque ya no esté presentado.
        $pagado = $this->bandeja(['ppq' => BandejaDocumentos::PPQ_PAGADO]);
        $this->assertCount(1, $pagado);
        $this->assertSame('DTE-03-M001P002-000000000000002', $pagado->first()->numeroLegible());
        $this->assertFalse($pagado->first()->enPpq());

        // Y los contadores lo dicen sin contradecirse: no está presentado y está pagado.
        $resumen = app(BandejaDocumentos::class)->consultar([])['resumen'];
        $this->assertSame(0, $resumen['en_ppq']);
        $this->assertSame(1, $resumen['pagados']);
    }

    // ==================================================== filtros duros

    public function test_filtra_por_papel_y_por_requiere_nc(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);

        $conPapel = $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001'));
        $conPapel->update(['documentacion_fisica_recibida_at' => now()]);

        $conNc = $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000002'));
        $conNc->update(['requiere_nc' => true]);

        $this->assertCount(1, $this->bandeja(['papel' => BandejaDocumentos::PAPEL_RECIBIDO]));
        $this->assertCount(1, $this->bandeja(['papel' => BandejaDocumentos::PAPEL_PENDIENTE]));
        $this->assertCount(1, $this->bandeja(['requiere_nc' => '1']));
        $this->assertCount(1, $this->bandeja(['requiere_nc' => '0']));
    }

    public function test_el_filtro_de_sala_alcanza_tambien_a_los_historicos_p001(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta, 'Selectos Metrocentro');
        $salida = $this->salida($ruta);

        // P002: la sala viaja como id.
        $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-000000000000001'));

        // P001: no hay sucursal resuelta, solo el nombre. Filtrar por id a secas lo
        // dejaría fuera en silencio, que es justo lo que no puede pasar.
        SalidaRutaDocumento::create([
            'salida_ruta_id' => $salida->id,
            'dte_id' => null,
            'numero_control' => 'DTE-03-M001P001-000000000000940',
            'origen' => SalidaRutaDocumento::ORIGEN_P001,
            'sala_nombre' => 'Selectos Metrocentro',
            'monto' => 90.00,
            'asignado_at' => now(),
            'bloqueo_asignacion' => 1,
        ]);

        $this->assertCount(2, $this->bandeja(['sucursal_id' => $sala->id]));
    }

    // ============================================== el resumen y la pantalla

    public function test_el_resumen_cuenta_todo_el_filtro_no_solo_la_pagina(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala = $this->sala($ruta);
        $salida = $this->salida($ruta);

        foreach (range(1, 4) as $n) {
            $this->documento($salida, $this->ccf($sala, 'DTE-03-M001P002-00000000000000'.$n));
        }

        $resultado = app(BandejaDocumentos::class)->consultar([]);

        $this->assertSame(4, $resultado['resumen']['total']);
        $this->assertSame($resultado['documentos']->count(), $resultado['resumen']['total']);
    }

    public function test_la_pantalla_muestra_las_columnas_y_los_contadores(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $this->documento($salida, $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001'));

        $this->actingAs($this->admin())->get(route('rutas.documentos.index'))
            ->assertOk()
            ->assertSee('Cobro / PPQ')
            ->assertSee('Fuera de PPQ')
            ->assertSee('En PPQ sin pagar')
            ->assertSee('Sin albarán')
            ->assertSee('San Miguel');
    }

    // ================================================== no escribe ni audita

    public function test_mirar_la_bandeja_no_escribe_ni_audita(): void
    {
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $salida = $this->salida($ruta);
        $documento = $this->documento($salida, $this->ccf($this->sala($ruta), 'DTE-03-M001P002-000000000000001'));
        $item = $this->itemPpq('DTE-03-M001P002-000000000000001', 'pagado');

        // El admin se crea ANTES de contar: dar de alta un usuario sí se audita, y
        // contarlo después metería ese registro en la cuenta de la consulta.
        $admin = $this->admin();

        $antesDocumento = $documento->fresh()->toArray();
        $antesItem = $item->fresh()->toArray();
        $antesActividad = Activity::count();

        $this->actingAs($admin)->get(route('rutas.documentos.index'))->assertOk();

        $this->assertSame($antesDocumento, $documento->fresh()->toArray());
        $this->assertSame($antesItem, $item->fresh()->toArray());
        $this->assertSame($antesActividad, Activity::count());
    }
}
