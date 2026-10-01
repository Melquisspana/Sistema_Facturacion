<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoSolicitudCobro;
use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\Cobros\TipoEventoCobro;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\User;
use App\Services\Cobros\Exportadores\ExportadorSolicitudCargaMasivaV1;
use App\Services\Cobros\SolicitudCobroService;
use App\Services\Cobros\VinculadorAlbaranes;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\CreaDteVerificableCobros;
use Tests\TestCase;

/**
 * SOLICITUDES de cobro con el formato nuevo del portal, y la VINCULACIÓN de albaranes de
 * la que dependen sus cinco columnas.
 *
 * Los datos de albarán que usan estas pruebas son los del albarán REAL de referencia
 * (`AC01/0017/00/5131`, OC 26090017003463, 17/09/2026, $123.74), precisamente para poder
 * comprobar lo que el encargo pide de él: que NO se vincule por suposición a una solicitud
 * a la que no pertenece.
 *
 * No emite documentos, no manda correos y no toca el circuito de notas de crédito.
 */
class CobrosSolicitudTest extends TestCase
{
    use CreaDteVerificableCobros;
    use RefreshDatabase;

    private function cliente(): Cliente
    {
        return Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);
    }

    private function usuario(): User
    {
        return User::factory()->create();
    }

    /** Documento del seguimiento con DTE aceptado: así se puede verificar que no tiene NC. */
    private function documento(Cliente $cliente, array $datos = []): CobroDocumento
    {
        $datos = array_merge([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Dte->value,
            'tipo_dte' => '03',
            'numero_control' => 'DTE-03-M001P002-000000000000131',
            'fecha_emision' => '2026-09-18',
            'monto' => '123.74',
        ], $datos);
        $dte = $this->dteVerificableCobros(
            $cliente,
            $datos['numero_control'],
            $datos['fecha_emision'],
            $datos['monto'],
            $datos['tipo_dte'],
        );

        return CobroDocumento::create($datos + ['dte_id' => $dte->id]);
    }

    /** El albarán real de referencia. */
    private function albaranReal(array $datos = []): PpqAlbaran
    {
        return PpqAlbaran::create(array_merge([
            'numero_albaran' => 'AC01/0017/00/5131',
            'tipo_codigo' => 'AC01',
            'fecha_albaran' => '2026-09-17',
            'monto_albaran' => '123.74',
            'numero_orden_compra' => '26090017003463',
            'sala_codigo' => '0017',
        ], $datos));
    }

    /** @return array<int, array<string, string>> celdas [fila][columna] */
    private function leer(string $ruta): array
    {
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        $celdas = [];
        foreach ($hoja->getRowIterator() as $fila) {
            $n = $fila->getRowIndex();
            foreach (range('A', 'E') as $col) {
                $celdas[$n][$col] = (string) $hoja->getCell($col.$n)->getValue();
            }
        }

        return $celdas;
    }

    // ------------------------------------------------------- vinculación

    /**
     * DORADA · con una orden de compra que apunta a UN albarán de entrega y nada que la
     * contradiga, el vínculo se hace solo y queda dicho por qué.
     */
    public function test_dorada_un_solo_albaran_por_orden_de_compra_se_vincula_y_se_explica(): void
    {
        $cliente = $this->cliente();
        $albaran = $this->albaranReal();

        // El documento trae su OC a través del DTE; acá se simula con un DTE mínimo.
        $documento = $this->documento($cliente);
        $documento->setRelation('dte', new Dte(['numero_orden_compra' => '26090017003463']));

        $veredicto = app(VinculadorAlbaranes::class)->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, $veredicto['estado']);
        $this->assertSame($albaran->id, $veredicto['albaran_id']);
        $this->assertStringContainsString('orden de compra 26090017003463', (string) $veredicto['motivo']);
        $this->assertCount(1, $veredicto['candidatos']);
    }

    /**
     * DORADA · el albarán real NO se vincula a un documento de otra orden de compra, por
     * mucho que el importe y la fecha encajen.
     *
     * Es el caso que el encargo pide expresamente: importe, fecha y correlativo no
     * identifican nada.
     */
    public function test_dorada_no_se_vincula_por_importe_ni_por_fecha_cuando_la_oc_es_otra(): void
    {
        $cliente = $this->cliente();
        $this->albaranReal();   // AC01/0017/00/5131, $123.74, 17/09/2026

        // Mismo importe y misma fecha, OTRA orden de compra.
        $documento = $this->documento($cliente, ['monto' => '123.74', 'fecha_emision' => '2026-09-17']);
        $documento->setRelation('dte', new Dte(['numero_orden_compra' => '26090261004111']));

        $veredicto = app(VinculadorAlbaranes::class)->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::SinAlbaran, $veredicto['estado']);
        $this->assertNull($veredicto['albaran_id']);
        $this->assertStringContainsString('26090261004111', (string) $veredicto['motivo']);
    }

    /** DORADA · dos albaranes para la misma OC: no se elige ninguno, se piden los dos. */
    public function test_dorada_dos_candidatos_por_la_misma_oc_van_a_revisar_con_sus_datos(): void
    {
        $cliente = $this->cliente();
        $this->albaranReal();
        $this->albaranReal(['numero_albaran' => 'AC01/0017/00/5132', 'monto_albaran' => '99.10']);

        $documento = $this->documento($cliente);
        $documento->setRelation('dte', new Dte(['numero_orden_compra' => '26090017003463']));

        $veredicto = app(VinculadorAlbaranes::class)->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertNull($veredicto['albaran_id'], 'Ambiguo no puede significar «el primero».');
        $this->assertCount(2, $veredicto['candidatos']);
        $this->assertStringContainsString('2 albaranes de entrega sin vincular', (string) $veredicto['motivo']);
        $this->assertSame('AC01/0017/00/5131', $veredicto['candidatos'][0]['numero']);
    }

    /** Una sala que se contradice manda el candidato a revisión aunque la OC coincida. */
    public function test_una_sala_contradictoria_impide_el_vinculo_automatico(): void
    {
        $cliente = $this->cliente();
        $this->albaranReal(['sala_codigo' => '0261', 'numero_albaran' => 'AC01/0261/00/5131']);

        $documento = $this->documento($cliente);
        $documento->setRelation('dte', new Dte(['numero_orden_compra' => '26090017003463']));

        $veredicto = app(VinculadorAlbaranes::class)->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertStringContainsString('sala 0017', (string) $veredicto['motivo']);
        $this->assertStringContainsString('sala 0261', str_replace('la 0261', 'sala 0261', (string) $veredicto['motivo']));
    }

    /** Un albarán de CRÉDITO (avería/devolución) no respalda una factura. */
    public function test_un_albaran_que_no_es_de_entrega_no_vincula(): void
    {
        $cliente = $this->cliente();
        $this->albaranReal(['tipo_codigo' => 'AC02', 'numero_albaran' => 'AC02/0017/00/5131']);

        $documento = $this->documento($cliente);
        $documento->setRelation('dte', new Dte(['numero_orden_compra' => '26090017003463']));

        $veredicto = app(VinculadorAlbaranes::class)->auditar($documento);

        // Ni siquiera es candidato: el filtro de entrega lo deja fuera.
        $this->assertSame(EstadoVinculacionAlbaran::SinAlbaran, $veredicto['estado']);
    }

    /** Un albarán ya tomado por otro documento no se reparte: sería cobrarlo dos veces. */
    public function test_un_albaran_ya_vinculado_a_otro_documento_va_a_revisar(): void
    {
        $cliente = $this->cliente();
        $albaran = $this->albaranReal();

        $otro = $this->documento($cliente, ['numero_control' => 'DTE-03-M001P002-000000000000900']);
        $otro->forceFill(['ppq_albaran_id' => $albaran->id])->save();

        $documento = $this->documento($cliente);
        $documento->setRelation('dte', new Dte(['numero_orden_compra' => '26090017003463']));

        $veredicto = app(VinculadorAlbaranes::class)->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertStringContainsString('ya están vinculados a otros documentos', (string) $veredicto['motivo']);
        $this->assertStringContainsString('DTE-03-M001P002-000000000000900', (string) $veredicto['motivo']);
    }

    /** Vincular a mano deja constancia de quién fue y de lo que se saltó. */
    public function test_vincular_a_mano_queda_registrado_con_sus_contradicciones(): void
    {
        $cliente = $this->cliente();
        $albaran = $this->albaranReal(['sala_codigo' => '0261', 'monto_albaran' => '99.10']);
        $usuario = $this->usuario();

        $documento = $this->documento($cliente);
        $documento->setRelation('dte', new Dte(['numero_orden_compra' => '26090017003463']));

        app(VinculadorAlbaranes::class)->vincularAMano($documento, $albaran, $usuario, 'Confirmado con la sala.');

        $documento->refresh();
        $this->assertSame(EstadoVinculacionAlbaran::Vinculado, $documento->vinculacion_estado);
        $this->assertSame($albaran->id, $documento->ppq_albaran_id);
        $this->assertStringContainsString('Vinculado a mano por '.$usuario->name, (string) $documento->vinculacion_motivo);
        $this->assertStringContainsString('pese a:', (string) $documento->vinculacion_motivo);
        $this->assertStringContainsString('Confirmado con la sala.', (string) $documento->vinculacion_motivo);
        $this->assertSame($usuario->id, $documento->vinculado_por);
    }

    // ------------------------------------------------------- el archivo

    /**
     * DORADA · los cinco encabezados exactos y una fila con los cinco datos del ALBARÁN,
     * incluidos el año y el mes de la entrega, no los de la factura.
     */
    public function test_dorada_el_archivo_lleva_los_cinco_encabezados_y_los_datos_del_albaran(): void
    {
        $cliente = $this->cliente();
        $albaran = $this->albaranReal();                 // 17/09/2026
        $documento = $this->documento($cliente, ['fecha_emision' => '2026-10-02']);   // otra fecha, a propósito
        $documento->forceFill([
            'ppq_albaran_id' => $albaran->id,
            'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value,
        ])->save();

        $servicio = app(SolicitudCobroService::class);
        $solicitud = $servicio->crear($cliente, [$documento->id], $this->usuario());
        $celdas = $this->leer($servicio->archivo($solicitud));

        $esperados = [
            'A' => 'CODIGO DE SALA O CD',
            'B' => '# ALBARAN',
            'C' => 'AÑO (ultimos 2 digitos)',
            'D' => 'MES  (en numero)',      // dos espacios: así viene la plantilla
            'E' => 'TIPO ALBARAN',
        ];
        foreach ($esperados as $col => $titulo) {
            $this->assertSame($titulo, $celdas[1][$col], "El encabezado de la columna {$col} cambió.");
        }

        $this->assertSame('0017', $celdas[2]['A'], 'La sala conserva su cero inicial.');
        $this->assertSame('5131', $celdas[2]['B'], 'Solo el número, no el canónico completo.');
        $this->assertSame('26', $celdas[2]['C'], 'Año del ALBARÁN.');
        $this->assertSame('9', $celdas[2]['D'], 'Mes del ALBARÁN (septiembre), no el de la factura (octubre).');
        $this->assertSame('AC01', $celdas[2]['E']);

        $this->assertArrayNotHasKey(3, $celdas, 'Una sola fila de datos.');
    }

    /**
     * El archivo sale DE la plantilla original del cliente: mismas hojas, mismos
     * encabezados con su estilo y sus anchos. Solo se le agregan filas, con A/B/E como
     * texto (la sala 0017 conserva su cero) y C/D como números.
     */
    public function test_el_archivo_parte_de_la_plantilla_original_y_respeta_tipos(): void
    {
        $cliente = $this->cliente();
        $albaran = $this->albaranReal();
        $documento = $this->documento($cliente);
        $documento->forceFill([
            'ppq_albaran_id' => $albaran->id,
            'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value,
        ])->save();

        $servicio = app(SolicitudCobroService::class);
        $solicitud = $servicio->crear($cliente, [$documento->id], $this->usuario());
        $generado = IOFactory::load($servicio->archivo($solicitud));
        $plantilla = IOFactory::load((new ExportadorSolicitudCargaMasivaV1)->rutaPlantilla());

        $this->assertSame($plantilla->getSheetNames(), $generado->getSheetNames(), 'Ni hojas extra ni renombradas.');
        $this->assertSame('Hoja3', $generado->getActiveSheet()->getTitle());

        $hojaG = $generado->getSheetByName('Hoja3');
        $hojaP = $plantilla->getSheetByName('Hoja3');
        foreach (range('A', 'E') as $col) {
            $this->assertSame((string) $hojaP->getCell($col.'1')->getValue(), (string) $hojaG->getCell($col.'1')->getValue());
            $estiloP = $hojaP->getStyle($col.'1');
            $estiloG = $hojaG->getStyle($col.'1');
            $this->assertSame($estiloP->getFont()->getBold(), $estiloG->getFont()->getBold(), "Negrita de {$col}1.");
            $this->assertSame($estiloP->getFont()->getName(), $estiloG->getFont()->getName(), "Fuente de {$col}1.");
            $this->assertEquals($estiloP->getFont()->getSize(), $estiloG->getFont()->getSize(), "Tamaño de {$col}1.");
            $this->assertSame($estiloP->getFill()->getFillType(), $estiloG->getFill()->getFillType(), "Relleno de {$col}1.");
            $this->assertSame($estiloP->getFill()->getStartColor()->getARGB(), $estiloG->getFill()->getStartColor()->getARGB(), "Color de {$col}1.");
            $this->assertSame($estiloP->getAlignment()->getHorizontal(), $estiloG->getAlignment()->getHorizontal(), "Alineación de {$col}1.");
            $this->assertEquals($hojaP->getColumnDimension($col)->getWidth(), $hojaG->getColumnDimension($col)->getWidth(), "Ancho de {$col}.");
        }

        // Tipos de la fila de datos.
        $this->assertSame(DataType::TYPE_STRING, $hojaG->getCell('A2')->getDataType());
        $this->assertSame('0017', $hojaG->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $hojaG->getCell('B2')->getDataType());
        $this->assertSame('5131', $hojaG->getCell('B2')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $hojaG->getCell('C2')->getDataType());
        $this->assertEquals(26, $hojaG->getCell('C2')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $hojaG->getCell('D2')->getDataType());
        $this->assertEquals(9, $hojaG->getCell('D2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $hojaG->getCell('E2')->getDataType());
        $this->assertSame('AC01', $hojaG->getCell('E2')->getValue());
        $this->assertFalse($hojaG->getCell('C2')->isFormula());
    }

    /** Sin la plantilla, o con una dañada, no se improvisa un formato. */
    public function test_sin_plantilla_valida_no_se_genera_ningun_archivo(): void
    {
        $solicitud = new CobroSolicitud;

        $faltante = new ExportadorSolicitudCargaMasivaV1(storage_path('framework/testing/no-existe-quedan.xlsx'));
        try {
            $faltante->generar($solicitud);
            $this->fail('Sin plantilla no debe generarse un archivo.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Falta la plantilla', $e->getMessage());
        }

        $danada = tempnam(sys_get_temp_dir(), 'quedan_danada_').'.xlsx';
        file_put_contents($danada, 'esto no es un xlsx');
        try {
            (new ExportadorSolicitudCargaMasivaV1($danada))->generar($solicitud);
            $this->fail('Con una plantilla dañada no debe generarse un archivo.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no se pudo leer', $e->getMessage());
        } finally {
            @unlink($danada);
        }
    }

    /** DORADA · una factura sin albarán no se exporta, y se dice exactamente qué falta. */
    public function test_dorada_una_factura_sin_albaran_no_se_exporta_y_se_dice_por_que(): void
    {
        $cliente = $this->cliente();
        $sinAlbaran = $this->documento($cliente);

        // Sigue VISIBLE en la bandeja de presentables: es justo la que hay que resolver.
        $presentables = app(SolicitudCobroService::class)->presentables($cliente);
        $this->assertTrue($presentables->contains('id', $sinAlbaran->id));
        $this->assertFalse($sinAlbaran->estaCompletoParaPresentar());
        $this->assertStringContainsString('el albarán', implode(' ', $sinAlbaran->faltantesParaPresentar()));

        try {
            app(SolicitudCobroService::class)->crear($cliente, [$sinAlbaran->id], $this->usuario());
            $this->fail('No debe poder generarse una solicitud con una factura sin albarán.');
        } catch (ValidationException $e) {
            $mensaje = implode(' ', $e->errors()['documentos']);
            $this->assertStringContainsString($sinAlbaran->numero_control, $mensaje);
            $this->assertStringContainsString('el albarán', $mensaje);
        }

        $this->assertSame(0, CobroSolicitud::count(), 'No queda ninguna solicitud a medias.');
    }

    /** Las notas de crédito NO entran en este formato: tienen su propio circuito. */
    public function test_una_nota_de_credito_no_entra_en_la_solicitud(): void
    {
        $cliente = $this->cliente();
        $albaran = $this->albaranReal();
        $nc = $this->documento($cliente, [
            'tipo_dte' => '05',
            'numero_control' => 'DTE-05-M001P002-000000000090001',
        ]);
        $nc->forceFill(['ppq_albaran_id' => $albaran->id])->save();

        $this->expectException(ValidationException::class);
        app(SolicitudCobroService::class)->crear($cliente, [$nc->id], $this->usuario());
    }

    // ------------------------------------------------------- presentación

    /**
     * DORADA · generar, presentar y recibir son tres hechos distintos, en ese orden y con
     * su evidencia. Descargar no presenta nada.
     */
    public function test_dorada_descargar_no_presenta_y_presentar_es_un_registro_explicito(): void
    {
        $cliente = $this->cliente();
        $albaran = $this->albaranReal();
        $documento = $this->documento($cliente);
        $documento->forceFill(['ppq_albaran_id' => $albaran->id])->save();

        $servicio = app(SolicitudCobroService::class);
        $usuario = $this->usuario();

        $solicitud = $servicio->crear($cliente, [$documento->id], $usuario);

        // 1 · Generada: el documento está «preparado», no presentado.
        $this->assertSame(EstadoSolicitudCobro::Generada, $solicitud->estado);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $documento->refresh()->presentacion_estado);
        $this->assertNull($solicitud->presentada_en);

        // 2 · Descargar dos veces NO la presenta.
        $servicio->archivo($solicitud);
        $solicitud->registrarDescarga();
        $servicio->archivo($solicitud->refresh());
        $solicitud->registrarDescarga();

        $this->assertSame(2, $solicitud->refresh()->descargas);
        $this->assertNull($solicitud->presentada_en, 'Bajar el archivo no es presentarlo.');
        $this->assertSame(EstadoPresentacionCobro::Preparada, $documento->refresh()->presentacion_estado);

        // 3 · Presentar es una declaración de una persona.
        $servicio->registrarPresentacion($solicitud, $usuario, Carbon::parse('2026-09-19'), 'Subida a las 9:30.');

        $solicitud->refresh();
        $this->assertSame(EstadoSolicitudCobro::Presentada, $solicitud->estado);
        $this->assertSame($usuario->id, $solicitud->presentada_por);
        $this->assertSame(EstadoPresentacionCobro::Presentada, $documento->refresh()->presentacion_estado);
        $this->assertSame(1, CobroEvento::where('tipo', TipoEventoCobro::Presentacion->value)->count());

        // 4 · Recibir lo dice el cliente, con su referencia y su fecha programada.
        $servicio->registrarRecibido(
            $solicitud,
            '31001',
            Carbon::parse('2026-09-24'),
            Carbon::parse('2026-09-20'),
            $usuario,
        );

        $solicitud->refresh();
        $documento->refresh();
        $this->assertSame(EstadoSolicitudCobro::Recibida, $solicitud->estado);
        $this->assertSame('31001', $solicitud->referencia_calleja);
        $this->assertSame('2026-09-24', $solicitud->fecha_programada_pago?->toDateString());
        $this->assertSame(EstadoPresentacionCobro::Recibida, $documento->presentacion_estado);
        $this->assertSame('2026-09-24', $documento->fechaProgramadaPago()?->toDateString());
        $this->assertSame(1, CobroEvento::where('tipo', TipoEventoCobro::Recibido->value)->count());
    }

    /**
     * Volver a descargar entrega la COPIA archivada: corregir el albarán después no cambia
     * el archivo que ya se entregó.
     */
    public function test_redescargar_la_solicitud_entrega_la_copia_archivada(): void
    {
        $cliente = $this->cliente();
        $albaran = $this->albaranReal();
        $documento = $this->documento($cliente);
        $documento->forceFill(['ppq_albaran_id' => $albaran->id])->save();

        $servicio = app(SolicitudCobroService::class);
        $solicitud = $servicio->crear($cliente, [$documento->id], $this->usuario());

        $primero = $this->leer($servicio->archivo($solicitud));

        // Se corrige el albarán DESPUÉS de presentar: el archivo ya entregado no cambia.
        $albaran->forceFill(['sala_codigo' => '9999'])->save();

        $segundo = $this->leer($servicio->archivo($solicitud->refresh()));

        $this->assertSame($primero, $segundo);
        $this->assertSame('0017', $segundo[2]['A'], 'El archivo conserva lo que se presentó.');
    }

    // ------------------------------------------------------- redescarga del original

    /** Solicitud con un documento vinculado, con el disco de archivos fingido. */
    private function solicitudArchivable(): CobroSolicitud
    {
        Storage::fake((string) config('dte.storage.disk', 'local'));

        $cliente = $this->cliente();
        $albaran = $this->albaranReal();
        $documento = $this->documento($cliente);
        $documento->forceFill(['ppq_albaran_id' => $albaran->id])->save();

        return app(SolicitudCobroService::class)->crear($cliente, [$documento->id], $this->usuario());
    }

    private function bytes(string $ruta): string
    {
        $contenido = (string) file_get_contents($ruta);
        @unlink($ruta);

        return $contenido;
    }

    /**
     * La segunda descarga entrega los BYTES archivados, aunque lo que sirvió para generar
     * el archivo haya cambiado después. Regenerar daría otro archivo.
     */
    public function test_la_redescarga_entrega_los_bytes_archivados_aunque_cambie_la_fuente(): void
    {
        $solicitud = $this->solicitudArchivable();
        $servicio = app(SolicitudCobroService::class);

        $primero = $this->bytes($servicio->archivo($solicitud));
        $solicitud->refresh();
        $this->assertSame(hash('sha256', $primero), $solicitud->archivo_hash);
        $this->assertNotNull($solicitud->archivo_path);

        // El renglón congelado cambia (no debería, pero es lo que un regenerado leería).
        $solicitud->items()->update(['sala_codigo' => '9999', 'albaran_numero' => '1']);

        $segundo = $this->bytes($servicio->archivo($solicitud->refresh()));

        $this->assertSame($primero, $segundo, 'Byte a byte el mismo archivo.');
        $this->assertSame(hash('sha256', $primero), $solicitud->refresh()->archivo_hash, 'La huella no cambia.');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function copiasInservibles(): array
    {
        return [
            'copia borrada' => ['borrar', 'No se encontró la copia archivada'],
            'copia alterada' => ['alterar', 'no coincide con su huella'],
            'registro a medias' => ['sin_ruta', 'registro de su archivo incompleto'],
        ];
    }

    #[DataProvider('copiasInservibles')]
    public function test_sin_copia_valida_no_se_regenera_ni_se_entrega(string $dano, string $mensaje): void
    {
        $solicitud = $this->solicitudArchivable();
        $servicio = app(SolicitudCobroService::class);
        $this->bytes($servicio->archivo($solicitud));
        $solicitud->refresh();
        $hash = $solicitud->archivo_hash;
        $disco = Storage::disk((string) config('dte.storage.disk', 'local'));

        match ($dano) {
            'borrar' => $disco->delete($solicitud->archivo_path),
            'alterar' => $disco->put($solicitud->archivo_path, 'otro contenido'),
            'sin_ruta' => $solicitud->forceFill(['archivo_path' => null])->save(),
        };

        try {
            $servicio->archivo($solicitud->refresh());
            $this->fail('Sin copia válida no debe entregarse ningún archivo.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($mensaje, $e->getMessage());
        }

        // Nada se reescribió: ni la huella ni una copia nueva.
        $this->assertSame($hash, $solicitud->refresh()->archivo_hash);
        $this->assertCount($dano === 'borrar' ? 0 : 1, $disco->allFiles());
    }

    /** Un storage que falla al leer se informa con la solicitud y su causa; no se regenera. */
    public function test_un_storage_ilegible_se_informa_sin_regenerar(): void
    {
        $solicitud = $this->solicitudArchivable();
        $servicio = app(SolicitudCobroService::class);
        $this->bytes($servicio->archivo($solicitud));
        $solicitud->refresh();
        $hash = $solicitud->archivo_hash;

        $disco = Mockery::mock(Filesystem::class);
        $disco->shouldReceive('exists')->andThrow(new RuntimeException('disco no disponible'));
        $disco->shouldNotReceive('put');
        Storage::shouldReceive('disk')->andReturn($disco);

        try {
            $servicio->archivo($solicitud);
            $this->fail('Con el storage caído no debe entregarse ni regenerarse nada.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No se pudo leer la copia archivada', $e->getMessage());
            $this->assertStringContainsString((string) $solicitud->referencia, $e->getMessage());
            $this->assertSame('disco no disponible', $e->getPrevious()?->getMessage(), 'Se conserva la causa.');
        }

        $this->assertSame($hash, $solicitud->refresh()->archivo_hash);
    }

    /** Por HTTP: descargar cuenta la descarga y NO presenta; si la copia falta, ni cuenta. */
    public function test_descargar_no_presenta_y_un_fallo_no_cuenta_la_descarga(): void
    {
        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::factory()->create()->assignRole(RolSistema::Administrador->value);

        $solicitud = $this->solicitudArchivable();

        $respuesta = $this->actingAs($admin)->get(route('cobros.solicitudes.descargar', $solicitud));
        $respuesta->assertOk();
        // Excel, aunque el temporal no tenga extensión, y con el nombre de la solicitud.
        $respuesta->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $respuesta->assertDownload($solicitud->archivo_nombre);

        $solicitud->refresh();
        $this->assertSame(1, $solicitud->descargas);
        $this->assertNull($solicitud->presentada_en, 'Descargar no es presentar.');
        $this->assertNotSame(EstadoSolicitudCobro::Presentada, $solicitud->estado);
        $this->assertSame(0, CobroEvento::where('tipo', TipoEventoCobro::Presentacion->value)->count());

        Storage::disk((string) config('dte.storage.disk', 'local'))->delete($solicitud->archivo_path);

        $this->actingAs($admin)->get(route('cobros.solicitudes.descargar', $solicitud))
            ->assertRedirect(route('cobros.index', ['cliente_id' => $solicitud->cliente_id]));
        $this->assertSame(1, $solicitud->refresh()->descargas, 'Una descarga fallida no se cuenta.');
    }

    /**
     * Por HTTP, una copia inservible (borrada, alterada o con registro incompleto) vuelve a
     * la bandeja con un aviso seguro, sin servir archivo, sin contar la descarga, sin
     * regenerar y sin cambiar ningún estado.
     */
    #[DataProvider('copiasInservibles')]
    public function test_por_http_una_copia_inservible_vuelve_a_cobros_con_aviso(string $dano, string $_mensaje): void
    {
        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::factory()->create()->assignRole(RolSistema::Administrador->value);

        $solicitud = $this->solicitudArchivable();
        $this->bytes(app(SolicitudCobroService::class)->archivo($solicitud));
        $solicitud->refresh();
        $disco = Storage::disk((string) config('dte.storage.disk', 'local'));
        $ruta = $solicitud->archivo_path;

        match ($dano) {
            'borrar' => $disco->delete($ruta),
            'alterar' => $disco->put($ruta, 'otro contenido'),
            'sin_ruta' => $solicitud->forceFill(['archivo_path' => null])->save(),
        };
        $antes = $solicitud->refresh()->only(['archivo_hash', 'archivo_path', 'descargas', 'presentada_en']);
        $estadoAntes = $solicitud->estado;

        $respuesta = $this->actingAs($admin)->get(route('cobros.solicitudes.descargar', $solicitud));

        $respuesta->assertRedirect(route('cobros.index', ['cliente_id' => $solicitud->cliente_id]));
        $respuesta->assertSessionHas('error', fn ($m) => str_contains((string) $m, $solicitud->referencia)
            && str_contains((string) $m, 'la descarga no se contó')
            && ! str_contains((string) $m, (string) $ruta)
            && ! str_contains((string) $m, 'cobros/solicitudes'));
        $this->assertNotInstanceOf(BinaryFileResponse::class, $respuesta->baseResponse);

        $solicitud->refresh();
        $this->assertSame($antes, $solicitud->only(['archivo_hash', 'archivo_path', 'descargas', 'presentada_en']));
        $this->assertSame($estadoAntes, $solicitud->estado);

        // La bandeja muestra el aviso.
        $this->actingAs($admin)->get(route('cobros.index', ['cliente_id' => $solicitud->cliente_id]))
            ->assertOk()
            ->assertSeeText('No se pudo descargar '.$solicitud->referencia);
    }

    /** Un documento no puede estar en dos solicitudes vigentes: sería cobrarlo dos veces. */
    public function test_no_se_puede_incluir_el_mismo_documento_en_dos_solicitudes(): void
    {
        $cliente = $this->cliente();
        $albaran = $this->albaranReal();
        $documento = $this->documento($cliente);
        $documento->forceFill(['ppq_albaran_id' => $albaran->id])->save();

        $servicio = app(SolicitudCobroService::class);
        $servicio->crear($cliente, [$documento->id], $this->usuario());

        $this->expectException(ValidationException::class);
        $servicio->crear($cliente, [$documento->id], $this->usuario());
    }

    /**
     * DORADA · corregir una solicitud reenvía sin duplicar la deuda: los documentos se
     * MUEVEN, la anterior queda como constancia, y el que se deja fuera vuelve a la bandeja.
     */
    public function test_dorada_corregir_una_solicitud_no_duplica_la_deuda(): void
    {
        $cliente = $this->cliente();
        $usuario = $this->usuario();

        $a = $this->documento($cliente, ['numero_control' => 'DTE-03-M001P002-000000000000131']);
        $a->forceFill(['ppq_albaran_id' => $this->albaranReal()->id])->save();

        $b = $this->documento($cliente, ['numero_control' => 'DTE-03-M001P002-000000000000132']);
        $b->forceFill(['ppq_albaran_id' => $this->albaranReal([
            'numero_albaran' => 'AC01/0017/00/5132',
            'numero_orden_compra' => '26090017003464',
        ])->id])->save();

        $servicio = app(SolicitudCobroService::class);
        $original = $servicio->crear($cliente, [$a->id, $b->id], $usuario);
        $this->assertSame(2, $original->items()->count());

        // El reenvío se queda solo con A: B no debía haber ido.
        $nueva = $servicio->corregir($original, [$a->id], 'B se facturó a otra sala.', $usuario);

        $original->refresh();
        $this->assertSame(EstadoSolicitudCobro::Corregida, $original->estado);
        $this->assertSame($original->id, $nueva->corrige_a_id);
        $this->assertSame('B se facturó a otra sala.', $nueva->motivo_correccion);

        // A cuelga SOLO de la nueva; B volvió a estar sin presentar.
        $this->assertSame($nueva->id, $a->refresh()->cobro_solicitud_id);
        $this->assertSame(EstadoPresentacionCobro::Preparada, $a->presentacion_estado);
        $this->assertNull($b->refresh()->cobro_solicitud_id);
        $this->assertSame(EstadoPresentacionCobro::SinPresentar, $b->presentacion_estado);

        // La deuda no se duplicó: un solo renglón vigente por documento.
        $this->assertSame(1, $a->solicitudItems()->whereHas('solicitud', fn ($q) => $q->where('estado', '!=', 'corregida'))->count());

        // Y la solicitud original se conserva entera como constancia de lo entregado.
        $this->assertSame(2, $original->items()->count());
    }

    /** Una solicitud ya corregida no se puede presentar: mandaría lo que ya se reemplazó. */
    public function test_una_solicitud_corregida_no_se_puede_presentar(): void
    {
        $cliente = $this->cliente();
        $usuario = $this->usuario();
        $documento = $this->documento($cliente);
        $documento->forceFill(['ppq_albaran_id' => $this->albaranReal()->id])->save();

        $servicio = app(SolicitudCobroService::class);
        $original = $servicio->crear($cliente, [$documento->id], $usuario);
        $servicio->corregir($original, [$documento->id], 'Se cargó incompleta.', $usuario);

        $this->expectException(ValidationException::class);
        $servicio->registrarPresentacion($original->refresh(), $usuario);
    }

    /** El formato de la solicitud queda congelado en ella. */
    public function test_la_solicitud_recuerda_con_que_formato_se_armo(): void
    {
        $cliente = $this->cliente();
        $documento = $this->documento($cliente);
        $documento->forceFill(['ppq_albaran_id' => $this->albaranReal()->id])->save();

        $solicitud = app(SolicitudCobroService::class)->crear($cliente, [$documento->id], $this->usuario());

        $this->assertSame(ExportadorSolicitudCargaMasivaV1::slug(), $solicitud->formato);
        $this->assertStringContainsString('portal de Calleja', ExportadorSolicitudCargaMasivaV1::entrega());
        $this->assertStringContainsString('no es presentarlo', ExportadorSolicitudCargaMasivaV1::entrega());
    }

    /** La sucursal de otro cliente contradice el vínculo aunque la OC coincida. */
    public function test_un_albaran_de_otro_cliente_no_vincula(): void
    {
        $cliente = $this->cliente();
        $otroCliente = Cliente::factory()->contribuyente()->create();
        $sucursalAjena = ClienteSucursal::factory()->create(['cliente_id' => $otroCliente->id]);

        $this->albaranReal(['cliente_sucursal_id' => $sucursalAjena->id]);

        $documento = $this->documento($cliente);
        $documento->setRelation('dte', new Dte(['numero_orden_compra' => '26090017003463']));

        $veredicto = app(VinculadorAlbaranes::class)->auditar($documento);

        $this->assertSame(EstadoVinculacionAlbaran::Revisar, $veredicto['estado']);
        $this->assertStringContainsString('otro cliente', (string) $veredicto['motivo']);
    }
}
