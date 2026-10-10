<?php

namespace Tests\Feature\Ppq;

use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Exceptions\Ppq\ArchivoQuedanIncompletoException;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\DteAlbaran;
use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\User;
use App\Services\Ppq\ExcelCallejaExporter;
use App\Services\Ppq\QuedanCallejaExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * Archivo de carga masiva de «Solicitud de Quedan» del portal de Calleja, armado desde un
 * lote PPQ ya construido: 5 columnas, solo CCF, todo o nada. No debe confundirse con el
 * Excel PPQ de 10 columnas ({@see ExcelCallejaExporter}), que sigue intacto.
 */
class QuedanCallejaExporterTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    private $estab = null;

    private const OC_A = '26050230001794'; // sala 0230

    private const OC_B = '26050231001795'; // sala 0231

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(): User
    {
        return User::factory()->create()->assignRole(RolSistema::Jefatura->value);
    }

    private function lote(): PpqLote
    {
        return PpqLote::create(['referencia' => 'Quedan', 'fecha' => now(), 'estado' => 'borrador']);
    }

    private function albaran(string $numero, string $oc, string $fecha = '2026-05-15', ?string $tipo = null): PpqAlbaran
    {
        return PpqAlbaran::create([
            'numero_albaran' => $numero,
            'numero_orden_compra' => $oc,
            'monto_albaran' => 10.00,
            'fecha_albaran' => $fecha,
            'origen' => 'gmail',
            'tipo_codigo' => $tipo,
        ]);
    }

    private function ccf(PpqLote $lote, string $control, string $oc, ?PpqAlbaran $albaran, float $monto = 10.00): PpqItem
    {
        return $lote->items()->create([
            'origen' => 'gmail',
            'tipo_dte' => '03',
            'numero_control' => $control,
            'numero_orden_compra' => $oc,
            'monto_dte' => $monto,
            'ppq_albaran_id' => $albaran?->id,
            'monto_albaran' => $albaran?->monto_albaran,
            'sin_albaran' => $albaran === null,
        ]);
    }

    private function nc(PpqLote $lote, string $control, string $oc): PpqItem
    {
        return $lote->items()->create([
            'origen' => 'gmail',
            'tipo_dte' => '05',
            'numero_control' => $control,
            'numero_orden_compra' => $oc,
            'monto_dte' => 5.00,
            'sin_albaran' => true,
        ]);
    }

    private function exportador(): QuedanCallejaExporter
    {
        return app(QuedanCallejaExporter::class);
    }

    private function casoVinculoManual(bool $manual = true, bool $incoherente = false): PpqLote
    {
        config(['ppq.quedan.salas_confirmadas' => []]);
        $cliente = Cliente::factory()->contribuyente()->create();
        $lote = $this->lote();
        $lote->update(['cliente_id' => $cliente->id]);
        $albaran = $this->albaran('AC01/0262/00/247', '26050262001794');
        if ($incoherente) {
            $albaran->update(['sala_codigo' => '0620']);
        }
        $item = $this->ccf($lote, 'DTE-03-M001P002-000000000000247', '26050620001794', $albaran);
        CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => 'externo',
            'tipo_dte' => '03',
            'numero_control' => str_replace('-', '', $item->numero_control),
            'fecha_emision' => '2026-05-15',
            'monto' => 10,
            'ppq_albaran_id' => $albaran->id,
            'vinculado_por' => $manual ? $this->usuario()->id : null,
            'vinculado_en' => '2026-10-06 10:00:00',
        ]);

        return $lote;
    }

    public function test_vinculo_manual_confirma_sala_del_numero_y_la_muestra_en_la_ficha(): void
    {
        $lote = $this->casoVinculoManual();
        $ruta = $this->exportador()->generar($lote->fresh());
        $libro = IOFactory::load($ruta);
        @unlink($ruta);
        $this->assertSame('0262', $libro->getActiveSheet()->getCell('A2')->getValue());
        $libro->disconnectWorksheets();
        $this->actingAs($this->usuario())->get(route('ppq.lotes.show', $lote))
            ->assertOk()
            ->assertSee('Sala confirmada por vínculo manual (0262)')
            ->assertSee('sala confirmada por vínculo manual de')
            ->assertSee('06/10/2026 10:00');
    }

    public function test_vinculo_sin_usuario_no_confirma_sala(): void
    {
        $lote = $this->casoVinculoManual(manual: false);
        $this->expectExceptionMessage('contradicción de sala');
        $this->exportador()->generar($lote->fresh());
    }

    public function test_vinculo_manual_no_permite_sala_incoherente_en_el_propio_albaran(): void
    {
        $lote = $this->casoVinculoManual(incoherente: true);
        $this->expectExceptionMessage('contradicción de sala');
        $this->exportador()->generar($lote->fresh());
    }

    public function test_configuracion_tiene_prioridad_sobre_vinculo_manual(): void
    {
        $lote = $this->casoVinculoManual();
        config(['ppq.quedan.salas_confirmadas' => ['DTE-03-M001P002-000000000000247' => '0620']]);
        $this->expectExceptionMessage('sala confirmada 0620, pero el número del albarán indica 0262');
        $this->exportador()->generar($lote->fresh());
    }

    public function test_genera_encabezados_hoja_tipos_y_orden_de_la_plantilla(): void
    {
        $lote = $this->lote();
        $albA = $this->albaran('AC01/0230/00/200', self::OC_A);
        $albB = $this->albaran('AC01/0231/00/50', self::OC_B);
        // Correlativo 200 y 50: en el orden de Calleja el 50 va primero (ascendente).
        $this->ccf($lote, 'DTE-03-M001P002-000000000000200', self::OC_A, $albA);
        $this->ccf($lote, 'DTE-03-M001P002-000000000000050', self::OC_B, $albB);

        $ruta = $this->exportador()->generar($lote->fresh());
        $libro = IOFactory::load($ruta);
        @unlink($ruta);

        $this->assertSame(['Hoja3'], $libro->getSheetNames());
        $hoja = $libro->getActiveSheet();

        $this->assertSame(QuedanCallejaExporter::columnas(), [
            (string) $hoja->getCell('A1')->getValue(),
            (string) $hoja->getCell('B1')->getValue(),
            (string) $hoja->getCell('C1')->getValue(),
            (string) $hoja->getCell('D1')->getValue(),
            (string) $hoja->getCell('E1')->getValue(),
        ]);

        // Fila 2: correlativo 50 (va primero); fila 3: correlativo 200.
        $this->assertSame('0231', $hoja->getCell('A2')->getValue());
        $this->assertSame('50', $hoja->getCell('B2')->getValue());
        $this->assertSame(26, $hoja->getCell('C2')->getValue());
        $this->assertSame(5, $hoja->getCell('D2')->getValue());
        $this->assertSame('AC01', $hoja->getCell('E2')->getValue());

        $this->assertSame('0230', $hoja->getCell('A3')->getValue());
        $this->assertSame('200', $hoja->getCell('B3')->getValue());

        // Tipos de celda: A, B, E texto; C, D numéricos.
        foreach (['A2', 'B2', 'E2', 'A3', 'B3', 'E3'] as $celda) {
            $this->assertSame(DataType::TYPE_STRING, $hoja->getCell($celda)->getDataType(), $celda);
        }
        foreach (['C2', 'D2', 'C3', 'D3'] as $celda) {
            $this->assertNotSame(DataType::TYPE_STRING, $hoja->getCell($celda)->getDataType(), $celda);
        }

        $this->assertSame(3, $hoja->getHighestDataRow(), 'Encabezado + 2 CCF, ninguna fila extra.');
    }

    /** NC local con su albarán de crédito propio, como queda al emitirla. */
    private function ncConAlbaran(PpqLote $lote, string $control, ?string $albaran, string $estado = 'aceptado'): PpqItem
    {
        $this->estab ??= $this->crearEmisorDte()['estab'];
        $dte = Dte::create([
            'establecimiento_id' => $this->estab->id,
            'tipo_dte' => '05',
            'estado' => $estado,
            'ambiente' => '01',
            'numero_control' => $control,
            'codigo_generacion' => strtoupper(Str::uuid()->toString()),
            'sello_recepcion' => '2026SELLOREAL'.Str::random(8),
            'fecha_procesamiento_mh' => now(),
            'fecha_emision' => '2026-09-10',
            'hora_emision' => '08:00:00',
            'total_pagar' => 5.00,
        ]);
        if ($albaran !== null) {
            DteAlbaran::create([
                'dte_id' => $dte->id,
                'numero_canonico' => $albaran,
                'tipo_codigo' => explode('/', $albaran)[0],
                'sala_codigo' => explode('/', $albaran)[1],
                'numero' => explode('/', $albaran)[3],
                'fecha' => '2026-08-26',
                'total' => 5.00,
            ]);
        }

        return $lote->items()->create([
            'dte_id' => $dte->id,
            'origen' => 'local',
            'tipo_dte' => '05',
            'numero_control' => $control,
            'numero_orden_compra' => self::OC_A,
            'monto_dte' => 5.00,
            'sin_albaran' => true,
        ]);
    }

    public function test_las_nc_van_despues_de_los_ccf_con_su_albaran_de_credito(): void
    {
        $lote = $this->lote();
        $this->ccf($lote, 'DTE-03-M001P002-000000000000010', self::OC_A, $this->albaran('AC01/0230/00/10', self::OC_A));
        $this->ncConAlbaran($lote, 'DTE-05-M001P002-000000000000001', 'AC04/0207/00/3874');
        $this->ncConAlbaran($lote, 'DTE-05-M001P002-000000000000002', 'AC02/0219/00/4534');
        $this->ncConAlbaran($lote, 'DTE-05-M001P002-000000000000003', 'AC02/0001/00/9', estado: 'invalidado');

        $ruta = $this->exportador()->generar($lote->fresh());
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);

        $this->assertSame(4, $hoja->getHighestDataRow(), 'Encabezado + 1 CCF + 2 NC; la invalidada no va.');
        $this->assertSame('AC01', $hoja->getCell('E2')->getValue());
        $this->assertSame(['0207', '3874', 26, 8, 'AC04'], [
            $hoja->getCell('A3')->getValue(), $hoja->getCell('B3')->getValue(),
            $hoja->getCell('C3')->getValue(), $hoja->getCell('D3')->getValue(), $hoja->getCell('E3')->getValue(),
        ]);
        $this->assertSame('4534', $hoja->getCell('B4')->getValue());
        $this->assertSame('AC02', $hoja->getCell('E4')->getValue());
    }

    public function test_nc_de_un_lote_anterior_borrado_se_incluye_en_el_nuevo_quedan(): void
    {
        $anterior = $this->lote();
        $nc = $this->ncConAlbaran($anterior, 'DTE-05-M001P002-000000000090008', 'AC02/0207/00/3854');
        $anterior->delete();

        $this->assertSoftDeleted($anterior);
        $this->assertTrue($anterior->items()->whereKey($nc->id)->exists());

        $lote = $this->lote();
        $this->ccf($lote, 'DTE-03-M001P002-000000000000010', self::OC_A, $this->albaran('AC01/0230/00/10', self::OC_A));
        $lote->items()->create([
            'dte_id' => $nc->dte_id,
            'origen' => 'local',
            'tipo_dte' => '05',
            'numero_control' => $nc->numero_control,
            'monto_dte' => 5.00,
            'sin_albaran' => true,
        ]);

        $ruta = $this->exportador()->generar($lote->fresh());
        $libro = IOFactory::load($ruta);
        $hoja = $libro->getActiveSheet();
        @unlink($ruta);

        $this->assertSame(3, $hoja->getHighestDataRow(), 'Encabezado + 1 CCF + la NC del lote borrado.');
        $this->assertSame('AC01', $hoja->getCell('E2')->getValue());
        $this->assertSame(['0207', '3854', 26, 8, 'AC02'], [
            $hoja->getCell('A3')->getValue(), $hoja->getCell('B3')->getValue(),
            $hoja->getCell('C3')->getValue(), $hoja->getCell('D3')->getValue(), $hoja->getCell('E3')->getValue(),
        ]);
        $libro->disconnectWorksheets();
    }

    /** Casos reales del portal (25/09/2026): 3854 repetida, 4684 de otra sala, 4534 vs 4354. */
    public function test_nc_usa_el_albaran_del_ppq_la_sala_confirmada_y_no_repite_lo_ya_presentado(): void
    {
        $anterior = $this->lote();
        $lote = $this->lote();
        $this->ccf($lote, 'DTE-03-M001P002-000000000000010', self::OC_A, $this->albaran('AC01/0230/00/10', self::OC_A));

        // Ya viajó en un lote anterior: no se repite.
        $repetida = $this->ncConAlbaran($lote, 'DTE-05-M001P002-000000000090008', 'AC02/0207/00/3854');
        $anterior->items()->create(['dte_id' => $repetida->dte_id, 'origen' => 'local', 'tipo_dte' => '05',
            'numero_control' => $repetida->numero_control, 'monto_dte' => 5.00, 'sin_albaran' => true]);

        // El PPQ tiene 4354; la NC se registró con 4534 (error de tecleo): manda el PPQ.
        $tecleo = $this->ncConAlbaran($lote, 'DTE-05-M001P002-000000000000036', 'AC02/0219/00/4534');
        $ppq = PpqAlbaran::create(['numero_albaran' => '4354', 'sala_codigo' => '0219', 'fecha_albaran' => '2026-09-09', 'origen' => 'manual']);
        $tecleo->update(['ppq_albaran_id' => $ppq->id, 'sin_albaran' => false]);

        // Sala confirmada a mano para la NC.
        config(['ppq.quedan.salas_confirmadas' => ['DTE-05-M001P002-000000000000029' => '0058']]);
        $this->ncConAlbaran($lote, 'DTE-05-M001P002-000000000000029', 'AC02/0059/00/4684');

        $ruta = $this->exportador()->generar($lote->fresh());
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);

        $filas = array_slice($hoja->toArray(), 1);
        $this->assertCount(3, $filas, '1 CCF + 2 NC; la ya presentada no va.');
        $numeros = array_map(fn ($f) => $f[0].'/'.$f[1].'/'.$f[4], $filas);
        $this->assertContains('0219/4354/AC02', $numeros);
        $this->assertContains('0058/4684/AC02', $numeros);
        $this->assertNotContains('0207/3854/AC02', $numeros);
    }

    public function test_nc_sin_albaran_de_credito_bloquea_el_archivo(): void
    {
        $lote = $this->lote();
        $this->ccf($lote, 'DTE-03-M001P002-000000000000010', self::OC_A, $this->albaran('AC01/0230/00/10', self::OC_A));
        $this->ncConAlbaran($lote, 'DTE-05-M001P002-000000000000001', null);

        $this->expectExceptionMessage('la NC no tiene completo su albarán de crédito');
        $this->exportador()->generar($lote->fresh());
    }

    public function test_lote_sin_ccf_se_bloquea(): void
    {
        $lote = $this->lote();
        $this->nc($lote, 'DTE-05-M001P002-000000000000001', self::OC_A);

        $this->expectException(ArchivoQuedanIncompletoException::class);
        $this->exportador()->generar($lote->fresh());
    }

    public function test_ccf_sin_albaran_bloquea_el_archivo_completo(): void
    {
        $lote = $this->lote();
        $alb = $this->albaran('AC01/0230/00/10', self::OC_A);
        $this->ccf($lote, 'DTE-03-M001P002-000000000000010', self::OC_A, $alb);
        $this->ccf($lote, 'DTE-03-M001P002-000000000000011', self::OC_A, null); // sin albarán

        try {
            $this->exportador()->generar($lote->fresh());
            $this->fail('Debió lanzar ArchivoQuedanIncompletoException.');
        } catch (ArchivoQuedanIncompletoException $e) {
            $this->assertStringContainsString('000000000000011', $e->getMessage());
            $this->assertStringContainsString('sin albarán', $e->getMessage());
        }
    }

    public function test_albaran_que_no_es_de_entrega_bloquea_el_archivo(): void
    {
        $lote = $this->lote();
        // Albarán de CRÉDITO (AC02), no de entrega: no prueba una entrega.
        $alb = $this->albaran('AC02/0230/00/10', self::OC_A, tipo: 'AC02');
        $this->ccf($lote, 'DTE-03-M001P002-000000000000010', self::OC_A, $alb);

        try {
            $this->exportador()->generar($lote->fresh());
            $this->fail('Debió lanzar ArchivoQuedanIncompletoException.');
        } catch (ArchivoQuedanIncompletoException $e) {
            $this->assertStringContainsString('no es de entrega', $e->getMessage());
        }
    }

    public function test_dos_ccf_con_el_mismo_albaran_bloquean_el_archivo(): void
    {
        $lote = $this->lote();
        $alb = $this->albaran('AC01/0230/00/10', self::OC_A);
        $this->ccf($lote, 'DTE-03-M001P002-000000000000010', self::OC_A, $alb);
        $this->ccf($lote, 'DTE-03-M001P002-000000000000011', self::OC_A, $alb);

        try {
            $this->exportador()->generar($lote->fresh());
            $this->fail('Debió lanzar ArchivoQuedanIncompletoException.');
        } catch (ArchivoQuedanIncompletoException $e) {
            $this->assertStringContainsString('mismo albarán', $e->getMessage());
        }
    }

    public function test_contradiccion_de_sala_bloquea_el_archivo(): void
    {
        $lote = $this->lote();
        // El CCF va con OC de la sala 0230, pero el albarán vinculado es (consistentemente
        // consigo mismo) de la sala 0231: contradicción entre el documento y su albarán.
        $albOtraSala = $this->albaran('AC01/0231/00/10', self::OC_B);
        $this->ccf($lote, 'DTE-03-M001P002-000000000000010', self::OC_A, $albOtraSala);

        try {
            $this->exportador()->generar($lote->fresh());
            $this->fail('Debió lanzar ArchivoQuedanIncompletoException.');
        } catch (ArchivoQuedanIncompletoException $e) {
            $this->assertStringContainsString('contradicción de sala', $e->getMessage());
        }
    }

    /** Caso 0136: albarán real de la sala 0231 guardado con la sala de la OC (0230). */
    private function ccfConAlbaranDeOtraSala(PpqLote $lote): void
    {
        $alb = $this->albaran('AC01/0231/00/4681', self::OC_A);
        $alb->update(['sala_codigo' => '0230']);
        $this->ccf($lote, 'DTE-03-M001P002-000000000000136', self::OC_A, $alb);
    }

    public function test_sala_confirmada_que_coincide_con_el_albaran_desbloquea_y_usa_esa_sala(): void
    {
        config(['ppq.quedan.salas_confirmadas' => ['DTE-03-M001P002-000000000000136' => '231']]);
        $lote = $this->lote();
        $this->ccfConAlbaranDeOtraSala($lote);

        $ruta = $this->exportador()->generar($lote->fresh());
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);

        $this->assertSame('0231', $hoja->getCell('A2')->getValue());
        $this->assertSame('4681', $hoja->getCell('B2')->getValue());
        $this->assertSame(2, $hoja->getHighestDataRow());
    }

    public function test_sin_confirmacion_la_contradiccion_de_sala_sigue_bloqueando(): void
    {
        $lote = $this->lote();
        $this->ccfConAlbaranDeOtraSala($lote);

        $this->expectExceptionMessage('contradicción de sala');
        $this->exportador()->generar($lote->fresh());
    }

    public function test_sala_confirmada_distinta_del_numero_del_albaran_bloquea(): void
    {
        config(['ppq.quedan.salas_confirmadas' => ['DTE-03-M001P002-000000000000136' => '0230']]);
        $lote = $this->lote();
        $this->ccfConAlbaranDeOtraSala($lote);

        $this->expectExceptionMessage('sala confirmada 0230, pero el número del albarán indica 0231');
        $this->exportador()->generar($lote->fresh());
    }

    public function test_documento_ajeno_a_ccf_o_nc_bloquea_el_archivo(): void
    {
        $lote = $this->lote();
        $alb = $this->albaran('AC01/0230/00/10', self::OC_A);
        $item = $this->ccf($lote, 'DTE-01-M001P002-000000000000010', self::OC_A, $alb);
        $item->update(['tipo_dte' => '01']);

        $this->expectExceptionMessage('no es un CCF');
        $this->exportador()->generar($lote->fresh());
    }

    public function test_numero_de_albaran_ilegible_bloquea_el_archivo(): void
    {
        $lote = $this->lote();
        $alb = $this->albaran('pendiente de confirmar', self::OC_A, tipo: 'AC01');
        $this->ccf($lote, 'DTE-03-M001P002-000000000000010', self::OC_A, $alb);

        $this->expectExceptionMessage('número confiable');
        $this->exportador()->generar($lote->fresh());
    }

    public function test_tipo_del_numero_contradictorio_bloquea_el_archivo(): void
    {
        $lote = $this->lote();
        $alb = $this->albaran('AC02/0230/00/10', self::OC_A, tipo: 'AC01');
        $this->ccf($lote, 'DTE-03-M001P002-000000000000010', self::OC_A, $alb);

        $this->expectExceptionMessage('contradicción de tipo');
        $this->exportador()->generar($lote->fresh());
    }

    public function test_no_muta_el_lote_ni_sus_estados_al_descargar(): void
    {
        $lote = $this->lote();
        $alb = $this->albaran('AC01/0230/00/10', self::OC_A);
        $this->ccf($lote, 'DTE-03-M001P002-000000000000010', self::OC_A, $alb);

        $estadoAntes = $lote->fresh()->estado;
        $cantidadAntes = $lote->items()->count();

        $ruta = $this->exportador()->generar($lote->fresh());
        @unlink($ruta);

        $this->assertSame($estadoAntes, $lote->fresh()->estado);
        $this->assertSame($cantidadAntes, $lote->fresh()->items()->count());
    }

    public function test_el_excel_ppq_de_diez_columnas_sigue_intacto(): void
    {
        $lote = $this->lote();
        $alb = $this->albaran('AC01/0230/00/10', self::OC_A);
        $this->ccf($lote, 'DTE-03-M001P002-000000000000010', self::OC_A, $alb);
        $this->nc($lote, 'DTE-05-M001P002-000000000000001', self::OC_A);

        $ruta = app(ExcelCallejaExporter::class)->generar($lote->fresh());
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);

        $this->assertSame('Número de orden de compra', (string) $hoja->getCell('A1')->getValue());
        $this->assertSame(3, $hoja->getHighestDataRow(), 'Encabezado + CCF + NC: el Excel PPQ sigue llevando ambos.');
    }

    public function test_ruta_de_descarga_sirve_el_archivo_para_un_lote_valido(): void
    {
        $lote = $this->lote();
        $alb = $this->albaran('AC01/0230/00/10', self::OC_A);
        $this->ccf($lote, 'DTE-03-M001P002-000000000000010', self::OC_A, $alb);

        $respuesta = $this->actingAs($this->usuario())->get(route('ppq.lotes.quedan', $lote));

        $respuesta->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $respuesta->headers->get('content-type'));
    }

    public function test_ruta_de_descarga_redirige_con_error_para_un_lote_incompleto(): void
    {
        $lote = $this->lote();
        $this->ccf($lote, 'DTE-03-M001P002-000000000000010', self::OC_A, null);

        $respuesta = $this->actingAs($this->usuario())->get(route('ppq.lotes.quedan', $lote));

        $respuesta->assertRedirect(route('ppq.lotes.show', $lote));
        $respuesta->assertSessionHas('error');
        $this->assertStringContainsString('sin albarán', (string) session('error'));
    }
}
