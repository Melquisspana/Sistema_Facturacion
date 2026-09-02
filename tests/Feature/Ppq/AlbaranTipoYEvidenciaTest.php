<?php

namespace Tests\Feature\Ppq;

use App\Models\Cliente;
use App\Models\Dte;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\PpqAlbaran;
use App\Models\Ruta;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaDocumento;
use App\Services\Ppq\AlbaranPersistidor;
use App\Services\Ppq\PpqGmailService;
use App\Services\Rutas\AlbaranLocalizador;
use App\Services\Rutas\AsignadorDocumentos;
use App\Services\Rutas\ResolucionAlbaran;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Solo un albarán de ENTREGA prueba una entrega, y solo cuando es el único candidato.
 *
 * ─────────────────────────── Los dos errores que cierra ───────────────────────────
 *
 * 1. EL TIPO. El cliente manda por correo albaranes de entrega (AC01) y de crédito
 *    (AC02 avería, AC04 devolución). Como el vínculo se hace por ORDEN DE COMPRA y una
 *    misma OC ampara los dos, un documento podía quedar «entregado» apoyándose en un
 *    albarán de abono —y tomar de él el monto contra el que se calcula la diferencia—.
 *    En la base de desarrollo hay 26 albaranes de crédito cargados a mano sobre la misma
 *    OC que su AC01.
 *
 * 2. «EL PRIMERO». Cuando había varios, el código se quedaba con el primero que la
 *    consulta devolviera, sin orden definido. Elegir mal pinta una entrega que nadie hizo.
 *
 * Y la tercera pieza: la EVIDENCIA. El PDF se bajaba, se parseaba con expresiones
 * regulares y se tiraba. Si el correo desaparece, la prueba de la entrega desaparece con
 * él.
 */
class AlbaranTipoYEvidenciaTest extends TestCase
{
    use RefreshDatabase;

    private const OC = '260602320012345';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Lo que se prueba acá es la evidencia del albarán, no el interruptor de la
        // sincronización automática (que arranca apagado en toda la suite y tiene sus
        // propias pruebas en PpqSincronizacionAutomaticaTest).
        config(['ppq.albaranes.sincronizacion_automatica' => true]);
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

    private function albaran(string $numero, float $monto, ?string $oc = self::OC): PpqAlbaran
    {
        return PpqAlbaran::create([
            'numero_albaran' => $numero,
            'numero_orden_compra' => $oc,
            'monto_albaran' => $monto,
            'fecha_albaran' => now()->toDateString(),
            'origen' => 'gmail',
        ]);
    }

    /** Documento de una salida, con su CCF real detrás. */
    private function documentoEnSalida(): SalidaRutaDocumento
    {
        $cliente = Cliente::factory()->create(['nombre' => 'Calleja']);
        $sala = $cliente->sucursales()->create(['nombre' => 'Selectos San Miguel', 'codigo' => '0232']);
        $ruta = Ruta::create(['nombre' => 'San Miguel']);
        $sala->update(['ruta_id' => $ruta->id]);

        $salida = SalidaRuta::create([
            'ruta_id' => $ruta->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => 'en_curso',
        ]);

        $dte = Dte::create([
            'establecimiento_id' => $this->establecimiento()->id,
            'tipo_dte' => '03',
            'estado' => 'aceptado',
            'ambiente' => '01',
            'sello_recepcion' => '2026'.strtoupper(Str::random(36)),
            'fecha_procesamiento_mh' => now(),
            'cliente_id' => $cliente->id,
            'cliente_sucursal_id' => $sala->id,
            'numero_control' => 'DTE-03-M001P002-000000000000001',
            'numero_orden_compra' => self::OC,
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => '10:00:00',
            'total_pagar' => 136.33,
        ]);

        return app(AsignadorDocumentos::class)->agregarDte($salida, $dte, null);
    }

    // ═════════════════════════ el tipo se deriva del propio número

    public function test_el_tipo_se_deriva_del_numero_al_guardar(): void
    {
        $this->assertSame('AC01', $this->albaran('AC01/0232/00/6715', 136.33)->tipo_codigo);
        $this->assertSame('AC02', $this->albaran('AC02/0232/00/6836', 2.89, '260602320099999')->tipo_codigo);
        $this->assertSame('AC04', $this->albaran('AC04/0033/00/3209', 6.24, '260600330011111')->tipo_codigo);

        // Un número suelto —como los 26 capturados a mano— NO permite determinar el tipo.
        // Queda en null, que significa «no consta», nunca «AC01».
        $this->assertNull($this->albaran('3211', 1.93, '260602520020871')->tipo_codigo);
    }

    public function test_solo_el_albaran_de_entrega_dice_que_es_de_entrega(): void
    {
        $this->assertTrue($this->albaran('AC01/0232/00/6715', 136.33)->esDeEntrega());
        $this->assertFalse($this->albaran('AC02/0232/00/6836', 2.89, '260602320099999')->esDeEntrega());
        $this->assertFalse($this->albaran('AC04/0033/00/3209', 6.24, '260600330011111')->esDeEntrega());
        $this->assertFalse($this->albaran('3211', 1.93, '260602520020871')->esDeEntrega());
    }

    // ═════════════════════════ la resolución

    public function test_un_unico_ac01_marca_el_documento_como_entregado(): void
    {
        $documento = $this->documentoEnSalida();
        $entrega = $this->albaran('AC01/0232/00/6715', 136.33);

        $this->assertTrue($documento->entregado());
        $this->assertSame($entrega->id, $documento->albaran()->id);
        $this->assertNull($documento->entregaExcepcion());
    }

    public function test_un_albaran_de_credito_sobre_la_misma_oc_no_marca_entrega(): void
    {
        $documento = $this->documentoEnSalida();

        // Solo el AC02 de la nota de crédito. La OC es la misma, el monto es el del abono.
        $this->albaran('AC02/0232/00/6836', 2.89);

        $this->assertFalse($documento->entregado());
        $this->assertNull($documento->albaran());
        $this->assertStringContainsString('no consta que sea de entrega', (string) $documento->entregaExcepcion());
    }

    public function test_el_ac01_gana_aunque_convivan_el_de_entrega_y_el_de_credito(): void
    {
        $documento = $this->documentoEnSalida();

        // Es el caso REAL de la base: la OC tiene el AC01 de la entrega y el albarán de
        // crédito de la NC, cargado a mano con el número suelto.
        $this->albaran('3211', 1.93);
        $entrega = $this->albaran('AC01/0232/00/6715', 136.33);

        $this->assertTrue($documento->entregado());
        $this->assertSame($entrega->id, $documento->albaran()->id);
        // Y el monto que se usa es el de la entrega, no el del abono.
        $this->assertSame('136.33', (string) $documento->albaran()->monto_albaran);
    }

    public function test_con_dos_ac01_para_la_misma_oc_no_se_elige_ninguno(): void
    {
        $documento = $this->documentoEnSalida();

        // Caso real: una OC con dos albaranes de entrega, de salas distintas.
        $this->albaran('AC01/0228/00/6556', 136.33);
        $this->albaran('AC01/0256/00/4816', 120.00);

        $this->assertFalse($documento->entregado());
        $this->assertStringContainsString('elegí a mano', (string) $documento->entregaExcepcion());
    }

    public function test_un_albaran_sin_tipo_no_se_supone_de_entrega(): void
    {
        $documento = $this->documentoEnSalida();
        $this->albaran('3474', 9.31);

        $this->assertFalse($documento->entregado());
        $this->assertNotNull($documento->entregaExcepcion());
    }

    public function test_sin_ningun_albaran_no_hay_excepcion_solo_espera(): void
    {
        $documento = $this->documentoEnSalida();

        $this->assertFalse($documento->entregado());
        // Esperar el albarán es lo normal; llamarlo excepción llenaría la bandeja de ruido.
        $this->assertNull($documento->entregaExcepcion());
    }

    // ═════════════════════════ el vínculo explícito NO exime del tipo

    public function test_un_ac01_vinculado_por_dte_id_marca_entrega(): void
    {
        $documento = $this->documentoEnSalida();

        $albaran = $this->albaran('AC01/0232/00/6715', 136.33, '260609990099999');
        $albaran->update(['dte_id' => $documento->dte_id]);

        $resolucion = app(AlbaranLocalizador::class)->paraUno($documento->dte_id, $documento->orden());

        $this->assertTrue($resolucion->estaVinculado());
        $this->assertSame($albaran->id, $resolucion->albaran->id);
        $this->assertNull($resolucion->motivo());
    }

    public function test_un_ac02_de_averia_vinculado_por_dte_id_no_marca_entrega(): void
    {
        $this->assertVinculoExplicitoNoPruebaEntrega('AC02/0232/00/6836');
    }

    public function test_un_ac04_de_devolucion_vinculado_por_dte_id_no_marca_entrega(): void
    {
        $this->assertVinculoExplicitoNoPruebaEntrega('AC04/0033/00/3209');
    }

    public function test_un_albaran_de_tipo_desconocido_vinculado_por_dte_id_no_marca_entrega(): void
    {
        // Número suelto, sin prefijo: no se puede saber qué es, y «no se sabe» nunca
        // equivale a «es de entrega».
        $this->assertVinculoExplicitoNoPruebaEntrega('3211');
    }

    /**
     * Vincula ese albarán al documento por `dte_id` y comprueba que NO marca entrega.
     *
     * La orden de compra del albarán es distinta a la del documento a propósito: así lo
     * único que los une es el vínculo explícito, y queda claro que lo que se prueba es esa
     * vía y no otra.
     */
    private function assertVinculoExplicitoNoPruebaEntrega(string $numero): void
    {
        $documento = $this->documentoEnSalida();

        $this->albaran($numero, 2.89, '260609990099999')->update(['dte_id' => $documento->dte_id]);

        $resolucion = app(AlbaranLocalizador::class)->paraUno($documento->dte_id, $documento->orden());

        // El vínculo explícito dice DE QUIÉN es el albarán, no que pruebe una entrega.
        $this->assertFalse($resolucion->estaVinculado());
        $this->assertNull($resolucion->albaran);
        $this->assertTrue($resolucion->esExcepcion());
        $this->assertStringContainsString('no es de entrega', (string) $resolucion->motivo());
    }

    public function test_un_credito_vinculado_no_deja_que_la_oc_tape_el_error(): void
    {
        $documento = $this->documentoEnSalida();

        // Hay un AC01 legítimo por la orden de compra…
        $this->albaran('AC01/0232/00/6715', 136.33);
        // …y alguien vinculó a mano el albarán de crédito de la NC a este mismo documento.
        $credito = $this->albaran('AC02/0232/00/6836', 2.89, '260609990099999');
        $credito->update(['dte_id' => $documento->dte_id]);

        $resolucion = app(AlbaranLocalizador::class)->paraUno($documento->dte_id, $documento->orden());

        // NO se cae a la orden de compra: si lo hiciera, el documento aparecería entregado
        // y el vínculo mal puesto no se vería nunca.
        $this->assertFalse($resolucion->estaVinculado());
        $this->assertTrue($resolucion->esExcepcion());
        $this->assertFalse($documento->entregado());
        $this->assertNotNull($documento->entregaExcepcion());
    }

    public function test_el_ac01_explicito_gana_sobre_varios_candidatos_por_orden_de_compra(): void
    {
        $documento = $this->documentoEnSalida();

        // Dos AC01 comparten la OC: por esa vía no se elige ninguno.
        $this->albaran('AC01/0228/00/6556', 136.33);
        $elegido = $this->albaran('AC01/0256/00/4816', 120.00);

        $this->assertFalse($documento->entregado());

        // En cuanto alguien establece el vínculo explícito sobre uno de ellos —y es de
        // entrega—, la ambigüedad desaparece.
        $elegido->update(['dte_id' => $documento->dte_id]);

        $resolucion = app(AlbaranLocalizador::class)->paraUno($documento->dte_id, $documento->orden());

        $this->assertTrue($resolucion->estaVinculado());
        $this->assertSame($elegido->id, $resolucion->albaran->id);
    }

    public function test_dos_albaranes_de_entrega_vinculados_al_mismo_documento_no_eligen_ninguno(): void
    {
        $documento = $this->documentoEnSalida();

        // El índice por `dte_id` también AGRUPA: antes se quedaba con el último que pasara.
        foreach (['AC01/0228/00/6556', 'AC01/0256/00/4816'] as $i => $numero) {
            $this->albaran($numero, 136.33, '26060999009999'.$i)->update(['dte_id' => $documento->dte_id]);
        }

        $resolucion = app(AlbaranLocalizador::class)->paraUno($documento->dte_id, $documento->orden());

        $this->assertFalse($resolucion->estaVinculado());
        $this->assertStringContainsString('vinculados a este documento', (string) $resolucion->motivo());
    }

    public function test_la_resolucion_distingue_los_tres_motivos(): void
    {
        $this->assertSame(ResolucionAlbaran::SIN_CANDIDATOS, ResolucionAlbaran::decidir([])->estado);

        $credito = $this->albaran('AC02/0232/00/6836', 2.89);
        $this->assertSame(ResolucionAlbaran::TIPO_INDETERMINADO, ResolucionAlbaran::decidir([$credito])->estado);

        $uno = $this->albaran('AC01/0232/00/6715', 136.33, '260602320099991');
        $this->assertSame(ResolucionAlbaran::VINCULADO, ResolucionAlbaran::decidir([$uno])->estado);

        $dos = $this->albaran('AC01/0232/00/6716', 136.33, '260602320099992');
        $this->assertSame(ResolucionAlbaran::VARIOS_CANDIDATOS, ResolucionAlbaran::decidir([$uno, $dos])->estado);
    }

    // ═════════════════════════ la evidencia

    public function test_el_pdf_del_albaran_se_guarda_con_su_hash_y_su_nombre(): void
    {
        $pdf = '%PDF-1.4 contenido del albaran AC01/0232/00/6715';

        $albaran = app(AlbaranPersistidor::class)->registrarConSala([
            'numero_albaran' => 'AC01/0232/00/6715',
            'numero_orden_compra' => self::OC,
            'monto_albaran' => 136.33,
            'fecha_albaran' => '15/07/2026',
            'gmail_message_id' => 'msg-001',
            'archivo_nombre' => '26-07-0232-00-006715-AC01-0001.PDF',
            'archivo_contenido' => $pdf,
        ], 'gmail');

        $albaran->refresh();

        $this->assertSame(hash('sha256', $pdf), $albaran->archivo_hash);
        $this->assertSame('26-07-0232-00-006715-AC01-0001.PDF', $albaran->archivo_nombre);
        $this->assertNotNull($albaran->archivo_descargado_en);
        $this->assertSame('msg-001', $albaran->gmail_message_id);

        Storage::disk('local')->assertExists($albaran->archivo_path);
        $this->assertSame($pdf, Storage::disk('local')->get($albaran->archivo_path));
    }

    public function test_releer_el_mismo_correo_es_idempotente(): void
    {
        $pdf = '%PDF-1.4 contenido del albaran';
        $datos = [
            'numero_albaran' => 'AC01/0232/00/6715',
            'numero_orden_compra' => self::OC,
            'monto_albaran' => 136.33,
            'gmail_message_id' => 'msg-001',
            'archivo_nombre' => 'albaran.pdf',
            'archivo_contenido' => $pdf,
        ];

        $primero = app(AlbaranPersistidor::class)->registrarConSala($datos, 'gmail');
        $segundo = app(AlbaranPersistidor::class)->registrarConSala($datos, 'gmail');

        // Misma fila, misma ruta: el archivo se direcciona por su contenido.
        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame(1, PpqAlbaran::count());
        $this->assertSame($primero->refresh()->archivo_path, $segundo->refresh()->archivo_path);
        $this->assertCount(1, Storage::disk('local')->allFiles('ppq/albaranes'));
    }

    public function test_una_evidencia_ya_guardada_no_se_pisa(): void
    {
        $original = '%PDF original';
        $corregido = '%PDF corregido por el cliente';

        $datos = [
            'numero_albaran' => 'AC01/0232/00/6715',
            'numero_orden_compra' => self::OC,
            'monto_albaran' => 136.33,
            'archivo_nombre' => 'albaran.pdf',
        ];

        $albaran = app(AlbaranPersistidor::class)->registrarConSala($datos + ['archivo_contenido' => $original], 'gmail');
        $hashOriginal = $albaran->refresh()->archivo_hash;

        // Un reenvío con contenido distinto es una CORRECCIÓN: se detecta comparando el
        // hash, y no se reemplaza en silencio la prueba original.
        app(AlbaranPersistidor::class)->registrarConSala($datos + ['archivo_contenido' => $corregido], 'gmail');

        $this->assertSame($hashOriginal, $albaran->refresh()->archivo_hash);
        $this->assertSame($original, Storage::disk('local')->get($albaran->archivo_path));
    }

    public function test_el_alta_sin_pdf_sigue_funcionando_igual_que_antes(): void
    {
        // El alta manual desde la pantalla no trae adjunto: no debe romperse ni inventar
        // una evidencia que no existe.
        $albaran = app(AlbaranPersistidor::class)->registrar([
            'numero_albaran' => 'AC02/0232/00/6836',
            'numero_orden_compra' => self::OC,
            'monto_albaran' => 2.89,
        ], 'manual');

        $this->assertNull($albaran->archivo_path);
        $this->assertNull($albaran->archivo_hash);
        $this->assertSame('AC02', $albaran->tipo_codigo);
        $this->assertEmpty(Storage::disk('local')->allFiles('ppq/albaranes'));
    }

    public function test_la_sincronizacion_deja_el_pdf_guardado_y_no_lo_duplica(): void
    {
        $pdf = '%PDF-1.4 albaran bajado del correo';

        // Doble del servicio de correo: devuelve el candidato tal como lo arma la lectura
        // real, adjunto incluido. Lo que se prueba acá es el recorrido completo desde el
        // candidato hasta el archivo en disco.
        $this->app->instance(PpqGmailService::class, new class($pdf) extends PpqGmailService
        {
            public function __construct(private string $pdf)
            {
                // No se llama a parent::__construct: este doble no toca Gmail.
            }

            public function disponible(): bool
            {
                return true;
            }

            public function albaranesDeFecha(string $fecha, int $limite = 40): array
            {
                return [[
                    'gmail_message_id' => 'msg-001',
                    'numero_albaran' => 'AC01/0232/00/6715',
                    'orden_compra' => '260602320012345',
                    'sala' => '0232',
                    'nombre_sala' => 'Selectos San Miguel',
                    'monto' => 136.33,
                    'fecha' => '2026-07-15',
                    'asunto' => 'Albaran AC01/0232/00/6715',
                    'archivo_nombre' => '26-07-0232-00-006715-AC01-0001.PDF',
                    'archivo_contenido' => $this->pdf,
                ]];
            }

            public function ultimaBusquedaTruncada(): bool
            {
                return false;
            }
        });

        $this->artisan('ppq:sincronizar-albaranes', [
            '--desde' => '2026-07-15', '--hasta' => '2026-07-15', '--aplicar' => true,
        ])->assertSuccessful();

        $albaran = PpqAlbaran::sole();
        $this->assertSame('AC01', $albaran->tipo_codigo);
        $this->assertSame(hash('sha256', $pdf), $albaran->archivo_hash);
        Storage::disk('local')->assertExists($albaran->archivo_path);

        // Segunda corrida: el correo ya está sincronizado, así que ni se vuelve a bajar ni
        // se duplica el archivo.
        $this->artisan('ppq:sincronizar-albaranes', [
            '--desde' => '2026-07-15', '--hasta' => '2026-07-15', '--aplicar' => true,
        ])->assertSuccessful();

        $this->assertSame(1, PpqAlbaran::count());
        $this->assertCount(1, Storage::disk('local')->allFiles('ppq/albaranes'));
    }
}
